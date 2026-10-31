<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Exceptions\CustomException;
use App\Repositories\Stock\StockPeriodRepository;
use App\Repositories\Workshop\CapacityRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Services\Workshop\Product\StandardTime;

/**
 * Moce produkcyjne [234] (makiety 10 i 11) — wykorzystanie maszyn wobec planu tygodnia i ile jeszcze można dodać.
 *
 *  - Plan : ilości tygodnia produkcji (WeekService::quantities — linie planu albo propozycja) i ich przydział na dni.
 *  - Obciążenie maszyny = etapy kart produkcyjnych (StandardTime : czas stały × wsady + czas zmienny × ilość + mycie)
 *    na stanowisku ; komora rozrostu / chłodnia jako miejsce oczekiwania (opóźnienie po etapie) = wsady × czas oczekiwania
 *    w miejscach (wózkach) komory.
 *  - Dostępność = zmiany, na których pracuje maszyna (zespół grupy na zmianie, bez zmian wyłączonych na maszynie ; maszyna
 *    włączona na zmianie pracuje też bez zespołu — np. rozrost w nocy ; zmiana dodana « co jeśli » — wszystkie maszyny) × dni produkcji
 *    (week.production_days ∩ dni zmiany) × wydajność realna (TRS, efficiency_pct).
 *  - Ilość na zmianę produktu = najwolniejszy etap (wąskie gardło) ; « jeszcze » = wolny czas każdego etapu ÷ czas na sztukę,
 *    najmniejszy ; wartość = jeszcze × cena standardowa ÷ sztuk w kartonie.
 *  - Mroźnia zapasu : zapas dziś w paletach wobec miejsc paletowych (stock.freezer_pallet_places) + produkcja MTS tygodnia.
 */
