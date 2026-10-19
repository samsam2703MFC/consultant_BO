<?php
declare(strict_types=1);

namespace App\Services\Workshop\Scheduling;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Repositories\Workshop\SchedulingRepository;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * P6 — propozycja optymalizatora zmian dla okresu, zastosowanie i symulacja.
 *
 * Propozycja : obciążenie z P4 (operacje już uszeregowane, obsada dnia) → ShiftOptimizer. Artykuły
 * MTS do przyspieszenia : karta produktu MTS z wolnym miejscem pod stanem maks. (stan wolny + OF MTS
 * otwarte), godziny na sztukę w grupie z gamy (dla partii), w granicach surgelatora.
 * Zastosowanie : obsada dnia zapisana (source = optimizer) i OF MTS dodane do zatwierdzonego planu,
 * z terminem końca zmiany, uszeregowane w wolnych miejscach — istniejące operacje (MTO) nie ruszają się.
 * Symulacja : obciążenie z inną obsadą, bez zapisu.
 */
final class OptimizerService
{
    public function __construct(
        private SchedulingService $scheduling,
        private SchedulingRepository $repo,
        private PlanningRepository $planning,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function propose(string $from, string $to, ApiContext $context, ?array $levers = null): array
    {
        $rows = $this->rows($this->scheduling->load($from, $to, $context));
        $levers ??= $this->levers();
        $freezer = (int) $this->params->get('cold.freezer_capacity_cartons', '0');
        $result = ShiftOptimizer::optimize($rows, $this->mtsCandidates(), $levers, $this->config() + [
            'freezer_room' => $freezer > 0 ? (float) max(0, $freezer - $this->planning->freezerOccupancy()) : null,
        ]);
        $plans = $this->validatedPlans();
        foreach ($result as &$r) {
            foreach ($r['actions'] as &$a) {
                if ($a['lever'] === 'advance_mts') $a['plan_id'] = $this->planFor($plans, $r['date']);
            }
            unset($a);
        }
        unset($r);
        $notes = [];
        if (in_array('alternative_workstation', $levers, true)) $notes[] = 'ALTERNATIVE_WORKSTATION_IN_SCHEDULE';
        if ($freezer <= 0) $notes[] = 'FREEZER_CAPACITY_NOT_SET';
        return ['from' => $from, 'to' => $to, 'levers' => $levers, 'config' => $this->config(), 'rows' => $result,
            'summary' => ShiftOptimizer::summary($result), 'notes' => $notes];
    }

    /**
     * 1. OF MTS des avances proposées, ordonnancés dans les places libres ; ceux qui ne tiennent pas
     *    (poste ou chambre déjà pleins) sont supprimés et signalés.
     * 2. Obsada recalculée sur la charge réellement ordonnancée (leviers sans « advance_mts ») et écrite.
     */
    public function apply(string $from, string $to, ApiContext $context, ?int $now = null): array
    {
        $proposal = $this->propose($from, $to, $context);
        $created = [];
        $skipped = 0;
        $this->tx->execute(function () use ($proposal, &$created, &$skipped) {
            foreach ($proposal['rows'] as $r) {
                foreach ($r['actions'] as $action) {
                    // Les heures d'emballage d'une avance (follows_group_id) appartiennent au même OF.
                    if ($action['lever'] !== 'advance_mts' || isset($action['follows_group_id'])) continue;
                    if ($action['plan_id'] === null) { $skipped++; continue; }
                    $created[$action['plan_id']][] = $this->planning->insertOrder([
                        'code' => $this->planning->nextOrderCode($r['date']), 'id_plan' => $action['plan_id'], 'id_item' => $action['item_id'],
                        'quantity' => $action['quantity'], 'origin' => 'MTS', 'due_at' => $r['end'], 'priority' => 200,
                    ]);
                }
            }
        });
        $operations = 0;
        $placed = 0;
        $failed = [];
        foreach ($created as $planId => $ids) {
            $result = $this->scheduling->scheduleOrders((int) $planId, $ids, $context, $now);
            $operations += $result['operations'];
            $notPlaced = array_values(array_unique(array_map(fn($c) => (int) $c['order_id'], $result['conflicts'])));
            if ($notPlaced) $this->repo->deleteOrders($notPlaced);
            $placed += count($ids) - count($notPlaced);
            foreach ($result['conflicts'] as $c) if (!isset($failed[$c['order_id']])) $failed[$c['order_id']] = $c;
        }

        $staffingProposal = $this->propose($from, $to, $context, array_values(array_diff($this->levers(), ['advance_mts'])));
        $staffing = 0;
        $this->tx->execute(function () use ($staffingProposal, $proposal, $context, $placed, &$staffing) {
            foreach ($staffingProposal['rows'] as $r) {
                $b = $r['before'];
                $a = $r['after'];
                if ($a['headcount'] === $b['headcount'] && abs($a['overtime_h'] - $b['overtime_h']) < 0.001) continue;
                $levers = array_values(array_unique(array_column($r['actions'], 'lever')));
                $this->repo->saveStaffing($r['date'], $r['group_id'], $r['shift_id'], $a['headcount'], (float) $a['overtime_h'], 'optimizer', implode(', ', $levers), $context->userId);
                $staffing++;
            }
            $this->audit->record($context, 'optimize', 'staffing', $proposal['from'] . '/' . $proposal['to'], ['summary' => $proposal['summary']['before']],
                ['summary' => $staffingProposal['summary']['after'], 'staffing_rows' => $staffing, 'mts_orders' => $placed]);
        });
        return [
            'staffing_rows' => $staffing,
            'mts_orders' => $placed,
            'mts_not_placed' => count($failed),
            'mts_skipped_no_plan' => $skipped,
            'operations' => $operations,
            'conflicts' => array_values($failed),
            'summary_before' => $proposal['summary']['before'],
            'summary_after' => $staffingProposal['summary']['after'],
        ];
    }

    /** Obciążenie z obsadą zmienioną w pamięci (nic nie jest zapisywane). */
    public function simulate(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['from', 'to', 'staffing'])->date('from', true, false)->date('to', true, false);
        $v->throwIfInvalid();
        $overrides = [];
        $errors = [];
        foreach (array_values(is_array($body['staffing'] ?? null) ? $body['staffing'] : []) as $i => $s) {
            $row = (new Validator(is_array($s) ? $s : []))->only(['date', 'group_id', 'shift_id', 'headcount', 'overtime_h'])
                ->date('date', true, false)->integer('group_id', true, 1)->integer('shift_id', true, 1)->integer('headcount', true, 0, 50)->decimal('overtime_h', false, 0, 200);
            foreach ($row->errors() as $e) $errors[] = ['field' => "staffing.{$i}.{$e['field']}"] + $e;
            if ($row->errors()) continue;
            $c = $row->clean();
            $overrides[$c['date']][$c['group_id']][$c['shift_id']] = ['headcount' => $c['headcount'], 'overtime_h' => (float) ($c['overtime_h'] ?? 0), 'source' => 'simulation'];
        }
        if ($errors) throw ApiException::validation($errors);
        $min = $this->config()['green_min'] / 100;
        $max = $this->config()['green_max'] / 100;
        $rows = array_map(function ($r) use ($min, $max) {
            $state = ShiftOptimizer::state($r['headcount'], $r['overtime_h'], $r['required_h'], $r['shift_h'], $min, $max);
            return $r + ['before' => $state, 'after' => $state];
        }, $this->rows($this->scheduling->load($body['from'], $body['to'], $context, $overrides)));
        return ['rows' => $rows, 'summary' => ShiftOptimizer::summary($rows)['after']];
    }

