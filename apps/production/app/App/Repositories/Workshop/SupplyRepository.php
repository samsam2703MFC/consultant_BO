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
            'SELECT r.*, l.id_raw_material FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line WHERE l.id_po = ? ORDER BY r.received_on, r.id',
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

    public function addReceipt(int $lineId, string $day, float $quantity, ?float $unitPrice, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_po_receipt (id_po_line, received_on, quantity, unit_price, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$lineId, $day, $quantity, $unitPrice, $userId]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM n_po WHERE id = ?')->execute([$id]);
    }
}
