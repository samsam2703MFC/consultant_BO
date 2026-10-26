<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Zaopatrzenie : surowce i opakowania (tryb, stany, inwentaryzacje), receptury produktów na karton, zużycie
 * z produkcji, zamówienia do dostawców, ich linie i przyjęcia. Ilości w jednostce bazowej surowca.
 */
final class SupplyRepository extends WorkshopRepository
{
    protected string $table = 'n_po';
    protected array $writable = ['code', 'id_supplier', 'status', 'week_start', 'delivery_date', 'language', 'email_to', 'note', 'id_message', 'sent_at', 'sent_by', 'created_by'];

    /** Surowce z jednostką, kategorią (grupa 1 = opakowanie), dostawcą bieżącym, cennikiem i trybem zaopatrzenia. */
    public function materials(?array $ids = null): array
    {
        if ($ids === []) return [];
        $where = $ids === null ? '' : ' WHERE rm.id IN (' . implode(',', array_map('intval', $ids)) . ')';
        return $this->all(
            "SELECT rm.id, rm.name, rm.name_fr, u.name AS unit, rm.price_per_unit, rm.package_size_in_base_unit AS package_size,
                    COALESCE(c.id_group, 0) = 1 AS packaging, c.name AS category,
                    rm.id_current_supplier AS supplier_id, s.name AS supplier_name, s.email AS supplier_email,
                    COALESCE(sp.supply_mode, 'order') AS supply_mode, sp.stock_min, sp.stock_max
             FROM raw_material rm
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN category_material c ON c.id = rm.id_category
             LEFT JOIN supplier s ON s.id = rm.id_current_supplier
             LEFT JOIN n_mp_supply sp ON sp.id_raw_material = rm.id" . $where . '
             ORDER BY rm.name, rm.id'
        );
    }

