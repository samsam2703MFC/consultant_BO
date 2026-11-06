<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;

/**
 * Repository for Product Margin (current margin) calculation data.
 * Aggregates data from last N cost_months for a given production section.
 */
class ProductMarginRepository extends BaseRepository
{
    protected string $table = 'final_product';

    // -------------------------------------------------------------------------
    // Product & section data
    // -------------------------------------------------------------------------

    public function getProductData(int $productId): array
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.id_category,
                    cp.name          AS category_name,
                    cp.target_margin_percentage,
                    fp.id_section,
                    ps.name          AS section_name,
                    fp.ppc,
                    fp.standard_price,
                    fp.pre_calculated_raw_price_per_carton,
                    fp.pre_calculated_raw_price_per_carton AS precalculated_material_cost,
                    fp.workload_points,
                    fp.id_recipe
                FROM final_product fp
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                LEFT JOIN production_sections ps ON fp.id_section = ps.id
                WHERE fp.id = :id
                LIMIT 1";
        $rows = $this->query($sql, ['id' => $productId]);
        return $rows[0] ?? [];
    }

    public function getAllProducts(string $orderBy = 'fp.name'): array
    {
        $safe = in_array($orderBy, ['fp.name', 'fp.id']) ? $orderBy : 'fp.name';
        $sql = "SELECT fp.id, fp.name, fp.id_section, ps.name AS section_name
                FROM final_product fp
                LEFT JOIN production_sections ps ON fp.id_section = ps.id
                WHERE fp.active = 1
                ORDER BY {$safe}";
        return $this->query($sql) ?: [];
    }

    /**
     * Returns all active categories with their products (and data needed for margin calc).
     * Ordered by category name, then product name.
     */
    public function getCategoriesWithProducts(): array
    {
        $sql = "SELECT
                    cp.id                           AS category_id,
                    cp.name                         AS category_name,
                    cp.target_margin_percentage     AS target_margin,
                    fp.id                           AS product_id,
                    fp.name                         AS product_name,
                    fp.id_section,
                    fp.standard_price,
                    ps.name                         AS section_name,
                    COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS foodcost
                FROM category_product cp
                LEFT JOIN final_product fp
                    ON fp.id_category = cp.id AND fp.active = 1
                LEFT JOIN production_sections ps
                    ON ps.id = fp.id_section
                ORDER BY cp.name ASC, fp.name ASC";
        return $this->query($sql) ?: [];
    }

    public function getSectionCosts(int $sectionId): array
    {
        $sql = "SELECT id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost
                FROM production_sections
                WHERE id = :id LIMIT 1";
        $rows = $this->query($sql, ['id' => $sectionId]);
        return $rows[0] ?? [];
    }

    // -------------------------------------------------------------------------
    // cost_month helpers – retrieve last N month IDs
    // -------------------------------------------------------------------------


    /**
     * Returns IDs of last $limit LOCKED (closed) cost_months, newest first.
     *
     * Strategy:
     *  1. Prefer months with status = 'LOCKED' – data is approved and complete.
     *  2. If fewer than $limit locked months exist, return all that are available.
     *  3. Fallback: if NO locked months exist at all, return the last $limit months
     *     that have at least one media cost entry (cost_media_month) so the
     *     calculator is never completely empty.
     *
     * @return int[]
     */
    public function getLastMonthIds(int $limit = 12): array
    {
        $limit = max(1, min(120, (int)$limit));

        // Primary: closed months (LOCKED)
        $sql = "SELECT id
                FROM cost_month
                WHERE status = 'LOCKED'
                ORDER BY year DESC, month DESC
                LIMIT {$limit}";
        $rows = $this->query($sql) ?: [];

        if (!empty($rows)) {
            return array_column($rows, 'id');
        }

        // Fallback: any month that has at least one media cost (data is meaningful)
        $sqlFallback = "SELECT DISTINCT cm.id
                FROM cost_month cm
                INNER JOIN cost_media_month cmm ON cmm.cost_month_id = cm.id
                ORDER BY cm.year DESC, cm.month DESC
                LIMIT {$limit}";
        $rows = $this->query($sqlFallback) ?: [];

        if (!empty($rows)) {
            return array_column($rows, 'id');
        }

        // Last resort: just the most recent months regardless of content
        $sqlLast = "SELECT id FROM cost_month ORDER BY year DESC, month DESC LIMIT {$limit}";
        $rows = $this->query($sqlLast) ?: [];
        return array_column($rows, 'id');
    }

    /**
     * Returns the count of LOCKED cost months (used to clamp the months selector).
     */
    public function getAvailableMonthsCount(): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM cost_month WHERE status = 'LOCKED'";
        $rows = $this->query($sql);
        $locked = (int)($rows[0]['cnt'] ?? 0);

        if ($locked > 0) {
            return $locked;
        }

        // Fallback: months with media data
        $sqlFallback = "SELECT COUNT(DISTINCT cost_month_id) AS cnt FROM cost_media_month";
        $rows = $this->query($sqlFallback);
        $withMedia = (int)($rows[0]['cnt'] ?? 0);

        if ($withMedia > 0) {
            return $withMedia;
        }

        // Last resort: all months
        $sqlAll = "SELECT COUNT(*) AS cnt FROM cost_month";
        $rows = $this->query($sqlAll);
        return (int)($rows[0]['cnt'] ?? 0);
    }

    /**
     * Returns label describing the data basis (for UI display).
     * e.g. "12 zamkniętych miesięcy (2025-03 – 2026-02)"
     */
    public function getDataBasisLabel(array $monthIds): array
    {
        if (empty($monthIds)) {
            return ['label' => 'Brak danych', 'from' => null, 'to' => null, 'count' => 0, 'all_locked' => false];
        }

        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT
                    COUNT(*)                             AS cnt,
                    MIN(CONCAT(year, '-', LPAD(month, 2, '0'))) AS period_from,
                    MAX(CONCAT(year, '-', LPAD(month, 2, '0'))) AS period_to,
                    SUM(CASE WHEN status = 'LOCKED' THEN 1 ELSE 0 END) AS locked_cnt
                FROM cost_month
                WHERE id IN ({$placeholders})";
        $rows = $this->queryPositional($sql, $monthIds);
        $row = $rows[0] ?? [];

        $cnt       = (int)($row['cnt'] ?? 0);
        $from      = $row['period_from'] ?? null;
        $to        = $row['period_to'] ?? null;
        $lockedCnt = (int)($row['locked_cnt'] ?? 0);
        $allLocked = ($lockedCnt === $cnt && $cnt > 0);

        return [
            'label'      => "{$cnt} " . ($allLocked ? 'zamkniętych miesięcy' : 'miesięcy') . ($from && $to ? " ({$from} – {$to})" : ''),
            'from'       => $from,
            'to'         => $to,
            'count'      => $cnt,
            'all_locked' => $allLocked,
        ];
    }

    /**
     * Returns meta info (year, month) for a list of month IDs.
     */
    public function getMonthsMeta(array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT id, year, month FROM cost_month WHERE id IN ({$placeholders}) ORDER BY year DESC, month DESC";
        return $this->queryPositional($sql, $monthIds) ?: [];
    }

    // -------------------------------------------------------------------------
    // Per-month data for a section
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Sales volume per month for a section
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Media allocation details for all sections in a month (for percentage view)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Sales quantity per product across months (from WDT)
    // -------------------------------------------------------------------------

    /**
     * Returns total sold cartons quantity per product for the given cost_month IDs.
     * Returns [product_id => ['total_qty' => float, 'months' => [month_id => float]]]
     */
    public function getSoldQuantityPerProductInMonths(array $productIds, array $monthIds): array
    {
        if (empty($productIds) || empty($monthIds)) {
            return [];
        }

        $phMonths   = implode(',', array_fill(0, count($monthIds), '?'));
        $phProducts = implode(',', array_fill(0, count($productIds), '?'));

        $sql = "SELECT
                    cm.id AS month_id,
                    CONCAT(cm.year, '-', LPAD(cm.month, 2, '0')) AS month_label,
                    wdp.id_product,
                    COALESCE(SUM(wdp.cartons), 0) AS qty
                FROM cost_month cm
                INNER JOIN (" . WdtSalesRepository::linesSql() . ") wdp
                    ON wdp.ym = CONCAT(cm.year, '-', LPAD(cm.month, 2, '0'))
                WHERE cm.id IN ({$phMonths})
                    AND wdp.id_product IN ({$phProducts})
                GROUP BY cm.id, cm.year, cm.month, wdp.id_product
                ORDER BY cm.year DESC, cm.month DESC";

        $params = array_merge($monthIds, $productIds);
        $rows   = $this->queryPositional($sql, $params) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $pid = (int)$row['id_product'];
            $mid = (int)$row['month_id'];
            $qty = (float)$row['qty'];

            if (!isset($result[$pid])) {
                $result[$pid] = ['total_qty' => 0.0, 'months' => []];
            }
            $result[$pid]['total_qty']          += $qty;
            $result[$pid]['months'][$mid]        = $qty;
            $result[$pid]['months_labels'][$mid] = $row['month_label'];
        }

        return $result;
    }

    /**
     * Returns [month_id => 'YYYY-MM'] for the given month IDs, sorted oldest → newest.
     */
    public function getMonthLabelsForIds(array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT id, year, month FROM cost_month WHERE id IN ({$ph}) ORDER BY year ASC, month ASC";
        $rows = $this->queryPositional($sql, $monthIds) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['id']] = $row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT);
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Average sale price for a product across months
    // -------------------------------------------------------------------------

    public function getExchangeRateEuro(): float
    {
        $sql = "SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1";
        $rows = $this->query($sql);
        return (float)($rows[0]['exchange_rate_euro'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Logistics costs per month
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a query with positional (?) placeholders.
     */
    protected function queryPositional(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $date = date('Y-m-d H:i:s');
            error_log("[{$date}] MYSQL ERROR (ProductMarginRepository): {$e->getMessage()}\n", 3, __DIR__ . '/../../Log/mysql_error.log');
            return [];
        }
    }
}




