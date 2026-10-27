<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Historia cen surowców (zł za jednostkę bazową, wyświetlane za kg / l) : faktury zakupowe przypisane do surowca,
 * przyjęcia z ceną z zamówień bez faktury w systemie i zmiany ceny w kartotece (raw_material_price_changelog).
 */
final class PriceHistoryRepository extends WorkshopRepository
{
    protected string $table = 'raw_material_price_changelog';

    /** Linie faktur (bez reklamowanych) : ilość w jednostkach bazowych i wartość netto. */
    public function invoicePoints(?array $materialIds, string $since): array
    {
        [$in, $params] = $this->in('l.id_raw_material', $materialIds);
        return $this->all("SELECT l.id_raw_material, COALESCE(i.issue_date, DATE(i.created_at)) AS day, i.id AS invoice_id, i.number,
                    i.id_supplier, COALESCE(NULLIF(TRIM(s.name), ''), i.seller_name) AS supplier,
                    l.quantity * COALESCE(NULLIF(l.factor, 0), 1) AS qty_base, COALESCE(l.net_value, l.unit_price * l.quantity) AS net
                FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv LEFT JOIN supplier s ON s.id = i.id_supplier
                WHERE l.id_raw_material IS NOT NULL AND i.status <> 'disputed' AND COALESCE(i.issue_date, DATE(i.created_at)) >= ?{$in}
                ORDER BY day, i.id, l.id", [$since, ...$params]);
    }

    /** Przyjęcia z ceną (za jednostkę bazową) z zamówień, do których nie ma faktury w systemie — bez liczenia dwa razy. */
    public function receiptPoints(?array $materialIds, string $since): array
    {
        [$in, $params] = $this->in('pl.id_raw_material', $materialIds);
        return $this->all("SELECT pl.id_raw_material, r.received_on AS day, p.id_supplier, TRIM(s.name) AS supplier, p.code, r.quantity AS qty_base, r.unit_price AS price
                FROM n_po_receipt r JOIN n_po_line pl ON pl.id = r.id_po_line JOIN n_po p ON p.id = pl.id_po LEFT JOIN supplier s ON s.id = p.id_supplier
                WHERE r.unit_price > 0 AND r.quantity > 0 AND r.received_on >= ? AND NOT EXISTS (SELECT 1 FROM n_inv i WHERE i.id_po = p.id){$in}
                ORDER BY r.received_on, r.id", [$since, ...$params]);
    }

    /** Zmiany ceny w kartotece : cena opakowania ÷ opakowanie = cena za jednostkę bazową, dostawca, źródło. */
    public function catalogPoints(?array $materialIds, string $since): array
    {
        [$in, $params] = $this->in('c.id_raw_material', $materialIds);
        return $this->all("SELECT c.id_raw_material, DATE(c.timestamp) AS day, c.timestamp, c.id_supplier, TRIM(s.name) AS supplier,
                    c.pack_price / c.package_size_in_base_unit AS price, c.source, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS user_name
                FROM raw_material_price_changelog c LEFT JOIN supplier s ON s.id = c.id_supplier LEFT JOIN user u ON u.id = c.id_user
                WHERE c.package_size_in_base_unit > 0 AND c.pack_price > 0 AND c.timestamp >= ?{$in}
                ORDER BY c.timestamp", [$since . ' 00:00:00', ...$params]);
    }

    public function materials(?array $ids = null): array
    {
        [$in, $params] = $this->in('rm.id', $ids);
        return $this->all("SELECT rm.id, rm.name, rm.name_fr, u.name AS unit, rm.price_per_unit, rm.id_current_supplier, TRIM(s.name) AS supplier, rm.is_part_of_package
                FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit LEFT JOIN supplier s ON s.id = rm.id_current_supplier
                WHERE 1 = 1{$in} ORDER BY rm.name", $params);
    }

    /** @return array{0: string, 1: list<int>} warunek « AND kolumna IN (…) » (pusty dla null) */
    private function in(string $column, ?array $ids): array
    {
        if ($ids === null) return ['', []];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [' AND 1 = 0', []];
        return [" AND {$column} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}
