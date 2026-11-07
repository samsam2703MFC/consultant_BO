<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsPlanRepository;
use App\Repositories\Stock\StockPeriodRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\LogisticsPlanService;

/**
 * Zapas potrzebny wg planu logistyki [216] (Magazyn › Prognoza zapasu, makieta 1) — produkty MTS, tygodnie planu 12 tygodni :
 *  - wyjazd (dzień z planu, zmieniany kalendarzem), powrót ciężarówki (trasa albo dostawa + dni drogi, + dni blokady po drodze)
 *    i dni pracy produkcji (pn–pt bez świąt i dni wolnych kraju wyjazdu : święta ustawowe, zamknięcie zakładu) ;
 *  - kartony produktu na wyjazd : tydzień z zamówieniami — zamówione kartony ; inaczej palety klienta z planu × jego kartony na
 *    paletę × udział produktu w jego kartonach (okno średniej planu, logistics.plan_avg_weeks) ;
 *  - zapas potrzebny przed wyjazdem = kartony wyjazdu × (1 + stock.plan_safety_pct) + kartony kolejnych wyjazdów w tygodniach
 *    bez produkcji (robione wcześniej) ; tydzień bez produkcji : wyjazd z zapasu tygodnia przed ;
 *  - projekcja od zapasu dziś : produkcja tygodnia = zapas potrzebny − zapas po poprzednim wyjeździe (nie mniej niż 0, nic w
 *    tygodniu bez produkcji), zapas przed wyjazdem = zapas + produkcja, po wyjeździe − kartony wyjazdu ; w paletach (kartony ÷
 *    kartony na paletę) razem z paletami pozostałych produktów w magazynie (zamrażarka, stock.freezer_pallet_places).
 * Stany okresu wyjątkowego z prognozy : min. = średnio kartonów na wyjazd w okresie × stock.suggest_min_weeks, maks. = szczyt
 * zapasu potrzebnego w okresie (nie mniej niż min.).
 */
final class StockForecastService
{
    public function __construct(
        private LogisticsPlanService $plans,
        private LogisticsPlanRepository $planRepo,
        private StockPeriodRepository $repo,
        private ParamRepository $params,
    ) {
    }

