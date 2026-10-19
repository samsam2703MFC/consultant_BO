<?php

namespace App\Services\Product;

use App\Repositories\Product\ProductProfitabilityRepository;
use App\Repositories\Production\Cost\CostMediaSectionAllocationRepository;
use App\Repositories\Production\Cost\CostMonthRepository;
use App\Services\Recipe\RecipeService;

class ProductProfitabilityService
{
    private ProductProfitabilityRepository $profitabilityRepository;
    private RecipeService $recipeService;
    private ProductService $productService;
    private CostMediaSectionAllocationRepository $mediaAllocationRepo;
    private CostMonthRepository $costMonthRepo;

    public function __construct(
        ProductProfitabilityRepository $profitabilityRepository,
        RecipeService $recipeService,
        ProductService $productService,
        CostMediaSectionAllocationRepository $mediaAllocationRepo,
        CostMonthRepository $costMonthRepo
    ) {
        $this->profitabilityRepository = $profitabilityRepository;
        $this->recipeService = $recipeService;
        $this->productService = $productService;
        $this->mediaAllocationRepo = $mediaAllocationRepo;
        $this->costMonthRepo = $costMonthRepo;
    }

    /**
     * Calculate complete profitability for a product
     * 
     * @param int $productId
     * @param float|null $customSalesPrice Override sales price for "what-if" scenarios
     * @param int|null $customMonthlyQty Override monthly quantity for "what-if" scenarios
     * @param int|null $costMonthId Cost month ID for variable costs (null = latest)
     * @return array Complete profitability breakdown
     */
    public function calculateProfitability(
        int $productId,
        ?float $customSalesPrice = null,
        ?int $customMonthlyQty = null,
        ?int $costMonthId = null
    ): array
    {
        // Get product data
        $product = $this->productService->getById($productId);
        if (!$product) {
            throw new \Exception("Product not found");
        }

        // Override parameters if provided (for "what-if" calculations)
        if ($customMonthlyQty !== null) {
            $product['monthly_planned_quantity'] = $customMonthlyQty;
        }

        // Calculate individual cost components
        $rawMaterialCost = $this->calculateRawMaterialCost($product['id_recipe']);
        $laborCost = $this->calculateLaborCost($product);
        $fixedCostPerUnit = $this->calculateFixedCostPerUnit($product);
        $variableCostPerUnit = $this->calculateVariableCostPerUnit($product, $costMonthId);

        // Total cost per unit
        $totalCostPerUnit = $rawMaterialCost + $laborCost + $fixedCostPerUnit + $variableCostPerUnit;

        // Sales price (use custom if provided, otherwise standard_price)
        $salesPrice = $customSalesPrice ?? (float)$product['standard_price'];

        // Profit and margin calculations
        $profitPerUnit = $salesPrice - $totalCostPerUnit;
        $marginPercentage = $salesPrice > 0 ? ($profitPerUnit / $salesPrice) * 100 : 0;

        // Get settings for warning threshold
        $settings = $this->profitabilityRepository->getSettings();
        $warningThreshold = $settings['warning_margin_threshold'] ?? 15.00;

        // Status determination
        $status = $this->determineStatus($marginPercentage, $warningThreshold, $product['target_margin_percentage']);

        // Suggested price based on target margin
        $targetMargin = $product['target_margin_percentage'] ?? $settings['default_target_margin'];
        $suggestedPrice = $this->calculateSuggestedPrice($totalCostPerUnit, $targetMargin);

        return [
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'category_name' => $product['category_name'] ?? '',
            'section_name' => $product['section_name'] ?? '',
            
            // Cost breakdown
            'costs' => [
                'raw_materials' => round($rawMaterialCost, 2),
                'labor' => round($laborCost, 2),
                'fixed_costs' => round($fixedCostPerUnit, 2),
                'variable_costs' => round($variableCostPerUnit, 2),
                'total_per_unit' => round($totalCostPerUnit, 2)
            ],
            
            // KPIs
            'kpis' => [
                'cost_per_unit' => round($totalCostPerUnit, 2),
                'sales_price' => round($salesPrice, 2),
                'profit_per_unit' => round($profitPerUnit, 2),
                'margin_percentage' => round($marginPercentage, 2),
                'target_margin_percentage' => round($targetMargin, 2)
            ],
            
            // Status and recommendations
            'status' => $status,
            'suggested_price' => round($suggestedPrice, 2),
            
            // Planning data
            'planning' => [
                'monthly_planned_quantity' => (int)$product['monthly_planned_quantity'],
                'monthly_total_cost' => round($totalCostPerUnit * $product['monthly_planned_quantity'], 2),
                'monthly_total_revenue' => round($salesPrice * $product['monthly_planned_quantity'], 2),
                'monthly_total_profit' => round($profitPerUnit * $product['monthly_planned_quantity'], 2)
            ],
            
            // Labor details
            'labor_details' => [
                'minutes_per_unit' => (float)$product['labor_minutes_per_unit'],
                'hourly_rate' => (float)$product['hourly_labor_rate']
            ]
        ];
    }

    /**
     * Calculate raw material cost from recipe
     */
    public function calculateRawMaterialCost(?int $recipeId): float
    {
        if (!$recipeId) {
            return 0.0;
        }

        $recipeSummary = $this->recipeService->getRecipeSummary($recipeId);
        return (float)($recipeSummary['total_ingredient_cost'] ?? 0);
    }

    /**
     * Calculate labor cost per unit
     */
    public function calculateLaborCost(array $product): float
    {
        $minutesPerUnit = (float)$product['labor_minutes_per_unit'];
        $hourlyRate = (float)$product['hourly_labor_rate'];

        if ($minutesPerUnit <= 0 || $hourlyRate <= 0) {
            return 0.0;
        }

        // Convert minutes to hours and multiply by hourly rate
        $hoursPerUnit = $minutesPerUnit / 60;
        return $hoursPerUnit * $hourlyRate;
    }