final class CapacityService
{
    public const TYPES = ['froid' => 'Chłodzenie i mrożenie', 'surgelation' => 'Chłodzenie i mrożenie', 'four' => 'Wypiek', 'machine' => 'Maszyny',
        'fermentation' => 'Rozrost i oczekiwanie', 'robot' => 'Roboty i linie', 'manuel' => 'Stanowiska ręczne'];
    private const TYPE_ORDER = ['Chłodzenie i mrożenie', 'Wypiek', 'Maszyny', 'Roboty i linie', 'Rozrost i oczekiwanie', 'Stanowiska ręczne'];
    public const UNITS = ['piece' => 'szt.', 'plaque' => 'blach', 'kg' => 'kg', 'chariot' => 'wózków', 'bac' => 'pojemników', 'litre' => 'l'];
    private const DAY_ABBR = [1 => 'pn.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    private const WHAT_IF = ['afternoon' => 'APRES_MIDI', 'night' => 'NUIT'];

    public function __construct(
        private WeekService $week,
        private PlanningRepository $planning,
        private CapacityRepository $repo,
        private ParamRepository $params,
        private StockPeriodRepository $stock,
    ) {
    }

    /**
     * Tydzień produkcji z datą : maszyny (wykorzystanie, dni, wolne), mroźnia, produkty (ilość na zmianę, wąskie gardło,
     * jeszcze, wartość), kafelki. @param array{afternoon?:bool, night?:bool} $whatIf
     */
    public function data(?string $date, array $whatIf = [], ?int $groupId = null): array
    {
        $c = $this->compute($date, $whatIf);
        $target = (float) $this->params->get('capacity.target_pct', '85');
        $machines = [];
        $hidden = [];
        foreach ($c['machines'] as $m) {
            if ($groupId !== null && $m['group_id'] !== $groupId) continue;
            if ($m['view']) $machines[] = $m; else $hidden[] = $m;
        }
        $products = array_values(array_filter($c['products'], fn($p) => $groupId === null || $p['group_id'] === $groupId));
        usort($products, fn($a, $b) => [$b['planned'] > 0, $b['value'] ?? 0, $a['name']] <=> [$a['planned'] > 0, $a['value'] ?? 0, $b['name']]);

        // Sekcje wg rodzaju.
        $sections = [];
        foreach (self::TYPE_ORDER as $t) $sections[$t] = [];
        foreach ($machines as $m) $sections[self::TYPES[$m['type']] ?? 'Maszyny'][] = $m;
        $sections = array_values(array_filter(array_map(fn($k, $v) => ['name' => $k, 'rows' => $v], array_keys($sections), array_values($sections)), fn($s) => $s['rows']));

        // Kafelki.
        $loaded = array_values(array_filter($machines, fn($m) => $m['pct'] !== null && ($m['load_s'] > 0 || $m['occupancy'] > 0)));
        usort($loaded, fn($a, $b) => $b['pct'] <=> $a['pct']);
        $bottleneck = $loaded[0] ?? null;
        $freezerCells = array_values(array_filter($machines, fn($m) => $m['type'] === 'surgelation' && $m['pct'] !== null));
        $best = null;
        foreach ($products as $p) if ($p['more'] > 0 && $p['value'] !== null && ($best === null || $p['value'] > $best['value'])) $best = $p;
        $avg = $loaded ? round(array_sum(array_column($loaded, 'pct')) / count($loaded), 1) : null;

        return [
            'week' => $c['week'], 'what_if' => $c['what_if'], 'group' => $groupId, 'target' => $target, 'readonly' => false, 'today' => date('Y-m-d'),
            'groups' => $c['groups'], 'shifts' => array_values(array_map(fn($s) => ['id' => (int) $s['id'], 'code' => $s['code'], 'name' => $s['name_pl'],
                'hours' => round($this->shiftSeconds($s) / 3600, 1)], $c['shifts'])),
            'sections' => $sections, 'hidden' => $hidden, 'freezer' => $c['freezer'],
            'products' => $products, 'unrouted' => $c['unrouted'],
            'teams' => array_values(array_filter($c['teams'], fn($t) => $groupId === null || $t['id'] === $groupId)),
            'tiles' => [
                'bottleneck' => $bottleneck ? ['name' => $bottleneck['name'], 'pct' => $bottleneck['pct'], 'peak_day' => $bottleneck['peak_day'], 'top' => $bottleneck['top']['name'] ?? null] : null,
                'freezer' => $c['freezer'],
                'blast' => $freezerCells ? ['name' => $freezerCells[0]['name'], 'pct' => max(array_column($freezerCells, 'pct')), 'free' => $freezerCells[0]['free_label']] : null,
                'average' => ['pct' => $avg, 'below' => count(array_filter($loaded, fn($m) => $m['pct'] < $target)), 'count' => count($loaded)],
                'best' => $best ? ['name' => $best['name'], 'more' => $best['more'], 'unit' => $best['unit'], 'value' => $best['value'], 'limit' => $best['more_limit']] : null,
            ],
        ];
    }

    /**
     * Stan obecny (produkcja, bez prawa « pełna wersja ») : tydzień bieżący, zmiany wg zespołów, tylko do odczytu — maszyny,
     * zespoły, mroźnia ; bez produktów, wartości w €, maszyn ukrytych i symulacji.
     */
    public function state(?int $groupId = null): array
    {
        $d = $this->data(null, [], $groupId);
        $d['tiles']['best'] = null;
        $d['products'] = [];
        $d['hidden'] = [];
        $d['readonly'] = true;
        return $d;
    }

    /**
     * « Co jeszcze zmieszczę ? » : produkt + ilość → wykorzystanie każdego etapu przed i po, najwięcej, ile się zmieści,
     * i ile więcej z dodatkową zmianą na wąskim gardle.
     */
    public function simulate(?string $date, int $itemId, float $qty, array $whatIf = []): array
    {
        if ($qty <= 0 || $qty > 1000000) throw new CustomException('Podaj ilość większą od zera.', 'errors', 422);
        $c = $this->compute($date, $whatIf);
        $p = $c['products'][$itemId] ?? throw new CustomException('Ten produkt nie ma etapów w karcie produkcyjnej — nie da się policzyć mocy.', 'errors', 422);
        $steps = [];
        $added = [];
        foreach ($c['routings'][$itemId] as $s) {
            $ws = (int) $s['id_workstation'];
            $calc = StandardTime::step($s, $qty);
            $added[$ws] = ($added[$ws] ?? 0.0) + $calc['work_s'] + $calc['cleaning_s'];
            $dws = $s['delay_id_workstation'] !== null ? (int) $s['delay_id_workstation'] : null;
            if ($dws !== null && (float) $s['delay_after_s'] > 0) $added['d' . $dws] = ($added['d' . $dws] ?? 0.0) + $calc['passes'] * (float) $s['delay_after_s'];
        }
        $seen = [];
        foreach ($c['routings'][$itemId] as $s) {
            foreach ([(int) $s['id_workstation'], $s['delay_id_workstation'] !== null && (float) $s['delay_after_s'] > 0 ? 'd' . (int) $s['delay_id_workstation'] : null] as $key) {
                if ($key === null || isset($seen[$key])) continue;
                $seen[$key] = true;
                $ws = (int) ltrim((string) $key, 'd');
                $m = $c['machines'][$ws] ?? null;
                if ($m === null) continue;
                $isDelay = is_string($key);
                $cap = $isDelay ? $m['avail_s'] * max(1.0, (float) $m['capacity_per_pass']) : $m['avail_s'];
                $before = $isDelay ? $m['occupancy'] : $m['load_s'];
                $after = $before + $added[$key];
                $steps[] = ['workstation' => $m['name'], 'type' => $isDelay ? 'delay' : 'work', 'before' => $cap > 0 ? round($before / $cap * 100, 1) : null,
                    'after' => $cap > 0 ? round($after / $cap * 100, 1) : null, 'ok' => $cap > 0 && $after <= $cap + 1];
            }
        }
        $extra = null;
        if ($p['limit_id'] !== null && $p['limit_tpu'] > 0) {
            $m = $c['machines'][$p['limit_id']];
            $extra = (int) floor($m['shift_s'] * $m['eff'] * count($c['week']['days']) / $p['limit_tpu']);
        }
        return ['item_id' => $itemId, 'name' => $p['name'], 'unit' => $p['unit'], 'qty' => $qty, 'steps' => $steps,
            'fits' => !array_filter($steps, fn($s) => !$s['ok']) && $qty <= $p['more'], 'more' => $p['more'], 'limit' => $p['more_limit'],
            'extra_shift' => $extra, 'value_per_unit' => $p['price_unit']];
    }

    // --- Ustawienia ----------------------------------------------------------------------------------------------------

    public function saveMachine(int $id, array $in, ?int $userId): void
    {
        $ws = $this->repo->workstations()[$id] ?? throw new CustomException('Nie znaleziono maszyny.', 'errors', 404);
        $f = [];
        if (array_key_exists('capacity_per_pass', $in)) {
            $v = $this->num($in['capacity_per_pass']);
            if ($v === null || $v <= 0 || $v > 100000) throw new CustomException('Wsad : liczba większa od zera.', 'errors', 422);
            $f['capacity_per_pass'] = $v;
        }
        if (array_key_exists('capacity_unit', $in)) {
            if (!isset(self::UNITS[$in['capacity_unit']])) throw new CustomException('Nieznana jednostka wsadu.', 'errors', 422);
            $f['capacity_unit'] = $in['capacity_unit'];
        }
        if (array_key_exists('efficiency_pct', $in)) {
            $v = $this->num($in['efficiency_pct']);
            if ($v === null || $v < 10 || $v > 100) throw new CustomException('Wydajność realna (TRS) : od 10 do 100 %.', 'errors', 422);
            $f['efficiency_pct'] = $v;
        }
        if (array_key_exists('cycle_min', $in)) {
            $v = $in['cycle_min'] === null || $in['cycle_min'] === '' ? null : $this->num($in['cycle_min']);
            if ($v !== null && ($v <= 0 || $v > 1440)) throw new CustomException('Cykl : od 1 do 1440 min.', 'errors', 422);
            $f['cycle_s'] = $v === null ? null : (int) round($v * 60);
        }
        if (array_key_exists('view', $in)) $f['capacity_view'] = !empty($in['view']) ? 1 : 0;
        $this->repo->saveMachine($id, $f);
        if (is_array($in['shifts'] ?? null)) {
            $shifts = $this->repo->shifts();
            foreach ($in['shifts'] as $sid => $on) if (isset($shifts[(int) $sid])) $this->repo->setMachineShift($id, (int) $sid, (bool) $on);
        }
    }

    public function saveFreezer(array $in, ?int $userId): void
    {
        $places = $in['places'] ?? null;
        if (!is_int($places) && !(is_string($places) && ctype_digit($places))) throw new CustomException('Miejsca paletowe : liczba całkowita.', 'errors', 422);
        if ((int) $places < 0 || (int) $places > 5000) throw new CustomException('Miejsca paletowe : od 0 do 5000.', 'errors', 422);
        $this->params->setValue('stock.freezer_pallet_places', (string) (int) $places, $userId);
        if (isset($in['alert_pct'])) {
            $a = $this->num($in['alert_pct']);
            if ($a === null || $a < 50 || $a > 100) throw new CustomException('Ostrzeżenie : od 50 do 100 %.', 'errors', 422);
            $this->params->setValue('stock.freezer_alert_pct', (string) $a, $userId);
        }
    }

    // --- Obliczenie -----------------------------------------------------------------------------------------------------

    private function compute(?string $date, array $whatIf): array
    {
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        $q = $this->week->quantities($date);
        $prodDays = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($q['start'] . " +{$i} days"));
            if (in_array((int) date('N', strtotime($d)), $prodDays, true)) $days[] = $d;
        }
        $shifts = $this->repo->shifts();
        $teams = $this->repo->teamShifts();
        $mShifts = $this->repo->machineShifts();
        $wsAll = $this->repo->workstations();
        $routings = $this->planning->routings();
        $items = [];
        foreach ($this->planning->items() as $it) $items[(int) $it['id']] = $it;
        $prices = $this->repo->prices();
        $whatIfIds = [];
        foreach ($shifts as $sid => $s) foreach (self::WHAT_IF as $k => $code) if (!empty($whatIf[$k]) && $s['code'] === $code) $whatIfIds[$sid] = true;