    public function forecast(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $plan = $this->plans->plan(null, $today);
        $dep = (string) $plan['params']['departure_country'];
        $avgWeeks = max(1, (int) $plan['params']['avg_weeks']);
        $safety = max(0.0, (float) $this->params->get('stock.plan_safety_pct', '25')) / 100;

        $holidays = [];
        foreach ($plan['weeks'] as $w) foreach ($w['holidays'] as $h) $holidays[$h['date']][$h['country']] = $h['name'];
        $home = [];
        foreach ($plan['trips'] ?? [] as $t) if (!empty($t['home_at'])) $home[$t['week_start']] = max($home[$t['week_start']] ?? '', substr($t['home_at'], 0, 10));
        $weeks = [];
        foreach ($plan['weeks'] as $w) {
            $off = [];
            foreach ($w['holidays'] as $h) if ($h['country'] === $dep && (int) date('N', strtotime($h['date'])) <= 5) $off[$h['date']] = $h['name'];
            [$return, $blocked] = self::returnDay($w, $home[$w['week_start']] ?? null, (int) $plan['params']['delivery_days'], $holidays, $dep, $plan['params']['transit_countries'] ?? []);
            $weeks[] = ['week_start' => $w['week_start'], 'iso_week' => $w['iso_week'], 'ship_date' => $w['ship_date'], 'ship_default' => $w['ship_default'], 'ship_manual' => $w['ship_manual'],
                'delivery_date' => $w['delivery_date'], 'return_date' => $return, 'return_blocked' => $blocked, 'return_from_trip' => isset($home[$w['week_start']]),
                'working_days' => 5 - count($off),
                'off' => array_values(array_map(fn($d, $n) => ['date' => $d, 'name' => $n], array_keys($off), $off)), 'approved_at' => $w['approved_at'], 'pallets' => 0];
        }
        $index = array_flip(array_column($weeks, 'week_start'));
        $current = self::monday($today);
        $histFrom = date('Y-m-d', strtotime($current . " -{$avgWeeks} weeks"));
        $last = end($weeks)['week_start'];

        // Koszyk klienta (okno średniej planu) i zamówienia w tygodniach planu, kartony na produkt.
        $mix = [];
        $ordered = [];
        foreach ($this->planRepo->orderLines($histFrom, date('Y-m-d', strtotime($last . ' +6 days'))) as $l) {
            $m = self::monday($l['ship_date']);
            $cid = (int) $l['id_customer'];
            $pid = (int) $l['product_id'];
            if ($m >= $histFrom && $m < $current) $mix[$cid][$pid] = ($mix[$cid][$pid] ?? 0) + (float) $l['cartons'];
            elseif (isset($index[$m])) $ordered[$cid][$m][$pid] = ($ordered[$cid][$m][$pid] ?? 0) + (float) $l['cartons'];
        }
        $products = $this->repo->mtsProducts();
        $dem = [];
        foreach ($plan['customers'] as $c) {
            $cid = (int) $c['id'];
            $histCartons = array_sum($mix[$cid] ?? []);
            $histPallets = (float) $c['avg'] * $avgWeeks;
            $perPallet = $histPallets > 0 ? $histCartons / $histPallets : 0.0;
            foreach ($c['cells'] as $ws => $cell) {
                if (!isset($index[$ws])) continue;
                $i = $index[$ws];
                $weeks[$i]['pallets'] += (int) $cell['pallets'];
                if ($cell['source'] === 'orders') {
                    foreach ($ordered[$cid][$ws] ?? [] as $pid => $n) if (isset($products[$pid])) $dem[$pid][$i] = ($dem[$pid][$i] ?? 0) + $n;
                } elseif ($cell['pallets'] > 0 && $perPallet > 0) {
                    $total = $cell['pallets'] * $perPallet;
                    foreach ($mix[$cid] as $pid => $n) if (isset($products[$pid])) $dem[$pid][$i] = ($dem[$pid][$i] ?? 0) + $total * $n / $histCartons;
                }
            }
        }

        $n = count($weeks);
        $rows = [];
        $needWeek = array_fill(0, $n, 0);
        $stockNow = $this->repo->stockCartons();
        $prodPallets = array_fill(0, $n, 0.0);
        $stockPallets = array_fill(0, $n, 0.0);
        $nowPallets = 0.0;
        foreach ($dem as $pid => $byWeek) {
            $p = $products[$pid];
            $ppc = max(1, (int) $p['ppc']);
            $d = [];
            for ($i = 0; $i < $n; $i++) $d[$i] = (int) ceil(round($byWeek[$i] ?? 0, 6));
            if (!array_sum($d)) continue;
            $need = [];
            for ($i = 0; $i < $n; $i++) {
                if ($weeks[$i]['working_days'] === 0) { $need[$i] = null; continue; }
                $v = $d[$i] * (1 + $safety);
                for ($j = $i + 1; $j < $n && $weeks[$j]['working_days'] === 0; $j++) $v += $d[$j];
                $need[$i] = (int) ceil(round($v, 6));
                $needWeek[$i] += $need[$i];
            }
            $peak = max(array_map(fn($v) => $v ?? 0, $need));
            $cpp = (int) $p['cpp'];
            [$stock, $prod, $before] = [$stockNow[$pid] ?? 0, [], []];
            if ($cpp > 0) $nowPallets += $stock / $cpp;
            for ($i = 0; $i < $n; $i++) {
                $prod[$i] = $need[$i] === null ? 0 : max(0, $need[$i] - $stock);
                $before[$i] = $stock + $prod[$i];
                $stock = max(0, $before[$i] - $d[$i]);
                if ($cpp > 0) { $prodPallets[$i] += $prod[$i] / $cpp; $stockPallets[$i] += $before[$i] / $cpp; }
            }
            $carton = fn($q) => $q === null ? null : round((float) $q / $ppc, 1);
            $rows[] = [
                'item_id' => (int) $p['item_id'], 'product_id' => $pid, 'name' => $p['name'], 'ppc' => $ppc, 'cpp' => (int) $p['cpp'] ?: null,
                'cost_eur' => $p['cost_eur'] !== null && (float) $p['cost_eur'] > 0 ? round((float) $p['cost_eur'], 2) : null,
                'min' => $carton($p['stock_min']), 'max' => $carton($p['stock_max']),
                'normal_min' => $carton($p['normal_stock_min']), 'normal_max' => $carton($p['normal_stock_max']),
                'period' => $p['stock_period'], 'period_to' => $p['stock_period_to'],
                'stock_now' => $stockNow[$pid] ?? 0, 'demand' => $d, 'need' => $need, 'prod' => $prod, 'stock' => $before, 'peak' => $peak, 'peak_week' => $weeks[array_search($peak, $need, true)]['iso_week'] ?? null,
            ];
        }
        usort($rows, fn($a, $b) => [$b['peak'], $a['name']] <=> [$a['peak'], $b['name']]);
        $peak = $needWeek ? max($needWeek) : 0;
        $peakIndex = $peak > 0 ? array_search($peak, $needWeek, true) : null;
        $maxNow = (int) round(array_sum(array_map(fn($r) => (float) ($r['max'] ?? 0), $rows)));
        $approved = array_values(array_filter($weeks, fn($w) => $w['approved_at'] !== null));
        // Palety : produkcja tygodnia i zapas przed wyjazdem (z paletami innych produktów, które zostają w magazynie).
        $all = round($this->repo->stockPallets(), 1);
        $other = max(0.0, $all - $nowPallets);
        $places = (int) $this->params->get('stock.freezer_pallet_places', '0');
        $pallets = ['now' => $all, 'other' => round($other, 1), 'places' => $places,
            'prod' => array_map(fn($v) => round($v, 1), $prodPallets), 'stock' => array_map(fn($v) => round($v + $other, 1), $stockPallets)];
        $pallets['prod_total'] = round(array_sum($prodPallets), 1);
        $pallets['stock_peak'] = $n ? max($pallets['stock']) : 0.0;
        $pallets['stock_peak_week'] = $n ? $weeks[array_search($pallets['stock_peak'], $pallets['stock'], true)]['iso_week'] : null;
        $pallets['over'] = $places > 0 ? array_values(array_map(fn($i) => $weeks[$i]['iso_week'], array_keys(array_filter($pallets['stock'], fn($v) => $v > $places)))) : [];
        return [
            'weeks' => $weeks, 'products' => $rows, 'need' => $needWeek, 'pallets' => $pallets,
            'totals' => ['peak' => $peak, 'peak_week' => $peakIndex !== null ? $weeks[$peakIndex]['iso_week'] : null, 'peak_ship' => $peakIndex !== null ? $weeks[$peakIndex]['ship_date'] : null,
                'peak_pallets' => $peakIndex !== null ? round(array_sum(array_map(fn($r) => $r['cpp'] && $r['need'][$peakIndex] !== null ? $r['need'][$peakIndex] / $r['cpp'] : 0, $rows)), 1) : 0.0,
                'max_now' => $maxNow, 'missing' => max(0, $peak - $maxNow), 'working_days' => array_sum(array_column($weeks, 'working_days')), 'days' => 5 * $n,
                'off' => array_merge([], ...array_column($weeks, 'off'))],
            'approved' => ['weeks' => count($approved), 'all' => count($approved) === $n, 'last_at' => $approved ? max(array_column($approved, 'approved_at')) : null],
            'params' => ['safety_pct' => round($safety * 100, 1), 'avg_weeks' => $avgWeeks, 'departure_country' => $dep, 'from' => $weeks[0]['week_start'] ?? null,
                'to' => $n ? date('Y-m-d', strtotime($last . ' +6 days')) : null],
        ];
    }

