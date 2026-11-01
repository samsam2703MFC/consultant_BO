<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\Translator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\CapacityRepository;
use App\Repositories\Workshop\MachineDataRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Dane maszyn [240] (makiety 12–13) — kierownik produkcji uzupełnia dane maszyna po maszynie :
 *  - przegląd : maszyny z produktami, produkty z danymi maszyn, produkty planu tygodnia bez danych ; maszyny wg grup
 *    ze stanem (do uzupełnienia / N produktów), grupy bez maszyn, następna maszyna do uzupełnienia ;
 *  - kreator maszyny : krok 1 — produkty na tej maszynie (grupa, bez danych, plan tygodnia, wszystkie) ; krok 2 — dla
 *    każdego : sztuk w 1 wsadzie, wsadów na godzinę, osób przy wsadzie ; krok 3 — zapis i następna maszyna.
 * Zapis = etap karty produkcyjnej produktu na tej maszynie (n_prod_routing_step) : istniejący etap na tej maszynie
 * zmienia wsad, czas (3600 ÷ wsadów na godzinę, bez czasu na sztukę) i liczbę osób ; brak etapu — nowy etap na końcu
 * karty. Wiersze bez wsadu albo tempa i wiersze bez zmian — pomijane. Ślad w dzienniku zmian (item_routing_step).
 */
final class MachineDataService
{
    /** Kolejność « następnej maszyny » : najpierw piece, potem maszyny, roboty, komory… */
    private const TYPE_ORDER = ['four' => 1, 'machine' => 2, 'robot' => 3, 'surgelation' => 4, 'froid' => 5, 'fermentation' => 6, 'manuel' => 7];
    public const ICONS = ['froid' => 'bi-thermometer-snow', 'surgelation' => 'bi-snow', 'four' => 'bi-fire', 'machine' => 'bi-gear', 'fermentation' => 'bi-hourglass-split', 'robot' => 'bi-robot', 'manuel' => 'bi-person-workspace'];
    private const TYPE_NAMES = ['froid' => 'chłodnia', 'surgelation' => 'mrożenie', 'four' => 'piec', 'machine' => 'maszyna', 'fermentation' => 'rozrost', 'robot' => 'robot', 'manuel' => 'stanowisko ręczne'];
    private const MAX_PER_BATCH = 1000000;
    private const MAX_PER_HOUR = 600;
    private const MAX_PEOPLE = 50;

