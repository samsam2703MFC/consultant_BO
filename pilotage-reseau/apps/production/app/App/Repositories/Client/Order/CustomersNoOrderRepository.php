<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Klienci bez zamówienia » [370] : lecture des commandes ordinaires des clients actifs (base du rythme
 * habituel), coordonnées des clients et résultats (n_customer_no_order, un par client et par date attendue).
 * N'écrit que n_customer_no_order ; customer et customer_order sont seulement lus.
 */
final class CustomersNoOrderRepository extends WorkshopRepository
{
    protected string $table = 'n_customer_no_order';

    /**
     * Commandes ordinaires des clients actifs créées dans [$from, $to) : sans domówienia (order_type SUPPLEMENT),
     * gratuits (is_freebie) ni archivées (status 5).
     * @return array<int, list<array{id:int, at:string, day:string, shipping_date:?string}>> par client, dans l'ordre
     */
    public function orders(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all("SELECT co.id, co.id_customer, co.create_order_date, DATE(co.expected_shipping_date) AS shipping_date
                FROM customer_order co
                INNER JOIN customer cust ON cust.id = co.id_customer AND cust.active = 1
                WHERE co.create_order_date >= ? AND co.create_order_date < ?
                  AND COALESCE(co.order_type, 'STANDARD') = 'STANDARD' AND COALESCE(co.is_freebie, 0) = 0
                  AND co.status <> " . CustomerOrderStatus::ARCHIVED . "
                ORDER BY co.create_order_date, co.id", [$from, $to]) as $r) {
            $at = (string) $r['create_order_date'];
            $ship = $r['shipping_date'] !== null && $r['shipping_date'] !== '0000-00-00' ? (string) $r['shipping_date'] : null;
            $out[(int) $r['id_customer']][] = ['id' => (int) $r['id'], 'at' => $at, 'day' => substr($at, 0, 10), 'shipping_date' => $ship];
        }
        return $out;
    }

    /** @return array<int, array> clients (nom, personne de contact, téléphone, e-mail) par id */
    public function customers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id, company, name, surname, phone_head, phone_alt, email_head FROM customer WHERE id IN ({$in})", $ids) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Résultats des clients pour les dates attendues depuis $from (dates attendues récentes et pauses en cours).
     * @return array<int, list<array>> par client, la date attendue la plus récente d'abord
     */
    public function results(array $customerIds, string $from): array
    {
        if (!$customerIds) return [];
        $in = implode(',', array_fill(0, count($customerIds), '?'));
        $out = [];
        foreach ($this->all("SELECT r.id_customer, r.expected_on, r.day, r.result, r.note, r.paused_until, r.updated_at,
                    COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_customer_no_order r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.id_customer IN ({$in}) AND (r.expected_on >= ? OR r.paused_until >= ?)
                ORDER BY r.expected_on DESC, r.id DESC", [...array_map('intval', $customerIds), $from, $from]) as $r) {
            $out[(int) $r['id_customer']][] = $r;
        }
        return $out;
    }

    /** Un résultat par client et par date attendue : le dernier enregistré remplace le précédent. */
    public function saveResult(int $customerId, string $expectedOn, string $day, string $result, ?string $note, ?string $pausedUntil, string $rhythm, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_customer_no_order (id_customer, expected_on, day, result, note, paused_until, rhythm, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE day = VALUES(day), result = VALUES(result), note = VALUES(note), paused_until = VALUES(paused_until),
                rhythm = VALUES(rhythm), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$customerId, $expectedOn, $day, $result, $note, $pausedUntil, mb_substr($rhythm, 0, 80), $userId]);
    }
}
