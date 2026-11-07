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

    public function getExchangeRateEuro(): float
    {
        $sql = "SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1";
        $rows = $this->query($sql);
        return (float)($rows[0]['exchange_rate_euro'] ?? 0);
    }


    public function getSections(): array
    {
        $sql = "SELECT id, name FROM production_sections ORDER BY name";
        return $this->query($sql);
    }

    /** @return array<int, array> karty produktów (nazwa, kategoria, cena standardowa, koszt surowców) po id */
    public function getProductsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $rows = $this->query('SELECT fp.id, fp.name AS product_name, cp.name AS category_name, fp.standard_price, fp.pre_calculated_raw_price_per_carton
                              FROM final_product fp LEFT JOIN category_product cp ON cp.id = fp.id_category
                              WHERE fp.id IN (' . implode(',', $ids) . ')') ?: [];
        return array_column($rows, null, 'id');
    }
}
