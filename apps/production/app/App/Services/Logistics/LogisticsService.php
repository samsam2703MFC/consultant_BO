<?php
namespace App\Services\Logistics;

use App\Domain\Product\ProductLogistics;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Dzień wysyłki : klienci z paletami i wagą, przydział do pojazdów, zapełnienie pojazdów.
 *
 * Trasa klienta na dany dzień : przydział tego dnia, inaczej trasa, w której klient jest na stałe i
 * która jeździ w ten dzień tygodnia (kilka : pierwsza po nazwie). Pojazd : przydział tego dnia,
 * inaczej pojazd trasy — klienci trasy po kolejności rozładunku, każdy do pierwszego pojazdu trasy,
 * w którym jego palety się mieszczą (inaczej do najmniej zapełnionego) — inaczej pojazd z ostatniego
 * CMR klienta (podpowiedź), inaczej brak. Przydział dnia można zapamiętać : klient na stałe w trasie.
 * Zapełnienie : palety klientów ÷ miejsca paletowe, waga ÷ ładowność. Palety klienta rozpisane po
 * kolei według rodzaju kartonu (palety mieszane tylko w jednym formacie), potem kategorii produktów.
 * Wyjazd : godzina trasy, inaczej pojazdu (lub planning.default_departure_time), załadunek od wyjazdu
 * − planning.loading_time_min.
 */
