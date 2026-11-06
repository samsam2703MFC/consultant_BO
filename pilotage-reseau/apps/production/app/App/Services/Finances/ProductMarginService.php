<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\ProductMarginRepository;
use App\Services\Production\Cost\SectionUnitCostService;

/**
 * ProductMarginService
 *
 * Calculates the "current" margin for a product based on:
 *  1. Foodcost       – koszt surowców karty produktu (= koszt receptury, jedno źródło)
 *  2–5. Media, wynagrodzenia, inne (amortyzacja działu + koszty ogólne) i logistyka na karton –
 *       wspólna reguła kosztu działu (SectionUnitCostService), ta sama co Kalkulator, Koszty i Rentowność.
 *
 * All averages are computed from the last $months cost_month records (sums of costs ÷ sums of cartons).
 * Supports simulation multipliers (sliders) applied client-side via JSON payload.
 */
class ProductMarginService
{
    public function __construct(
        private ProductMarginRepository $repo,
        private SectionUnitCostService $unitCosts
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function getProducts(): array
    {
        return $this->repo->getAllProducts('fp.name');
    }

    public function getAvailableMonthsCount(): int
    {
        return $this->repo->getAvailableMonthsCount();
    }

    /**
     * Returns basic product data (id, name, id_recipe, id_section etc.) without full calculation.
     */
    public function getProductBasicData(int $productId): array
    {
        return $this->repo->getProductData($productId);
    }

    /**
     * Calculates margin for all products grouped by category.
     *
     * Returns array of categories, each with:
     *  - category_id, category_name, target_margin
     *  - avg_margin_percent (weighted by number of products with sale price)
     *  - products[]  — each with product_id, name, margin_percent, total_cost, sale_price_pln, has_data
     *
     * @param int $requestedMonths
     * @return array{categories: array, data_basis: array, months_used: int, available_months: int}
     */
    public function calculateCategoryOverview(int $requestedMonths = 12): array
    {
        $availableMonths = $this->repo->getAvailableMonthsCount();
        $months = min($requestedMonths, max(1, $availableMonths));

        $monthIds     = $this->repo->getLastMonthIds($months);
        $dataBasis    = $this->repo->getDataBasisLabel($monthIds);
        $exchangeRate = $this->repo->getExchangeRateEuro();

        // --- Koszt działów i firmy na karton (wspólna reguła) ---
        $basis = $this->unitCosts->months($monthIds);

        // --- Load all categories + products ---
        $rows = $this->repo->getCategoriesWithProducts();

        // Group into categories map
        $categoriesMap = [];
        foreach ($rows as $row) {
            $cid = (int)$row['category_id'];
            if (!isset($categoriesMap[$cid])) {
                $categoriesMap[$cid] = [
                    'category_id'    => $cid,
                    'category_name'  => $row['category_name'],
                    'target_margin'  => (float)($row['target_margin'] ?? 0),
                    'products'       => [],
                ];
            }
            if (empty($row['product_id'])) {
                continue; // kategoria bez produktów
            }
            $categoriesMap[$cid]['products'][] = [
                'product_id'     => (int)$row['product_id'],
                'name'           => $row['product_name'],
                'section_id'     => (int)($row['id_section'] ?? 0),
                'section_name'   => $row['section_name'] ?? '',
                'foodcost'       => (float)$row['foodcost'],
                'standard_price' => (float)($row['standard_price'] ?? 0),
            ];
        }

        // --- Collect all product IDs and load WDT sales quantities in one query ---
        $allProductIds = [];
        foreach ($categoriesMap as $cat) {
            foreach ($cat['products'] as $p) {
                $allProductIds[] = $p['product_id'];
            }
        }
        $salesData = !empty($monthIds) && !empty($allProductIds)
            ? $this->repo->getSoldQuantityPerProductInMonths($allProductIds, $monthIds)
            : [];

        // Build ordered month labels from monthIds (newest first → reverse for display)
        $monthLabels = $this->repo->getMonthLabelsForIds($monthIds);

        // --- Calculate margin per product, aggregate per category ---
        $categories = [];

        foreach ($categoriesMap as $cid => $cat) {
            $productsResult = [];
            $marginSum      = 0.0;
            $marginCount    = 0;

            foreach ($cat['products'] as $p) {
                $sid      = $p['section_id'];
                $foodcost = $p['foodcost'];

                $unit      = SectionUnitCostService::unitCost($basis, $sid > 0 ? $sid : null, $foodcost);
                $totalCost = $unit['total'];

                // Cena sprzedaży: standard_price z final_product (EUR)
                $standardPriceEur = (float)($p['standard_price'] ?? 0);
                $salePricePln     = $standardPriceEur > 0 && $exchangeRate > 0
                    ? $standardPriceEur * $exchangeRate
                    : 0.0;

                $marginPct = $salePricePln > 0
                    ? (($salePricePln - $totalCost) / $salePricePln) * 100
                    : null;

                // Sugerowana cena wg marży kategorii:
                // cena = totalCost / (1 - targetMargin/100)
                $targetMargin     = $cat['target_margin'];
                $suggestedPricePln = ($targetMargin < 100 && $totalCost > 0)
                    ? $totalCost / (1 - $targetMargin / 100)
                    : null;
                $suggestedPriceEur = ($suggestedPricePln !== null && $exchangeRate > 0)
                    ? $suggestedPricePln / $exchangeRate
                    : null;

                $productsResult[] = [
                    'product_id'           => $p['product_id'],
                    'name'                 => $p['name'],
                    'section_name'         => $p['section_name'],
                    'foodcost'             => round($foodcost, 2),
                    'foodcost_eur'         => $exchangeRate > 0 ? round($foodcost / $exchangeRate, 2) : null,
                    'total_cost'           => round($totalCost, 2),
                    'total_cost_eur'       => $exchangeRate > 0 ? round($totalCost / $exchangeRate, 2) : null,
                    'sale_price_pln'       => round($salePricePln, 2),
                    'sale_price_eur'       => round($standardPriceEur, 2),
                    'margin_percent'       => $marginPct !== null ? round($marginPct, 1) : null,
                    'has_data'             => $standardPriceEur > 0,
                    'suggested_price_pln'  => $suggestedPricePln !== null ? round($suggestedPricePln, 2) : null,
                    'suggested_price_eur'  => $suggestedPriceEur !== null ? round($suggestedPriceEur, 2) : null,
                    // WDT sales data
                    'sold_qty_total'       => isset($salesData[$p['product_id']]) ? $salesData[$p['product_id']]['total_qty'] : 0,
                    'sold_qty_per_month'   => isset($salesData[$p['product_id']]) ? $salesData[$p['product_id']]['months'] : [],
                    'avg_sold_qty_per_month' => (isset($salesData[$p['product_id']]) && $months > 0)
                        ? round($salesData[$p['product_id']]['total_qty'] / $months, 1)
                        : 0,
                ];

                if ($marginPct !== null) {
                    $marginSum   += $marginPct;
                    $marginCount += 1;
                }
            }

            if (empty($productsResult)) {
                continue; // pomiń kategorie bez aktywnych produktów
            }

            $avgMargin = $marginCount > 0 ? round($marginSum / $marginCount, 1) : null;

            $categories[] = [
                'category_id'        => $cat['category_id'],
                'category_name'      => $cat['category_name'],
                'target_margin'      => $cat['target_margin'],
                'avg_margin_percent' => $avgMargin,
                'products_count'     => count($productsResult),
                'products_with_data' => $marginCount,
                'products'           => $productsResult,
            ];
        }

        // Sortuj alfabetycznie po nazwie kategorii
        usort($categories, fn($a, $b) => strcmp($a['category_name'], $b['category_name']));

        return [
            'categories'       => $categories,
            'data_basis'       => $dataBasis,
            'months_used'      => $months,
            'available_months' => $availableMonths,
            'exchange_rate'    => $exchangeRate,
            'month_labels'     => $monthLabels,
        ];
    }

    /**
     * Main calculation method.
     *
     * @param int   $productId        ID of the product
     * @param int   $requestedMonths  How many months to look back (1-12)
     * @return array
     */
    public function calculate(int $productId, int $requestedMonths = 12): array
    {
        $warnings = [];

        // --- 0. Clamp months --------------------------------------------------
        $availableMonths = $this->repo->getAvailableMonthsCount();
        $months = min($requestedMonths, max(1, $availableMonths));
        if ($months < $requestedMonths) {
            $warnings[] = "Dostępnych jest tylko {$months} miesięcy danych (żądano {$requestedMonths}).";
        }

        // --- 1. Product data --------------------------------------------------
        $product = $this->repo->getProductData($productId);
        if (empty($product)) {
            return $this->emptyResult('Nie znaleziono produktu.');
        }

        $sectionId = (int)($product['id_section'] ?? 0);
        if ($sectionId <= 0) {
            $warnings[] = 'Produkt nie ma przypisanego działu – koszty działu nie są obliczone.';
        }

        // --- 2. Foodcost per carton -------------------------------------------
        $foodcost = (float)($product['precalculated_material_cost'] ?? 0);
        if ($foodcost <= 0) {
            $warnings[] = 'Brak kosztu surowcowego (precalculated_material_cost).';
        }

        // --- 3. Get last N month IDs ------------------------------------------
        $monthIds = $this->repo->getLastMonthIds($months);
        if (empty($monthIds)) {
            $warnings[] = 'Brak zdefiniowanych miesięcy kosztowych.';
        }

        $monthsMeta   = $this->repo->getMonthsMeta($monthIds);
        $actualMonths = count($monthIds);
        $dataBasis    = $this->repo->getDataBasisLabel($monthIds);

        // Warn if not all months are locked
        if (!$dataBasis['all_locked'] && $actualMonths > 0) {
            $warnings[] = 'Uwaga: podstawa obliczenia zawiera miesiące niezamknięte (OPEN). '
                        . 'Dane mogą być niekompletne. Zamknij miesiące w kalkulatorze kosztów.';
        }

        // --- 4–6. Koszt działu, koszty ogólne i logistyka na karton (wspólna reguła) ---
        $basis = $this->unitCosts->months($monthIds);
        foreach ($basis['warnings'] as $w) $warnings[] = $w;
        $unit = SectionUnitCostService::unitCost($basis, $sectionId > 0 ? $sectionId : null, $foodcost);
        $avgMediaPerCarton     = $unit['media'];
        $avgLaborPerCarton     = $unit['labor'];
        $avgOtherPerCarton     = $unit['depreciation'] + $unit['overhead'];
        $avgLogisticsPerCarton = $unit['logistics'];

        // --- 7. Exchange rate -------------------------------------------------
        $exchangeRate = $this->repo->getExchangeRateEuro();
        if ($exchangeRate <= 0) {
            $warnings[] = 'Brak kursu EUR w ustawieniach firmy.';
        }

        // --- 8. Sale price: standard_price z final_product (EUR) ---------------
        $avgSalePriceForeign = (float)($product['standard_price'] ?? 0);
        $saleCurrency        = 'EUR';
        $avgSalePricePln     = $avgSalePriceForeign > 0 && $exchangeRate > 0
            ? $avgSalePriceForeign * $exchangeRate
            : 0.0;

        if ($avgSalePriceForeign <= 0) {
            $warnings[] = 'Brak ceny standardowej produktu (final_product.standard_price = 0).';
        }

        // --- 9. Total cost per carton ----------------------------------------
        $totalCostPerCarton = $foodcost
            + $avgMediaPerCarton
            + $avgLaborPerCarton
            + $avgOtherPerCarton
            + $avgLogisticsPerCarton;

        $marginAmount  = $avgSalePricePln - $totalCostPerCarton;
        $marginPercent = $avgSalePricePln > 0 ? ($marginAmount / $avgSalePricePln) * 100 : 0.0;

        // --- 10. Breakdown percentages (of sale price) -----------------------
        $breakdown = $this->buildBreakdown(
            $avgSalePricePln,
            $foodcost,
            $avgMediaPerCarton,
            $avgLaborPerCarton,
            $avgOtherPerCarton,
            $avgLogisticsPerCarton,
            $marginAmount
        );

        return [
            'product'             => $product,
            'months_requested'    => $requestedMonths,
            'months_used'         => $actualMonths,
            'data_basis'          => $dataBasis,
            'warnings'            => $warnings,
            'kpi' => [
                'foodcost'                   => $foodcost,
                'avg_media_per_carton'       => $avgMediaPerCarton,
                'avg_labor_per_carton'       => $avgLaborPerCarton,
                'avg_other_per_carton'       => $avgOtherPerCarton,
                'avg_logistics_per_carton'   => $avgLogisticsPerCarton,
                'total_cost_per_carton'      => $totalCostPerCarton,
                'sale_price_foreign'         => $avgSalePriceForeign,
                'sale_currency'              => $saleCurrency,
                'sale_price_pln'             => $avgSalePricePln,
                'margin_amount'              => $marginAmount,
                'margin_percent'             => $marginPercent,
                'exchange_rate'              => $exchangeRate,
            ],
            'breakdown'           => $breakdown,
            'section_allocations' => [],
            'monthly_details'     => [],
            'media_breakdown'     => [],
            'logistics_breakdown' => [],
            // Simulation base data – exposed as JSON for JS sliders
            'simulation_base' => [
                'foodcost'              => $foodcost,
                'media_per_carton'      => $avgMediaPerCarton,
                'labor_per_carton'      => $avgLaborPerCarton,
                'other_per_carton'      => $avgOtherPerCarton,
                'logistics_per_carton'  => $avgLogisticsPerCarton,
                'total_cost_per_carton' => $totalCostPerCarton,
                'sale_price_foreign'    => $avgSalePriceForeign,
                'sale_price_pln'        => $avgSalePricePln,
                'sale_currency'         => $saleCurrency,
                'margin_percent'        => $marginPercent,
                'exchange_rate'         => $exchangeRate,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Private calculation helpers
    // -------------------------------------------------------------------------

    private function buildBreakdown(
        float $salePrice,
        float $foodcost,
        float $media,
        float $labor,
        float $other,
        float $logistics,
        float $margin
    ): array {
        if ($salePrice <= 0) {
            // Brak ceny sprzedaży – pokaż strukturę kosztów względem sumy kosztów
            $totalCost = $foodcost + $media + $labor + $other + $logistics;
            if ($totalCost <= 0) {
                return ['foodcost' => 0, 'media' => 0, 'labor' => 0, 'other' => 0, 'logistics' => 0, 'margin' => 0];
            }
            return [
                'foodcost'  => max(0, ($foodcost  / $totalCost) * 100),
                'media'     => max(0, ($media     / $totalCost) * 100),
                'labor'     => max(0, ($labor     / $totalCost) * 100),
                'other'     => max(0, ($other     / $totalCost) * 100),
                'logistics' => max(0, ($logistics / $totalCost) * 100),
                'margin'    => 0,
            ];
        }
        return [
            'foodcost'  => max(0, ($foodcost  / $salePrice) * 100),
            'media'     => max(0, ($media     / $salePrice) * 100),
            'labor'     => max(0, ($labor     / $salePrice) * 100),
            'other'     => max(0, ($other     / $salePrice) * 100),
            'logistics' => max(0, ($logistics / $salePrice) * 100),
            'margin'    => max(0, ($margin    / $salePrice) * 100),
        ];
    }

    private function emptyResult(string $message): array
    {
        return [
            'product'             => [],
            'months_requested'    => 0,
            'months_used'         => 0,
            'data_basis'          => ['label' => 'Brak danych', 'from' => null, 'to' => null, 'count' => 0, 'all_locked' => false],
            'warnings'            => [$message],
            'kpi'                 => [],
            'breakdown'           => [],
            'section_allocations' => [],
            'monthly_details'     => [],
            'media_breakdown'     => [],
            'logistics_breakdown' => [],
            'simulation_base'     => [],
        ];
    }
}