    public function __construct(
        private MachineDataRepository $repo,
        private CapacityRepository $capacityRepo,
        private CapacityService $capacity,
        private WeekService $week,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /** Przegląd (makieta 12). */
    public function overview(?string $date = null): array
    {
        $ws = $this->capacityRepo->workstations();
        $products = $this->repo->products();
        $byItem = $this->stepsByItem();
        $plan = $this->planItems($date);
        $groups = $this->repo->groups();
        $groupProducts = [];
        $groupWith = [];
        foreach ($products as $p) {
            $g = $p['id_primary_group'] !== null ? (int) $p['id_primary_group'] : 0;
            $groupProducts[$g] = ($groupProducts[$g] ?? 0) + 1;
            if (isset($byItem[(int) $p['id']])) $groupWith[$g] = ($groupWith[$g] ?? 0) + 1;
        }
        $onMachine = [];
        $incomplete = [];
        foreach ($byItem as $itemId => $steps) foreach ($steps as $s) {
            $w = (int) $s['id_workstation'];
            $onMachine[$w][$itemId] = true;
            if ((int) $s['fixed_time_s'] === 0 && (float) $s['variable_time_s'] == 0.0) $incomplete[$w][$itemId] = true;
        }
        $known = array_flip(array_map(fn($p) => (int) $p['id'], $products));
        $names = array_column($products, 'name', 'id');
        $planWithout = [];
        foreach ($plan as $id => $_) if (isset($known[$id]) && !isset($byItem[$id])) $planWithout[$id] = $names[$id] ?? ('#' . $id);
        $planGroups = [];
        foreach ($products as $p) if (isset($planWithout[(int) $p['id']]) && $p['id_primary_group'] !== null) $planGroups[(int) $p['id_primary_group']] = true;

        $out = [];
        $machinesWith = 0;
        $candidates = [];
        foreach ($groups as $gi => $g) {
            $rows = [];
            foreach ($ws as $w) {
                if ((int) ($w['id_group'] ?? 0) !== $g['id']) continue;
                $rows[] = $this->machineRow($w, count($onMachine[(int) $w['id']] ?? []), count($incomplete[(int) $w['id']] ?? []));
            }
            foreach ($rows as $r) {
                if ($r['products'] > 0) $machinesWith++;
                else $candidates[] = ['id' => $r['id'], 'key' => [isset($planGroups[$g['id']]) ? 0 : 1, self::TYPE_ORDER[$r['type']] ?? 9, $gi, $r['name']]];
            }
            $out[] = ['id' => $g['id'], 'name' => $g['name_pl'], 'products' => $groupProducts[$g['id']] ?? 0, 'products_with' => $groupWith[$g['id']] ?? 0, 'machines' => $rows];
        }
        // Maszyny bez grupy (np. wspólne) — osobna sekcja.
        $loose = [];
        foreach ($ws as $w) if ($w['id_group'] === null) $loose[] = $this->machineRow($w, count($onMachine[(int) $w['id']] ?? []), count($incomplete[(int) $w['id']] ?? []));
        foreach ($loose as $r) { if ($r['products'] > 0) $machinesWith++; else $candidates[] = ['id' => $r['id'], 'key' => [1, self::TYPE_ORDER[$r['type']] ?? 9, 99, $r['name']]]; }
        if ($loose) $out[] = ['id' => null, 'name' => 'Bez grupy', 'products' => 0, 'products_with' => 0, 'machines' => $loose];
        usort($candidates, fn($a, $b) => $a['key'] <=> $b['key']);

        $withData = count(array_filter($products, fn($p) => isset($byItem[(int) $p['id']])));
        return [
            'tiles' => [
                'machines_with' => $machinesWith, 'machines' => count($ws),
                'products_with' => $withData, 'products' => count($products),
                'plan_without' => count($planWithout), 'plan' => count(array_intersect_key($plan, $known)),
                'plan_without_names' => array_values(array_slice($planWithout, 0, 4)),
            ],
            'todo' => count($ws) - $machinesWith,
            'groups' => $out,
            'next' => $candidates[0]['id'] ?? null,
            'order' => array_column($candidates, 'id'),
        ];
    }

    /** Kreator maszyny : maszyna i wszystkie produkty (z danymi tej maszyny, jeśli są). */
    public function machine(int $id, ?string $date = null): array
    {
        $w = $this->repo->workstation($id);
        if (!$w || (int) $w['active'] !== 1) throw new CustomException('Nie znaleziono maszyny.', 'errors', 404);
        $byItem = $this->stepsByItem();
        $plan = $this->planItems($date);
        $groupNames = array_column($this->repo->groups(), 'name_pl', 'id');
        $out = [];
        foreach ($this->repo->products() as $p) {
            $iid = (int) $p['id'];
            $steps = $byItem[$iid] ?? [];
            $mine = null;
            $others = [];
            foreach ($steps as $s) {
                if ((int) $s['id_workstation'] === $id && $mine === null) $mine = $s;
                elseif (!in_array($s['workstation'], $others, true)) $others[] = $s['workstation'];
            }
            $row = ['id' => $iid, 'name' => $p['name'], 'group_id' => $p['id_primary_group'] !== null ? (int) $p['id_primary_group'] : null,
                'group' => $p['id_primary_group'] !== null ? ($groupNames[(int) $p['id_primary_group']] ?? null) : null, 'semi' => $p['item_type'] === 'semi',
                'unit' => $p['base_unit'] === 'kg' ? 'kg' : 'szt.', 'ppc' => $p['ppc'] !== null && (float) $p['ppc'] > 0 ? (float) $p['ppc'] : null,
                'in_plan' => isset($plan[$iid]), 'has_data' => (bool) $steps, 'others' => $others, 'on' => $mine !== null,
                'per_batch' => null, 'per_hour' => null, 'people' => null];
            if ($mine) {
                $cap = (float) $mine['capacity_per_pass'];
                $sec = (float) $mine['fixed_time_s'] + (float) $mine['variable_time_s'] * $cap;
                $row['per_batch'] = round($cap, 2);
                $row['per_hour'] = $sec > 0 ? round(3600 / $sec, 2) : null;
                $row['people'] = round((float) $mine['operators_required'], 2);
            }
            $out[] = $row;
        }
        $shiftH = 8.0;
        foreach ($this->capacityRepo->shifts() as $s) { $shiftH = $this->capacity->shiftSeconds($s) / 3600; break; }
        return [
            'machine' => ['id' => (int) $w['id'], 'name' => $w['name_pl'], 'type' => $w['type'], 'type_name' => self::TYPE_NAMES[$w['type']] ?? $w['type'],
                'icon' => self::ICONS[$w['type']] ?? 'bi-gear', 'group_id' => $w['id_group'] !== null ? (int) $w['id_group'] : null, 'group' => $w['group_name'],
                'capacity' => (float) $w['capacity_per_pass'], 'unit' => CapacityService::UNITS[$w['capacity_unit']] ?? $w['capacity_unit'],
                'efficiency_pct' => (float) $w['efficiency_pct'], 'shift_h' => round($shiftH, 2)],
            'products' => $out,
        ];
    }

    /**
     * Zapis kreatora (krok 2) : rows = [{item_id, per_batch, per_hour, people}]. @return array{created:int, updated:int,
     * unchanged:int, empty:int, load_pct:?float, next:?int}
     */
    public function save(int $id, array $rows, ?int $userId): array
    {
        $w = $this->repo->workstation($id);
        if (!$w || (int) $w['active'] !== 1) throw new CustomException('Nie znaleziono maszyny.', 'errors', 404);
        $known = [];
        foreach ($this->repo->products() as $p) $known[(int) $p['id']] = $p['name'];
        $plan = [];
        $empty = 0;
        $seen = [];
        foreach (array_values($rows) as $n => $r) {
            if (!is_array($r)) throw new CustomException('Nieprawidłowe dane.', 'errors', 422);
            $itemId = (int) ($r['item_id'] ?? 0);
            if (!isset($known[$itemId])) throw new CustomException('Nie znaleziono produktu (wiersz ' . ($n + 1) . ').', 'errors', 422);
            if (isset($seen[$itemId])) continue;
            $seen[$itemId] = true;
            $batch = self::num($r['per_batch'] ?? null);
            $hour = self::num($r['per_hour'] ?? null);
            $people = self::num($r['people'] ?? null);
            $label = $known[$itemId];
            if ($batch === null || $hour === null) { $empty++; continue; }
            if ($batch <= 0 || $batch > self::MAX_PER_BATCH) throw new CustomException("{$label} : sztuk w wsadzie od 0,01 do " . self::MAX_PER_BATCH . '.', 'errors', 422);
            if ($hour <= 0 || $hour > self::MAX_PER_HOUR) throw new CustomException("{$label} : wsadów na godzinę od 0,01 do " . self::MAX_PER_HOUR . '.', 'errors', 422);
            if ($people !== null && ($people < 0 || $people > self::MAX_PEOPLE)) throw new CustomException("{$label} : osób przy wsadzie od 0 do " . self::MAX_PEOPLE . '.', 'errors', 422);
            $plan[$itemId] = ['capacity_per_pass' => round($batch, 2), 'fixed_time_s' => max(1, (int) round(3600 / $hour)), 'variable_time_s' => 0, 'people' => $people];
        }
        $ctx = new ApiContext('machine-data-' . bin2hex(random_bytes(4)), new Translator('pl'), $userId, [], ['prod.products.write' => true]);
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $this->tx->execute(function () use ($id, $w, $plan, $ctx, &$created, &$updated, &$unchanged) {
            foreach ($plan as $itemId => $f) {
                $cur = $this->repo->stepOn($itemId, $id);
                $people = $f['people'] ?? ($cur !== null ? (float) $cur['operators_required'] : 1.0);
                $new = ['capacity_per_pass' => $f['capacity_per_pass'], 'fixed_time_s' => $f['fixed_time_s'], 'variable_time_s' => 0, 'operators_required' => round($people, 2)];
                if ($cur !== null) {
                    $before = ['capacity_per_pass' => (float) $cur['capacity_per_pass'], 'fixed_time_s' => (int) $cur['fixed_time_s'],
                        'variable_time_s' => (float) $cur['variable_time_s'], 'operators_required' => (float) $cur['operators_required']];
                    $sec = $before['fixed_time_s'] + $before['variable_time_s'] * $before['capacity_per_pass'];
                    $same = abs($before['capacity_per_pass'] - $new['capacity_per_pass']) < 0.005 && abs($sec - $new['fixed_time_s']) <= max(1.0, $sec * 0.005)
                        && abs($before['operators_required'] - $new['operators_required']) < 0.005;
                    if ($same) { $unchanged++; continue; }
                    $this->repo->updateStep((int) $cur['id'], $new);
                    $this->audit->record($ctx, 'update', 'item_routing_step', (int) $cur['id'], $before, $new + ['source' => 'kreator maszyny']);
                    $updated++;
                } else {
                    $row = $new + ['id_item' => $itemId, 'step_no' => $this->repo->nextStepNo($itemId), 'name_pl' => mb_substr((string) $w['name_pl'], 0, 100),
                        'name_fr' => mb_substr((string) ($w['name_fr'] ?: $w['name_pl']), 0, 100), 'id_workstation' => $id];
                    $stepId = $this->repo->insertStep($row);
                    $this->audit->record($ctx, 'create', 'item_routing_step', $stepId, null, $row + ['source' => 'kreator maszyny']);
                    $created++;
                }
            }
        });
        $load = null;
        try {
            foreach ($this->capacity->data(null)['sections'] as $s) foreach ($s['rows'] as $m) if ((int) $m['id'] === $id) $load = $m['pct'];
        } catch (\Throwable) {
            $load = null;
        }
        $order = $this->overview()['order'];
        $next = null;
        foreach ($order as $mid) if ((int) $mid !== $id) { $next = (int) $mid; break; }
        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'empty' => $empty, 'load_pct' => $load, 'next' => $next,
            'next_machine' => $next !== null ? $this->nextLabel($next) : null];
    }

