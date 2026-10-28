<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsBoardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Logistyka i pakowanie (28/10/2026) — trzy ekrany na tych samych liczbach dnia wysyłki :
 *  - paletyzacja na żywo : na klienta zamówione kartony, na paletach (skan palet + tablet paletyzacji), gotowe do położenia
 *    (na stanie albo spakowane, czekają na etykietę) i brak — do spakowania ; palety w budowie, alarmy ;
 *  - pakowanie : brak na stanie = do spakowania, sztuki (kartony × sztuki w kartonie), czas wg normy « Pakowanie » karty
 *    produktu (bez normy : średnia norm), osoby obecne w grupie pakowania, koniec przed wyjazdem ;
 *  - załadunek : naczepa (miejsca palet), klienci pojazdu w kolejności rozładunku (pierwszy przy drzwiach), palety
 *    zeskanowane z numerem i planowane ; układ zapisany albo automatyczny, wysłany na tablet kierowcy (wersja).
 * Stan magazynu dzielony między klientów w kolejności trasy (najpierw pierwszy przystanek).
 */
final class LogisticsBoardService
{
    public const COLUMNS = 3;
    private const PACK_FIXED_S = 120.0;
    private const PACK_PER_PIECE_S = 4.0;

    public function __construct(
        private LogisticsService $logistics,
        private LogisticsBoardRepository $repo,
        private ParamRepository $params,
    ) {
    }

    // --- Wspólne liczby dnia -------------------------------------------------------------------------

    /**
     * Dzień : plan logistyki, klienci w kolejności (pojazdy, przystanki, potem bez pojazdu), pozycje klient × produkt
     * z podziałem : na palecie / na stanie / spakowane / brak.
     */
    private function state(string $date): array
    {
        $day = $this->logistics->day($date);
        $order = [];
        $vehicleOf = [];
        foreach ($day['fleet'] as $t) {
            foreach ($t['customers'] as $cid) { $order[] = $cid; $vehicleOf[$cid] = $t; }
        }
        foreach ($day['unassigned'] as $c) $order[] = (int) $c['id'];
        $lines = $this->repo->orderLines($date);
        $byCustomer = [];
        $productIds = [];
        foreach ($lines as $l) {
            $byCustomer[(int) $l['id_customer']][] = $l;
            $productIds[] = (int) $l['id_product'];
        }
        // Klienci z zamówieniami, których plan nie zna (np. dzień spoza listy) : na końcu.
        foreach (array_keys($byCustomer) as $cid) if (!in_array($cid, $order, true)) $order[] = $cid;
        $scanned = $this->repo->scanned($date);
        $entered = $this->repo->entered($date);
        $stockLeft = $this->repo->stock($productIds);
        $packing = $this->repo->packing($date);
        $now = $packing ? $this->repo->stockOrPallet(array_keys($packing)) : [];
        $pendingLeft = [];
        foreach ($packing as $pid => $p) $pendingLeft[$pid] = max(0, $p['packed'] - max(0, ($now[$pid] ?? 0) - $p['base_count']));
        $pending = $pendingLeft;

        $customers = [];
        foreach ($order as $cid) {
            $rows = [];
            foreach ($byCustomer[$cid] ?? [] as $l) {
                $pid = (int) $l['id_product'];
                $ordered = (int) round((float) $l['cartons']);
                $on = ($scanned[$cid][$pid] ?? 0) + ($entered[$cid][$pid] ?? 0);
                $miss = max(0, $ordered - $on);
                $take = min($miss, $stockLeft[$pid] ?? 0);
                $stockLeft[$pid] = ($stockLeft[$pid] ?? 0) - $take;
                $rest = $miss - $take;
                $packed = min($rest, $pendingLeft[$pid] ?? 0);
                if ($packed) $pendingLeft[$pid] -= $packed;
                $rows[] = ['product_id' => $pid, 'name' => $l['product'], 'category' => $l['category'], 'ppc' => (int) $l['ppc'],
                    'ordered' => $ordered, 'on_pallet' => $on, 'scanned' => $scanned[$cid][$pid] ?? 0, 'entered' => $entered[$cid][$pid] ?? 0,
                    'stock' => $take, 'packed' => $packed, 'ready' => $take + $packed, 'missing' => $rest - $packed, 'to_pack' => $rest];
            }
            $c = $day['by_id'][$cid] ?? null;
            $truck = $vehicleOf[$cid] ?? null;
            $customers[$cid] = [
                'id' => $cid, 'name' => $c['name'] ?? ('#' . $cid), 'town' => $c['town'] ?? null,
                'vehicle' => $truck ? ['id' => (int) $truck['id'], 'label' => $truck['label']] : null,
                'departure' => $truck['departure'] ?? $day['settings']['default_departure'],
                'planned_pallets' => (int) ($c['pallets'] ?? 0), 'weight_kg' => (float) ($c['weight_kg'] ?? 0),
                'lines' => $rows,
            ];
        }
        return ['day' => $day, 'customers' => $customers, 'pending' => $pending];
    }

