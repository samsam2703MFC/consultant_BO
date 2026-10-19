<?php
namespace App\Services\Dashboard;

use App\Exceptions\CustomException;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ParamRepository;
use DateTimeImmutable;

/**
 * Rentabilité de la production, page d'accueil de l'ERP.
 *
 * Par groupe de produits (dział produkcyjny) : ventes WDT, coût du personnel, ratio personnel /
 * ventes face à l'objectif, CA manquant pour l'atteindre (en € et en % des ventes) et marge après
 * matières, personnel et coûts du groupe. Pour l'entreprise : même ratio avec les services
 * communs, et résultat de production après tous les coûts, avec le CA qu'il faut pour l'objectif.
 *
 * Mois en cours : le ratio compare les ventes au coût du personnel au prorata des jours écoulés ;
 * le CA nécessaire porte sur le mois entier, donc « brakuje » = ce qu'il reste à vendre d'ici la
 * fin du mois. Un mois sans coûts saisis reprend le dernier mois de coûts connu (signalé).
 */
final class ProfitabilityService
{
    private ?array $targets = null;
    private ?array $costs = null;

    public function __construct(private ProfitabilityRepository $repo, private ParamRepository $params)
    {
    }

    /** Choix de période proposés : mois en cours, 11 mois précédents, 3 derniers mois, année. */
    public function periodOptions(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $options = ['month' => 'Bieżący miesiąc (' . self::monthLabel($today->format('Y-m')) . ')'];
        for ($i = 1; $i <= 11; $i++) {
            $ym = $today->modify('first day of this month')->modify("-{$i} months")->format('Y-m');
            $options[$ym] = self::monthLabel($ym);
        }
        $options['3m'] = 'Ostatnie 3 pełne miesiące';
        $options['ytd'] = 'Od początku roku ' . $today->format('Y');
        return $options;
    }

    public function overview(string $period = 'month', ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $spec = $this->period($period, $today);
        $rate = $this->repo->eurRate();
        $sales = $this->repo->sales($spec['from'], $spec['to'], $rate);
        $open = $spec['current'] ? $this->repo->openOrders($today->format('Y-m-d'), $spec['end']) : [];

        $data = $this->compute($spec, $sales, $open, $rate);
        $data['trend'] = $this->trend($today, $rate);
        $data['options'] = $this->periodOptions($today);
        $data['last_sale'] = $this->repo->lastSaleDate();
        return $data;
    }

