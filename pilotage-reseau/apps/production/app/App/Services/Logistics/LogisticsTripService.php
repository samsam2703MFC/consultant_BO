<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Domain\Logistics\TripPlan;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsPlanRepository;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Kalendarz tras [207] (plan logistyki) : trasa każdej ciężarówki w tygodniu — z szablonu (parametry : załadunek,
 * jazda tam i z powrotem, rozładunek, odpoczynek tygodniowy ; przerwy i odpoczynki wstawiane według UE 561/2006)
 * albo zmieniona ręcznie w kalendarzu (n_logistics_trip). Klienci = klienci na ciężarówce w Tablicy planu.
 */
final class LogisticsTripService
{
    /** Szablon : parametr => [klucz, domyślnie, min, max]. */
    private const TEMPLATE = [
        'logistics.trip_load_min' => ['load_min', 60, 0, 600],
        'logistics.trip_drive_out_min' => ['drive_out_min', 765, 0, 3000],
        'logistics.trip_between_min' => ['between_min', 35, 0, 600],
        'logistics.trip_unload_min' => ['unload_min', 30, 0, 600],
        'logistics.trip_unload_pallet_min' => ['unload_pallet_min', 2, 0, 60],
        'logistics.trip_drive_back_min' => ['drive_back_min', 765, 0, 3000],
        'logistics.trip_weekly_rest_min' => ['weekly_rest_min', 2700, 0, 6000],
    ];

    public function __construct(
        private LogisticsPlanRepository $repo,
        private ParamRepository $params,
        private LogisticsRepository $logi,
    ) {}

    public function template(): array
    {
        $out = ['departure_time' => self::time($this->params->get('logistics.trip_departure_time', '05:00')) ?? '05:00'];
        foreach (self::TEMPLATE as $code => [$key, $default, $min, $max]) {
            $v = $this->params->get($code, (string) $default);
            $out[$key] = is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
        }
        return $out;
    }

    /** Jazda w tygodniu kierowcy (UE 561/2006) : najwyżej 56 h. */
    public const WEEK_DRIVE = 3360;

