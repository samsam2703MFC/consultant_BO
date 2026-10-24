<?php
namespace App\Services\Production\Cost;

use App\Exceptions\CustomException;
use App\Repositories\Employee\Section\ProductionSectionRepository;
use App\Repositories\Production\Cost\CostMonthRepository;
use App\Repositories\Production\Cost\CostMonthEmployeeRepository;
use App\Repositories\Production\Cost\CostMonthFixedCostRepository;
use App\Repositories\Production\Cost\CostMonthSectionRepository;
use App\Repositories\Production\Cost\FinalProductRepository;
use App\Repositories\Production\Cost\CostMediaTypeRepository;
use App\Repositories\Production\Cost\CostMediaMonthRepository;
use App\Repositories\Production\Cost\CostMediaSectionAllocationRepository;
use App\Repositories\Production\Cost\CostLogisticsTypeRepository;
use App\Repositories\Production\Cost\CostMonthLogisticsRepository;

class CostCalculatorService
{
    public function __construct(
        private CostMonthRepository $monthRepo,
        private CostMonthSectionRepository $monthSectionRepo,
        private CostMonthEmployeeRepository $costMonthEmployeeRepo,
        private CostMonthFixedCostRepository $monthFixedRepo,
        private ProductionSectionRepository $sectionRepo,
        private FinalProductRepository $productRepo,
        private CostMediaTypeRepository $mediaTypeRepo,
        private CostMediaMonthRepository $mediaMonthRepo,
        private CostMediaSectionAllocationRepository $mediaAllocationRepo,
        private CostLogisticsTypeRepository $logisticsTypeRepo,
        private CostMonthLogisticsRepository $logisticsRepo,
        private SectionUnitCostService $unitCosts,
    ) {}

