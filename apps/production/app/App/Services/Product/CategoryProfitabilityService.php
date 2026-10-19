<?php

namespace App\Services\Product;

use App\Repositories\Product\CategoryProfitabilityRepository;

class CategoryProfitabilityService
{
    private CategoryProfitabilityRepository $repository;

    public function __construct(CategoryProfitabilityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get all categories with KPI summary
     */
    public function getCategoriesWithKPIs(): array
    {
        $categories = $this->repository->getAllCategories();
        $result = [];

        foreach ($categories as $category) {
            $profitability = $this->calculateCategoryProfitability($category['id']);
            
            // Only include categories with sales data
            if ($profitability['sales']['total_quantity'] > 0) {
                $result[] = [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'target_margin' => $category['target_margin_percentage'],
                    'sales' => $profitability['sales'],
                    'costs' => $profitability['costs'],
                    'kpis' => $profitability['kpis'],
                    'status' => $this->determineStatus($profitability['kpis'], $category['target_margin_percentage'])
                ];
            }
        }

        return $result;
    }

    /**
     * Calculate complete profitability for a category
     */
    public function calculateCategoryProfitability(int $categoryId, ?float $customPrice = null, ?int $customQuantity = null): array
    {
        $category = $this->repository->getCategoryById($categoryId);
        if (!$category) {
            throw new \Exception("Category not found");
        }

        // Get sales data from previous month
        $sales = $this->repository->getPreviousMonthSales($categoryId);
        
        // Get detailed product sales for cost calculation
        $productSales = $this->repository->getCategoryProductSales($categoryId);

        // Calculate costs
        $costs = $this->calculateCategoryCosts($productSales);

        // Use custom values if provided (for what-if scenarios)
        $salesQuantity = $customQuantity ?? $sales['total_quantity'];
        $salesValue = $customPrice ? ($customPrice * $salesQuantity) : $sales['total_value'];

        // Calculate KPIs
        $totalCost = $costs['total_cost'] * $salesQuantity;
        $avgCostPerUnit = $salesQuantity > 0 ? $totalCost / $salesQuantity : 0;
        $avgPricePerUnit = $salesQuantity > 0 ? $salesValue / $salesQuantity : 0;
        $profitPerUnit = $avgPricePerUnit - $avgCostPerUnit;
        $marginPercentage = $avgPricePerUnit > 0 ? ($profitPerUnit / $avgPricePerUnit) * 100 : 0;
        
        $suggestedPrice = $this->calculateSuggestedPrice($avgCostPerUnit, $category['target_margin_percentage']);

        return [
            'category' => $category,
            'sales' => [
                'total_quantity' => $sales['total_quantity'],
                'total_value' => $sales['total_value'],
                'avg_price_per_unit' => $avgPricePerUnit,
                'period_start' => $sales['period_start'],
                'period_end' => $sales['period_end'],
                'invoice_count' => $sales['invoice_count'],
                'product_count' => $sales['product_count']
            ],
            'costs' => [
                'raw_materials' => $costs['raw_materials'],
                'labor' => $costs['labor'],
                'fixed' => $costs['fixed'],
                'variable' => $costs['variable'],
                'total_cost' => $costs['total_cost'],
                'breakdown' => $costs['breakdown']
            ],
            'kpis' => [
                'cost_per_unit' => $avgCostPerUnit,
                'profit_per_unit' => $profitPerUnit,
                'margin_percentage' => $marginPercentage,
                'suggested_price' => $suggestedPrice,
                'monthly_total_cost' => $totalCost,
                'monthly_total_revenue' => $salesValue,
                'monthly_total_profit' => $salesValue - $totalCost
            ],
            'products' => $productSales
        ];
    }

    /**
     * Calculate aggregated costs for a category based on product sales
     */
    private function calculateCategoryCosts(array $productSales): array
    {
        $totalRawMaterials = 0;
        $totalLabor = 0;
        $totalFixed = 0;
        $totalVariable = 0;
        $totalQuantity = 0;
        $breakdown = [];

        // Group products by section for labor cost allocation
        $sectionQuantities = [];
        $sectionProducts = [];

        foreach ($productSales as $product) {
            $sectionId = $product['id_section'];
            if (!isset($sectionQuantities[$sectionId])) {
                $sectionQuantities[$sectionId] = 0;
                $sectionProducts[$sectionId] = [];
            }
            $sectionQuantities[$sectionId] += $product['quantity_sold'];
            $sectionProducts[$sectionId][] = $product;
        }

        // Calculate costs for each product
        foreach ($productSales as $product) {
            $quantity = (int)$product['quantity_sold'];
            $totalQuantity += $quantity;

            // 1. Raw material cost
            $rawMaterialCostPerUnit = 0;
            if ($product['id_recipe']) {
                $rawMaterialCostPerUnit = $this->repository->getRecipeMaterialCost($product['id_recipe']);
            }
            $totalRawMaterials += $rawMaterialCostPerUnit * $quantity;

            // 2. Labor cost (allocated from section's employee costs)
            $sectionId = $product['id_section'];
            $laborCostPerUnit = 0;
            if ($sectionId && isset($sectionQuantities[$sectionId]) && $sectionQuantities[$sectionId] > 0) {
                $sectionLaborCost = $this->repository->getSectionLaborCost($sectionId);
                // Allocate labor cost proportionally based on quantity produced in this section
                $proportion = $quantity / $sectionQuantities[$sectionId];
                $allocatedLaborCost = $sectionLaborCost * $proportion;
                $laborCostPerUnit = $quantity > 0 ? $allocatedLaborCost / $quantity : 0;
                $totalLabor += $allocatedLaborCost;
            }

            $breakdown[] = [
                'product_id' => $product['product_id'],
                'product_name' => $product['product_name'],
                'quantity' => $quantity,
                'raw_materials_per_unit' => $rawMaterialCostPerUnit,
                'labor_per_unit' => $laborCostPerUnit,
                'total_cost_per_unit' => $rawMaterialCostPerUnit + $laborCostPerUnit
            ];
        }

        // Calculate average costs per unit
        $avgRawMaterialsPerUnit = $totalQuantity > 0 ? $totalRawMaterials / $totalQuantity : 0;
        $avgLaborPerUnit = $totalQuantity > 0 ? $totalLabor / $totalQuantity : 0;
        $avgFixedPerUnit = $totalQuantity > 0 ? $totalFixed / $totalQuantity : 0;
        $avgVariablePerUnit = $totalQuantity > 0 ? $totalVariable / $totalQuantity : 0;

        return [
            'raw_materials' => $avgRawMaterialsPerUnit,
            'labor' => $avgLaborPerUnit,
            'fixed' => $avgFixedPerUnit,
            'variable' => $avgVariablePerUnit,
            'total_cost' => $avgRawMaterialsPerUnit + $avgLaborPerUnit + $avgFixedPerUnit + $avgVariablePerUnit,
            'breakdown' => $breakdown
        ];
    }

    /**
     * Calculate suggested selling price based on cost and target margin
     */
    private function calculateSuggestedPrice(float $costPerUnit, float $targetMarginPercentage): float
    {
        if ($targetMarginPercentage >= 100) {
            return 0; // Invalid margin
        }
        return $costPerUnit / (1 - ($targetMarginPercentage / 100));
    }

    /**
     * Determine status based on actual margin vs target
     */
    private function determineStatus(array $kpis, float $targetMargin): array
    {
        $actualMargin = $kpis['margin_percentage'];
        $warningThreshold = 15.0; // Below this is danger

        if ($actualMargin >= $targetMargin) {
            return [
                'level' => 'success',
                'message' => 'OK: Kategoria rentowna',
                'icon' => '✅'
            ];
        } elseif ($actualMargin >= $warningThreshold) {
            return [
                'level' => 'warning',
                'message' => 'Uwaga: Marża poniżej docelowej',
                'icon' => '⚠️'
            ];
        } else {
            return [
                'level' => 'danger',
                'message' => 'OSTRZEŻENIE: Marża poniżej progu bezpieczeństwa!',
                'icon' => '🚨'
            ];
        }
    }

    /**
     * Get category details with full breakdown
     */
    public function getCategoryDetails(int $categoryId): array
    {
        return $this->calculateCategoryProfitability($categoryId);
    }

    /**
     * Calculate what-if scenario for a category
     */
    public function calculateWhatIf(int $categoryId, float $salesPrice, int $monthlyQuantity): array
    {
        // Get base profitability data
        $profitability = $this->calculateCategoryProfitability($categoryId);
        
        // Recalculate with custom values
        $costPerUnit = $profitability['costs']['total_cost'];
        $profitPerUnit = $salesPrice - $costPerUnit;
        $marginPercentage = $salesPrice > 0 ? ($profitPerUnit / $salesPrice) * 100 : 0;
        
        $totalCost = $costPerUnit * $monthlyQuantity;
        $totalRevenue = $salesPrice * $monthlyQuantity;
        $totalProfit = $totalRevenue - $totalCost;

        return [
            'category_id' => $categoryId,
            'category_name' => $profitability['category']['name'],
            'input' => [
                'sales_price' => $salesPrice,
                'monthly_quantity' => $monthlyQuantity
            ],
            'kpis' => [
                'cost_per_unit' => $costPerUnit,
                'profit_per_unit' => $profitPerUnit,
                'margin_percentage' => $marginPercentage,
                'monthly_total_cost' => $totalCost,
                'monthly_total_revenue' => $totalRevenue,
                'monthly_total_profit' => $totalProfit
            ],
            'status' => $this->determineStatus([
                'margin_percentage' => $marginPercentage
            ], $profitability['category']['target_margin_percentage'])
        ];
    }
}
