<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\CostAnalysisRepository;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Services\Product\ProductService;
use App\Services\Production\Cost\SectionUnitCostService;
use DateTime;

class CostAnalysisService
{
    public function __construct(
        private CostAnalysisRepository $costAnalysisRepository,
        private ProductService $productService,
        private SectionUnitCostService $unitCosts,
        private WdtSalesRepository $wdtSales
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

        // Koszt działu, koszty ogólne i logistyka na karton : wspólna reguła (te same liczby co Marże, Kalkulator i Rentowność)
        $basis = !empty($month) ? $this->unitCosts->month((int)$month['id']) : $this->unitCosts->months([]);
        foreach ($basis['warnings'] as $w) $warnings[] = $w;
        $rawCostCarton = $this->calculateRawCostPerCarton($product, $warnings);
        $unit = SectionUnitCostService::unitCost($basis, $sectionId > 0 ? $sectionId : null, $rawCostCarton);
        $section = $basis['sections'][$sectionId] ?? null;

        $sectionCostUnit = $unit['labor'] + $unit['media'] + $unit['depreciation'];
        $sharedCostCarton = $unit['overhead'] + $unit['logistics'];

        // Cena sprzedaży : średnia ważona z WDT miesiąca (wartość ÷ kartony), inaczej cena standardowa
        $sales = $this->wdtSales->sales($startDate, $endDate, ['product_id' => $productId])[0] ?? [];
        $soldCartons = (int)($sales['cartons'] ?? 0);
        if ($soldCartons <= 0) {
            $warnings[] = 'Brak danych sprzedaży dla wybranego okresu.';
        }
        $saleCurrency = 'EUR';
        $salePriceCarton = $soldCartons > 0 ? (float)$sales['value_eur'] / $soldCartons : 0.0;
        if ($salePriceCarton <= 0) {
            $standardPrice = (float)($product['standard_price'] ?? 0);
            $warnings[] = $standardPrice > 0 ? 'Brak ceny z dokumentów WDT – użyto ceny standardowej.' : 'Brak ceny sprzedaży.';
            $salePriceCarton = $standardPrice;
        }
        $salePriceCartonPln = $exchangeRateEuro > 0 ? $salePriceCarton * $exchangeRateEuro : 0.0;
        if ($salePriceCartonPln <= 0) {
            $warnings[] = 'Cena sprzedaży (PLN) jest równa 0 lub brak przeliczenia waluty.';
        }

        $unitFullCost = $unit['total'];

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
            'warnings' => array_values(array_unique($warnings)),
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
                'section_cost_pool' => (float)($section['own'] ?? 0),
                'employee_cost' => (float)($section['labor'] ?? 0),
                'media_cost' => (float)($section['media'] ?? 0),
                'depreciation' => (float)($section['depreciation'] ?? 0),
                'section_cartons' => (int)($section['cartons'] ?? 0),
                'section_cartons_source' => $section['cartons_source'] ?? 'none',
                'section_cost_per_carton' => $sectionCostUnit,
                'overhead_per_carton' => $unit['overhead'],
                'logistics_per_carton' => $unit['logistics'],
                'company_cartons' => $basis['cartons'],
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
        // Jedno źródło : koszt surowców karty produktu (= koszt receptury)
        $recipeCost = (float)($product['pre_calculated_raw_price_per_carton'] ?? 0);
        if ($recipeCost > 0) {
            return $recipeCost;
        }

        $warnings[] = 'Brak kosztu surowców produktu (receptura bez składników albo produkt bez receptury).';
        return 0.0;
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

}