    /**
     * Calculate allocated fixed cost per unit
     * Fixed costs from section divided by total monthly production
     */
    public function calculateFixedCostPerUnit(array $product): float
    {
        $sectionId = $product['id_section'];
        $monthlyQty = (int)$product['monthly_planned_quantity'];

        if ($monthlyQty <= 0) {
            return 0.0;
        }

        // Get section fixed costs
        $sectionCosts = $this->profitabilityRepository->getSectionCosts($sectionId);
        $totalFixedCosts = (float)$sectionCosts['depreciation'];

        // Get total planned production for the section
        $totalSectionProduction = $this->profitabilityRepository->getTotalMonthlyProductionForSection($sectionId);

        if ($totalSectionProduction <= 0) {
            return 0.0;
        }

        // Allocate proportionally
        $productProportion = $monthlyQty / $totalSectionProduction;
        $allocatedFixedCost = $totalFixedCosts * $productProportion;

        // Return per unit cost
        return $allocatedFixedCost / $monthlyQty;
    }

    /**
     * Calculate allocated variable cost per unit
     * Uses new cost_media_section_allocation structure with fallback to old columns
     *
     * @param array $product Product data with section info
     * @param int|null $costMonthId Specific cost month ID (null = latest)
     * @return float Variable cost per unit
     */
    public function calculateVariableCostPerUnit(array $product, ?int $costMonthId = null): float
    {
        $sectionId = $product['id_section'];
        $monthlyQty = (int)$product['monthly_planned_quantity'];

        if ($monthlyQty <= 0) {
            return 0.0;
        }

        // Determine which cost month to use
        if ($costMonthId === null) {
            $latestMonth = $this->costMonthRepo->getLatest();
            $costMonthId = !empty($latestMonth) ? (int)$latestMonth['id'] : null;
        }

        $totalVariableCosts = 0.0;

        // Try new structure first (cost_media_section_allocation)
        if ($costMonthId !== null) {
            $totalVariableCosts = $this->mediaAllocationRepo->getTotalAllocatedCostForSection(
                $costMonthId,
                $sectionId
            );
        }

        // Fallback to old structure if no data in new system
        if ($totalVariableCosts == 0.0) {
            $sectionCosts = $this->profitabilityRepository->getSectionCosts($sectionId);
            $totalVariableCosts = (float)($sectionCosts['water_cost'] ?? 0)
                                + (float)($sectionCosts['power_supply_cost'] ?? 0)
                                + (float)($sectionCosts['fuel_cost'] ?? 0)
                                + (float)($sectionCosts['other_cost'] ?? 0);
        }

        // Get total planned production for the section
        $totalSectionProduction = $this->profitabilityRepository->getTotalMonthlyProductionForSection($sectionId);

        if ($totalSectionProduction <= 0) {
            return 0.0;
        }

        // Allocate proportionally
        $productProportion = $monthlyQty / $totalSectionProduction;
        $allocatedVariableCost = $totalVariableCosts * $productProportion;

        // Return per unit cost
        return $allocatedVariableCost / $monthlyQty;
    }

    /**
     * Calculate suggested price based on target margin
     * Formula: Price = Cost / (1 - Margin%)
     */
    public function calculateSuggestedPrice(float $totalCost, float $targetMarginPercent): float
    {
        if ($targetMarginPercent >= 100) {
            return $totalCost * 2; // Fallback: 100% markup
        }

        $marginDecimal = $targetMarginPercent / 100;
        return $totalCost / (1 - $marginDecimal);
    }

    /**
     * Determine status message based on margin
     */
    private function determineStatus(float $marginPercent, float $warningThreshold, float $targetMargin): array
    {
        if ($marginPercent < $warningThreshold) {
            return [
                'level' => 'danger',
                'message' => 'OSTRZEŻENIE: Marża poniżej progu bezpieczeństwa!',
                'icon' => 'bi-exclamation-triangle-fill'
            ];
        } elseif ($marginPercent < $targetMargin) {
            return [
                'level' => 'warning',
                'message' => 'Uwaga: Marża poniżej docelowej',
                'icon' => 'bi-exclamation-circle-fill'
            ];
        } else {
            return [
                'level' => 'success',
                'message' => 'OK: Produkt rentowny',
                'icon' => 'bi-check-circle-fill'
            ];
        }
    }

    /**
     * Get list of all products with KPIs for list view
     *
     * @param string|null $searchTerm Search filter
     * @param int|null $costMonthId Cost month ID for variable costs (null = latest)
     * @return array
     */
    public function getProductListWithKPIs(?string $searchTerm = null, ?int $costMonthId = null): array
    {
        $products = $this->profitabilityRepository->getProductListWithKPIs($searchTerm);
        $result = [];

        foreach ($products as $product) {
            // Calculate profitability for each product
            try {
                $profitability = $this->calculateProfitability($product['id'], null, null, $costMonthId);

                $result[] = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'category_name' => $product['category_name'],
                    'section_name' => $product['section_name'],
                    'cost_per_unit' => $profitability['kpis']['cost_per_unit'],
                    'profit_per_unit' => $profitability['kpis']['profit_per_unit'],
                    'margin_percentage' => $profitability['kpis']['margin_percentage'],
                    'sales_price' => $profitability['kpis']['sales_price'],
                    'status' => $profitability['status']
                ];
            } catch (\Exception $e) {
                // Log error and skip products with missing data
                error_log("Profitability calculation failed for product {$product['id']} ({$product['name']}): {$e->getMessage()}");
                continue;
            }
        }

        return $result;
    }
}
