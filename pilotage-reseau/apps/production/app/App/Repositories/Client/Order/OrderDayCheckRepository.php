<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Domain\Client\Order\OpenOrders;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Zamówienia dnia » [369] : lecture des commandes clients nouvelles et modifiées depuis une date (en-tête,
 * lignes, historique customer_order_changelog), moyenne des commandes du client, et résultat de vérification
 * (n_order_check, une ligne par commande et par jour). N'écrit que n_order_check.
 */
final class OrderDayCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_order_check';

    /** Moment de la dernière validation de l'assistant avant le jour donné (null = jamais). */
    public function lastDone(string $code, string $beforeDay): ?string
    {
        $r = $this->all('SELECT done_at FROM n_wizard_done WHERE code = ? AND day < ? ORDER BY day DESC, done_at DESC LIMIT 1', [$code, $beforeDay])[0] ?? null;
        return $r ? (string) $r['done_at'] : null;
    }

    /**
     * Commandes créées depuis $since ou avec une modification (hors système) depuis $since ; sans les commandes
     * archivées (« Nie »). Colonnes : en-tête, client, lignes, cartons, statut affiché, ouverte ou non.
     */
    public function orders(string $since): array
    {
        $archived = CustomerOrderStatus::ARCHIVED;
        return $this->all("SELECT co.id, co.reference, co.order_description, co.id_customer, cust.company, co.create_order_date,
                DATE(co.expected_shipping_date) AS shipping_date, COALESCE(co.order_type, 'STANDARD') AS order_type, COALESCE(co.is_freebie, 0) AS is_freebie,
                " . OpenOrders::effectiveStatusSql('co') . " AS status, IF(" . OpenOrders::sql('co') . ", 1, 0) AS is_open,
                (SELECT COUNT(*) FROM customer_order_product cop WHERE cop.id_order = co.id) AS line_count,
                (SELECT COALESCE(SUM(cop.quantity_carton), 0) FROM customer_order_product cop WHERE cop.id_order = co.id) AS cartons
            FROM customer_order co
            INNER JOIN customer cust ON cust.id = co.id_customer
            WHERE co.status <> {$archived}
              AND (co.create_order_date >= ? OR co.id IN (SELECT ch.id_order FROM customer_order_changelog ch
                    WHERE ch.action_type = 'update' AND ch.source <> 'system' AND ch.created_at >= ?))
            ORDER BY co.expected_shipping_date IS NULL DESC, co.expected_shipping_date, cust.company, co.id", [$since, $since]);
    }

    /** Entrées « update » de l'historique depuis $since (hors système : envoi des domówienia), par commande. */
    public function changes(array $orderIds, string $since): array
    {
        if (!$orderIds) return [];
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $rows = $this->all("SELECT ch.id_order, ch.source, ch.changed_fields, ch.created_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
            FROM customer_order_changelog ch LEFT JOIN user u ON u.id = ch.id_user
            WHERE ch.id_order IN ({$in}) AND ch.action_type = 'update' AND ch.source <> 'system' AND ch.created_at >= ?
            ORDER BY ch.created_at, ch.id", [...array_map('intval', $orderIds), $since]);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id_order']][] = $r;
        return $out;
    }

    /** Lignes des commandes : produit, cartons, produit actif (final_product.active ; null = produit absent). */
    public function lines(array $orderIds): array
    {
        if (!$orderIds) return [];
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        foreach ($this->all("SELECT cop.id_order, cop.id_product, cop.quantity_carton, fp.name, fp.active FROM customer_order_product cop
                LEFT JOIN final_product fp ON fp.id = cop.id_product WHERE cop.id_order IN ({$in}) ORDER BY cop.id_order, fp.name", array_map('intval', $orderIds)) as $r) {
            $out[(int) $r['id_order']][] = $r;
        }
        return $out;
    }

    /** @return array<int, string> noms des produits (lignes retirées d'une commande comprises) */
    public function productNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column(array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $this->all("SELECT id, name FROM final_product WHERE id IN ({$in})", $ids)), 'name', 'id');
    }

    /**
     * Commandes ordinaires des clients depuis $from (sans domówienia, gratuits ni archivées) : base de la moyenne
     * « commande habituelle » du client. @return array<int, list<array{id:int, at:string, cartons:float}>>
     */
    public function history(array $customerIds, string $from): array
    {
        if (!$customerIds) return [];
        $in = implode(',', array_fill(0, count($customerIds), '?'));
        $out = [];
        foreach ($this->all("SELECT co.id, co.id_customer, co.create_order_date,
                    (SELECT COALESCE(SUM(cop.quantity_carton), 0) FROM customer_order_product cop WHERE cop.id_order = co.id) AS cartons
                FROM customer_order co
                WHERE co.id_customer IN ({$in}) AND co.create_order_date >= ? AND COALESCE(co.order_type, 'STANDARD') = 'STANDARD'
                  AND COALESCE(co.is_freebie, 0) = 0 AND co.status <> " . CustomerOrderStatus::ARCHIVED . "
                ORDER BY co.create_order_date", [...array_map('intval', $customerIds), $from]) as $r) {
            $out[(int) $r['id_customer']][] = ['id' => (int) $r['id'], 'at' => (string) $r['create_order_date'], 'cartons' => (float) $r['cartons']];
        }
        return $out;
    }

    /** @return array<int, array> résultats du jour par commande */
    public function checks(string $day): array
    {
        $out = [];
        foreach ($this->all("SELECT c.id_order, c.result, c.note, c.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_order_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.day = ?", [$day]) as $r) {
            $out[(int) $r['id_order']] = $r;
        }
        return $out;
    }

    /** Un résultat par commande et par jour : le dernier enregistré remplace le précédent. */
    public function saveCheck(int $orderId, string $day, string $result, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_order_check (id_order, day, result, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE result = VALUES(result), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$orderId, $day, $result, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
