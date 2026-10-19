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
                    pr.precalculated_material_cost,
                    fp.workload_points,
                    fp.id_recipe
                FROM final_product fp
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                LEFT JOIN production_sections ps ON fp.id_section = ps.id
                LEFT JOIN product_recipe pr
                    ON pr.id = fp.id_recipe
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
                    COALESCE(pr.precalculated_material_cost, 0) AS foodcost
                FROM category_product cp
                LEFT JOIN final_product fp
                    ON fp.id_category = cp.id AND fp.active = 1
                LEFT JOIN production_sections ps
                    ON ps.id = fp.id_section
                LEFT JOIN product_recipe pr
                    ON pr.id = fp.id_recipe
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

    /**
     * Returns media costs allocated to a section per month.
     * Structure: [month_id => ['total_media' => float, 'details' => [...]]]
     */
    public function getMediaCostsPerMonth(int $sectionId, array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));

        // New structure (cost_media_section_allocation + cost_media_month)
        $sql = "SELECT
                    csa.cost_month_id,
                    cmt.code          AS media_code,
                    cmt.name          AS media_name,
                    cmm.amount        AS total_cost,
                    csa.allocation_percent,
                    ROUND(cmm.amount * (csa.allocation_percent / 100), 4) AS allocated_cost
                FROM cost_media_section_allocation csa
                INNER JOIN cost_media_month cmm
                    ON csa.cost_month_id = cmm.cost_month_id
                    AND csa.media_type_id = cmm.media_type_id
                INNER JOIN cost_media_type cmt ON csa.media_type_id = cmt.id
                WHERE csa.cost_month_id IN ({$placeholders})
                    AND csa.section_id = ?
                    AND cmt.is_active = 1
                ORDER BY csa.cost_month_id DESC, cmt.name";

        $params = array_merge($monthIds, [$sectionId]);
        $rows = $this->queryPositional($sql, $params) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $mid = (int)$row['cost_month_id'];
            if (!isset($result[$mid])) {
                $result[$mid] = ['total_media' => 0.0, 'details' => []];
            }
            $allocated = (float)$row['allocated_cost'];
            $result[$mid]['total_media'] += $allocated;
            $result[$mid]['details'][] = [
                'media_code' => $row['media_code'],
                'media_name' => $row['media_name'],
                'total_cost' => (float)$row['total_cost'],
                'allocation_percent' => (float)$row['allocation_percent'],
                'allocated_cost' => $allocated,
            ];
        }

        return $result;
    }

    /**
     * Fallback: media costs from production_sections static fields (water+power+fuel+other).
     */
    public function getStaticMediaCosts(int $sectionId): float
    {
        $sql = "SELECT COALESCE(water_cost,0) + COALESCE(power_supply_cost,0)
                     + COALESCE(fuel_cost,0) + COALESCE(other_cost,0) AS total
                FROM production_sections WHERE id = :sid LIMIT 1";
        $rows = $this->query($sql, ['sid' => $sectionId]);
        return (float)($rows[0]['total'] ?? 0.0);
    }

    /**
     * Employee costs per month for section.
     * Returns [month_id => float]
     */
    public function getEmployeeCostsPerMonth(int $sectionId, array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT month_id, COALESCE(SUM(monthly_cost), 0) AS total
                FROM cost_month_employee
                WHERE month_id IN ({$placeholders})
                    AND section_id = ?
                    AND is_included = 1
                GROUP BY month_id";
        $params = array_merge($monthIds, [$sectionId]);
        $rows = $this->queryPositional($sql, $params) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['month_id']] = (float)$row['total'];
        }
        return $result;
    }

    /**
     * Fixed costs (company-wide) per month from cost_month_fixed_cost.
     * These are not per-section – they are spread across total company volume (like logistics).
     * Returns [month_id => ['total' => float, 'details' => [['name', 'amount', 'category'], ...]]]
     */
    public function getFixedCostsPerMonth(array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT month_id, name, COALESCE(amount, 0) AS amount, category
                FROM cost_month_fixed_cost
                WHERE month_id IN ({$placeholders})
                ORDER BY month_id DESC, id ASC";
        $rows = $this->queryPositional($sql, $monthIds) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $mid = (int)$row['month_id'];
            if (!isset($result[$mid])) {
                $result[$mid] = ['total' => 0.0, 'details' => []];
            }
            $amount = (float)$row['amount'];
            $result[$mid]['total'] += $amount;
            $result[$mid]['details'][] = [
                'name'     => $row['name'],
                'amount'   => $amount,
                'category' => $row['category'],
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Sales volume per month for a section
    // -------------------------------------------------------------------------

    /**
     * Returns actual sales carton volume from document_wdt per month (cost_month periods).
     * Returns [month_id => float]
     */
    public function getSectionSalesVolumePerMonth(int $sectionId, array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        // Build date ranges for the requested months using subquery approach
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT
                    cm.id AS month_id,
                    COALESCE(SUM(wdp.quantity), 0) AS cartons_qty
                FROM cost_month cm
                LEFT JOIN document_wdt wdt
                    ON wdt.established_date >= CONCAT(cm.year, '-', LPAD(cm.month, 2, '0'), '-01')
                    AND wdt.established_date <= CONCAT(
                          cm.year, '-',
                          LPAD(cm.month, 2, '0'), '-',
                          LPAD(DAY(LAST_DAY(STR_TO_DATE(CONCAT(cm.year, '-', LPAD(cm.month,2,'0'), '-01'), '%Y-%m-%d'))), 2, '0')
                       )
                LEFT JOIN document_wdt_product wdp ON wdp.id_document_wdt = wdt.id
                LEFT JOIN final_product fp ON fp.id = wdp.id_product AND fp.id_section = ?
                WHERE cm.id IN ({$placeholders})
                GROUP BY cm.id";
        // section_id must come first (it's the ? before the IN list placeholders)
        $params = array_merge([$sectionId], $monthIds);
        $rows = $this->queryPositional($sql, $params) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['month_id']] = (float)$row['cartons_qty'];
        }
        return $result;
    }

    /**
     * Returns cartons_qty from cost_month_section (manual/auto entry) per month.
     * Returns [month_id => int]
     */
    public function getSectionMonthVolume(int $sectionId, array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT month_id, cartons_qty
                FROM cost_month_section
                WHERE month_id IN ({$placeholders}) AND section_id = ?";
        $params = array_merge($monthIds, [$sectionId]);
        $rows = $this->queryPositional($sql, $params) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['month_id']] = (int)$row['cartons_qty'];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Media allocation details for all sections in a month (for percentage view)
    // -------------------------------------------------------------------------

    /**
     * Returns media allocation summary per section for a set of months (averaged).
     * Used for "section share in media costs" display.
     */
    public function getMediaAllocationBySection(array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT
                    csa.section_id,
                    ps.name AS section_name,
                    cmt.name AS media_name,
                    cmt.code AS media_code,
                    AVG(csa.allocation_percent) AS avg_alloc_pct,
                    SUM(cmm.amount * (csa.allocation_percent / 100)) AS total_allocated
                FROM cost_media_section_allocation csa
                INNER JOIN cost_media_month cmm
                    ON csa.cost_month_id = cmm.cost_month_id
                    AND csa.media_type_id = cmm.media_type_id
                INNER JOIN cost_media_type cmt ON csa.media_type_id = cmt.id
                INNER JOIN production_sections ps ON csa.section_id = ps.id
                WHERE csa.cost_month_id IN ({$placeholders})
                    AND cmt.is_active = 1
                GROUP BY csa.section_id, ps.name, cmt.name, cmt.code
                ORDER BY ps.name, cmt.name";
        return $this->queryPositional($sql, $monthIds) ?: [];
    }

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
                    COALESCE(SUM(wdp.quantity), 0) AS qty
                FROM cost_month cm
                INNER JOIN document_wdt wdt
                    ON wdt.established_date >= CONCAT(cm.year, '-', LPAD(cm.month, 2, '0'), '-01')
                    AND wdt.established_date <= CONCAT(
                          cm.year, '-',
                          LPAD(cm.month, 2, '0'), '-',
                          LPAD(DAY(LAST_DAY(STR_TO_DATE(CONCAT(cm.year, '-', LPAD(cm.month,2,'0'), '-01'), '%Y-%m-%d'))), 2, '0')
                       )
                INNER JOIN document_wdt_product wdp ON wdp.id_document_wdt = wdt.id
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

    public function getProductAvgSalePriceInMonths(int $productId, array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT
                    cm.id AS month_id,
                    AVG(wdp.price_carton) AS avg_price,
                    MIN(wdt.currency)     AS currency,
                    COALESCE(SUM(wdp.quantity), 0) AS cartons_qty
                FROM cost_month cm
                INNER JOIN document_wdt wdt
                    ON wdt.established_date >= CONCAT(cm.year, '-', LPAD(cm.month, 2, '0'), '-01')
                    AND wdt.established_date <= CONCAT(
                          cm.year, '-',
                          LPAD(cm.month, 2, '0'), '-',
                          LPAD(DAY(LAST_DAY(STR_TO_DATE(CONCAT(cm.year, '-', LPAD(cm.month,2,'0'), '-01'), '%Y-%m-%d'))), 2, '0')
                       )
                INNER JOIN document_wdt_product wdp ON wdp.id_document_wdt = wdt.id
                WHERE cm.id IN ({$placeholders})
                    AND wdp.id_product = ?
                    AND wdp.price_carton > 0
                GROUP BY cm.id";
        $params = array_merge($monthIds, [$productId]);
        return $this->queryPositional($sql, $params) ?: [];
    }

    public function getExchangeRateEuro(): float
    {
        $sql = "SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1";
        $rows = $this->query($sql);
        return (float)($rows[0]['exchange_rate_euro'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Logistics costs per month
    // -------------------------------------------------------------------------

    /**
     * Returns total logistics costs (sum of all types) per month.
     * Returns [month_id => ['total' => float, 'details' => [['type_code', 'type_name', 'amount'], ...]]]
     */
    public function getLogisticsCostsPerMonth(array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT
                    cml.month_id,
                    clt.code  AS type_code,
                    clt.name  AS type_name,
                    SUM(cml.amount) AS amount
                FROM cost_month_logistics cml
                INNER JOIN cost_logistics_type clt ON clt.id = cml.logistics_type_id
                WHERE cml.month_id IN ({$placeholders})
                GROUP BY cml.month_id, cml.logistics_type_id, clt.code, clt.name
                ORDER BY cml.month_id DESC, clt.sort_order ASC";

        $rows = $this->queryPositional($sql, $monthIds) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $mid = (int)$row['month_id'];
            if (!isset($result[$mid])) {
                $result[$mid] = ['total' => 0.0, 'details' => []];
            }
            $amount = (float)$row['amount'];
            $result[$mid]['total'] += $amount;
            $result[$mid]['details'][] = [
                'type_code' => $row['type_code'],
                'type_name' => $row['type_name'],
                'amount'    => $amount,
            ];
        }

        return $result;
    }

    /**
     * Returns total sales volume across ALL sections per month (for logistics cost / carton).
     * Uses cost_month_section first (manual/auto), falls back to WDT aggregation.
     * Returns [month_id => float]
     */
    public function getTotalSalesVolumePerMonth(array $monthIds): array
    {
        if (empty($monthIds)) {
            return [];
        }

        // Primary: sum of all sections from cost_month_section
        $placeholders = implode(',', array_fill(0, count($monthIds), '?'));
        $sql = "SELECT month_id, SUM(cartons_qty) AS total_cartons
                FROM cost_month_section
                WHERE month_id IN ({$placeholders})
                GROUP BY month_id";
        $rows = $this->queryPositional($sql, $monthIds) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['month_id']] = (float)$row['total_cartons'];
        }

        // For months without cost_month_section data, fall back to WDT
        $missingIds = array_filter($monthIds, fn($mid) => !isset($result[$mid]) || $result[$mid] <= 0);
        if (!empty($missingIds)) {
            $ph2 = implode(',', array_fill(0, count($missingIds), '?'));
            $sqlWdt = "SELECT
                        cm.id AS month_id,
                        COALESCE(SUM(wdp.quantity), 0) AS cartons_qty
                    FROM cost_month cm
                    LEFT JOIN document_wdt wdt
                        ON wdt.established_date >= CONCAT(cm.year, '-', LPAD(cm.month, 2, '0'), '-01')
                        AND wdt.established_date <= CONCAT(
                              cm.year, '-',
                              LPAD(cm.month, 2, '0'), '-',
                              LPAD(DAY(LAST_DAY(STR_TO_DATE(CONCAT(cm.year, '-', LPAD(cm.month,2,'0'), '-01'), '%Y-%m-%d'))), 2, '0')
                           )
                    LEFT JOIN document_wdt_product wdp ON wdp.id_document_wdt = wdt.id
                    WHERE cm.id IN ({$ph2})
                    GROUP BY cm.id";
            $wdtRows = $this->queryPositional($sqlWdt, array_values($missingIds)) ?: [];
            foreach ($wdtRows as $row) {
                $mid = (int)$row['month_id'];
                if (($result[$mid] ?? 0) <= 0) {
                    $result[$mid] = (float)$row['cartons_qty'];
                }
            }
        }

        return $result;
    }

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