    /**
     * Stany okresu [od, do] z prognozy : produkty z kartonami w tygodniach okresu (tygodnie planu, które go przecinają).
     * @return array{levels: array<int, array{item_id:int, avg:float, peak:float, min:int, max:int, peak_week:?int, peak_days:?int}>, weeks: list<int>, beyond: bool}
     */
    public function periodLevels(string $from, string $to, ?array $forecast = null): array
    {
        $f = $forecast ?? $this->forecast();
        $idx = [];
        foreach ($f['weeks'] as $i => $w) if ($w['week_start'] <= $to && date('Y-m-d', strtotime($w['week_start'] . ' +6 days')) >= $from) $idx[] = $i;
        if (!$idx) {
            throw new CustomException('Okres poza planem logistyki (' . date('d.m', strtotime($f['params']['from'])) . ' – ' . date('d.m.Y', strtotime($f['params']['to']))
                . ') : brak prognozy. Wybierz daty w planie 12 tygodni.', 'errors', 422);
        }
        $minWeeks = max(0.0, (float) $this->params->get('stock.suggest_min_weeks', '1'));
        $levels = [];
        foreach ($f['products'] as $p) {
            $d = array_map(fn($i) => $p['demand'][$i], $idx);
            if (max($d) <= 0) continue;
            $peak = 0;
            $peakAt = null;
            foreach ($idx as $i) if (($p['need'][$i] ?? 0) > $peak) { $peak = $p['need'][$i]; $peakAt = $i; }
            $avg = array_sum($d) / count($d);
            $min = (int) ceil(round($avg * $minWeeks, 6));
            $levels[$p['item_id']] = ['item_id' => $p['item_id'], 'avg' => round($avg, 1), 'peak' => (float) $peak, 'min' => $min, 'max' => max($min, (int) $peak),
                'peak_week' => $peakAt !== null ? $f['weeks'][$peakAt]['iso_week'] : null, 'peak_days' => $peakAt !== null ? $f['weeks'][$peakAt]['working_days'] : null,
                'peak_index' => $peakAt];
        }
        return ['levels' => $levels, 'weeks' => array_map(fn($i) => $f['weeks'][$i]['iso_week'], $idx), 'indexes' => $idx, 'beyond' => $to > $f['params']['to'],
            'unapproved' => array_values(array_map(fn($i) => $f['weeks'][$i]['iso_week'], array_filter($idx, fn($i) => $f['weeks'][$i]['approved_at'] === null)))];
    }

