<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;

class CostAnalysisRepository extends BaseRepository
{
    protected string $table = '';

    public function getCostMonths(): array
    {
        $sql = "SELECT id, year, month, status FROM cost_month ORDER BY year DESC, month DESC";
        return $this->query($sql);
    }

    public function getProductData(int $productId): array
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.id_category,
                    cp.name as category_name,
                    cp.target_margin_percentage,
                    fp.id_section,
                    ps.name as section_name,
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
                    ON pr.id_product = fp.id AND pr.is_subrecipe = 0
                WHERE fp.id = :id
                LIMIT 1";
        $rows = $this->query($sql, ['id' => $productId]);
        return $rows[0] ?? [];
    }

    public function getSectionCosts(int $sectionId): array
    {
        $sql = "SELECT
                    id,
                    name,
                    depreciation,
                    water_cost,
                    power_supply_cost,
                    fuel_cost,
                    other_cost
                FROM production_sections
                WHERE id = :id
                LIMIT 1";
        $rows = $this->query($sql, ['id' => $sectionId]);
        return $rows[0] ?? [];
    }

    public function getSectionEmployeeCost(int $monthId, int $sectionId): float
    {
        $sql = "SELECT IFNULL(SUM(monthly_cost), 0) as total
                FROM cost_month_employee
                WHERE month_id = :month_id
                  AND section_id = :section_id
                  AND is_included = 1";
        $rows = $this->query($sql, [
            'month_id' => $monthId,
            'section_id' => $sectionId
        ]);
        return (float)($rows[0]['total'] ?? 0);
    }

    public function getProductSalesInRange(int $productId, string $startDate, string $endDate): array
    {
        $sql = "SELECT
                    IFNULL(SUM(wdp.quantity), 0) as cartons_qty,
                    IFNULL(SUM(wdp.quantity * wdp.price_carton), 0) as revenue_carton_total,
                    AVG(wdp.price_carton) as avg_price_carton,
                    IFNULL(MIN(wdt.currency), '') as currency,
                    COUNT(DISTINCT wdt.currency) as currency_count
                FROM document_wdt_product wdp
                INNER JOIN document_wdt wdt ON wdp.id_document_wdt = wdt.id
                INNER JOIN final_product fp ON wdp.id_product = fp.id
                WHERE wdp.id_product = :product_id
                  AND wdt.established_date BETWEEN :start_date AND :end_date";
        $rows = $this->query($sql, [
            'product_id' => $productId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        return $rows[0] ?? [
            'cartons_qty' => 0,
            'revenue_carton_total' => 0,
            'avg_price_carton' => null,
            'currency' => '',
            'currency_count' => 0
        ];
    }

    public function getExchangeRateEuro(): float
    {
        $sql = "SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1";
        $rows = $this->query($sql);
        return (float)($rows[0]['exchange_rate_euro'] ?? 0);
    }

    public function getSectionWeightedUnitsInRange(int $sectionId, string $startDate, string $endDate, int $defaultWorkloadPoints): array
    {
        $sql = "SELECT
                    IFNULL(SUM(wdp.quantity * IFNULL(fp.workload_points, :default_points)), 0) as weighted_units,
                    IFNULL(SUM(wdp.quantity), 0) as units_qty
                FROM document_wdt_product wdp
                INNER JOIN document_wdt wdt ON wdp.id_document_wdt = wdt.id
                INNER JOIN final_product fp ON wdp.id_product = fp.id
                WHERE fp.id_section = :section_id
                  AND wdt.established_date BETWEEN :start_date AND :end_date";
        $rows = $this->query($sql, [
            'section_id' => $sectionId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'default_points' => $defaultWorkloadPoints
        ]);
        return $rows[0] ?? [
            'weighted_units' => 0,
            'units_qty' => 0
        ];
    }

    public function getTotalUnitsInRange(string $startDate, string $endDate): int
    {
        $sql = "SELECT IFNULL(SUM(wdp.quantity), 0) as total_units
                FROM document_wdt_product wdp
                INNER JOIN document_wdt wdt ON wdp.id_document_wdt = wdt.id
                INNER JOIN final_product fp ON wdp.id_product = fp.id
                WHERE wdt.established_date BETWEEN :start_date AND :end_date";
        $rows = $this->query($sql, [
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        return (int)($rows[0]['total_units'] ?? 0);
    }

    public function getCompanyCosts(): array
    {
        $sql = "SELECT depreciation, water, power_supply, other FROM company_cost WHERE id = 1 LIMIT 1";
        $rows = $this->query($sql);
        return $rows[0] ?? [
            'depreciation' => 0,
            'water' => 0,
            'power_supply' => 0,
            'other' => 0
        ];
    }

    public function getSharedCostPool(int $monthId): float
    {
        $sql = "SELECT IFNULL(SUM(amount), 0) as total
                FROM cost_month_fixed_cost
                WHERE month_id = :month_id";
        $rows = $this->query($sql, ['month_id' => $monthId]);
        return (float)($rows[0]['total'] ?? 0);
    }

    public function getSections(): array
    {
        $sql = "SELECT id, name FROM production_sections ORDER BY name";
        return $this->query($sql);
    }

    public function getSectionSalesProductsInRange(int $sectionId, string $startDate, string $endDate): array
    {
        $sql = "SELECT
                    fp.id as product_id,
                    fp.name as product_name,
                    fp.id_category,
                    cp.name as category_name,
                    fp.ppc,
                    fp.standard_price,
                    fp.pre_calculated_raw_price_per_carton,
                    pr.precalculated_material_cost,
                    fp.workload_points,
                    fp.id_recipe,
                    IFNULL(SUM(wdp.quantity), 0) as cartons_qty,
                    AVG(wdp.price_carton) as avg_price_carton,
                    IFNULL(MIN(wdt.currency), '') as currency,
                    COUNT(DISTINCT wdt.currency) as currency_count
                FROM document_wdt_product wdp
                INNER JOIN document_wdt wdt ON wdp.id_document_wdt = wdt.id
                INNER JOIN final_product fp ON wdp.id_product = fp.id
                LEFT JOIN category_product cp ON fp.id_category = cp.id
                LEFT JOIN product_recipe pr
                    ON pr.id = fp.id_recipe AND pr.is_subrecipe = 0
                WHERE fp.id_section = :section_id
                  AND wdt.established_date BETWEEN :start_date AND :end_date
                GROUP BY fp.id, fp.name, fp.id_category, cp.name, fp.ppc, fp.standard_price,
                         fp.pre_calculated_raw_price_per_carton, pr.precalculated_material_cost,
                         fp.workload_points, fp.id_recipe
                ORDER BY fp.name";

        return $this->query($sql, [
            'section_id' => $sectionId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
    }
}
