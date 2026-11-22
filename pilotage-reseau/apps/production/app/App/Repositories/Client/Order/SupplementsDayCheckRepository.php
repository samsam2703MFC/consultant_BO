<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Domain\Client\Order\OpenOrders;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Domówienia dnia » [371] : lecture des domówienia (customer_order.order_type SUPPLEMENT) récentes et à
 * venir, de leurs lignes et de leur état côté production (fiche de planification n_prod_item / n_prod_product_settings,
 * zlecenia du plan reliés par n_prod_order_demand, plans validés n_prod_plan), de la commande principale, et résultats
 * (n_supplement_check, une ligne par domówienie et par jour). N'écrit que n_supplement_check.
 */
final class SupplementsDayCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_supplement_check';

    /** Moment de la dernière validation de l'assistant avant le jour donné (null = jamais). */
    public function lastDone(string $code, string $beforeDay): ?string
    {
        $r = $this->all('SELECT done_at FROM n_wizard_done WHERE code = ? AND day < ? ORDER BY day DESC, done_at DESC LIMIT 1', [$code, $beforeDay])[0] ?? null;
        return $r ? (string) $r['done_at'] : null;
    }

    /**
     * Domówienia (sans les archivées « Nie ») passées depuis $since, ou ouvertes avec une expédition entre $fromDay et
     * $toDay inclus (expédition à venir, ou passée depuis la validation précédente sans WDT).
     */
    public function supplements(string $since, string $fromDay, string $toDay): array
    {
        $archived = CustomerOrderStatus::ARCHIVED;
        return $this->all("SELECT co.id, co.reference, co.order_description, co.id_customer, cust.company, co.create_order_date,
                DATE(co.expected_shipping_date) AS shipping_date, COALESCE(co.is_freebie, 0) AS is_freebie, co.parent_order_id, co.id_wdt,
                co.supplement_push_status, co.supplement_push_at,
                " . OpenOrders::effectiveStatusSql('co') . " AS status, IF(" . OpenOrders::sql('co') . ", 1, 0) AS is_open,
                (SELECT COUNT(*) FROM customer_order_product cop WHERE cop.id_order = co.id) AS line_count,
                (SELECT COALESCE(SUM(cop.quantity_carton), 0) FROM customer_order_product cop WHERE cop.id_order = co.id) AS cartons
            FROM customer_order co
            INNER JOIN customer cust ON cust.id = co.id_customer
            WHERE co.order_type = 'SUPPLEMENT' AND co.status <> {$archived}
              AND (co.create_order_date >= ? OR (" . OpenOrders::sql('co') . " AND DATE(co.expected_shipping_date) BETWEEN ? AND ?))
            ORDER BY co.expected_shipping_date IS NULL DESC, co.expected_shipping_date, cust.company, co.id", [$since, $fromDay, $toDay]);
    }

    /**
     * Lignes : produit, cartons, produit actif (final_product.active ; null = produit absent), fiche de planification
     * (id_item null = produit sans fiche avec réglages : hors calcul du plan, comme PlanningRepository::items) et mode MTO / MTS.
     * @return array<int, list<array>> par domówienie
     */
    public function lines(array $orderIds): array
    {
        if (!$orderIds) return [];
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        foreach ($this->all("SELECT cop.id_order, cop.id_product, cop.quantity_carton, fp.name, fp.active, s.id_item, s.mode
                FROM customer_order_product cop
                LEFT JOIN final_product fp ON fp.id = cop.id_product
                LEFT JOIN n_prod_item i ON i.id_final_product = cop.id_product
                LEFT JOIN n_prod_product_settings s ON s.id_item = i.id
                WHERE cop.id_order IN ({$in}) ORDER BY cop.id_order, fp.name, cop.id_product", array_map('intval', $orderIds)) as $r) {
            $out[(int) $r['id_order']][] = $r;
        }
        return $out;
    }

    /**
     * Zlecenia (OF) du plan reliés aux domówienia (n_prod_order_demand, écrit à la validation du plan), sans les annulés.
     * @return array<int, list<array{id_final_product:int, code:string, status:string, due_at:string}>> par domówienie
     */
    public function demand(array $orderIds): array
    {
        if (!$orderIds) return [];
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        foreach ($this->all("SELECT d.id_customer_order, d.id_final_product, o.code, o.status, o.due_at
                FROM n_prod_order_demand d INNER JOIN n_prod_order o ON o.id = d.id_order
                WHERE d.id_customer_order IN ({$in}) AND o.status <> 'cancelled' ORDER BY o.due_at, o.id", array_map('intval', $orderIds)) as $r) {
            $out[(int) $r['id_customer_order']][] = ['id_final_product' => (int) $r['id_final_product'], 'code' => (string) $r['code'], 'status' => (string) $r['status'], 'due_at' => (string) $r['due_at']];
        }
        return $out;
    }

    /** Plans de production validés, figés ou clos qui recouvrent [$from, $to] (le plus récent d'abord). */
    public function plans(string $from, string $to): array
    {
        return $this->all("SELECT id, period_start, period_end, status, version, validated_at FROM n_prod_plan
            WHERE status IN ('validated', 'frozen', 'closed') AND period_start <= ? AND period_end >= ?
            ORDER BY status = 'closed', version DESC, id DESC", [$to, $from]);
    }

    /** Commandes principales désignées (parent_order_id) : n°, date d'expédition, statut affiché. @return array<int, array> */
    public function ordersById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT co.id, DATE(co.expected_shipping_date) AS shipping_date, " . OpenOrders::effectiveStatusSql('co') . " AS status,
                IF(" . OpenOrders::sql('co') . ", 1, 0) AS is_open FROM customer_order co WHERE co.id IN ({$in})", $ids) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Commandes ordinaires (STANDARD, non archivées) des clients avec une expédition entre deux dates : commande principale
     * probable d'une domówienie sans parent_order_id (même client, même jour d'expédition).
     * @return array<string, array> « client|jour » => commande (la plus récente)
     */
    public function standardByCustomerDay(array $customerIds, string $from, string $to): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds), fn($id) => $id > 0)));
        if (!$customerIds) return [];
        $in = implode(',', array_fill(0, count($customerIds), '?'));
        $out = [];
        foreach ($this->all("SELECT co.id, co.id_customer, DATE(co.expected_shipping_date) AS shipping_date, " . OpenOrders::effectiveStatusSql('co') . " AS status,
                IF(" . OpenOrders::sql('co') . ", 1, 0) AS is_open
                FROM customer_order co WHERE co.id_customer IN ({$in}) AND COALESCE(co.order_type, 'STANDARD') = 'STANDARD'
                  AND co.status <> " . CustomerOrderStatus::ARCHIVED . " AND DATE(co.expected_shipping_date) BETWEEN ? AND ?
                ORDER BY co.id", [...$customerIds, $from, $to]) as $r) {
            $out[(int) $r['id_customer'] . '|' . $r['shipping_date']] = $r;
        }
        return $out;
    }

    /** @return array<int, array> résultats du jour par domówienie */
    public function checks(string $day): array
    {
        $out = [];
        foreach ($this->all("SELECT c.id_order, c.result, c.note, c.updated_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_supplement_check c LEFT JOIN user u ON u.id = c.id_user WHERE c.day = ?", [$day]) as $r) {
            $out[(int) $r['id_order']] = $r;
        }
        return $out;
    }

    /** Un résultat par domówienie et par jour : le dernier enregistré remplace le précédent. */
    public function saveCheck(int $orderId, string $day, string $result, ?string $note, string $flags, string $state, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_supplement_check (id_order, day, result, note, flags, production_state, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE result = VALUES(result), note = VALUES(note), flags = VALUES(flags), production_state = VALUES(production_state),
                id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$orderId, $day, $result, $note, mb_substr($flags, 0, 255), mb_substr($state, 0, 20), $userId]);
    }
}
