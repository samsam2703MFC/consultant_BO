<?php
declare(strict_types=1);

namespace App\Services\Workshop\Scheduling;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\SchedulingRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * P5 — déplacer ou verrouiller une opération sur le Gantt.
 *
 * Un déplacement garde la durée et respecte les mêmes règles que l'ordonnancement :
 *   - jamais après l'échéance de l'OF (départ camion − chargement pour le MTO) ;
 *   - après l'opération précédente de l'OF, avant la suivante ; un semi-fini fini avant le début de
 *     l'OF parent, et l'OF parent pas commencé avant la fin de ses semi-finis ;
 *   - poste de l'étape ou une de ses alternatives, sur un shift où le groupe a assez de monde
 *     (robot autonome et attente en chambre : heures de disponibilité du poste) ;
 *   - poste libre (chambre : capacité cumulée non dépassée) ; jamais dans le passé.
 * Une opération déplacée est verrouillée (le réordonnancement la laisse en place), sauf locked=false.
 */
final class GanttService
{
    private const CUMULATIVE_TYPES = ['fermentation', 'froid', 'surgelation'];

    public function __construct(
        private SchedulingRepository $repo,
        private SchedulingService $scheduling,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function update(int $id, array $body, ApiContext $context, ?int $now = null): array
    {
        $op = $this->repo->operation($id) ?? throw ApiException::notFound('operation', $id);
        if (!$context->canInGroup('prod.gantt.move', (int) $op['id_group'])) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.gantt.move']);
        }
        $v = (new Validator($body))->only(['start', 'workstation_id', 'locked'])
            ->datetime('start')->integer('workstation_id', false, 1)->boolean('locked');
        $v->throwIfInvalid();
        $clean = $v->clean();
        if (!$clean) throw ApiException::validation([['field' => 'start', 'code' => 'REQUIRED', 'params' => []]]);

        $moving = array_key_exists('start', $clean) || array_key_exists('workstation_id', $clean);
        if ($moving && $op['status'] !== 'planned') {
            throw new ApiException('OPERATION_NOT_MOVABLE', 409, [], ['status' => $op['status']]);
        }
        $data = ['updated_by' => $context->userId];
        if ($moving) {
            $data += $this->move($op, $clean, $now ?? time());
            $data['locked'] = 1;
        }
        if (array_key_exists('locked', $clean)) $data['locked'] = $clean['locked'] ? 1 : 0;

        $this->tx->execute(function () use ($id, $op, $data, $context) {
            $this->repo->update($id, $data);
            $this->audit->record($context, $data === ['updated_by' => $context->userId] ? 'update' : 'move', 'operation', $id,
                ['start' => $op['planned_start'], 'workstation_id' => (int) $op['id_workstation'], 'locked' => (bool) $op['locked']],
                ['start' => $data['planned_start'] ?? $op['planned_start'], 'workstation_id' => $data['id_workstation'] ?? (int) $op['id_workstation'], 'locked' => (bool) ($data['locked'] ?? $op['locked'])]);
        });
        return $this->scheduling->format($this->repo->operation($id), $context->translator->lang());
    }