        // Dostępność maszyn (s) na dzień.
        $machines = [];
        foreach ($wsAll as $id => $w) {
            $eff = max(0.1, min(1.0, (float) $w['efficiency_pct'] / 100));
            $works = [];
            foreach ($shifts as $sid => $s) {
                $flag = $mShifts[$id][$sid] ?? null;
                // « Co jeśli » otwiera zmianę dla wszystkich maszyn (także wyłączonych na niej w Stanowiskach) — symulacja.
                $team = isset($teams[(int) $w['id_group']][$sid]);
                if ($flag === 1 || isset($whatIfIds[$sid]) || ($team && $flag !== 0)) $works[$sid] = $s;
            }
            $avail = [];
            $shiftCount = 0;
            foreach ($days as $d) {
                $sec = 0;
                foreach ($works as $s) if (in_array((int) date('N', strtotime($d)), array_map('intval', explode(',', (string) $s['active_days'])), true)) { $sec += $this->shiftSeconds($s); $shiftCount++; }
                $avail[$d] = $sec * $eff;
            }
            $shiftS = $works ? array_sum(array_map(fn($s) => $this->shiftSeconds($s), $works)) / count($works) : 8 * 3600;
            $machines[$id] = ['id' => $id, 'code' => $w['code'], 'name' => $w['name_pl'], 'type' => $w['type'], 'group_id' => $w['id_group'] !== null ? (int) $w['id_group'] : null,
                'group' => $w['group_name'], 'group_color' => $w['group_color'], 'capacity_per_pass' => (float) $w['capacity_per_pass'], 'unit' => self::UNITS[$w['capacity_unit']] ?? $w['capacity_unit'],
                'unit_code' => $w['capacity_unit'], 'eff' => $eff, 'efficiency_pct' => round($eff * 100, 1), 'cycle_s' => $w['cycle_s'] !== null ? (int) $w['cycle_s'] : null, 'cycle_set' => $w['cycle_s'] !== null ? (int) $w['cycle_s'] : null,
                'view' => (bool) $w['capacity_view'], 'shift_ids' => array_map('intval', array_keys($works)), 'shift_names' => array_values(array_map(fn($s) => $s['name_pl'], $works)),
                'shift_s' => $shiftS, 'avail_day' => $avail, 'avail_s' => array_sum($avail), 'load_s' => 0.0, 'load_day' => array_fill_keys($days, 0.0), 'passes' => 0,
                'occupancy' => 0.0, 'occ_day' => array_fill_keys($days, 0.0), 'per_pass' => [], 'contrib' => [], 'step_count' => 0, 'shifts_week' => $shiftCount];
        }

