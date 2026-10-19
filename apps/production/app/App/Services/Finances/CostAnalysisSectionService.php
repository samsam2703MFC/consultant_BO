<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\CostAnalysisRepository;
use App\Repositories\Production\Cost\CostMediaSectionAllocationRepository;
use App\Repositories\Production\Cost\CostMonthRepository;
use DateTime;

class CostAnalysisSectionService
{
    private int $defaultWorkloadPoints = 3;

    public function __construct(
        private CostAnalysisRepository $costAnalysisRepository,
        private CostMediaSectionAllocationRepository $mediaAllocationRepo,
        private CostMonthRepository $costMonthRepo
    ) {
    }

    public function getSections(): array
    {
        return $this->costAnalysisRepository->getSections();
    }

    public function getCostMonths(): array
    {
        return $this->costAnalysisRepository->getCostMonths();
    }

    public function calculate(int $sectionId, int $monthId): array
    {
        $warnings = [];

        $month = $this->getMonthById($monthId);
        if (empty($month)) {
            $month = $this->getLatestMonth();
        }

        $dateRange = $this->getMonthDateRange($month);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        $exchangeRateEuro = $this->costAnalysisRepository->getExchangeRateEuro();
        if ($exchangeRateEuro <= 0) {
            $warnings[] = 'Brak kursu EUR w ustawieniach firmy.';
        }

        $sectionCosts = $this->costAnalysisRepository->getSectionCosts($sectionId);
        if (empty($sectionCosts)) {
            $warnings[] = 'Brak danych kosztów działu.';
        }

        $employeeCost = ($month && $sectionId > 0)
            ? $this->costAnalysisRepository->getSectionEmployeeCost((int)$month['id'], $sectionId)
            : 0.0;

        // Koszty zmienne (media) - nowa struktura z fallbackiem
        $mediaCosts = 0.0;
        $monthId = (int)($month['id'] ?? 0);

        if ($monthId > 0) {
            // Najpierw spróbuj pobrać z nowej struktury cost_media_section_allocation
            $mediaCosts = $this->mediaAllocationRepo->getTotalAllocatedCostForSection($monthId, $sectionId);
        }

        // Fallback do starych kolumn jeśli brak danych w nowej strukturze
        if ($mediaCosts == 0.0) {
            $mediaCosts = (float)($sectionCosts['water_cost'] ?? 0)
                        + (float)($sectionCosts['power_supply_cost'] ?? 0)
                        + (float)($sectionCosts['fuel_cost'] ?? 0)
                        + (float)($sectionCosts['other_cost'] ?? 0);
        }

        // Koszty stałe (amortyzacja)
        $fixedCosts = (float)($sectionCosts['depreciation'] ?? 0);

        $sectionCostPool = $employeeCost + $fixedCosts + $mediaCosts;

        $sectionTotals = $this->costAnalysisRepository->getSectionWeightedUnitsInRange(
            $sectionId,
            $startDate,
            $endDate,
            $this->defaultWorkloadPoints
        );

        $totalWeightedUnits = (float)($sectionTotals['weighted_units'] ?? 0);
        $totalSectionUnits = (float)($sectionTotals['units_qty'] ?? 0);

        if ($totalWeightedUnits <= 0) {
            $warnings[] = 'Brak wolumenu działu w WDT — koszty działu (' . number_format($sectionCostPool, 2) . ' zł) zostają doliczone do kosztów wspólnych firmy.';
        }

        $sectionCostPerPoint = $totalWeightedUnits > 0
            ? $sectionCostPool / $totalWeightedUnits
            : 0.0;

        // Jeśli dział nie ma wolumenu, jego koszty redystrybuujemy do puli wspólnej
        $extraSharedPool = $totalWeightedUnits <= 0 ? $sectionCostPool : 0.0;

        $sharedCostUnit = $this->calculateSharedCostPerCarton((int)$month['id'], $startDate, $endDate, $warnings, $extraSharedPool);

        $products = $this->costAnalysisRepository->getSectionSalesProductsInRange($sectionId, $startDate, $endDate);

        if (empty($products)) {
            $warnings[] = 'Brak sprzedaży w wybranym okresie.';
        }

        $rows = [];
        $revenuePlnTotal = 0.0;
        $costPlnTotal = 0.0;
        $cartonsTotal = 0;

        foreach ($products as $product) {
            $cartonsQty = (int)($product['cartons_qty'] ?? 0);

            $workloadPoints = (int)($product['workload_points'] ?? $this->defaultWorkloadPoints);
            if ($workloadPoints <= 0) {
                $workloadPoints = $this->defaultWorkloadPoints;
            }

            $rawCostCarton = $this->calculateRawCostPerCarton($product, $warnings);

            $salePriceCarton = $this->resolveSalePricePerCarton($product, $warnings);
            $saleCurrency = $product['currency'] ?? 'EUR';
            $currencyCount = (int)($product['currency_count'] ?? 0);
            if ($currencyCount > 1) {
                $warnings[] = 'W okresie występuje więcej niż jedna waluta w WDT (produkt: ' . ($product['product_name'] ?? '') . ').';
            }
            $salePriceCartonPln = $this->convertSalePriceToPln($salePriceCarton, $saleCurrency, $exchangeRateEuro, $warnings);

            $sectionCostCarton = $sectionCostPerPoint * $workloadPoints;
            $unitFullCost = $rawCostCarton + $sectionCostCarton + $sharedCostUnit;
            $marginAmount = $salePriceCartonPln - $unitFullCost;
            $marginPercent = $salePriceCartonPln > 0 ? ($marginAmount / $salePriceCartonPln) * 100 : 0;

            $revenuePln = $salePriceCartonPln * $cartonsQty;
            $costPln = $unitFullCost * $cartonsQty;

            $revenuePlnTotal += $revenuePln;
            $costPlnTotal += $costPln;
            $cartonsTotal += $cartonsQty;

            $rows[] = [
                'product_id' => $product['product_id'],
                'product_name' => $product['product_name'],
                'category_name' => $product['category_name'] ?? '-',
                'cartons_qty' => $cartonsQty,
                'sale_price_unit' => $salePriceCarton,
                'sale_currency' => $saleCurrency,
                'sale_price_unit_pln' => $salePriceCartonPln,
                'raw_cost_unit' => $rawCostCarton,
                'section_cost_unit' => $sectionCostCarton,
                'shared_cost_unit' => $sharedCostUnit,
                'unit_full_cost' => $unitFullCost,
                'margin_amount' => $marginAmount,
                'margin_percent' => $marginPercent
            ];
        }

        $marginTotal = $revenuePlnTotal - $costPlnTotal;
        $marginPercentTotal = $revenuePlnTotal > 0 ? ($marginTotal / $revenuePlnTotal) * 100 : 0;

        return [
            'section' => $sectionCosts,
            'month' => $month,
            'date_range' => $dateRange,
            'warnings' => $warnings,
            'summary' => [
                'revenue_pln' => $revenuePlnTotal,
                'cost_pln' => $costPlnTotal,
                'margin_pln' => $marginTotal,
                'margin_percent' => $marginPercentTotal,
                'units_total' => $cartonsTotal
            ],
            'explanation' => [
                'section_cost_pool' => $sectionCostPool,
                'employee_cost' => $employeeCost,
                'fixed_costs' => $fixedCosts,
                'media_costs' => $mediaCosts,
                'total_weighted_units' => $totalWeightedUnits,
                'total_section_units' => $totalSectionUnits,
                'section_cost_per_point' => $sectionCostPerPoint,
                'shared_cost_unit' => $sharedCostUnit,
                'exchange_rate_euro' => $exchangeRateEuro
            ],
            'rows' => $rows
        ];
    }

