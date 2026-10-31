<?php
declare(strict_types=1);

namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Kontrola przyjęć dnia [230] : dostawy dnia (zamówienia dostawców z terminem w tym dniu albo przyjęte w tym dniu,
 * zaległe otwarte z ostatnich 14 dni), kontrola zamówienia w dniu (n_receiving_check), zdjęcia niezgodności.
 */
final class ReceivingWizardRepository extends WorkshopRepository
{
    protected string $table = 'n_receiving_check';

    /** Warunek « dostawa dnia » (zamówienie p, dzień jako dwa parametry) — ten sam w kreatorze i w stanie zadania. */
    public const DUE = "((p.status IN ('sent', 'partial', 'received') AND p.delivery_date = ?)
        OR (p.status IN ('partial', 'received') AND EXISTS (SELECT 1 FROM n_po_receipt rr JOIN n_po_line ll ON ll.id = rr.id_po_line WHERE ll.id_po = p.id AND rr.received_on = ?)))";

    /** Zamówienia dnia (due = 1) i zaległe otwarte (due = 0, termin w ostatnich 14 dniach) z kontrolą dnia. */
    public function orders(string $day): array
    {
        return $this->all(
            "SELECT p.id, p.code, p.status, p.delivery_date, p.id_supplier, s.name AS supplier_name, s.email AS supplier_email, s.order_lang,
                    (SELECT COUNT(*) FROM n_po_line l WHERE l.id_po = p.id) AS line_count,
                    " . self::DUE . " AS due,
                    c.lines_ok, c.lines_nc, c.arrived, c.nc_ids, c.checked_at,
                    COALESCE(NULLIF(TRIM(CONCAT(pe.name, ' ', pe.surname)), ''), NULLIF(u.displayed_name, ''), u.login) AS checked_by_name
             FROM n_po p
             JOIN supplier s ON s.id = p.id_supplier
             LEFT JOIN n_receiving_check c ON c.id_po = p.id AND c.day = ?
             LEFT JOIN n_prod_employee e ON e.id = c.id_employee
             LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN user u ON u.id = c.checked_by
             WHERE " . self::DUE . "
                OR (p.status IN ('sent', 'partial') AND p.delivery_date < ? AND p.delivery_date >= DATE_SUB(?, INTERVAL 14 DAY))
             ORDER BY due DESC, p.delivery_date, s.name, p.id",
            [$day, $day, $day, $day, $day, $day, $day]
        );
    }

    /** Stan zadania : dostawy dnia, sprawdzone, pozycje OK / niezgodne. */
    public function day(string $day): array
    {
        $r = $this->all(
            "SELECT COUNT(*) AS due, SUM(c.id IS NOT NULL) AS checked, COALESCE(SUM(c.lines_ok), 0) AS ok, COALESCE(SUM(c.lines_nc), 0) AS nc,
                    SUM(c.arrived = 0) AS missing
             FROM n_po p LEFT JOIN n_receiving_check c ON c.id_po = p.id AND c.day = ?
             WHERE " . self::DUE,
            [$day, $day, $day]
        )[0] ?? [];
        return ['due' => (int) ($r['due'] ?? 0), 'checked' => (int) ($r['checked'] ?? 0), 'ok' => (int) ($r['ok'] ?? 0), 'nc' => (int) ($r['nc'] ?? 0), 'missing' => (int) ($r['missing'] ?? 0)];
    }

    /** Pozycje zamówienia z kategorią surowca (chłodnia / mroźnia) i ilością przyjętą do tej pory. */
    public function lines(int $orderId): array
    {
        return $this->all(
            'SELECT l.id, l.id_raw_material, l.quantity, l.package_size, l.unit_price, rm.name, rm.name_fr, u.name AS unit, cm.name AS category,
                    COALESCE(r.qty, 0) AS received_qty
             FROM n_po_line l
             JOIN raw_material rm ON rm.id = l.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN category_material cm ON cm.id = rm.id_category
             LEFT JOIN (SELECT id_po_line, SUM(quantity) AS qty FROM n_po_receipt GROUP BY id_po_line) r ON r.id_po_line = l.id
             WHERE l.id_po = ? ORDER BY rm.name, l.id',
            [$orderId]
        );
    }

    /** Przyjęcia zamówienia w dniu (pozycja → ilość, partia, termin, temperatura, NC). */
    public function receiptsOn(int $orderId, string $day): array
    {
        return $this->all(
            'SELECT r.id, r.id_po_line, r.quantity, r.lot_number, r.best_before, r.temperature_c, r.id_nc
             FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line WHERE l.id_po = ? AND r.received_on = ? ORDER BY r.id',
            [$orderId, $day]
        );
    }

    public function check(int $orderId, string $day): ?array
    {
        return $this->all('SELECT * FROM n_receiving_check WHERE id_po = ? AND day = ?', [$orderId, $day])[0] ?? null;
    }

    /** Kontrola zamówienia w dniu (ponowna kontrola dopisuje pozycje i niezgodności). */
    public function saveCheck(int $orderId, string $day, int $ok, int $nc, bool $arrived, array $ncIds, ?int $userId, ?int $employeeId = null): void
    {
        $prev = $this->check($orderId, $day);
        $ids = array_values(array_unique(array_merge($prev ? (json_decode((string) $prev['nc_ids'], true) ?: []) : [], $ncIds)));
        $this->db->prepare(
            'INSERT INTO n_receiving_check (id_po, day, lines_ok, lines_nc, arrived, nc_ids, checked_by, id_employee, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE lines_ok = lines_ok + VALUES(lines_ok), lines_nc = lines_nc + VALUES(lines_nc), arrived = VALUES(arrived),
                                     nc_ids = VALUES(nc_ids), checked_by = VALUES(checked_by), id_employee = VALUES(id_employee), checked_at = VALUES(checked_at)'
        )->execute([$orderId, $day, $ok, $nc, $arrived ? 1 : 0, $ids ? json_encode($ids) : null, $userId, $employeeId]);
    }

    /** Przyjęcie pozycji w dniu (ostatnie) — powiązanie z niezgodnością. */
    public function lastReceiptOf(int $lineId, string $day): ?int
    {
        $id = $this->all('SELECT MAX(id) AS id FROM n_po_receipt WHERE id_po_line = ? AND received_on = ?', [$lineId, $day])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function addPhoto(int $ncId, string $mime, string $content, ?int $userId): int
    {
        $st = $this->db->prepare('INSERT INTO n_nc_photo (id_nc, mime, content, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
        $st->bindValue(1, $ncId, \PDO::PARAM_INT);
        $st->bindValue(2, $mime);
        $st->bindValue(3, $content, \PDO::PARAM_LOB);
        $st->bindValue(4, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $st->execute();
        return (int) $this->db->lastInsertId();
    }

    public function photo(int $id): ?array
    {
        return $this->all('SELECT id, id_nc, mime, content FROM n_nc_photo WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return array<int, int[]> niezgodność => id zdjęć */
    public function photoIds(array $ncIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $ncIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT id, id_nc FROM n_nc_photo WHERE id_nc IN (' . implode(',', $ids) . ') ORDER BY id') as $r) $out[(int) $r['id_nc']][] = (int) $r['id'];
        return $out;
    }

    /** Niezgodności kontroli (podsumowanie, szkic e-maila). */
    public function ncs(array $ncIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $ncIds)));
        if (!$ids) return [];
        return $this->all(
            'SELECT n.id, n.number, n.reported_at, n.id_supplier, n.id_raw_material, n.description, n.severity, n.quantity, n.unit, n.immediate_action, n.status,
                    rm.name AS material_name, s.name AS supplier_name
             FROM n_nc n LEFT JOIN raw_material rm ON rm.id = n.id_raw_material LEFT JOIN supplier s ON s.id = n.id_supplier
             WHERE n.id IN (' . implode(',', $ids) . ') ORDER BY n.id'
        );
    }
}
