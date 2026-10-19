<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\SectionFinanceDashboardRepository;

class SectionFinanceDashboardService
{
    public function __construct(
        private SectionFinanceDashboardRepository $repo
    ) {
    }

    /**
     * Zwraca domyślny zakres dat: ostatnie 12 miesięcy.
     */
    public function getDefaultDateRange(): array
    {
        $range  = $this->repo->getAvailableDateRange();
        $maxRaw = $range['date_max'] ?? date('Y-m');

        $maxTs = strtotime($maxRaw . '-01');
        $minTs = strtotime('-11 months', $maxTs);

        return [
            'from' => date('Y-m', $minTs),
            'to'   => $maxRaw,
        ];
    }

    /**
     * Główna metoda: buduje pełne zestawienie działów + KPI globalne.
     *
     * Zwraca:
     *   sections[]
     *     - section_id, section_name
     *     - revenue_gross          (przychód brutto z WDT)
     *     - labor_total            (suma wynagrodzeń z cost_month_employee)
     *     - revenue_share_pct      (% udziału przychodu działu w łącznym przychodzie firmy)
     *     - labor_share_total_pct  (% udziału wynagrodzeń działu w łącznych wynagrodzeniach)
     *     - labor_to_revenue_pct   (wynagrodzenia / przychód działu × 100)
     *   totals
     *     - total_revenue
     *     - total_labor
     *     - labor_to_revenue_pct   (wynagrodzenia / przychód całej firmy × 100)
     */
    public function getDashboard(string $dateFrom, string $dateTo): array
    {
        $revenueRows  = $this->repo->getRevenueBySection($dateFrom, $dateTo);
        $laborRows    = $this->repo->getLaborCostBySection($dateFrom, $dateTo);
        $totalRevenue = $this->repo->getTotalRevenue($dateFrom, $dateTo);
        $totalLabor   = $this->repo->getTotalLaborCost($dateFrom, $dateTo);
        $eurRate      = $this->repo->getExchangeRateEuro();

        // Indeksujemy labour po section_id
        $laborMap = [];
        foreach ($laborRows as $row) {
            $laborMap[(int)$row['section_id']] = (float)$row['labor_total'];
        }

        $totalLaborAllSections = array_sum($laborMap) ?: 0.0;

        $sections = [];
        foreach ($revenueRows as $row) {
            $sid     = (int)$row['section_id'];
            $revenue = (float)$row['revenue_gross'];
            $labor   = $laborMap[$sid] ?? 0.0;
            $cartons = (int)$row['total_cartons'];

            $revenueSharePct   = $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 2) : 0.0;
            $laborSharePct     = $totalLaborAllSections > 0 ? round($labor / $totalLaborAllSections * 100, 2) : 0.0;
            $laborToRevenuePct = $revenue > 0 ? round($labor / ($revenue * ($eurRate ?: 1)) * 100, 2) : 0.0;

            $sections[] = [
                'section_id'              => $sid,
                'section_name'            => $row['section_name'],
                'revenue_gross'           => round($revenue, 2),
                'revenue_gross_pln'       => $eurRate > 0 ? round($revenue * $eurRate, 2) : null,
                'total_cartons'           => $cartons,
                'labor_total'             => round($labor, 2),
                'revenue_share_pct'       => $revenueSharePct,
                'labor_share_total_pct'   => $laborSharePct,
                'labor_to_revenue_pct'    => $laborToRevenuePct,
            ];
        }

        usort($sections, fn($a, $b) => $b['revenue_gross'] <=> $a['revenue_gross']);

        $globalLaborToRevenue = ($totalRevenue > 0 && $eurRate > 0)
            ? round($totalLabor / ($totalRevenue * $eurRate) * 100, 2)
            : 0.0;

        $totals = [
            'total_revenue'          => round($totalRevenue, 2),
            'total_revenue_pln'      => $eurRate > 0 ? round($totalRevenue * $eurRate, 2) : null,
            'total_labor'            => round($totalLabor, 2),
            'labor_to_revenue_pct'   => $globalLaborToRevenue,
        ];

        return [
            'sections'      => $sections,
            'totals'        => $totals,
            'exchange_rate' => $eurRate,
        ];
    }

    /**
     * Miesięczny trend: przychód + wynagrodzenia (całość firmy).
     */
    public function getMonthlyTrend(string $dateFrom, string $dateTo): array
    {
        $rows    = $this->repo->getMonthlyTrend($dateFrom, $dateTo);
        $eurRate = $this->repo->getExchangeRateEuro();

        $labels      = [];
        $revenues    = [];
        $revenuesPln = [];
        $labors      = [];

        foreach ($rows as $row) {
            $rev         = round((float)$row['revenue'], 2);
            $labels[]    = $row['period'];
            $revenues[]  = $rev;
            $revenuesPln[] = $eurRate > 0 ? round($rev * $eurRate, 2) : null;
            $labors[]    = round((float)$row['labor_cost'], 2);
        }

        return [
            'labels'       => $labels,
            'revenues'     => $revenues,
            'revenues_pln' => $revenuesPln,
            'labors'       => $labors,
            'eur_rate'     => $eurRate,
        ];
    }
}