        // Obciążenie z planu tygodnia.
        $unrouted = [];
        foreach ($q['items'] as $id => $it) {
            $steps = $routings[$id] ?? [];
            if (!$steps) { $unrouted[] = ['item_id' => $id, 'name' => $items[$id]['name'] ?? ('#' . $id), 'quantity' => $it['quantity']]; continue; }
            foreach ($steps as $s) {
                $ws = (int) $s['id_workstation'];
                if (!isset($machines[$ws]) || (float) $s['capacity_per_pass'] <= 0) continue;
                $calc = StandardTime::step($s, (float) $it['quantity']);
                $machines[$ws]['load_s'] += $calc['work_s'] + $calc['cleaning_s'];
                $machines[$ws]['passes'] += $calc['passes'];
                $machines[$ws]['contrib'][$id] = ($machines[$ws]['contrib'][$id] ?? 0.0) + $calc['work_s'] + $calc['cleaning_s'];
                foreach ($it['slots'] as $d => $qd) {
                    if (!isset($machines[$ws]['load_day'][$d]) || $qd <= 0) continue;
                    $cd = StandardTime::step($s, (float) $qd);
                    $machines[$ws]['load_day'][$d] += $cd['work_s'] + $cd['cleaning_s'];
                }
                $dws = $s['delay_id_workstation'] !== null ? (int) $s['delay_id_workstation'] : null;
                if ($dws !== null && isset($machines[$dws]) && (float) $s['delay_after_s'] > 0) {
                    $machines[$dws]['occupancy'] += $calc['passes'] * (float) $s['delay_after_s'];
                    foreach ($it['slots'] as $d => $qd) if (isset($machines[$dws]['occ_day'][$d]) && $qd > 0) {
                        $machines[$dws]['occ_day'][$d] += StandardTime::passes((float) $qd, (float) $s['capacity_per_pass']) * (float) $s['delay_after_s'];
                    }
                }
            }
        }
        // Zespoły (ludzie) : obsada grupy na jej zmianach (+ zmiany « co jeśli » z obsadą zmiany dziennej) × dni ; potrzebne =
        // praca etapów na stanowiskach grupy × osoby na etapie (StandardTime::labour_s).
        $teams = [];
        $heads = $this->repo->teamHeadcount();
        $groupNames = [];
        foreach ($wsAll as $w) if ($w['id_group'] !== null) $groupNames[(int) $w['id_group']] = $w['group_name'];
        foreach ($heads as $gid => $byShift) {
            if (!isset($groupNames[$gid])) continue;
            $base = max($byShift ?: [0]);
            $works = [];
            foreach ($shifts as $sid => $sh) {
                $n = $byShift[$sid] ?? (isset($whatIfIds[$sid]) ? $base : 0);
                if ($n > 0) $works[$sid] = [$sh, $n];
            }
            $avail = [];
            foreach ($days as $d) {
                $sec = 0;
                foreach ($works as [$sh, $n]) if (in_array((int) date('N', strtotime($d)), array_map('intval', explode(',', (string) $sh['active_days'])), true)) $sec += $this->shiftSeconds($sh) * $n;
                $avail[$d] = $sec;
            }
            $teams[$gid] = ['id' => $gid, 'name' => 'Zespół — ' . $groupNames[$gid], 'group' => $groupNames[$gid], 'headcount' => $base, 'avail_day' => $avail,
                'avail_s' => array_sum($avail), 'load_s' => 0.0, 'load_day' => array_fill_keys($days, 0.0)];
        }
        foreach ($q['items'] as $id => $it) foreach ($routings[$id] ?? [] as $s) {
            $g = isset($wsAll[(int) $s['id_workstation']]) ? (int) $wsAll[(int) $s['id_workstation']]['id_group'] : null;
            if ($g === null || !isset($teams[$g]) || (float) $s['capacity_per_pass'] <= 0) continue;
            $teams[$g]['load_s'] += StandardTime::step($s, (float) $it['quantity'])['labour_s'];
            foreach ($it['slots'] as $d => $qd) if (isset($teams[$g]['load_day'][$d]) && $qd > 0) $teams[$g]['load_day'][$d] += StandardTime::step($s, (float) $qd)['labour_s'];
        }
        foreach ($teams as &$t) {
            $t['pct'] = $t['avail_s'] > 0 ? round($t['load_s'] / $t['avail_s'] * 100, 1) : ($t['load_s'] > 0 ? 999.0 : null);
            $t['free_s'] = max(0.0, $t['avail_s'] - $t['load_s']);
            $t['avail_h'] = round($t['avail_s'] / 3600, 1);
            $t['load_h'] = round($t['load_s'] / 3600, 1);
            $t['free_h'] = round($t['free_s'] / 3600, 1);
            $t['days'] = array_map(fn($d) => ['date' => $d, 'pct' => $t['avail_day'][$d] > 0 ? round($t['load_day'][$d] / $t['avail_day'][$d] * 100) : ($t['load_day'][$d] > 0 ? 999 : null)], $days);
            unset($t['avail_day'], $t['load_day']);
        }
        unset($t);

