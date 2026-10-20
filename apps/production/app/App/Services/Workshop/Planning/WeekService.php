<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Repositories\Workshop\SchedulingRepository;
use App\Repositories\Workshop\WeekRepository;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Referentiel\AuditTrail;
use App\Services\Workshop\Scheduling\SchedulingService;

/**
 * Gantt tygodnia : produkcja jest tygodniowa, jeden blok = jeden produkt w dniu.
 *
 * Ilości tygodnia : linie zatwierdzonych (zamrożonych, zamkniętych) planów z terminem w tygodniu,
 * a bez planu — propozycja (zamówienia MTO + uzupełnienie stanu MTS + półprodukty). Godziny = norma
 * czasu z karty (StandardTime) × ilość. Dostępne godziny = obsada grupy × zmiana (8 h) w dni
 * produkcji (week.production_days, domyślnie pn–pt), z obsadą dnia i nadgodzinami.
 * Podział na dni zapisuje się w tfb_prod_week_slot (przeciągnij i upuść albo « Rozdziel na dni ») ;
 * nieprzydzielona reszta czeka w « Do rozplanowania ». Rozdzielanie wypełnia dni po kolei od
 * dzisiaj (jak najwcześniej), najpierw najbliższe terminy, i dzieli produkt na pełne kartony, gdy dzień
 * jest pełny ; nigdy po terminie zamówienia, chyba że termin już minął (wtedy dzień = pierwszy wolny,
 * oznaczony jako po terminie). Wykonanie = udział zakończonych operacji zleceń planu.
 */
final class WeekService
{
    private const EPS = 0.0005;