    private function sum(array $rows, string $key): int
    {
        return (int) array_sum(array_column($rows, $key));
    }

    // --- Ekran paletyzacji na żywo -------------------------------------------------------------------

    public function live(string $date): array
    {
        $this->checkDate($date);
        $s = $this->state($date);
        $pallets = [];
        foreach ($this->repo->pallets($date) as $p) {
            $pallets[(int) $p['id_customer']][] = ['id' => (int) $p['id_palet'], 'cartons' => (int) $p['cartons'], 'open' => (int) $p['status'] === 1];
        }
        $clients = [];
        $alerts = [];
        $n = 0;
        foreach ($s['customers'] as $cid => $c) {
            $rows = $c['lines'];
            usort($rows, fn($a, $b) => [$b['missing'], $b['ready'], $a['name']] <=> [$a['missing'], $a['ready'], $b['name']]);
            $item = [
                'id' => $cid, 'n' => ++$n, 'name' => $c['name'], 'town' => $c['town'], 'vehicle' => $c['vehicle'], 'departure' => $c['departure'],
                'ordered' => $this->sum($rows, 'ordered'), 'on_pallet' => $this->sum($rows, 'on_pallet'), 'stock' => $this->sum($rows, 'stock'),
                'packed' => $this->sum($rows, 'packed'), 'ready' => $this->sum($rows, 'ready'), 'missing' => $this->sum($rows, 'missing'),
                'pallets' => $pallets[$cid] ?? [], 'planned_pallets' => max($c['planned_pallets'], count($pallets[$cid] ?? [])),
                'lines' => $rows,
            ];
            $item['left'] = max(0, $item['ordered'] - $item['on_pallet']);
            $clients[] = $item;
            if ($item['ordered'] > 0 && $item['on_pallet'] === 0) $alerts[] = ['type' => 'no_pallet', 'customer' => $item['name']];
        }
        $worst = $clients ? array_reduce($clients, fn($w, $c) => $w === null || $c['missing'] > $w['missing'] ? $c : $w) : null;
        if ($worst && $worst['missing'] > 0) {
            $alerts[] = ['type' => 'missing', 'customer' => $worst['name'], 'cartons' => $worst['missing'],
                'products' => array_slice(array_map(fn($r) => $r['name'], array_filter($worst['lines'], fn($r) => $r['missing'] > 0)), 0, 3)];
        }
        $all = array_merge(...array_map(fn($c) => $c['lines'], $clients ?: [['lines' => []]]));
        $allPallets = array_merge([], ...array_values($pallets));
        return [
            'date' => $date, 'dates' => $s['day']['dates'], 'now' => date('Y-m-d H:i:s'),
            'departure' => $this->departure($s['day']),
            'totals' => [
                'ordered' => $this->sum($all, 'ordered'), 'on_pallet' => $this->sum($all, 'on_pallet'), 'stock' => $this->sum($all, 'stock'),
                'packed' => $this->sum($all, 'packed'), 'ready' => $this->sum($all, 'ready'), 'missing' => $this->sum($all, 'missing'),
                'pallets' => count($allPallets), 'pallets_open' => count(array_filter($allPallets, fn($p) => $p['open'])),
                'planned_pallets' => array_sum(array_column($clients, 'planned_pallets')),
            ],
            'clients' => $clients, 'alerts' => $alerts,
            'last_activity' => $this->repo->lastActivity($date),
        ];
    }