    /**
     * Trasy tygodni planu : ciężarówka z klientami w tygodniu albo trasa zapisana ręcznie. Kilka tras w tygodniu [208]
     * (zmiany kierowcy, standard 1) : klienci w kolejności przystanków dzieleni na trasy po miejscach ciężarówki ;
     * następna trasa po odpoczynku dobowym (11 h) po powrocie poprzedniej, odpoczynek tygodniowy po ostatniej.
     * @param array<string, array> $weekRows tydzień => wiersz planu (ship_date…)
     * @param list<array> $vehicles pojazdy planu (cells : customers, pallets, driver, trips, places_per_trip)
     * @param list<array> $customers klienci planu (cells : pallets)
     */
    public function build(array $weekRows, array $vehicles, array $customers): array
    {
        if (!$weekRows) return [];
        $weeks = array_keys($weekRows);
        $tpl = $this->template();
        $saved = $this->repo->trips($weeks[0], end($weeks));
        $order = $this->repo->stopOrders();
        $byId = array_column($customers, null, 'id');
        $name = fn($id) => $byId[$id]['short'] ?? ('#' . $id);
        $out = [];
        foreach ($vehicles as $v) {
            foreach ($weeks as $ws) {
                $cell = $v['cells'][$ws] ?? null;
                $manual = $saved[$ws][$v['id']] ?? [];
                if ($cell === null || (!$cell['customers'] && !$manual)) continue;
                $ids = $cell['customers'];
                usort($ids, fn($a, $b) => [$order[$a] ?? PHP_INT_MAX, $name($a)] <=> [$order[$b] ?? PHP_INT_MAX, $name($b)]);
                $stops = array_map(fn($id) => ['client_id' => $id, 'pallets' => (int) ($byId[$id]['cells'][$ws]['pallets'] ?? 0)], $ids);
                // Podział na trasy : po kolei, do miejsc ciężarówki ; ostatnia trasa bierze resztę.
                $n = max(1, (int) ($cell['trips'] ?? 1));
                $cap = max(1, (int) ($cell['places_per_trip'] ?? $cell['places']));
                $split = array_fill(1, $n, []);
                $k = 1;
                $load = 0;
                foreach ($stops as $st) {
                    if ($k < $n && $split[$k] && $load + $st['pallets'] > $cap) { $k++; $load = 0; }
                    $split[$k][] = $st;
                    $load += $st['pallets'];
                }
                $numbers = array_values(array_filter(range(1, $n), fn($no) => $split[$no] || isset($manual[$no])));
                foreach (array_keys($manual) as $no) if ($no > $n) $numbers[] = $no;
                sort($numbers);
                $ship = $weekRows[$ws]['ship_date'];
                $defaultStart = $ship . ' ' . (self::time((string) ($v['departure'] ?? '')) ?? $tpl['departure_time']);
                $prevEnd = null;
                $group = [];
                foreach ($numbers as $idx => $no) {
                    $last = $idx === count($numbers) - 1;
                    $m = $manual[$no] ?? null;
                    $autoStart = $prevEnd === null ? $defaultStart : self::quarter($prevEnd);
                    if ($m !== null) {
                        $phases = $m['phases'];
                        $start = $m['start_at'];
                    } else {
                        $phases = TripPlan::withRest(TripPlan::work($tpl, $split[$no] ?? []), $last ? $tpl['weekly_rest_min'] : 0);
                        // Między trasami tygodnia : odpoczynek dobowy (nie tygodniowy).
                        if (!$last) $phases[] = ['k' => 'rest', 'm' => TripPlan::DAILY_REST];
                        $start = $autoStart;
                    }
                    $timeline = array_map(function ($p) use ($byId, $ws, $name) {
                        if ($p['k'] === 'unload' && isset($p['client_id'])) {
                            $p['client'] = $name((int) $p['client_id']);
                            $p['pallets'] = (int) ($byId[(int) $p['client_id']]['cells'][$ws]['pallets'] ?? 0);
                        }
                        return $p;
                    }, TripPlan::timeline($start, $phases));
                    // U klienta = pierwszy rozładunek ; powrót = koniec ostatniej fazy pracy (bez odpoczynku na końcu).
                    $first = null;
                    $home = null;
                    foreach ($timeline as $p) {
                        if ($p['k'] === 'unload' && $first === null) $first = $p['s'];
                        if (!in_array($p['k'], ['weekly', 'rest', 'brk'], true)) $home = $p['e'];
                    }
                    $end = $timeline ? end($timeline)['e'] : $start;
                    $warnings = [];
                    if ($m !== null && $no === 1 && substr($start, 0, 10) !== $ship) $warnings[] = ['code' => 'SHIP_DATE', 'date' => $ship];
                    // Trasa zaczyna się przed końcem poprzedniej (z odpoczynkiem dobowym).
                    if ($prevEnd !== null && $start < $prevEnd) $warnings[] = ['code' => 'OVERLAP', 'no' => $no];
                    $prevEnd = $end;
                    $group[] = [
                        'week_start' => $ws, 'vehicle_id' => $v['id'], 'trip_no' => $no, 'trips' => count($numbers), 'label' => $v['label'], 'driver' => $cell['driver']['name'] ?? null,
                        'pallets' => array_sum(array_map(fn($p) => $p['k'] === 'unload' ? (int) ($p['pallets'] ?? 0) : 0, $timeline)), 'places' => $cap, 'clients' => count(array_filter($timeline, fn($p) => $p['k'] === 'unload')),
                        'start_at' => $start, 'default_start' => $autoStart, 'arrive_at' => $first, 'home_at' => $home, 'end_at' => $end,
                        'manual' => $m !== null, 'phases' => $timeline, 'checks' => TripPlan::checks($phases), 'warnings' => $warnings,
                        'stops' => array_map(fn($st) => $st + ['client' => $name($st['client_id'])], $stops),
                    ];
                }
                // Tydzień ciężarówki : klienci planu wobec rozładunków (gdy trasa zmieniona ręcznie), jazda w tygodniu ≤ 56 h.
                $drive = 0;
                $unloaded = [];
                foreach ($group as $t) foreach ($t['phases'] as $p) {
                    if (in_array($p['k'], ['drive', 'back'], true)) $drive += (int) $p['m'];
                    if ($p['k'] === 'unload' && isset($p['client_id'])) $unloaded[(int) $p['client_id']] = true;
                }
                if (array_filter($group, fn($t) => $t['manual'])) {
                    $planned = array_map('intval', $ids);
                    $missing = array_diff($planned, array_keys($unloaded));
                    $extra = array_diff(array_keys($unloaded), $planned);
                    if ($missing) $group[0]['warnings'][] = ['code' => 'MISSING', 'names' => array_values(array_map($name, $missing))];
                    if ($extra) $group[0]['warnings'][] = ['code' => 'EXTRA', 'names' => array_values(array_map($name, $extra))];
                }
                if (count($group) > 1) {
                    $check = ['code' => 'WEEK_DRIVE', 'level' => $drive <= self::WEEK_DRIVE ? 'ok' : 'bad', 'value' => $drive, 'limit' => self::WEEK_DRIVE];
                    $group[count($group) - 1]['checks'][] = $check;
                }
                array_push($out, ...$group);
            }
        }
        return $out;
    }

