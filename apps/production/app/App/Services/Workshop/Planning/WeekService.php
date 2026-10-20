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
 * produkcji (week.production_days, domyślnie pn–pt), z obsadą dnia i nadgodzinami. Tydzień zaczyna się
 * w week.start_day (w pracowni środa : śr → wt).
 * Ilość produktu dzieli się na « na wysyłkę » (zamówienia klientów MTO i półprodukty do nich — termin =
 * dzień wyjazdu ciężarówki) i « na magazyn » (uzupełnienie stanu MTS). Podział na dni zapisuje się w
 * tfb_prod_week_slot (przeciągnij i upuść albo « Ułóż tydzień ») ; nieprzydzielona reszta czeka w
 * « Do rozplanowania ». Układanie : najpierw wysyłka — jak najwcześniej od dzisiaj, najbliższe terminy
 * najpierw, nigdy po dniu wyjazdu (termin miniony = pierwszy wolny dzień, oznaczony jako po terminie) ;
 * potem magazyn — tylko w dni magazynowe (week.stock_days, domyślnie śr–pt), w wolnych godzinach.
 * Produkt dzieli się na pełne kartony, gdy dzień jest pełny. W dniu ilość wysyłki liczy się od
 * najwcześniejszych przydziałów (FIFO). Wykonanie = udział zakończonych operacji zleceń planu.
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
        return $this->present($this->data($this->weekStart($date), $context, $now), $context, $now);
    }

    /** Przenosi produkt (cały blok albo część) na inny dzień albo z powrotem do « Do rozplanowania ». */
    public function move(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['date', 'item_id', 'from', 'to', 'quantity'])
            ->date('date', true, false)->integer('item_id', true, 1)->date('from')->date('to')
            ->decimal('quantity', false, 0, 1000000000, true, true);
        $v->throwIfInvalid();
        $data = $this->data($this->weekStart($v->value('date')), $context, $now);
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
            if ($from !== null) $this->repo->setSlot($data['start'], $itemId, $from, ($p['slots'][$from] ?? 0.0) - $qty, $context->userId);
            if ($to !== null) $this->repo->setSlot($data['start'], $itemId, $to, ($p['slots'][$to] ?? 0.0) + $qty, $context->userId);
            // Cały blok na pusty dzień : godzina startu idzie razem z nim.
            if ($from !== null && $to !== null && !isset($p['slots'][$to]) && isset($p['times'][$from]) && $qty >= ($p['slots'][$from] ?? 0.0) - self::EPS) {
                $this->repo->setSlotTime($data['start'], $itemId, $to, $p['times'][$from] . ':00');
            }
            $this->audit->record($context, 'move', 'week_slot', $itemId, ['week' => $data['start'], 'day' => $from, 'quantity' => round($qty, 3)],
                ['week' => $data['start'], 'day' => $to, 'quantity' => round($qty, 3)]);
        });
        return $this->present($this->data($data['start'], $context, $now), $context, $now);
    }

    /**
     * 📌 « Co tydzień » : blok produktu w dniu staje się stałą partią (ten dzień tygodnia, ta ilość, w każdym
     * kolejnym tygodniu) albo przestaje nią być. Ten tydzień zostaje jak jest ; tygodnie późniejsze, do których
     * partia już weszła, są cofane (wejdą na nowo z nową ilością albo wcale).
     */
    public function pin(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['date', 'item_id', 'day', 'pinned'])
            ->date('date', true, false)->integer('item_id', true, 1)->date('day', true, false)->boolean('pinned', true);
        $v->throwIfInvalid();
        $data = $this->data($this->weekStart($v->value('date')), $context, $now);
        $itemId = (int) $v->value('item_id');
        $p = $data['products'][$itemId] ?? throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_IN_WEEK', 'params' => []]]);
        if (!$context->canInGroup('prod.gantt.move', $p['group_id'])) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.gantt.move']);
        }
        $day = (string) $v->value('day');
        if (!in_array($day, $data['days'], true)) throw ApiException::validation([['field' => 'day', 'code' => 'DAY_NOT_IN_WEEK', 'params' => []]]);
        $weekday = (int) date('N', strtotime($day));
        $pinned = (bool) $v->value('pinned');
        $qty = (float) ($p['slots'][$day] ?? 0.0);
        $rule = $this->repo->fixedRule($itemId, $weekday);
        if ($pinned && $qty <= self::EPS) throw ApiException::validation([['field' => 'day', 'code' => 'NOTHING_ON_DAY', 'params' => []]]);
        if (!$pinned && $rule === null) throw ApiException::validation([['field' => 'day', 'code' => 'NOT_PINNED', 'params' => []]]);

        $this->tx->execute(function () use ($data, $p, $itemId, $weekday, $day, $qty, $pinned, $rule, $context) {
            foreach ($this->repo->laterFixedWeeks($itemId, $weekday, $data['start']) as $w) {
                if ((float) $w['placed'] > self::EPS) {
                    $slot = $this->repo->slotQuantity($w['week_start'], $itemId, $w['work_date']);
                    $this->repo->setSlot($w['week_start'], $itemId, $w['work_date'], max(0.0, $slot - (float) $w['placed']), $context->userId);
                }
                $this->repo->deleteFixedWeek($w['week_start'], $itemId, $w['work_date']);
            }
            if ($pinned) {
                $this->repo->saveFixedRule($itemId, $weekday, $qty, isset($p['times'][$day]) ? $p['times'][$day] . ':00' : null, $context->userId);
                $this->repo->markFixedWeek($data['start'], $itemId, $day, $qty, 0.0, true);
            } else {
                $this->repo->deleteFixedRule($itemId, $weekday);
                // Blok już zdjęty z tego dnia (np. przeniesiony « co tydzień » na inny dzień) : partia tego dnia
                // nie liczy się już w tym tygodniu ; blok, który jest na dniu, zostaje jak jest.
                if ($qty <= self::EPS) $this->repo->deleteFixedWeek($data['start'], $itemId, $day);
            }
            $this->audit->record($context, $pinned ? 'pin' : 'unpin', 'fixed_batch', $itemId,
                $rule ? ['weekday' => $weekday, 'quantity' => (float) $rule['quantity']] : null,
                $pinned ? ['weekday' => $weekday, 'quantity' => round($qty, 3), 'week' => $data['start']] : null);
        });
        return $this->present($this->data($data['start'], $context, $now), $context, $now);
    }

    /**
     * Arkusz tygodnia (cała produkcja na jednej kartce) : jeden wiersz na kartę — produkty z ilością w tygodniu
     * i produkty « na magazyn » zawsze (stan, dostępne, min., maks.), zamówienia, uzupełnienie (dostępne < min. →
     * do maks.), do produkcji = tydzień produktu w pełnych partiach, sztuk w partii, partie, godziny, dni ; grupy :
     * dostępne godziny i osoby (ETP = godziny ÷ week.fte_hours po stronie widoku).
     */
    public function sheet(string $date, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $data = $this->data($this->weekStart($date), $context, $now);
        $demand = $data['demand'] ?? $this->plans->compute($data['start'], $data['end']);
        $week = $this->present($data, $context, $now);
        $products = array_column($week['products'], null, 'item_id');
        $empty = ['orders' => 0.0, 'refill' => 0.0, 'semi' => 0.0, 'fixed' => 0.0, 'batch_extra' => 0.0];
        $parts = [];
        foreach ($demand['lines'] as $l) {
            $p = &$parts[(int) $l['item']['id']];
            $p ??= $empty;
            $p['orders'] += $l['qty_mto'];
            $p['refill'] += $l['qty_mts'];
            $p['semi'] += $l['qty_semi'];
            $p['fixed'] += $l['qty_fixed'];
            $p['batch_extra'] += $l['qty_batch'] ?? 0.0;
            unset($p);
        }
        $free = $this->planning->freeStockCartons();
        $num = fn($v) => $v === null ? null : (float) $v;
        $rows = [];
        foreach ($this->planning->items() as $it) {
            $id = (int) $it['id'];
            if ($it['erp_active'] !== null && !(int) $it['erp_active']) continue;
            $w = $products[$id] ?? null;
            if ($w === null && $it['mode'] !== 'MTS') continue;
            $ppc = $it['item_type'] === 'product' && (int) $it['ppc'] > 0 ? (int) $it['ppc'] : null;
            $batch = (float) $it['batch_multiple'] > 0 ? (float) $it['batch_multiple'] : null;
            $qty = (float) ($w['quantity'] ?? 0.0);
            $pr = $parts[$id] ?? $empty;
            $s = $demand['stock'][$id] ?? null;
            $rows[] = [
                'item_id' => $id, 'name' => $it['name'], 'item_type' => $it['item_type'],
                'group_id' => $it['id_primary_group'] !== null ? (int) $it['id_primary_group'] : null,
                'mode' => $it['mode'], 'never_frozen' => (bool) $it['never_frozen'], 'unit' => $it['base_unit'], 'ppc' => $ppc, 'batch' => $batch,
                'stock_min' => $num($it['stock_min']), 'stock_max' => $num($it['stock_max']),
                'stock' => $ppc && $it['id_final_product'] ? (float) (($free[(int) $it['id_final_product']] ?? 0) * $ppc) : null,
                'available' => $s ? round($s['available'], 3) : null, 'in_production' => $s ? round($s['in_production'], 3) : null,
                'orders_from_stock' => $s ? round($s['orders_from_stock'], 3) : null,
                'orders' => round($pr['orders'] + $pr['semi'], 3), 'refill' => round($pr['refill'], 3), 'fixed' => round($pr['fixed'], 3),
                'batch_extra' => round($pr['batch_extra'], 3), 'to_produce' => round($qty, 3),
                'batches' => $batch ? (int) ceil(round($qty / $batch, 6)) : null,
                'hours' => (float) ($w['hours'] ?? 0.0), 'placed' => $w ? round(array_sum(array_column($w['slots'], 'quantity')), 3) : 0.0,
                'days' => $w ? array_column($w['slots'], 'day') : [],
            ];
        }
        usort($rows, fn($a, $b) => [$a['group_id'] ?? 999, $a['to_produce'] <= 0, $a['name']] <=> [$b['group_id'] ?? 999, $b['to_produce'] <= 0, $b['name']]);
        return [
            'week_start' => $week['week_start'], 'week_end' => $week['week_end'], 'iso_week' => $week['iso_week'], 'today' => $week['today'],
            'days' => $week['days'], 'fte_hours' => $week['fte_hours'], 'source' => $week['source'],
            'groups' => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'color' => $g['color'], 'available_h' => $g['available_h'], 'people' => $g['people']], $week['groups']),
            'rows' => $rows,
            'can_edit' => $context->can('prod.products.write'),
        ];
    }

    /**
     * Godzina startu bloku w dniu (tydzień typowy) ; stała partia (📌) tego dnia tygodnia dostaje tę samą
     * godzinę. time = null usuwa godzinę.
     */
    public function time(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['date', 'item_id', 'day', 'time'])->date('date', true, false)->integer('item_id', true, 1)->date('day', true, false);
        $clear = !array_key_exists('time', $body) || $body['time'] === null || $body['time'] === '';
        if (!$clear) $v->time('time');
        $v->throwIfInvalid();
        $data = $this->data($this->weekStart($v->value('date')), $context, $now);
        $itemId = (int) $v->value('item_id');
        $p = $data['products'][$itemId] ?? throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_IN_WEEK', 'params' => []]]);
        if (!$context->canInGroup('prod.gantt.move', $p['group_id'])) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.gantt.move']);
        }
        $day = (string) $v->value('day');
        if (($p['slots'][$day] ?? 0.0) <= self::EPS) throw ApiException::validation([['field' => 'day', 'code' => 'NOTHING_ON_DAY', 'params' => []]]);
        $time = $clear ? null : (string) $v->value('time');
        $weekday = (int) date('N', strtotime($day));
        $rule = $this->repo->fixedRule($itemId, $weekday);
        $this->tx->execute(function () use ($data, $itemId, $day, $time, $weekday, $rule, $p, $context) {
            $this->repo->setSlotTime($data['start'], $itemId, $day, $time);
            if ($rule !== null) {
                // Stała partia : nowa godzina w regule i w tygodniach późniejszych, do których partia już weszła.
                $this->repo->setFixedRuleTime($itemId, $weekday, $time);
                foreach ($this->repo->laterFixedWeeks($itemId, $weekday, $data['start']) as $w) {
                    if ((float) $w['placed'] > self::EPS) $this->repo->setSlotTime($w['week_start'], $itemId, $w['work_date'], $time);
                }
            }
            $this->audit->record($context, 'time', 'week_slot', $itemId, ['day' => $day, 'time' => $p['times'][$day] ?? null],
                ['day' => $day, 'time' => $time !== null ? substr($time, 0, 5) : null, 'fixed' => $rule !== null]);
        });
        return $this->present($this->data($data['start'], $context, $now), $context, $now);
    }

    /**
     * « Wyczyść tydzień » : zdejmuje wszystkie produkty z dni od dzisiaj (dni minione zostają) do « Do zrobienia »
     * — w grupach, w których użytkownik może przesuwać. Stałe partie (📌) nie wracają same w tym tygodniu ;
     * « Ułóż tydzień » kładzie je najpierw na ich dni.
     */
    public function clear(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['date'])->date('date', true, false);
        $v->throwIfInvalid();
        $data = $this->data($this->weekStart($v->value('date')), $context, $now);
        if (!array_filter($data['days'], fn($d) => $d >= $data['today'])) throw new ApiException('WEEK_IN_PAST', 409, [], ['week' => $data['start']]);
        $movable = array_keys(array_filter($data['products'], fn($p) => $context->canInGroup('prod.gantt.move', $p['group_id'])));
        if (!$movable && $data['products']) throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.gantt.move']);
        $this->tx->execute(function () use ($data, $movable, $context) {
            $removed = $this->repo->clearFrom($data['start'], $movable, $data['today']);
            $this->audit->record($context, 'clear', 'week', $data['start'], null, ['from' => $data['today'], 'slots' => $removed]);
        });
        return $this->present($this->data($data['start'], $context, $now), $context, $now);
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
        $data = $this->data($this->weekStart($v->value('date')), $context, $now);
        $reset = (bool) $v->value('reset', false);
        $days = array_values(array_filter($data['days'], fn($d) => $d >= $data['today']));
        if (!$days) throw new ApiException('WEEK_IN_PAST', 409, [], ['week' => $data['start']]);

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

        // Stałe partie (📌) najpierw na swoje dni (od dzisiaj, w dni ze zmianą grupy).
        foreach ($movable as $id => &$p) {
            foreach ($data['fixed_week'][$id] ?? [] as $day => $f) {
                $g = $p['group_id'] ?? 0;
                $missing = min($p['unassigned_qty'], $f['quantity'] - ($p['slots'][$day] ?? 0.0));
                if ($day < $data['today'] || $missing <= self::EPS || ($data['capacity'][$g][$day]['available_h'] ?? 0.0) <= 0) continue;
                $p['slots'][$day] = ($p['slots'][$day] ?? 0.0) + $missing;
                $p['unassigned_qty'] = round($p['unassigned_qty'] - $missing, 3);
                $writes[$id][$day] = $p['slots'][$day];
                $used[$g][$day] = ($used[$g][$day] ?? 0.0) + $this->hours($data['routings'], $id, $missing);
            }
        }
        unset($p);

        // Zadania : części « na wysyłkę » (każdy termin osobno), potem reszta « na magazyn » — w pełnych partiach
        // produkcyjnych, gdy produkt ma partię (WeekSplit).
        $direct = [];
        $stock = [];
        foreach ($movable as $id => $p) {
            if ($p['unassigned_qty'] <= self::EPS) continue;
            $split = WeekSplit::tasks($p['unassigned_qty'], array_sum($p['slots']), $p['direct_parts'], $p['batch']);
            foreach ($split['direct'] as $due => $qty) {
                $direct[] = ['id' => $id, 'qty' => $qty, 'latest' => $this->latestDay($data['days'], (string) $due), 'semi' => $p['item_type'] === 'semi'];
            }
            if ($split['stock'] > self::EPS) $stock[] = ['id' => $id, 'qty' => $split['stock']];
        }
        usort($direct, fn($a, $b) => [$a['latest'], $a['semi'] ? 0 : 1, -$this->hours($data['routings'], $a['id'], $a['qty'])]
            <=> [$b['latest'], $b['semi'] ? 0 : 1, -$this->hours($data['routings'], $b['id'], $b['qty'])]);
        usort($stock, fn($a, $b) => -$this->hours($data['routings'], $a['id'], $a['qty']) <=> -$this->hours($data['routings'], $b['id'], $b['qty']));
        $stockWindow = array_values(array_filter($days, fn($d) => in_array($d, $data['stock_days'], true))) ?: $days;

        $placed = 0;
        $slots = array_map(fn($p) => $p['slots'], $movable);
        foreach ([[$direct, null], [$stock, $stockWindow]] as [$tasks, $window]) {
            foreach ($tasks as $task) {
                $id = $task['id'];
                $p = $movable[$id];
                $g = $p['group_id'] ?? 0;
                $win = $window ?? (array_values(array_filter($days, fn($d) => $d <= $task['latest'])) ?: [$days[0]]);
                $free = [];
                foreach ($win as $day) $free[$day] = ($data['capacity'][$g][$day]['available_h'] ?? 0.0) - ($used[$g][$day] ?? 0.0);
                $unit = $p['batch'] ?? (float) ($p['ppc'] ?: ($p['unit'] === 'kg' ? 0.1 : 1));
                foreach (WeekSplit::place($task['qty'], $win, $unit, $free, fn(float $q) => $this->hours($data['routings'], $id, $q)) as $day => $qty) {
                    $slots[$id][$day] = ($slots[$id][$day] ?? 0.0) + $qty;
                    $writes[$id][$day] = $slots[$id][$day];
                    $used[$g][$day] = ($used[$g][$day] ?? 0.0) + $this->hours($data['routings'], $id, $qty);
                    $placed++;
                }
            }
        }

        $this->tx->execute(function () use ($writes, $data, $context, $reset, $placed) {
            foreach ($writes as $id => $byDay) foreach ($byDay as $day => $qty) $this->repo->setSlot($data['start'], (int) $id, $day, $qty, $context->userId);
            $this->audit->record($context, 'distribute', 'week', $data['start'], null, ['reset' => $reset, 'slots' => $placed]);
        });
        return $this->present($this->data($data['start'], $context, $now), $context, $now);
    }

    /** Dane tygodnia : dni, źródło, produkty z przydziałami, zdolność grup, wykonanie. */
    private function data(string $start, ApiContext $context, int $now): array
    {
        $end = date('Y-m-d', strtotime($start . ' +6 days'));
        $weekdays = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        // Dni produkcji w kolejności tygodnia (od week.start_day, np. śr, czw, pt, pn, wt).
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($start . " +{$i} days"));
            if (in_array((int) date('N', strtotime($d)), $weekdays, true)) $days[] = $d;
        }
        $lastDay = end($days);
        $stockWeekdays = array_map('intval', explode(',', $this->params->get('week.stock_days', '3,4,5')));
        $stockDays = array_values(array_filter($days, fn($d) => in_array((int) date('N', strtotime($d)), $stockWeekdays, true)));

        $items = [];
        foreach ($this->planning->items() as $it) $items[(int) $it['id']] = $it;
        $routings = $this->planning->routings();
        $today = date('Y-m-d', $now);

        // Zdolność : obsada × zmiana w dni produkcji.
        $capacity = [];
        foreach ($this->scheduling->load($start, $end, $context) as $row) {
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

        // Stałe partie (📌 co tydzień) : wchodzą do tygodnia przy pierwszym otwarciu, potem są zwykłymi przydziałami.
        $rules = array_values(array_filter($this->planning->fixedRules(), fn($r) => isset($items[(int) $r['id_item']])));
        $this->seedFixed($start, $days, $today, $items, $capacity, $rules, $context);
        $fixedWeek = $this->repo->fixedWeek($start);

        // Źródło : plan zaczynający się w tym tygodniu produkcji (zatwierdzony, zamrożony, inaczej zamknięty),
        // inaczej propozycja na żywo (zamówienia + uzupełnienie stanów + stałe partie).
        $plans = $this->repo->plans($start, $end);
        $active = array_values(array_filter($plans, fn($p) => $p['status'] !== 'closed'));
        $plans = $active ?: $plans;
        $lines = [];
        $warnings = [];
        $demand = null;
        if ($plans) {
            foreach ($plans as $plan) {
                foreach ($this->planning->lines((int) $plan['id']) as $l) {
                    if ($l['due_date'] < $start || $l['due_date'] > $end) continue;
                    $lines[] = ['item_id' => (int) $l['id_item'], 'qty' => (float) $l['qty_final'], 'due_date' => $l['due_date'],
                        'direct' => min((float) $l['qty_final'], (float) $l['qty_mto'] + (float) $l['qty_semi'])];
                }
            }
        } else {
            $demand = $this->plans->compute($start, $end);
            foreach ($demand['lines'] as $l) {
                $lines[] = ['item_id' => (int) $l['item']['id'], 'qty' => (float) $l['qty_proposed'], 'due_date' => $l['due_date'],
                    'direct' => min((float) $l['qty_proposed'], (float) $l['qty_mto'] + (float) $l['qty_semi'])];
            }
            foreach ($demand['warnings'] as $w) if ($w['code'] === 'UNPLANNED_PRODUCTS') $warnings[] = $w;
        }

        $products = [];
        foreach ($lines as $l) {
            $it = $items[$l['item_id']] ?? null;
            if ($it === null || $l['qty'] <= 0) continue;
            $p = &$products[$l['item_id']];
            $p ??= $this->newProduct($l['item_id'], $it);
            $p['quantity'] += $l['qty'];
            if ($l['direct'] > self::EPS) {
                $p['direct'] += $l['direct'];
                $p['direct_parts'][$l['due_date']] = ($p['direct_parts'][$l['due_date']] ?? 0.0) + $l['direct'];
                if ($p['due_date'] === null || $l['due_date'] < $p['due_date']) $p['due_date'] = $l['due_date'];
            }
            $p['stock'] += max(0.0, $l['qty'] - $l['direct']);
            unset($p);
        }
        // Ilość tygodnia co najmniej taka, jak stałe partie, które weszły do tygodnia : nadwyżka ponad zamówienia
        // i uzupełnienie stanu idzie na magazyn (np. plan sprzed przypięcia albo partia większa niż potrzeba).
        foreach ($fixedWeek as $id => $byDay) {
            $it = $items[$id] ?? null;
            $fixedQty = array_sum(array_column($byDay, 'quantity'));
            if ($it === null || $fixedQty <= self::EPS) continue;
            $p = &$products[$id];
            $p ??= $this->newProduct($id, $it);
            $extra = $fixedQty - $p['quantity'];
            if ($extra > self::EPS) {
                $p['quantity'] += $extra;
                $p['stock'] += $extra;
            }
            unset($p);
        }
        foreach ($products as $id => &$p) {
            $p['quantity'] = round($p['quantity'], 3);
            $p['direct'] = round($p['direct'], 3);
            $p['stock'] = round($p['stock'], 3);
            ksort($p['direct_parts']);
            $p['hours'] = $this->hours($routings, $id, $p['quantity']);
            // Ostatni dzień produkcji nie później niż termin (MTO, półprodukt) ; MTS — do końca tygodnia.
            $p['latest_day'] = $lastDay;
            if ($p['due_date'] !== null) {
                $before = array_values(array_filter($days, fn($d) => $d <= $p['due_date']));
                $p['latest_day'] = $before ? end($before) : $days[0];
            }
        }
        unset($p);
        foreach ($this->repo->slots($start) as $s) {
            $id = (int) $s['id_item'];
            if (!isset($products[$id])) continue;
            $products[$id]['slots'][$s['work_date']] = (float) $s['quantity'];
            if ($s['start_time'] !== null) $products[$id]['times'][$s['work_date']] = substr($s['start_time'], 0, 5);
        }
        foreach ($products as &$p) {
            $assigned = array_sum($p['slots']);
            $p['unassigned_qty'] = round(max(0.0, $p['quantity'] - $assigned), 3);
            $p['over_qty'] = round(max(0.0, $assigned - $p['quantity']), 3);
        }
        unset($p);

        // Wykonanie : z planem — udział godzin zakończonych operacji zleceń planu ; bez planu — ilość z partii
        // zapisanych na tabletach w dniach tygodnia (udział w ilości tygodnia).
        $labour = [];
        $produced = $plans ? [] : $this->repo->produced($start, $end);
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
            if (!$plans && $p['quantity'] > 0) $share = min(1.0, ($produced[$id] ?? 0.0) / $p['quantity']);
            $p['done_h'] = round($p['hours'] * $share, 3);
            $p['state'] = $share >= 0.999 ? 'done' : ($share > 0 || !empty($l['running']) ? 'in_progress' : 'planned');
        }
        unset($p);

        // Stała partia w dniu bez zmiany grupy : czeka w « Do zrobienia ».
        foreach ($fixedWeek as $id => $byDay) {
            foreach ($byDay as $day => $f) {
                if (!isset($products[$id]) || $day < $today || $f['quantity'] <= self::EPS) continue;
                if (($capacity[$products[$id]['group_id'] ?? 0][$day]['available_h'] ?? 0.0) > 0) continue;
                $warnings[] = ['code' => 'FIXED_NO_SHIFT', 'product' => $products[$id]['name'], 'day' => date('d.m', strtotime($day))];
            }
        }
        $pins = [];
        $startN = (int) date('N', strtotime($start));
        foreach ($rules as $r) {
            $it = $items[(int) $r['id_item']];
            $pins[(int) $r['id_item']][] = ['item_id' => (int) $r['id_item'], 'name' => $it['name'], 'group_id' => $it['id_primary_group'] !== null ? (int) $it['id_primary_group'] : null,
                'unit' => $it['base_unit'], 'ppc' => $it['item_type'] === 'product' && (int) $it['ppc'] > 0 ? (int) $it['ppc'] : null,
                'batch' => (float) $it['batch_multiple'] > 0 ? (float) $it['batch_multiple'] : null,
                'weekday' => (int) $r['weekday'], 'quantity' => (float) $r['quantity'], 'time' => $r['start_time'] !== null ? substr($r['start_time'], 0, 5) : null,
                'day' => date('Y-m-d', strtotime($start . ' +' . (((int) $r['weekday'] - $startN + 7) % 7) . ' days'))];
        }

        return ['start' => $start, 'end' => $end, 'days' => array_values($days), 'stock_days' => $stockDays, 'today' => $today,
            'plans' => $plans, 'products' => $products, 'capacity' => $capacity, 'routings' => $routings, 'warnings' => $warnings,
            'pins' => $pins, 'fixed_week' => $fixedWeek, 'demand' => $demand];
    }

    /** Nowy produkt tygodnia (z karty produkcyjnej). */
    private function newProduct(int $id, array $it): array
    {
        return ['item_id' => $id, 'name' => $it['name'], 'item_type' => $it['item_type'], 'mode' => $it['mode'],
            'group_id' => $it['id_primary_group'] !== null ? (int) $it['id_primary_group'] : null, 'unit' => $it['base_unit'],
            'ppc' => $it['item_type'] === 'product' && (int) $it['ppc'] > 0 ? (int) $it['ppc'] : null,
            'batch' => (float) $it['batch_multiple'] > 0 ? (float) $it['batch_multiple'] : null,
            'quantity' => 0.0, 'direct' => 0.0, 'stock' => 0.0, 'direct_parts' => [], 'due_date' => null, 'slots' => [], 'times' => []];
    }

    /**
     * Stałe partie (📌) wchodzą do tygodnia raz : na swój dzień tygodnia, od dzisiaj, w dni produkcji. Dzień, który
     * już ma tyle, zostaje bez zmian ; w dniu bez zmiany grupy partia czeka w « Do zrobienia ». Przesunięcie albo
     * zdjęcie partii w tygodniu zostaje (wejście tygodnia jest zapisane).
     */
    private function seedFixed(string $start, array $days, string $today, array $items, array $capacity, array $rules, ApiContext $context): void
    {
        if (!$rules) return;
        $done = $this->repo->fixedWeek($start);
        $startN = (int) date('N', strtotime($start));
        $new = [];
        foreach ($rules as $r) {
            $id = (int) $r['id_item'];
            $it = $items[$id];
            $day = date('Y-m-d', strtotime($start . ' +' . (((int) $r['weekday'] - $startN + 7) % 7) . ' days'));
            if (isset($done[$id][$day]) || $day < $today || !in_array($day, $days, true)) continue;
            if ($it['erp_active'] !== null && !(int) $it['erp_active']) continue;
            $new[] = [$id, $day, (float) $r['quantity'], ($capacity[$it['id_primary_group'] ?? 0][$day]['available_h'] ?? 0.0) > 0, $r['start_time']];
        }
        if (!$new) return;
        $this->tx->execute(function () use ($new, $start, $context) {
            foreach ($new as [$id, $day, $qty, $shift, $time]) {
                $existing = $this->repo->slotQuantity($start, $id, $day);
                $add = $shift ? max(0.0, $qty - $existing) : 0.0;
                if ($add > self::EPS) $this->repo->setSlot($start, $id, $day, $existing + $add, $context->userId);
                if ($add > self::EPS && $existing <= self::EPS && $time !== null) $this->repo->setSlotTime($start, $id, $day, $time);
                $this->repo->markFixedWeek($start, $id, $day, $qty, $add);
            }
            $this->audit->record($context, 'seed_fixed', 'week', $start, null, ['batches' => count($new)]);
        });
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
            // Wysyłka liczy się od najwcześniejszych przydziałów : każdy dzień pokrywa najpierw najbliższe terminy.
            $parts = $p['direct_parts'];
            $cartons = fn(float $q) => $p['ppc'] ? round($q / $p['ppc'], 1) : null;
            $batches = fn(float $q) => $p['batch'] ? round($q / $p['batch'], 2) : null;
            foreach ($p['slots'] as $day => $qty) {
                $h = $this->hours($data['routings'], $id, $qty);
                $slotDone = min($h, $done);
                $done = max(0.0, $done - $h);
                $directQty = 0.0;
                $isLate = false;
                foreach ($parts as $due => &$left) {
                    if ($left <= self::EPS || $directQty >= $qty - self::EPS) continue;
                    $take = min($left, $qty - $directQty);
                    $left -= $take;
                    $directQty += $take;
                    if ($day > $due) $isLate = true;
                }
                unset($left);
                if ($isLate) $late++;
                $directH = $qty > 0 ? $h * $directQty / $qty : 0.0;
                $slots[] = ['day' => $day, 'time' => $p['times'][$day] ?? null, 'quantity' => round($qty, 3), 'cartons' => $cartons($qty), 'batches' => $batches($qty),
                    'direct_qty' => round($directQty, 3), 'stock_qty' => round($qty - $directQty, 3),
                    'direct_cartons' => $cartons($directQty), 'stock_cartons' => $cartons($qty - $directQty),
                    'hours' => round($h, 2), 'direct_h' => round($directH, 2), 'done_h' => round($slotDone, 2), 'late' => $isLate,
                    'stock_off_day' => $qty - $directQty > self::EPS && $data['stock_days'] && !in_array($day, $data['stock_days'], true),
                    'behind' => $day < $today && $slotDone < $h - 0.01];
                $lanes[$g]['required'][$day] = ($lanes[$g]['required'][$day] ?? 0.0) + $h;
                $lanes[$g]['direct'][$day] = ($lanes[$g]['direct'][$day] ?? 0.0) + $directH;
                $lanes[$g]['expected'] = ($lanes[$g]['expected'] ?? 0.0) + $h * $this->elapsed($data['capacity'][$g][$day] ?? null, $day, $today, $now);
            }
            $unassignedH = $this->hours($data['routings'], $id, $p['unassigned_qty']);
            $lanes[$g]['unassigned_h'] = ($lanes[$g]['unassigned_h'] ?? 0.0) + $unassignedH;
            $lanes[$g]['done_h'] = ($lanes[$g]['done_h'] ?? 0.0) + $p['done_h'];
            $lanes[$g]['hours'] = ($lanes[$g]['hours'] ?? 0.0) + $p['hours'];
            if ($p['hours'] <= 0) $noRouting++;
            $directH = $p['quantity'] > 0 ? $p['hours'] * $p['direct'] / $p['quantity'] : 0.0;
            $lanes[$g]['direct_h'] = ($lanes[$g]['direct_h'] ?? 0.0) + $directH;
            $products[] = [
                'item_id' => $id, 'name' => $p['name'], 'item_type' => $p['item_type'], 'mode' => $p['mode'], 'group_id' => $p['group_id'],
                'unit' => $p['unit'], 'ppc' => $p['ppc'], 'batch' => $p['batch'], 'quantity' => $p['quantity'], 'cartons' => $p['ppc'] ? round($p['quantity'] / $p['ppc'], 1) : null,
                'batches' => $batches($p['quantity']),
                'direct' => $p['direct'], 'stock' => $p['stock'], 'direct_cartons' => $cartons($p['direct']), 'stock_cartons' => $cartons($p['stock']),
                'direct_h' => round($directH, 2), 'stock_h' => round($p['hours'] - $directH, 2),
                'direct_parts' => array_map(fn($d, $q) => ['due_date' => $d, 'quantity' => round($q, 3), 'cartons' => $cartons($q)], array_keys($p['direct_parts']), array_values($p['direct_parts'])),
                'hours' => round($p['hours'], 2), 'due_date' => $p['due_date'], 'latest_day' => $p['latest_day'],
                'done_h' => round($p['done_h'], 2), 'done_pct' => $p['hours'] > 0 ? round($p['done_h'] / $p['hours'] * 100, 1) : null, 'state' => $p['state'],
                'unassigned_qty' => $p['unassigned_qty'], 'unassigned_h' => round($unassignedH, 2), 'over_qty' => $p['over_qty'],
                'slots' => $slots, 'movable' => $context->canInGroup('prod.gantt.move', $p['group_id']),
                'pins' => $data['pins'][$id] ?? [], 'fixed_qty' => round(array_sum(array_column($data['fixed_week'][$id] ?? [], 'quantity')), 3),
            ];
        }
        usort($products, fn($a, $b) => [$a['group_id'] ?? 999, $a['slots'][0]['day'] ?? '9', $a['latest_day'], $a['name']] <=> [$b['group_id'] ?? 999, $b['slots'][0]['day'] ?? '9', $b['latest_day'], $b['name']]);

        $outGroups = [];
        $totals = ['hours' => 0.0, 'available_h' => 0.0, 'required_h' => 0.0, 'done_h' => 0.0, 'expected_h' => 0.0, 'unassigned_h' => 0.0, 'direct_h' => 0.0];
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
                    'direct_h' => round($lane['direct'][$day] ?? 0.0, 2), 'stock_h' => round($required - ($lane['direct'][$day] ?? 0.0), 2),
                    'free_h' => round($available - $required, 2), 'load_pct' => $available > 0 ? round($required / $available * 100, 1) : null,
                    'status' => $this->status($required, $available), 'elapsed' => round($this->elapsed($cap[$day] ?? null, $day, $today, $now), 3)];
            }
            $available = array_sum(array_column($dayRows, 'available_h'));
            $required = array_sum(array_column($dayRows, 'required_h'));
            $people = max([0, ...array_column($dayRows, 'people')]);
            $outGroups[] = [
                'id' => $gid ?: null, 'name' => $groups[$gid]['name'] ?? null, 'color' => $groups[$gid]['color'] ?? null, 'people' => $people,
                'hours' => round($lane['hours'] ?? 0.0, 2), 'available_h' => round($available, 2), 'required_h' => round($required, 2),
                'direct_h' => round($lane['direct_h'] ?? 0.0, 2), 'stock_h' => round(($lane['hours'] ?? 0.0) - ($lane['direct_h'] ?? 0.0), 2),
                'load_pct' => $available > 0 ? round(($lane['hours'] ?? 0.0) / $available * 100, 1) : null,
                'unassigned_h' => round($lane['unassigned_h'] ?? 0.0, 2), 'over_h' => round($over, 2),
                'done_h' => round($lane['done_h'] ?? 0.0, 2), 'expected_h' => round($lane['expected'] ?? 0.0, 2),
                'movable' => $context->canInGroup('prod.gantt.move', $gid ?: null), 'days' => $dayRows,
            ];
            if ($over > 0.05) $warnings[] = ['code' => 'GROUP_OVERLOAD', 'group' => $groups[$gid]['name'] ?? '—', 'hours' => round($over, 1)];
            foreach (['hours' => $lane['hours'] ?? 0.0, 'available_h' => $available, 'required_h' => $required, 'done_h' => $lane['done_h'] ?? 0.0,
                         'expected_h' => $lane['expected'] ?? 0.0, 'unassigned_h' => $lane['unassigned_h'] ?? 0.0, 'direct_h' => $lane['direct_h'] ?? 0.0] as $k => $val) $totals[$k] += $val;
        }
        $unassigned = count(array_filter($products, fn($p) => $p['unassigned_qty'] > self::EPS));
        if ($unassigned) $warnings[] = ['code' => 'UNASSIGNED', 'count' => $unassigned, 'hours' => round($totals['unassigned_h'], 1)];
        if ($late) $warnings[] = ['code' => 'AFTER_DUE', 'count' => $late];
        if ($noRouting) $warnings[] = ['code' => 'NO_HOURS', 'count' => $noRouting];

        return [
            'week_start' => $data['start'], 'week_end' => $data['end'], 'week_start_day' => WeekStart::day($this->params), 'iso_week' => (int) date('W', strtotime($data['start'])),
            'fte_hours' => max(1.0, (float) $this->params->get('week.fte_hours', '40')),
            'today' => $today, 'now' => date('Y-m-d H:i:s', $now),
            'days' => array_map(fn($d) => ['date' => $d, 'weekday' => (int) date('N', strtotime($d)), 'past' => $d < $today, 'today' => $d === $today,
                'stock_day' => in_array($d, $data['stock_days'], true)], $data['days']),
            'source' => ['type' => $data['plans'] ? 'plan' : 'demand', 'plans' => array_map(fn($p) => ['id' => (int) $p['id'], 'version' => (int) $p['version'],
                'status' => $p['status'], 'period_start' => $p['period_start'], 'period_end' => $p['period_end']], $data['plans'])],
            'can_move' => (bool) array_filter($outGroups, fn($g) => $g['movable']), 'can_schedule' => $context->can('prod.plan.validate'),
            // Wszystkie stałe partie (📌) : lista pod tablicą.
            'pins' => array_merge([], ...array_values($data['pins'])),
            'groups' => $outGroups,
            'products' => $products,
            'totals' => array_map(fn($v) => round($v, 2), $totals) + [
                'products' => count($products), 'unassigned' => $unassigned, 'stock_h' => round($totals['hours'] - $totals['direct_h'], 2),
                'direct_cartons' => round(array_sum(array_map(fn($p) => $p['direct_cartons'] ?? 0, $products)), 1),
                'stock_cartons' => round(array_sum(array_map(fn($p) => $p['stock_cartons'] ?? 0, $products)), 1),
                'direct_products' => count(array_filter($products, fn($p) => $p['direct'] > self::EPS)),
                'stock_products' => count(array_filter($products, fn($p) => $p['stock'] > self::EPS)),
                'load_pct' => $totals['available_h'] > 0 ? round($totals['hours'] / $totals['available_h'] * 100, 1) : null,
                'done_pct' => $totals['hours'] > 0 ? round($totals['done_h'] / $totals['hours'] * 100, 1) : null,
                'delay_h' => round(max(0.0, $totals['expected_h'] - $totals['done_h']), 2),
            ],
            'warnings' => array_map(fn($w) => $w + ['message' => $context->translator->error(($w['code'] === 'UNPLANNED_PRODUCTS' ? 'PLAN_WARNING_' : 'WEEK_WARNING_') . $w['code'],
                isset($w['hours']) ? ['hours' => number_format($w['hours'], 1, ',', ' ')] + $w : $w)], $warnings),
        ];
    }

    /** Część dnia, która już minęła (plan « na teraz ») : 1 dla dni minionych, udział zmiany dzisiaj. */
    /** Ostatni dzień produkcji nie później niż dzień wyjazdu (termin) ; termin przed tygodniem = pierwszy dzień. */
    private function latestDay(array $days, string $due): string
    {
        $before = array_values(array_filter($days, fn($d) => $d <= $due));
        return $before ? end($before) : $days[0];
    }

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

    /** Pierwszy dzień tygodnia produkcji (week.start_day) zawierającego datę. */
    private function weekStart(?string $date): string
    {
        (new Validator(['date' => $date]))->date('date', true, false)->throwIfInvalid();
        return WeekStart::of((string) $date, WeekStart::day($this->params));
    }
}