    /** Najwcześniejszy wyjazd dnia (pojazdy z klientami), inaczej domyślny. */
    private function departure(array $day): string
    {
        $times = array_map(fn($t) => $t['departure'], array_filter($day['fleet'], fn($t) => $t['customers']));
        return $times ? min($times) : $day['settings']['default_departure'];
    }

    // --- Pakowanie -----------------------------------------------------------------------------------

    public function packing(string $date): array
    {
        $this->checkDate($date);
        $s = $this->state($date);
        $packing = $this->repo->packing($date);
        $items = [];
        foreach ($s['customers'] as $c) {
            foreach ($c['lines'] as $l) {
                if ($l['to_pack'] <= 0) continue;
                $pid = $l['product_id'];
                $items[$pid] ??= ['product_id' => $pid, 'name' => $l['name'], 'category' => $l['category'], 'ppc' => $l['ppc'], 'need' => 0, 'clients' => []];
                $items[$pid]['need'] += $l['to_pack'];
                $items[$pid]['clients'][] = ['name' => $c['name'], 'cartons' => $l['to_pack']];
            }
        }
        // Spakowane dziś, których już nie trzeba (etykiety na stanie) : zostają na liście jako gotowe.
        foreach ($packing as $pid => $p) {
            if (isset($items[$pid]) || $p['packed'] <= 0) continue;
            foreach ($s['customers'] as $c) foreach ($c['lines'] as $l) {
                if ($l['product_id'] !== $pid || isset($items[$pid])) continue;
                $items[$pid] = ['product_id' => $pid, 'name' => $l['name'], 'category' => $l['category'], 'ppc' => $l['ppc'], 'need' => 0, 'clients' => []];
            }
        }
        $norms = $this->repo->packNorms(array_keys($items));
        $avg = $this->repo->averagePackNorm() ?? ['fixed' => self::PACK_FIXED_S, 'per_piece' => self::PACK_PER_PIECE_S];
        foreach ($items as $pid => &$i) {
            $pending = (int) ($s['pending'][$pid] ?? 0);
            $i['packed'] = min($pending, $i['need']) + ($i['need'] === 0 ? (int) ($packing[$pid]['packed'] ?? 0) : 0);
            $i['remaining'] = max(0, $i['need'] - $pending);
            $i['done'] = $i['remaining'] === 0;
            $i['pieces'] = $i['remaining'] * $i['ppc'];
            $norm = $norms[$pid] ?? null;
            $i['norm'] = $norm !== null;
            $n = $norm ?? $avg;
            $i['seconds'] = $i['remaining'] > 0 ? (int) round($n['fixed'] + $n['per_piece'] * $i['pieces']) : 0;
        }
        unset($i);
        $items = array_values($items);
        usort($items, fn($a, $b) => [$a['done'], (string) $a['category'], $a['name']] <=> [$b['done'], (string) $b['category'], $b['name']]);
        $code = (string) ($this->params->get('logistics.packing_group', 'PAKOWANIE') ?? 'PAKOWANIE');
        $present = $this->repo->presentInGroup($code);
        $seconds = (int) array_sum(array_column($items, 'seconds'));
        $people = max(1, $present);
        $departure = $this->departure($s['day']);
        $finish = time() + (int) ceil($seconds / $people);
        $deadline = strtotime($date . ' ' . $departure);
        return [
            'date' => $date, 'dates' => $s['day']['dates'], 'departure' => $departure, 'now' => date('Y-m-d H:i:s'),
            'items' => $items,
            'totals' => [
                'products' => count($items), 'done' => count(array_filter($items, fn($i) => $i['done'])),
                'need' => (int) array_sum(array_column($items, 'need')), 'remaining' => (int) array_sum(array_column($items, 'remaining')),
                'pieces' => (int) array_sum(array_column($items, 'pieces')), 'seconds' => $seconds,
                'with_norm' => count(array_filter($items, fn($i) => $i['norm'] && !$i['done'])),
            ],
            'people' => ['present' => $present, 'used' => $people, 'group' => $code],
            'finish' => date('Y-m-d H:i:s', $finish), 'in_time' => $finish <= $deadline, 'margin_s' => $deadline - $finish,
            'average_norm' => ['fixed' => round($avg['fixed']), 'per_piece' => round($avg['per_piece'], 1)],
        ];
    }