    /**
     * Dzień powrotu ciężarówki : koniec ostatniej fazy pracy trasy tygodnia (Kalendarz tras), bez trasy — dostawa + tyle samo
     * dni drogi ; każdy dzień blokady po drodze (zakaz ruchu : święto kraju przejazdu albo wyjazdu, niedziela w kraju
     * przejazdu) przesuwa powrót o dzień. @return array{0: string, 1: list<array{date:string, why:string}>}
     */
    private static function returnDay(array $w, ?string $tripHome, int $deliveryDays, array $holidays, string $dep, array $transit): array
    {
        $return = $tripHome ?? date('Y-m-d', strtotime($w['delivery_date'] . " +{$deliveryDays} days"));
        $blocked = [];
        for ($d = date('Y-m-d', strtotime($w['delivery_date'] . ' +1 day')); $d <= $return; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $why = null;
            foreach ($transit as $c) {
                if (isset($holidays[$d][$c])) { $why = $holidays[$d][$c] . ' (' . $c . ')'; break; }
                if ((int) date('N', strtotime($d)) === 7) { $why = 'niedziela (' . $c . ')'; break; }
            }
            if ($why === null && isset($holidays[$d][$dep])) $why = $holidays[$d][$dep] . ' (' . $dep . ')';
            if ($why === null) continue;
            $blocked[] = ['date' => $d, 'why' => $why];
            $return = date('Y-m-d', strtotime($return . ' +1 day'));
        }
        return [$return, $blocked];
    }

    private static function monday(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days'));
    }
}
