<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Repositories\Workshop\FollowUpRepository;

/**
 * Śledzenie produkcji okresu (produkty, sztuki) : plan z Gantta tygodnia wobec partii i strat zapisanych na
 * tabletach, dni zatwierdzone przez zespoły, niezgodności produkcji. Na grupę, na dzień, straty według powodu
 * i produkty poniżej planu. Strona « Śledzenie produkcji » i protokół zebrania produkcyjnego.
 */
final class ProductionFollowUpService
{
    public const MAX_DAYS = 92;

    public function __construct(private FollowUpRepository $repo)
    {
    }

    /** Okres domyślny : bieżący tydzień (pon. – dziś). @return array{0:string,1:string} */
    public static function defaultPeriod(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        return [date('Y-m-d', strtotime('monday this week', strtotime($today))), $today];
    }

    /** @return array{0:string,1:string} okres poprawiony (daty, kolejność, najwyżej MAX_DAYS dni) */
    public static function period(?string $from, ?string $to): array
    {
        $valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        [$defFrom, $defTo] = self::defaultPeriod();
        $from = $valid($from) ? $from : $defFrom;
        $to = $valid($to) ? $to : $defTo;
        if ($from > $to) [$from, $to] = [$to, $from];
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_DAYS - 1) $from = date('Y-m-d', strtotime($to . ' -' . (self::MAX_DAYS - 1) . ' days'));
        return [$from, $to];
    }

    public function summary(?string $from, ?string $to): array
    {
        [$from, $to] = self::period($from, $to);
        $groups = [];
        foreach ($this->repo->groups() as $g) {
            $groups[(int) $g['id']] = ['id' => (int) $g['id'], 'name' => $g['name_pl'], 'planned' => 0.0, 'produced' => 0.0, 'waste' => 0.0, 'batches' => 0,
                'days' => [], 'closed' => 0, 'nc' => 0, 'plan_hit' => 0.0];
        }
        $days = [];
        $items = [];
        $touch = function (string $day, int $group) use (&$days, &$groups) {
            $days[$day] ??= ['date' => $day, 'planned' => 0.0, 'produced' => 0.0, 'waste' => 0.0, 'groups' => [], 'closed' => []];
            $groups[$group] ??= ['id' => $group, 'name' => '#' . $group, 'planned' => 0.0, 'produced' => 0.0, 'waste' => 0.0, 'batches' => 0, 'days' => [], 'closed' => 0, 'nc' => 0, 'plan_hit' => 0.0];
        };
        $byKey = []; // grupa:produkt => plan / zrobione (realizacja planu bez nadwyżek)
        foreach ($this->repo->planned($from, $to) as $r) {
            $g = (int) $r['id_group'];
            $touch($r['work_date'], $g);
            $q = (float) $r['q'];
            $groups[$g]['planned'] += $q;
            $days[$r['work_date']]['planned'] += $q;
            $items[(int) $r['id_item']]['planned'] = ($items[(int) $r['id_item']]['planned'] ?? 0) + $q;
            $byKey[$g . ':' . $r['id_item']]['planned'] = ($byKey[$g . ':' . $r['id_item']]['planned'] ?? 0) + $q;
        }
        foreach ($this->repo->produced($from, $to) as $r) {
            $g = (int) $r['id_group'];
            $touch($r['work_date'], $g);
            $q = (float) $r['q'];
            $groups[$g]['produced'] += $q;
            $groups[$g]['batches'] += (int) $r['n'];
            $groups[$g]['days'][$r['work_date']] = true;
            $days[$r['work_date']]['produced'] += $q;
            $days[$r['work_date']]['groups'][$g] = true;
            $items[(int) $r['id_item']]['produced'] = ($items[(int) $r['id_item']]['produced'] ?? 0) + $q;
            $byKey[$g . ':' . $r['id_item']]['produced'] = ($byKey[$g . ':' . $r['id_item']]['produced'] ?? 0) + $q;
        }
        $reasons = array_fill_keys(OutputService::WASTE_REASONS, 0.0);
        foreach ($this->repo->waste($from, $to) as $r) {
            $g = (int) $r['id_group'];
            $touch($r['work_date'], $g);
            $q = (float) $r['q'];
            $groups[$g]['waste'] += $q;
            $days[$r['work_date']]['waste'] += $q;
            $reasons[$r['reason']] = ($reasons[$r['reason']] ?? 0) + $q;
            $items[(int) $r['id_item']]['waste'] = ($items[(int) $r['id_item']]['waste'] ?? 0) + $q;
        }
        foreach ($this->repo->closes($from, $to) as $c) {
            $g = (int) $c['id_group'];
            $touch($c['work_date'], $g);
            $groups[$g]['closed']++;
            $days[$c['work_date']]['closed'][$g] = ['at' => $c['closed_at'], 'employee' => $c['employee_name'] ?: null, 'note' => $c['note']];
            $days[$c['work_date']]['groups'][$g] = true;
            $groups[$g]['days'][$c['work_date']] = true;
        }
        $nc = $this->repo->nc($from, $to);
        foreach ($nc as $n) if ($n['id_prod_group'] !== null && isset($groups[(int) $n['id_prod_group']])) $groups[(int) $n['id_prod_group']]['nc']++;
        foreach ($byKey as $key => $v) {
            $g = (int) explode(':', $key)[0];
            if (isset($v['planned'])) $groups[$g]['plan_hit'] += min($v['planned'], $v['produced'] ?? 0);
        }
        $pct = fn(float $a, float $b) => $b > 0 ? round($a / $b * 100, 1) : null;
        $rows = [];
        $total = ['planned' => 0.0, 'produced' => 0.0, 'waste' => 0.0, 'plan_hit' => 0.0, 'batches' => 0, 'days' => 0, 'closed' => 0, 'nc' => count($nc)];
        foreach ($groups as $g) {
            if (!$g['planned'] && !$g['produced'] && !$g['waste'] && !$g['closed'] && !$g['nc']) continue;
            $active = count($g['days']);
            $rows[] = [
                'id' => $g['id'], 'name' => $g['name'], 'planned' => round($g['planned'], 3), 'produced' => round($g['produced'], 3), 'waste' => round($g['waste'], 3),
                'good' => round($g['produced'] - $g['waste'], 3), 'batches' => $g['batches'],
                'plan_pct' => $pct($g['plan_hit'], $g['planned']), 'waste_pct' => $pct($g['waste'], $g['produced']),
                'days' => $active, 'closed' => $g['closed'], 'unclosed' => max(0, $active - $g['closed']), 'nc' => $g['nc'],
            ];
            foreach (['planned', 'produced', 'waste', 'plan_hit', 'batches', 'closed'] as $k) $total[$k] += $g[$k];
            $total['days'] += $active;
        }
        ksort($days);
        $names = $this->repo->items(array_keys($items));
        $below = [];
        foreach ($items as $id => $v) {
            $planned = $v['planned'] ?? 0;
            $produced = $v['produced'] ?? 0;
            if ($planned > 0 && $produced + 1e-6 < $planned) {
                $below[] = ['item_id' => $id, 'name' => $names[$id]['name'] ?? ('#' . $id), 'ppc' => $names[$id]['ppc'] ?? null,
                    'planned' => round($planned, 3), 'produced' => round($produced, 3), 'missing' => round($planned - $produced, 3), 'pct' => $pct($produced, $planned)];
            }
        }
        usort($below, fn($a, $b) => $b['missing'] <=> $a['missing']);
        $topWaste = [];
        foreach ($items as $id => $v) if (($v['waste'] ?? 0) > 0) $topWaste[] = ['item_id' => $id, 'name' => $names[$id]['name'] ?? ('#' . $id), 'waste' => round($v['waste'], 3),
            'waste_pct' => $pct($v['waste'], $v['produced'] ?? 0)];
        usort($topWaste, fn($a, $b) => $b['waste'] <=> $a['waste']);
        $groupNames = array_column($rows, 'name', 'id');
        return [
            'from' => $from, 'to' => $to,
            'groups' => $rows,
            'total' => [
                'planned' => round($total['planned'], 3), 'produced' => round($total['produced'], 3), 'waste' => round($total['waste'], 3),
                'good' => round($total['produced'] - $total['waste'], 3), 'batches' => $total['batches'],
                'plan_pct' => $pct($total['plan_hit'], $total['planned']), 'waste_pct' => $pct($total['waste'], $total['produced']),
                'days' => $total['days'], 'closed' => $total['closed'], 'unclosed' => max(0, $total['days'] - $total['closed']), 'nc' => $total['nc'],
            ],
            'days' => array_values(array_map(fn($d) => [
                'date' => $d['date'], 'planned' => round($d['planned'], 3), 'produced' => round($d['produced'], 3), 'waste' => round($d['waste'], 3),
                'groups' => count($d['groups']), 'closed' => count($d['closed']),
                'open_groups' => array_values(array_map(fn($g) => $groupNames[$g] ?? ('#' . $g), array_diff(array_keys($d['groups']), array_keys($d['closed'])))),
            ], $days)),
            'reasons' => array_filter(array_map(fn($q) => round($q, 3), $reasons)),
            'below_plan' => array_slice($below, 0, 15),
            'top_waste' => array_slice($topWaste, 0, 10),
            'nc' => array_map(fn($n) => ['number' => $n['number'], 'day' => $n['day'], 'severity' => $n['severity'], 'status' => $n['status'],
                'group' => $n['id_prod_group'] !== null ? ($groupNames[(int) $n['id_prod_group']] ?? null) : null, 'description' => mb_substr((string) $n['description'], 0, 160)], $nc),
        ];
    }
}
