<?php
declare(strict_types=1);

namespace App\Services\Workshop\Scheduling;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SchedulingRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * P4 — ordonnancement d'un plan validé et charge des équipes.
 *
 * Fenêtres : pour chaque jour, les shifts actifs ce jour-là (active_days) où le poste est disponible
 * (tfb_prod_workstation_shift, disponible par défaut) et où le groupe du poste a une équipe d'au moins
 * autant de personnes que l'étape demande d'opérateurs — la nuit comprise : sans équipe de nuit, pas
 * de travail de nuit. Les robots autonomes tournent sur toutes leurs fenêtres ; les attentes en
 * chambre sont passives.
 * Chambres (fermentation, froid, surgélation) : capacité cumulée en unités.
 * Charge : heures de travail planifiées (opérateurs × durée, nettoyage compris) ÷ heures
 * disponibles des équipes (effectif prévu × durée du shift), par groupe, jour et shift ;
 * vert entre load.green_min_pct et load.green_max_pct, cible load.target_pct.
 */
final class SchedulingService
{
    private const CUMULATIVE_TYPES = ['fermentation', 'froid', 'surgelation'];

    public function __construct(
        private SchedulingRepository $repo,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function schedulePlan(int $planId, ApiContext $context, ?int $now = null): array
    {
        $plan = $this->repo->plan($planId) ?? throw ApiException::notFound('plan', $planId);
        if (!in_array($plan['status'], ['validated', 'frozen'], true)) throw new ApiException('PLAN_NOT_VALIDATED', 409, [], ['status' => $plan['status']]);
        $orders = $this->repo->orders($planId);
        if (!$orders) return ['plan_id' => $planId, 'operations' => 0, 'conflicts' => [], 'load' => []];

        $now ??= time();
        $earliest = max((int) (ceil($now / 900) * 900), strtotime($plan['period_start'] . ' -1 day'));
        $horizonEnd = max(array_map(fn($o) => strtotime($o['due_at']), $orders)) + 86400;
        $from = date('Y-m-d H:i:s', $earliest - 86400);
        $to = date('Y-m-d H:i:s', $horizonEnd);

        $shifts = $this->repo->shifts();
        $workstations = [];
        foreach ($this->repo->workstations() as $w) $workstations[(int) $w['id']] = $w;
        $availability = $this->repo->availability();
        $teams = $this->repo->teams();
        $segments = $this->segments($shifts, $earliest - 86400, $horizonEnd + 86400);

        // Effectif prévu par groupe et par shift : une opération qui demande n opérateurs ne tourne que
        // sur les shifts où le groupe du poste a au moins n personnes (la nuit comprise) ; un robot
        // autonome tourne sur toutes ses fenêtres ; une attente en chambre est passive.
        $headcount = [];
        foreach ($teams as $t) $headcount[(int) $t['id_group']][(int) $t['id_shift_template']] = ($headcount[(int) $t['id_group']][(int) $t['id_shift_template']] ?? 0) + (int) $t['planned_headcount'];
        $engine = new Scheduler($earliest, max(900, (int) $this->params->get('scheduling.max_operation_min', '240') * 60));
        foreach ($workstations as $id => $w) {
            $day = [];
            $all = [];
            $staffed = [];
            foreach ($segments as $seg) {
                if (!($availability[$id][$seg['shift_id']] ?? true)) continue;
                $all[] = [$seg['start'], $seg['end']];
                if (!$seg['night']) $day[] = [$seg['start'], $seg['end']];
                $people = $headcount[(int) $w['id_group']][$seg['shift_id']] ?? 0;
                for ($n = 1; $n <= min($people, 10); $n++) $staffed['staff:' . $n][] = [$seg['start'], $seg['end']];
            }
            $cumulative = in_array($w['type'], self::CUMULATIVE_TYPES, true);
            $engine->addResource($id, $day, $all, $cumulative, $cumulative ? (float) $w['capacity_per_pass'] : 1.0, $staffed + ['auto' => $all]);
        }
        $orderIds = array_map(fn($o) => (int) $o['id'], $orders);
        foreach ($this->repo->existingOperations($from, $to, $orderIds) as $op) {
            $units = $op['kind'] === 'delay' ? 1.0 : 1.0;
            $engine->reserve((int) $op['id_workstation'], strtotime($op['planned_start']), strtotime($op['planned_end']), $units);
        }

        $windowKey = function (int $wsId, float $operators) use ($workstations): string {
            $w = $workstations[$wsId] ?? null;
            if ($w && $w['type'] === 'robot' && $w['robot_mode'] === 'autonome') return 'auto';
            return 'staff:' . max(1, (int) ceil($operators));
        };
        $routings = $this->repo->routings(array_values(array_unique(array_map(fn($o) => (int) $o['id_item'], $orders))));
        $stepIds = [];
        foreach ($routings as $steps) foreach ($steps as $s) $stepIds[] = (int) $s['id'];
        $alternatives = $this->repo->alternatives($stepIds);
        $input = [];
        foreach ($orders as $o) {
            $steps = [];
            foreach ($routings[(int) $o['id_item']] ?? [] as $s) {
                $operators = (float) $s['operators_required'];
                $steps[] = [
                    'id' => (int) $s['id'], 'workstation_id' => (int) $s['id_workstation'], 'fixed_time_s' => (int) $s['fixed_time_s'],
                    'variable_time_s' => (float) $s['variable_time_s'], 'capacity_per_pass' => (float) $s['capacity_per_pass'],
                    'operators_required' => $operators, 'delay_after_s' => (int) $s['delay_after_s'],
                    'delay_workstation_id' => $s['delay_id_workstation'] !== null ? (int) $s['delay_id_workstation'] : null,
                    'cleaning_time_s' => (int) $s['cleaning_time_s'], 'window_key' => $windowKey((int) $s['id_workstation'], $operators),
                    'alternatives' => array_map(fn($a) => [
                        'workstation_id' => (int) $a['id_workstation'], 'fixed_time_s' => (int) $a['fixed_time_s'], 'variable_time_s' => (float) $a['variable_time_s'],
                        'capacity_per_pass' => (float) $a['capacity_per_pass'], 'window_key' => $windowKey((int) $a['id_workstation'], $operators),
                    ], $alternatives[(int) $s['id']] ?? []),
                ];
            }
            $input[] = ['id' => (int) $o['id'], 'due' => strtotime($o['due_at']), 'qty' => (float) $o['quantity'],
                'parent' => $o['id_parent_order'] !== null && in_array((int) $o['id_parent_order'], $orderIds, true) ? (int) $o['id_parent_order'] : null, 'steps' => $steps];
        }
        $result = $engine->schedule($input);

        $teamFor = function (int $wsId, int $start) use ($workstations, $teams, $segments): ?int {
            $w = $workstations[$wsId] ?? null;
            if (!$w) return null;
            foreach ($segments as $seg) {
                if ($start < $seg['start'] || $start >= $seg['end']) continue;
                foreach ($teams as $t) if ((int) $t['id_group'] === (int) $w['id_group'] && (int) $t['id_shift_template'] === $seg['shift_id']) return (int) $t['id'];
            }
            return null;
        };
        $this->tx->execute(function () use ($orderIds, $result, $teamFor, $context, $planId) {
            $this->repo->deleteUnlocked($orderIds);
            foreach ($result['operations'] as $op) {
                $this->repo->insert([
                    'id_order' => $op['order_id'], 'id_routing_step' => $op['step_id'], 'kind' => $op['kind'], 'id_workstation' => $op['workstation_id'],
                    'id_team' => $op['kind'] === 'delay' ? null : $teamFor($op['workstation_id'], $op['start']),
                    'planned_start' => date('Y-m-d H:i:s', $op['start']), 'planned_end' => date('Y-m-d H:i:s', $op['end']),
                    'status' => 'planned', 'locked' => 0, 'updated_by' => $context->userId,
                ]);
            }
            $this->audit->record($context, 'schedule', 'plan', $planId, null, ['operations' => count($result['operations']), 'conflicts' => count($result['conflicts'])]);
        });

        $names = [];
        foreach ($orders as $o) $names[(int) $o['id']] = ['code' => $o['code'], 'name' => $o['name'], 'due_at' => $o['due_at']];
        return [
            'plan_id' => $planId,
            'operations' => count($result['operations']),
            'conflicts' => array_map(fn($c) => $c + $names[$c['order_id']] + ['message' => $context->translator->error('SCHEDULE_' . $c['reason'], $names[$c['order_id']])], $result['conflicts']),
            'load' => $this->load(date('Y-m-d', $earliest), date('Y-m-d', $horizonEnd - 86400), $context),
        ];
    }

    public function operations(string $from, string $to, ?int $groupId, ApiContext $context): array
    {
        [$from, $to] = $this->period($from, $to);
        $lang = $context->translator->lang();
        return array_map(fn($o) => $this->format($o, $lang), $this->repo->operations($from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00', $groupId));
    }

    /** Opération telle que l'API la rend (Gantt). */
    public function format(array $o, string $lang): array
    {
        return [
            'id' => (int) $o['id'], 'order_id' => (int) $o['id_order'], 'order_code' => $o['order_code'], 'origin' => $o['origin'],
            'parent_order_id' => $o['id_parent_order'] !== null ? (int) $o['id_parent_order'] : null,
            'item_id' => (int) $o['id_item'], 'item' => $o['item_name'], 'quantity' => (float) $o['order_qty'], 'unit' => $o['base_unit'],
            'kind' => $o['kind'], 'step_no' => $o['step_no'] !== null ? (int) $o['step_no'] : null, 'step' => $o['step_name_' . $lang] ?? null,
            'workstation_id' => (int) $o['id_workstation'], 'workstation' => $o['ws_code'], 'workstation_name' => $o['ws_name_' . $lang], 'workstation_type' => $o['ws_type'],
            'group_id' => (int) $o['id_group'], 'team' => $o['team_code'], 'operators' => $this->operators($o),
            'start' => $o['planned_start'], 'end' => $o['planned_end'], 'due_at' => $o['due_at'], 'status' => $o['status'], 'locked' => (bool) $o['locked'],
        ];
    }

    /** Opérateurs mobilisés : étape pour le travail, 1 pour le nettoyage, 0 pour une attente passive. */
    public function operators(array $o): float
    {
        return $o['kind'] === 'work' ? (float) ($o['operators_required'] ?? 1) : ($o['kind'] === 'cleaning' ? 1.0 : 0.0);
    }

    /**
     * Fenêtres où une opération peut tourner sur un poste entre deux instants : shifts où le poste est
     * disponible et où le groupe a au moins $operators personnes ; robot autonome ou attente passive
     * ($operators = 0) : toutes les fenêtres disponibles. Même règle que l'ordonnancement.
     * @return list<array{0:int,1:int}>
     */
    public function windows(array $workstation, float $operators, int $from, int $to): array
    {
        $availability = $this->repo->availability()[(int) $workstation['id']] ?? [];
        $headcount = [];
        foreach ($this->repo->teams() as $t) {
            if ((int) $t['id_group'] === (int) $workstation['id_group']) $headcount[(int) $t['id_shift_template']] = ($headcount[(int) $t['id_shift_template']] ?? 0) + (int) $t['planned_headcount'];
        }
        $passive = $operators <= 0 || ($workstation['type'] === 'robot' && $workstation['robot_mode'] === 'autonome');
        $out = [];
        foreach ($this->segments($this->repo->shifts(), $from - 86400, $to + 86400) as $seg) {
            if (!($availability[$seg['shift_id']] ?? true)) continue;
            if (!$passive && ($headcount[$seg['shift_id']] ?? 0) < (int) ceil($operators)) continue;
            $out[] = [$seg['start'], $seg['end']];
        }
        return Scheduler::merge($out);
    }

    /** Équipe du groupe du poste sur le shift qui contient l'instant. */
    public function teamAt(array $workstation, int $at): ?int
    {
        $teams = $this->repo->teams();
        foreach ($this->segments($this->repo->shifts(), $at - 86400, $at + 86400) as $seg) {
            if ($at < $seg['start'] || $at >= $seg['end']) continue;
            foreach ($teams as $t) if ((int) $t['id_group'] === (int) $workstation['id_group'] && (int) $t['id_shift_template'] === $seg['shift_id']) return (int) $t['id'];
        }
        return null;
    }

    /** Charge par groupe, jour et shift : heures planifiées ÷ heures des équipes. */
    public function load(string $from, string $to, ApiContext $context): array
    {
        [$from, $to] = $this->period($from, $to);
        $start = strtotime($from);
        $end = strtotime($to . ' +1 day');
        $shifts = $this->repo->shifts();
        $segments = $this->segments($shifts, $start, $end);
        $teams = $this->repo->teams();
        $ops = $this->repo->operations(date('Y-m-d H:i:s', $start - 86400), date('Y-m-d H:i:s', $end + 86400), null);
        $greenMin = (float) $this->params->get('load.green_min_pct', '90');
        $greenMax = (float) $this->params->get('load.green_max_pct', '100');
        $target = (float) $this->params->get('load.target_pct', '95');
        $lang = $context->translator->lang();
        $shiftCodes = [];
        foreach ($shifts as $s) $shiftCodes[(int) $s['id']] = $s['code'];
        $out = [];
        foreach ($this->repo->groups() as $g) {
            foreach ($segments as $seg) {
                if ($seg['start'] < $start || $seg['start'] >= $end) continue;
                $headcount = 0;
                foreach ($teams as $t) if ((int) $t['id_group'] === (int) $g['id'] && (int) $t['id_shift_template'] === $seg['shift_id']) $headcount += (int) $t['planned_headcount'];
                $required = 0.0;
                foreach ($ops as $op) {
                    if ((int) $op['id_group'] !== (int) $g['id'] || $op['kind'] === 'delay') continue;
                    $overlap = min(strtotime($op['planned_end']), $seg['end']) - max(strtotime($op['planned_start']), $seg['start']);
                    if ($overlap <= 0) continue;
                    $required += $overlap / 3600 * ($op['kind'] === 'work' ? (float) ($op['operators_required'] ?? 1) : 1.0);
                }
                $available = $headcount * ($seg['end'] - $seg['start']) / 3600;
                if ($available == 0.0 && $required == 0.0) continue;
                $pct = $available > 0 ? $required / $available * 100 : null;
                $out[] = [
                    'group_id' => (int) $g['id'], 'group' => $g['name_' . $lang], 'color' => $g['color'],
                    'date' => date('Y-m-d', $seg['start']), 'shift' => $shiftCodes[$seg['shift_id']] ?? '', 'shift_id' => $seg['shift_id'], 'night' => $seg['night'],
                    'start' => date('Y-m-d H:i:s', $seg['start']), 'end' => date('Y-m-d H:i:s', $seg['end']),
                    'headcount' => $headcount, 'available_h' => round($available, 2), 'required_h' => round($required, 2),
                    'load_pct' => $pct === null ? null : round($pct, 1), 'target_pct' => $target,
                    'status' => $pct === null ? 'no_team' : ($pct < $greenMin ? 'under' : ($pct > $greenMax ? 'over' : 'ok')),
                ];
            }
        }
        return $out;
    }

    /**
     * Occurrences des shifts entre deux instants : un segment par jour et par shift actif ce jour-là.
     * @return list<array{shift_id:int, start:int, end:int, night:bool}>
     */
    public function segments(array $shifts, int $from, int $to): array
    {
        $out = [];
        for ($day = strtotime(date('Y-m-d', $from)); $day <= $to; $day = strtotime('+1 day', $day)) {
            $weekday = (int) date('N', $day);
            foreach ($shifts as $s) {
                $days = array_map('intval', explode(',', (string) $s['active_days']));
                if (!in_array($weekday, $days, true)) continue;
                $start = strtotime(date('Y-m-d', $day) . ' ' . $s['start_time']);
                $end = strtotime(date('Y-m-d', $day) . ' ' . $s['end_time']);
                if ($end <= $start) $end = strtotime('+1 day', $end);
                if ($end <= $from || $start >= $to) continue;
                $out[] = ['shift_id' => (int) $s['id'], 'start' => $start, 'end' => $end, 'night' => (bool) $s['is_night']];
            }
        }
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    /** @return array{0:string,1:string} */
    private function period(string $from, string $to): array
    {
        $v = (new Validator(['from' => $from, 'to' => $to]))->date('from', true, false)->date('to', true, false);
        $v->throwIfInvalid();
        if ($to < $from) throw ApiException::validation([['field' => 'to', 'code' => 'DATE_ORDER', 'params' => []]]);
        $max = max(1, (int) $this->params->get('planning.max_period_days', '28'));
        if ((strtotime($to) - strtotime($from)) / 86400 + 1 > $max) throw ApiException::validation([['field' => 'to', 'code' => 'PERIOD_TOO_LONG', 'params' => ['max' => $max]]]);
        return [$from, $to];
    }
}
