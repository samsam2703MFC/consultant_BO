<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Workshop\ParamRepository;
use DateTimeImmutable;

/**
 * [285] Raport stanowisk (makieta 39, administrateur) : pour chaque poste ERP, les tâches avec les minutes données par
 * l'administrateur, le remplissage du jour, de la semaine et du mois (minutes des tâches de la période ÷ temps de travail :
 * 8 h par jour et par personne, jours ouvrés × 8 h) et ce qui a été fait (fait / dû, en retard). Les postes de l'atelier
 * (tablettes) sur une ligne. Remplissage aussi en tête de « Zadania stanowiska » (plan du poste).
 */
final class PositionReportService
{
    public const DAY_MIN = 480;
    private const FREQ = ['daily' => 'codziennie', 'weekly' => 'co tydzień', 'monthly' => 'co miesiąc', 'quarterly' => 'co kwartał'];
    private const MONTHS = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private PositionTaskService $tasks, private PositionRepository $positions, private PageAccessService $access,
        private ParamRepository $params)
    {
    }

    public function report(?int $actorId, ?DateTimeImmutable $today = null): array
    {
        if (!$this->access->isAdmin($actorId) && !$this->access->isCeo($actorId)) throw new CustomException('Raport stanowisk : tylko administrator.', 'errors', 403);
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $p = $this->periods($today);
        $people = [];
        foreach ($this->positions->users() as $u) if ($u['id_position']) $people[(int) $u['id_position']] = ($people[(int) $u['id_position']] ?? 0) + 1;
        $month = $this->workItems($this->tasks->itemsBetween($p['month_from'], $p['month_to'], $today), $p);
        $week = $this->workItems($this->tasks->itemsBetween($p['week_from'], $p['week_to'], $today), $p);
        $todayStr = $today->format('Y-m-d');

        $rows = [];
        $atelier = ['positions' => [], 'items_m' => [], 'items_w' => []];
        foreach ($month as $it) {
            if ($it['position_kind'] === 'atelier') { $atelier['items_m'][] = $it; $atelier['positions'][$it['position_id']] = true; continue; }
            $rows[$it['position_id']]['items_m'][] = $it;
            $rows[$it['position_id']]['name'] = $it['position_name'];
        }
        foreach ($week as $it) {
            if ($it['position_kind'] === 'atelier') { $atelier['items_w'][] = $it; $atelier['positions'][$it['position_id']] = true; continue; }
            $rows[$it['position_id']]['items_w'][] = $it;
            $rows[$it['position_id']]['name'] = $it['position_name'];
        }
        $out = [];
        foreach ($rows as $pid => $r) {
            $n = max(1, $people[$pid] ?? 0);
            $out[] = $this->row($r['name'], $n, $r['items_m'] ?? [], $r['items_w'] ?? [], $todayStr, $p, $people[$pid] ?? 0) + ['id' => $pid, 'atelier' => false];
        }
        usort($out, fn($a, $b) => $b['month']['min'] <=> $a['month']['min']);
        $atelierRow = null;
        if ($atelier['items_m'] || $atelier['items_w']) {
            $atelierRow = $this->row('Stanowiska pracowni (tablety)', count($atelier['positions']), $atelier['items_m'], $atelier['items_w'], $todayStr, $p, null)
                + ['id' => null, 'atelier' => true, 'positions' => count($atelier['positions'])];
        }

        $done = array_sum(array_column($out, 'done')) + ($atelierRow['done'] ?? 0);
        $due = array_sum(array_column($out, 'due')) + ($atelierRow['due'] ?? 0);
        $lateBy = [];
        foreach (array_merge($out, $atelierRow ? [$atelierRow] : []) as $r) if ($r['late']) $lateBy[] = $r['short'] . ' ' . $r['late'];
        $top = $out ? array_reduce($out, fn($c, $r) => $c === null || $r['month']['pct'] > $c['month']['pct'] ? $r : $c) : null;
        $target = (int) $this->params->get('tasks.done_target_pct', '90');
        return [
            'today' => $todayStr, 'period' => $p,
            'kpi' => ['positions' => count($out), 'atelier' => $atelierRow['positions'] ?? 0, 'done' => $done, 'due' => $due,
                'done_pct' => $due ? (int) round($done / $due * 100) : null, 'target' => $target,
                'late' => array_sum(array_column($out, 'late')) + ($atelierRow['late'] ?? 0), 'late_by' => $lateBy,
                'top' => $top ? ['name' => $top['name'], 'pct' => $top['month']['pct'], 'min' => $top['month']['min'], 'cap' => $top['month']['cap']] : null],
            'rows' => $out, 'atelier' => $atelierRow,
        ];
    }

    /** Remplissage d'un poste (en tête de « Zadania stanowiska »). */
    public function positionFill(int $positionId, ?DateTimeImmutable $today = null): ?array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $p = $this->periods($today);
        $n = 0;
        foreach ($this->positions->users() as $u) if ((int) $u['id_position'] === $positionId) $n++;
        $m = array_values(array_filter($this->workItems($this->tasks->itemsBetween($p['month_from'], $p['month_to'], $today), $p), fn($it) => $it['position_id'] === $positionId));
        $w = array_values(array_filter($this->workItems($this->tasks->itemsBetween($p['week_from'], $p['week_to'], $today), $p), fn($it) => $it['position_id'] === $positionId));
        $r = $this->row('', max(1, $n), $m, $w, $today->format('Y-m-d'), $p, $n);
        return ['day' => $r['day'], 'week' => $r['week'], 'month' => $r['month'], 'people' => $n, 'period' => $p];
    }

    private function periods(DateTimeImmutable $today): array
    {
        $work = $this->tasks->workdayNumbers();
        $mFrom = $today->modify('first day of this month');
        $mTo = $today->modify('last day of this month');
        $wFrom = $today->setISODate((int) $today->format('o'), (int) $today->format('W'), 1);
        $wTo = $wFrom->modify('+6 days');
        $holidays = Holidays::between(min($mFrom, $wFrom)->format('Y-m-d'), max($mTo, $wTo)->format('Y-m-d'), ['PL']);
        $count = function (DateTimeImmutable $from, DateTimeImmutable $to) use ($work, $holidays): int {
            $n = 0;
            for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) if (in_array((int) $d->format('N'), $work, true) && !isset($holidays[$d->format('Y-m-d')])) $n++;
            return $n;
        };
        $mHol = array_values(array_filter(array_keys($holidays), fn($d) => $d >= $mFrom->format('Y-m-d') && $d <= $mTo->format('Y-m-d') && in_array((int) date('N', strtotime($d)), $work, true)));
        return ['month_from' => $mFrom->format('Y-m-d'), 'month_to' => $mTo->format('Y-m-d'), 'week_from' => $wFrom->format('Y-m-d'), 'week_to' => $wTo->format('Y-m-d'),
            'month_days' => $count($mFrom, $mTo), 'week_days' => $count($wFrom, $wTo), 'week_no' => (int) $today->format('W'),
            'month_label' => self::MONTHS[(int) $today->format('n')] . ' ' . $today->format('Y'), 'month_name' => self::MONTHS[(int) $today->format('n')],
            'holidays' => array_map(fn($d) => date('d.m', strtotime($d)), $mHol), 'holiday_dates' => array_keys($holidays),
            'day_label' => ['', 'pn.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.', 'nd.'][(int) $today->format('N')] . ' ' . $today->format('d.m')];
    }

    /** Terminy w święta (PL) pominięte : dzień wolny, zadanie nie przypada. */
    private function workItems(array $items, array $p): array
    {
        $off = array_flip($p['holiday_dates']);
        return array_values(array_filter($items, fn($it) => !isset($off[$it['due']])));
    }

    /** Ligne d'un poste : minutes jour / semaine / mois et remplissage, fait / dû, en retard, tâches du mois. */
    private function row(string $name, int $n, array $itemsM, array $itemsW, string $today, array $p, ?int $people): array
    {
        $sum = fn(array $items) => array_sum(array_map(fn($it) => (int) ($it['minutes'] ?? 0), $items));
        $dayItems = array_values(array_filter($itemsW, fn($it) => $it['due'] === $today));
        $cap = ['day' => self::DAY_MIN * $n, 'week' => self::DAY_MIN * $p['week_days'] * $n, 'month' => self::DAY_MIN * $p['month_days'] * $n];
        $fill = fn(int $min, int $c) => ['min' => $min, 'cap' => $c, 'pct' => $c > 0 ? (int) round($min / $c * 100) : 0];
        $done = $due = $late = 0;
        $tasks = [];
        foreach ($itemsM as $it) {
            $ok = in_array($it['state'], ['ok', 'auto'], true);
            $past = $it['due'] <= $today;
            if ($past || $ok) $due++;
            if ($ok) $done++;
            if ($it['state'] === 'late') $late++;
            $t = &$tasks[$it['task']];
            $t ??= ['title' => $it['title'], 'freq' => (self::FREQ[$it['frequency']] ?? $it['frequency']) . ($it['due_time'] ? ' · ' . $it['due_time'] : ''), 'minutes' => (int) ($it['minutes'] ?? 0),
                'count' => 0, 'done' => 0, 'due' => 0, 'late' => 0, 'next' => null, 'auto' => $it['wizard'] !== null];
            $t['count']++;
            if ($past || $ok) $t['due']++;
            if ($ok) $t['done']++;
            if ($it['state'] === 'late') $t['late']++;
            if (!$past && !$ok && ($t['next'] === null || $it['due'] < $t['next'])) $t['next'] = $it['due'];
            unset($t);
        }
        foreach ($tasks as &$t) {
            $t['total_min'] = $t['minutes'] * $t['count'];
            $t['next_label'] = $t['next'] !== null && $t['due'] === 0 ? 'do ' . date('d.m', strtotime($t['next'])) : null;
        }
        unset($t);
        usort($tasks, fn($a, $b) => $b['total_min'] <=> $a['total_min']);
        $short = preg_split('/\s+/', trim($name))[0] ?? $name;
        return ['name' => $name, 'short' => $short, 'people' => $people, 'tasks_count' => count($tasks), 'tasks' => $tasks,
            'day' => $fill($sum($dayItems), $cap['day']), 'week' => $fill($sum($itemsW), $cap['week']), 'month' => $fill($sum($itemsM), $cap['month']),
            'done' => $done, 'due' => $due, 'late' => $late];
    }
}
