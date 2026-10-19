<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\ProductMarginRepository;

/**
 * ProductMarginService
 *
 * Calculates the "current" margin for a product based on:
 *  1. Foodcost       – precalculated_material_cost from product_recipe
 *  2. Media cost     – average allocated media cost for the product's section / average sales volume
 *  3. Labor cost     – average employee cost for the section / average sales volume
 *  4. Other+Depr     – section depreciation (static) / average sales volume
 *  5. Logistics cost – total logistics costs from cost_month_logistics / total company sales volume
 *
 * All averages are computed from the last $months cost_month records.
 * Supports simulation multipliers (sliders) applied client-side via JSON payload.
 */
class ProductMarginService
{
    public function __construct(
        private ProductMarginRepository $repo
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

        // --- Pre-compute shared costs that don't depend on section ---
        $avgLogisticsPerCarton = 0.0;
        $avgOtherPerCarton     = 0.0;

        if (!empty($monthIds)) {
            $warnShared = [];
            [$avgLogisticsPerCarton] = $this->calcAvgLogisticsPerCarton($monthIds, $warnShared);
            [$avgOtherPerCarton]     = $this->calcAvgOtherPerCarton($monthIds, $warnShared);
        }

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

        // --- Per-section cost cache (avoid re-querying same section) ---
        $sectionMediaCache = [];
        $sectionLaborCache = [];

        $getMediaAvg = function (int $sid) use ($monthIds, &$sectionMediaCache): float {
            if (!isset($sectionMediaCache[$sid])) {
                $w = [];
                [$avg] = $this->calcAvgMediaPerCarton($sid, $monthIds, $w);
                $sectionMediaCache[$sid] = $avg;
            }
            return $sectionMediaCache[$sid];
        };

        $getLaborAvg = function (int $sid) use ($monthIds, &$sectionLaborCache): float {
            if (!isset($sectionLaborCache[$sid])) {
                $w = [];
                [$labor] = $this->calcAvgLaborPerCarton($sid, $monthIds, $w);
                $sectionLaborCache[$sid] = $labor;
            }
            return $sectionLaborCache[$sid];
        };

        // --- Calculate margin per product, aggregate per category ---
        $categories = [];

        foreach ($categoriesMap as $cid => $cat) {
            $productsResult = [];
            $marginSum      = 0.0;
            $marginCount    = 0;

            foreach ($cat['products'] as $p) {
                $sid      = $p['section_id'];
                $foodcost = $p['foodcost'];

                $mediaAvg = $sid > 0 ? $getMediaAvg($sid) : 0.0;
                $laborAvg = $sid > 0 ? $getLaborAvg($sid) : 0.0;

                $totalCost = $foodcost + $mediaAvg + $laborAvg + $avgOtherPerCarton + $avgLogisticsPerCarton;

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

        // --- 4. Media costs avg per carton ------------------------------------
        [$avgMediaPerCarton, $mediaBreakdown, $mediaCostPerMonth] = $this->calcAvgMediaPerCarton(
            $sectionId, $monthIds, $warnings
        );

        // --- 5. Labor costs avg per carton ------------------------------------
        [$avgLaborPerCarton, $laborCostPerMonth, $salesVolumePerMonth] = $this->calcAvgLaborPerCarton(
            $sectionId, $monthIds, $warnings
        );

        // --- 6. Other (fixed) costs avg per carton ----------------------------
        // Fixed costs are company-wide (cost_month_fixed_cost), spread over total volume.
        [$avgOtherPerCarton, $fixedCostPerMonth] = $this->calcAvgOtherPerCarton(
            $monthIds, $warnings
        );

        // --- 6.5 Logistics cost avg per carton --------------------------------
        // Dzielimy łączne koszty logistyczne przez ŁĄCZNY wolumen firmy (wszystkie działy),
        // bo logistyka jest kosztem zakładowym, nie przypisanym do jednego działu.
        [$avgLogisticsPerCarton, $logisticsCostPerMonth, $logisticsBreakdown] = $this->calcAvgLogisticsPerCarton(
            $monthIds, $warnings
        );

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

        // --- 11. Section allocation (media shares across sections) -----------
        $sectionAllocations = [];
        if ($sectionId > 0 && !empty($monthIds)) {
            $sectionAllocations = $this->buildSectionAllocations($monthIds);
        }

        // --- 12. Per-month details table -------------------------------------
        $monthlyDetails = $this->buildMonthlyDetails(
            $monthIds,
            $monthsMeta,
            $mediaCostPerMonth,
            $laborCostPerMonth,
            $salesVolumePerMonth,
            $fixedCostPerMonth,
            $logisticsCostPerMonth
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
            'section_allocations' => $sectionAllocations,
            'monthly_details'     => $monthlyDetails,
            'media_breakdown'     => $mediaBreakdown,
            'logistics_breakdown' => $logisticsBreakdown,
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

    /**
     * Calculates average allocated media cost per carton for a section.
     * Returns [float $avgPerCarton, array $mediaBreakdown, array $perMonthRaw]
     */
    private function calcAvgMediaPerCarton(int $sectionId, array $monthIds, array &$warnings): array
    {
        if ($sectionId <= 0 || empty($monthIds)) {
            return [0.0, [], []];
        }

        $mediaPerMonth  = $this->repo->getMediaCostsPerMonth($sectionId, $monthIds);
        $volumePerMonth = $this->getSalesVolumePerMonth($sectionId, $monthIds);

        $totalMedia   = 0.0;
        $totalCartons = 0.0;
        $mediaDetails = [];

        foreach ($monthIds as $mid) {
            $mediaCost = (float)($mediaPerMonth[$mid]['total_media'] ?? 0.0);
            $cartons   = (float)($volumePerMonth[$mid] ?? 0.0);

            // collect detailed media breakdown per month
            foreach ($mediaPerMonth[$mid]['details'] ?? [] as $detail) {
                $code = $detail['media_code'];
                if (!isset($mediaDetails[$code])) {
                    $mediaDetails[$code] = ['name' => $detail['media_name'], 'total_allocated' => 0.0, 'months_count' => 0];
                }
                $mediaDetails[$code]['total_allocated'] += (float)$detail['allocated_cost'];
                $mediaDetails[$code]['months_count']++;
            }

            $totalMedia   += $mediaCost;
            $totalCartons += $cartons;
        }

        // Fallback to static if no data
        if ($totalMedia <= 0) {
            $staticMedia = $this->repo->getStaticMediaCosts($sectionId);
            if ($staticMedia > 0) {
                $warnings[] = 'Brak danych mediów w nowej strukturze – użyto statycznych kosztów mediów działu.';
                $totalMedia = $staticMedia * count($monthIds);
            } else {
                $warnings[] = 'Brak danych kosztów mediów dla działu.';
            }
        }

        $avgPerCarton = $totalCartons > 0 ? ($totalMedia / $totalCartons) : 0.0;

        // Build breakdown percentages
        $breakdown = [];
        foreach ($mediaDetails as $code => $detail) {
            $breakdown[] = [
                'code'               => $code,
                'name'               => $detail['name'],
                'total_allocated'    => $detail['total_allocated'],
                'avg_per_month'      => $detail['months_count'] > 0 ? $detail['total_allocated'] / $detail['months_count'] : 0,
                'pct_of_total_media' => $totalMedia > 0 ? ($detail['total_allocated'] / $totalMedia) * 100 : 0,
            ];
        }

        return [$avgPerCarton, $breakdown, $mediaPerMonth];
    }

    /**
     * Calculates average labor cost per carton for a section.
     * Returns [float $avgPerCarton, array $laborPerMonth, array $salesVolumePerMonth]
     */
    private function calcAvgLaborPerCarton(int $sectionId, array $monthIds, array &$warnings): array
    {
        if ($sectionId <= 0 || empty($monthIds)) {
            return [0.0, [], []];
        }

        $laborPerMonth  = $this->repo->getEmployeeCostsPerMonth($sectionId, $monthIds);
        $volumePerMonth = $this->getSalesVolumePerMonth($sectionId, $monthIds);

        $totalLabor   = 0.0;
        $totalCartons = 0.0;

        foreach ($monthIds as $mid) {
            $totalLabor   += (float)($laborPerMonth[$mid] ?? 0.0);
            $totalCartons += (float)($volumePerMonth[$mid] ?? 0.0);
        }

        if (array_sum($laborPerMonth) <= 0) {
            $warnings[] = 'Brak danych wynagrodzeń pracowników dla działu w wybranym okresie.';
        }

        $avgPerCarton = $totalCartons > 0 ? ($totalLabor / $totalCartons) : 0.0;

        return [$avgPerCarton, $laborPerMonth, $volumePerMonth];
    }

    /**
     * Calculates average fixed cost per carton (company-wide) from cost_month_fixed_cost.
     * Fixed costs are not per-section – they are spread across total company sales volume,
     * analogically to logistics costs.
     * Returns [float $avgPerCarton, array $fixedCostPerMonth]
     */
    private function calcAvgOtherPerCarton(array $monthIds, array &$warnings): array
    {
        if (empty($monthIds)) {
            return [0.0, []];
        }

        $fixedPerMonth       = $this->repo->getFixedCostsPerMonth($monthIds);
        $totalVolumePerMonth = $this->repo->getTotalSalesVolumePerMonth($monthIds);

        $totalFixed   = 0.0;
        $totalCartons = 0.0;

        foreach ($monthIds as $mid) {
            $totalFixed   += (float)($fixedPerMonth[$mid]['total'] ?? 0.0);
            $totalCartons += (float)($totalVolumePerMonth[$mid] ?? 0.0);
        }

        if ($totalFixed <= 0) {
            $warnings[] = 'Brak kosztów stałych w wybranym okresie (cost_month_fixed_cost).';
        }

        if ($totalCartons <= 0) {
            $warnings[] = 'Brak łącznego wolumenu sprzedaży do przeliczenia kosztów stałych.';
        }

        $avgPerCarton = $totalCartons > 0 ? ($totalFixed / $totalCartons) : 0.0;

        // Upraszczamy do [month_id => float] dla buildMonthlyDetails
        $perMonthSimple = [];
        foreach ($monthIds as $mid) {
            $perMonthSimple[$mid] = (float)($fixedPerMonth[$mid]['total'] ?? 0.0);
        }

        return [$avgPerCarton, $perMonthSimple];
    }

    /**
     * Calculates average logistics cost per carton (company-wide).
     *
     * Logika: suma wszystkich wpisów z cost_month_logistics dla danych miesięcy
     * dzielona przez ŁĄCZNY wolumen sprzedaży całej firmy w tych miesiącach.
     * Logistyka jest kosztem zakładowym — nie przypisujemy jej do działu.
     *
     * Returns [float $avgPerCarton, array $logisticsCostPerMonth, array $logisticsBreakdown]
     */
    private function calcAvgLogisticsPerCarton(array $monthIds, array &$warnings): array
    {
        if (empty($monthIds)) {
            return [0.0, [], []];
        }

        $logisticsPerMonth  = $this->repo->getLogisticsCostsPerMonth($monthIds);
        $totalVolumePerMonth = $this->repo->getTotalSalesVolumePerMonth($monthIds);

        $totalLogistics = 0.0;
        $totalCartons   = 0.0;
        $typeDetails    = []; // kod => ['name', 'total']

        foreach ($monthIds as $mid) {
            $logCost = (float)($logisticsPerMonth[$mid]['total'] ?? 0.0);
            $cartons = (float)($totalVolumePerMonth[$mid] ?? 0.0);

            // zbieramy rozbicie na typy
            foreach ($logisticsPerMonth[$mid]['details'] ?? [] as $detail) {
                $code = $detail['type_code'];
                if (!isset($typeDetails[$code])) {
                    $typeDetails[$code] = ['name' => $detail['type_name'], 'total' => 0.0, 'months_count' => 0];
                }
                $typeDetails[$code]['total'] += (float)$detail['amount'];
                $typeDetails[$code]['months_count']++;
            }

            $totalLogistics += $logCost;
            $totalCartons   += $cartons;
        }

        if ($totalLogistics <= 0) {
            $warnings[] = 'Brak kosztów logistycznych w wybranym okresie (cost_month_logistics).';
        }

        if ($totalCartons <= 0) {
            $warnings[] = 'Brak łącznego wolumenu sprzedaży do przeliczenia kosztów logistycznych.';
        }

        $avgPerCarton = $totalCartons > 0 ? ($totalLogistics / $totalCartons) : 0.0;

        // Buduj breakdown per typ
        $breakdown = [];
        foreach ($typeDetails as $code => $detail) {
            $breakdown[] = [
                'code'            => $code,
                'name'            => $detail['name'],
                'total'           => $detail['total'],
                'avg_per_month'   => $detail['months_count'] > 0 ? $detail['total'] / $detail['months_count'] : 0.0,
                'pct_of_total'    => $totalLogistics > 0 ? ($detail['total'] / $totalLogistics) * 100 : 0.0,
            ];
        }

        // Upraszczamy logisticsCostPerMonth do [month_id => float] dla buildMonthlyDetails
        $perMonthSimple = [];
        foreach ($monthIds as $mid) {
            $perMonthSimple[$mid] = (float)($logisticsPerMonth[$mid]['total'] ?? 0.0);
        }

        return [$avgPerCarton, $perMonthSimple, $breakdown];
    }

    /**
     * Cached sales volume per month for a section (shared between media & labor).
     */
    private array $volumeCache = [];

    private function getSalesVolumePerMonth(int $sectionId, array $monthIds): array
    {
        $key = $sectionId . '_' . implode(',', $monthIds);
        if (!isset($this->volumeCache[$key])) {
            $manualVolume = $this->repo->getSectionMonthVolume($sectionId, $monthIds);
            $wdtVolume    = $this->repo->getSectionSalesVolumePerMonth($sectionId, $monthIds);

            $merged = [];
            foreach ($monthIds as $mid) {
                $manual = $manualVolume[$mid] ?? 0;
                $wdt    = $wdtVolume[$mid] ?? 0;
                $merged[$mid] = $manual > 0 ? (float)$manual : (float)$wdt;
            }
            $this->volumeCache[$key] = $merged;
        }
        return $this->volumeCache[$key];
    }

    private function calcAvgSalePrice(array $salePriceData, array &$warnings, array $product): float
    {
        if (empty($salePriceData)) {
            $std = (float)($product['standard_price'] ?? 0);
            if ($std > 0) {
                $warnings[] = 'Brak cen sprzedaży z WDT – użyto ceny standardowej produktu.';
                return $std;
            }
            $warnings[] = 'Brak ceny sprzedaży produktu.';
            return 0.0;
        }

        $totalRevenue = 0.0;
        $totalCartons = 0.0;
        foreach ($salePriceData as $row) {
            $cartons = (float)($row['cartons_qty'] ?? 0);
            $avg     = (float)($row['avg_price'] ?? 0);
            if ($cartons > 0 && $avg > 0) {
                $totalRevenue += $avg * $cartons;
                $totalCartons += $cartons;
            }
        }

        if ($totalCartons <= 0) {
            $std = (float)($product['standard_price'] ?? 0);
            if ($std > 0) {
                $warnings[] = 'Brak wolumenu sprzedaży w WDT – użyto ceny standardowej produktu.';
                return $std;
            }
            $warnings[] = 'Brak ceny sprzedaży z WDT i brak ceny standardowej.';
            return 0.0;
        }

        return $totalRevenue / $totalCartons;
    }

    private function detectCurrency(array $salePriceData, array $product): string
    {
        foreach ($salePriceData as $row) {
            if (!empty($row['currency'])) {
                return strtoupper($row['currency']);
            }
        }
        return 'EUR';
    }

    private function convertToPln(float $price, string $currency, float $rate, array &$warnings): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'PLN') {
            return $price;
        }
        if ($currency === 'EUR' || $currency === '') {
            if ($rate <= 0) {
                return 0.0;
            }
            return $price * $rate;
        }
        $warnings[] = 'Nieobsługiwana waluta: ' . $currency;
        return $price;
    }

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

    private function buildSectionAllocations(array $monthIds): array
    {
        $raw = $this->repo->getMediaAllocationBySection($monthIds);
        if (empty($raw)) {
            return [];
        }

        // Group by section_id
        $sections = [];
        $totalMap = []; // media_code => total_allocated (all sections)

        foreach ($raw as $row) {
            $sid = (int)$row['section_id'];
            if (!isset($sections[$sid])) {
                $sections[$sid] = [
                    'section_id'   => $sid,
                    'section_name' => $row['section_name'],
                    'total'        => 0.0,
                    'media'        => [],
                ];
            }
            $allocated = (float)$row['total_allocated'];
            $sections[$sid]['total'] += $allocated;
            $sections[$sid]['media'][] = [
                'media_name'    => $row['media_name'],
                'media_code'    => $row['media_code'],
                'avg_alloc_pct' => (float)$row['avg_alloc_pct'],
                'total'         => $allocated,
            ];

            $code = $row['media_code'];
            $totalMap[$code] = ($totalMap[$code] ?? 0.0) + $allocated;
        }

        $grandTotal = array_sum(array_column($sections, 'total'));

        foreach ($sections as &$sec) {
            $sec['pct_of_total'] = $grandTotal > 0 ? ($sec['total'] / $grandTotal) * 100 : 0;
        }
        unset($sec);

        return array_values($sections);
    }

    /**
     * Builds per-month details table including fixed and logistics costs.
     */
    private function buildMonthlyDetails(
        array $monthIds,
        array $monthsMeta,
        array $mediaCostPerMonth,
        array $laborCostPerMonth,
        array $salesVolumePerMonth,
        array $fixedCostPerMonth,
        array $logisticsCostPerMonth = []
    ): array {
        $metaMap = [];
        foreach ($monthsMeta as $m) {
            $metaMap[(int)$m['id']] = $m;
        }

        $rows = [];
        foreach ($monthIds as $mid) {
            $meta      = $metaMap[$mid] ?? [];
            $media     = (float)(isset($mediaCostPerMonth[$mid]) ? $mediaCostPerMonth[$mid]['total_media'] : 0.0);
            $labor     = (float)($laborCostPerMonth[$mid] ?? 0.0);
            $volume    = (float)($salesVolumePerMonth[$mid] ?? 0.0);
            $fixed     = (float)($fixedCostPerMonth[$mid] ?? 0.0);
            $logistics = (float)($logisticsCostPerMonth[$mid] ?? 0.0);

            $rows[] = [
                'month_id'        => $mid,
                'year'            => $meta['year'] ?? '-',
                'month'           => isset($meta['month']) ? str_pad((string)$meta['month'], 2, '0', STR_PAD_LEFT) : '-',
                'media_cost'      => $media,
                'labor_cost'      => $labor,
                'fixed_cost'      => $fixed,
                'logistics_cost'  => $logistics,
                'total_cost'      => $media + $labor + $fixed + $logistics,
                'cartons_qty'     => $volume,
                'cost_per_carton' => $volume > 0 ? ($media + $labor + $fixed + $logistics) / $volume : 0.0,
            ];
        }
        return $rows;
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