        // Typowy cykl : ustawienie maszyny albo średni czas wsadu z kart (czas stały + zmienny × wsad).
        foreach ($routings as $steps) foreach ($steps as $s) {
            $ws = (int) $s['id_workstation'];
            if (!isset($machines[$ws]) || (float) $s['capacity_per_pass'] <= 0) continue;
            $machines[$ws]['per_pass'][] = (float) $s['fixed_time_s'] + (float) $s['variable_time_s'] * (float) $s['capacity_per_pass'];
            $machines[$ws]['step_count']++;
        }

        // Wykorzystanie maszyn.
        foreach ($machines as $id => &$m) {
            $cycle = $m['cycle_s'] ?? ($m['per_pass'] ? (int) round(array_sum($m['per_pass']) / count($m['per_pass'])) : null);
            $isDelay = $m['occupancy'] > 0 && $m['load_s'] <= 0;
            $capS = $isDelay ? $m['avail_s'] * max(1.0, $m['capacity_per_pass']) : $m['avail_s'];
            $used = $isDelay ? $m['occupancy'] : $m['load_s'];
            $m['mode'] = $isDelay ? 'delay' : 'work';
            $m['cycle_s'] = $cycle;
            $m['passes_shift'] = $cycle ? (int) floor($m['shift_s'] * $m['eff'] / $cycle) : null;
            $m['pct'] = $capS > 0 ? round($used / $capS * 100, 1) : ($used > 0 ? 999.0 : null);
            $m['free_s'] = max(0.0, $capS - $used);
            $m['days'] = [];
            foreach ($days as $d) {
                $cd = $isDelay ? $m['avail_day'][$d] * max(1.0, $m['capacity_per_pass']) : $m['avail_day'][$d];
                $ud = $isDelay ? $m['occ_day'][$d] : $m['load_day'][$d];
                $m['days'][] = ['date' => $d, 'pct' => $cd > 0 ? round($ud / $cd * 100) : ($ud > 0 ? 999 : null)];
            }
            $peak = null;
            foreach ($m['days'] as $dd) if ($dd['pct'] !== null && ($peak === null || $dd['pct'] > $peak['pct'])) $peak = $dd;
            $m['peak_day'] = $peak && $peak['pct'] > 0 ? ['label' => self::DAY_ABBR[(int) date('N', strtotime($peak['date']))], 'pct' => $peak['pct']] : null;
            arsort($m['contrib']);
            $topId = array_key_first($m['contrib']);
            $m['top'] = $topId !== null ? ['item_id' => $topId, 'name' => $items[$topId]['name'] ?? ('#' . $topId), 'unit' => $this->unit($items[$topId] ?? [])] : null;
            $m['avail_h'] = round($m['avail_s'] / 3600, 1);
            $m['load_h'] = round(($isDelay ? $m['occupancy'] / max(1.0, $m['capacity_per_pass']) : $m['load_s']) / 3600, 1);
            $m['free_h'] = round(($isDelay ? $m['free_s'] / max(1.0, $m['capacity_per_pass']) : $m['free_s']) / 3600, 1);
            $m['capacity_label'] = $this->fmt($m['capacity_per_pass']) . ' ' . $m['unit'];
            $m['no_steps'] = $m['step_count'] === 0 && $m['occupancy'] <= 0;
        }
        unset($m);

