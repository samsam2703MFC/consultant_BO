<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SchedulingRepository;
use App\Services\Workshop\Scheduling\SchedulingService;

/**
 * Wskaźniki produkcji (P7) z tego, co tablety zarejestrowały, i podgląd dnia (kokpit).
 *
 *   OTD           zlecenia MTO skończone przed terminem (wyjazd ciężarówki) ÷ zlecenia z terminem w okresie
 *   punktualność  operacje rozpoczęte w ± kpi.start_tolerance_min (30) od planu ÷ operacje rozpoczęte
 *   wydajność     godziny normatywne operacji zakończonych ÷ godziny faktycznie na nich przepracowane
 *   wykorzystanie godziny przepracowane na operacjach ÷ godziny obecności (wejście–wyjście)
 *   obecność      godziny obecności ÷ godziny planowane (obsada × zmiana)
 *   braki         ilość odrzucona ÷ (dobra + odrzucona)
 */
final class KpiService
{
    public function __construct(
        private ExecutionRepository $repo,
        private SchedulingRepository $scheduling,
        private SchedulingService $schedulingService,
        private ParamRepository $params,
    ) {
    }

    public function kpis(string $from, string $to, ?int $groupId, ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $v = (new Validator(['from' => $from, 'to' => $to]))->date('from', true, false)->date('to', true, false);
        $v->throwIfInvalid();
        if ($to < $from) throw ApiException::validation([['field' => 'to', 'code' => 'DATE_ORDER', 'params' => []]]);
        $start = $from . ' 00:00:00';
        $end = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
        $tolerance = max(0, (int) $this->params->get('kpi.start_tolerance_min', '30')) * 60;

        $ops = $this->repo->operationsWithLabour($start, $end);
        if ($groupId !== null) $ops = array_values(array_filter($ops, fn($o) => (int) $o['id_group'] === $groupId));
        $clocks = $this->repo->clocks($start, $end);
        if ($groupId !== null) $clocks = array_values(array_filter($clocks, fn($k) => (int) $k['id_primary_group'] === $groupId));
        try {
            $load = $this->schedulingService->load($from, $to, $context);
        } catch (ApiException) {
            $load = []; // période plus longue que planning.max_period_days : pas d'heures planifiées
        }
        if ($groupId !== null) $load = array_values(array_filter($load, fn($l) => $l['group_id'] === $groupId));

        $byGroup = [];
        foreach ($this->scheduling->groups() as $g) {
            if ($groupId !== null && (int) $g['id'] !== $groupId) continue;
            $gid = (int) $g['id'];
            $byGroup[$gid] = ['group_id' => $gid, 'group' => $g['name_' . $context->translator->lang()], 'color' => $g['color']]
                + $this->compute(array_filter($ops, fn($o) => (int) $o['id_group'] === $gid), array_filter($clocks, fn($k) => (int) $k['id_primary_group'] === $gid),
                    array_filter($load, fn($l) => $l['group_id'] === $gid), strtotime($start), strtotime($end), $now, $tolerance);
        }
        $orders = $this->repo->dueOrders($start, $end);
        $mto = array_filter($orders, fn($o) => $o['origin'] === 'MTO');
        $onTime = 0;
        $late = 0;
        foreach ($mto as $o) {
            $finished = (int) $o['open_ops'] === 0 && (int) $o['ops'] > 0;
            if ($finished && $o['finished_at'] !== null && $o['finished_at'] <= $o['due_at']) $onTime++;
            elseif (($finished && $o['finished_at'] > $o['due_at']) || (!$finished && strtotime($o['due_at']) < $now)) $late++;
        }
        return [
            'from' => $from, 'to' => $to,
            'totals' => $this->compute($ops, $clocks, $load, strtotime($start), strtotime($end), $now, $tolerance) + [
                'mto_orders' => count($mto), 'mto_on_time' => $onTime, 'mto_late' => $late,
                'otd_pct' => $onTime + $late > 0 ? round($onTime / ($onTime + $late) * 100, 1) : null,
            ],
            'groups' => array_values($byGroup),
        ];
    }

