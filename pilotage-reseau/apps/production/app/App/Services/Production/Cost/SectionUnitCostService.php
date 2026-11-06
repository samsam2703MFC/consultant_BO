<?php
declare(strict_types=1);

namespace App\Services\Production\Cost;

use App\Repositories\Production\Cost\SectionUnitCostRepository;

/**
 * Jedna reguła kosztu jednostkowego działu dla Marż, Kosztów produktu i działów, Kalkulatora kosztów i Rentowności
 * (koszt pełny : każdy złoty kosztu miesiąca przypisany raz).
 *
 *  - Dział produkcyjny : wynagrodzenia (uwzględnione) + media + amortyzacja działu ÷ kartony działu.
 *  - Media : koszty miesiąca rozdzielone wg procentów działów, sprowadzonych do 100 % na każde medium ;
 *    medium bez rozdziału → koszty ogólne ; miesiąc bez kosztów mediów → media stałe z karty działu.
 *  - Działy wspólne (Pakowanie, Administracja…) i działy bez kartonów : ich koszty → koszty ogólne.
 *  - Koszty ogólne (koszty stałe firmy + działy wspólne + media bez rozdziału) i logistyka ÷ wszystkie kartony firmy.
 *  - Kartony : wpisane w kalkulatorze kosztów (> 0), inaczej sprzedane w miesiącu (WDT, bez dokumentów zastąpionych).
 *  - Kilka miesięcy : sumy kosztów ÷ sumy kartonów.
 */
final class SectionUnitCostService
{
    /** @var array<string, array> */
    private array $cache = [];

    public function __construct(private SectionUnitCostRepository $repo)
    {
    }

    /** Koszty jednego miesiąca kosztowego (cost_month.id). */
    public function month(int $monthId): array
    {
        return $this->months([$monthId]);
    }

