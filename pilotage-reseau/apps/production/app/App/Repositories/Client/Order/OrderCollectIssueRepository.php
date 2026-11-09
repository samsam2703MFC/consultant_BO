<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Échecs des commandes des boutiques (n_order_collect_issue) : configuration de la boutique, récupération refusée,
 * commande rejetée à l'import, ligne au produit inconnu. Une ligne par cas : une répétition met à jour last_at et
 * occurrences ; un cas fermé (closed_at) qui revient repart de zéro. Lu par Stan wysyłek (file « shops »).
 */
final class OrderCollectIssueRepository extends WorkshopRepository
{
    protected string $table = 'n_order_collect_issue';

    public const KINDS = ['config', 'pull', 'import', 'unknown_product'];

    /** Enregistre (ou rouvre) un cas ; $detail distingue deux cas de même nature sur la même commande (SKU). */
    public function record(string $kind, string $shopUid, ?int $customerId, ?string $orderKey, string $message, ?int $orderId = null, string $detail = ''): void
    {
        if (!in_array($kind, self::KINDS, true)) return;
        $dedup = hash('sha256', implode('|', [$kind, $shopUid, (string) $customerId, (string) $orderKey, $detail]));
        $message = mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 500);
        // MySQL évalue les affectations de gauche à droite : occurrences et first_at lisent l'ancien closed_at.
        $this->db->prepare("INSERT INTO n_order_collect_issue (id_customer, shop_uid, kind, order_key, id_order, dedup_key, message)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    occurrences = IF(closed_at IS NULL, occurrences + 1, 1),
                    first_at = IF(closed_at IS NULL, first_at, NOW()),
                    last_at = NOW(), message = VALUES(message), id_order = COALESCE(VALUES(id_order), id_order), closed_at = NULL")
            ->execute([$customerId, $shopUid, $kind, $orderKey, $orderId, $dedup, $message]);
    }

    /** Ferme les cas ouverts d'une boutique (la boutique répond de nouveau / la commande est importée). */
    public function close(array $kinds, string $shopUid, ?string $orderKey = null): void
    {
        $kinds = array_values(array_intersect($kinds, self::KINDS));
        if (!$kinds || $shopUid === '') return;
        $sql = 'UPDATE n_order_collect_issue SET closed_at = NOW() WHERE closed_at IS NULL AND shop_uid = ? AND kind IN ('
            . implode(',', array_fill(0, count($kinds), '?')) . ')' . ($orderKey !== null ? ' AND order_key = ?' : '');
        $this->db->prepare($sql)->execute(array_merge([$shopUid], $kinds, $orderKey !== null ? [$orderKey] : []));
    }
}
