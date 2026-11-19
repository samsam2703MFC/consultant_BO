<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [352] Partie surowców (makieta 68) : rejestr n_material_lot — pozycje faktur (KSeF / PDF) przypisane do surowca,
 * przyjęcia dostaw z numerem partii, partie wpisane ręcznie na tablecie ; surowce śledzone (n_material_trace).
 */
final class MaterialLotRepository extends WorkshopRepository
{
    protected string $table = 'n_material_lot';
    protected array $writable = ['id_raw_material', 'lot_number', 'best_before', 'source', 'id_inv_line', 'id_po_receipt', 'id_supplier',
        'received_on', 'quantity', 'active', 'off_reason', 'updated_by'];

    /**
     * Pozycje faktur towarowych (nie odrzuconych) z surowcem, z ostatnich $days dni, jeszcze nie w rejestrze : nowa partia
     * bez numeru (nieaktywna, « czeka na numer »). Ilość w jednostce bazowej (ilość × przelicznik). @return int dodane
     */
    public function addInvoiceLines(int $days): int
    {
        $st = $this->db->prepare("INSERT INTO n_material_lot (id_raw_material, source, id_inv_line, id_supplier, received_on, quantity, active, off_reason)
            SELECT l.id_raw_material, 'ksef', l.id, i.id_supplier, COALESCE(i.issue_date, DATE(i.created_at)),
                   l.quantity * COALESCE(NULLIF(l.factor, 0), 1), 0, 'no_number'
            FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv
            WHERE l.id_raw_material IS NOT NULL AND i.kind = 'goods' AND i.status <> 'rejected'
              AND COALESCE(i.issue_date, DATE(i.created_at)) >= CURDATE() - INTERVAL ? DAY
              AND NOT EXISTS (SELECT 1 FROM n_material_lot m WHERE m.id_inv_line = l.id)");
        $st->execute([$days]);
        return $st->rowCount();
    }

    /** Przyjęcia z numerem partii z ostatnich $days dni, jeszcze nie w rejestrze. */
    public function newReceipts(int $days): array
    {
        return $this->all("SELECT r.id, r.lot_number, r.best_before, r.received_on, pl.id_raw_material, po.id_supplier
            FROM n_po_receipt r JOIN n_po_line pl ON pl.id = r.id_po_line JOIN n_po po ON po.id = pl.id_po
            WHERE r.lot_number IS NOT NULL AND r.lot_number <> '' AND r.received_on >= CURDATE() - INTERVAL ? DAY
              AND NOT EXISTS (SELECT 1 FROM n_material_lot m WHERE m.id_po_receipt = r.id)
            ORDER BY r.received_on, r.id", [$days]);
    }

    /** Partia z faktury bez numeru i bez przyjęcia : ten sam surowiec i dostawca, data ±$window dni (najbliższa). */
    public function invoiceLotFor(int $materialId, ?int $supplierId, string $day, int $window): ?int
    {
        $id = $this->all("SELECT id FROM n_material_lot
            WHERE source = 'ksef' AND id_po_receipt IS NULL AND (lot_number IS NULL OR lot_number = '') AND id_raw_material = ?
              AND id_supplier <=> ? AND ABS(DATEDIFF(received_on, ?)) <= ?
            ORDER BY ABS(DATEDIFF(received_on, ?)), id LIMIT 1", [$materialId, $supplierId, $day, $window, $day])[0]['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** Partie aktywne po dacie ważności : wyłączone (powód « expired »). */
    public function expire(string $today): int
    {
        $st = $this->db->prepare("UPDATE n_material_lot SET active = 0, off_reason = 'expired' WHERE active = 1 AND best_before IS NOT NULL AND best_before < ?");
        $st->execute([$today]);
        return $st->rowCount();
    }

    /** Partia o tym numerze dla surowca (wpis ręczny na tablecie nie dubluje rejestru). */
    public function byNumber(int $materialId, string $lot): ?array
    {
        return $this->all('SELECT * FROM n_material_lot WHERE id_raw_material = ? AND lot_number = ? ORDER BY active DESC, id DESC LIMIT 1', [$materialId, $lot])[0] ?? null;
    }

    /**
     * Rejestr : partie z ostatnich $days dni (i starsze, wciąż aktywne), surowiec, dostawca, faktura, przyjęcie, użycie
     * w produkcji (partie wyrobu z tą partią — wybrane z listy albo z tym samym numerem). $q : nazwa surowca albo numer.
     */
    public function register(int $days, ?string $q, bool $activeOnly): array
    {
        $where = ['(m.received_on >= CURDATE() - INTERVAL ? DAY OR m.active = 1)'];
        $params = [$days];
        if ($q !== null && $q !== '') { $where[] = '(rm.name LIKE ? OR m.lot_number LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
        if ($activeOnly) $where[] = 'm.active = 1';
        return $this->all("SELECT m.*, rm.name AS material, u.name AS unit, COALESCE(t.tracked, 1) AS tracked, s.name AS supplier,
                i.number AS invoice_number, i.source AS invoice_source, r.received_on AS receipt_on,
                (SELECT COUNT(DISTINCT ol.id_output) FROM n_prod_output_lot ol
                  WHERE ol.id_material_lot = m.id OR (ol.id_material_lot IS NULL AND ol.id_material = m.id_raw_material
                        AND m.lot_number IS NOT NULL AND m.lot_number <> '' AND ol.lot_number = m.lot_number)) AS used
            FROM n_material_lot m
            JOIN raw_material rm ON rm.id = m.id_raw_material
            LEFT JOIN unit u ON u.id = rm.id_unit
            LEFT JOIN n_material_trace t ON t.id_raw_material = m.id_raw_material
            LEFT JOIN supplier s ON s.id = m.id_supplier
            LEFT JOIN n_inv_line il ON il.id = m.id_inv_line LEFT JOIN n_inv i ON i.id = il.id_inv
            LEFT JOIN n_po_receipt r ON r.id = m.id_po_receipt
            WHERE " . implode(' AND ', $where) . "
            ORDER BY rm.name, m.id_raw_material, m.active DESC, m.best_before IS NULL, m.best_before, m.received_on DESC, m.id DESC", $params);
    }

    /** @return array<int, bool> surowiec => śledzony (domyślnie tak) */
    public function tracked(array $materialIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $materialIds)));
        if (!$ids) return [];
        $out = array_fill_keys($ids, true);
        foreach ($this->all('SELECT id_raw_material, tracked FROM n_material_trace WHERE id_raw_material IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id_raw_material']] = (bool) (int) $r['tracked'];
        }
        return $out;
    }

    public function setTracked(int $materialId, bool $tracked, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_material_trace (id_raw_material, tracked, updated_by) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE tracked = VALUES(tracked), updated_by = VALUES(updated_by)')
            ->execute([$materialId, $tracked ? 1 : 0, $userId]);
    }

    public function materialExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM raw_material WHERE id = ?', [$id]);
    }

    /**
     * Lista tabletu : partie aktywne z numerem, ważne dziś (albo bez DLC), najkrótsza DLC najpierw (FEFO), do $limit na
     * surowiec. @return array<int, list<array>> surowiec => partie
     */
    public function forTablet(array $materialIds, string $today, int $limit = 6): array
    {
        $ids = array_values(array_unique(array_map('intval', $materialIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all("SELECT m.id, m.id_raw_material, m.lot_number, m.best_before, m.received_on, m.source, s.name AS supplier, i.number AS invoice_number
                FROM n_material_lot m
                LEFT JOIN supplier s ON s.id = m.id_supplier
                LEFT JOIN n_inv_line il ON il.id = m.id_inv_line LEFT JOIN n_inv i ON i.id = il.id_inv
                WHERE m.id_raw_material IN (" . implode(',', $ids) . ") AND m.active = 1 AND m.lot_number IS NOT NULL AND m.lot_number <> ''
                  AND (m.best_before IS NULL OR m.best_before >= ?)
                ORDER BY m.best_before IS NULL, m.best_before, m.received_on, m.id", [$today]) as $r) {
            $m = (int) $r['id_raw_material'];
            if (count($out[$m] ?? []) >= $limit) continue;
            $out[$m][] = $r;
        }
        return $out;
    }
}
