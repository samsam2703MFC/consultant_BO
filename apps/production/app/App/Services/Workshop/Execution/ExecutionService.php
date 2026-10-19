<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SchedulingRepository;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Referentiel\AuditTrail;
use App\Services\Workshop\Scheduling\SchedulingService;

/**
 * P7 — ce que fait une personne identifiée sur la tablette.
 *
 * Pointage en un geste : entrée / sortie (sens déduit si absent) ; une sortie ferme ses temps
 * d'opération ouverts ; client_uuid rend l'appel rejouable (tablette hors ligne puis resynchronisée).
 * Opérations de ses groupes (et du groupe de la tablette) : démarrer (il faut être pointé), pause,
 * terminer avec quantités bonne / rebut ; à la fin d'une opération de travail le temps réel devient une
 * mesure de chronométrage (P2) de l'étape. Signature manuscrite : PNG, condensé SHA-256 conservé.
 */
final class ExecutionService
{
    private const MAX_SIGNATURE_BYTES = 300000;

    public function __construct(
        private ExecutionRepository $repo,
        private SchedulingRepository $scheduling,
        private SchedulingService $schedulingService,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
        private SignatureStorage $storage,
    ) {
    }

    public function me(ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $employee = $this->repo->employee((int) $context->employeeId);
        $open = $this->repo->openClock((int) $context->employeeId);
        $signOnOut = $this->params->get('kiosk.signature_on_clock_out', '1') === '1';
        return [
            'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name']],
            'clocked_in' => $open !== null,
            'clock' => $open ? $this->clockRow($open, $now) : null,
            'signature_on_clock_out' => $signOnOut,
            'idle_logout_s' => max(15, (int) $this->params->get('kiosk.idle_logout_s', '45')),
            'operations' => $this->operations($context, $now),
            'working_on' => array_map(fn($l) => (int) $l['id_operation'], $this->repo->openLogsOfEmployee((int) $context->employeeId)),
        ];
    }

    /** Opérations du jour et des 24 h à venir (et en cours) des groupes de la personne et de la tablette. */
    public function operations(ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $groups = $this->groups($context);
        // Du début de la journée (ou des 6 dernières heures, pour la nuit) aux 24 prochaines heures.
        $from = date('Y-m-d 00:00:00', $now - 6 * 3600);
        $to = date('Y-m-d H:i:s', $now + 86400);
        $lang = $context->translator->lang();
        $rows = array_filter($this->scheduling->operations($from, $to, null), fn($o) => in_array((int) $o['id_group'], $groups, true) && $o['status'] !== 'done' || ($o['status'] === 'done' && strtotime((string) $o['actual_end']) > $now - 3600 && in_array((int) $o['id_group'], $groups, true)));
        return array_values(array_map(fn($o) => $this->schedulingService->format($o, $lang) + [
            'actual_start' => $o['actual_start'], 'actual_end' => $o['actual_end'], 'qty_good' => $o['qty_good'] !== null ? (float) $o['qty_good'] : null,
            'late' => $o['status'] !== 'done' && strtotime($o['planned_end']) < $now,
        ], $rows));
    }

    public function clock(array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator($body))->only(['direction', 'client_uuid'])->choice('direction', ['in', 'out'], false)
            ->string('client_uuid', 36, false, true, '/^[0-9a-fA-F-]{36}$/', 'INVALID_UUID');
        $v->throwIfInvalid();
        $c = $v->clean();
        $employeeId = (int) $context->employeeId;
        $uuid = $c['client_uuid'] ?? null;
        if ($uuid !== null && ($done = $this->repo->clockByUuid($uuid)) !== null) {
            return ['replayed' => true, 'clock' => $this->clockRow($done, $now)];
        }
        $open = $this->repo->openClock($employeeId);
        $direction = $c['direction'] ?? ($open ? 'out' : 'in');
        $at = date('Y-m-d H:i:s', $now);
        if ($direction === 'in') {
            if ($open) throw new ApiException('CLOCK_ALREADY_IN', 409, [], ['since' => substr($open['clock_in'], 11, 5)]);
            $id = $this->tx->execute(function () use ($employeeId, $at, $context, $uuid) {
                $id = $this->repo->clockIn($employeeId, $at, $context->deviceId, $uuid);
                $this->audit->record($context, 'clock_in', 'clock', $id, null, ['employee_id' => $employeeId, 'device_id' => $context->deviceId]);
                return $id;
            });
        } else {
            if (!$open) throw new ApiException('CLOCK_NOT_IN', 409);
            $id = (int) $open['id'];
            $this->tx->execute(function () use ($id, $at, $employeeId, $context, $uuid) {
                $this->repo->clockOut($id, $at, $uuid);
                foreach ($this->repo->openLogsOfEmployee($employeeId) as $log) {
                    $this->repo->endLog((int) $log['id'], $at);
                    if (!$this->repo->openLogs((int) $log['id_operation'])) $this->repo->setOperation((int) $log['id_operation'], ['status' => 'paused']);
                }
                $this->audit->record($context, 'clock_out', 'clock', $id, null, ['employee_id' => $employeeId, 'device_id' => $context->deviceId]);
            });
        }
        $row = $this->clockRow($this->repo->clock($id), $now);
        return ['replayed' => false, 'clock' => $row,
            'signature_required' => $direction === 'out' && $this->params->get('kiosk.signature_on_clock_out', '1') === '1'];
    }

    public function start(int $operationId, array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $op = $this->operation($operationId, $context);
        if (!in_array($op['status'], ['planned', 'paused', 'in_progress'], true)) throw new ApiException('OPERATION_NOT_STARTABLE', 409, [], ['status' => $op['status']]);
        if ($this->repo->openClock((int) $context->employeeId) === null) throw new ApiException('NOT_CLOCKED_IN', 409);
        $at = date('Y-m-d H:i:s', $now);
        $uuid = isset($body['client_uuid']) && preg_match('/^[0-9a-fA-F-]{36}$/', (string) $body['client_uuid']) ? (string) $body['client_uuid'] : null;
        $this->tx->execute(function () use ($op, $at, $context, $uuid) {
            $mine = array_filter($this->repo->openLogs((int) $op['id']), fn($l) => (int) $l['id_employee'] === (int) $context->employeeId);
            if (!$mine) $this->repo->startLog((int) $op['id'], (int) $context->employeeId, $at, $uuid);
            $this->repo->setOperation((int) $op['id'], ['status' => 'in_progress'] + ($op['actual_start'] === null ? ['actual_start' => $at] : []));
            $this->audit->record($context, 'start', 'operation', (int) $op['id'], ['status' => $op['status']], ['status' => 'in_progress', 'employee_id' => $context->employeeId]);
        });
        return $this->result($operationId, $context);
    }

    public function pause(int $operationId, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $op = $this->operation($operationId, $context);
        if ($op['status'] !== 'in_progress') throw new ApiException('OPERATION_NOT_IN_PROGRESS', 409, [], ['status' => $op['status']]);
        $at = date('Y-m-d H:i:s', $now);
        $this->tx->execute(function () use ($op, $at, $context) {
            foreach ($this->repo->openLogs((int) $op['id']) as $log) if ((int) $log['id_employee'] === (int) $context->employeeId) $this->repo->endLog((int) $log['id'], $at);
            if (!$this->repo->openLogs((int) $op['id'])) $this->repo->setOperation((int) $op['id'], ['status' => 'paused']);
            $this->audit->record($context, 'pause', 'operation', (int) $op['id'], null, ['employee_id' => $context->employeeId]);
        });
        return $this->result($operationId, $context);
    }

    public function finish(int $operationId, array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $op = $this->operation($operationId, $context);
        if (!in_array($op['status'], ['in_progress', 'paused'], true)) throw new ApiException('OPERATION_NOT_IN_PROGRESS', 409, [], ['status' => $op['status']]);
        $v = (new Validator($body))->only(['qty_good', 'qty_scrap', 'scrap_reason'])
            ->decimal('qty_good', true, 0, 1000000)->decimal('qty_scrap', false, 0, 1000000)->string('scrap_reason', 255, false, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        if (($c['qty_scrap'] ?? 0) > 0 && trim((string) ($c['scrap_reason'] ?? '')) === '') {
            throw ApiException::validation([['field' => 'scrap_reason', 'code' => 'REQUIRED', 'params' => []]]);
        }
        $at = date('Y-m-d H:i:s', $now);
        $this->tx->execute(function () use ($op, $c, $at, $context) {
            foreach ($this->repo->openLogs((int) $op['id']) as $log) $this->repo->endLog((int) $log['id'], $at);
            $this->repo->setOperation((int) $op['id'], ['status' => 'done', 'actual_end' => $at, 'actual_start' => $op['actual_start'] ?? $at,
                'qty_good' => $c['qty_good'], 'qty_scrap' => $c['qty_scrap'] ?? 0, 'scrap_reason' => $c['scrap_reason'] ?? null]);
            $this->timeStudy($op, (float) $c['qty_good'] + (float) ($c['qty_scrap'] ?? 0), $at, $context);
            $this->audit->record($context, 'finish', 'operation', (int) $op['id'], ['status' => $op['status']],
                ['status' => 'done', 'qty_good' => $c['qty_good'], 'qty_scrap' => $c['qty_scrap'] ?? 0, 'employee_id' => $context->employeeId]);
        });
        return $this->result($operationId, $context);
    }

    public function sign(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['purpose', 'ref', 'image'])
            ->choice('purpose', ['clock_out', 'timesheet_month', 'operation'], true)->string('ref', 64, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $employeeId = (int) $context->employeeId;
        $refOk = match ($c['purpose']) {
            'clock_out' => ($clock = $this->repo->clock((int) $c['ref'])) !== null && (int) $clock['id_employee'] === $employeeId,
            'timesheet_month' => (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $c['ref']),
            'operation' => $this->scheduling->operation((int) $c['ref']) !== null,
        };
        if (!$refOk) throw ApiException::validation([['field' => 'ref', 'code' => 'SIGNATURE_REF_INVALID', 'params' => []]]);
        $png = $this->decodePng($body['image'] ?? null);
        $hash = hash('sha256', $png);
        $stored = $this->storage->store($employeeId, $png, $hash);
        $id = $this->repo->insertSignature(['id_employee' => $employeeId, 'purpose' => $c['purpose'], 'ref' => $c['ref'], 'id_device' => $context->deviceId, 'sha256' => $hash] + $stored);
        $this->audit->record($context, 'sign', 'signature', $id, null, ['employee_id' => $employeeId, 'purpose' => $c['purpose'], 'ref' => $c['ref'], 'sha256' => $hash]);
        return ['id' => $id, 'purpose' => $c['purpose'], 'ref' => $c['ref'], 'sha256' => $hash];
    }

    private function decodePng(mixed $image): string
    {
        $error = fn(string $code) => ApiException::validation([['field' => 'image', 'code' => $code, 'params' => ['max_kb' => intdiv(self::MAX_SIGNATURE_BYTES, 1000)]]]);
        if (!is_string($image) || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $image, $m)) throw $error('INVALID_IMAGE');
        if (strlen($m[1]) > self::MAX_SIGNATURE_BYTES * 4 / 3 + 4) throw $error('IMAGE_TOO_LARGE');
        $png = base64_decode($m[1], true);
        if ($png === false || !str_starts_with($png, "\x89PNG\r\n\x1a\n") || strlen($png) < 100) throw $error('INVALID_IMAGE');
        if (strlen($png) > self::MAX_SIGNATURE_BYTES) throw $error('IMAGE_TOO_LARGE');
        return $png;
    }

    /** Temps réel d'une opération de travail → mesure de chronométrage de l'étape (temps par pièce). */
    private function timeStudy(array $op, float $quantity, string $at, ApiContext $context): void
    {
        if ($op['kind'] !== 'work' || $op['id_routing_step'] === null || $quantity <= 0) return;
        $step = $this->repo->routingStep((int) $op['id_routing_step']);
        if ($step === null) return;
        // Temps écoulé travaillé : union des intervalles de travail des personnes.
        $intervals = array_map(fn($l) => [strtotime($l['started_at']), strtotime((string) $l['ended_at'])], array_filter($this->repo->logs((int) $op['id']), fn($l) => $l['ended_at'] !== null));
        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
        $duration = 0;
        $cursor = null;
        foreach ($intervals as [$a, $b]) {
            if ($cursor === null || $a > $cursor[1]) { if ($cursor) $duration += $cursor[1] - $cursor[0]; $cursor = [$a, $b]; }
            else $cursor[1] = max($cursor[1], $b);
        }
        if ($cursor) $duration += $cursor[1] - $cursor[0];
        if ($duration <= 0) return;
        $passes = StandardTime::passes($quantity, max(0.0001, (float) $step['capacity_per_pass']));
        $component = StandardTime::defaultComponent($step);
        $this->repo->insertTimeStudy([
            'id_routing_step' => (int) $step['id'], 'id_workstation' => (int) $op['id_workstation'] !== (int) $step['id_workstation'] ? (int) $op['id_workstation'] : null,
            'component' => $component, 'quantity' => $quantity, 'passes' => $passes, 'duration_s' => $duration,
            'unit_time_s' => round(StandardTime::unitTime($component, $step, $quantity, $passes, (float) $duration), 4),
            'measured_at' => $at, 'id_employee' => $context->employeeId, 'note' => 'tablet · op ' . $op['id'],
        ]);
    }

    private function operation(int $id, ApiContext $context): array
    {
        $op = $this->scheduling->operation($id) ?? throw ApiException::notFound('operation', $id);
        if (!in_array((int) $op['id_group'], $this->groups($context), true)) throw new ApiException('OPERATION_NOT_IN_YOUR_GROUPS', 403);
        return $op;
    }

    private function result(int $id, ApiContext $context): array
    {
        $o = $this->scheduling->operation($id);
        return $this->schedulingService->format($o, $context->translator->lang()) + [
            'actual_start' => $o['actual_start'], 'actual_end' => $o['actual_end'],
            'qty_good' => $o['qty_good'] !== null ? (float) $o['qty_good'] : null, 'qty_scrap' => $o['qty_scrap'] !== null ? (float) $o['qty_scrap'] : null,
            'working' => array_map(fn($l) => (int) $l['id_employee'], $this->repo->openLogs($id)),
        ];
    }

    /** @return list<int> */
    private function groups(ApiContext $context): array
    {
        $groups = $context->employeeId ? $this->repo->employeeGroups($context->employeeId) : [];
        if ($context->deviceGroupId !== null) $groups[] = $context->deviceGroupId;
        return array_values(array_unique($groups));
    }

    private function clockRow(array $k, int $now): array
    {
        $end = $k['clock_out'] !== null ? strtotime($k['clock_out']) : $now;
        return ['id' => (int) $k['id'], 'clock_in' => $k['clock_in'], 'clock_out' => $k['clock_out'], 'hours' => round(max(0, $end - strtotime($k['clock_in'])) / 3600, 2)];
    }
}