    /** Podgląd dnia : kto jest, co trwa, co się spóźnia, zlecenia zagrożone, wskaźniki tygodnia. */
    public function cockpit(ApiContext $context, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $monday = date('Y-m-d', strtotime('monday this week', $now));
        $lang = $context->translator->lang();
        $groups = [];
        foreach ($this->scheduling->groups() as $g) $groups[(int) $g['id']] = $g['name_' . $lang];
        $present = $this->repo->clocks($today . ' 00:00:00', date('Y-m-d H:i:s', $now + 1));
        $presentNow = array_values(array_filter($present, fn($k) => $k['clock_out'] === null));
        $maxShift = max(1, (int) $this->params->get('kiosk.max_shift_hours', '16')) * 3600;
        $ops = $this->scheduling->operations($today . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($today . ' +1 day')), null);
        $late = array_values(array_filter($ops, fn($o) => !in_array($o['status'], ['done', 'cancelled'], true) && strtotime($o['planned_end']) < $now));
        $orders = $this->repo->dueOrders($today . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($today . ' +1 day')));
        $atRisk = array_values(array_filter($orders, fn($o) => $o['origin'] === 'MTO' && ((int) $o['open_ops'] > 0 || (int) $o['ops'] === 0) && strtotime($o['due_at']) - $now < 3 * 3600));
        $alerts = [];
        foreach ($this->repo->clocks(date('Y-m-d', $now - 3 * 86400) . ' 00:00:00', date('Y-m-d H:i:s', $now + 1)) as $k) {
            if ($k['clock_out'] === null && $now - strtotime($k['clock_in']) > $maxShift) $alerts[] = ['code' => 'CLOCK_OPEN_TOO_LONG', 'name' => $k['name'], 'since' => $k['clock_in']];
        }
        foreach ($atRisk as $o) $alerts[] = ['code' => 'MTO_AT_RISK', 'order' => $o['code'], 'due_at' => $o['due_at']];
        if ($late) $alerts[] = ['code' => 'OPERATIONS_LATE', 'count' => count($late)];
        $byStatus = array_count_values(array_map(fn($o) => $o['status'], $ops));
        $people = [];
        foreach ($presentNow as $k) $people[$groups[(int) $k['id_primary_group']] ?? '—'][] = $k['name'];
        return [
            'date' => $today,
            'people_present' => count($presentNow),
            'people_by_group' => array_map(fn($names, $group) => ['group' => $group, 'count' => count($names), 'names' => $names], $people, array_keys($people)),
            'operations' => ['total' => count($ops), 'planned' => $byStatus['planned'] ?? 0, 'in_progress' => $byStatus['in_progress'] ?? 0,
                'paused' => $byStatus['paused'] ?? 0, 'done' => $byStatus['done'] ?? 0, 'late' => count($late)],
            'mto_due_today' => count(array_filter($orders, fn($o) => $o['origin'] === 'MTO')),
            'mto_at_risk' => array_map(fn($o) => ['code' => $o['code'], 'due_at' => $o['due_at'], 'open_operations' => (int) $o['open_ops']], $atRisk),
            'week' => $this->kpis($monday, $today, null, $context, $now)['totals'],
            'alerts' => $alerts,
        ];
    }

    private function compute(array $ops, array $clocks, array $load, int $from, int $to, int $now, int $tolerance): array
    {
        $started = array_filter($ops, fn($o) => $o['actual_start'] !== null);
        $punctual = array_filter($started, fn($o) => abs(strtotime($o['actual_start']) - strtotime($o['planned_start'])) <= $tolerance);
        $done = array_filter($ops, fn($o) => $o['status'] === 'done' && $o['kind'] === 'work');
        $standard = 0.0;
        $actual = 0.0;
        foreach ($done as $o) {
            $standard += (strtotime($o['planned_end']) - strtotime($o['planned_start'])) / 3600 * (float) ($o['operators_required'] ?? 1);
            $actual += (float) $o['labour_s'] / 3600;
        }
        $labour = array_sum(array_map(fn($o) => (float) $o['labour_s'], $ops)) / 3600;
        $present = 0.0;
        foreach ($clocks as $k) {
            $in = max($from, strtotime($k['clock_in']));
            $out = min($to, $k['clock_out'] !== null ? strtotime($k['clock_out']) : $now);
            $present += max(0, $out - $in) / 3600;
        }
        $planned = array_sum(array_column($load, 'available_h'));
        $good = array_sum(array_map(fn($o) => (float) $o['qty_good'], $ops));
        $scrap = array_sum(array_map(fn($o) => (float) $o['qty_scrap'], $ops));
        $pct = fn(float $a, float $b) => $b > 0 ? round($a / $b * 100, 1) : null;
        return [
            'operations' => count($ops), 'operations_done' => count(array_filter($ops, fn($o) => $o['status'] === 'done')),
            'started' => count($started), 'started_on_time' => count($punctual), 'punctuality_pct' => $pct((float) count($punctual), (float) count($started)),
            'standard_h' => round($standard, 2), 'worked_on_done_h' => round($actual, 2), 'efficiency_pct' => $pct($standard, $actual),
            'labour_h' => round($labour, 2), 'present_h' => round($present, 2), 'utilisation_pct' => $pct($labour, $present),
            'planned_h' => round($planned, 2), 'attendance_pct' => $pct($present, $planned),
            'qty_good' => $good, 'qty_scrap' => $scrap, 'scrap_pct' => $pct($scrap, $good + $scrap),
        ];
    }
}
