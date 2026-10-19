<?php
namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Dzień wysyłki : klienci z paletami i wagą, przydział do pojazdów, zapełnienie pojazdów.
 *
 * Pojazd klienta na dany dzień : przydział tego dnia, inaczej stała trasa klienta, inaczej pojazd
 * z ostatniego CMR klienta (podpowiedź), inaczej brak. Przydział można zapamiętać jako stałą trasę.
 * Zapełnienie : palety klientów ÷ miejsca paletowe, waga ÷ ładowność. Palety klienta rozpisane po
 * kolei według rodzaju kartonu (palety mieszane tylko w jednym formacie), potem kategorii produktów. Wyjazd : godzina pojazdu
 * (lub planning.default_departure_time), załadunek od wyjazdu − planning.loading_time_min.
 */
final class LogisticsService
{
    public function __construct(private LogisticsRepository $repo, private ParamRepository $params)
    {
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
            $byCustomer[$id] ??= ['id' => $id, 'name' => $l['customer'], 'town' => $l['town'], 'country' => $l['country'], 'orders' => [], 'lines' => []];
            $byCustomer[$id]['orders'][(int) $l['order_id']] = (int) $l['status'];
            $byCustomer[$id]['lines'][] = $l;
            $cpp = (int) $l['cpp'];
            $layers = (int) $l['cpl'] * (int) $l['lpp'];
            if ($cpp <= 0 || ($layers > 0 && $layers !== $cpp)) {
                $products[(int) $l['product_id']] = ['id' => (int) $l['product_id'], 'name' => $l['product'], 'cpp' => $cpp ?: null, 'cpl' => (int) $l['cpl'] ?: null, 'lpp' => (int) $l['lpp'] ?: null,
                    'issue' => $cpp <= 0 ? 'missing' : 'layers'];
            }
        }

        $assignments = $this->repo->assignments($date);
        $routes = $this->repo->routes();
        $last = $this->repo->lastVehicles(array_keys($byCustomer));
        $scanned = $this->repo->scannedPallets($date);
        $vehicles = [];
        foreach ($this->repo->vehicles() as $v) $vehicles[(int) $v['id']] = $v;

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
            [$vehicleId, $source, $stop] = match (true) {
                isset($assignments[$id]) => [$assignments[$id]['vehicle'], 'day', $assignments[$id]['stop']],
                isset($routes[$id]) && $routes[$id]['vehicle'] !== null => [$routes[$id]['vehicle'], 'route', $routes[$id]['stop']],
                isset($last[$id]) && (int) ($vehicles[$last[$id]]['active'] ?? 0) === 1 => [$last[$id], 'last_cmr', null],
                default => [null, null, null],
            };
            if ($vehicleId !== null && !isset($vehicles[$vehicleId])) [$vehicleId, $source] = [null, null];
            $customers[$id] = $calc + [
                'id' => $id, 'name' => $c['name'], 'town' => $c['town'], 'country' => $c['country'],
                'orders' => count($c['orders']), 'order_ids' => array_keys($c['orders']),
                'palletised' => count(array_filter($c['orders'], fn($s) => $s >= 3)) === count($c['orders']),
                'scanned' => $scanned[$id] ?? 0,
                'vehicle_id' => $vehicleId, 'vehicle_source' => $source, 'stop' => $stop,
                'products' => array_map(fn($l) => ['id' => (int) $l['product_id'], 'name' => $l['product'], 'cartons' => (float) $l['cartons'], 'cpp' => (int) $l['cpp'] ?: null], $c['lines']),
            ];
        }
        uasort($customers, fn($a, $b) => [$a['stop'] ?? 999, $b['pallets']] <=> [$b['stop'] ?? 999, $a['pallets']]);

        $trucks = [];
        foreach ($customers as $c) {
            if ($c['vehicle_id'] === null) continue;
            $trucks[$c['vehicle_id']] ??= ['customers' => []];
            $trucks[$c['vehicle_id']]['customers'][] = $c['id'];
        }
        $fleet = [];
        foreach ($vehicles as $id => $v) {
            $assigned = $trucks[$id]['customers'] ?? [];
            if (!$assigned && !(int) $v['active']) continue;
            $pallets = array_sum(array_map(fn($cid) => $customers[$cid]['pallets'], $assigned));
            $weight = array_sum(array_map(fn($cid) => $customers[$cid]['weight_kg'], $assigned));
            $places = $v['pallet_places'] !== null ? (int) $v['pallet_places'] : null;
            $maxKg = $v['max_load_kg'] !== null ? (int) $v['max_load_kg'] : null;
            $departure = $v['departure_time'] ?: $defaultDeparture;
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

    /** Przydział klienta do pojazdu na dzień ; remember = także stała trasa klienta. */
    public function assign(array $input, ?int $userId): void
    {
        $date = (string) ($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $customerId = (int) ($input['customer_id'] ?? 0);
        if (!$this->repo->customerExists($customerId)) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
        $vehicleId = ($input['vehicle_id'] ?? '') === '' || $input['vehicle_id'] === null ? null : (int) $input['vehicle_id'];
        if ($vehicleId !== null && !$this->repo->vehicleExists($vehicleId)) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        $stop = ($input['stop'] ?? '') === '' || ($input['stop'] ?? null) === null ? null : max(1, min(99, (int) $input['stop']));
        $this->repo->saveAssignment($date, $customerId, $vehicleId, $stop, $userId);
        if (filter_var($input['remember'] ?? false, FILTER_VALIDATE_BOOLEAN)) $this->repo->saveRoute($customerId, $vehicleId, $stop, $userId);
    }

    public function vehicles(): array
    {
        return ['vehicles' => $this->repo->vehicles(), 'carriers' => $this->repo->carriers(),
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
        $data = $this->vehicleData($input + ['active' => true], $userId, true);
        $id = $this->repo->addVehicle($carrierId, $regis, trim((string) ($input['driver_name'] ?? '')), trim((string) ($input['driver_surname'] ?? '')), trim((string) ($input['driver_phone'] ?? '')));
        $this->repo->saveVehicle($id, $data);
        return $id;
    }

    /** Karta produktu : kartony na warstwę, warstwy, kartony na paletę. */
    public function savePalletData(int $productId, array $input): array
    {
        $product = $this->repo->product($productId) ?? throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        $values = [];
        foreach (['cpl' => 'Kartony na warstwę', 'lpp' => 'Warstwy na palecie', 'cpp' => 'Kartony na paletę'] as $field => $label) {
            $raw = $input[$field] ?? $product[$field];
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || $value > 1000) throw new CustomException("{$label}: liczba całkowita od 1 do 1000.", 'errors', 422);
            $values[$field] = $value;
        }
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