    /** Ratio personnel / ventes et résultat des 6 derniers mois (mois en cours compris). */
    public function trend(DateTimeImmutable $today, float $rate): array
    {
        $first = $today->modify('first day of this month')->modify('-5 months');
        $sales = $this->repo->sales($first->format('Y-m-d'), $today->format('Y-m-d'), $rate);
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $ym = $first->modify("+{$i} months")->format('Y-m');
            $spec = $this->period($ym === $today->format('Y-m') ? 'month' : $ym, $today);
            $rows = array_values(array_filter($sales, fn($r) => $r['ym'] === $ym));
            $m = $this->compute($spec, $rows, [], $rate);
            $out[] = [
                'ym' => $ym,
                'label' => self::monthLabel($ym, true),
                'current' => $spec['current'],
                'revenue' => $m['totals']['revenue'],
                'staff_pct' => $m['totals']['staff_pct'],
                'margin_pct' => $m['totals']['margin_pct'],
                'estimated' => $m['estimated_months'] !== [],
            ];
        }
        return $out;
    }

    /** Objectifs d'un groupe ; une valeur vide revient au paramètre général. */
    public function saveTarget(int $sectionId, array $input, ?int $userId): void
    {
        if (!$this->repo->sectionExists($sectionId)) {
            throw new CustomException('Nie znaleziono działu.', 'errors', 404);
        }
        $data = ['updated_by' => $userId];
        foreach (['staff_pct_target' => [0, 100], 'margin_pct_target' => [-100, 100]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $input)) continue;
            $raw = trim(str_replace(',', '.', (string) $input[$field]));
            if ($raw === '') { $data[$field] = null; continue; }
            if (!is_numeric($raw) || (float) $raw <= $min || (float) $raw >= $max) {
                throw new CustomException($field === 'staff_pct_target' ? 'Cel kosztu personelu: od 0 do 100 %.' : 'Cel marży: od -100 do 100 %.', 'errors', 422);
            }
            $data[$field] = round((float) $raw, 2);
        }
        if (array_key_exists('is_shared', $input)) $data['is_shared'] = (int) (bool) $input['is_shared'];
        $this->repo->saveTarget($sectionId, $data);
    }

    /** Objectifs généraux (paramètres). */
    public function saveGeneralTargets(array $input, ?int $userId): void
    {
        $limits = [
            'profit.staff_target_pct' => [0, 100],
            'profit.total_staff_target_pct' => [0, 100],
            'profit.section_margin_target_pct' => [-100, 100],
            'profit.margin_target_pct' => [-100, 100],
        ];
        $values = [];
        foreach ($limits as $code => [$min, $max]) {
            if (!array_key_exists($code, $input)) continue;
            $raw = trim(str_replace(',', '.', (string) $input[$code]));
            if (!is_numeric($raw) || (float) $raw <= $min || (float) $raw >= $max) {
                throw new CustomException('Nieprawidłowa wartość celu (' . $code . ').', 'errors', 422);
            }
            $values[$code] = (string) round((float) $raw, 2);
        }
        foreach ($values as $code => $value) {
            $this->params->setValue($code, $value, $userId);
        }
        $this->targets = null;
    }

    public function generalTargets(): array
    {
        return $this->targets ??= [
            'profit.staff_target_pct' => $this->param('profit.staff_target_pct', 25, true),
            'profit.total_staff_target_pct' => $this->param('profit.total_staff_target_pct', 35, true),
            'profit.section_margin_target_pct' => $this->param('profit.section_margin_target_pct', 30, false),
            'profit.margin_target_pct' => $this->param('profit.margin_target_pct', 10, false),
        ];
    }

    /**
     * @param array{months:array<string,float>, from:string, to:string, end:string, current:bool, label:string, elapsed:float} $spec
     */
    public function compute(array $spec, array $salesRows, array $openRows, float $rate): array
    {
        $warnings = [];
        if ($rate <= 0) {
            $warnings[] = 'Brak kursu EUR w ustawieniach firmy — koszty w PLN nie są przeliczone.';
            $rate = 1.0;
        }
        $this->costs ??= [$this->repo->costMonths(), $this->repo->staff(), $this->repo->media(), $this->repo->fixedCosts(), $this->repo->sections()];
        [$costMonths, $staff, $media, $fixed, $sectionRows] = $this->costs;
        $targets = $this->generalTargets();

        // Coûts du personnel et du groupe, au prorata (pace) et sur les mois entiers (full).
        $sections = [];
        foreach ($sectionRows as $s) {
            $sections[(int) $s['id']] = $s + ['staff' => 0.0, 'staff_full' => 0.0, 'overhead' => 0.0, 'overhead_full' => 0.0,
                'revenue' => 0.0, 'materials' => 0.0, 'cartons' => 0, 'open' => 0.0];
        }
        $fixedPace = 0.0;
        $fixedFull = 0.0;
        $estimated = [];
        foreach ($spec['months'] as $ym => $fraction) {
            [$monthId, $source] = $this->costMonthFor($ym, $costMonths);
            if ($monthId === null) {
                $warnings[] = 'Brak miesięcznych kosztów (personel, media, koszty stałe) — uzupełnij je w kalkulatorze kosztów.';
                continue;
            }
            if ($source !== $ym) $estimated[$ym] = $source;
            foreach ($sections as $id => &$s) {
                $staffCost = ($staff[$monthId][$id] ?? 0.0) / $rate;
                $mediaCost = $media[$monthId][$id] ?? 0.0;
                if ($mediaCost == 0.0) {
                    $mediaCost = (float) $s['water_cost'] + (float) $s['power_supply_cost'] + (float) $s['fuel_cost'] + (float) $s['other_cost'];
                }
                $overhead = ((float) $s['depreciation'] + $mediaCost) / $rate;
                $s['staff_full'] += $staffCost;
                $s['staff'] += $staffCost * $fraction;
                $s['overhead_full'] += $overhead;
                $s['overhead'] += $overhead * $fraction;
            }
            unset($s);
            $fixedFull += ($fixed[$monthId] ?? 0.0) / $rate;
            $fixedPace += ($fixed[$monthId] ?? 0.0) / $rate * $fraction;
        }
        if ($estimated) {
            $bySource = [];
            foreach ($estimated as $ym => $source) $bySource[$source][] = self::monthLabel($ym);
            $parts = [];
            foreach ($bySource as $source => $months) $parts[] = implode(', ', $months) . ' → przyjęto ' . self::monthLabel($source);
            $warnings[] = 'Brak wprowadzonych kosztów miesięcznych: ' . implode('; ', $parts) . '.';
        }

        $unassigned = ['revenue' => 0.0, 'materials' => 0.0, 'cartons' => 0];
        foreach ($salesRows as $r) {
            $id = (int) $r['id_section'];
            $target = isset($sections[$id]) ? 'section' : 'none';
            $revenue = (float) $r['revenue_eur'];
            $materials = (float) $r['materials_pln'] / $rate;
            if ($target === 'section') {
                $sections[$id]['revenue'] += $revenue;
                $sections[$id]['materials'] += $materials;
                $sections[$id]['cartons'] += (int) $r['cartons'];
            } else {
                $unassigned['revenue'] += $revenue;
                $unassigned['materials'] += $materials;
                $unassigned['cartons'] += (int) $r['cartons'];
            }
        }
        foreach ($openRows as $r) {
            if (isset($sections[(int) $r['id_section']])) $sections[(int) $r['id_section']]['open'] += (float) $r['value_eur'];
        }

        $groups = [];
        $shared = ['names' => [], 'staff' => 0.0, 'staff_full' => 0.0, 'overhead' => 0.0, 'overhead_full' => 0.0, 'revenue' => 0.0, 'materials' => 0.0];
        foreach ($sections as $id => $s) {
            if ($s['is_shared']) {
                $shared['names'][] = $s['name'];
                foreach (['staff', 'staff_full', 'overhead', 'overhead_full', 'revenue', 'materials'] as $k) $shared[$k] += $s[$k];
                continue;
            }
            if ($s['revenue'] == 0.0 && $s['staff_full'] == 0.0) continue;
            $groups[] = $this->group($id, $s, $targets);
        }
        usort($groups, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        $revenue = array_sum(array_column($sections, 'revenue')) + $unassigned['revenue'];
        $materials = array_sum(array_column($sections, 'materials')) + $unassigned['materials'];
        $staffPace = array_sum(array_column($sections, 'staff'));
        $staffFull = array_sum(array_column($sections, 'staff_full'));
        $overheadPace = array_sum(array_column($sections, 'overhead'));
        $overheadFull = array_sum(array_column($sections, 'overhead_full'));
        $open = array_sum(array_column($sections, 'open'));

        $staffTarget = $targets['profit.total_staff_target_pct'];
        $staffNeeded = $staffFull / ($staffTarget / 100);
        $staffMissing = max(0.0, $staffNeeded - $revenue);
        $result = $revenue - $materials - $staffPace - $overheadPace - $fixedPace;

        // CA pour l'objectif de résultat : R × (1 − matières/CA − objectif) = coûts fixes du mois.
        $marginTarget = $targets['profit.margin_target_pct'];
        $variableRatio = $revenue > 0 ? $materials / $revenue : null;
        $fixedCosts = $staffFull + $overheadFull + $fixedFull;
        $denominator = $variableRatio === null ? null : 1 - $variableRatio - $marginTarget / 100;
        $marginNeeded = $denominator !== null && $denominator > 0 ? $fixedCosts / $denominator : null;

        $totals = [
            'revenue' => $revenue,
            'materials' => $materials,
            'materials_pct' => $revenue > 0 ? $materials / $revenue * 100 : null,
            'staff' => $staffPace,
            'staff_full' => $staffFull,
            'staff_pct' => $revenue > 0 ? $staffPace / $revenue * 100 : null,
            'staff_target' => $staffTarget,
            'staff_needed' => $staffNeeded,
            'staff_missing' => $staffMissing,
            'staff_missing_pct' => $revenue > 0 ? $staffMissing / $revenue * 100 : null,
            'staff_ok' => $revenue > 0 && $staffMissing <= 0.005,
            'overhead' => $overheadPace,
            'fixed' => $fixedPace,
            'result' => $result,
            'margin_pct' => $revenue > 0 ? $result / $revenue * 100 : null,
            'margin_target' => $marginTarget,
            'margin_needed' => $marginNeeded,
            'margin_missing' => $marginNeeded === null ? null : max(0.0, $marginNeeded - $revenue),
            'margin_missing_pct' => $marginNeeded !== null && $revenue > 0 ? max(0.0, $marginNeeded - $revenue) / $revenue * 100 : null,
            'margin_reachable' => $revenue <= 0 || $marginNeeded !== null,
            'margin_ok' => $revenue > 0 && $marginNeeded !== null && $marginNeeded <= $revenue,
            'open' => $open,
            'projection' => $spec['current'] && $spec['elapsed'] > 0 ? $revenue / $spec['elapsed'] : null,
            'cartons' => array_sum(array_column($sections, 'cartons')) + $unassigned['cartons'],
        ];

        return [
            'period' => $spec,
            'rate' => $rate,
            'groups' => $groups,
            'shared' => $shared,
            'unassigned' => $unassigned,
            'totals' => $totals,
            'targets' => $targets,
            'warnings' => array_values(array_unique($warnings)),
            'estimated_months' => $estimated,
        ];
    }

    private function group(int $id, array $s, array $targets): array
    {
        $staffTarget = $s['staff_pct_target'] !== null ? (float) $s['staff_pct_target'] : $targets['profit.staff_target_pct'];
        $marginTarget = $s['margin_pct_target'] !== null ? (float) $s['margin_pct_target'] : $targets['profit.section_margin_target_pct'];
        $revenue = $s['revenue'];
        $needed = $s['staff_full'] / ($staffTarget / 100);
        $missing = max(0.0, $needed - $revenue);
        $contribution = $revenue - $s['materials'] - $s['staff'] - $s['overhead'];
        return [
            'id' => $id,
            'name' => $s['name'],
            'revenue' => $revenue,
            'cartons' => $s['cartons'],
            'materials' => $s['materials'],
            'staff' => $s['staff'],
            'staff_full' => $s['staff_full'],
            'staff_pct' => $revenue > 0 ? $s['staff'] / $revenue * 100 : null,
            'staff_target' => $staffTarget,
            'staff_target_custom' => $s['staff_pct_target'] !== null,
            'needed' => $needed,
            'missing' => $missing,
            'missing_pct' => $revenue > 0 ? $missing / $revenue * 100 : null,
            'missing_after_open' => max(0.0, $missing - $s['open']),
            'open' => $s['open'],
            'progress_pct' => $needed > 0 ? min(100.0, $revenue / $needed * 100) : 100.0,
            'ok' => $revenue > 0 && $missing <= 0.005,
            'overhead' => $s['overhead'],
            'contribution' => $contribution,
            'margin_pct' => $revenue > 0 ? $contribution / $revenue * 100 : null,
            'margin_target' => $marginTarget,
            'margin_target_custom' => $s['margin_pct_target'] !== null,
        ];
    }

    /**
     * Période → mois concernés avec la part écoulée de chacun.
     * @return array{key:string, months:array<string,float>, from:string, to:string, end:string, current:bool, label:string, elapsed:float}
     */
    public function period(string $period, DateTimeImmutable $today): array
    {
        $currentYm = $today->format('Y-m');
        $monthFraction = (int) $today->format('j') / (int) $today->format('t');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) && $period < $currentYm) {
            $first = new DateTimeImmutable($period . '-01');
            return ['key' => $period, 'months' => [$period => 1.0], 'from' => $first->format('Y-m-d'), 'to' => $first->format('Y-m-t'),
                'end' => $first->format('Y-m-t'), 'current' => false, 'label' => self::monthLabel($period), 'elapsed' => 1.0];
        }
        if ($period === '3m') {
            $first = $today->modify('first day of this month')->modify('-3 months');
            $months = [];
            for ($i = 0; $i < 3; $i++) $months[$first->modify("+{$i} months")->format('Y-m')] = 1.0;
            $last = $today->modify('first day of this month')->modify('-1 day');
            return ['key' => '3m', 'months' => $months, 'from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d'), 'end' => $last->format('Y-m-d'),
                'current' => false, 'label' => self::monthLabel(array_key_first($months)) . ' – ' . self::monthLabel(array_key_last($months)), 'elapsed' => 1.0];
        }
        if ($period === 'ytd') {
            $months = [];
            $daysTotal = 0;
            $daysElapsed = 0;
            for ($m = 1; $m <= (int) $today->format('n'); $m++) {
                $ym = sprintf('%s-%02d', $today->format('Y'), $m);
                $days = (int) (new DateTimeImmutable($ym . '-01'))->format('t');
                $months[$ym] = $ym === $currentYm ? $monthFraction : 1.0;
                $daysTotal += $days;
                $daysElapsed += $ym === $currentYm ? (int) $today->format('j') : $days;
            }
            return ['key' => 'ytd', 'months' => $months, 'from' => $today->format('Y-01-01'), 'to' => $today->format('Y-m-d'), 'end' => $today->format('Y-m-t'),
                'current' => true, 'label' => 'Od początku roku ' . $today->format('Y'), 'elapsed' => $daysElapsed / $daysTotal];
        }
        return ['key' => 'month', 'months' => [$currentYm => $monthFraction], 'from' => $today->format('Y-m-01'), 'to' => $today->format('Y-m-d'),
            'end' => $today->format('Y-m-t'), 'current' => true, 'label' => self::monthLabel($currentYm), 'elapsed' => $monthFraction];
    }

    /** Mois de coûts à utiliser : le même, sinon le dernier avant, sinon le premier connu. */
    private function costMonthFor(string $ym, array $costMonths): array
    {
        if (isset($costMonths[$ym])) return [$costMonths[$ym], $ym];
        $before = array_filter($costMonths, fn($key) => $key < $ym, ARRAY_FILTER_USE_KEY);
        if ($before) {
            $key = array_key_last($before);
            return [$before[$key], $key];
        }
        if ($costMonths) {
            $key = array_key_first($costMonths);
            return [$costMonths[$key], $key];
        }
        return [null, null];
    }

    /** Valeur numérique d'un paramètre ; un ratio de personnel doit rester > 0 (on divise par lui). */
    private function param(string $code, float $default, bool $positive): float
    {
        $value = $this->params->get($code, (string) $default);
        if (!is_numeric($value) || ($positive && (float) $value <= 0)) return $default;
        return (float) $value;
    }

    public static function monthLabel(string $ym, bool $short = false): string
    {
        static $names = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        static $shortNames = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
        [$y, $m] = array_map('intval', explode('-', $ym));
        return ($short ? $shortNames[$m - 1] : $names[$m - 1]) . ' ' . $y;
    }
}