    /** Obsada ręczna jednego dnia, grupy i zmiany ; headcount = null wraca do zespołów. */
    public function saveStaffing(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['date', 'group_id', 'shift_id', 'headcount', 'overtime_h', 'note'])
            ->date('date', true, false)->integer('group_id', true, 1)->integer('shift_id', true, 1)
            ->integer('headcount', true, 0, 50, true)->decimal('overtime_h', false, 0, 200)->string('note', 255, false, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        if (!in_array($c['group_id'], array_map(fn($g) => (int) $g['id'], $this->repo->groups()), true)) {
            throw ApiException::validation([['field' => 'group_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => []]]);
        }
        if (!in_array($c['shift_id'], array_map(fn($s) => (int) $s['id'], $this->repo->shifts()), true)) {
            throw ApiException::validation([['field' => 'shift_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => []]]);
        }
        $this->tx->execute(function () use ($c, $context) {
            $this->repo->saveStaffing($c['date'], $c['group_id'], $c['shift_id'], $c['headcount'], (float) ($c['overtime_h'] ?? 0), 'manual', $c['note'] ?? null, $context->userId);
            $this->audit->record($context, $c['headcount'] === null ? 'delete' : 'update', 'staffing', "{$c['date']}/{$c['group_id']}/{$c['shift_id']}", null, $c);
        });
        $row = array_values(array_filter($this->scheduling->load($c['date'], $c['date'], $context), fn($l) => $l['group_id'] === $c['group_id'] && $l['shift_id'] === $c['shift_id']));
        return $row[0] ?? ['date' => $c['date'], 'group_id' => $c['group_id'], 'shift_id' => $c['shift_id'], 'headcount' => $c['headcount'] ?? 0, 'required_h' => 0, 'available_h' => 0, 'status' => 'closed'];
    }

    /** Pozycje optymalizatora z wierszy obciążenia. */
    private function rows(array $load): array
    {
        return array_map(fn($l) => [
            'key' => "{$l['date']}|{$l['group_id']}|{$l['shift_id']}", 'date' => $l['date'], 'shift_id' => $l['shift_id'], 'shift' => $l['shift'],
            'group_id' => $l['group_id'], 'group' => $l['group'], 'color' => $l['color'], 'start' => $l['start'], 'end' => $l['end'],
            'shift_h' => (float) $l['shift_h'], 'headcount' => (int) $l['headcount'], 'base_headcount' => (int) $l['base_headcount'],
            'overtime_h' => (float) $l['overtime_h'], 'required_h' => (float) $l['required_h'], 'max_operators' => (int) $l['max_operators'],
            'staffing' => $l['staffing'],
        ], $load);
    }

    /** Artykuły MTS z miejscem pod stanem maks., godziny na sztukę w każdej grupie gamy. */
    private function mtsCandidates(): array
    {
        $stock = $this->planning->freeStockCartons();
        $open = $this->planning->openMtsOrders();
        $routings = $this->planning->routings();
        $groupOf = [];
        foreach ($this->repo->workstations() as $w) $groupOf[(int) $w['id']] = (int) $w['id_group'];
        $out = [];
        foreach ($this->planning->items() as $it) {
            if ($it['mode'] !== 'MTS' || $it['item_type'] !== 'product' || $it['stock_max'] === null || $it['status'] !== 'validated') continue;
            $steps = $routings[(int) $it['id']] ?? [];
            if (!$steps) continue;
            $ppc = max(1, (int) $it['ppc']);
            $room = (float) $it['stock_max'] - ($stock[(int) $it['id_final_product']] ?? 0) * $ppc - ($open[(int) $it['id']] ?? 0.0);
            if ($room <= 0) continue;
            // Temps de travail et temps écoulé ≈ a + b × quantité, mesurés sur deux quantités réalistes
            // (une partie et vingt) : le temps fixe par passage n'est pas compté pour chaque pièce.
            $batch = (float) ($it['batch_multiple'] ?: ($it['batch_min'] ?: min($room, 100)));
            $qa = max($batch, (float) ($it['batch_min'] ?? 0));
            $qb = $qa * 20;
            $labour = fn(float $q) => array_reduce($steps, function ($acc, $s) use ($q, $groupOf) {
                $group = $groupOf[(int) $s['id_workstation']] ?? null;
                if ($group !== null) $acc[$group] = ($acc[$group] ?? 0) + StandardTime::step($s, $q)['labour_s'];
                return $acc;
            }, []);
            [$la, $lb] = [$labour($qa), $labour($qb)];
            $operators = [];
            foreach ($steps as $s) {
                $group = $groupOf[(int) $s['id_workstation']] ?? null;
                if ($group !== null) $operators[$group] = max($operators[$group] ?? 1, (int) ceil((float) $s['operators_required']));
            }
            $e1 = StandardTime::routing($steps, $qa)['totals']['elapsed_s'];
            $e2 = StandardTime::routing($steps, $qb)['totals']['elapsed_s'];
            $eb = max(1e-6, ($e2 - $e1) / ($qb - $qa));
            foreach ($la as $group => $labourA) {
                $unit = max(1e-6, (($lb[$group] ?? $labourA) - $labourA) / ($qb - $qa));
                $out[] = ['item_id' => (int) $it['id'], 'group_id' => $group, 'name' => $it['name'],
                    'unit_h' => $unit / 3600, 'fixed_h' => max(0.0, $labourA - $unit * $qa) / 3600, 'room' => $room,
                    'batch_min' => $it['batch_min'] !== null ? (float) $it['batch_min'] : null, 'batch_multiple' => $it['batch_multiple'] !== null ? (float) $it['batch_multiple'] : null,
                    'ppc' => $ppc, 'operators' => $operators[$group], 'elapsed_a' => max(0.0, $e1 - $eb * $qa), 'elapsed_b' => $eb];
            }
        }
        return $out;
    }

    private function levers(): array
    {
        $levers = json_decode($this->params->get('optimizer.levers_order', ''), true);
        return is_array($levers) && $levers ? array_values(array_intersect($levers, ShiftOptimizer::LEVERS)) : ShiftOptimizer::LEVERS;
    }

    private function config(): array
    {
        return [
            'target' => (float) $this->params->get('load.target_pct', '95'),
            'green_min' => (float) $this->params->get('load.green_min_pct', '90'),
            'green_max' => (float) $this->params->get('load.green_max_pct', '100'),
            'max_overtime_h' => (float) $this->params->get('optimizer.max_overtime_h', '2'),
        ];
    }

    private function validatedPlans(): array
    {
        return array_values(array_filter($this->planning->plans(null), fn($p) => in_array($p['status'], ['validated', 'frozen'], true)));
    }

    private function planFor(array $plans, string $date): ?int
    {
        foreach ($plans as $p) if ($p['period_start'] <= $date && $p['period_end'] >= $date) return (int) $p['id'];
        return null;
    }
}
