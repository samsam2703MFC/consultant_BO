<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Finanse : faktury usług (koszty firmy) po miesiącach, « nie w tym miesiącu », zamknięcie miesiąca usług i jego wiersze
 * w kalkulatorze kosztów (koszty stałe, media, logistyka), typy mediów i logistyki kalkulatora.
 */
final class FinanceRepository extends WorkshopRepository
{
    protected string $table = 'n_service_month';

    public function mediaTypes(): array
    {
        return $this->all('SELECT id, code, name FROM cost_media_type WHERE is_active = 1 ORDER BY id');
    }

    public function logisticsTypes(): array
    {
        return $this->all('SELECT id, code, name FROM cost_logistics_type WHERE is_active = 1 ORDER BY sort_order, id');
    }

    /** Faktury usług miesiąca (bez odrzuconych). */
    public function serviceInvoices(string $ym): array
    {
        return $this->all(
            "SELECT i.*, s.name AS supplier_name, f.filename, f.mime, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS decided_by_name
             FROM n_inv i LEFT JOIN supplier s ON s.id = i.id_supplier LEFT JOIN n_inv_file f ON f.id_inv = i.id LEFT JOIN user u ON u.id = i.decided_by
             WHERE i.kind = 'service' AND i.status <> 'rejected' AND i.service_month = ?
             ORDER BY i.cost_category, COALESCE(i.issue_date, DATE(i.created_at)), i.id",
            [$ym]
        );
    }

    /** Sumy usług na miesiąc, kategorię i sprzedawcę (oczekiwane koszty, chipy miesięcy). */
    public function serviceTotals(array $yms): array
    {
        if (!$yms) return [];
        $in = implode(',', array_fill(0, count($yms), '?'));
        return $this->all(
            "SELECT i.service_month AS ym, i.cost_category, i.seller_nip, i.seller_name, COUNT(*) AS n, SUM(i.status = 'open') AS open,
                    SUM(COALESCE(i.cost_amount, IF(i.currency = 'PLN', i.total_net, 0))) AS amount
             FROM n_inv i WHERE i.kind = 'service' AND i.status <> 'rejected' AND i.service_month IN ({$in})
             GROUP BY i.service_month, i.cost_category, i.seller_nip, i.seller_name",
            array_values($yms)
        );
    }