final class LogisticsService
{
    public const WEEKDAYS = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private LogisticsRepository $repo, private ParamRepository $params)
    {
    }

    /**
     * Wyjazd klientów w dniu wysyłki (planowanie : MTO nigdy po wyjeździe) : fn(data, klienci, domyślna)
     * → najwcześniejsza godzina. Klient : godzina jego trasy tego dnia, inaczej pojazdu przydzielonego
     * na ten dzień, inaczej pierwszego pojazdu trasy, inaczej domyślna. Trasy czytane raz na funkcję.
     */
    public function departures(): \Closure
    {
        $cache = [];
        return function (string $date, array $customerIds, string $default) use (&$cache): string {
            if (!$customerIds) return $default;
            [$tours, $members] = $cache['map'][$date] ??= $this->tourMap($date);
            $assignments = $cache['assign'][$date] ??= $this->repo->assignments($date);
            $vehicles = $cache['vehicles'] ??= array_column($this->repo->vehicles(), null, 'id');
            $times = [];
            foreach ($customerIds as $id) {
                $a = $assignments[(int) $id] ?? null;
                $tourId = $a !== null && $a['tour_set'] ? $a['tour'] : ($members[(int) $id] ?? null);
                $tour = $tourId !== null ? ($tours[$tourId] ?? null) : null;
                $tourVehicle = null;
                foreach ($tour['vehicles'] ?? [] as $vid) if ((int) ($vehicles[$vid]['active'] ?? 0)) { $tourVehicle = $vid; break; }
                $times[] = ($tour['departure_time'] ?? null)
                    ?: ($a !== null && $a['vehicle_set'] && $a['vehicle'] !== null ? ($vehicles[$a['vehicle']]['departure_time'] ?? null) : null)
                    ?: ($tourVehicle !== null ? $vehicles[$tourVehicle]['departure_time'] : null)
                    ?: $default;
            }
            return min($times);
        };
    }

    public function dates(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        return $this->repo->shippingDates(date('Y-m-d', strtotime($today . ' -7 days')));
    }

    /** Pierwszy dzień wysyłki od dziś (albo dziś, gdy brak zamówień). */
    public function defaultDate(?string $today = null): string
    {
        $today ??= date('Y-m-d');
        foreach ($this->dates($today) as $d) if ($d['ship_date'] >= $today) return $d['ship_date'];
        return $today;
    }

    public function day(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $mixed = $this->params->get('logistics.mixed_pallets', '1') === '1';
        $tare = (float) $this->params->get('logistics.pallet_tare_kg', '25');
        $leftoverMax = max(0.0, min(1.0, (float) $this->params->get('logistics.leftover_fill_max', '0.5')));
        $defaultDeparture = $this->params->get('planning.default_departure_time', '20:00');
        $loadingMin = (int) $this->params->get('planning.loading_time_min', '60');

        $lines = $this->repo->dayLines($date);
        $byCustomer = [];
        $products = [];
        foreach ($lines as $l) {
            $id = (int) $l['id_customer'];
            // Karta « 1 / 1 / 1 » (wartość domyślna kreatora, 1 karton na paletę) : jak brak danych — nie liczy się palety na każdy karton.
            $placeholder = (int) $l['cpp'] === 1 && (int) $l['cpl'] <= 1 && (int) $l['lpp'] <= 1;
            if ($placeholder) $l['cpp'] = 0;
            $byCustomer[$id] ??= ['id' => $id, 'name' => $l['customer'], 'town' => $l['town'], 'country' => $l['country'], 'orders' => [], 'lines' => []];
            $byCustomer[$id]['orders'][(int) $l['order_id']] = (int) $l['status'];
            $byCustomer[$id]['lines'][] = $l;
            $cpp = (int) $l['cpp'];
            $layers = (int) $l['cpl'] * (int) $l['lpp'];
            if ($cpp <= 0 || ($layers > 0 && $layers !== $cpp)) {
                $products[(int) $l['product_id']] = ['id' => (int) $l['product_id'], 'name' => $l['product'], 'cpp' => $placeholder ? 1 : ($cpp ?: null), 'cpl' => (int) $l['cpl'] ?: null, 'lpp' => (int) $l['lpp'] ?: null,
                    'issue' => $cpp <= 0 ? 'missing' : 'layers', 'placeholder' => $placeholder];
            }
        }

        $assignments = $this->repo->assignments($date);
        $last = $this->repo->lastVehicles(array_keys($byCustomer));
        $scanned = $this->repo->scannedPallets($date);
        $vehicles = [];
        foreach ($this->repo->vehicles() as $v) $vehicles[(int) $v['id']] = $v;
        [$tours, $members, $stops] = $this->tourMap($date);

        $customers = [];
        foreach ($byCustomer as $id => $c) {
            // Ten sam produkt z kilku zamówień klienta : jedna pozycja (pełne palety liczone łącznie).
            $merged = [];
            foreach ($c['lines'] as $l) {
                $pid = (int) $l['product_id'];
                if (isset($merged[$pid])) {
                    $merged[$pid]['cartons'] = (float) $merged[$pid]['cartons'] + (float) $l['cartons'];
                    continue;
                }
                $merged[$pid] = $l;
            }
            $c['lines'] = array_values($merged);
            $calc = PalletCalculator::compute(array_map(fn($l) => ['cartons' => $l['cartons'], 'cpp' => $l['cpp'], 'ppc' => $l['ppc'], 'weight_g' => $l['weight_g'],
                'product' => $l['product'], 'carton_type_id' => $l['carton_type_id'] !== null ? (int) $l['carton_type_id'] : null,
                'carton_type' => $l['carton_type'], 'category' => $l['category']], $c['lines']), $mixed, $tare, $leftoverMax);
            $a = $assignments[$id] ?? null;
            [$tourId, $tourSource] = match (true) {
                $a !== null && $a['tour_set'] => [$a['tour'], 'day'],
                isset($members[$id]) => [$members[$id], 'tour'],
                default => [null, null],
            };
            if ($tourId !== null && !isset($tours[$tourId])) [$tourId, $tourSource] = [null, null];
            $stop = $a['stop'] ?? ($tourId !== null ? ($stops[$tourId][$id] ?? null) : null);
            $tourVehicles = $tourId !== null ? array_values(array_filter($tours[$tourId]['vehicles'], fn($v) => (int) ($vehicles[$v]['active'] ?? 0) === 1)) : [];
            [$vehicleId, $source] = match (true) {
                $a !== null && $a['vehicle_set'] => [$a['vehicle'], 'day'],
                (bool) $tourVehicles => [null, 'tour'],
                isset($last[$id]) && (int) ($vehicles[$last[$id]]['active'] ?? 0) === 1 => [$last[$id], 'last_cmr'],
                default => [null, null],
            };
            if ($vehicleId !== null && !isset($vehicles[$vehicleId])) [$vehicleId, $source] = [null, null];
            $customers[$id] = $calc + [
                'id' => $id, 'name' => $c['name'], 'town' => $c['town'], 'country' => $c['country'],
                'orders' => count($c['orders']), 'order_ids' => array_keys($c['orders']),
                'palletised' => count(array_filter($c['orders'], fn($s) => $s >= 3)) === count($c['orders']),
                'scanned' => $scanned[$id] ?? 0,
                'vehicle_id' => $vehicleId, 'vehicle_source' => $source, 'stop' => $stop,
                'tour_id' => $tourId, 'tour_source' => $tourSource, 'tour_member' => $tourId !== null && array_key_exists($id, $stops[$tourId] ?? []),
                'tour_vehicles' => $tourVehicles,
                'products' => array_map(fn($l) => ['id' => (int) $l['product_id'], 'name' => $l['product'], 'cartons' => (float) $l['cartons'], 'cpp' => (int) $l['cpp'] ?: null], $c['lines']),
            ];
        }
        uasort($customers, fn($a, $b) => [$a['stop'] ?? 999, $b['pallets']] <=> [$b['stop'] ?? 999, $a['pallets']]);

        // Pojazd z trasy : najpierw palety przydzielone ręcznie, potem klienci tras po kolejności rozładunku.
        $load = [];
        foreach ($customers as $c) if ($c['vehicle_id'] !== null) $load[$c['vehicle_id']] = ($load[$c['vehicle_id']] ?? 0) + $c['pallets'];
        foreach ($customers as $id => $c) {
            if ($c['vehicle_source'] !== 'tour') continue;
            $chosen = null;
            foreach ($c['tour_vehicles'] as $vid) {
                $places = $vehicles[$vid]['pallet_places'] !== null ? (int) $vehicles[$vid]['pallet_places'] : null;
                if ($places === null || ($load[$vid] ?? 0) + $c['pallets'] <= $places) { $chosen = $vid; break; }
            }
            if ($chosen === null) {
                usort($c['tour_vehicles'], fn($x, $y) => ((int) $vehicles[$y]['pallet_places'] - ($load[$y] ?? 0)) <=> ((int) $vehicles[$x]['pallet_places'] - ($load[$x] ?? 0)));
                $chosen = $c['tour_vehicles'][0];
            }
            $customers[$id]['vehicle_id'] = $chosen;
            $load[$chosen] = ($load[$chosen] ?? 0) + $c['pallets'];
        }

        $trucks = [];
        foreach ($customers as $c) {
            if ($c['vehicle_id'] === null) continue;
            $trucks[$c['vehicle_id']] ??= ['customers' => [], 'departures' => []];
            $trucks[$c['vehicle_id']]['customers'][] = $c['id'];
            if ($c['tour_id'] !== null && $tours[$c['tour_id']]['departure_time']) $trucks[$c['vehicle_id']]['departures'][] = $tours[$c['tour_id']]['departure_time'];
        }
        $fleet = [];
        foreach ($vehicles as $id => $v) {
            $assigned = $trucks[$id]['customers'] ?? [];
            if (!$assigned && !(int) $v['active']) continue;
            $pallets = array_sum(array_map(fn($cid) => $customers[$cid]['pallets'], $assigned));
            $weight = array_sum(array_map(fn($cid) => $customers[$cid]['weight_kg'], $assigned));
            $places = $v['pallet_places'] !== null ? (int) $v['pallet_places'] : null;
            $maxKg = $v['max_load_kg'] !== null ? (int) $v['max_load_kg'] : null;
            $departure = ($trucks[$id]['departures'] ?? []) ? min($trucks[$id]['departures']) : ($v['departure_time'] ?: $defaultDeparture);
            $fleet[] = [
                'id' => $id, 'label' => trim($v['regis_num']) !== '' ? $v['regis_num'] : $v['carrier'], 'carrier' => $v['carrier'], 'driver' => $v['driver'],
                'configured' => (bool) $v['configured'], 'active' => (bool) (int) $v['active'],
                'places' => $places, 'max_kg' => $maxKg, 'customers' => $assigned,
                'pallets' => $pallets, 'weight_kg' => round($weight, 1),
                'fill_pct' => $places ? $pallets / $places * 100 : null,
                'weight_pct' => $maxKg ? $weight / $maxKg * 100 : null,
                'over' => ($places !== null && $pallets > $places) || ($maxKg !== null && $weight > $maxKg),
                'departure' => $departure,
                'loading_from' => date('H:i', strtotime($date . ' ' . $departure) - $loadingMin * 60),
            ];
        }
        usort($fleet, fn($a, $b) => [!$a['customers'], $a['departure'], $a['label']] <=> [!$b['customers'], $b['departure'], $b['label']]);

        $unassigned = array_values(array_filter($customers, fn($c) => $c['vehicle_id'] === null));
        $fleetById = array_column($fleet, null, 'id');

        // Trasy dnia : jeżdżące w ten dzień tygodnia lub z klientem przydzielonym na ten dzień.
        $dayTours = [];
        $weekBit = 1 << ((int) date('N', strtotime($date)) - 1);
        foreach ($tours as $tid => $t) {
            $list = array_values(array_filter($customers, fn($c) => $c['tour_id'] === $tid));
            usort($list, fn($a, $b) => [$a['stop'] ?? 999, $b['pallets']] <=> [$b['stop'] ?? 999, $a['pallets']]);
            if (!$list && (!(int) $t['active'] || !((int) $t['weekdays'] & $weekBit))) continue;
            $tourVehicles = [];
            foreach ($t['vehicles'] as $vid) {
                if (!isset($vehicles[$vid]) || !(int) $vehicles[$vid]['active']) continue;
                $truck = $fleetById[$vid] ?? null;
                $tourVehicles[] = ['id' => $vid, 'label' => $truck['label'] ?? $vehicles[$vid]['regis_num'], 'places' => $truck['places'] ?? null,
                    'pallets' => $truck['pallets'] ?? 0, 'fill_pct' => $truck['fill_pct'] ?? null, 'over' => $truck['over'] ?? false];
            }
            $pallets = array_sum(array_column($list, 'pallets'));
            $places = array_sum(array_map(fn($v) => (int) $v['places'], $tourVehicles));
            $first = $tourVehicles ? $vehicles[$tourVehicles[0]['id']]['departure_time'] : null;
            $dayTours[] = [
                'id' => $tid, 'name' => $t['name'], 'color' => $t['color'], 'days' => $this->dayLabels((int) $t['weekdays']),
                'departure' => $t['departure_time'] ?: ($first ?: $defaultDeparture),
                'vehicles' => $tourVehicles, 'customers' => array_column($list, 'id'),
                'pallets' => $pallets, 'weight_kg' => round(array_sum(array_column($list, 'weight_kg')), 1), 'places' => $places,
                'fill_pct' => $places ? $pallets / $places * 100 : null,
            ];
        }
        usort($dayTours, fn($a, $b) => [!$a['customers'], $a['departure'], $a['name']] <=> [!$b['customers'], $b['departure'], $b['name']]);
        $noTour = array_values(array_filter($customers, fn($c) => $c['tour_id'] === null));
        // Rodzaje kartonów dnia : kartony i palety (materiał opakowaniowy, miejsce na rampie).
        $cartonTypes = [];
        foreach ($customers as $c) {
            foreach ($c['carton_types'] as $type) {
                if ($type['id'] === 'formats') continue;
                $key = $type['id'] ?? 'none';
                $cartonTypes[$key] ??= ['id' => $type['id'], 'name' => $type['name'], 'cartons' => 0.0, 'pallets' => 0, 'full' => 0, 'mixed' => 0, 'customers' => 0];
                $cartonTypes[$key]['cartons'] += $type['cartons'];
                $cartonTypes[$key]['pallets'] += count($type['pallets']);
                $cartonTypes[$key]['full'] += count(array_filter($type['pallets'], fn($p) => $p['kind'] === 'full'));
                $cartonTypes[$key]['mixed'] += count(array_filter($type['pallets'], fn($p) => $p['kind'] !== 'full'));
                $cartonTypes[$key]['customers']++;
            }
        }
        uasort($cartonTypes, fn($a, $b) => [$a['id'] === null, -$a['pallets']] <=> [$b['id'] === null, -$b['pallets']]);
        $warnings = [];
        foreach ($fleet as $t) {
            $kg = fn(float $v) => number_format($v, 0, ',', ' ') . ' kg';
            if ($t['over']) $warnings[] = "Pojazd {$t['label']}: {$t['pallets']} palet" . ($t['places'] ? " na {$t['places']} miejsc" : '') . ($t['max_kg'] && $t['weight_kg'] > $t['max_kg'] ? ', ' . $kg($t['weight_kg']) . ' na ' . $kg($t['max_kg']) : '') . ' — przenieś klienta do innego pojazdu.';
            if ($t['customers'] && $t['places'] === null) $warnings[] = "Pojazd {$t['label']} nie ma podanej pojemności — uzupełnij w zakładce Pojazdy.";
        }
        foreach ($dayTours as $t) {
            if ($t['customers'] && !$t['vehicles']) $warnings[] = "Trasa {$t['name']} nie ma pojazdu w użyciu — dodaj pojazd w zakładce Trasy.";
        }
        if ($unassigned) $warnings[] = count($unassigned) . ' klient(ów) bez pojazdu.';
        $missing = count(array_filter($products, fn($p) => $p['issue'] === 'missing'));
        if ($missing) $warnings[] = "{$missing} produkt(ów) bez kartonów na paletę — ich palety nie są policzone, uzupełnij poniżej.";

        return [
            'date' => $date,
            'dates' => $this->dates(),
            'customers' => array_values($customers),
            'by_id' => $customers,
            'fleet' => $fleet,
            'unassigned' => $unassigned,
            'tours' => $dayTours,
            'no_tour' => array_column($noTour, 'id'),
            'tour_options' => array_values(array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'active' => (bool) (int) $t['active']], $tours)),
            'products' => array_values($products),
            'carton_types' => array_values($cartonTypes),
            'warnings' => $warnings,
            'totals' => [
                'customers' => count($customers),
                'orders' => array_sum(array_column($customers, 'orders')),
                'cartons' => array_sum(array_column($customers, 'cartons')),
                'pallets' => array_sum(array_column($customers, 'pallets')),
                'weight_kg' => array_sum(array_column($customers, 'weight_kg')),
                'scanned' => array_sum(array_column($customers, 'scanned')),
                'places' => array_sum(array_map(fn($t) => $t['customers'] ? (int) $t['places'] : 0, $fleet)),
            ],
            'settings' => ['mixed' => $mixed, 'tare_kg' => $tare, 'leftover_max' => $leftoverMax, 'default_departure' => $defaultDeparture, 'loading_min' => $loadingMin],
            // Lista wyboru : tylko pojazdy w użyciu ; pojazd nieaktywny już przydzielony pokazuje się tylko u tego klienta.
            'vehicles' => array_values(array_filter($vehicles, fn($v) => (int) $v['active'] === 1)),
            'all_vehicles' => $vehicles,
        ];
    }

    /**
     * Tydzień logistyki : pojazdy dzień po dniu z paletami, miejscami i wypełnieniem (%), klienci bez
     * pojazdu i sumy dnia. Pn–pt zawsze, sobota i niedziela tylko z wysyłkami.
     */
    public function week(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $t = strtotime($date);
        $monday = date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
        $days = [];
        $trucks = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($monday . " +{$i} days"));
            $day = $this->day($d);
            if ($i >= 5 && !$day['totals']['customers']) continue;
            $used = array_values(array_filter($day['fleet'], fn($v) => $v['customers']));
            foreach ($used as $v) {
                $trucks[$v['id']] ??= ['id' => $v['id'], 'label' => $v['label'], 'carrier' => $v['carrier'], 'places' => $v['places'], 'days' => []];
                $trucks[$v['id']]['days'][$d] = [
                    'pallets' => $v['pallets'], 'places' => $v['places'], 'fill_pct' => $v['fill_pct'], 'weight_kg' => $v['weight_kg'], 'weight_pct' => $v['weight_pct'],
                    'over' => $v['over'], 'departure' => $v['departure'],
                    'customers' => array_map(fn($cid) => $day['by_id'][$cid]['name'], $v['customers']),
                ];
            }
            $places = array_sum(array_map(fn($v) => (int) $v['places'], $used));
            $loaded = array_sum(array_column($used, 'pallets'));
            $days[] = [
                'date' => $d, 'customers' => $day['totals']['customers'], 'orders' => $day['totals']['orders'], 'cartons' => $day['totals']['cartons'],
                'pallets' => $day['totals']['pallets'], 'loaded' => $loaded, 'places' => $places, 'trucks' => count($used),
                'fill_pct' => $places > 0 ? $loaded / $places * 100 : null,
                'unassigned' => ['customers' => count($day['unassigned']), 'pallets' => array_sum(array_column($day['unassigned'], 'pallets'))],
                'warnings' => count($day['warnings']),
            ];
        }
        foreach ($trucks as &$v) {
            $v['pallets'] = array_sum(array_column($v['days'], 'pallets'));
            $v['places_used'] = array_sum(array_map(fn($x) => (int) $x['places'], $v['days']));
            $v['fill_pct'] = $v['places_used'] > 0 ? $v['pallets'] / $v['places_used'] * 100 : null;
        }
        unset($v);
        usort($trucks, fn($a, $b) => [-count($a['days']), $a['label']] <=> [-count($b['days']), $b['label']]);
        $places = array_sum(array_column($days, 'places'));
        $loaded = array_sum(array_column($days, 'loaded'));
        return [
            'week_start' => $monday, 'week_end' => date('Y-m-d', strtotime($monday . ' +6 days')), 'iso_week' => (int) date('W', strtotime($monday)),
            'prev' => date('Y-m-d', strtotime($monday . ' -7 days')), 'next' => date('Y-m-d', strtotime($monday . ' +7 days')), 'today' => date('Y-m-d'),
            'days' => $days, 'trucks' => $trucks,
            'totals' => [
                'customers' => array_sum(array_column($days, 'customers')), 'pallets' => array_sum(array_column($days, 'pallets')),
                'loaded' => $loaded, 'places' => $places, 'fill_pct' => $places > 0 ? $loaded / $places * 100 : null,
                'runs' => array_sum(array_column($days, 'trucks')),
                'unassigned' => array_sum(array_map(fn($d) => $d['unassigned']['pallets'], $days)),
                'unassigned_customers' => array_sum(array_map(fn($d) => $d['unassigned']['customers'], $days)),
            ],
        ];
    }

    /**
     * Przydział klienta na dzień : trasa (tour_id ; brak klucza = trasa klienta), pojazd (vehicle_id ;
     * „auto” = z pojazdów trasy), kolejność. remember : klient na stałe w wybranej trasie (true) albo
     * usunięty z niej (false).
     */
    public function assign(array $input, ?int $userId): void
    {
        $date = (string) ($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $customerId = (int) ($input['customer_id'] ?? 0);
        if (!$this->repo->customerExists($customerId)) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
        $tourSet = array_key_exists('tour_id', $input);
        $tourId = $tourSet && $input['tour_id'] !== null && $input['tour_id'] !== '' && $input['tour_id'] !== 'none' ? (int) $input['tour_id'] : null;
        $tour = $tourId !== null ? ($this->repo->tour($tourId) ?? throw new CustomException('Nie znaleziono trasy.', 'errors', 404)) : null;
        $vehicleSet = ($input['vehicle_id'] ?? null) !== 'auto';
        $vehicleId = !$vehicleSet || ($input['vehicle_id'] ?? '') === '' || $input['vehicle_id'] === null ? null : (int) $input['vehicle_id'];
        if ($vehicleId !== null && !$this->repo->vehicleExists($vehicleId)) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        $stop = ($input['stop'] ?? '') === '' || ($input['stop'] ?? null) === null ? null : max(1, min(99, (int) $input['stop']));

        if (array_key_exists('remember', $input) && $tour !== null) {
            if (filter_var($input['remember'], FILTER_VALIDATE_BOOLEAN)) {
                $this->repo->putCustomerOnTour($tourId, $customerId, $stop ?? (int) ($this->repo->tourStop($tourId, $customerId)['stop_order'] ?? $this->repo->nextTourStop($tourId)), (int) $tour['weekdays']);
            } else {
                $this->repo->removeCustomerFromTour($tourId, $customerId);
            }
        }
        // Trasa dnia taka jak trasa klienta : bez wyjątku na ten dzień.
        if ($tourSet && ($this->tourMap($date)[1][$customerId] ?? null) === $tourId) $tourSet = false;
        $this->repo->saveAssignment($date, $customerId, $tourSet, $tourId, $vehicleSet, $vehicleId, $stop, $userId);
    }

    // --- Trasy -------------------------------------------------------------------------------------

    /** Nowy klient (kreator) : na stałe w trasie, na końcu albo na podanym przystanku ; usunięty z tras w te same dni. */
    public function placeOnTour(int $customerId, int $tourId, ?int $stop = null): void
    {
        $tour = $this->repo->tour($tourId) ?? throw new CustomException('Nie znaleziono trasy.', 'errors', 404);
        $stop = $stop !== null ? max(1, min(99, $stop)) : $this->repo->nextTourStop($tourId);
        $this->repo->putCustomerOnTour($tourId, $customerId, $stop, (int) $tour['weekdays']);
    }

    public function tours(): array
    {
        $vehicles = [];
        foreach ($this->repo->vehicles() as $v) $vehicles[(int) $v['id']] = $v;
        $tours = [];
        foreach ($this->repo->tours() as $t) {
            $tours[(int) $t['id']] = $t + ['id' => (int) $t['id'], 'active' => (bool) (int) $t['active'], 'weekdays' => (int) $t['weekdays'],
                'days' => $this->dayLabels((int) $t['weekdays']), 'day_list' => $this->dayList((int) $t['weekdays']), 'vehicles' => [], 'customers' => [], 'places' => 0];
        }
        foreach ($this->repo->tourVehicles() as $tv) {
            $tid = (int) $tv['id_tour'];
            $v = $vehicles[(int) $tv['id_carrier_vehicle']] ?? null;
            if (!isset($tours[$tid]) || $v === null) continue;
            $tours[$tid]['vehicles'][] = ['id' => (int) $v['id'], 'label' => trim($v['regis_num']) !== '' ? $v['regis_num'] : $v['carrier'], 'carrier' => $v['carrier'],
                'places' => $v['pallet_places'] !== null ? (int) $v['pallet_places'] : null, 'departure' => $v['departure_time'], 'active' => (bool) (int) $v['active']];
            if ((int) $v['active']) $tours[$tid]['places'] += (int) $v['pallet_places'];
        }
        $byCustomer = [];
        foreach ($this->repo->tourCustomers() as $tc) {
            $tid = (int) $tc['id_tour'];
            if (!isset($tours[$tid])) continue;
            $tours[$tid]['customers'][] = ['id' => (int) $tc['id_customer'], 'name' => $tc['customer'], 'town' => $tc['town'], 'country' => $tc['country'],
                'stop' => $tc['stop_order'] !== null ? (int) $tc['stop_order'] : null, 'active' => (bool) (int) $tc['active']];
            $byCustomer[(int) $tc['id_customer']][] = $tid;
        }
        $warnings = [];
        foreach ($byCustomer as $cid => $list) {
            for ($i = 0; $i < count($list); $i++) {
                for ($j = $i + 1; $j < count($list); $j++) {
                    [$a, $b] = [$tours[$list[$i]], $tours[$list[$j]]];
                    $common = $a['weekdays'] & $b['weekdays'];
                    if (!$common || !$a['active'] || !$b['active']) continue;
                    $name = array_values(array_filter($a['customers'], fn($c) => $c['id'] === $cid))[0]['name'];
                    $warnings[] = "{$name}: w trasach {$a['name']} i {$b['name']} w te same dni ({$this->dayLabels($common)}) — jedzie trasą {$a['name']}.";
                }
            }
        }
        foreach ($tours as $t) {
            if ($t['active'] && !array_filter($t['vehicles'], fn($v) => $v['active'])) $warnings[] = "Trasa {$t['name']} nie ma pojazdu w użyciu.";
        }
        return [
            'tours' => array_values($tours),
            'vehicles' => array_values(array_filter($vehicles, fn($v) => (int) $v['active'] === 1)),
            'customers' => $this->repo->customers(),
            'weekdays' => self::WEEKDAYS,
            'default_departure' => $this->params->get('planning.default_departure_time', '20:00'),
            'warnings' => $warnings,
        ];
    }

    public function createTour(array $input, ?int $userId): int
    {
        $data = $this->tourData($input + ['days' => [1, 2, 3, 4, 5], 'active' => true], null);
        return $this->repo->insertTour($data + ['updated_by' => $userId]);
    }

    public function updateTour(int $id, array $input, ?int $userId): void
    {
        $this->repo->tour($id) ?? throw new CustomException('Nie znaleziono trasy.', 'errors', 404);
        $this->repo->updateTour($id, $this->tourData($input, $id) + ['updated_by' => $userId]);
    }

    public function deleteTour(int $id): void
    {
        $this->repo->tour($id) ?? throw new CustomException('Nie znaleziono trasy.', 'errors', 404);
        $this->repo->deleteTour($id);
    }

    /** Pojazdy trasy w kolejności wypełniania. */
    public function setTourVehicles(int $id, array $input): array
    {
        $this->repo->tour($id) ?? throw new CustomException('Nie znaleziono trasy.', 'errors', 404);
        $ids = [];
        foreach ((array) ($input['vehicle_ids'] ?? []) as $raw) {
            $vid = filter_var($raw, FILTER_VALIDATE_INT);
            if ($vid === false || !$this->repo->vehicleExists($vid)) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
            if (!in_array($vid, $ids, true)) $ids[] = $vid;
        }
        if (count($ids) > 20) throw new CustomException('Najwyżej 20 pojazdów na trasie.', 'errors', 422);
        $this->repo->setTourVehicles($id, $ids);
        return $ids;
    }

    /** Klienci trasy z kolejnością rozładunku (puste = na końcu). */
    public function setTourCustomers(int $id, array $input): array
    {
        $tour = $this->repo->tour($id) ?? throw new CustomException('Nie znaleziono trasy.', 'errors', 404);
        $rows = [];
        foreach ((array) ($input['customers'] ?? []) as $c) {
            $cid = filter_var(is_array($c) ? ($c['id'] ?? null) : $c, FILTER_VALIDATE_INT);
            if ($cid === false || !$this->repo->customerExists($cid)) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
            $rawStop = is_array($c) ? ($c['stop'] ?? null) : null;
            $stop = $rawStop === null || $rawStop === '' ? null : filter_var($rawStop, FILTER_VALIDATE_INT);
            if ($stop === false || ($stop !== null && ($stop < 1 || $stop > 99))) throw new CustomException('Kolejność rozładunku: od 1 do 99.', 'errors', 422);
            $rows[$cid] = ['id' => $cid, 'stop' => $stop];
        }
        if (count($rows) > 300) throw new CustomException('Najwyżej 300 klientów na trasie.', 'errors', 422);
        $moved = $this->repo->setTourCustomers($id, array_values($rows), (int) $tour['weekdays']);
        return ['customers' => array_values($rows), 'moved' => $moved];
    }

    /** [trasy, klient => trasa w ten dzień tygodnia, trasa => [klient => kolejność]] */
    private function tourMap(string $date): array
    {
        $weekBit = 1 << ((int) date('N', strtotime($date)) - 1);
        $tours = [];
        foreach ($this->repo->tours() as $t) $tours[(int) $t['id']] = $t + ['vehicles' => []];
        foreach ($this->repo->tourVehicles() as $tv) if (isset($tours[(int) $tv['id_tour']])) $tours[(int) $tv['id_tour']]['vehicles'][] = (int) $tv['id_carrier_vehicle'];
        $members = [];
        $stops = [];
        foreach ($this->repo->tourCustomers() as $tc) {
            $tid = (int) $tc['id_tour'];
            if (!isset($tours[$tid])) continue;
            $stops[$tid][(int) $tc['id_customer']] = $tc['stop_order'] !== null ? (int) $tc['stop_order'] : null;
            if ((int) $tours[$tid]['active'] && ((int) $tours[$tid]['weekdays'] & $weekBit)) $members[(int) $tc['id_customer']] ??= $tid;
        }
        return [$tours, $members, $stops];
    }

    private function tourData(array $input, ?int $id): array
    {
        $data = [];
        if (array_key_exists('name', $input) || $id === null) {
            $name = trim(preg_replace('/\s+/', ' ', (string) ($input['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 80) throw new CustomException('Podaj nazwę trasy (do 80 znaków).', 'errors', 422);
            if ($this->repo->tourNameTaken($name, $id)) throw new CustomException("Trasa „{$name}” już istnieje.", 'errors', 422);
            $data['name'] = $name;
        }
        if (array_key_exists('days', $input)) {
            $mask = 0;
            foreach ((array) $input['days'] as $day) {
                $day = filter_var($day, FILTER_VALIDATE_INT);
                if ($day === false || $day < 1 || $day > 7) throw new CustomException('Dni tygodnia: od 1 (poniedziałek) do 7 (niedziela).', 'errors', 422);
                $mask |= 1 << ($day - 1);
            }
            if ($mask === 0) throw new CustomException('Wybierz co najmniej jeden dzień tygodnia.', 'errors', 422);
            $data['weekdays'] = $mask;
        }
        if (array_key_exists('departure_time', $input)) {
            $time = trim((string) $input['departure_time']);
            if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new CustomException('Godzina wyjazdu: GG:MM.', 'errors', 422);
            $data['departure_time'] = $time === '' ? null : $time . ':00';
        }
        if (array_key_exists('color', $input)) {
            $color = strtolower(trim((string) $input['color']));
            if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/', $color)) throw new CustomException('Kolor: #rrggbb.', 'errors', 422);
            $data['color'] = $color ?: null;
        }
        if (array_key_exists('active', $input)) $data['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if (array_key_exists('notes', $input)) $data['notes'] = mb_substr(trim((string) $input['notes']), 0, 255) ?: null;
        return $data;
    }

    private function dayList(int $mask): array
    {
        return array_values(array_filter(array_keys(self::WEEKDAYS), fn($d) => $mask & (1 << ($d - 1))));
    }

    private function dayLabels(int $mask): string
    {
        if (($mask & 127) === 127) return 'codziennie';
        if (($mask & 127) === 31) return 'pon.–pt.';
        if (($mask & 127) === 63) return 'pon.–sob.';
        return implode(', ', array_map(fn($d) => self::WEEKDAYS[$d], $this->dayList($mask)));
    }

    public function vehicles(): array
    {
        $names = array_column($this->repo->tours(), 'name', 'id');
        $tourNames = [];
        foreach ($this->repo->tourVehicles() as $tv) $tourNames[(int) $tv['id_carrier_vehicle']][] = $names[$tv['id_tour']] ?? null;
        return ['vehicles' => array_map(fn($v) => $v + ['tours' => array_values(array_filter($tourNames[(int) $v['id']] ?? []))], $this->repo->vehicles()), 'carriers' => $this->repo->carriers(),
            'default_places' => (int) $this->params->get('logistics.default_pallet_places', '33'),
            'default_departure' => $this->params->get('planning.default_departure_time', '20:00')];
    }

    public function saveVehicle(int $id, array $input, ?int $userId): void
    {
        if (!$this->repo->vehicleExists($id)) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        $this->repo->saveVehicle($id, $this->vehicleData($input, $userId, true));
    }

    public function addVehicle(array $input, ?int $userId): int
    {
        $carrierId = (int) ($input['carrier_id'] ?? 0);
        if (!$this->repo->carrierExists($carrierId)) throw new CustomException('Wybierz przewoźnika.', 'errors', 422);
        $regis = trim((string) ($input['regis_num'] ?? ''));
        if ($regis === '' || mb_strlen($regis) > 60) throw new CustomException('Podaj numer rejestracyjny.', 'errors', 422);
        $email = trim((string) ($input['driver_email'] ?? ''));
        if ($email !== '' && (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new CustomException('Nieprawidłowy e-mail kierowcy.', 'errors', 422);
        $data = $this->vehicleData($input + ['active' => true], $userId, true);
        // Jeden formularz nowego pojazdu (dawniej też Przewoźnicy → Pojazdy) : tożsamość w carrier_vehicle, pojemność w n_vehicle.
        $id = $this->repo->addVehicle($carrierId, $regis, trim((string) ($input['driver_name'] ?? '')), trim((string) ($input['driver_surname'] ?? '')), trim((string) ($input['driver_phone'] ?? '')), $email);
        $this->repo->saveVehicle($id, $data);
        return $id;
    }

    /** Karta produktu : kartony na warstwę, warstwy, kartony na paletę — ta sama walidacja co Karta produktu. */
    public function savePalletData(int $productId, array $input): array
    {
        $product = $this->repo->product($productId) ?? throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        $given = array_intersect_key($input, array_flip(['cpl', 'lpp', 'cpp']));
        // Formularz uzupełnia paletę : trzy wartości większe od zera
        $errors = ProductLogistics::errors($given, $product, ['cpl', 'lpp', 'cpp']);
        if ($errors) throw new CustomException(implode(' ', ProductLogistics::messages($errors)), 'errors', 422);
        $values = [];
        foreach (['cpl', 'lpp', 'cpp'] as $field) $values[$field] = (int) ($given[$field] ?? $product[$field]);
        $this->repo->savePalletData($productId, $values['cpl'], $values['lpp'], $values['cpp']);
        return $values + ['consistent' => $values['cpl'] * $values['lpp'] === $values['cpp']];
    }

    private function vehicleData(array $input, ?int $userId, bool $full): array
    {
        $data = ['updated_by' => $userId];
        if (array_key_exists('pallet_places', $input) || $full) {
            $places = filter_var($input['pallet_places'] ?? null, FILTER_VALIDATE_INT);
            if ($places === false || $places < 1 || $places > 100) throw new CustomException('Miejsca paletowe: od 1 do 100.', 'errors', 422);
            $data['pallet_places'] = $places;
        }
        if (array_key_exists('max_load_kg', $input)) {
            $raw = trim(str_replace(' ', '', (string) $input['max_load_kg']));
            $kg = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT);
            if ($kg === false || ($kg !== null && ($kg < 1 || $kg > 60000))) throw new CustomException('Ładowność: kg, od 1 do 60 000 (puste = bez kontroli).', 'errors', 422);
            $data['max_load_kg'] = $kg;
        }
        if (array_key_exists('departure_time', $input)) {
            $time = trim((string) $input['departure_time']);
            if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new CustomException('Godzina wyjazdu: GG:MM.', 'errors', 422);
            $data['departure_time'] = $time === '' ? null : $time . ':00';
        }
        if (array_key_exists('active', $input)) $data['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if (array_key_exists('notes', $input)) $data['notes'] = mb_substr(trim((string) $input['notes']), 0, 255) ?: null;
        return $data;
    }
}
