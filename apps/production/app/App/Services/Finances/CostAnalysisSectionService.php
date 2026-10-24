<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\CostAnalysisRepository;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Services\Production\Cost\SectionUnitCostService;
use DateTime;

class CostAnalysisSectionService
{
    public function __construct(
        private CostAnalysisRepository $costAnalysisRepository,
        private SectionUnitCostService $unitCosts,
        private WdtSalesRepository $wdtSales
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

        // Wspólna reguła kosztu działu (te same liczby co Marże, Kalkulator, Koszty produktu i Rentowność)
        $basis = !empty($month) ? $this->unitCosts->month((int)$month['id']) : $this->unitCosts->months([]);
        foreach ($basis['warnings'] as $w) $warnings[] = $w;
        $section = $basis['sections'][$sectionId] ?? null;
        if ($section !== null && ($section['shared'] || $section['cartons'] <= 0) && $section['redistributed'] > 0) {
            $warnings[] = 'Dział wspólny albo bez kartonów — jego koszty (' . number_format($section['redistributed'], 2, ',', ' ') . ' zł) są w kosztach ogólnych wszystkich kartonów firmy.';
        }

        $sold = $this->wdtSales->sales($startDate, $endDate, ['section_id' => $sectionId], ['product']);
        if (empty($sold)) {
            $warnings[] = 'Brak sprzedaży w wybranym okresie.';
        }
        $cards = $this->costAnalysisRepository->getProductsByIds(array_map(fn($r) => (int)$r['product_id'], $sold));

        $rows = [];
        $revenuePlnTotal = 0.0;
        $costPlnTotal = 0.0;
        $cartonsTotal = 0;

        foreach ($sold as $r) {
            $card = $cards[(int)$r['product_id']] ?? ['product_name' => $r['product_name'], 'category_name' => null, 'standard_price' => 0, 'pre_calculated_raw_price_per_carton' => 0];
            $cartonsQty = (int)$r['cartons'];
            $rawCostCarton = $this->calculateRawCostPerCarton($card + ['product_name' => $r['product_name']], $warnings);
            $unit = SectionUnitCostService::unitCost($basis, $sectionId, $rawCostCarton);

            // Cena sprzedaży : średnia ważona z WDT (wartość ÷ kartony), inaczej cena standardowa
            $salePriceCarton = $cartonsQty > 0 ? (float)$r['value_eur'] / $cartonsQty : 0.0;
            if ($salePriceCarton <= 0) {
                $salePriceCarton = (float)($card['standard_price'] ?? 0);
                $warnings[] = $salePriceCarton > 0
                    ? 'Brak ceny z dokumentów WDT – użyto ceny standardowej (produkt: ' . $r['product_name'] . ').'
                    : 'Brak ceny sprzedaży dla produktu: ' . $r['product_name'];
            }
            $salePriceCartonPln = $exchangeRateEuro > 0 ? $salePriceCarton * $exchangeRateEuro : 0.0;

            $sectionCostCarton = $unit['labor'] + $unit['media'] + $unit['depreciation'];
            $sharedCostUnit = $unit['overhead'] + $unit['logistics'];
            $unitFullCost = $unit['total'];
            $marginAmount = $salePriceCartonPln - $unitFullCost;
            $marginPercent = $salePriceCartonPln > 0 ? ($marginAmount / $salePriceCartonPln) * 100 : 0;

            $revenuePlnTotal += $salePriceCartonPln * $cartonsQty;
            $costPlnTotal += $unitFullCost * $cartonsQty;
            $cartonsTotal += $cartonsQty;

            $rows[] = [
                'product_id' => (int)$r['product_id'],
                'product_name' => $r['product_name'],
                'category_name' => $card['category_name'] ?? '-',
                'cartons_qty' => $cartonsQty,
                'sale_price_unit' => $salePriceCarton,
                'sale_currency' => 'EUR',
                'sale_price_unit_pln' => $salePriceCartonPln,
                'raw_cost_unit' => $rawCostCarton,
                'section_cost_unit' => $sectionCostCarton,
                'shared_cost_unit' => $sharedCostUnit,
                'unit_full_cost' => $unitFullCost,
                'margin_amount' => $marginAmount,
                'margin_percent' => $marginPercent
            ];
        }
        usort($rows, fn($a, $b) => strcmp((string)$a['product_name'], (string)$b['product_name']));

        $marginTotal = $revenuePlnTotal - $costPlnTotal;
        $marginPercentTotal = $revenuePlnTotal > 0 ? ($marginTotal / $revenuePlnTotal) * 100 : 0;

        return [
            'section' => $sectionCosts,
            'month' => $month,
            'date_range' => $dateRange,
            'warnings' => array_values(array_unique($warnings)),
            'summary' => [
                'revenue_pln' => $revenuePlnTotal,
                'cost_pln' => $costPlnTotal,
                'margin_pln' => $marginTotal,
                'margin_percent' => $marginPercentTotal,
                'units_total' => $cartonsTotal
            ],
            'explanation' => [
                'section_cost_pool' => (float)($section['own'] ?? 0),
                'employee_cost' => (float)($section['labor'] ?? 0),
                'fixed_costs' => (float)($section['depreciation'] ?? 0),
                'media_costs' => (float)($section['media'] ?? 0),
                'section_cartons' => (int)($section['cartons'] ?? 0),
                'section_cartons_source' => $section['cartons_source'] ?? 'none',
                'section_cost_per_carton' => (float)($section['own_per_carton'] ?? 0),
                'overhead_per_carton' => $basis['overhead_per_carton'],
                'logistics_per_carton' => $basis['logistics_per_carton'],
                'shared_cost_unit' => $basis['overhead_per_carton'] + $basis['logistics_per_carton'],
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
        // Jedno źródło : koszt surowców karty produktu (= koszt receptury)
        $recipeCost = (float)($product['pre_calculated_raw_price_per_carton'] ?? 0);
        if ($recipeCost > 0) {
            return $recipeCost;
        }

        $warnings[] = 'Brak kosztu surowców produktu: ' . ($product['product_name'] ?? '');
        return 0.0;
    }

}