        // Produkty : ilość na zmianę (wąskie gardło), jeszcze w tygodniu, wartość.
        $products = [];
        foreach ($routings as $id => $steps) {
            if (!isset($items[$id])) continue;
            $best = null;
            $more = null;
            foreach ($steps as $s) {
                $ws = (int) $s['id_workstation'];
                $cap = (float) $s['capacity_per_pass'];
                if (!isset($machines[$ws]) || $cap <= 0) continue;
                $m = $machines[$ws];
                $tpu = (float) $s['fixed_time_s'] / $cap + (float) $s['variable_time_s'];
                if ($tpu > 0) {
                    $perShift = $m['shift_s'] * $m['eff'] / $tpu;
                    if ($best === null || $perShift < $best['per_shift']) $best = ['per_shift' => $perShift, 'ws' => $ws, 'tpu' => $tpu, 'cycle' => $s, 'delay' => false];
                    $can = $m['mode'] === 'work' && $m['avail_s'] > 0 ? $m['free_s'] / $tpu : 0.0;
                    $more = $more === null ? $can : min($more, $can);
                }
                $dws = $s['delay_id_workstation'] !== null ? (int) $s['delay_id_workstation'] : null;
                if ($dws !== null && isset($machines[$dws]) && (float) $s['delay_after_s'] > 0) {
                    $d = $machines[$dws];
                    $occ = (float) $s['delay_after_s'] / $cap; // wózko-sekundy na sztukę
                    $perShift = $d['shift_s'] * $d['eff'] * max(1.0, $d['capacity_per_pass']) / $occ;
                    if ($best === null || $perShift < $best['per_shift']) $best = ['per_shift' => $perShift, 'ws' => $dws, 'tpu' => $occ / max(1.0, $d['capacity_per_pass']), 'cycle' => $s, 'delay' => true];
                    $can = $d['avail_s'] > 0 ? $d['free_s'] / $occ : 0.0;
                    $more = $more === null ? $can : min($more, $can);
                }
            }
            if ($best === null) continue;
            // Ludzie : roboczogodziny na sztukę w każdej grupie (etapy jej stanowisk) wobec wolnych godzin zespołu.
            $labour = [];
            foreach ($steps as $s) {
                $cap = (float) $s['capacity_per_pass'];
                $g = isset($wsAll[(int) $s['id_workstation']]) ? (int) $wsAll[(int) $s['id_workstation']]['id_group'] : null;
                if ($cap <= 0 || $g === null) continue;
                $labour[$g] = ($labour[$g] ?? 0.0) + ((float) $s['fixed_time_s'] / $cap + (float) $s['variable_time_s']) * (float) ($s['operators_required'] ?? 1);
            }
            $peopleLimit = null;
            foreach ($labour as $g => $lpu) {
                if ($lpu <= 0 || !isset($teams[$g])) continue;
                $can = $teams[$g]['free_s'] / $lpu;
                if ($more === null || $can < $more) { $more = $can; $peopleLimit = $teams[$g]['name']; }
            }
            $it = $items[$id];
            $price = $prices[$id] ?? null;
            $ppc = $price && (int) $price['ppc'] > 0 ? (int) $price['ppc'] : ((int) ($it['ppc'] ?? 0) ?: null);
            $priceUnit = $price && (float) $price['standard_price'] > 0 && $ppc ? (float) $price['standard_price'] / $ppc : null;
            $lim = $machines[$best['ws']];
            $moreQ = (int) floor(max(0.0, (float) $more));
            $perShift = (int) floor($best['per_shift']);
            $products[$id] = ['item_id' => $id, 'name' => $it['name'] ?? ('#' . $id), 'item_type' => $it['item_type'], 'group_id' => $it['id_primary_group'] !== null ? (int) $it['id_primary_group'] : null,
                'unit' => $this->unit($it), 'ppc' => $ppc, 'per_shift' => $perShift, 'per_shift_cartons' => $ppc ? round($perShift / $ppc, 1) : null,
                'limit_id' => $best['ws'], 'limit_name' => $lim['name'], 'more_limit' => $peopleLimit ?? $lim['name'], 'people_limited' => $peopleLimit !== null, 'limit_pct' => $lim['pct'], 'limit_shifts' => $lim['shifts_week'],
                'limit_cycle' => $this->cycleLabel($best['cycle'], $best['delay']), 'limit_tpu' => $best['tpu'],
                'planned' => round((float) ($q['items'][$id]['quantity'] ?? 0), 1), 'more' => $moreQ, 'more_cartons' => $ppc ? round($moreQ / $ppc, 1) : null,
                'price_unit' => $priceUnit !== null ? round($priceUnit, 4) : null, 'value' => $priceUnit !== null ? (int) round($moreQ * $priceUnit) : null];
        }

