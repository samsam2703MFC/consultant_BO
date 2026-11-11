<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ItemRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\TimeStudyRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Chronométrage : chaque mesure d'une étape donne un temps unitaire (par pièce ou par passage,
 * en retirant l'autre part de la norme actuelle). La norme proposée est la moyenne des
 * timing.moving_average_n dernières mesures retenues, dès timing.min_measurements mesures ;
 * un écart au-delà de timing.deviation_alert_pct est signalé. La norme de la gamme ne change
 * que sur décision explicite (apply-standard), tracée dans le journal.
 */
final class TimeStudyService
{
    public const COMPONENTS = ['variable', 'fixed'];

    public function __construct(
        private TimeStudyRepository $studies,
        private ItemRepository $items,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(int $stepId, ApiContext $context): array
    {
        $step = $this->loadStep($stepId);
        return [
            'step' => $this->presentStep($step, $context),
            'proposals' => array_map(fn($c) => $this->proposal($step, $c), self::COMPONENTS),
            'measurements' => array_map(fn($m) => $this->present($m), $this->studies->list($stepId)),
        ];
    }

    public function record(int $stepId, array $input, ApiContext $context): array
    {
        $step = $this->loadStep($stepId);
        $this->requireInGroup($context, 'prod.execution.write', $step);
        $v = (new Validator($input))->only(['quantity', 'duration_s', 'passes', 'component', 'workstation_id', 'measured_at', 'production_employee_id', 'note'])
            ->decimal('quantity', true, 0, 1_000_000, false, true)
            ->integer('duration_s', true, 1, 7 * 86400)
            ->integer('passes', false, 1, 100_000)
            ->choice('component', self::COMPONENTS, false)
            ->integer('workstation_id', false, 1, null, true)
            ->string('measured_at', 19, false, false, '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', 'INVALID_DATE')
            ->integer('production_employee_id', false, 1, null, true)
            ->string('note', 500, false, true);
        $employeeId = null;
        if ($v->value('production_employee_id') !== null) {
            $employeeId = $this->studies->employeeIdFor($v->value('production_employee_id'));
            if ($employeeId === null) $v->add('production_employee_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('production_employee_id')]);
        }
        if ($v->value('workstation_id') !== null) {
            $alternatives = array_column($this->items->alternatives([$stepId])[$stepId] ?? [], 'id_workstation');
            if ((int) $v->value('workstation_id') !== (int) $step['id_workstation'] && !in_array((string) $v->value('workstation_id'), array_map('strval', $alternatives), true)) {
                $v->add('workstation_id', 'NOT_STEP_WORKSTATION');
            }
        }
        $measuredAt = $v->value('measured_at') !== null ? str_replace('T', ' ', $v->value('measured_at')) : date('Y-m-d H:i:s');
        if (strlen($measuredAt) === 16) $measuredAt .= ':00';
        if ($v->value('measured_at') !== null && strtotime($measuredAt) === false) $v->add('measured_at', 'INVALID_DATE');
        if (strtotime($measuredAt) > time() + 300) $v->add('measured_at', 'IN_FUTURE');
        $v->throwIfInvalid();

        $quantity = (float) $v->value('quantity');
        $passes = (int) ($v->value('passes') ?? StandardTime::passes($quantity, (float) $step['capacity_per_pass']));
        $component = $v->value('component') ?? StandardTime::defaultComponent($step);
        $duration = (int) $v->value('duration_s');
        // Mesure sur le poste principal : workstation_id NULL ; sur une alternative : son id.
        $workstationId = $v->value('workstation_id') !== null && (int) $v->value('workstation_id') !== (int) $step['id_workstation'] ? (int) $v->value('workstation_id') : null;
        $id = $this->tx->execute(function () use ($stepId, $workstationId, $component, $quantity, $passes, $duration, $step, $measuredAt, $employeeId, $v, $context) {
            $id = $this->studies->insert([
                'id_routing_step' => $stepId,
                'id_workstation' => $workstationId,
                'component' => $component,
                'quantity' => $quantity,
                'passes' => $passes,
                'duration_s' => $duration,
                'unit_time_s' => round(StandardTime::unitTime($component, $step, $quantity, $passes, $duration), 4),
                'measured_at' => $measuredAt,
                'id_employee' => $employeeId,
                'measured_by' => $context->userId,
                'note' => $v->value('note'),
            ]);
            $this->audit->record($context, 'create', 'time_study', $id, null, ['step_id' => $stepId, 'quantity' => $quantity, 'duration_s' => $duration]);
            return $id;
        });
        return $this->list($stepId, $context) + ['created_id' => $id];
    }

    public function setExcluded(int $id, array $input, ApiContext $context): array
    {
        $study = $this->studies->find($id) ?? throw ApiException::notFound('time_study', $id);
        $step = $this->loadStep((int) $study['id_routing_step']);
        $this->requireInGroup($context, 'prod.execution.write', $step);
        $v = (new Validator($input, true))->only(['excluded', 'note'])->boolean('excluded', true)->string('note', 500, false, true);
        $v->throwIfInvalid();
        $data = ['excluded' => $v->value('excluded')];
        if ($v->provided('note')) $data['note'] = $v->value('note');
        $this->studies->update($id, $data);
        $this->audit->record($context, 'update', 'time_study', $id, $study, $data);
        return $this->list((int) $study['id_routing_step'], $context);
    }

    /** Remplace la norme de l'étape (temps par pièce ou par passage) par la moyenne proposée. */
    public function applyStandard(int $stepId, array $input, ApiContext $context): array
    {
        $step = $this->loadStep($stepId);
        $this->requireInGroup($context, 'prod.products.write', $step);
        $v = (new Validator($input))->only(['component'])->choice('component', self::COMPONENTS);
        $v->throwIfInvalid();
        $component = $v->value('component');
        $proposal = $this->proposal($step, $component);
        if (!$proposal['ready']) {
            throw new ApiException('NOT_ENOUGH_MEASUREMENTS', 409, [], ['count' => $proposal['count'], 'min' => $proposal['min_measurements']]);
        }
        $column = $component === 'variable' ? 'variable_time_s' : 'fixed_time_s';
        $value = $component === 'variable' ? round($proposal['proposed_s'], 3) : (int) round($proposal['proposed_s']);
        $this->tx->execute(function () use ($stepId, $column, $value, $step, $proposal, $context) {
            $this->items->updateStepTimes($stepId, [$column => $value]);
            $this->studies->markApplied($proposal['measurement_ids']);
            $this->audit->record($context, 'apply_standard', 'routing_step', $stepId, [$column => $step[$column]], [$column => $value, 'measurements' => $proposal['measurement_ids']]);
        });
        return $this->list($stepId, $context);
    }

    private function proposal(array $step, string $component): array
    {
        $n = max(1, (int) $this->params->get('timing.moving_average_n', '5'));
        $min = max(1, (int) $this->params->get('timing.min_measurements', '3'));
        $alertPct = (float) $this->params->get('timing.deviation_alert_pct', '15');
        $rows = $this->studies->latest((int) $step['id'], $component, $n);
        $average = StandardTime::movingAverage(array_map(fn($r) => (float) $r['unit_time_s'], $rows), $n);
        $current = (float) ($component === 'variable' ? $step['variable_time_s'] : $step['fixed_time_s']);
        $deviation = $average !== null && $current > 0 ? ($average - $current) / $current * 100 : null;
        return [
            'component' => $component,
            'current_s' => $current,
            'proposed_s' => $average === null ? null : round($average, 4),
            'count' => count($rows),
            'window' => $n,
            'min_measurements' => $min,
            'ready' => $average !== null && count($rows) >= $min,
            'deviation_pct' => $deviation === null ? null : round($deviation, 1),
            'alert' => $deviation !== null && abs($deviation) > $alertPct,
            'measurement_ids' => array_map(fn($r) => (int) $r['id'], $rows),
        ];
    }

    private function present(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'component' => $m['component'],
            'workstation_id' => $m['id_workstation'] !== null ? (int) $m['id_workstation'] : null,
            'quantity' => (float) $m['quantity'],
            'passes' => (int) $m['passes'],
            'duration_s' => (int) $m['duration_s'],
            'unit_time_s' => (float) $m['unit_time_s'],
            'measured_at' => $m['measured_at'],
            'production_employee_id' => $m['id_production_employee'] !== null ? (int) $m['id_production_employee'] : null,
            'employee' => $m['employee_name'] !== null ? trim($m['employee_name'] . ' ' . $m['employee_surname']) : null,
            'measured_by' => $m['measured_by_name'],
            'note' => $m['note'],
            'excluded' => (bool) $m['excluded'],
            'applied_at' => $m['applied_at'],
        ];
    }

    private function presentStep(array $step, ApiContext $context): array
    {
        $lang = $context->translator->lang();
        return [
            'id' => (int) $step['id'],
            'item_id' => (int) $step['id_item'],
            'step_no' => (int) $step['step_no'],
            'name' => $step['name_' . $lang],
            'workstation_id' => (int) $step['id_workstation'],
            'fixed_time_s' => (int) $step['fixed_time_s'],
            'variable_time_s' => (float) $step['variable_time_s'],
            'capacity_per_pass' => (float) $step['capacity_per_pass'],
        ];
    }

    private function requireInGroup(ApiContext $context, string $permission, array $step): void
    {
        $ws = $this->items->workstations([(int) $step['id_workstation']])[(int) $step['id_workstation']] ?? null;
        $groupId = $ws ? (int) $ws['id_group'] : null;
        if (!$context->canInGroup($permission, $groupId)) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => $permission]);
        }
    }

    private function loadStep(int $id): array
    {
        return $this->items->step($id) ?? throw ApiException::notFound('routing_step', $id);
    }
}
