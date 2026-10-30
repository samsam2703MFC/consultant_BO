<?php
declare(strict_types=1);

namespace App\Services\Forecast;

use App\Core\TransactionManager;
use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Forecast\ForecastRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Logistics\LogisticsPlanService;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Planning\PlanningService;
use App\Services\Workshop\Product\StandardTime;

/**
 * Prognoza 13 tygodni [220] (Produkcja › Prognoza 13 tygodni, makiety 1–3) — co tydzień te same 13 tygodni do przodu od
 * bieżącego poniedziałku, kartony na produkt i tydzień :
 *  - tydzień bieżący : zamówienia (gdy są) ; dalej większe z : zamówienia już złożone, prognoza ;
 *  - produkty całoroczne : średnio na tydzień w ostatnich tygodniach (forecast.trend_weeks) × kształt N-1 (kartony sieci bez
 *    produktów sezonowych w tym samym tygodniu rok temu, ±1 tydzień, ÷ poziom N-1 tych ostatnich tygodni) = N-1 × trend ;
 *    tydzień N-1 bez danych (mniej niż 30 % poziomu) : sama średnia ;
 *  - produkty sezonowe (gamy panelu TF Buddy, tylko nasze produkty — katalog SL Food) : kartony wysłane w tej samej gamie
 *    rok temu (tydzień gamy N-1 → ten sam tydzień gamy w tym roku) × trend sieci ; poza gamą tylko zamówienia ;
 *  - trend sieci = kartony ostatnich tygodni ÷ te same tygodnie rok temu (tygodnie N-1 z danymi), w granicach parametrów.
 * Z kartonów : palety (kartony na paletę), godziny produkcji (partia z arkusza albo marszruta) wobec zdolności (pracownicy ×
 * etat, dni pracy bez świąt PL) i zakupy surowców (receptura na karton, stan, w drodze, czas dostawy → « zamówić do »).
 * Zatwierdzenie (Kierownik produkcji, co poniedziałek) zapisuje zdjęcie liczb w n_forecast_run i zalicza zadanie tygodnia.
 */
final class ForecastService
{
    private const EPS = 1e-9;
    private const COVERAGE = 0.3;
    private const SHAPE_MIN = 0.5;
    private const SHAPE_MAX = 2.0;
    private const COLORS = ['#8e1f2f', '#5b3a85', '#2b5f9e', '#c17a2a', '#1f5a37', '#3f6b7c', '#9a5b13', '#6b6b6b'];

    public function __construct(
        private ForecastRepository $repo,
        private PanelSeasonService $panel,
        private PlanningRepository $planning,
        private SupplyRepository $supply,
        private ParamRepository $params,
        private TransactionManager $tx,
    ) {
    }

    /** Strona : prognoza, gamy, zakupy, zatwierdzenie tygodnia ; odczyt panelu, gdy ostatni jest sprzed poniedziałku. */
    public function page(?string $today = null, ?int $now = null): array
    {
        $now ??= time();
        $today ??= date('Y-m-d', $now);
        $panel = $this->panel->last($now);
        // Odczyt tygodniowy przy pierwszym otwarciu po poniedziałku 6:00 (przyrostowy : tylko nowe tygodnie sprzedaży) ;
        // pierwszy odczyt w ogóle (dłuższy) — przyciskiem « Odśwież z panelu ».
        if ($panel !== null && $panel['stale'] && $this->panel->configured()) {
            try { $panel = $this->panel->refresh(null, $now, ...$this->windowParams()); } catch (\Throwable) { /* zostaje poprzedni odczyt, błąd w lastAttempt */ }
        }
        $f = $this->compute($today, $panel);
        $run = $this->repo->runForWeek($f['weeks'][0]['week_start']);
        $attempt = $this->panel->lastAttempt();
        return $f + [
            'approval' => $run ? ['approved_at' => $run['approved_at'], 'by' => $run['approved_by_name'], 'cartons' => (int) $run['cartons'], 'note' => $run['note'],
                'changed' => (int) $run['cartons'] !== $f['totals']['cartons']] : null,
            'runs' => array_map(fn($r) => ['week_start' => $r['week_start'], 'iso_week' => (int) date('W', strtotime($r['week_start'])), 'cartons' => (int) $r['cartons'],
                'season_cartons' => (int) $r['season_cartons'], 'hours' => $r['hours'] !== null ? (float) $r['hours'] : null,
                'purchase_value' => $r['purchase_value'] !== null ? (float) $r['purchase_value'] : null, 'approved_at' => $r['approved_at'], 'by' => $r['approved_by_name']], $this->repo->runs()),
            'panel_status' => ['configured' => $this->panel->configured(), 'read_at' => $panel['read_at'] ?? null, 'read_by' => $panel['read_by'] ?? null,
                'weeks' => count($panel['sales'] ?? []), 'failed' => count($panel['sales_failed'] ?? []),
                'error' => $attempt && !(int) $attempt['ok'] && (!$panel || $attempt['read_at'] > $panel['read_at']) ? $attempt['error'] : null],
        ];
    }

