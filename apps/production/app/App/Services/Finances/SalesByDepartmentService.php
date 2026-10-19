<?php
namespace App\Services\Finances;
use App\Repositories\Analysis\SalesByDepartmentRepository;
class SalesByDepartmentService
{
    public function __construct(
        private SalesByDepartmentRepository $repo
    ) {
    }
    /**
     * Domyślny zakres dat: ostatnie 12 miesięcy.
     */
    public function getDefaultDateRange(): array
    {
        $range  = $this->repo->getAvailableDateRange();
        $maxDate = $range['date_max'] ?? date('Y-m');
        $maxTs  = strtotime($maxDate . '-01');
        $minTs  = strtotime('-11 months', $maxTs);
        return [
            'from' => date('Y-m', $minTs),
            'to'   => $maxDate,
        ];
    }
    /**
     * Wszystkie działy produkcyjne.
     */
    public function getDepartments(): array
    {
        return $this->repo->getDepartments();
    }
    /**
     * Dane sprzedaży pogrupowane po działach – gotowe do widoku.
     * Zwraca tablicę działów, każdy zawiera listę produktów.
     */
    public function getSalesByDepartment(string $dateFrom, string $dateTo, ?int $sectionId = null): array
    {
        $rows = $this->repo->getSalesByDepartment($dateFrom, $dateTo, $sectionId);
        $sections = [];
        foreach ($rows as $row) {
            $sid = $row['section_id'];
            if (!isset($sections[$sid])) {
                $sections[$sid] = [
                    'section_id'   => $sid,
                    'section_name' => $row['section_name'],
                    'products'     => [],
                    'total_quantity' => 0,
                    'total_value'    => 0,
                ];
            }
            $row['total_quantity'] = round((float)$row['total_quantity'], 2);
            $row['total_value']    = round((float)$row['total_value'],    2);
            $row['avg_quantity']   = round((float)$row['avg_quantity'],   2);
            $row['avg_value']      = round((float)$row['avg_value'],      2);
            $sections[$sid]['products'][]      = $row;
            $sections[$sid]['total_quantity'] += $row['total_quantity'];
            $sections[$sid]['total_value']    += $row['total_value'];
        }
        // Zaokrąglenie podsumowań działów
        foreach ($sections as &$s) {
            $s['total_quantity'] = round($s['total_quantity'], 2);
            $s['total_value']    = round($s['total_value'],    2);
        }
        return array_values($sections);
    }
    /**
     * KPI per dział.
     */
    public function getSectionSummaries(string $dateFrom, string $dateTo): array
    {
        $rows = $this->repo->getSectionSummaries($dateFrom, $dateTo);
        return array_map(function ($r) {
            $r['total_quantity'] = round((float)$r['total_quantity'], 2);
            $r['total_value']    = round((float)$r['total_value'],    2);
            return $r;
        }, $rows);
    }
    /**
     * Dane wykresu liniowego trendów miesięcznych per dział.
     * Zwraca: labels[], datasets[{label, data[]}]
     */
    public function getMonthlyChartData(string $dateFrom, string $dateTo, ?int $sectionId = null): array
    {
        $rows = $this->repo->getMonthlyTrendBySection($dateFrom, $dateTo, $sectionId);
        // Zbierz wszystkie unikalne okresy (oś X)
        $periodsSet = [];
        $sectionRows = [];
        foreach ($rows as $row) {
            $periodsSet[$row['period']] = true;
            $sectionRows[$row['section_id']]['name'] = $row['section_name'];
            $sectionRows[$row['section_id']]['data'][$row['period']] = (float)$row['total_quantity'];
        }
        ksort($periodsSet);
        $labels = array_keys($periodsSet);
        $datasets = [];
        $colors   = ['#3498db','#e74c3c','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#34495e'];
        $i        = 0;
        foreach ($sectionRows as $sid => $sec) {
            $data = [];
            foreach ($labels as $period) {
                $data[] = $sec['data'][$period] ?? 0;
            }
            $color = $colors[$i % count($colors)];
            $datasets[] = [
                'label'           => $sec['name'],
                'data'            => $data,
                'borderColor'     => $color,
                'backgroundColor' => $color . '33',
                'tension'         => 0.3,
                'fill'            => false,
                'pointRadius'     => 4,
            ];
            $i++;
        }
        return compact('labels', 'datasets');
    }
}
