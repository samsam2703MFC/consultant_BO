<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\CostAnalysisRepository;
use App\Services\Product\ProductService;
use DateTime;

class CostAnalysisService
{
    private int $defaultWorkloadPoints = 3;

    public function __construct(
        private CostAnalysisRepository $costAnalysisRepository,
        private ProductService $productService
    ) {
    }

    public function getCostMonths(): array
    {
        return $this->costAnalysisRepository->getCostMonths();
    }

    public function getProducts($orderBy = ""): array
    {
        return $this->productService->getAll($orderBy);
    }

    public function calculate(int $productId, int $monthId): array
    {
        $warnings = [];

        $product = $this->costAnalysisRepository->getProductData($productId);
        if (empty($product)) {
            return [
                'product' => [],
                'warnings' => ['Nie znaleziono produktu.'],
                'kpi' => [],
                'breakdown' => [],
                'explanation' => []
            ];
        }

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

        $sectionId = (int)$product['id_section'];
        if ($sectionId <= 0) {
            $warnings[] = 'Brak przypisania działu do produktu.';
        }

        $sectionCosts = $sectionId > 0 ? $this->costAnalysisRepository->getSectionCosts($sectionId) : [];
        if (empty($sectionCosts)) {
            $warnings[] = 'Brak danych kosztów działu.';
        }

        $employeeCost = ($month && $sectionId > 0)
            ? $this->costAnalysisRepository->getSectionEmployeeCost((int)$month['id'], $sectionId)
            : 0.0;

        $sectionFixedCost = (float)($sectionCosts['depreciation'] ?? 0)
            + (float)($sectionCosts['water_cost'] ?? 0)
            + (float)($sectionCosts['power_supply_cost'] ?? 0)
            + (float)($sectionCosts['fuel_cost'] ?? 0)
            + (float)($sectionCosts['other_cost'] ?? 0);

        $sectionCostPool = $employeeCost + $sectionFixedCost;

        $sales = $this->costAnalysisRepository->getProductSalesInRange($productId, $startDate, $endDate);
        $soldCartons = (int)($sales['cartons_qty'] ?? 0);

        if ($soldCartons <= 0) {
            $warnings[] = 'Brak danych sprzedaży dla wybranego okresu.';
        }

        $workloadPoints = (int)($product['workload_points'] ?? $this->defaultWorkloadPoints);
        if ($workloadPoints <= 0) {
            $workloadPoints = $this->defaultWorkloadPoints;
            $warnings[] = 'Brak punktów pracochłonności – użyto wartości domyślnej.';
        }

        $sectionTotals = ($sectionId > 0)
            ? $this->costAnalysisRepository->getSectionWeightedUnitsInRange($sectionId, $startDate, $endDate, $this->defaultWorkloadPoints)
            : ['weighted_units' => 0, 'units_qty' => 0];

        $totalWeightedUnits = (float)($sectionTotals['weighted_units'] ?? 0);
        $totalSectionUnits = (float)($sectionTotals['units_qty'] ?? 0);

        if ($totalWeightedUnits <= 0) {
            $warnings[] = 'Brak wolumenu działu do alokacji kosztów.';
        }

        $sectionCostPerPoint = $totalWeightedUnits > 0
            ? $sectionCostPool / $totalWeightedUnits
            : 0.0;

        $sectionCostUnit = $sectionCostPerPoint * $workloadPoints;

        $rawCostCarton = $this->calculateRawCostPerCarton($product, $warnings);

        $salePriceCarton = $this->resolveSalePricePerCarton($product, $sales, $warnings);
        $saleCurrency = $sales['currency'] ?? 'EUR';
        $currencyCount = (int)($sales['currency_count'] ?? 0);

        if ($currencyCount > 1) {
            $warnings[] = 'W okresie występuje więcej niż jedna waluta w WDT.';
        }

        $salePriceCartonPln = $this->convertSalePriceToPln($salePriceCarton, $saleCurrency, $exchangeRateEuro, $warnings);

        if ($salePriceCartonPln <= 0) {
            $warnings[] = 'Cena sprzedaży (PLN) jest równa 0 lub brak przeliczenia waluty.';
        }

        $sharedCostCarton = $this->calculateSharedCostPerCarton((int)$month['id'], $startDate, $endDate, $warnings);

        $unitOperatingCost = $rawCostCarton + $sectionCostUnit;
        $unitFullCost = $rawCostCarton + $sectionCostUnit + $sharedCostCarton;

        $marginAmount = $salePriceCartonPln - $unitFullCost;
        $marginPercent = $salePriceCartonPln > 0 ? ($marginAmount / $salePriceCartonPln) * 100 : 0;

        $contributionAmount = $salePriceCartonPln - $rawCostCarton;
        $contributionPercent = $salePriceCartonPln > 0 ? ($contributionAmount / $salePriceCartonPln) * 100 : 0;

        $breakdown = $this->calculateBreakdown($salePriceCartonPln, $rawCostCarton, $sectionCostUnit, $sharedCostCarton, $marginAmount);
        $breakdownAmounts = [
            'raw' => $rawCostCarton,
            'section' => $sectionCostUnit,
            'shared' => $sharedCostCarton,
            'margin' => $marginAmount
        ];

        return [
            'product' => $product,
            'month' => $month,
            'date_range' => $dateRange,
            'warnings' => $warnings,
            'kpi' => [
                'sale_price_unit' => $salePriceCarton,
                'sale_currency' => $saleCurrency,
                'sale_price_unit_pln' => $salePriceCartonPln,
                'raw_cost_unit' => $rawCostCarton,
                'section_cost_unit' => $sectionCostUnit,
                'shared_cost_unit' => $sharedCostCarton,
                'unit_full_cost' => $unitFullCost,
                'margin_amount' => $marginAmount,
                'margin_percent' => $marginPercent,
                'contribution_amount' => $contributionAmount,
                'contribution_percent' => $contributionPercent
            ],
            'breakdown' => $breakdown,
            'breakdown_amounts' => $breakdownAmounts,
            'explanation' => [
                'section_name' => $product['section_name'] ?? '-',
                'section_cost_pool' => $sectionCostPool,
                'employee_cost' => $employeeCost,
                'section_fixed_cost' => $sectionFixedCost,
                'total_weighted_units' => $totalWeightedUnits,
                'total_section_units' => $totalSectionUnits,
                'section_cost_per_point' => $sectionCostPerPoint,
                'workload_points' => $workloadPoints,
                'sold_units' => $soldCartons,
                'sold_cartons' => $soldCartons,
                'exchange_rate_euro' => $exchangeRateEuro
            ]
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


        $warnings[] = 'Brak kosztu surowcowego (product_recipe.precalculated_material_cost).';
        return 0.0;
    }

    private function resolveSalePricePerCarton(array $product, array $sales, array &$warnings): float
    {
        $avgPriceCarton = $sales['avg_price_carton'] ?? null;

        if (!empty($avgPriceCarton)) {
            return (float)$avgPriceCarton;
        }

        $standardPrice = (float)($product['standard_price'] ?? 0);
        if ($standardPrice > 0) {
            $warnings[] = 'Brak ceny z dokumentów WDT – użyto ceny standardowej.';
            return $standardPrice;
        }

        $warnings[] = 'Brak ceny sprzedaży.';
        return 0.0;
    }

    private function calculateSharedCostPerCarton(int $monthId, string $startDate, string $endDate, array &$warnings): float
    {
        $sharedPool = $this->costAnalysisRepository->getSharedCostPool($monthId);

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

    private function calculateBreakdown(float $salePrice, float $raw, float $section, float $shared, float $margin): array
    {
        if ($salePrice <= 0) {
            return [
                'raw' => 0,
                'section' => 0,
                'shared' => 0,
                'margin' => 0
            ];
        }

        return [
            'raw' => max(0, ($raw / $salePrice) * 100),
            'section' => max(0, ($section / $salePrice) * 100),
            'shared' => max(0, ($shared / $salePrice) * 100),
            'margin' => max(0, ($margin / $salePrice) * 100)
        ];
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