    /** Faktury usług bez miesiąca albo bez kategorii (nie powinno być, ale nic nie ginie). */
    public function serviceWithoutMonth(): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_inv WHERE kind = 'service' AND status <> 'rejected' AND (service_month IS NULL OR cost_category IS NULL)")[0]['n'] ?? 0);
    }

    public function openServices(): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_inv WHERE kind = 'service' AND status = 'open'")[0]['n'] ?? 0);
    }

    // --- « Nie w tym miesiącu » -----------------------------------------------------------------------------------------

    public function marks(string $ym): array
    {
        return $this->all('SELECT cost_category, seller_key FROM n_service_month_mark WHERE ym = ?', [$ym]);
    }

    public function setMark(string $ym, string $category, string $sellerKey, ?int $userId): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_service_month_mark (ym, cost_category, seller_key, created_by) VALUES (?, ?, ?, ?)')->execute([$ym, $category, $sellerKey, $userId]);
    }

    public function deleteMark(string $ym, string $category, string $sellerKey): void
    {
        $this->db->prepare('DELETE FROM n_service_month_mark WHERE ym = ? AND cost_category = ? AND seller_key = ?')->execute([$ym, $category, $sellerKey]);
    }

    // --- Zamknięcie miesiąca usług --------------------------------------------------------------------------------------

    public function closed(string $ym): ?array
    {
        return $this->all("SELECT m.*, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS closed_by_name FROM n_service_month m LEFT JOIN user u ON u.id = m.closed_by WHERE m.ym = ?", [$ym])[0] ?? null;
    }

    /** @return array<string, true> zamknięte miesiące z listy */
    public function closedMonths(array $yms): array
    {
        if (!$yms) return [];
        $in = implode(',', array_fill(0, count($yms), '?'));
        return array_fill_keys(array_column($this->all("SELECT ym FROM n_service_month WHERE ym IN ({$in})", array_values($yms)), 'ym'), true);
    }

    public function close(string $ym, float $total, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_service_month (ym, total, closed_by, closed_at) VALUES (?, ?, ?, NOW())')->execute([$ym, round($total, 2), $userId]);
    }

    public function reopen(string $ym): void
    {
        $this->db->prepare('DELETE FROM n_service_month WHERE ym = ?')->execute([$ym]);
        $this->db->prepare('DELETE FROM n_service_month_push WHERE ym = ?')->execute([$ym]);
    }

    public function pushes(string $ym): array
    {
        return $this->all('SELECT * FROM n_service_month_push WHERE ym = ? ORDER BY id', [$ym]);
    }

    public function addPush(string $ym, string $category, string $target, ?int $rowId, float $amount, ?array $replaced): void
    {
        $this->db->prepare('INSERT INTO n_service_month_push (ym, cost_category, target, row_id, amount, replaced) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$ym, $category, $target, $rowId, round($amount, 2), $replaced === null ? null : json_encode($replaced, JSON_UNESCAPED_UNICODE)]);
    }

    // --- Kalkulator kosztów (miesiąc, koszty stałe, media, logistyka) ---------------------------------------------------

    public function costMonth(int $year, int $month): ?array
    {
        return $this->all('SELECT id, year, month, status FROM cost_month WHERE year = ? AND month = ?', [$year, $month])[0] ?? null;
    }

    public function createCostMonth(int $year, int $month): int
    {
        $this->db->prepare("INSERT INTO cost_month (year, month, status) VALUES (?, ?, 'OPEN')")->execute([$year, $month]);
        return (int) $this->db->lastInsertId();
    }

    /** Wiersze kalkulatora miesiąca dla kategorii (bez wierszy zapisanych z faktur : $exclude). */
    public function fixedRows(int $monthId, string $category, array $exclude = []): array
    {
        return $this->without($this->all('SELECT id, name, amount, category, service_month, posting_month FROM cost_month_fixed_cost WHERE month_id = ? AND category = ? ORDER BY id', [$monthId, $category]), $exclude);
    }

    public function insertFixed(int $monthId, string $name, float $amount, string $category, string $ym): int
    {
        $this->db->prepare('INSERT INTO cost_month_fixed_cost (month_id, name, amount, category, service_month, posting_month) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$monthId, mb_substr($name, 0, 255), round($amount, 2), $category, $ym, $ym]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteFixed(int $id): void
    {
        $this->db->prepare('DELETE FROM cost_month_fixed_cost WHERE id = ?')->execute([$id]);
    }

    public function mediaRow(int $monthId, int $typeId): ?array
    {
        return $this->all('SELECT id, amount, note FROM cost_media_month WHERE cost_month_id = ? AND media_type_id = ? ORDER BY id LIMIT 1', [$monthId, $typeId])[0] ?? null;
    }

    public function insertMedia(int $monthId, int $typeId, float $amount, ?string $note): int
    {
        $this->db->prepare('INSERT INTO cost_media_month (cost_month_id, media_type_id, amount, note) VALUES (?, ?, ?, ?)')->execute([$monthId, $typeId, round($amount, 2), $note !== null ? mb_substr($note, 0, 255) : null]);
        return (int) $this->db->lastInsertId();
    }

    public function updateMedia(int $id, float $amount, ?string $note): void
    {
        $this->db->prepare('UPDATE cost_media_month SET amount = ?, note = ? WHERE id = ?')->execute([round($amount, 2), $note !== null ? mb_substr($note, 0, 255) : null, $id]);
    }

    public function deleteMedia(int $id): void
    {
        $this->db->prepare('DELETE FROM cost_media_month WHERE id = ?')->execute([$id]);
    }

    public function logisticsRows(int $monthId, int $typeId, array $exclude = []): array
    {
        return $this->without($this->all('SELECT id, logistics_type_id, amount, note FROM cost_month_logistics WHERE month_id = ? AND logistics_type_id = ? ORDER BY id', [$monthId, $typeId]), $exclude);
    }

    public function insertLogistics(int $monthId, int $typeId, float $amount, ?string $note): int
    {
        $this->db->prepare('INSERT INTO cost_month_logistics (month_id, logistics_type_id, amount, note) VALUES (?, ?, ?, ?)')->execute([$monthId, $typeId, round($amount, 2), $note !== null ? mb_substr($note, 0, 255) : null]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteLogistics(int $id): void
    {
        $this->db->prepare('DELETE FROM cost_month_logistics WHERE id = ?')->execute([$id]);
    }

    // --- Espace Finanse : linie do przypisania, zakupy surowca ---------------------------------------------------------

    /** Linie bez surowca na otwartych fakturach surowców (najnowsze najpierw). */
    public function unmappedLines(int $limit): array
    {
        return $this->all(
            "SELECT l.id, l.id_inv, l.name, l.unit, l.quantity, l.unit_price, l.net_value, i.number, i.issue_date, i.seller_name, i.seller_nip, i.id_supplier,
                    s.name AS supplier_name, i.currency
             FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv LEFT JOIN supplier s ON s.id = i.id_supplier
             WHERE l.id_raw_material IS NULL AND i.kind = 'goods' AND i.status = 'open'
             ORDER BY COALESCE(i.issue_date, DATE(i.created_at)) DESC, i.id DESC, l.line_no LIMIT " . max(1, min(500, $limit))
        );
    }

    /** Zakupy surowców (jednostki bazowe) z faktur od daty : średnio na miesiąc do skutku podwyżki. */
    public function purchasedSince(array $materialIds, string $since): array
    {
        if (!$materialIds) return [];
        $in = implode(',', array_fill(0, count($materialIds), '?'));
        $out = [];
        foreach ($this->all("SELECT l.id_raw_material, SUM(l.quantity * COALESCE(NULLIF(l.factor, 0), 1)) AS qty
                             FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv
                             WHERE l.id_raw_material IN ({$in}) AND i.kind = 'goods' AND i.status NOT IN ('disputed', 'rejected') AND i.is_correction = 0
                               AND COALESCE(i.issue_date, DATE(i.created_at)) >= ?
                             GROUP BY l.id_raw_material", [...array_values($materialIds), $since]) as $r) {
            $out[(int) $r['id_raw_material']] = (float) $r['qty'];
        }
        return $out;
    }

    private function without(array $rows, array $exclude): array
    {
        if (!$exclude) return $rows;
        $skip = array_flip(array_map('intval', $exclude));
        return array_values(array_filter($rows, fn($r) => !isset($skip[(int) $r['id']])));
    }
}