    public function materialExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM raw_material WHERE id = ?', [$id]);
    }

    /** @return array<int, array{count_date:string, quantity:float}> ostatnia inwentaryzacja surowca */
    public function lastCounts(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_raw_material, count_date, quantity FROM n_mp_count ORDER BY id_raw_material, count_date, id') as $r) {
            $out[(int) $r['id_raw_material']] = ['count_date' => $r['count_date'], 'quantity' => (float) $r['quantity']];
        }
        return $out;
    }

    /** @return array<int, float> przyjęcia po dniu ostatniej inwentaryzacji (bez inwentaryzacji : wszystkie) */
    public function receivedSinceCount(): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT l.id_raw_material, SUM(r.quantity) AS qty
             FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line
             LEFT JOIN (SELECT id_raw_material, MAX(count_date) AS d FROM n_mp_count GROUP BY id_raw_material) lc ON lc.id_raw_material = l.id_raw_material
             WHERE lc.d IS NULL OR r.received_on > lc.d
             GROUP BY l.id_raw_material'
        ) as $r) $out[(int) $r['id_raw_material']] = (float) $r['qty'];
        return $out;
    }

    /** @return array<int, float> zużycie z produkcji po dniu ostatniej inwentaryzacji : kartony wyprodukowane × receptura na karton */
    public function consumedSinceCount(): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT fri.material_id, SUM(fri.quantity) AS qty
             FROM (SELECT id_raw_material, MAX(count_date) AS d FROM n_mp_count GROUP BY id_raw_material) lc
             JOIN flattened_recipe_ingredient fri ON fri.material_id = lc.id_raw_material
             JOIN final_product fp ON fp.id_recipe = fri.recipe_id
             JOIN carton_stock cs ON cs.id_product = fp.id AND cs.date_add > lc.d
             GROUP BY fri.material_id'
        ) as $r) $out[(int) $r['material_id']] = (float) $r['qty'];
        return $out;
    }

    /** @return array<int, float> zużycie z produkcji na [from, to] (średnia tygodniowa liczona w serwisie) */
    public function consumed(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT fri.material_id, SUM(fri.quantity) AS qty
             FROM carton_stock cs
             JOIN final_product fp ON fp.id = cs.id_product
             JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             WHERE cs.date_add BETWEEN ? AND ?
             GROUP BY fri.material_id',
            [$from, $to]
        ) as $r) $out[(int) $r['material_id']] = (float) $r['qty'];
        return $out;
    }

    /** @return array<int, list<array{material_id:int, quantity:float}>> karta produktu → receptura na karton */
    public function recipes(): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT i.id AS item_id, fri.material_id, fri.quantity
             FROM n_prod_item i
             JOIN final_product fp ON fp.id = i.id_final_product AND fp.ppc > 0
             JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             WHERE i.item_type = 'product'"
        ) as $r) $out[(int) $r['item_id']][] = ['material_id' => (int) $r['material_id'], 'quantity' => (float) $r['quantity']];
        return $out;
    }

    /**
     * Dostawy tygodnia (plan zespołów) : linie zamówień wysłanych / przyjętych z terminem dostawy w okresie, a bez
     * terminu — zamówienia jeszcze w drodze na ten tydzień (week_start w okresie).
     */
    public function deliveries(string $from, string $to): array
    {
        return $this->all(
            "SELECT p.id, p.code, p.status, p.delivery_date, s.name AS supplier_name, l.id_raw_material, l.quantity, rm.name, rm.name_fr, u.name AS unit,
                    COALESCE(r.qty, 0) AS received_qty
             FROM n_po p
             JOIN supplier s ON s.id = p.id_supplier
             JOIN n_po_line l ON l.id_po = p.id
             JOIN raw_material rm ON rm.id = l.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN (SELECT id_po_line, SUM(quantity) AS qty FROM n_po_receipt GROUP BY id_po_line) r ON r.id_po_line = l.id
             WHERE p.status IN ('sent', 'partial', 'received')
               AND (p.delivery_date BETWEEN ? AND ? OR (p.delivery_date IS NULL AND p.status IN ('sent', 'partial') AND p.week_start BETWEEN ? AND ?))
             ORDER BY COALESCE(p.delivery_date, p.week_start), s.name, p.id, rm.name",
            [$from, $to, $from, $to]
        );
    }

    /**
     * Dostawy tygodnia : zamówienia wysłane / przyjęte z terminem w [from, to] albo przyjęte w tych dniach, oraz otwarte
     * (wysłane, częściowo przyjęte) z terminem wcześniejszym (zaległe) albo bez terminu ; linie z przyjętą ilością.
     */
    public function schedule(string $from, string $to): array
    {
        return $this->all(
            "SELECT p.id, p.code, p.status, p.delivery_date, p.week_start, p.id_supplier, s.name AS supplier_name, s.phone AS supplier_phone,
                    l.id_raw_material, l.quantity, rm.name, rm.name_fr, u.name AS unit, COALESCE(c.id_group, 0) = 1 AS packaging,
                    COALESCE(r.qty, 0) AS received_qty, r.last_on
             FROM n_po p
             JOIN supplier s ON s.id = p.id_supplier
             JOIN n_po_line l ON l.id_po = p.id
             JOIN raw_material rm ON rm.id = l.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN category_material c ON c.id = rm.id_category
             LEFT JOIN (SELECT id_po_line, SUM(quantity) AS qty, MAX(received_on) AS last_on FROM n_po_receipt GROUP BY id_po_line) r ON r.id_po_line = l.id
             WHERE (p.status IN ('sent', 'partial', 'received') AND p.delivery_date BETWEEN ? AND ?)
                OR (p.status IN ('sent', 'partial') AND (p.delivery_date IS NULL OR p.delivery_date < ?))
                OR (p.status IN ('partial', 'received') AND EXISTS (SELECT 1 FROM n_po_receipt rr JOIN n_po_line ll ON ll.id = rr.id_po_line
                                                                    WHERE ll.id_po = p.id AND rr.received_on BETWEEN ? AND ?))
             ORDER BY COALESCE(p.delivery_date, p.week_start), s.name, p.id, rm.name",
            [$from, $to, $from, $from, $to]
        );
    }

    /** Dostawcy (aktywni i ci, którzy są bieżącym dostawcą surowca albo mają zamówienia). */
    public function suppliersWithSupply(): array
    {
        return $this->all(
            "SELECT s.id, s.name, s.phone, s.email, s.is_active FROM supplier s
             WHERE s.is_active = 1 OR EXISTS (SELECT 1 FROM raw_material rm WHERE rm.id_current_supplier = s.id) OR EXISTS (SELECT 1 FROM n_po p WHERE p.id_supplier = s.id)
             ORDER BY s.name, s.id"
        );
    }

    /** Co dostarcza dostawca : surowce, których jest bieżącym dostawcą, i surowce z jego zamówień i faktur od daty. */
    public function suppliedMaterials(string $since): array
    {
        return $this->all(
            "SELECT x.id_supplier, rm.id, rm.name, rm.name_fr, COALESCE(c.id_group, 0) = 1 AS packaging, MAX(x.current_supplier) AS current_supplier
             FROM (SELECT rm.id_current_supplier AS id_supplier, rm.id AS id_raw_material, 1 AS current_supplier FROM raw_material rm WHERE rm.id_current_supplier IS NOT NULL
                   UNION ALL
                   SELECT p.id_supplier, l.id_raw_material, 0 FROM n_po p JOIN n_po_line l ON l.id_po = p.id WHERE p.status <> 'cancelled' AND p.created_at >= ?
                   UNION ALL
                   SELECT i.id_supplier, il.id_raw_material, 0 FROM n_inv i JOIN n_inv_line il ON il.id_inv = i.id
                   WHERE i.id_supplier IS NOT NULL AND il.id_raw_material IS NOT NULL AND i.issue_date >= ?) x
             JOIN raw_material rm ON rm.id = x.id_raw_material
             LEFT JOIN category_material c ON c.id = rm.id_category
             GROUP BY x.id_supplier, rm.id, rm.name, rm.name_fr, c.id_group
             ORDER BY x.id_supplier, rm.name",
            [$since, $since]
        );
    }

    /** Dni dostaw od daty : przyjęcia zamówień i daty faktur zakupowych (dostawca, dzień). */
    public function deliveryDays(string $since): array
    {
        return $this->all(
            "SELECT p.id_supplier, r.received_on AS day FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line JOIN n_po p ON p.id = l.id_po WHERE r.received_on >= ?
             UNION
             SELECT i.id_supplier, i.issue_date FROM n_inv i WHERE i.id_supplier IS NOT NULL AND i.issue_date >= ?
             ORDER BY 1, 2",
            [$since, $since]
        );
    }

    /** Otwarte zamówienia na dostawcę : liczba i najbliższy termin od dnia. */
    public function openBySupplier(string $today): array
    {
        return $this->all(
            "SELECT id_supplier, COUNT(*) AS open_orders, MIN(CASE WHEN delivery_date >= ? THEN delivery_date END) AS next_delivery
             FROM n_po WHERE status IN ('sent', 'partial') GROUP BY id_supplier",
            [$today]
        );
    }

    /** @return array<int, float> zamówione, jeszcze nie przyjęte (szkice, wysłane, częściowo przyjęte) */
    public function onOrder(): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT l.id_raw_material, SUM(GREATEST(l.quantity - COALESCE(r.qty, 0), 0)) AS qty
             FROM n_po_line l JOIN n_po p ON p.id = l.id_po
             LEFT JOIN (SELECT id_po_line, SUM(quantity) AS qty FROM n_po_receipt GROUP BY id_po_line) r ON r.id_po_line = l.id
             WHERE p.status IN ('draft', 'sent', 'partial')
             GROUP BY l.id_raw_material"
        ) as $r) $out[(int) $r['id_raw_material']] = (float) $r['qty'];
        return $out;
    }

    public function saveSupply(int $materialId, array $data): void
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_mp_supply (id_raw_material, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')
                            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)))
            ->execute([$materialId, ...array_values($data)]);
    }

    public function supply(int $materialId): ?array
    {
        return $this->all('SELECT * FROM n_mp_supply WHERE id_raw_material = ?', [$materialId])[0] ?? null;
    }

    public function addCount(int $materialId, string $day, float $quantity, ?string $note, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_mp_count (id_raw_material, count_date, quantity, note, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$materialId, $day, $quantity, $note, $userId]);
        return (int) $this->db->lastInsertId();
    }

    // --- Zamówienia ---------------------------------------------------------------------------------------------

    public function supplier(int $id): ?array
    {
        return $this->all('SELECT id, name, email, phone FROM supplier WHERE id = ?', [$id])[0] ?? null;
    }

    public function setSupplierEmail(int $id, string $email): void
    {
        $this->db->prepare('UPDATE supplier SET email = ? WHERE id = ?')->execute([$email, $id]);
    }

    public function nextCode(string $day): string
    {
        $prefix = 'ZD-' . str_replace('-', '', $day) . '-';
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM n_po WHERE code LIKE ?');
        $stmt->execute([$prefix . '%']);
        return sprintf('%s%03d', $prefix, (int) $stmt->fetchColumn() + 1);
    }

    /** Zamówienia z dostawcą, liczbą linii, wartością zamówioną i przyjętą (cena oczekiwana / rzeczywista). */
    public function orders(?string $status): array
    {
        $where = $status === null ? '' : ($status === 'open' ? " WHERE p.status IN ('draft', 'sent', 'partial')" : ' WHERE p.status = ?');
        return $this->summaries($where, $status === null || $status === 'open' ? [] : [$status]);
    }

    public function orderSummary(int $id): ?array
    {
        return $this->summaries(' WHERE p.id = ?', [$id])[0] ?? null;
    }

    private function summaries(string $where, array $params): array
    {
        return $this->all(
            'SELECT p.*, s.name AS supplier_name, COUNT(l.id) AS line_count, COALESCE(SUM(l.quantity * l.unit_price), 0) AS value_ordered,
                    COALESCE(SUM(r.qty), 0) AS received_qty, COALESCE(SUM(l.quantity), 0) AS ordered_qty,
                    COALESCE(SUM(r.value), 0) AS value_received, COALESCE(SUM(r.qty * l.unit_price), 0) AS value_received_expected,
                    SUM(r.price_gap) AS price_gaps
             FROM n_po p
             LEFT JOIN supplier s ON s.id = p.id_supplier
             LEFT JOIN n_po_line l ON l.id_po = p.id
             LEFT JOIN (SELECT r.id_po_line, SUM(r.quantity) AS qty, SUM(r.quantity * COALESCE(r.unit_price, l2.unit_price)) AS value,
                               SUM(r.unit_price IS NOT NULL AND l2.unit_price IS NOT NULL AND ABS(r.unit_price - l2.unit_price) > 0.000001) AS price_gap
                        FROM n_po_receipt r JOIN n_po_line l2 ON l2.id = r.id_po_line GROUP BY r.id_po_line) r ON r.id_po_line = l.id'
            . $where . ' GROUP BY p.id ORDER BY p.created_at DESC, p.id DESC LIMIT 300',
            $params
        );
    }

    public function order(int $id): ?array
    {
        return $this->all('SELECT p.*, s.name AS supplier_name, s.email AS supplier_email, s.phone AS supplier_phone FROM n_po p LEFT JOIN supplier s ON s.id = p.id_supplier WHERE p.id = ?', [$id])[0] ?? null;
    }

    public function lines(int $orderId): array
    {
        return $this->all(
            'SELECT l.*, rm.name, rm.name_fr, u.name AS unit, rm.price_per_unit AS catalog_price,
                    COALESCE(r.qty, 0) AS received_qty, r.value AS received_value, r.last_price, r.last_day
             FROM n_po_line l
             JOIN raw_material rm ON rm.id = l.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN (SELECT r.id_po_line, SUM(r.quantity) AS qty, SUM(r.quantity * COALESCE(r.unit_price, l2.unit_price)) AS value,
                               SUBSTRING_INDEX(GROUP_CONCAT(r.unit_price ORDER BY r.received_on, r.id SEPARATOR \'|\'), \'|\', -1) AS last_price,
                               MAX(r.received_on) AS last_day
                        FROM n_po_receipt r JOIN n_po_line l2 ON l2.id = r.id_po_line GROUP BY r.id_po_line) r ON r.id_po_line = l.id
             WHERE l.id_po = ? ORDER BY rm.name, l.id',
            [$orderId]
        );
    }

    public function receipts(int $orderId): array
    {
        return $this->all(
            "SELECT r.*, l.id_raw_material, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line
             LEFT JOIN n_prod_employee e ON e.id = r.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE l.id_po = ? ORDER BY r.received_on, r.id",
            [$orderId]
        );
    }

    /** Zamówienie utworzone z faktury (dostawa już przyjęta). */
    public function insertReceived(int $supplierId, string $day, string $note, ?int $userId): int
    {
        return $this->insert(['code' => $this->nextCode(date('Y-m-d')), 'id_supplier' => $supplierId, 'status' => 'received', 'delivery_date' => $day,
            'language' => 'pl', 'note' => mb_substr($note, 0, 1000), 'sent_at' => date('Y-m-d H:i:s'), 'sent_by' => 'manual', 'created_by' => $userId]);
    }

    public function insertLine(int $orderId, int $materialId, float $quantity, ?float $packageSize, ?float $unitPrice): int
    {
        $this->db->prepare('INSERT INTO n_po_line (id_po, id_raw_material, quantity, package_size, unit_price) VALUES (?, ?, ?, ?, ?)')
            ->execute([$orderId, $materialId, $quantity, $packageSize, $unitPrice]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteLines(int $orderId): void
    {
        $this->db->prepare('DELETE FROM n_po_line WHERE id_po = ?')->execute([$orderId]);
    }

    /** Przyjęcie linii ; partia i data ważności (asystent « Przyjęcie dostawy ») — podpowiedź dla tabletu (FEFO). */
    public function addReceipt(int $lineId, string $day, float $quantity, ?float $unitPrice, ?int $userId, ?string $lot = null, ?string $bestBefore = null, ?int $employeeId = null): int
    {
        $this->db->prepare('INSERT INTO n_po_receipt (id_po_line, received_on, quantity, unit_price, lot_number, best_before, created_by, id_employee) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$lineId, $day, $quantity, $unitPrice, $lot, $bestBefore, $userId, $employeeId]);
        return (int) $this->db->lastInsertId();
    }

    /** Salarié de production (tablette) : « Imię Nazwisko ». */
    public function employeeName(int $employeeId): ?string
    {
        $name = $this->db->prepare("SELECT TRIM(CONCAT(pe.name, ' ', pe.surname)) FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.id = ?");
        $name->execute([$employeeId]);
        $v = $name->fetchColumn();
        return $v === false || $v === '' ? null : (string) $v;
    }

    public function linkReceiptNc(int $receiptId, int $ncId): void
    {
        $this->db->prepare('UPDATE n_po_receipt SET id_nc = ? WHERE id = ?')->execute([$ncId, $receiptId]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM n_po WHERE id = ?')->execute([$id]);
    }
}