        // Mroźnia zapasu : zapas dziś + produkcja MTS tygodnia (bez wysyłek — górna granica).
        $places = (int) $this->params->get('stock.freezer_pallet_places', '0');
        try { $now = round($this->stock->stockPallets(), 1); } catch (\PDOException) { $now = 0.0; }
        $mtsPallets = 0.0;
        foreach ($q['items'] as $id => $it) {
            $i = $items[$id] ?? null;
            $p = $prices[$id] ?? null;
            if (!$i || ($i['mode'] ?? '') !== 'MTS' || !$p || (int) $p['ppc'] <= 0 || (int) $p['cpp'] <= 0) continue;
            $mtsPallets += (float) $it['quantity'] / (int) $p['ppc'] / (int) $p['cpp'];
        }
        $alert = (float) $this->params->get('stock.freezer_alert_pct', '90');
        $freezer = ['places' => $places, 'now' => $now, 'mts_week' => round($mtsPallets, 1), 'max_week' => round($now + $mtsPallets, 1),
            'pct' => $places > 0 ? round($now / $places * 100, 1) : null, 'pct_max' => $places > 0 ? round(($now + $mtsPallets) / $places * 100, 1) : null,
            'free' => $places > 0 ? round(max(0, $places - $now), 1) : null, 'alert_pct' => $alert];