    public function __construct(
        private WeekRepository $repo,
        private PlanningRepository $planning,
        private PlanningService $plans,
        private SchedulingService $scheduling,
        private SchedulingRepository $schedulingRepo,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function week(string $date, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        return $this->present($this->data($this->monday($date), $context, $now), $context, $now);
    }

    /** Przenosi produkt (cały blok albo część) na inny dzień albo z powrotem do « Do rozplanowania ». */
    public function move(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['date', 'item_id', 'from', 'to', 'quantity'])
            ->date('date', true, false)->integer('item_id', true, 1)->date('from')->date('to')
            ->decimal('quantity', false, 0, 1000000000, true, true);
        $v->throwIfInvalid();
        $data = $this->data($this->monday($v->value('date')), $context, $now);
        $itemId = (int) $v->value('item_id');
        $p = $data['products'][$itemId] ?? throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_IN_WEEK', 'params' => []]]);
        if (!$context->canInGroup('prod.gantt.move', $p['group_id'])) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.gantt.move']);
        }
        $from = $v->value('from');
        $to = $v->value('to');
        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $field => $day) {
            if ($day !== null && !in_array($day, $data['days'], true)) $errors[] = ['field' => $field, 'code' => 'DAY_NOT_IN_WEEK', 'params' => []];
        }
        if ($from === $to) $errors[] = ['field' => 'to', 'code' => 'SAME_DAY', 'params' => []];
        if ($to !== null && $to < $data['today']) $errors[] = ['field' => 'to', 'code' => 'DAY_IN_PAST', 'params' => []];
        if ($errors) throw ApiException::validation($errors);

        $available = $from === null ? $p['unassigned_qty'] : ($p['slots'][$from] ?? 0.0);
        if ($available <= self::EPS) throw ApiException::validation([['field' => 'from', 'code' => 'NOTHING_TO_MOVE', 'params' => []]]);
        $qty = $v->value('quantity') !== null ? (float) $v->value('quantity') : $available;
        if ($qty > $available + self::EPS) {
            throw ApiException::validation([['field' => 'quantity', 'code' => 'OUT_OF_RANGE', 'params' => ['min' => 0, 'max' => round($available, 3)]]]);
        }
        $qty = min($qty, $available);

        $this->tx->execute(function () use ($data, $p, $from, $to, $qty, $context, $itemId) {
            if ($from !== null) $this->repo->setSlot($data['monday'], $itemId, $from, ($p['slots'][$from] ?? 0.0) - $qty, $context->userId);
            if ($to !== null) $this->repo->setSlot($data['monday'], $itemId, $to, ($p['slots'][$to] ?? 0.0) + $qty, $context->userId);
            $this->audit->record($context, 'move', 'week_slot', $itemId, ['week' => $data['monday'], 'day' => $from, 'quantity' => round($qty, 3)],
                ['week' => $data['monday'], 'day' => $to, 'quantity' => round($qty, 3)]);
        });
        return $this->present($this->data($data['monday'], $context, $now), $context, $now);
    }

    /**
     * « Rozdziel na dni » : przydziela to, co czeka w « Do rozplanowania », do dni od dzisiaj.
     * reset = najpierw zdejmuje przydziały od dzisiaj (dni minione zostają) — tylko w grupach,
     * w których użytkownik może przesuwać.
     */
    public function distribute(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['date', 'reset'])->date('date', true, false)->boolean('reset');
        $v->throwIfInvalid();
        $data = $this->data($this->monday($v->value('date')), $context, $now);
        $reset = (bool) $v->value('reset', false);
        $days = array_values(array_filter($data['days'], fn($d) => $d >= $data['today']));
        if (!$days) throw new ApiException('WEEK_IN_PAST', 409, [], ['week' => $data['monday']]);

        $movable = array_filter($data['products'], fn($p) => $context->canInGroup('prod.gantt.move', $p['group_id']));
        if (!$movable) throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.gantt.move']);

        // Godziny już zajęte w dniach (wszystkie grupy, także te, których użytkownik nie przesuwa).
        $used = [];
        foreach ($data['products'] as $id => $p) {
            foreach ($p['slots'] as $day => $qty) {
                if ($reset && isset($movable[$id]) && $day >= $data['today']) continue;
                $used[$p['group_id'] ?? 0][$day] = ($used[$p['group_id'] ?? 0][$day] ?? 0.0) + $this->hours($data['routings'], $id, $qty);
            }
        }
        $writes = [];
        foreach ($movable as $id => &$p) {
            if (!$reset) continue;
            foreach ($p['slots'] as $day => $qty) {
                if ($day < $data['today']) continue;
                $writes[$id][$day] = 0.0;
                $p['unassigned_qty'] += $qty;
                unset($p['slots'][$day]);
            }
        }
        unset($p);

        uasort($movable, fn($a, $b) => [$a['latest_day'], $a['item_type'] === 'semi' ? 0 : 1, $a['mode'] === 'MTO' ? 0 : 1, -$a['hours'], $a['name']]
            <=> [$b['latest_day'], $b['item_type'] === 'semi' ? 0 : 1, $b['mode'] === 'MTO' ? 0 : 1, -$b['hours'], $b['name']]);
        $placed = 0;
        foreach ($movable as $id => $p) {
            if ($p['unassigned_qty'] <= self::EPS) continue;
            $g = $p['group_id'] ?? 0;
            $free = [];
            foreach ($days as $day) $free[$day] = ($data['capacity'][$g][$day]['available_h'] ?? 0.0) - ($used[$g][$day] ?? 0.0);
            foreach ($this->place($p, $days, $free, fn(float $q) => $this->hours($data['routings'], $id, $q)) as $day => $qty) {
                $writes[$id][$day] = ($p['slots'][$day] ?? 0.0) + $qty;
                $used[$g][$day] = ($used[$g][$day] ?? 0.0) + $this->hours($data['routings'], $id, $qty);
                $placed++;
            }
        }

        $this->tx->execute(function () use ($writes, $data, $context, $reset, $placed) {
            foreach ($writes as $id => $byDay) foreach ($byDay as $day => $qty) $this->repo->setSlot($data['monday'], (int) $id, $day, $qty, $context->userId);
            $this->audit->record($context, 'distribute', 'week', $data['monday'], null, ['reset' => $reset, 'slots' => $placed]);
        });
        return $this->present($this->data($data['monday'], $context, $now), $context, $now);
    }

    /**
     * Wypełnia dni po kolei (najwcześniej), w oknie do ostatniego dozwolonego dnia ; dzieli na pełne
     * kartony, gdy dzień jest pełny. To, co się nie mieści, idzie na ostatni dzień okna (przeciążenie).
     * @param array<string,float> $free wolne godziny w dniach @return array<string,float> dzień => ilość
     */
    private function place(array $p, array $days, array $free, callable $hours): array
    {
        $window = array_values(array_filter($days, fn($d) => $d <= $p['latest_day'])) ?: [$days[0]];
        $unit = $p['ppc'] ? (float) $p['ppc'] : ($p['unit'] === 'kg' ? 0.1 : 1.0);
        $qty = $p['unassigned_qty'];
        $out = [];
        foreach ($window as $i => $day) {
            if ($qty <= self::EPS) break;
            $room = max(0.0, $free[$day]);
            if ($i === count($window) - 1 || $hours($qty) <= $room + 0.01) {
                $out[$day] = $qty;
                break;
            }
            if ($room < 0.25) continue;
            $part = floor($qty * $room / max(0.001, $hours($qty)) / $unit) * $unit;
            for ($n = 0; $part > 0 && $hours($part) > $room + 0.01 && $n < 50; $n++) $part -= $unit;
            if ($part < $unit - self::EPS || $part >= $qty - self::EPS) continue;
            $out[$day] = round($part, 3);
            $qty = round($qty - $part, 3);
        }
        return $out;
    }

    /** Dane tygodnia : dni, źródło, produkty z przydziałami, zdolność grup, wykonanie. */
    private function data(string $monday, ApiContext $context, int $now): array
    {
        $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
        $weekdays = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($weekdays);
        $days = array_map(fn($d) => date('Y-m-d', strtotime($monday . ' +' . ($d - 1) . ' days')), array_unique($weekdays));
        $lastDay = end($days);

        $items = [];
        foreach ($this->planning->items() as $it) $items[(int) $it['id']] = $it;
        $routings = $this->planning->routings();

        // Źródło : plany aktywne (zatwierdzone, zamrożone), inaczej zamknięte, inaczej propozycja.
        $plans = $this->repo->plans($monday, $sunday);
        $active = array_values(array_filter($plans, fn($p) => $p['status'] !== 'closed'));
        $plans = $active ?: $plans;
        $lines = [];
        $warnings = [];
        if ($plans) {
            foreach ($plans as $plan) {
                foreach ($this->planning->lines((int) $plan['id']) as $l) {
                    if ($l['due_date'] < $monday || $l['due_date'] > $sunday) continue;
                    $lines[] = ['item_id' => (int) $l['id_item'], 'qty' => (float) $l['qty_final'], 'due_date' => $l['due_date'],
                        'dated' => (float) $l['qty_mto'] > 0 || (float) $l['qty_semi'] > 0];
                }
            }
        } else {
            $demand = $this->plans->compute($monday, $sunday);
            foreach ($demand['lines'] as $l) {
                $lines[] = ['item_id' => (int) $l['item']['id'], 'qty' => (float) $l['qty_proposed'], 'due_date' => $l['due_date'],
                    'dated' => $l['qty_mto'] > 0 || $l['qty_semi'] > 0];
            }
            foreach ($demand['warnings'] as $w) if ($w['code'] === 'UNPLANNED_PRODUCTS') $warnings[] = $w;
        }

        $products = [];
        foreach ($lines as $l) {
            $it = $items[$l['item_id']] ?? null;
            if ($it === null || $l['qty'] <= 0) continue;
            $p = &$products[$l['item_id']];
            $p ??= ['item_id' => $l['item_id'], 'name' => $it['name'], 'item_type' => $it['item_type'], 'mode' => $it['mode'],
                'group_id' => $it['id_primary_group'] !== null ? (int) $it['id_primary_group'] : null, 'unit' => $it['base_unit'],
                'ppc' => $it['item_type'] === 'product' && (int) $it['ppc'] > 0 ? (int) $it['ppc'] : null,
                'quantity' => 0.0, 'due_date' => null, 'slots' => []];
            $p['quantity'] += $l['qty'];
            if ($l['dated'] && ($p['due_date'] === null || $l['due_date'] < $p['due_date'])) $p['due_date'] = $l['due_date'];
            unset($p);
        }
        foreach ($products as $id => &$p) {
            $p['quantity'] = round($p['quantity'], 3);
            $p['hours'] = $this->hours($routings, $id, $p['quantity']);
            // Ostatni dzień produkcji nie później niż termin (MTO, półprodukt) ; MTS — do końca tygodnia.
            $p['latest_day'] = $lastDay;
            if ($p['due_date'] !== null) {
                $before = array_values(array_filter($days, fn($d) => $d <= $p['due_date']));
                $p['latest_day'] = $before ? end($before) : $days[0];
            }
        }
        unset($p);
        foreach ($this->repo->slots($monday) as $s) {
            $id = (int) $s['id_item'];
            if (isset($products[$id])) $products[$id]['slots'][$s['work_date']] = (float) $s['quantity'];
        }
        foreach ($products as &$p) {
            $assigned = array_sum($p['slots']);
            $p['unassigned_qty'] = round(max(0.0, $p['quantity'] - $assigned), 3);
            $p['over_qty'] = round(max(0.0, $assigned - $p['quantity']), 3);
        }
        unset($p);

        // Wykonanie : udział godzin zakończonych operacji zleceń planu w godzinach wszystkich operacji artykułu.
        $labour = [];
        foreach ($this->repo->operations(array_map(fn($p) => (int) $p['id'], $plans)) as $op) {
            $operators = $op['kind'] === 'work' ? (float) ($op['operators_required'] ?? 1) : ($op['kind'] === 'cleaning' ? 1.0 : 0.0);
            $h = max(0, strtotime($op['planned_end']) - strtotime($op['planned_start'])) / 3600 * $operators;
            $id = (int) $op['id_item'];
            $labour[$id]['total'] = ($labour[$id]['total'] ?? 0.0) + $h;
            if ($op['status'] === 'done') $labour[$id]['done'] = ($labour[$id]['done'] ?? 0.0) + $h;
            if (in_array($op['status'], ['in_progress', 'paused'], true)) $labour[$id]['running'] = true;
        }
        foreach ($products as $id => &$p) {
            $l = $labour[$id] ?? null;
            $share = $l && $l['total'] > 0 ? min(1.0, ($l['done'] ?? 0.0) / $l['total']) : 0.0;
            $p['done_h'] = round($p['hours'] * $share, 3);
            $p['state'] = $share >= 0.999 ? 'done' : ($share > 0 || !empty($l['running']) ? 'in_progress' : 'planned');
        }
        unset($p);

        // Zdolność : obsada × zmiana w dni produkcji.
        $capacity = [];
        foreach ($this->scheduling->load($monday, $sunday, $context) as $row) {
            if (!in_array($row['date'], $days, true)) continue;
            $c = &$capacity[$row['group_id']][$row['date']];
            $c ??= ['available_h' => 0.0, 'people' => 0, 'start' => $row['start'], 'end' => $row['end']];
            $c['available_h'] += $row['available_h'];
            $c['people'] += $row['headcount'];
            if ($row['available_h'] > 0) {
                $c['start'] = min($c['start'], $row['start']);
                $c['end'] = max($c['end'], $row['end']);
            }
            unset($c);
        }

        return ['monday' => $monday, 'sunday' => $sunday, 'days' => array_values($days), 'today' => date('Y-m-d', $now),
            'plans' => $plans, 'products' => $products, 'capacity' => $capacity, 'routings' => $routings, 'warnings' => $warnings];
    }

    private function present(array $data, ApiContext $context, int $now): array
    {
        $lang = $context->translator->lang();
        $today = $data['today'];
        $groups = [];
        foreach ($this->schedulingRepo->groups() as $g) {
            $groups[(int) $g['id']] = ['id' => (int) $g['id'], 'name' => $g['name_' . $lang], 'color' => $g['color']];
        }
        $products = [];
        $lanes = [];
        $warnings = $data['warnings'];
        $late = 0;
        $noRouting = 0;
        foreach ($data['products'] as $id => $p) {
            $g = $p['group_id'] ?? 0;
            $slots = [];
            $done = $p['done_h'];
            ksort($p['slots']);
            foreach ($p['slots'] as $day => $qty) {
                $h = $this->hours($data['routings'], $id, $qty);
                $slotDone = min($h, $done);
                $done = max(0.0, $done - $h);
                $isLate = $p['due_date'] !== null && $day > $p['due_date'];
                if ($isLate) $late++;
                $slots[] = ['day' => $day, 'quantity' => round($qty, 3), 'cartons' => $p['ppc'] ? round($qty / $p['ppc'], 1) : null,
                    'hours' => round($h, 2), 'done_h' => round($slotDone, 2), 'late' => $isLate,
                    'behind' => $day < $today && $slotDone < $h - 0.01];
                $lanes[$g]['required'][$day] = ($lanes[$g]['required'][$day] ?? 0.0) + $h;
                $lanes[$g]['expected'] = ($lanes[$g]['expected'] ?? 0.0) + $h * $this->elapsed($data['capacity'][$g][$day] ?? null, $day, $today, $now);
            }
            $unassignedH = $this->hours($data['routings'], $id, $p['unassigned_qty']);
            $lanes[$g]['unassigned_h'] = ($lanes[$g]['unassigned_h'] ?? 0.0) + $unassignedH;
            $lanes[$g]['done_h'] = ($lanes[$g]['done_h'] ?? 0.0) + $p['done_h'];
            $lanes[$g]['hours'] = ($lanes[$g]['hours'] ?? 0.0) + $p['hours'];
            if ($p['hours'] <= 0) $noRouting++;
            $products[] = [
                'item_id' => $id, 'name' => $p['name'], 'item_type' => $p['item_type'], 'mode' => $p['mode'], 'group_id' => $p['group_id'],
                'unit' => $p['unit'], 'ppc' => $p['ppc'], 'quantity' => $p['quantity'], 'cartons' => $p['ppc'] ? round($p['quantity'] / $p['ppc'], 1) : null,
                'hours' => round($p['hours'], 2), 'due_date' => $p['due_date'], 'latest_day' => $p['latest_day'],
                'done_h' => round($p['done_h'], 2), 'done_pct' => $p['hours'] > 0 ? round($p['done_h'] / $p['hours'] * 100, 1) : null, 'state' => $p['state'],
                'unassigned_qty' => $p['unassigned_qty'], 'unassigned_h' => round($unassignedH, 2), 'over_qty' => $p['over_qty'],
                'slots' => $slots, 'movable' => $context->canInGroup('prod.gantt.move', $p['group_id']),
            ];
        }
        usort($products, fn($a, $b) => [$a['group_id'] ?? 999, $a['slots'][0]['day'] ?? '9', $a['latest_day'], $a['name']] <=> [$b['group_id'] ?? 999, $b['slots'][0]['day'] ?? '9', $b['latest_day'], $b['name']]);

        $outGroups = [];
        $totals = ['hours' => 0.0, 'available_h' => 0.0, 'required_h' => 0.0, 'done_h' => 0.0, 'expected_h' => 0.0, 'unassigned_h' => 0.0];
        $ids = array_keys($groups);
        if (isset($lanes[0])) $ids[] = 0;
        foreach ($ids as $gid) {
            $cap = $data['capacity'][$gid] ?? [];
            $lane = $lanes[$gid] ?? [];
            if (!$cap && !$lane) continue;
            $dayRows = [];
            $over = 0.0;
            foreach ($data['days'] as $day) {
                $available = $cap[$day]['available_h'] ?? 0.0;
                $required = $lane['required'][$day] ?? 0.0;
                $over += max(0.0, $required - $available);
                $dayRows[] = ['date' => $day, 'people' => $cap[$day]['people'] ?? 0, 'available_h' => round($available, 2), 'required_h' => round($required, 2),
                    'free_h' => round($available - $required, 2), 'load_pct' => $available > 0 ? round($required / $available * 100, 1) : null,
                    'status' => $this->status($required, $available)];
            }
            $available = array_sum(array_column($dayRows, 'available_h'));
            $required = array_sum(array_column($dayRows, 'required_h'));
            $people = max([0, ...array_column($dayRows, 'people')]);
            $outGroups[] = [
                'id' => $gid ?: null, 'name' => $groups[$gid]['name'] ?? null, 'color' => $groups[$gid]['color'] ?? null, 'people' => $people,
                'hours' => round($lane['hours'] ?? 0.0, 2), 'available_h' => round($available, 2), 'required_h' => round($required, 2),
                'load_pct' => $available > 0 ? round(($lane['hours'] ?? 0.0) / $available * 100, 1) : null,
                'unassigned_h' => round($lane['unassigned_h'] ?? 0.0, 2), 'over_h' => round($over, 2),
                'done_h' => round($lane['done_h'] ?? 0.0, 2), 'expected_h' => round($lane['expected'] ?? 0.0, 2),
                'movable' => $context->canInGroup('prod.gantt.move', $gid ?: null), 'days' => $dayRows,
            ];
            if ($over > 0.05) $warnings[] = ['code' => 'GROUP_OVERLOAD', 'group' => $groups[$gid]['name'] ?? '—', 'hours' => round($over, 1)];
            foreach (['hours' => $lane['hours'] ?? 0.0, 'available_h' => $available, 'required_h' => $required, 'done_h' => $lane['done_h'] ?? 0.0,
                         'expected_h' => $lane['expected'] ?? 0.0, 'unassigned_h' => $lane['unassigned_h'] ?? 0.0] as $k => $val) $totals[$k] += $val;
        }
        // Bez planu nie ma zleceń ani operacji : nie ma czego porównywać z planem « na teraz ».
        if (!$data['plans']) $totals['expected_h'] = 0.0;
        $unassigned = count(array_filter($products, fn($p) => $p['unassigned_qty'] > self::EPS));
        if (!$data['plans']) array_unshift($warnings, ['code' => 'NO_PLAN']);
        if ($unassigned) $warnings[] = ['code' => 'UNASSIGNED', 'count' => $unassigned, 'hours' => round($totals['unassigned_h'], 1)];
        if ($late) $warnings[] = ['code' => 'AFTER_DUE', 'count' => $late];
        if ($noRouting) $warnings[] = ['code' => 'NO_HOURS', 'count' => $noRouting];

        return [
            'week_start' => $data['monday'], 'week_end' => $data['sunday'], 'iso_week' => (int) date('W', strtotime($data['monday'])),
            'today' => $today, 'now' => date('Y-m-d H:i:s', $now),
            'days' => array_map(fn($d) => ['date' => $d, 'weekday' => (int) date('N', strtotime($d)), 'past' => $d < $today, 'today' => $d === $today], $data['days']),
            'source' => ['type' => $data['plans'] ? 'plan' : 'demand', 'plans' => array_map(fn($p) => ['id' => (int) $p['id'], 'version' => (int) $p['version'],
                'status' => $p['status'], 'period_start' => $p['period_start'], 'period_end' => $p['period_end']], $data['plans'])],
            'can_move' => (bool) array_filter($outGroups, fn($g) => $g['movable']), 'can_schedule' => $context->can('prod.plan.validate'),
            'groups' => $outGroups,
            'products' => $products,
            'totals' => array_map(fn($v) => round($v, 2), $totals) + [
                'products' => count($products), 'unassigned' => $unassigned,
                'load_pct' => $totals['available_h'] > 0 ? round($totals['hours'] / $totals['available_h'] * 100, 1) : null,
                'done_pct' => $totals['hours'] > 0 ? round($totals['done_h'] / $totals['hours'] * 100, 1) : null,
                'delay_h' => round(max(0.0, $totals['expected_h'] - $totals['done_h']), 2),
            ],
            'warnings' => array_map(fn($w) => $w + ['message' => $context->translator->error(($w['code'] === 'UNPLANNED_PRODUCTS' ? 'PLAN_WARNING_' : 'WEEK_WARNING_') . $w['code'],
                isset($w['hours']) ? ['hours' => number_format($w['hours'], 1, ',', ' ')] + $w : $w)], $warnings),
        ];
    }

    /** Część dnia, która już minęła (plan « na teraz ») : 1 dla dni minionych, udział zmiany dzisiaj. */
    private function elapsed(?array $capacity, string $day, string $today, int $now): float
    {
        if ($day < $today) return 1.0;
        if ($day > $today || $capacity === null) return 0.0;
        $start = strtotime($capacity['start']);
        $end = strtotime($capacity['end']);
        return $end > $start ? max(0.0, min(1.0, ($now - $start) / ($end - $start))) : 0.0;
    }

    private function status(float $required, float $available): string
    {
        if ($available <= 0) return $required > 0 ? 'no_team' : 'free';
        $pct = $required / $available * 100;
        $min = (float) $this->params->get('load.green_min_pct', '90');
        $max = (float) $this->params->get('load.green_max_pct', '100');
        return $pct > $max ? 'over' : ($pct >= $min ? 'ok' : 'under');
    }

    private function hours(array $routings, int $itemId, float $qty): float
    {
        if ($qty <= self::EPS) return 0.0;
        return StandardTime::routing($routings[$itemId] ?? [], $qty)['totals']['labour_s'] / 3600;
    }

    private function monday(?string $date): string
    {
        (new Validator(['date' => $date]))->date('date', true, false)->throwIfInvalid();
        $t = strtotime((string) $date);
        return date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
    }
}
