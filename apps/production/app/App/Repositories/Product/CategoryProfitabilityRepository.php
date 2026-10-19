<?php

namespace App\Repositories\Product;

use App\Core\Database;
use PDO;

class CategoryProfitabilityRepository
{
    private PDO $pdo;

    public function __construct(Database $database)
    {
        $this->pdo = $database->getConnection();
    }

    /**
     * Get all categories with basic info
     */
    public function getAllCategories(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                name,
                target_margin_percentage
            FROM category_product
            ORDER BY name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get category by ID
     */
    public function getCategoryById(int $categoryId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                name,
                target_margin_percentage
            FROM category_product
            WHERE id = :id
        ");
        $stmt->execute(['id' => $categoryId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get previous month's sales for a category
     * Returns total quantity and total value sold in the previous month
     */
    public function getPreviousMonthSales(int $categoryId): array
    {
        // Calculate previous month's date range
        $previousMonth = date('Y-m', strtotime('-1 month'));
        $startDate = $previousMonth . '-01';
        $endDate = date('Y-m-t', strtotime($startDate)); // Last day of previous month

        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(dip.quantity) as total_quantity,
                SUM(dip.quantity * dip.single_price) as total_value,
                COUNT(DISTINCT dip.id_document_invoice) as invoice_count,
                COUNT(DISTINCT dip.id_product) as product_count
            FROM document_invoice_product dip
            INNER JOIN document_invoice di ON dip.id_document_invoice = di.id
            INNER JOIN final_product fp ON dip.id_product = fp.id
            WHERE fp.id_category = :category_id
                AND di.invoiced_date >= :start_date
                AND di.invoiced_date <= :end_date
        ");
        
        $stmt->execute([
            'category_id' => $categoryId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_quantity' => (int)($result['total_quantity'] ?? 0),
            'total_value' => (float)($result['total_value'] ?? 0),
            'invoice_count' => (int)($result['invoice_count'] ?? 0),
            'product_count' => (int)($result['product_count'] ?? 0),
            'period_start' => $startDate,
            'period_end' => $endDate
        ];
    }

    /**
     * Get all products in a category
     */
    public function getProductsByCategory(int $categoryId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                fp.id,
                fp.name,
                fp.id_recipe,
                fp.id_section,
                fp.standard_price,
                fp.active,
                ps.name as section_name
            FROM final_product fp
            LEFT JOIN production_sections ps ON fp.id_section = ps.id
            WHERE fp.id_category = :category_id
                AND fp.active = 1
            ORDER BY fp.name ASC
        ");
        $stmt->execute(['category_id' => $categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get labor costs for a specific month and section
     * Returns total monthly cost for employees in the section
     */
    public function getSectionLaborCost(int $sectionId, ?string $month = null): float
    {
        // Default to previous month if not specified
        if ($month === null) {
            $month = date('Y-m', strtotime('-1 month'));
        }

        list($year, $monthNum) = explode('-', $month);

        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(cme.monthly_cost) as total_labor_cost
            FROM cost_month_employee cme
            INNER JOIN cost_month cm ON cme.month_id = cm.id
            WHERE cme.section_id = :section_id
                AND cm.year = :year
                AND cm.month = :month
                AND cme.is_included = 1
        ");
        
        $stmt->execute([
            'section_id' => $sectionId,
            'year' => (int)$year,
            'month' => (int)$monthNum
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total_labor_cost'] ?? 0);
    }

    /**
     * Get all sections used by products in a category
     */
    public function getCategorySections(int $categoryId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                ps.id,
                ps.name,
                ps.depreciation,
                ps.water_cost,
                ps.power_supply_cost,
                ps.fuel_cost,
                ps.other_cost
            FROM production_sections ps
            INNER JOIN final_product fp ON ps.id = fp.id_section
            WHERE fp.id_category = :category_id
                AND fp.active = 1
        ");
        $stmt->execute(['category_id' => $categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get flattened recipe cost for a product
     */
    public function getRecipeMaterialCost(int $recipeId): float
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(fri.quantity * rm.price) as total_cost
            FROM flattened_recipe_ingredient fri
            INNER JOIN raw_material rm ON fri.material_id = rm.id
            WHERE fri.recipe_id = :recipe_id
        ");
        $stmt->execute(['recipe_id' => $recipeId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total_cost'] ?? 0);
    }

    /**
     * Get sales breakdown by product within a category for previous month
     */
    public function getCategoryProductSales(int $categoryId): array
    {
        $previousMonth = date('Y-m', strtotime('-1 month'));
        $startDate = $previousMonth . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $stmt = $this->pdo->prepare("
            SELECT 
                fp.id as product_id,
                fp.name as product_name,
                fp.id_section,
                fp.id_recipe,
                SUM(dip.quantity) as quantity_sold,
                SUM(dip.quantity * dip.single_price) as total_value
            FROM document_invoice_product dip
            INNER JOIN document_invoice di ON dip.id_document_invoice = di.id
            INNER JOIN final_product fp ON dip.id_product = fp.id
            WHERE fp.id_category = :category_id
                AND di.invoiced_date >= :start_date
                AND di.invoiced_date <= :end_date
                AND fp.active = 1
            GROUP BY fp.id, fp.name, fp.id_section, fp.id_recipe
            ORDER BY total_value DESC
        ");
        
        $stmt->execute([
            'category_id' => $categoryId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
