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
     * Wyniki “w locie” — etap 1.
     */
    public function calculateResults(int $monthId): array
    {
        $bundle = $this->getMonthBundle($monthId);
        $m = $bundle['month'];

        // map cartons per section
        $cartonsBySection = [];
        foreach (($bundle['month_sections'] ?? []) as $ms) {
            $cartonsBySection[(int)$ms['section_id']] = (int)$ms['cartons_qty'];
        }

        // labor totals per section
        $laborTotalBySection = [];
        $laborTotalAll = 0.0;
        foreach (($bundle['employees'] ?? []) as $e) {
            $sid = (int)$e['section_id'];
            $val = (float)$e['monthly_cost'];
            $laborTotalBySection[$sid] = ($laborTotalBySection[$sid] ?? 0.0) + $val;
            $laborTotalAll += $val;
        }

        // fixed total
        $fixedTotal = 0.0;
        foreach (($bundle['fixed_costs'] ?? []) as $fc) {
            $fixedTotal += (float)$fc['amount'];
        }

        // logistics total
        $logisticsTotal = 0.0;
        foreach (($bundle['logistics_costs'] ?? []) as $lc) {
            $logisticsTotal += (float)$lc['amount'];
        }

        // media costs allocation with normalization
        $mediaCostBySection = [];
        $mediaCosts = $bundle['media_costs'] ?? [];
        $mediaAllocations = $bundle['media_allocations'] ?? [];

        // Grupuj alokacje per medium
        $allocationsByMediaType = [];
        foreach ($mediaAllocations as $alloc) {
            $mtId = (int)$alloc['media_type_id'];
            if (!isset($allocationsByMediaType[$mtId])) {
                $allocationsByMediaType[$mtId] = [];
            }
            $allocationsByMediaType[$mtId][] = $alloc;
        }

        // Rozdziel koszty mediów na działy z normalizacją
        foreach ($mediaCosts as $mc) {
            $mtId = (int)$mc['media_type_id'];
            $amount = (float)$mc['amount'];
            $allocations = $allocationsByMediaType[$mtId] ?? [];

            if (empty($allocations)) {
                // Brak alokacji - można to zignorować lub dodać warning
                continue;
            }

            // Oblicz sumę wag
            $totalWeight = 0.0;
            foreach ($allocations as $alloc) {
                $totalWeight += (float)$alloc['allocation_percent'];
            }

            // Rozdziel proporcjonalnie (z normalizacją)
            if ($totalWeight > 0) {
                foreach ($allocations as $alloc) {
                    $sid = (int)$alloc['section_id'];
                    $weight = (float)$alloc['allocation_percent'];
                    $normalizedPercent = ($weight / $totalWeight); // 0.0 - 1.0
                    $allocatedAmount = $amount * $normalizedPercent;
                    $mediaCostBySection[$sid] = ($mediaCostBySection[$sid] ?? 0.0) + $allocatedAmount;
                }
            }
        }

        // cartons total
        $cartonsTotal = 0;
        foreach ($cartonsBySection as $qty) $cartonsTotal += (int)$qty;

        $fixedPerCartonGlobal = ($cartonsTotal > 0) ? ($fixedTotal / $cartonsTotal) : 0.0;

        // media costs per carton per section
        // Działy z 0 kartonami: ich koszt mediów trafia do puli redystrybucji globalnej
        $mediaPerCartonBySection = [];
        $mediaRedistributed = 0.0;
        foreach (($bundle['sections'] ?? []) as $s) {
            $sid    = (int)$s['id'];
            $cart   = (int)($cartonsBySection[$sid] ?? 0);
            $mediaCost = (float)($mediaCostBySection[$sid] ?? 0.0);
            if ($cart > 0) {
                $mediaPerCartonBySection[$sid] = $mediaCost / $cart;
            } else {
                // Brak kartonów w tym dziale — media nie mogą być podzielone per karton działu,
                // redystrybuujemy globalnie
                $mediaPerCartonBySection[$sid] = 0.0;
                if ($mediaCost > 0.0) {
                    $mediaRedistributed += $mediaCost;
                }
            }
        }
        // Dodaj zredystrybuowane koszty mediów do kosztu stałego per karton
        if ($mediaRedistributed > 0.0 && $cartonsTotal > 0) {
            $fixedPerCartonGlobal += $mediaRedistributed / $cartonsTotal;
        }

        // labor per carton per section
        // Działy z 0 kartonami: ich wynagrodzenia trafiają do puli redystrybucji globalnej
        $laborPerCartonBySection = [];
        $laborRedistributed = 0.0;
        $warnings = [];

        foreach (($bundle['sections'] ?? []) as $s) {
            $sid  = (int)$s['id'];
            $cart = (int)($cartonsBySection[$sid] ?? 0);
            $lab  = (float)($laborTotalBySection[$sid] ?? 0.0);

            if ($cart > 0) {
                $laborPerCartonBySection[$sid] = $lab / $cart;
            } else {
                $laborPerCartonBySection[$sid] = 0.0;
                if ($lab > 0.0) {
                    $warnings[] = "Dział \"{$s['name']}\" ma 0 kartonów, ale " . number_format($lab, 2) . " zł wynagrodzeń — koszty redystrybuowane globalnie.";
                    $laborRedistributed += $lab;
                }
            }
        }
        // Dodaj zredystrybuowane wynagrodzenia do kosztu stałego per karton
        if ($laborRedistributed > 0.0 && $cartonsTotal > 0) {
            $fixedPerCartonGlobal += $laborRedistributed / $cartonsTotal;
        }

        // products
        $products = $this->productRepo->getActiveForCosting();

        $rows = [];
        foreach ($products as $p) {
            $ppc = max(1, (int)$p['ppc']);
            $sid = (int)$p['id_section'];

            $rawPerCarton = (float)($p['pre_calculated_raw_price_per_carton'] ?? 0.0);
            $laborPerCarton = (float)($laborPerCartonBySection[$sid] ?? 0.0);
            $mediaPerCarton = (float)($mediaPerCartonBySection[$sid] ?? 0.0);
            $fixedPerCarton = (float)$fixedPerCartonGlobal;

            $totalPerCarton = $rawPerCarton + $laborPerCarton + $mediaPerCarton + $fixedPerCarton;
            $perPiece = $totalPerCarton / $ppc;

            $rows[] = [
                'product_id' => (int)$p['id'],
                'product_name' => (string)$p['name'],
                'section_id' => $sid,
                'section_name' => $p['section_name'],
                'ppc' => $ppc,
                'raw_per_carton' => $rawPerCarton,
                'labor_per_carton' => $laborPerCarton,
                'media_per_carton' => $mediaPerCarton,
                'fixed_per_carton' => $fixedPerCarton,
                'total_per_carton' => $totalPerCarton,
                'total_per_piece' => $perPiece,
                'standard_price' => (float)($p['standard_price'] ?? 0.0),
            ];
        }

        return [
            'month' => $m,
            'summary' => [
                'cartons_total' => $cartonsTotal,
                'labor_total' => $laborTotalAll,
                'media_total' => array_sum($mediaCostBySection),
                'fixed_total' => $fixedTotal,
                'logistics_total' => $logisticsTotal,
                'fixed_per_carton_global' => $fixedPerCartonGlobal,
            ],
            'warnings' => $warnings,
            'rows' => $rows
        ];
    }
}