    /** Contrôles d'un déplacement ; renvoie les colonnes à écrire. */
    private function move(array $op, array $clean, int $now): array
    {
        $oldStart = strtotime($op['planned_start']);
        $duration = strtotime($op['planned_end']) - $oldStart;
        $start = isset($clean['start']) ? strtotime($clean['start']) : $oldStart;
        $end = $start + $duration;
        $wsId = $clean['workstation_id'] ?? (int) $op['id_workstation'];
        $errors = [];
        $fmt = fn(int $t) => date('Y-m-d H:i', $t);

        $workstation = $this->repo->workstation($wsId);
        if ($workstation === null) {
            throw ApiException::validation([['field' => 'workstation_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => []]]);
        }
        if ($wsId !== (int) $op['id_workstation'] && $op['id_routing_step'] !== null
            && !in_array($wsId, $this->repo->stepWorkstations((int) $op['id_routing_step']), true)) {
            // Une attente en chambre peut changer de chambre du même type.
            $current = $this->repo->workstation((int) $op['id_workstation']);
            if ($op['kind'] !== 'delay' || $current === null || $current['type'] !== $workstation['type']) {
                throw ApiException::validation([['field' => 'workstation_id', 'code' => 'NOT_STEP_WORKSTATION', 'params' => []]]);
            }
        }

        if ($start < $now - 300) $errors[] = ['field' => 'start', 'code' => 'IN_PAST', 'params' => []];
        if ($op['due_at'] !== null && $end > strtotime($op['due_at'])) {
            $errors[] = ['field' => 'start', 'code' => 'AFTER_DUE', 'params' => ['end' => $fmt($end), 'due_at' => substr($op['due_at'], 0, 16)]];
        }

        // Ordre des opérations de l'OF.
        $sequence = $this->repo->orderOperations((int) $op['id_order']);
        $index = array_search((int) $op['id'], array_map(fn($o) => (int) $o['id'], $sequence), true);
        if ($index !== false && $index > 0) {
            $previousEnd = strtotime($sequence[$index - 1]['planned_end']);
            if ($start < $previousEnd) $errors[] = ['field' => 'start', 'code' => 'BEFORE_PREVIOUS_STEP', 'params' => ['end' => $fmt($previousEnd)]];
        }
        if ($index !== false && $index < count($sequence) - 1) {
            $nextStart = strtotime($sequence[$index + 1]['planned_start']);
            if ($end > $nextStart) $errors[] = ['field' => 'start', 'code' => 'AFTER_NEXT_STEP', 'params' => ['start' => $fmt($nextStart)]];
        }
        if ($index === count($sequence) - 1 && $op['id_parent_order'] !== null) {
            $parentStart = $this->repo->parentStart((int) $op['id_parent_order']);
            if ($parentStart !== null && $end > strtotime($parentStart)) {
                $errors[] = ['field' => 'start', 'code' => 'AFTER_PARENT_START', 'params' => ['start' => substr($parentStart, 0, 16)]];
            }
        }
        if ($index === 0) {
            foreach ($this->repo->childrenEnds((int) $op['id_order']) as $child) {
                if ($start < strtotime($child['end'])) {
                    $errors[] = ['field' => 'start', 'code' => 'CHILD_NOT_READY', 'params' => ['code' => $child['code'], 'end' => substr($child['end'], 0, 16)]];
                }
            }
        }

        // Shift avec assez de monde (ou heures du poste pour un robot autonome / une attente).
        $operators = $this->scheduling->operators($op);
        $inWindow = false;
        foreach ($this->scheduling->windows($workstation, $operators, $start, $end) as [$a, $b]) {
            if ($start >= $a && $end <= $b) { $inWindow = true; break; }
        }
        if (!$inWindow) $errors[] = ['field' => 'start', 'code' => 'OUTSIDE_STAFFED_SHIFT', 'params' => ['operators' => (int) ceil($operators)]];

        // Poste libre ; chambre : capacité cumulée.
        $others = $this->repo->workstationOperations($wsId, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end), (int) $op['id']);
        if (in_array($workstation['type'], self::CUMULATIVE_TYPES, true)) {
            $passes = array_map(fn($o) => ['kind' => 'delay', 'workstation_id' => $wsId, 'start' => max($start, strtotime($o['planned_start'])), 'end' => strtotime($o['planned_end'])], $others);
            $passes[] = ['kind' => 'delay', 'workstation_id' => $wsId, 'start' => $start, 'end' => $end];
            $peak = Scheduler::peakUnits($passes, $wsId);
            $capacity = max(1.0, (float) $workstation['capacity_per_pass']);
            if ($peak > $capacity) $errors[] = ['field' => 'start', 'code' => 'CAPACITY_EXCEEDED', 'params' => ['peak' => $peak, 'capacity' => $capacity]];
        } elseif ($others) {
            $errors[] = ['field' => 'start', 'code' => 'WORKSTATION_BUSY', 'params' => ['code' => $others[0]['order_code']]];
        }
        if ($errors) throw ApiException::validation($errors);

        return [
            'planned_start' => date('Y-m-d H:i:s', $start),
            'planned_end' => date('Y-m-d H:i:s', $end),
            'id_workstation' => $wsId,
            'id_team' => $op['kind'] === 'delay' ? null : $this->scheduling->teamAt($workstation, $start),
        ];
    }
}