    /** Trasa zmieniona w kalendarzu : {start_at, phases: [{k, m, client_id?}]}. */
    public function save(string $week, int $vehicleId, array $input, ?int $userId, int $tripNo = 1): void
    {
        if ($tripNo < 1 || $tripNo > 4) throw new CustomException('Trasa : numer od 1 do 4.', 'errors', 422);
        $week = self::monday($week);
        if (!in_array($vehicleId, array_map(fn($v) => (int) $v['id'], $this->logi->vehicles()), true)) throw new CustomException('Nie znaleziono ciężarówki.', 'errors', 404);
        $start = str_replace('T', ' ', trim((string) ($input['start_at'] ?? '')));
        $ts = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start) ? strtotime($start) : false;
        if ($ts === false || date('Y-m-d H:i', $ts) !== $start) throw new CustomException('Początek trasy : data i godzina (RRRR-MM-DD GG:MM).', 'errors', 422);
        if (abs($ts - strtotime($week)) > 21 * 86400) throw new CustomException('Początek trasy : w okolicy tygodnia planu.', 'errors', 422);
        $phases = $this->phases($input['phases'] ?? null);
        $this->repo->saveTrip($week, $vehicleId, $start, $phases, $userId, $tripNo);
    }

    public function reset(string $week, int $vehicleId, int $tripNo = 1): void
    {
        $this->repo->deleteTrip(self::monday($week), $vehicleId, $tripNo);
    }

    /** « Wstaw przerwy i odpoczynki » : trasy zmienione ręcznie w tygodniu — przerwy według reguł. @return int tras */
    public function insertRest(string $week, ?int $userId): int
    {
        $week = self::monday($week);
        $tpl = $this->template();
        $n = 0;
        foreach ($this->repo->trips($week, $week)[$week] ?? [] as $vid => $byNo) {
            $lastNo = max(array_keys($byNo));
            foreach ($byNo as $no => $t) {
                $weekly = 0;
                foreach ($t['phases'] as $p) if ($p['k'] === 'weekly') $weekly = (int) $p['m'];
                $tail = end($t['phases'])['k'] ?? null;
                // Trasa przed następną w tygodniu kończy się odpoczynkiem dobowym, ostatnia — tygodniowym.
                $before = $tail === 'rest' || (!$weekly && $tail !== 'weekly' && $no < $lastNo);
                $phases = TripPlan::withRest($t['phases'], $before ? 0 : ($weekly ?: $tpl['weekly_rest_min']));
                if ($before) $phases[] = ['k' => 'rest', 'm' => TripPlan::DAILY_REST];
                $this->repo->saveTrip($week, (int) $vid, $t['start_at'], $phases, $userId, (int) $no);
                $n++;
            }
        }
        return $n;
    }

    /** Szablon trasy : godzina załadunku i czasy (min ; odpoczynek tygodniowy w h). */
    public function saveTemplate(array $input, ?int $userId): void
    {
        $values = [];
        $errors = [];
        if (array_key_exists('departure_time', $input)) {
            $t = self::time((string) $input['departure_time']);
            if ($t === null) $errors[] = 'Godzina załadunku : GG:MM.';
            else $values['logistics.trip_departure_time'] = $t;
        }
        foreach (self::TEMPLATE as $code => [$key, , $min, $max]) {
            if (!array_key_exists($key, $input)) continue;
            $v = filter_var($input[$key], FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) { $errors[] = "{$key} : od {$min} do {$max} min."; continue; }
            $values[$code] = (string) $v;
        }
        if ($errors) throw new CustomException(implode(' ', $errors), 'errors', 422);
        foreach ($values as $code => $v) $this->params->setValue($code, $v, $userId);
    }

    /** @return list<array{k:string, m:int, client_id?:int}> */
    private function phases(mixed $raw): array
    {
        if (!is_array($raw) || !$raw || count($raw) > 80) throw new CustomException('Trasa : od 1 do 80 faz.', 'errors', 422);
        $out = [];
        foreach (array_values($raw) as $i => $p) {
            $k = is_array($p) ? (string) ($p['k'] ?? '') : '';
            $m = is_array($p) ? filter_var($p['m'] ?? null, FILTER_VALIDATE_INT) : false;
            if (!in_array($k, TripPlan::KINDS, true)) throw new CustomException('Faza ' . ($i + 1) . ' : nieznany rodzaj.', 'errors', 422);
            if ($m === false || $m < 1 || $m > 6000) throw new CustomException('Faza ' . ($i + 1) . ' : czas od 1 do 6000 min.', 'errors', 422);
            $row = ['k' => $k, 'm' => $m];
            if ($k === 'unload') {
                $c = filter_var($p['client_id'] ?? null, FILTER_VALIDATE_INT);
                if ($c === false || $c < 1) throw new CustomException('Faza ' . ($i + 1) . ' : rozładunek bez klienta.', 'errors', 422);
                $row['client_id'] = $c;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Początek następnej trasy : w górę do pełnego kwadransa. */
    private static function quarter(string $at): string
    {
        $t = strtotime($at);
        return date('Y-m-d H:i', (int) (ceil($t / 900) * 900));
    }

    private static function time(string $v): ?string
    {
        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)/', trim($v), $m) ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : null;
    }

    private static function monday(string $week): string
    {
        $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? strtotime($week) : false;
        if ($ts === false || date('N', $ts) !== '1') throw new CustomException('Tydzień : poniedziałek (RRRR-MM-DD).', 'errors', 422);
        return date('Y-m-d', $ts);
    }
}