    /**
     * @return array{month_ids: list<int>, sections: array<int, array>, fixed: float, logistics: float, labor_total: float,
     *   media_total: float, depreciation_total: float, shared_pool: float,
     *   unallocated_media: float, cartons: int, fixed_per_carton: float, shared_per_carton: float, overhead_per_carton: float,
     *   logistics_per_carton: float, warnings: list<string>}
     */
    public function months(array $monthIds): array
    {
        $monthIds = array_values(array_unique(array_map('intval', $monthIds)));
        sort($monthIds);
        $key = implode(',', $monthIds);
        if (isset($this->cache[$key])) return $this->cache[$key];

        $months = $this->repo->months($monthIds);
        $monthIds = array_keys($months);
        $sectionRows = $this->repo->sections();
        $labor = $this->repo->labor($monthIds);
        $entered = $this->repo->enteredCartons($monthIds);
        $sold = $this->repo->soldCartons($months);
        $fixed = $this->repo->fixed($monthIds);
        $logistics = $this->repo->logistics($monthIds);
        [$media, $unallocated, $staticMonths] = $this->media($monthIds, $sectionRows);

        $warnings = [];
        $sections = [];
        $rowsById = array_column($sectionRows, null, 'id');
        foreach ($sectionRows as $s) {
            $sections[(int) $s['id']] = ['id' => (int) $s['id'], 'name' => $s['name'], 'shared' => (bool) (int) $s['is_shared'],
                'labor' => 0.0, 'media' => 0.0, 'depreciation' => 0.0, 'cartons' => 0, 'cartons_entered' => 0, 'cartons_sold' => 0,
                'labor_actual' => 0.0, 'media_actual' => 0.0, 'depreciation_actual' => 0.0];
        }
        $sharedPool = array_sum($unallocated);
        $companyCartons = 0;
        $totals = ['labor' => 0.0, 'media' => array_sum($unallocated), 'depreciation' => 0.0];
        foreach ($monthIds as $m) {
            foreach ($sections as $id => &$s) {
                $row = $rowsById[$id];
                $cartonsEntered = (int) ($entered[$m][$id] ?? 0);
                $cartonsSold = (int) ($sold[$m][$id] ?? 0);
                $cartons = $cartonsEntered > 0 ? $cartonsEntered : $cartonsSold;
                $own = ['labor' => (float) ($labor[$m][$id] ?? 0), 'media' => (float) ($media[$m][$id] ?? 0), 'depreciation' => (float) $row['depreciation']];
                foreach ($own as $k => $v) {
                    $totals[$k] += $v;
                    $s[$k . '_actual'] += $v; // koszt działu w miesiącu, przed rozłożeniem działów wspólnych
                }
                if ($s['shared'] || $cartons <= 0) {
                    // Dział wspólny albo bez kartonów w miesiącu : koszt rozkładany na cały wolumen firmy
                    $sharedPool += array_sum($own);
                    if (!$s['shared'] && array_sum($own) > 0) {
                        $warnings[] = "Dział „{$s['name']}” bez kartonów w miesiącu — jego koszty rozłożone na wszystkie kartony firmy.";
                    }
                    $s['redistributed'] = ($s['redistributed'] ?? 0) + array_sum($own);
                    continue;
                }
                foreach ($own as $k => $v) $s[$k] += $v;
                $s['cartons'] += $cartons;
                $s['cartons_entered'] += $cartonsEntered;
                $s['cartons_sold'] += $cartonsSold;
                $companyCartons += $cartons;
            }
            unset($s);
        }
        foreach ($sections as &$s) {
            $s['redistributed'] = (float) ($s['redistributed'] ?? 0);
            $s['own'] = $s['labor'] + $s['media'] + $s['depreciation'];
            foreach (['labor', 'media', 'depreciation', 'own'] as $k) $s[$k . '_per_carton'] = $s['cartons'] > 0 ? $s[$k] / $s['cartons'] : 0.0;
            $s['cartons_source'] = $s['cartons_entered'] > 0 ? 'entered' : ($s['cartons'] > 0 ? 'sold' : 'none');
        }
        unset($s);

        $fixedTotal = array_sum(array_intersect_key($fixed, array_flip($monthIds)));
        $logisticsTotal = array_sum(array_intersect_key($logistics, array_flip($monthIds)));
        $perCompany = fn(float $v) => $companyCartons > 0 ? $v / $companyCartons : 0.0;
        if ($staticMonths) $warnings[] = 'Brak kosztów mediów w miesiącu — przyjęto media stałe z kart działów.';
        if ($unallocated && array_sum($unallocated) > 0) $warnings[] = 'Media bez rozdziału na działy — doliczone do kosztów ogólnych.';
        if ($monthIds && $companyCartons === 0) $warnings[] = 'Brak kartonów w miesiącu — koszt na karton nieobliczony.';

        return $this->cache[$key] = [
            'month_ids' => $monthIds,
            'sections' => $sections,
            'fixed' => $fixedTotal,
            'logistics' => $logisticsTotal,
            'labor_total' => $totals['labor'],
            'media_total' => $totals['media'],
            'depreciation_total' => $totals['depreciation'],
            'shared_pool' => $sharedPool,
            'unallocated_media' => array_sum($unallocated),
            'cartons' => $companyCartons,
            'fixed_per_carton' => $perCompany($fixedTotal),
            'shared_per_carton' => $perCompany($sharedPool),
            'overhead_per_carton' => $perCompany($fixedTotal + $sharedPool),
            'logistics_per_carton' => $perCompany($logisticsTotal),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Koszt kartonu produktu : surowce + dział (wynagrodzenia, media, amortyzacja) + koszty ogólne + logistyka.
     * @return array{raw: float, labor: float, media: float, depreciation: float, overhead: float, logistics: float, total: float}
     */
    public static function unitCost(array $basis, ?int $sectionId, float $raw): array
    {
        $s = $sectionId !== null ? ($basis['sections'][$sectionId] ?? null) : null;
        $out = [
            'raw' => $raw,
            'labor' => (float) ($s['labor_per_carton'] ?? 0),
            'media' => (float) ($s['media_per_carton'] ?? 0),
            'depreciation' => (float) ($s['depreciation_per_carton'] ?? 0),
            'overhead' => (float) $basis['overhead_per_carton'],
            'logistics' => (float) $basis['logistics_per_carton'],
        ];
        return $out + ['total' => array_sum($out)];
    }

    /**
     * Media miesięcy : [miesiąc => dział => koszt], [miesiąc => koszt bez rozdziału], miesiące z mediami stałymi.
     */
    private function media(array $monthIds, array $sectionRows): array
    {
        $amounts = [];
        foreach ($this->repo->mediaAmounts($monthIds) as $r) $amounts[(int) $r['month_id']][(int) $r['media_type_id']] = (float) $r['amount'];
        $alloc = [];
        foreach ($this->repo->mediaAllocations($monthIds) as $r) $alloc[(int) $r['month_id']][(int) $r['media_type_id']][(int) $r['section_id']] = (float) $r['pct'];

        $media = [];
        $unallocated = [];
        $static = [];
        foreach ($monthIds as $m) {
            if (empty($amounts[$m])) {
                foreach ($sectionRows as $s) $media[$m][(int) $s['id']] = (float) $s['static_media'];
                $static[] = $m;
                continue;
            }
            foreach ($amounts[$m] as $type => $amount) {
                $weights = array_filter($alloc[$m][$type] ?? [], fn($p) => $p > 0);
                $total = array_sum($weights);
                if ($total <= 0) {
                    $unallocated[$m] = ($unallocated[$m] ?? 0.0) + $amount;
                    continue;
                }
                foreach ($weights as $sid => $pct) $media[$m][$sid] = ($media[$m][$sid] ?? 0.0) + $amount * $pct / $total;
            }
        }
        return [$media, $unallocated, $static];
    }
}