    public function getMonthBundle(int $monthId): array
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) throw new CustomException("MONTH_NOT_FOUND", "error", 404);

        $sections = $this->sectionRepo->findAll();
        $monthSections = $this->monthSectionRepo->getByMonthId($monthId);
        $employees = $this->costMonthEmployeeRepo->getIncludedByMonth($monthId);
        $fixed = $this->monthFixedRepo->getByMonthId($monthId);
        $mediaTypes = $this->mediaTypeRepo->getActive();
        $mediaCosts = $this->mediaMonthRepo->getByMonthId($monthId);
        $mediaAllocations = $this->mediaAllocationRepo->getByMonthId($monthId);
        $logisticsTypes = $this->logisticsTypeRepo->getActive();
        $logisticsCosts = $this->logisticsRepo->getByMonthId($monthId);

        return [
            'month' => $m,
            'sections' => $sections,
            'month_sections' => $monthSections,
            'employees' => $employees,
            'fixed_costs' => $fixed,
            'media_types' => $mediaTypes,
            'media_costs' => $mediaCosts,
            'media_allocations' => $mediaAllocations,
            'logistics_types' => $logisticsTypes,
            'logistics_costs' => $logisticsCosts,
        ];
    }

    /**
     * AUTO cartons from orders in given month.
     * Returns rows: [section_id, cartons_qty]
     */
    public function getAutoCartonsFromOrders(int $year, int $month): array
    {
        // ...existing code...
        $start = sprintf('%04d-%02d-01', $year, $month);
        $nm = $month + 1;
        $ny = $year;
        if ($nm === 13) { $nm = 1; $ny++; }
        $end = sprintf('%04d-%02d-01', $ny, $nm);

        $sql = "
            SELECT
                fp.id_section AS section_id,
                SUM(cop.quantity_carton) AS cartons_qty
            FROM customer_order co
            INNER JOIN customer_order_product cop ON co.id = cop.id_order
            INNER JOIN final_product fp ON fp.id = cop.id_product
            WHERE co.expected_shipping_date >= :start
              AND co.expected_shipping_date < :end
            GROUP BY fp.id_section
            ORDER BY fp.id_section ASC
        ";

        $res = $this->productRepo->query($sql, ['start'=>$start,'end'=>$end]);
        return $res ?: [];
    }

    /**
     * Zwraca podgląd kartonów per dział z faktur WDT w podanym zakresie dat.
     * Nie zapisuje do bazy – służy do wyświetlenia przed zatwierdzeniem.
     *
     * @return array [['section_id', 'section_name', 'cartons_qty'], ...]
     */
    public function previewWdtCartons(string $dateFrom, string $dateTo): array
    {
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo   = $this->normalizeDate($dateTo);
        return $this->monthSectionRepo->getWdtCartonsBySection($dateFrom, $dateTo);
    }

    /**
     * Zapisuje kartony z WDT do cost_month_section (source = 'WDT').
     * Nie nadpisuje wpisów ze źródłem MANUAL.
     *
     * @return array ['saved' => int, 'skipped_manual' => int, 'rows' => array]
     */
    public function syncCartonsFromWdt(int $monthId, string $dateFrom, string $dateTo): array
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) {
            return ['error' => 'MONTH_NOT_FOUND'];
        }
        if (($m['status'] ?? 'OPEN') === 'LOCKED') {
            return ['error' => 'MONTH_LOCKED'];
        }

        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo   = $this->normalizeDate($dateTo);

        $wdtRows = $this->monthSectionRepo->getWdtCartonsBySection($dateFrom, $dateTo);
        if (empty($wdtRows)) {
            return ['saved' => 0, 'skipped_manual' => 0, 'rows' => []];
        }

        // Pobierz istniejące wpisy dla tego miesiąca
        $existing = $this->monthSectionRepo->getByMonthId($monthId);
        $manualSections = [];
        foreach ($existing as $row) {
            if (($row['source'] ?? '') === 'MANUAL') {
                $manualSections[(int)$row['section_id']] = true;
            }
        }

        $saved   = 0;
        $skipped = 0;
        $savedRows = [];

        // Zbierz sekcje, które mają dane z WDT
        $wdtSectionIds = [];
        foreach ($wdtRows as $row) {
            $sectionId = (int)$row['section_id'];
            $qty       = (int)$row['cartons_qty'];

            if (isset($manualSections[$sectionId])) {
                $skipped++;
                continue;
            }

            $this->monthSectionRepo->upsert($monthId, $sectionId, $qty, 'WDT');
            $saved++;
            $wdtSectionIds[$sectionId] = true;
            $savedRows[] = [
                'section_id'   => $sectionId,
                'section_name' => $row['section_name'] ?? '',
                'cartons_qty'  => $qty,
            ];
        }

        // Upewnij się, że wszystkie działy mają wpis w cost_month_section (nawet z qty=0).
        // Dzięki temu calculateResults uwzględni wynagrodzenia działów pomocniczych
        // i zredystrybuuje je globalnie zamiast ich tracić.
        $allSections = $this->sectionRepo->findAll();
        foreach ($allSections as $s) {
            $sectionId = (int)$s['id'];
            if (isset($wdtSectionIds[$sectionId]) || isset($manualSections[$sectionId])) {
                continue; // już zapisano z WDT lub jest MANUAL
            }
            // Sprawdź czy wpis już istnieje
            $existing = $this->monthSectionRepo->getOne($monthId, $sectionId);
            if ($existing === null) {
                $this->monthSectionRepo->upsert($monthId, $sectionId, 0, 'AUTO');
            }
        }

        return [
            'saved'           => $saved,
            'skipped_manual'  => $skipped,
            'rows'            => $savedRows,
        ];
    }

    /**
     * Normalize date string to YYYY-MM-DD.
     */
    private function normalizeDate(string $date): string
    {
        $d = trim($date);
        // Accept YYYY-MM and expand to first/last day based on context — caller should pass full date
        if (preg_match('/^\d{4}-\d{2}$/', $d)) {
            $d .= '-01';
        }
        return $d;
    }

    /**
     * Wyniki “w locie” : koszt kartonu każdego produktu wg wspólnej reguły kosztu działu (SectionUnitCostService) —
     * te same liczby co Marże, Koszty produktu i działów oraz Rentowność.
     */
    public function calculateResults(int $monthId): array
    {
        $m = $this->monthRepo->getById($monthId);
        if (!$m) throw new CustomException("MONTH_NOT_FOUND", "error", 404);
        $basis = $this->unitCosts->month($monthId);

        $rows = [];
        foreach ($this->productRepo->getActiveForCosting() as $p) {
            $ppc = max(1, (int)$p['ppc']);
            $sid = $p['id_section'] !== null ? (int)$p['id_section'] : null;
            $cost = SectionUnitCostService::unitCost($basis, $sid, (float)($p['pre_calculated_raw_price_per_carton'] ?? 0.0));
            $rows[] = [
                'product_id' => (int)$p['id'],
                'product_name' => (string)$p['name'],
                'section_id' => $sid,
                'section_name' => $p['section_name'],
                'ppc' => $ppc,
                'raw_per_carton' => $cost['raw'],
                'labor_per_carton' => $cost['labor'],
                'media_per_carton' => $cost['media'],
                'depreciation_per_carton' => $cost['depreciation'],
                'fixed_per_carton' => $cost['overhead'],
                'logistics_per_carton' => $cost['logistics'],
                'total_per_carton' => $cost['total'],
                'total_per_piece' => $cost['total'] / $ppc,
                'standard_price' => (float)($p['standard_price'] ?? 0.0),
            ];
        }

        return [
            'month' => $m,
            'summary' => [
                'cartons_total' => $basis['cartons'],
                'labor_total' => $basis['labor_total'],
                'media_total' => $basis['media_total'],
                'depreciation_total' => $basis['depreciation_total'],
                'shared_total' => $basis['shared_pool'],
                'fixed_total' => $basis['fixed'],
                'logistics_total' => $basis['logistics'],
                'fixed_per_carton_global' => $basis['overhead_per_carton'],
                'logistics_per_carton' => $basis['logistics_per_carton'],
            ],
            'warnings' => $basis['warnings'],
            'rows' => $rows
        ];
    }
}