    private function nextLabel(int $id): ?array
    {
        $w = $this->repo->workstation($id);
        if (!$w) return null;
        return ['id' => $id, 'name' => $w['name_pl'], 'group' => $w['group_name'], 'icon' => self::ICONS[$w['type']] ?? 'bi-gear',
            'capacity' => (float) $w['capacity_per_pass'], 'unit' => CapacityService::UNITS[$w['capacity_unit']] ?? $w['capacity_unit']];
    }

    private function machineRow(array $w, int $products, int $incomplete): array
    {
        return ['id' => (int) $w['id'], 'name' => $w['name_pl'], 'type' => $w['type'], 'type_name' => self::TYPE_NAMES[$w['type']] ?? $w['type'],
            'icon' => self::ICONS[$w['type']] ?? 'bi-gear', 'capacity' => (float) $w['capacity_per_pass'],
            'unit' => CapacityService::UNITS[$w['capacity_unit']] ?? $w['capacity_unit'], 'products' => $products, 'incomplete' => $incomplete,
            'state' => $products === 0 ? 'todo' : ($incomplete > 0 ? 'part' : 'ok')];
    }

    /** @return array<int, list<array>> */
    private function stepsByItem(): array
    {
        $out = [];
        foreach ($this->repo->steps() as $s) $out[(int) $s['id_item']][] = $s;
        return $out;
    }

    /** Produkty planu tygodnia (bieżącego albo z datą). @return array<int, true> */
    private function planItems(?string $date): array
    {
        try {
            $q = $this->week->quantities($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d'));
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($q['items'] ?? [] as $id => $_) $out[(int) $id] = true;
        return $out;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        $s = str_replace([' ', ','], ['', '.'], trim((string) $v));
        if ($s === '' || !is_numeric($s)) throw new CustomException('Wpisz liczbę.', 'errors', 422);
        return (float) $s;
    }
}