    /** Licznik pakowania produktu (0–9999 kartonów) ; przy pierwszym wpisie zapamiętany stan kartonów produktu. */
    public function setPacked(string $date, int $productId, mixed $packed, ?int $userId): array
    {
        $this->checkDate($date);
        if (!$this->repo->productExists($productId)) throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        if (!is_numeric($packed) || (int) $packed != $packed || (int) $packed < 0 || (int) $packed > 9999) {
            throw new CustomException('Liczba spakowanych kartonów : od 0 do 9999.', 'errors', 422);
        }
        $current = $this->repo->packing($date)[$productId] ?? null;
        $base = $current['base_count'] ?? ($this->repo->stockOrPallet([$productId])[$productId] ?? 0);
        $this->repo->savePacking($date, $productId, (int) $packed, (int) $base, $userId);
        return $this->packing($date);
    }

    // --- Załadunek ciężarówki ------------------------------------------------------------------------

    public function loading(string $date, ?int $vehicleId = null): array
    {
        $this->checkDate($date);
        $day = $this->logistics->day($date);
        $fleet = array_values(array_filter($day['fleet'], fn($t) => $t['customers'] || $t['active']));
        if (!$fleet) {
            return ['date' => $date, 'dates' => $day['dates'], 'vehicles' => [], 'vehicle' => null, 'unassigned' => $this->unassigned($day)];
        }
        $truck = null;
        foreach ($fleet as $t) if ((int) $t['id'] === $vehicleId) $truck = $t;
        $truck ??= array_values(array_filter($fleet, fn($t) => $t['customers']))[0] ?? $fleet[0];
        $plans = $this->repo->plans($date);
        $real = $this->realPallets($date);
        $vehicles = array_map(fn($t) => ['id' => (int) $t['id'], 'label' => $t['label'], 'carrier' => $t['carrier'], 'driver' => $t['driver'],
            'places' => $t['places'] ?? $this->defaultPlaces(), 'departure' => $t['departure'], 'customers' => count($t['customers']),
            'pallets' => (int) $t['pallets'], 'over' => (bool) $t['over'], 'sent' => isset($plans[(int) $t['id']]) && $plans[(int) $t['id']]['sent_at'] !== null], $fleet);
        return ['date' => $date, 'dates' => $day['dates'], 'vehicles' => $vehicles, 'unassigned' => $this->unassigned($day)]
            + ['vehicle' => $this->truckPlan($date, $day, $truck, $plans[(int) $truck['id']] ?? null, $real)];
    }

    private function defaultPlaces(): int
    {
        return max(1, (int) ($this->params->get('logistics.default_pallet_places', '33') ?? 33));
    }

