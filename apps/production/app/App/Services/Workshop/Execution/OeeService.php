<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\OeeRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * TRS (OEE) uproszczony : zespół zapisuje na tablecie stan maszyny — praca, postój z powodem, koniec pracy.
 *
 * Dostępność = praca ÷ (praca + postoje nieplanowane) ; przerwa i mycie to postoje planowane (poza czasem otwarcia).
 * Wydajność = czas normatywny tego, co zrobiono na maszynie (etapy marszruty na tej maszynie, także cobot jako
 * alternatywa, × partie z tabletu grupy) ÷ czas pracy (najwyżej 100 % ; surowa wartość pokazana osobno).
 * Jakość = (zrobione − straty) ÷ zrobione dla tych kart. TRS = dostępność × wydajność × jakość.
 */
final class OeeService
{
    public const STATES = ['run', 'stop', 'off'];
    public const STOP_REASONS = ['breakdown', 'setup', 'waiting', 'cleaning', 'break', 'other'];
    public const PLANNED_STOPS = ['cleaning', 'break'];

    public function __construct(
        private OeeRepository $repo,
        private PlanningRepository $planning,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /** Tablet : maszyny grupy tabletu, stan bieżący, przebieg dnia, TRS dnia. */
    public function day(ApiContext $context, ?string $date = null, ?int $now = null): array
    {
        $now ??= time();
        $groupId = $this->groupId($context);
        $day = $date !== null && $date !== '' ? $date : date('Y-m-d', $now);
        $machines = $this->compute($this->repo->machines($groupId), $day, $day, $now, $context->translator->lang() === 'fr', true);
        return ['date' => $day, 'today' => $day === date('Y-m-d', $now), 'machines' => $machines,
            'stop_reasons' => self::STOP_REASONS, 'planned_stops' => self::PLANNED_STOPS];
    }

    /** Zmiana stanu maszyny : zamyka bieżący stan i otwiera nowy (praca / postój) ; « off » = koniec pracy. */
    public function setState(int $wsId, array $body, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['state', 'reason', 'note', 'client_uuid'])
            ->choice('state', self::STATES)->choice('reason', self::STOP_REASONS, false, true)->string('note', 255, false, true)
            ->string('client_uuid', 36, false, true, '/^[0-9a-fA-F-]{36}$/', 'INVALID_UUID');
        $v->throwIfInvalid();
        $c = $v->clean();
        if (!empty($c['client_uuid']) && $this->repo->byUuid($c['client_uuid']) !== null) return $this->day($context, null, $now) + ['replayed' => true];
        $machine = $this->repo->machine($wsId) ?? throw ApiException::notFound('workstation', $wsId);
        if ((int) $machine['id_group'] !== $groupId) throw new ApiException('MACHINE_NOT_IN_YOUR_GROUP', 403);
        $state = $c['state'];
        $reason = $state === 'stop' ? ($c['reason'] ?? null) : null;
        $note = isset($c['note']) && trim((string) $c['note']) !== '' ? trim((string) $c['note']) : null;
        if ($state === 'stop' && $reason === null) throw ApiException::validation([['field' => 'reason', 'code' => 'STOP_REASON_REQUIRED']]);
        if ($reason === 'other' && $note === null) throw ApiException::validation([['field' => 'note', 'code' => 'STOP_NOTE_REQUIRED']]);
        $open = $this->repo->open($wsId);
        // Ten sam stan (i powód) : nic do zmiany.
        $same = $open !== null ? ($open['state'] === $state && ($open['reason'] ?? null) === $reason) : $state === 'off';
        if (!$same) {
            $at = date('Y-m-d H:i:s', $now);
            $this->tx->execute(function () use ($wsId, $state, $reason, $note, $at, $open, $c, $context) {
                $this->repo->close($wsId, $at);
                $id = null;
                if ($state !== 'off') {
                    $id = $this->repo->start(['id_workstation' => $wsId, 'state' => $state, 'reason' => $reason, 'note' => $note, 'started_at' => $at,
                        'id_employee' => $context->employeeId, 'id_device' => $context->deviceId, 'client_uuid' => $c['client_uuid'] ?? null]);
                }
                $this->audit->record($context, 'state', 'workstation', $wsId, $open ? ['state' => $open['state'], 'reason' => $open['reason']] : null,
                    ['state' => $state, 'reason' => $reason, 'state_id' => $id]);
            });
        }
        return $this->day($context, null, $now);
    }

    /** Biuro (śledzenie produkcji, protokół zebrania) : TRS maszyn w okresie dni. */
    public function summary(string $fromDay, string $toDay, ?int $now = null): array
    {
        return $this->compute($this->repo->machines(), $fromDay, $toDay, $now ?? time(), false, false);
    }

    /**
     * TRS z czasów i ilości (obliczenie czyste). Czasy w sekundach.
     * @return array{availability:?float, performance:?float, performance_raw:?float, quality:?float, oee:?float}
     */
    public static function figures(float $runS, float $unplannedS, float $idealS, float $pieces, float $waste): array
    {
        $availability = $runS + $unplannedS > 0 ? $runS / ($runS + $unplannedS) : null;
        $raw = $runS > 0 && $idealS > 0 ? $idealS / $runS : null;
        $performance = $raw !== null ? min(1.0, $raw) : null;
        $quality = $pieces > 0 ? max(0.0, $pieces - $waste) / $pieces : null;
        $oee = $availability !== null && $performance !== null && $quality !== null ? $availability * $performance * $quality : null;
        $pct = fn(?float $x) => $x === null ? null : round($x * 100, 1);
        return ['availability' => $pct($availability), 'performance' => $pct($performance), 'performance_raw' => $pct($raw), 'quality' => $pct($quality), 'oee' => $pct($oee)];
    }

