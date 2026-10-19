<?php

namespace App\Repositories\Product;

use PDO;

class ProductProfitabilityRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get profitability settings (company-wide defaults)
     */
    public function getSettings(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM product_profitability_settings WHERE id = 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // Return defaults if not found
            return [
                'default_hourly_labor_rate' => 50.00,
                'default_target_margin' => 25.00,
                'warning_margin_threshold' => 15.00
            ];
        }
        
        return $result;
    }

    /**
     * Get all products with profitability KPIs
     * Returns list with cost, profit, and margin calculations
     */
    public function getProductListWithKPIs(?string $searchTerm = null): array
    {
        $sql = "
            SELECT 
                fp.id,
                fp.name,
                fp.id_category,
                cp.name as category_name,
                fp.id_section,
                ps.name as section_name,
                fp.id_recipe,
                fp.standard_price,
                fp.labor_minutes_per_unit,
                fp.hourly_labor_rate,
                fp.monthly_planned_quantity,
                fp.target_margin_percentage,
                fp.active
            FROM final_product fp
            LEFT JOIN category_product cp ON fp.id_category = cp.id
            LEFT JOIN production_sections ps ON fp.id_section = ps.id
            WHERE fp.active = 1
        ";
        
        $params = [];
        
        if ($searchTerm) {
            $sql .= " AND fp.name LIKE :search";
            $params['search'] = '%' . $searchTerm . '%';
        }
        
        $sql .= " ORDER BY fp.name ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get section costs for a specific production section
     */
    public function getSectionCosts(int $sectionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                name,
                depreciation,
                water_cost,
                power_supply_cost,
                fuel_cost,
                other_cost
            FROM production_sections
            WHERE id = :section_id
        ");
        $stmt->execute(['section_id' => $sectionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return [
                'depreciation' => 0,
                'water_cost' => 0,
                'power_supply_cost' => 0,
                'fuel_cost' => 0,
                'other_cost' => 0
            ];
        }
        
        return $result;
    }

    /**
     * Get total monthly planned production for a section
     */
    public function getTotalMonthlyProductionForSection(int $sectionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(monthly_planned_quantity), 0) as total
            FROM final_product
            WHERE id_section = :section_id
            AND active = 1
            AND monthly_planned_quantity > 0
        ");
        $stmt->execute(['section_id' => $sectionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['total'] ?? 0);
    }

    /**
     * Update product profitability parameters
     */
    public function updateProductProfitabilityParams(int $productId, array $data): bool
    {
        $fields = [];
        $params = ['id' => $productId];
        
        if (isset($data['labor_minutes_per_unit'])) {
            $fields[] = "labor_minutes_per_unit = :labor_minutes";
            $params['labor_minutes'] = $data['labor_minutes_per_unit'];
        }
        
        if (isset($data['hourly_labor_rate'])) {
            $fields[] = "hourly_labor_rate = :hourly_rate";
            $params['hourly_rate'] = $data['hourly_labor_rate'];
        }
        
        if (isset($data['monthly_planned_quantity'])) {
            $fields[] = "monthly_planned_quantity = :monthly_qty";
            $params['monthly_qty'] = $data['monthly_planned_quantity'];
        }
        
        if (isset($data['target_margin_percentage'])) {
            $fields[] = "target_margin_percentage = :target_margin";
            $params['target_margin'] = $data['target_margin_percentage'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE final_product SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute($params);
    }
}