    /** Zatwierdzenie prognozy tygodnia (ponowne : nadpisuje zdjęcie liczb). */
    public function approve(?int $userId, ?string $note = null, ?string $today = null, ?int $now = null): array
    {
        $now ??= time();
        $today ??= date('Y-m-d', $now);
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 255) throw new CustomException('Uwaga : najwyżej 255 znaków.', 'errors', 422);
        $f = $this->compute($today, $this->panel->last($now));
        if (!$f['products']) throw new CustomException('Prognoza jest pusta — brak zamówień i sprzedaży w ostatnich tygodniach.', 'errors', 422);
        $payload = [
            'weeks' => array_map(fn($w) => ['week_start' => $w['week_start'], 'iso_week' => $w['iso_week']], $f['weeks']),
            'products' => array_map(fn($p) => ['id' => $p['product_id'], 'name' => $p['name'], 'kind' => $p['kind'], 'values' => $p['values'], 'sources' => $p['sources']], $f['products']),
            'totals' => $f['totals'], 'trend' => $f['trend'], 'seasons' => array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'from' => $s['from'], 'to' => $s['to'], 'cartons' => $s['cartons']], $f['seasons']),
            'purchases' => array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name'], 'need' => $m['need'], 'to_buy' => $m['to_buy'], 'order_by' => $m['order_by'], 'value' => $m['value']], $f['purchases']['rows']),
        ];
        $this->tx->execute(fn() => $this->repo->saveRun([
            'week_start' => $f['weeks'][0]['week_start'], 'weeks' => count($f['weeks']), 'trend' => $f['trend']['value'],
            'cartons' => $f['totals']['cartons'], 'season_cartons' => $f['totals']['season_cartons'], 'hours' => $f['totals']['hours'],
            'purchase_value' => $f['purchases']['totals']['value'], 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'note' => $note ?: null, 'approved_at' => date('Y-m-d H:i:s', $now), 'approved_by' => $userId,
        ]));
        return $this->page($today, $now);
    }

    /** Cofnięcie zatwierdzenia bieżącego tygodnia. */
    public function reopen(?string $today = null, ?int $now = null): array
    {
        $now ??= time();
        $today ??= date('Y-m-d', $now);
        $this->tx->execute(fn() => $this->repo->deleteRun(LogisticsPlanService::monday($today)));
        return $this->page($today, $now);
    }

    /** Odczyt panelu na żądanie (GET). */
    public function refreshPanel(?int $userId, ?string $today = null, ?int $now = null): array
    {
        $this->panel->refresh($userId, $now, ...$this->windowParams());
        return $this->page($today, $now);
    }

    /** Produkt gamy panelu → nasz produkt (albo « nie od nas » = pominięty, albo null = z powrotem podpowiedź). */
    public function link(int $panelProductId, array $input, ?int $userId, ?string $today = null, ?int $now = null): array
    {
        $panel = $this->panel->last($now);
        $name = $panel['products'][$panelProductId]['name'] ?? $panel['products'][(string) $panelProductId]['name'] ?? null;
        if ($panel === null || $name === null) throw new CustomException('Nie ma takiego produktu w gamach panelu (odśwież odczyt).', 'errors', 404);
        $mode = (string) ($input['mode'] ?? 'product');
        $this->tx->execute(function () use ($mode, $input, $panelProductId, $name, $userId) {
            if ($mode === 'auto') { $this->repo->deletePanelLink($panelProductId); return; }
            if ($mode === 'ignore') { $this->repo->savePanelLink($panelProductId, $name, null, true, $userId); return; }
            $pid = (int) ($input['product_id'] ?? 0);
            if ($pid <= 0 || !$this->repo->productExists($pid)) throw new CustomException('Wybierz nasz produkt z listy.', 'errors', 422);
            $this->repo->savePanelLink($panelProductId, $name, $pid, false, $userId);
        });
        return $this->page($today, $now);
    }

    /** @return list<array{id:int, name:string}> nasze aktywne produkty (połączenie produktu gamy) */
    public function productOptions(): array
    {
        return $this->repo->activeProducts();
    }

    /** @return array{0:int, 1:int} tygodnie prognozy i tygodnie trendu */
    private function windowParams(): array
    {
        return [(int) $this->param('forecast.weeks', '13', 4, 26), (int) $this->param('forecast.trend_weeks', '8', 4, 26)];
    }

    // --- Obliczenie ----------------------------------------------------------------------------------------------

    public function compute(string $today, ?array $panel): array
    {
        $n = (int) $this->param('forecast.weeks', '13', 4, 26);
        $trendWeeks = (int) $this->param('forecast.trend_weeks', '8', 4, 26);
        $start = LogisticsPlanService::monday($today);
        $weeks = [];
        for ($i = 0; $i < $n; $i++) $weeks[] = date('Y-m-d', strtotime($start . " +{$i} weeks"));
        $end = date('Y-m-d', strtotime(end($weeks) . ' +6 days'));
        $index = array_flip($weeks);
        $recent = [];
        for ($i = $trendWeeks; $i >= 1; $i--) $recent[] = date('Y-m-d', strtotime($start . " -{$i} weeks"));

        // Gamy panelu w oknie (tylko produkty z katalogu SL Food), zanim policzymy sieć bez produktów sezonowych.
        $seasons = $this->seasons($panel, $start, $end);
        $seasonal = [];
        foreach ($seasons['list'] as $s) foreach ($s['products'] as $p) if ($p['product_id'] !== null && $p['status'] !== 'ignored') $seasonal[$p['product_id']][] = $s;
        $allYear = [];

        // Historia : kartony na produkt i poniedziałek (od N-1 najstarszego potrzebnego tygodnia).
        $histFrom = min(date('Y-m-d', strtotime(LogisticsPlanService::lastYear($recent[0]) . ' -7 days')), $seasons['n1_from'] ?? $start);
        $by = [];
        $network = [];
        $regular = [];
        foreach ($this->repo->cartonsByDay($histFrom, $end) as $l) {
            $m = LogisticsPlanService::monday($l['ship_date']);
            $by[$l['product_id']][$m] = ($by[$l['product_id']][$m] ?? 0.0) + $l['cartons'];
            $network[$m] = ($network[$m] ?? 0.0) + $l['cartons'];
        }
        // Produkt gamy sezonowej sprzedawany w ostatnich tygodniach poza swoją gamą (w tym i zeszłym roku) jest całoroczny.
        foreach ($seasonal as $pid => $list) {
            $outside = 0.0;
            foreach ($recent as $m) {
                $in = false;
                foreach ($list as $s) {
                    foreach ([[$s['from'], $s['to']], [$s['n1_from'], $s['n1_to']]] as [$a, $b]) if ($a !== null && $m >= LogisticsPlanService::monday($a) && $m <= $b) $in = true;
                }
                if (!$in) $outside += $by[$pid][$m] ?? 0.0;
            }
            if ($outside >= $trendWeeks / 2) { $allYear[$pid] = array_values(array_unique(array_map(fn($s) => $s['name'], $list))); unset($seasonal[$pid]); }
        }
        foreach ($by as $pid => $byWeek) {
            if (isset($seasonal[$pid])) continue;
            foreach ($byWeek as $m => $c) $regular[$m] = ($regular[$m] ?? 0.0) + $c;
        }

        $sales = $panel['sales'] ?? [];
        $trend = $this->trend($recent, $network, $sales);
        $t = $trend['value'] ?? 1.0;
        // Poziom N-1 sieci (bez sezonowych) w tygodniach trendu z danymi → kształt tygodnia N-1 (średnia tygodni N-1 ±1
        // z danymi ; tydzień N-1 z mniej niż 30 % poziomu = brak danych, nie spadek).
        $base = [];
        foreach ($recent as $m) { $v = $regular[LogisticsPlanService::lastYear($m)] ?? 0.0; if ($v > 0) $base[] = $v; }
        $level = $base ? array_sum($base) / count($base) : 0.0;
        $base = array_filter($base, fn($v) => $v >= self::COVERAGE * $level);
        $baseLevel = $base ? array_sum($base) / count($base) : 0.0;
        $shape = [];
        foreach ($weeks as $i => $w) {
            $n1 = LogisticsPlanService::lastYear($w);
            $near = array_filter([$regular[date('Y-m-d', strtotime($n1 . ' -7 days'))] ?? 0.0, $regular[$n1] ?? 0.0, $regular[date('Y-m-d', strtotime($n1 . ' +7 days'))] ?? 0.0],
                fn($v) => $baseLevel > 0 && $v >= self::COVERAGE * $baseLevel);
            $shape[$i] = $near ? max(self::SHAPE_MIN, min(self::SHAPE_MAX, array_sum($near) / count($near) / $baseLevel)) : null;
        }
        $orderedThisWeek = 0.0;
        foreach ($by as $byWeek) $orderedThisWeek += $byWeek[$weeks[0]] ?? 0.0;

        // Produkty : ostatnie tygodnie, zamówienia w oknie, sezonowe.
        $ids = array_keys($seasonal);
        foreach ($by as $pid => $byWeek) {
            foreach ([...$recent, ...$weeks] as $m) if (($byWeek[$m] ?? 0) > 0) { $ids[] = $pid; break; }
        }
        $info = $this->repo->products($ids);
        $hpc = $this->hoursPerCarton();
        $rows = [];
        foreach (array_unique($ids) as $pid) {
            $p = $info[$pid] ?? null;
            if ($p === null) continue;
            $byWeek = $by[$pid] ?? [];
            $avg = array_sum(array_map(fn($m) => $byWeek[$m] ?? 0.0, $recent)) / $trendWeeks;
            $isSeason = isset($seasonal[$pid]);
            $values = [];
            $sources = [];
            $seasonValues = $isSeason ? $this->seasonValues($seasonal[$pid], $byWeek, $weeks, $index, $t, $this->panelCartons($sales, $pid, (int) $p['ppc'])) : null;
            foreach ($weeks as $i => $w) {
                $ordered = (float) ($byWeek[$w] ?? 0.0);
                if ($i === 0 && $orderedThisWeek > 0) { $values[$i] = (int) round($ordered); $sources[$i] = 'orders'; continue; }
                if ($isSeason) { $fc = $seasonValues[$i]; $src = $fc !== null ? 'season' : 'none'; $fc ??= 0.0; }
                elseif ($shape[$i] !== null) { $fc = $avg * $shape[$i]; $src = 'n1'; }
                else { $fc = $avg; $src = 'avg'; }
                if ($ordered > 0 && $ordered >= $fc - self::EPS) { $values[$i] = (int) round($ordered); $sources[$i] = 'orders'; }
                else { $values[$i] = (int) round($fc); $sources[$i] = $values[$i] > 0 ? $src : 'none'; }
            }
            if (!array_sum($values) && !$isSeason) continue;
            $cpp = (int) $p['cpp'];
            if ($cpp === 1 && (int) $p['cpl'] <= 1 && (int) $p['lpp'] <= 1) $cpp = 0; // karta « 1 / 1 / 1 » = brak danych
            $rows[] = [
                'product_id' => $pid, 'item_id' => $p['item_id'] !== null ? (int) $p['item_id'] : null, 'name' => (string) $p['name'], 'category' => $p['category'],
                'active' => (bool) (int) $p['active'], 'mode' => $p['mode'], 'ppc' => (int) $p['ppc'] ?: null, 'cpp' => $cpp ?: null,
                'kind' => $isSeason ? 'season' : 'regular', 'seasons' => $isSeason ? array_values(array_unique(array_map(fn($s) => $s['id'], $seasonal[$pid]))) : [],
                'all_year_in' => $allYear[$pid] ?? null,
                'avg' => round($avg, 1), 'values' => $values, 'sources' => $sources, 'total' => array_sum($values),
                'hours_per_carton' => isset($hpc[$pid]) ? round($hpc[$pid], 4) : null,
            ];
        }
        usort($rows, fn($a, $b) => [$a['kind'] === 'season' ? 1 : 0, -$a['total'], $a['name']] <=> [$b['kind'] === 'season' ? 1 : 0, -$b['total'], $b['name']]);

        // Sumy tygodni : kartony, palety, godziny wobec zdolności (dni pracy bez świąt kraju wyjazdu).
        $dep = strtoupper($this->params->get('logistics.plan_departure_country', 'PL'));
        $holidays = Holidays::between($start, $end, [$dep]);
        $capacity = $this->capacity();
        // Średnio godzin na karton (produkty z czasem partii / marszrutą, ważone kartonami 13 tygodni) — szacunek dla pozostałych.
        $known = array_filter($rows, fn($r) => $r['hours_per_carton'] !== null && $r['total'] > 0);
        $knownCartons = array_sum(array_column($known, 'total'));
        $avgHours = $knownCartons > 0 ? array_sum(array_map(fn($r) => $r['total'] * $r['hours_per_carton'], $known)) / $knownCartons : 0.0;
        $weekRows = [];
        foreach ($weeks as $i => $w) {
            $off = [];
            for ($d = 0; $d < 5; $d++) { $day = date('Y-m-d', strtotime($w . " +{$d} days")); if (isset($holidays[$day][$dep])) $off[] = ['date' => $day, 'name' => $holidays[$day][$dep]]; }
            $c = 0; $sc = 0; $pal = 0.0; $h = 0.0; $est = 0.0; $noCpp = 0; $noHours = 0;
            foreach ($rows as $r) {
                $v = $r['values'][$i];
                if ($v <= 0) continue;
                $c += $v;
                if ($r['kind'] === 'season') $sc += $v;
                if ($r['cpp']) $pal += $v / $r['cpp']; else $noCpp++;
                if ($r['hours_per_carton'] !== null) $h += $v * $r['hours_per_carton'];
                else { $noHours++; $est += $v * $avgHours; }
            }
            $h += $est;
            $cap = $capacity['hours'] * (5 - count($off)) / 5;
            $weekRows[] = ['week_start' => $w, 'iso_week' => (int) date('W', strtotime($w)), 'iso_year' => (int) date('o', strtotime($w)), 'n1_week' => LogisticsPlanService::lastYear($w),
                'cartons' => $c, 'season_cartons' => $sc, 'pallets' => round($pal, 1), 'hours' => round($h, 1), 'capacity' => round($cap, 1),
                'estimated_hours' => round($est, 1), 'load_pct' => $cap > 0 ? (int) round($h / $cap * 100) : null, 'off' => $off,
                'shape' => $shape[$i] !== null ? round($shape[$i], 2) : null, 'no_cpp' => $noCpp, 'no_hours' => $noHours];
        }
        $seasonsOut = $this->presentSeasons($seasons['list'], $rows, $by, $weeks, $t, $sales);
        $purchases = $this->purchases($rows, $weeks, $today, $seasonal);
        // Szczyt i tygodnie ponad zdolność : bez tygodnia bieżącego (produkcja w toku, zamówienia już wysłane).
        $ahead = array_slice(array_keys($weekRows), 1, null);
        $peak = $ahead ? array_reduce($ahead, fn($m, $i) => $m === null || ($weekRows[$i]['load_pct'] ?? -1) > ($weekRows[$m]['load_pct'] ?? -1) ? $i : $m) : null;
        $totalHours = round(array_sum(array_column($weekRows, 'hours')), 1);
        return [
            'today' => $today, 'weeks' => $weekRows, 'products' => $rows, 'trend' => $trend + ['weeks' => $trendWeeks], 'capacity' => $capacity,
            'seasons' => $seasonsOut, 'panel_products' => $seasons['unlinked'], 'purchases' => $purchases,
            'totals' => ['cartons' => (int) array_sum(array_column($weekRows, 'cartons')), 'season_cartons' => (int) array_sum(array_column($weekRows, 'season_cartons')),
                'pallets' => round(array_sum(array_column($weekRows, 'pallets')), 1), 'hours' => $totalHours,
                'peak_week' => $peak !== null ? $weekRows[$peak]['iso_week'] : null, 'peak_pct' => $peak !== null ? $weekRows[$peak]['load_pct'] : null,
                'peak_hours' => $peak !== null ? $weekRows[$peak]['hours'] : null, 'peak_capacity' => $peak !== null ? $weekRows[$peak]['capacity'] : null,
                'over' => array_values(array_map(fn($i) => $weekRows[$i]['iso_week'], array_filter($ahead, fn($i) => $weekRows[$i]['load_pct'] !== null && $weekRows[$i]['load_pct'] > 100))),
                'products' => count($rows), 'season_products' => count(array_filter($rows, fn($r) => $r['kind'] === 'season')),
                'no_hours' => count(array_filter($rows, fn($r) => $r['hours_per_carton'] === null)), 'no_cpp' => count(array_filter($rows, fn($r) => !$r['cpp'])),
                'hours_known_cartons' => (int) $knownCartons, 'hours_avg_per_carton' => round($avgHours, 4),
                'estimated_hours' => round(array_sum(array_column($weekRows, 'estimated_hours')), 1)],
            'params' => ['weeks' => $n, 'trend_weeks' => $trendWeeks, 'from' => $start, 'to' => $end, 'departure_country' => $dep,
                'orders_this_week' => (int) round($orderedThisWeek), 'season_max_days' => PanelSeasonService::SEASON_MAX_DAYS],
        ];
    }

    /**
     * Trend sieci : ostatnie tygodnie ÷ te same tygodnie rok temu, tylko tygodnie N-1 z danymi (≥ 30 % średniej N-1) —
     * ze sprzedaży naszych produktów we wszystkich sklepach (panel), gdy panel ma większość tygodni, inaczej z wysyłek ERP.
     */
    private function trend(array $recent, array $network, array $sales = []): array
    {
        $panelPairs = 0;
        foreach ($recent as $m) if (isset($sales[$m], $sales[LogisticsPlanService::lastYear($m)])) $panelPairs++;
        $source = $panelPairs * 2 >= count($recent) ? 'panel' : 'erp';
        $pairs = [];
        foreach ($recent as $m) {
            $n1 = LogisticsPlanService::lastYear($m);
            $pairs[] = $source === 'panel'
                ? [$m, isset($sales[$m], $sales[$n1]) ? (float) $sales[$m]['total'] : 0.0, isset($sales[$m], $sales[$n1]) ? (float) $sales[$n1]['total'] : 0.0]
                : [$m, $network[$m] ?? 0.0, $network[$n1] ?? 0.0];
        }
        $nonZero = array_filter(array_column($pairs, 2), fn($v) => $v > 0);
        $level = $nonZero ? array_sum($nonZero) / count($nonZero) : 0.0;
        $used = array_values(array_filter($pairs, fn($p) => $level > 0 && $p[2] >= self::COVERAGE * $level));
        $now = array_sum(array_column($used, 1));
        $last = array_sum(array_column($used, 2));
        $min = $this->param('forecast.trend_min', '0.5', 0.1, 1);
        $max = $this->param('forecast.trend_max', '2', 1, 5);
        $raw = $last > 0 ? $now / $last : null;
        return ['value' => $raw !== null ? round(max($min, min($max, $raw)), 3) : null, 'raw' => $raw !== null ? round($raw, 3) : null, 'source' => $source,
            'unit' => $source === 'panel' ? 'szt.' : 'kart.',
            'now' => (int) round($now), 'last' => (int) round($last), 'used' => count($used), 'skipped' => array_values(array_map(fn($p) => (int) date('W', strtotime($p[0])),
                array_filter($pairs, fn($p) => !in_array($p, $used, true)))), 'min' => $min, 'max' => $max];
    }

    /**
     * Gamy sezonowe w oknie : wystąpienia, produkty sklepu → nasze produkty (połączenie ręczne, podpowiedź pewna z nazwy,
     * pominięte, bez pary). Produkty dostępne cały rok (gamy całoroczne) nie są sezonowe.
     * @return array{list:list<array>, unlinked:list<array>, n1_from:?string}
     */
    private function seasons(?array $panel, string $from, string $to): array
    {
        if ($panel === null) return ['list' => [], 'unlinked' => [], 'n1_from' => null];
        $catalog = array_map('intval', $panel['catalog'] ?? []);
        $always = array_flip(array_map('intval', $panel['always'] ?? []));
        $links = $this->repo->panelLinks();
        $candidates = [];
        $ours = $this->repo->products($catalog);
        foreach ($ours as $id => $p) $candidates[] = ['id' => (int) $id, 'name' => (string) $p['name'], 'active' => (bool) (int) $p['active'], 'tokens' => SeasonMatcher::tokens((string) $p['name'])];
        $names = [];
        foreach ($links as $l) if ($l['id_final_product'] !== null) $names[$l['id_final_product']] = true;
        $linked = $this->repo->products(array_keys($names));
        $list = [];
        $unlinked = [];
        $n1From = null;
        $color = 0;
        foreach ($panel['periods'] ?? [] as $period) {
            if (($period['kind'] ?? '') !== 'season') continue;
            $occ = SeasonMatcher::occurrences($period, $from, $to);
            if (!$occ) continue;
            $o = $occ[0];
            if ($o['n1_from'] !== null) $n1From = $n1From === null ? $o['n1_from'] : min($n1From, $o['n1_from']);
            $products = [];
            foreach ($period['products'] ?? [] as $panelId) {
                $panelId = (int) $panelId;
                if (isset($always[$panelId])) continue;
                $name = (string) ($panel['products'][$panelId]['name'] ?? $panel['products'][(string) $panelId]['name'] ?? ('#' . $panelId));
                $link = $links[$panelId] ?? null;
                $hints = SeasonMatcher::best($name, $candidates);
                if ($link !== null && $link['ignored']) { $status = 'ignored'; $pid = null; }
                elseif ($link !== null && $link['id_final_product'] !== null) { $status = 'linked'; $pid = $link['id_final_product']; }
                elseif ($hints && $hints[0]['score'] >= SeasonMatcher::SURE) { $status = 'auto'; $pid = $hints[0]['id']; }
                else { $status = 'none'; $pid = null; }
                $erp = $pid !== null ? ($ours[$pid] ?? $linked[$pid] ?? null) : null;
                $row = ['panel_id' => $panelId, 'panel_name' => $name, 'status' => $status, 'product_id' => $pid, 'product_name' => $erp['name'] ?? null,
                    'product_active' => $erp !== null ? (bool) (int) $erp['active'] : null, 'score' => $status === 'auto' ? $hints[0]['score'] : null, 'hints' => $hints];
                $products[] = $row;
                if ($status === 'none') $unlinked[$panelId] = $row + ['season' => trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) $period['name']))];
            }
            $list[] = ['id' => (int) $period['id'], 'name' => trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) $period['name'])) ?: (string) $period['name'], 'color' => self::COLORS[$color++ % count(self::COLORS)],
                'from' => $o['from'], 'to' => $o['to'], 'n1_from' => $o['n1_from'], 'n1_to' => $o['n1_to'], 'recurring' => (bool) $period['is_recurring'], 'products' => $products];
        }
        return ['list' => $list, 'unlinked' => array_values($unlinked), 'n1_from' => $n1From];
    }

    /** Sprzedaż produktu w sklepach (panel) w kartonach na poniedziałek ; tylko tygodnie odczytane. @return array<string, float> */
    private function panelCartons(array $sales, int $pid, int $ppc): array
    {
        $out = [];
        if ($ppc <= 0) return $out;
        foreach ($sales as $m => $w) $out[(string) $m] = (float) ($w['skus'][(string) $pid] ?? 0.0) / $ppc;
        return $out;
    }

    /**
     * Kartony produktu sezonowego na tygodnie okna : tydzień k gamy N-1 → tydzień k gamy w tym roku, × trend (kilka gam :
     * większa wartość) ; N-1 = sprzedaż w sklepach z panelu (sztuki ÷ sztuk w kartonie), a gdy panel nie ma tego tygodnia —
     * wysyłki z ERP ; tydzień poza gamą : null.
     * @return list<?float>
     */
    private function seasonValues(array $seasons, array $byWeek, array $weeks, array $index, float $trend, array $panel = []): array
    {
        $out = array_fill(0, count($weeks), null);
        foreach ($seasons as $s) {
            $first = LogisticsPlanService::monday($s['from']);
            $last = LogisticsPlanService::monday($s['to']);
            $n1 = $s['n1_from'] !== null ? LogisticsPlanService::monday($s['n1_from']) : null;
            for ($k = 0; ($m = date('Y-m-d', strtotime($first . " +{$k} weeks"))) <= $last; $k++) {
                if (!isset($index[$m])) continue;
                $i = $index[$m];
                $n1m = $n1 !== null ? date('Y-m-d', strtotime($n1 . " +{$k} weeks")) : null;
                $v = $n1m !== null ? (float) ($panel[$n1m] ?? $byWeek[$n1m] ?? 0.0) * $trend : 0.0;
                $out[$i] = max($out[$i] ?? 0.0, $v);
            }
        }
        return $out;
    }

    /** Karty gam (makieta 2) : produkty, sprzedane w sklepach N-1 (panel), kartony N-1, wysłane N-1 (ERP), prognoza, tygodnie. */
    private function presentSeasons(array $list, array $rows, array $by, array $weeks, float $trend, array $sales = []): array
    {
        $rowById = [];
        foreach ($rows as $r) $rowById[$r['product_id']] = $r;
        $out = [];
        foreach ($list as $s) {
            $weekIdx = [];
            foreach ($weeks as $i => $w) if ($w >= LogisticsPlanService::monday($s['from']) && $w <= LogisticsPlanService::monday($s['to'])) $weekIdx[] = $i;
            $products = [];
            $sum = 0;
            $n1Total = 0.0;
            foreach ($s['products'] as $p) {
                $r = $p['product_id'] !== null ? ($rowById[$p['product_id']] ?? null) : null;
                $n1 = null;
                $shipped = null;
                $pieces = null;
                $read = 0;
                $total = 0;
                if ($p['product_id'] !== null && $s['n1_from'] !== null) {
                    $shipped = 0.0;
                    $ppc = (int) ($r['ppc'] ?? 0);
                    $n1 = 0.0;
                    for ($m = LogisticsPlanService::monday($s['n1_from']); $m <= $s['n1_to']; $m = date('Y-m-d', strtotime($m . ' +7 days'))) {
                        $total++;
                        $erp = (float) ($by[$p['product_id']][$m] ?? 0.0);
                        $shipped += $erp;
                        if (isset($sales[$m])) {
                            $read++;
                            $q = (float) ($sales[$m]['skus'][(string) $p['product_id']] ?? 0.0);
                            $pieces = ($pieces ?? 0.0) + $q;
                            $n1 += $ppc > 0 ? $q / $ppc : 0.0;
                        } else $n1 += $erp;
                    }
                }
                $fc = $r ? array_sum(array_map(fn($i) => $r['values'][$i], $weekIdx)) : null;
                if ($p['status'] === 'linked' || $p['status'] === 'auto') { $sum += (int) $fc; $n1Total += (float) $n1; }
                $products[] = $p + ['n1_cartons' => $n1 !== null ? (int) round($n1) : null, 'n1_pieces' => $pieces !== null ? (int) round($pieces) : null,
                    'n1_shipped' => $shipped !== null ? (int) round($shipped) : null, 'n1_weeks_read' => $read, 'n1_weeks' => $total, 'forecast' => $fc, 'ppc' => $r['ppc'] ?? null,
                    'weeks' => $r ? array_map(fn($i) => $r['values'][$i], $weekIdx) : []];
            }
            usort($products, fn($a, $b) => [self::rank($a['status']), -($a['forecast'] ?? 0), $a['panel_name']] <=> [self::rank($b['status']), -($b['forecast'] ?? 0), $b['panel_name']]);
            $out[] = ['id' => $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'from' => $s['from'], 'to' => $s['to'], 'n1_from' => $s['n1_from'], 'n1_to' => $s['n1_to'],
                'week_index' => $weekIdx, 'cartons' => $sum, 'n1_cartons' => (int) round($n1Total), 'trend' => $trend, 'products' => $products,
                'ours' => count(array_filter($products, fn($p) => in_array($p['status'], ['linked', 'auto'], true))),
                'unlinked' => count(array_filter($products, fn($p) => $p['status'] === 'none')), 'ignored' => count(array_filter($products, fn($p) => $p['status'] === 'ignored'))];
        }
        usort($out, fn($a, $b) => [$a['from'], $a['name']] <=> [$b['from'], $b['name']]);
        return $out;
    }

    private static function rank(string $status): int
    {
        return ['linked' => 0, 'auto' => 0, 'none' => 1, 'ignored' => 2][$status] ?? 3;
    }

    /**
     * Zakupy 13 tygodni (makieta 3) : surowce z receptur (na karton) × kartony tygodni, stan (inwentaryzacja + przyjęcia −
     * zużycie), w drodze, brak od tygodnia, « zamówić do » = poniedziałek tygodnia braku − czas dostawy, do kupienia w
     * pełnych opakowaniach (na stanie z min. / maks. — do maks.), wartość. Surowiec tylko produktów sezonowych : « sezon ».
     */
    private function purchases(array $rows, array $weeks, string $today, array $seasonal): array
    {
        $recipes = $this->supply->recipes();
        $n = count($weeks);
        $need = [];
        $usedBy = [];
        $noRecipe = [];
        foreach ($rows as $r) {
            if (!array_sum($r['values'])) continue;
            if ($r['item_id'] === null || !isset($recipes[$r['item_id']])) { $noRecipe[] = $r['name']; continue; }
            foreach ($recipes[$r['item_id']] as $ing) {
                $mid = $ing['material_id'];
                foreach ($r['values'] as $i => $v) if ($v > 0) $need[$mid][$i] = ($need[$mid][$i] ?? 0.0) + $v * $ing['quantity'];
                $usedBy[$mid][$r['product_id']] = $r['kind'] === 'season';
            }
        }
        $levels = $this->levels();
        $onOrder = $this->supply->onOrder();
        $lead = $this->repo->leadTimes();
        $defaultLead = (int) $this->param('forecast.lead_days', '7', 0, 90);
        $out = [];
        foreach ($this->supply->materials(array_keys($need)) as $m) {
            $id = (int) $m['id'];
            $weekly = array_map(fn($i) => round($need[$id][$i] ?? 0.0, 3), range(0, $n - 1));
            $total = array_sum($weekly);
            if ($total <= self::EPS) continue;
            $stock = $levels[$id]['stock'] ?? null;
            $ordered = (float) ($onOrder[$id] ?? 0.0);
            $have = max(0.0, $stock ?? 0.0) + $ordered;
            $short = null;
            $cum = 0.0;
            foreach ($weekly as $i => $v) { $cum += $v; if ($cum > $have + self::EPS) { $short = $i; break; } }
            $after = $have - $total;
            $max = $m['stock_max'] !== null ? (float) $m['stock_max'] : null;
            $qty = $m['supply_mode'] === 'stock' && $max !== null ? ($after < (float) ($m['stock_min'] ?? 0) - self::EPS ? $max - $after : 0.0) : max(0.0, -$after);
            $pack = (float) ($m['package_size'] ?? 0);
            $packages = null;
            if ($qty > self::EPS && $pack > 0) { $packages = (int) ceil(round($qty / $pack, 6)); $qty = $packages * $pack; }
            $days = $lead[$id] ?? null;
            $leadDays = $days ?? $defaultLead;
            $orderBy = $short !== null ? date('Y-m-d', strtotime($weeks[$short] . " -{$leadDays} days")) : null;
            $price = $m['price_per_unit'] !== null && (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null;
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $out[] = [
                'id' => $id, 'name' => (string) $m['name'], 'category' => $m['category'], 'packaging' => (bool) $m['packaging'],
                'unit' => $unit['label'], 'factor' => $unit['factor'], 'supplier' => $m['supplier_name'] !== null ? trim((string) $m['supplier_name']) : null,
                'need_weeks' => $weekly, 'need' => round($total, 3), 'stock' => $stock, 'stock_basis' => $levels[$id]['basis'] ?? null, 'on_order' => round($ordered, 3),
                'short_week' => $short, 'order_by' => $orderBy,
                'urgency' => $stock === null && $ordered <= 0 ? 'unknown' : ($orderBy === null ? 'ok' : ($orderBy <= $today ? 'now' : ($orderBy <= date('Y-m-d', strtotime($today . ' +7 days')) ? 'soon' : 'later'))),
                'lead_days' => $leadDays, 'lead_known' => $days !== null, 'to_buy' => round($qty, 3), 'packages' => $packages, 'price' => $price,
                'value' => $price !== null ? round($qty * $price, 2) : null, 'season' => !in_array(false, $usedBy[$id] ?? [false], true), 'products' => count($usedBy[$id] ?? []),
            ];
        }
        usort($out, fn($a, $b) => [['now' => 0, 'soon' => 1, 'later' => 2, 'unknown' => 3, 'ok' => 4][$a['urgency']], $a['order_by'] ?? '9', -($a['value'] ?? 0), $a['name']]
            <=> [['now' => 0, 'soon' => 1, 'later' => 2, 'unknown' => 3, 'ok' => 4][$b['urgency']], $b['order_by'] ?? '9', -($b['value'] ?? 0), $b['name']]);
        $buy = array_filter($out, fn($r) => $r['to_buy'] > 0);
        $peakWeek = null;
        $peakValue = 0.0;
        foreach ($weeks as $i => $w) {
            $v = 0.0;
            foreach ($out as $r) if ($r['price'] !== null) $v += $r['need_weeks'][$i] * $r['price'];
            if ($v > $peakValue) { $peakValue = $v; $peakWeek = $i; }
        }
        return ['rows' => $out, 'no_recipe' => array_values(array_unique($noRecipe)),
            'totals' => ['materials' => count($out), 'to_buy' => count($buy), 'value' => round(array_sum(array_map(fn($r) => (float) $r['value'], $buy)), 2),
                'season_value' => round(array_sum(array_map(fn($r) => (float) $r['value'], array_filter($buy, fn($r) => $r['season']))), 2),
                'season' => count(array_filter($out, fn($r) => $r['season'])), 'now' => count(array_filter($out, fn($r) => in_array($r['urgency'], ['now', 'soon'], true) && $r['to_buy'] > 0)),
                'no_price' => count(array_filter($buy, fn($r) => $r['price'] === null)), 'no_supplier' => count(array_filter($buy, fn($r) => $r['supplier'] === null)),
                'unknown_stock' => count(array_filter($out, fn($r) => $r['urgency'] === 'unknown')),
                'peak_week' => $peakWeek, 'peak_value' => round($peakValue, 2)]];
    }

    /** Stan teoretyczny surowców — ta sama reguła co Zaopatrzenie (inwentaryzacja + przyjęcia − zużycie z produkcji). */
    private function levels(): array
    {
        $counts = $this->supply->lastCounts();
        $in = $this->supply->receivedSinceCount();
        $out = $this->supply->consumedSinceCount();
        $levels = [];
        foreach ($counts as $id => $c) $levels[$id] = ['stock' => round($c['quantity'] + ($in[$id] ?? 0.0) - ($out[$id] ?? 0.0), 3), 'basis' => 'count'];
        foreach ($in as $id => $qty) if (!isset($levels[$id])) $levels[$id] = ['stock' => round($qty - ($out[$id] ?? 0.0), 3), 'basis' => 'receipts'];
        return $levels;
    }

    /** Godziny pracy na karton : partia z arkusza (sztuki partii, osoby × minuty), inaczej marszruta karty. @return array<int, float> */
    private function hoursPerCarton(): array
    {
        $routings = $this->planning->routings();
        $out = [];
        foreach ($this->planning->items() as $it) {
            if ($it['item_type'] !== 'product' || $it['id_final_product'] === null) continue;
            $ppc = (float) ($it['ppc'] ?? 0);
            if ($ppc <= 0) continue;
            $bh = PlanningService::batchHours($it);
            if ($bh !== null) $h = $ppc / $bh[0] * $bh[1];
            elseif (!empty($routings[(int) $it['id']])) $h = StandardTime::routing($routings[(int) $it['id']], $ppc)['totals']['labour_s'] / 3600;
            else continue;
            if ($h > 0) $out[(int) $it['id_final_product']] = $h;
        }
        return $out;
    }

    /** Zdolność tygodnia (h) : parametr albo aktywni pracownicy produkcji × godziny etatu. */
    private function capacity(): array
    {
        $fixed = $this->param('forecast.capacity_hours', '0', 0, 100000);
        $fte = $this->param('week.fte_hours', '40', 1, 80);
        $people = $this->repo->activeEmployees();
        return $fixed > 0 ? ['hours' => $fixed, 'source' => 'param', 'people' => $people, 'fte_hours' => $fte]
            : ['hours' => $people * $fte, 'source' => 'people', 'people' => $people, 'fte_hours' => $fte];
    }

    private function param(string $code, string $default, float $min, float $max): float
    {
        $v = (float) str_replace(',', '.', $this->params->get($code, $default));
        return max($min, min($max, $v));
    }
}