    private function unassigned(array $day): array
    {
        return array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'town' => $c['town'], 'pallets' => (int) $c['pallets'],
            'cartons' => (float) $c['cartons']], $day['unassigned']);
    }

    /** @return array<int, list<array{id:int, cartons:int, open:bool}>> klient → palety zeskanowane dnia */
    private function realPallets(string $date): array
    {
        $out = [];
        foreach ($this->repo->pallets($date) as $p) {
            $out[(int) $p['id_customer']][] = ['id' => (int) $p['id_palet'], 'cartons' => (int) $p['cartons'], 'open' => (int) $p['status'] === 1];
        }
        return $out;
    }

    private function truckPlan(string $date, array $day, array $truck, ?array $plan, array $real): array
    {
        $places = $truck['places'] ?? $this->defaultPlaces();
        $customers = [];
        foreach (array_values($truck['customers']) as $i => $cid) {
            $c = $day['by_id'][$cid];
            $customers[] = ['id' => $cid, 'stop' => $i + 1, 'name' => $c['name'], 'town' => $c['town'], 'cartons' => (float) $c['cartons'],
                'weight_kg' => (float) $c['weight_kg'], 'pallets' => max((int) $c['pallets'], count($real[$cid] ?? [])), 'real' => $real[$cid] ?? []];
        }
        $draft = $plan && $plan['layout'] !== null ? json_decode((string) $plan['layout'], true) : null;
        [$layout, $overflow] = $this->normalize(is_array($draft) ? $draft : [], $customers, $places);
        $sent = $plan && $plan['sent_layout'] !== null ? json_decode((string) $plan['sent_layout'], true) : null;
        $checks = $plan && $plan['sent_at'] !== null ? $this->repo->checks($date, (int) $truck['id']) : [];
        return [
            'id' => (int) $truck['id'], 'label' => $truck['label'], 'carrier' => $truck['carrier'], 'driver' => $truck['driver'],
            'places' => $places, 'columns' => self::COLUMNS, 'departure' => $truck['departure'], 'loading_from' => $truck['loading_from'],
            'weight_kg' => (float) $truck['weight_kg'], 'max_kg' => $truck['max_kg'],
            'customers' => $customers, 'layout' => $layout, 'overflow' => $overflow, 'auto' => !is_array($draft),
            'used' => count($layout),
            'sent' => $plan && $plan['sent_at'] !== null ? ['version' => (int) $plan['version'], 'at' => $plan['sent_at'], 'by' => $this->repo->userName($plan['sent_by'] !== null ? (int) $plan['sent_by'] : null),
                'changed' => $this->key($layout) !== $this->key(is_array($sent) ? $sent : []), 'loaded' => count($checks), 'total' => is_array($sent) ? count($sent) : 0] : null,
        ];
    }

    private function key(array $layout): string
    {
        usort($layout, fn($a, $b) => (int) $a['slot'] <=> (int) $b['slot']);
        return json_encode(array_map(fn($s) => [(int) $s['slot'], (int) $s['customer_id'], $s['pallet_id'] !== null ? (int) $s['pallet_id'] : null], $layout));
    }

    /**
     * Układ naczepy : miejsca 1…N (1 = przy kabinie, rząd po COLUMNS miejsc). Zachowane miejsca z zapisanego układu, gdy klient
     * jest w pojeździe i ma jeszcze paletę ; numer palety tylko zeskanowanej palety klienta. Brakujące palety (najpierw
     * zeskanowane, potem planowane) na wolne miejsca od kabiny, ostatni przystanek pierwszy (pierwszy przystanek przy drzwiach).
     * @return array{0: list<array{slot:int, customer_id:int, pallet_id:?int}>, 1: array<int,int>} układ, klient → palety bez miejsca
     */
    private function normalize(array $draft, array $customers, int $places): array
    {
        $byId = array_column($customers, null, 'id');
        $used = [];
        $count = [];
        $placedIds = [];
        $layout = [];
        foreach ($draft as $s) {
            $slot = (int) ($s['slot'] ?? 0);
            $cid = (int) ($s['customer_id'] ?? 0);
            if ($slot < 1 || $slot > $places || isset($used[$slot]) || !isset($byId[$cid])) continue;
            if (($count[$cid] ?? 0) >= $byId[$cid]['pallets']) continue;
            $pid = isset($s['pallet_id']) && $s['pallet_id'] !== null ? (int) $s['pallet_id'] : null;
            if ($pid !== null && (isset($placedIds[$pid]) || !in_array($pid, array_column($byId[$cid]['real'], 'id'), true))) $pid = null;
            $used[$slot] = true;
            $count[$cid] = ($count[$cid] ?? 0) + 1;
            if ($pid !== null) $placedIds[$pid] = true;
            $layout[$slot] = ['slot' => $slot, 'customer_id' => $cid, 'pallet_id' => $pid];
        }
        // Palety zeskanowane po ustawieniu : numer na miejscu planowanym tego klienta.
        foreach ($customers as $c) {
            foreach ($c['real'] as $p) {
                if (isset($placedIds[$p['id']])) continue;
                foreach ($layout as $slot => $s) {
                    if ($s['customer_id'] === $c['id'] && $s['pallet_id'] === null) { $layout[$slot]['pallet_id'] = $p['id']; $placedIds[$p['id']] = true; break; }
                }
            }
        }
        $overflow = [];
        $free = array_values(array_diff(range(1, $places), array_keys($used)));
        foreach (array_reverse($customers) as $c) {
            $missingReal = array_values(array_filter($c['real'], fn($p) => !isset($placedIds[$p['id']])));
            $todo = $c['pallets'] - ($count[$c['id']] ?? 0);
            for ($k = 0; $k < $todo; $k++) {
                if (!$free) { $overflow[$c['id']] = ($overflow[$c['id']] ?? 0) + 1; continue; }
                $slot = array_shift($free);
                $pid = $missingReal[$k]['id'] ?? null;
                $layout[$slot] = ['slot' => $slot, 'customer_id' => $c['id'], 'pallet_id' => $pid];
            }
        }
        ksort($layout);
        return [array_values($layout), $overflow];
    }

    /**
     * Zapis : klienci pojazdu w kolejności rozładunku (przydział dnia : pojazd i przystanek), klienci zdjęci z pojazdu
     * (bez pojazdu), układ naczepy (null = automatyczny).
     */
    public function saveLoading(array $input, ?int $userId): array
    {
        $date = (string) ($input['date'] ?? '');
        $this->checkDate($date);
        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        $day = $this->logistics->day($date);
        if (!isset($day['all_vehicles'][$vehicleId])) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        $known = fn($cid) => isset($day['by_id'][(int) $cid]);
        $list = array_values(array_unique(array_map('intval', (array) ($input['customers'] ?? []))));
        // « append » : klienci dopisani na koniec pojazdu (przeniesieni z innego pojazdu albo spoza pojazdów).
        if (array_key_exists('append', $input)) {
            $current = [];
            foreach ($day['fleet'] as $t) if ((int) $t['id'] === $vehicleId) $current = array_map('intval', $t['customers']);
            $list = array_values(array_unique(array_merge($current, array_map('intval', (array) $input['append']))));
        }
        foreach ($list as $cid) if (!$known($cid)) throw new CustomException('Klient bez zamówień na ten dzień.', 'errors', 422);
        foreach ($list as $i => $cid) $this->repo->setVehicleStop($date, $cid, $vehicleId, $i + 1, $userId);
        foreach (array_map('intval', (array) ($input['unassign'] ?? [])) as $cid) {
            if ($known($cid) && !in_array($cid, $list, true)) $this->repo->setVehicleStop($date, $cid, null, null, $userId);
        }
        if (array_key_exists('layout', $input)) {
            $layout = $input['layout'] === null ? null : array_values(array_map(fn($s) => ['slot' => (int) ($s['slot'] ?? 0), 'customer_id' => (int) ($s['customer_id'] ?? 0),
                'pallet_id' => isset($s['pallet_id']) && $s['pallet_id'] !== null && $s['pallet_id'] !== '' ? (int) $s['pallet_id'] : null], (array) $input['layout']));
            $this->repo->saveLayout($date, $vehicleId, $layout, $userId);
            // Zapisany układ = układ pokazany (miejsca poprawione, palety dołożone) : nie przesuwa się przy następnej zmianie.
            $state = $this->loading($date, $vehicleId);
            if ($layout !== null && $state['vehicle'] !== null && $state['vehicle']['id'] === $vehicleId) {
                $this->repo->saveLayout($date, $vehicleId, $state['vehicle']['layout'], $userId);
            }
            return $state;
        }
        return $this->loading($date, $vehicleId);
    }

    /** Wyślij na tablet kierowcy : bieżący układ (automatyczny, jeśli nie ruszany) jako nowa wersja. */
    public function sendLoading(array $input, ?int $userId): array
    {
        $date = (string) ($input['date'] ?? '');
        $state = $this->loading($date, (int) ($input['vehicle_id'] ?? 0));
        $v = $state['vehicle'];
        if ($v === null || $v['id'] !== (int) ($input['vehicle_id'] ?? 0)) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        if (!$v['customers']) throw new CustomException('Pojazd nie ma klientów na ten dzień — nie ma czego wysłać.', 'errors', 422);
        $plan = $this->repo->plan($date, $v['id']);
        $old = $plan && $plan['sent_layout'] !== null ? (json_decode((string) $plan['sent_layout'], true) ?: []) : [];
        $oldBySlot = [];
        foreach ($old as $s) $oldBySlot[(int) $s['slot']] = [(int) $s['customer_id'], $s['pallet_id'] ?? null];
        $changed = [];
        $newSlots = [];
        foreach ($v['layout'] as $s) {
            $newSlots[$s['slot']] = true;
            if (($oldBySlot[$s['slot']] ?? null) !== [$s['customer_id'], $s['pallet_id']]) $changed[] = $s['slot'];
        }
        foreach (array_keys($oldBySlot) as $slot) if (!isset($newSlots[$slot])) $changed[] = $slot;
        $this->repo->clearChecks($date, $v['id'], $changed);
        $this->repo->send($date, $v['id'], $v['layout'], $userId);
        return $this->loading($date, $v['id']);
    }

    /**
     * Plan wysłany kierowcy : miejsca z klientem (numer przystanku wg trasy dnia), numer palety, załadowane.
     * null — biuro jeszcze nie wysłało planu dla tego pojazdu.
     */
    public function driverPlan(string $date, int $vehicleId): ?array
    {
        $plan = $this->repo->plan($date, $vehicleId);
        if (!$plan || $plan['sent_at'] === null) return null;
        $layout = json_decode((string) $plan['sent_layout'], true) ?: [];
        $day = $this->logistics->day($date);
        $truck = null;
        foreach ($day['fleet'] as $t) if ((int) $t['id'] === $vehicleId) $truck = $t;
        $stopOf = array_flip(array_values($truck['customers'] ?? []));
        $checks = $this->repo->checks($date, $vehicleId);
        $slots = [];
        $stops = [];
        foreach ($layout as $s) {
            $cid = (int) $s['customer_id'];
            $n = isset($stopOf[$cid]) ? $stopOf[$cid] + 1 : null;
            $slot = ['slot' => (int) $s['slot'], 'customer_id' => $cid, 'stop' => $n, 'pallet_id' => $s['pallet_id'] ?? null, 'loaded' => isset($checks[(int) $s['slot']])];
            $slots[] = $slot;
            $stops[$cid] ??= ['customer_id' => $cid, 'stop' => $n, 'name' => $day['by_id'][$cid]['name'] ?? ('#' . $cid), 'town' => $day['by_id'][$cid]['town'] ?? null, 'slots' => []];
            $stops[$cid]['slots'][] = $slot;
        }
        $stops = array_values($stops);
        usort($stops, fn($a, $b) => [$a['stop'] ?? 999, $a['name']] <=> [$b['stop'] ?? 999, $b['name']]);
        return [
            'version' => (int) $plan['version'], 'sent_at' => $plan['sent_at'], 'places' => $truck['places'] ?? max(count($layout), $this->defaultPlaces()),
            'columns' => self::COLUMNS, 'slots' => $slots, 'stops' => $stops,
            'loaded' => count(array_filter($slots, fn($s) => $s['loaded'])), 'total' => count($slots),
        ];
    }

    /** Krótko dla ekranu trasy kierowcy : wersja planu, załadowane / wszystkie miejsca (null — brak planu). */
    public function driverSummary(string $date, int $vehicleId): ?array
    {
        $plan = $this->repo->plan($date, $vehicleId);
        if (!$plan || $plan['sent_at'] === null) return null;
        $layout = json_decode((string) $plan['sent_layout'], true) ?: [];
        return ['version' => (int) $plan['version'], 'sent_at' => $plan['sent_at'], 'total' => count($layout),
            'loaded' => count(array_intersect_key($this->repo->checks($date, $vehicleId), array_flip(array_map(fn($s) => (int) $s['slot'], $layout))))];
    }

    /** Kierowca : miejsce załadowane / nie (tylko miejsca wysłanego planu). */
    public function driverCheck(string $date, int $vehicleId, int $slot, bool $loaded, ?int $deviceId, ?int $employeeId): ?array
    {
        $plan = $this->driverPlan($date, $vehicleId);
        if ($plan === null || !in_array($slot, array_column($plan['slots'], 'slot'), true)) return null;
        $this->repo->setCheck($date, $vehicleId, $slot, $loaded, $deviceId, $employeeId);
        return $this->driverPlan($date, $vehicleId);
    }

    private function checkDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
    }
}