    private function getMonthById(int $monthId): array
    {
        foreach ($this->getCostMonths() as $month) {
            if ((int)$month['id'] === $monthId) {
                return $month;
            }
        }
        return [];
    }

    private function getLatestMonth(): array
    {
        $months = $this->getCostMonths();
        return $months[0] ?? [];
    }

    private function getMonthDateRange(array $month): array
    {
        if (empty($month)) {
            $now = new DateTime('first day of this month');
            $start = $now->format('Y-m-01');
            $end = $now->format('Y-m-t');
            return ['start' => $start, 'end' => $end];
        }

        $year = (int)$month['year'];
        $monthNum = (int)$month['month'];
        $start = new DateTime("$year-$monthNum-01");
        $end = new DateTime($start->format('Y-m-t'));

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d')
        ];
    }

    private function calculateRawCostPerCarton(array $product, array &$warnings): float
    {
        $recipeCost = (float)($product['precalculated_material_cost'] ?? 0);
        if ($recipeCost > 0) {
            return $recipeCost;
        }


        $warnings[] = 'Brak kosztu surowcowego (product_recipe.precalculated_material_cost) dla produktu: ' . ($product['product_name'] ?? '');
        return 0.0;
    }

    private function resolveSalePricePerCarton(array $product, array &$warnings): float
    {
        $avgPriceCarton = $product['avg_price_carton'] ?? null;

        if (!empty($avgPriceCarton)) {
            return (float)$avgPriceCarton;
        }

        $standardPrice = (float)($product['standard_price'] ?? 0);
        if ($standardPrice > 0) {
            $warnings[] = 'Brak ceny z dokumentów WDT – użyto ceny standardowej (produkt: ' . ($product['product_name'] ?? '') . ').';
            return $standardPrice;
        }

        $warnings[] = 'Brak ceny sprzedaży dla produktu: ' . ($product['product_name'] ?? '');
        return 0.0;
    }

    private function calculateSharedCostPerCarton(int $monthId, string $startDate, string $endDate, array &$warnings, float $extraPool = 0.0): float
    {
        $sharedPool = $this->costAnalysisRepository->getSharedCostPool($monthId);
        $sharedPool += $extraPool;

        if ($sharedPool <= 0) {
            $warnings[] = 'Brak kosztów wspólnych do alokacji.';
            return 0.0;
        }

        $totalCartons = $this->costAnalysisRepository->getTotalUnitsInRange($startDate, $endDate);
        if ($totalCartons <= 0) {
            $warnings[] = 'Brak wolumenu do alokacji kosztów wspólnych.';
            return 0.0;
        }

        return $sharedPool / $totalCartons;
    }

    private function convertSalePriceToPln(float $salePrice, string $currency, float $exchangeRateEuro, array &$warnings): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'EUR') {
            if ($exchangeRateEuro <= 0) {
                return 0.0;
            }
            return $salePrice * $exchangeRateEuro;
        }

        if ($currency === 'PLN') {
            return $salePrice;
        }

        $warnings[] = 'Nieobslugiwana waluta w WDT: ' . $currency;
        return $salePrice;
    }
}
