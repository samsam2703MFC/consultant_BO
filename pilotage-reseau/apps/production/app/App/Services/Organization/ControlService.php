<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Exceptions\CustomException;
use App\Repositories\Organization\ControlRepository;
use App\Repositories\Organization\TaskLockRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;
use DateTimeImmutable;

/**
 * [281] Profil CEO — « Kontrola » (makieta 47) : l'administrateur (profil CEO) ou un compte CEO voit tout le monde aujourd'hui
 * (entrée QR / tablette, sortie, tâches du jour, obligatoires, remplissage = minutes des tâches faites ÷ temps de travail,
 * activité ERP), les équipes de l'atelier, la semaine par poste (tâches faites à temps) et ce qui attend sa décision :
 * demandes de déblocage, tâches en retard (Przypomnij), sorties oubliées, résultats du mois. Lecture seule : les décisions
 * passent par les écrans existants (déblocage, rappel).
 */
final class ControlService
{
    public const ABSENCE = ['urlop' => 'urlop', 'na_zadanie' => 'urlop na żądanie', 'l4' => 'L4', 'wn' => 'wolne za nadgodziny', 'inna' => 'nieobecność'];
    private const DAY_SHORT = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'So', 7 => 'Nd'];

    public function __construct(private ControlRepository $repo, private PageAccessService $access, private PositionTaskService $tasks,
        private TaskLockService $lock, private TaskLockRepository $locks, private ParamRepository $params, private ProfitabilityService $profit,
        private \App\Repositories\Organization\UserLeaveRepository $leaves)
    {
    }

    public function allowed(?int $userId): bool
    {
        return $userId !== null && ($this->access->isAdmin($userId) || $this->access->isCeo($userId));
    }

    public function overview(?int $actorId, ?DateTimeImmutable $now = null): array
    {
        if (!$this->allowed($actorId)) throw new CustomException('Kontrola : administrator albo profil CEO.', 'errors', 403);
        $now ??= new DateTimeImmutable();
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $day = $this->tasks->execution('day', $actorId, $now);
        $week = $this->tasks->execution('week', $actorId, $now);
        $clocks = [];
        foreach ($this->repo->clocks($today . ' 00:00:00', $tomorrow . ' 00:00:00') as $c) $clocks[(int) $c['id_employee']][] = $c;
        $absent = $this->repo->absences($today);
        $activity = $this->repo->activity($today . ' 00:00:00', $tomorrow . ' 00:00:00');
        $qrDevice = (int) $this->params->get('erp.qr_device_id', '0');
        $dayRows = [];
        foreach ($day['rows'] as $r) if ($r['id'] !== null) $dayRows[(int) $r['id']] = $r;

        // 1 · Wszyscy dziś : comptes ERP (sans administrateurs ni comptes CEO).
        $people = [];
        $fill = ['done' => 0, 'worked' => 0];
        foreach ($this->repo->people() as $u) {
            $uid = (int) $u['id'];
            if ($this->access->isAdmin($uid) || $this->access->isCeo($uid)) continue;
            $emp = $u['id_employee'] !== null ? (int) $u['id_employee'] : null;
            $presence = $this->presence($emp !== null ? ($clocks[$emp] ?? []) : [], $emp !== null ? ($absent[$emp] ?? null) : null, $qrDevice, $now);
            $row = $dayRows[(int) $u['position_id']] ?? null;
            $items = $row['cells'][0] ?? [];
            $doneMin = 0;
            foreach ($items as $it) if (in_array($it['state'], ['ok', 'auto'], true)) $doneMin += (int) ($it['minutes'] ?? 0);
            $mandatory = $this->mandatory($uid, $items, $now);
            $late = array_values(array_filter($items, fn($it) => in_array($it['state'], ['todo', 'late'], true) && $it['occurrence'] !== null));
            $pct = $presence['worked'] > 0 ? (int) round($doneMin / $presence['worked'] * 100) : null;
            if ($presence['worked'] > 0) { $fill['done'] += $doneMin; $fill['worked'] += $presence['worked']; }
            $people[] = [
                'id' => $uid, 'name' => $u['name'], 'position' => $u['position_name'], 'linked' => $emp !== null,
                'in' => $presence['in'], 'in_source' => $presence['source'], 'out' => $presence['out'], 'absence' => $presence['absence'],
                'tasks_done' => $row['done'] ?? 0, 'tasks_total' => $row['total'] ?? 0, 'mandatory' => $mandatory, 'fill' => $pct,
                'activity_min' => intdiv($activity[$uid] ?? 0, 60),
                'action' => $mandatory['state'] === 'blocked' ? ['kind' => 'unblock', 'href' => '/tasks/unblock/' . $uid . '/' . $mandatory['task_id']]
                    : ($late && $presence['absence'] === null ? ['kind' => 'remind', 'occurrences' => array_map(fn($it) => $it['occurrence'], $late)]
                    : ['kind' => 'details', 'href' => '/organization/execution']),
            ];
        }

        // Zespoły pracowni : personnes sans compte ERP (tablettes) + lignes « atelier » des tâches.
        $team = ['people' => 0, 'present' => 0, 'absent' => 0, 'done' => 0, 'total' => 0, 'rows' => 0];
        $all = ['people' => 0, 'present' => 0, 'absent' => 0];
        foreach ($this->repo->employees() as $e) {
            $id = (int) $e['id'];
            $present = isset($clocks[$id]);
            $off = !$present && isset($absent[$id]);
            $all['people']++;
            $all['present'] += $present ? 1 : 0;
            $all['absent'] += $off ? 1 : 0;
            if ($e['id_user'] !== null) continue;
            $team['people']++;
            $team['present'] += $present ? 1 : 0;
            $team['absent'] += $off ? 1 : 0;
        }
        foreach ($day['rows'] as $r) if ($r['atelier']) { $team['done'] += $r['done']; $team['total'] += $r['total']; $team['rows']++; }

        // 2 · Tydzień : stanowisko × dzień (zrobione na czas / z opóźnieniem / częściowo / nic).
        $weekRows = [];
        foreach ($week['rows'] as $r) {
            $cells = [];
            foreach ($r['cells'] as $i => $items) $cells[] = $this->weekCell($items, $week['columns'][$i], $today);
            $weekRows[] = ['name' => $r['name'], 'sub' => $r['people'] ? implode(', ', $r['people']) : ($r['sub'] ?? ''), 'atelier' => $r['atelier'], 'cells' => $cells];
        }

        // 3 · Do decyzji CEO.
        $requests = [];
        $names = array_column($people, 'name', 'id');
        foreach ($this->repo->unblockRequests($today) as $q) {
            $blocked = array_filter($this->lock->blocking($q['user'], $now), fn($b) => $b['task_id'] === $q['task']);
            if (!$blocked) continue;
            $requests[] = $q + ['name' => $names[$q['user']] ?? $this->locks->userName($q['user']) ?? ('#' . $q['user']), 'title' => reset($blocked)['title'],
                'href' => '/tasks/unblock/' . $q['user'] . '/' . $q['task']];
        }
        $lateByPosition = [];
        foreach ($week['late'] as $it) {
            if ($it['occurrence'] === null) continue;
            $p = &$lateByPosition[$it['position']];
            $p ??= ['position' => $it['position'], 'count' => 0, 'since' => $it['due'], 'titles' => [], 'occurrences' => []];
            $p['count'] += $it['count'] ?? 1;
            $p['since'] = min($p['since'], $it['due']);
            $p['titles'][] = PositionTaskService::shortTitle($it['title']);
            $p['occurrences'][] = $it['occurrence'];
            unset($p);
        }
        foreach ($lateByPosition as &$p) $p['since_label'] = self::sinceLabel($p['since'], $now);
        unset($p);
        $forgotten = $this->repo->forgottenExits($today);
        $results = null;
        try {
            $t = $this->profit->snapshot($now)['totals'] ?? null;
            if ($t) $results = ['month' => self::monthName((int) $now->format('n')), 'staff_pct' => $t['staff_pct'], 'staff_target' => $t['staff_target'],
                'staff_ok' => (bool) $t['staff_ok'], 'margin_pct' => $t['margin_pct'], 'margin_target' => $t['margin_target'], 'revenue' => $t['revenue']];
        } catch (\Throwable $e) {
            error_log('[control] wyniki : ' . $e->getMessage());
        }

        $blockedPeople = count(array_filter($people, fn($p) => $p['mandatory']['state'] === 'blocked'));
        $lateMandatory = count(array_filter($people, fn($p) => in_array($p['mandatory']['state'], ['blocked', 'late'], true)));
        return [
            'now' => $now->format('H:i'), 'today' => $today, 'day_label' => self::DAY_SHORT[(int) $now->format('N')] . ' ' . $now->format('d.m.Y'),
            'kpi' => [
                'present' => $all['present'], 'people' => $all['people'], 'absent' => $all['absent'], 'missing' => max(0, $all['people'] - $all['present'] - $all['absent']),
                'tasks_done' => $day['tiles']['done'], 'tasks_total' => $day['tiles']['total'],
                'tasks_pct' => $day['tiles']['total'] > 0 ? (int) round($day['tiles']['done'] / $day['tiles']['total'] * 100) : null,
                'mandatory_late' => $lateMandatory, 'blocked' => $blockedPeople, 'requests' => count($requests),
                'fill' => $fill['worked'] > 0 ? (int) round($fill['done'] / $fill['worked'] * 100) : null,
            ],
            'people' => $people, 'team' => $team,
            'week' => ['columns' => array_map(fn($c) => ['label' => $c['label'], 'today' => $c['today']], $week['columns']), 'rows' => $weekRows],
            'decisions' => ['requests' => $requests, 'late' => array_values($lateByPosition), 'forgotten' => count($forgotten), 'results' => $results,
                // [289] Urlopy osób z biura do zatwierdzenia (Mój urlop).
                'leaves' => array_map(fn($l) => ['id' => (int) $l['id'], 'name' => $l['name'], 'position' => $l['position_name'], 'from' => $l['date_from'], 'to' => $l['date_to'],
                    'days' => (int) $l['days'], 'substitute' => $l['substitute_name']], $this->leaves->pending())],
        ];
    }

    /** Entrée (heure + source), sortie, minutes de travail du jour, absence. */
    private function presence(array $clocks, ?string $absence, int $qrDevice, DateTimeImmutable $now): array
    {
        if (!$clocks) return ['in' => null, 'source' => null, 'out' => null, 'worked' => 0, 'absence' => $absence !== null ? (self::ABSENCE[$absence] ?? $absence) : null];
        $first = $clocks[0];
        $last = end($clocks);
        $worked = 0;
        foreach ($clocks as $c) {
            $end = $c['clock_out'] !== null ? strtotime((string) $c['clock_out']) : $now->getTimestamp();
            $worked += max(0, intdiv($end - strtotime((string) $c['clock_in']), 60));
        }
        $source = $first['source'] === 'manual' ? 'ręcznie' : ($qrDevice > 0 && (int) $first['id_device'] === $qrDevice ? 'QR' : 'tablet');
        return ['in' => substr((string) $first['clock_in'], 11, 5), 'source' => $source, 'out' => $last['clock_out'] !== null ? substr((string) $last['clock_out'], 11, 5) : null,
            'worked' => $worked, 'absence' => null];
    }

    /**
     * Obowiązkowe du compte : blocked (ERP bloqué : tâche, depuis), late (blocage proche dépassé mais débloqué), due (à faire
     * aujourd'hui, « do HH:MM »), done, none.
     */
    private function mandatory(int $userId, array $items, DateTimeImmutable $now): array
    {
        $blocking = $this->lock->blocking($userId, $now);
        if ($blocking) {
            $b = $blocking[0];
            return ['state' => 'blocked', 'task_id' => $b['task_id'], 'title' => PositionTaskService::shortTitle($b['title']), 'since' => substr($b['block_at'], 11, 5)];
        }
        $tasks = $this->locks->mandatoryTasks($userId);
        if (!$tasks) return ['state' => 'none'];
        $states = [];
        foreach ($items as $it) if ($it['task'] !== null) $states[(int) $it['task']] = $it['state'];
        $open = null;
        foreach ($tasks as $t) {
            $st = $states[(int) $t['id']] ?? null;
            if ($st === null || in_array($st, ['ok', 'auto'], true)) continue;
            $at = TaskLockService::blockAt($t, $now->format('Y-m-d'));
            $entry = ['task_id' => (int) $t['id'], 'title' => PositionTaskService::shortTitle($t['title_pl']), 'until' => $at->format('H:i'), 'next_day' => $at->format('Y-m-d') !== $now->format('Y-m-d')];
            if ($open === null || $at < new DateTimeImmutable($now->format('Y-m-d') . ' ' . $open['until'])) $open = $entry;
        }
        $any = array_filter($tasks, fn($t) => isset($states[(int) $t['id']]));
        if ($open !== null) return ['state' => 'due'] + $open;
        return $any ? ['state' => 'done'] : ['state' => 'none'];
    }

    /** Komórka tygodnia : zrobione (na czas / z opóźnieniem), razem, stan i lista zadań dnia. */
    private function weekCell(array $items, array $col, string $today): array
    {
        $future = $col['from'] > $today;
        $done = $lateDone = $total = 0;
        $list = [];
        foreach ($items as $it) {
            $ok = in_array($it['state'], ['ok', 'auto'], true);
            $doneDay = $ok && !empty($it['done_at']) ? substr((string) $it['done_at'], 0, 10) : null;
            $wasLate = $ok && $doneDay !== null && $doneDay > ($it['due'] ?? $col['from']);
            if (!$future || $ok) $total++;
            if ($ok) $done++;
            if ($wasLate) $lateDone++;
            $list[] = ['title' => PositionTaskService::shortTitle((string) ($it['title'] ?? '')), 'who' => $it['done_by'] ?? ($it['who'] ?? null),
                'at' => !empty($it['done_at']) ? substr((string) $it['done_at'], 11, 5) : ($it['at'] ?? null), 'state' => $it['state'],
                'late' => $wasLate, 'note' => $it['summary'] ?? ($it['note'] ?? null)];
        }
        $state = $future ? 'plan' : ($total === 0 ? 'none' : ($done >= $total ? ($lateDone ? 'late' : 'ok') : ($done > 0 ? 'part' : 'zero')));
        return ['done' => $done, 'total' => $total, 'late' => $lateDone, 'state' => $state, 'items' => $list, 'today' => $col['today'] ?? false];
    }

    private static function sinceLabel(string $due, DateTimeImmutable $now): string
    {
        $days = (int) floor(($now->setTime(0, 0)->getTimestamp() - strtotime($due)) / 86400);
        if ($days <= 0) return 'od dziś';
        if ($days === 1) return 'od wczoraj';
        if ($days < 7) return 'od ' . ['', 'poniedziałku', 'wtorku', 'środy', 'czwartku', 'piątku', 'soboty', 'niedzieli'][(int) date('N', strtotime($due))];
        return 'od ' . date('d.m', strtotime($due));
    }

    private static function monthName(int $m): string
    {
        return ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'][$m];
    }
}