        $groups = [];
        foreach ($wsAll as $w) if ($w['id_group'] !== null) $groups[(int) $w['id_group']] = $w['group_name'];
        $weekNo = (int) date('W', strtotime($days[0] ?? $q['start']));
        return [
            'week' => ['start' => $q['start'], 'end' => $q['end'], 'days' => array_map(fn($d) => ['date' => $d, 'label' => self::DAY_ABBR[(int) date('N', strtotime($d))] . ' ' . date('d.m', strtotime($d)),
                'short' => self::DAY_ABBR[(int) date('N', strtotime($d))], 'today' => $d === date('Y-m-d')], $days), 'iso' => $weekNo,
                'label' => sprintf('tydzień %d (%s–%s)', $weekNo, date('d.m', strtotime($days[0] ?? $q['start'])), date('d.m.Y', strtotime(end($days) ?: $q['end']))),
                'prev' => date('Y-m-d', strtotime($q['start'] . ' -7 days')), 'next' => date('Y-m-d', strtotime($q['start'] . ' +7 days')),
                'planned_items' => count($q['items'])],
            'what_if' => ['afternoon' => !empty($whatIf['afternoon']), 'night' => !empty($whatIf['night'])],
            'machines' => array_map(fn($m) => $this->publicMachine($m), $machines), 'products' => $products, 'routings' => $routings, 'freezer' => $freezer,
            'unrouted' => $unrouted, 'teams' => array_values($teams), 'shifts' => $shifts, 'groups' => array_map(fn($k, $v) => ['id' => $k, 'name' => $v], array_keys($groups), array_values($groups)),
        ];
    }

    private function publicMachine(array $m): array
    {
        $free = $m['free_s'];
        $label = null;
        if ($m['mode'] === 'delay') $label = $this->fmt(round($free / max(1.0, $m['capacity_per_pass']) / 3600, 1)) . ' h × ' . $this->fmt($m['capacity_per_pass']) . ' ' . $m['unit'];
        elseif ($m['cycle_s']) $label = $this->fmt(floor($free / $m['cycle_s'])) . ' wsadów';
        unset($m['per_pass'], $m['contrib'], $m['load_day'], $m['occ_day'], $m['avail_day']);
        return $m + ['free_label' => $label];
    }

    private function cycleLabel(array $s, bool $delay): string
    {
        if ($delay) return 'oczekiwanie ' . $this->minutes((int) $s['delay_after_s']) . ' / wsad ' . $this->fmt((float) $s['capacity_per_pass']);
        $pass = (float) $s['fixed_time_s'] + (float) $s['variable_time_s'] * (float) $s['capacity_per_pass'];
        return $this->minutes((int) round($pass)) . ' / ' . $this->fmt((float) $s['capacity_per_pass']);
    }

    private function minutes(int $s): string
    {
        if ($s < 60) return $s . ' s';
        $m = (int) round($s / 60);
        return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : '');
    }

    private function unit(array $item): string
    {
        $u = strtolower((string) ($item['base_unit'] ?? 'szt'));
        return in_array($u, ['kg', 'g', 'l'], true) ? $u : 'szt.';
    }

    public function shiftSeconds(array $s): int
    {
        $a = strtotime('1970-01-01 ' . $s['start_time'] . ' UTC');
        $b = strtotime('1970-01-01 ' . $s['end_time'] . ' UTC');
        $d = $b - $a;
        return $d <= 0 ? $d + 86400 : $d;
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', "\u{00A0}"), '0'), ',');
    }

    private function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_string($v) && preg_match('/^-?\d+([.,]\d+)?$/', trim($v))) return (float) str_replace(',', '.', trim($v));
        return null;
    }
}