    // ---------------------------------------------------------------------------------------------

    private function compute(array $machines, string $fromDay, string $toDay, int $now, bool $fr, bool $withSegments): array
    {
        if (!$machines) return [];
        $from = $fromDay . ' 00:00:00';
        $to = date('Y-m-d 00:00:00', strtotime($toDay . ' +1 day'));
        $limit = min(strtotime($to), $now);
        $states = [];
        foreach ($this->repo->states(array_map(fn($m) => (int) $m['id'], $machines), $from, $to) as $s) $states[(int) $s['id_workstation']][] = $s;
        $groups = array_values(array_unique(array_map(fn($m) => (int) $m['id_group'], $machines)));
        $produced = [];
        foreach ($this->repo->produced($groups, $fromDay, $toDay) as $r) $produced[(int) $r['id_group']][(int) $r['id_item']] = (float) $r['q'];
        $waste = [];
        foreach ($this->repo->waste($groups, $fromDay, $toDay) as $r) $waste[(int) $r['id_group']][(int) $r['id_item']] = (float) $r['q'];
        $steps = $this->stepsByMachine();
        $itemIds = [];
        foreach ($produced as $byItem) $itemIds = array_merge($itemIds, array_keys($byItem));
        $names = $this->repo->itemNames(array_unique($itemIds));
        $out = [];
        foreach ($machines as $m) {
            $id = (int) $m['id'];
            $g = (int) $m['id_group'];
            $run = 0.0;
            $stops = [];
            $segments = [];
            $current = null;
            foreach ($states[$id] ?? [] as $s) {
                $start = max(strtotime($s['started_at']), strtotime($from));
                $end = min($s['ended_at'] !== null ? strtotime($s['ended_at']) : $now, $limit);
                if ($s['ended_at'] === null) $current = $s;
                if ($end <= $start) continue;
                $len = (float) ($end - $start);
                if ($s['state'] === 'run') $run += $len;
                else $stops[$s['reason'] ?? 'other'] = ($stops[$s['reason'] ?? 'other'] ?? 0.0) + $len;
                if ($withSegments) $segments[] = ['state' => $s['state'], 'reason' => $s['reason'], 'note' => $s['note'], 'start' => date('Y-m-d H:i:s', $start),
                    'end' => $s['ended_at'] !== null && strtotime($s['ended_at']) <= $limit ? date('Y-m-d H:i:s', $end) : null, 'minutes' => round($len / 60, 1),
                    'employee' => $s['employee_name'] ?: null];
            }
            $planned = array_sum(array_intersect_key($stops, array_flip(self::PLANNED_STOPS)));
            $unplanned = array_sum($stops) - $planned;
            $ideal = 0.0;
            $pieces = 0.0;
            $lost = 0.0;
            $items = [];
            foreach ($steps[$id] ?? [] as [$itemId, $step]) {
                $q = $produced[$g][$itemId] ?? 0.0;
                if ($q <= 0) continue;
                $ideal += StandardTime::step($step, $q)['work_s'];
                $pieces += $q;
                $lost += min($q, $waste[$g][$itemId] ?? 0.0);
                $items[$itemId] = ['item_id' => $itemId, 'name' => $names[$itemId] ?? ('#' . $itemId), 'quantity' => round($q, 3)];
            }
            $reasons = [];
            foreach (self::STOP_REASONS as $r) if (($stops[$r] ?? 0) > 0) $reasons[] = ['reason' => $r, 'planned' => in_array($r, self::PLANNED_STOPS, true), 'minutes' => round($stops[$r] / 60, 1)];
            $row = [
                'id' => $id, 'code' => $m['code'], 'name' => $fr && $m['name_fr'] ? $m['name_fr'] : $m['name_pl'], 'group_id' => $g, 'group' => $m['group_name'],
                'type' => $m['type'], 'collaborative' => $m['type'] === 'robot' && $m['robot_mode'] === 'collaboratif',
                'state' => $current ? ['state' => $current['state'], 'reason' => $current['reason'], 'note' => $current['note'], 'since' => $current['started_at'],
                    'stale' => substr((string) $current['started_at'], 0, 10) < date('Y-m-d', $now)] : null,
                'run_h' => round($run / 3600, 2), 'stop_h' => round($unplanned / 3600, 2), 'planned_stop_h' => round($planned / 3600, 2), 'stops' => $reasons,
                'ideal_h' => round($ideal / 3600, 2), 'pieces' => round($pieces, 3), 'waste' => round($lost, 3), 'items' => array_values($items),
                'has_routing' => !empty($steps[$id]),
            ] + self::figures($run, $unplanned, $ideal, $pieces, $lost);
            if ($withSegments) $row['segments'] = $segments;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Etapy marszrut na maszynie : stanowisko główne etapu, a dla cobota — etap przeniesiony na cobota (kadencja cobota).
     * @return array<int, list<array{0:int, 1:array}>> maszyna => [karta, etap]
     */
    private function stepsByMachine(): array
    {
        $out = [];
        foreach ($this->planning->routings() as $itemId => $steps) {
            foreach ($steps as $s) {
                $ws = isset($s['cobot']) ? (int) $s['cobot']['workstation_id'] : (int) $s['id_workstation'];
                $out[$ws][] = [(int) $itemId, $s];
            }
        }
        return $out;
    }

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }
}
