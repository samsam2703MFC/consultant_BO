<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Workshop\DayEfficiencyRepository;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Services\Workshop\Planning\PlanningService;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Wydajność dnia grupy (Tablety › Zatwierdzenia produkcji) = czas normatywny zrobionych sztuk ÷ czas pracy grupy.
 *
 * Czas normatywny jak w planie tygodnia : partia z karty produktu (osoby × minuty partii ÷ sztuki w partii),
 * inaczej marszruta (StandardTime) ; produkt bez czasu = « brak », nie wchodzi do wskaźnika.
 * Czas pracy osoby = odbicia dnia − przerwa (efficiency.break_min_per_person, na osobę obecną, bez kontroli)
 * − sprzątanie (0, 15 albo 30 min na osobę obecną, wybór kierownika). Odbicie bez wyjścia : dziś do teraz,
 * w dniach poprzednich nie liczy się (do poprawki w Hala › Godziny pracy).
 */
final class DayEfficiencyService
{
    public const CLEANING_OPTIONS = [0, 15, 30];

    /** @var array{0: array<int, array>, 1: array<int, list<array>>}|null produkty i marszruty (raz na żądanie) */
    private ?array $catalog = null;

    public function __construct(
        private DayEfficiencyRepository $repo,
        private PlanningRepository $planning,
        private GroupRepository $groups,
        private ParamRepository $params,
        private AuditTrail $audit,
    ) {
    }

    /** Wydajność dnia grupy : osoby (odbicia, przerwa, sprzątanie), stanowiska, produkty z czasem normatywnym. */
    public function day(int $groupId, string $date, string $lang = 'pl', ?int $now = null): array
    {
        $group = $this->groups->find($groupId) ?? throw ApiException::notFound('group', $groupId);
        $cleaning = $this->repo->cleaning($date, $date)[$date . '|' . $groupId] ?? 0;
        $calc = $this->compute($groupId, $date, $this->peopleOf($groupId, $date, $lang, $now), $this->repo->outputs($date, $date, $groupId), $cleaning);
        $target = (float) $this->params->get('efficiency.target_pct', '85');
        return [
            'date' => $date,
            'group' => ['id' => $groupId, 'name' => $lang === 'fr' ? ($group['name_fr'] ?: $group['name_pl']) : $group['name_pl']],
            'target_pct' => $target,
            'break_min' => $this->breakMin(),
            'cleaning_min' => $cleaning,
            'cleaning_options' => self::CLEANING_OPTIONS,
        ] + $calc + ['target_reached' => $calc['efficiency_pct'] !== null ? $calc['efficiency_pct'] >= $target : null];
    }

    /**
     * Lista dni (Zatwierdzenia produkcji) : wydajność każdego wiersza dzień × grupa, liczona zakresowo (3 zapytania).
     * @param list<array{date:string, group:array{id:int}}> $rows
     */
    public function annotate(array $rows, ?int $now = null): array
    {
        if (!$rows) return $rows;
        $dates = array_column($rows, 'date');
        [$from, $to] = [min($dates), max($dates)];
        $members = $this->repo->memberships();
        $clocks = [];
        foreach ($this->repo->clocks($from, $to) as $k) {
            foreach ($members[(int) $k['id_employee']] ?? [] as $g) $clocks[substr((string) $k['clock_in'], 0, 10) . '|' . $g][] = $k;
        }
        $outputs = [];
        foreach ($this->repo->outputs($from, $to) as $o) $outputs[$o['work_date'] . '|' . $o['id_group']][] = $o;
        $cleaning = $this->repo->cleaning($from, $to);
        $target = (float) $this->params->get('efficiency.target_pct', '85');
        foreach ($rows as &$r) {
            $key = $r['date'] . '|' . $r['group']['id'];
            $calc = $this->compute((int) $r['group']['id'], $r['date'], $this->people($clocks[$key] ?? [], $r['date'], 'pl', $now), $outputs[$key] ?? [], $cleaning[$key] ?? 0);
            $r['efficiency_pct'] = $calc['efficiency_pct'];
            $r['efficiency_target_reached'] = $calc['efficiency_pct'] !== null ? $calc['efficiency_pct'] >= $target : null;
        }
        return $rows;
    }

    /** Sprzątanie dnia grupy : 0, 15 albo 30 min na osobę obecną (kierownik produkcji). */
    public function setCleaning(int $groupId, string $date, array $body, ApiContext $context): array
    {
        if (!$this->groups->exists($groupId)) throw ApiException::notFound('group', $groupId);
        $v = (new Validator($body))->only(['minutes'])->integer('minutes', true, 0, 240);
        $v->throwIfInvalid();
        $minutes = (int) $v->value('minutes');
        if (!in_array($minutes, self::CLEANING_OPTIONS, true)) {
            throw ApiException::validation([['field' => 'minutes', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', self::CLEANING_OPTIONS)]]]);
        }
        $before = $this->repo->cleaning($date, $date)[$date . '|' . $groupId] ?? 0;
        $this->repo->setCleaning($date, $groupId, $minutes, $context->userId);
        $this->audit->record($context, 'update', 'day_cleaning', $groupId, ['date' => $date, 'minutes' => $before], ['date' => $date, 'minutes' => $minutes]);
        return $this->day($groupId, $date, $context->translator->lang());
    }

    /** Odbicia osób grupy w dniu. */
    private function peopleOf(int $groupId, string $date, string $lang, ?int $now): array
    {
        $members = $this->repo->memberships();
        $mine = array_values(array_filter($this->repo->clocks($date, $date), fn($k) => in_array($groupId, $members[(int) $k['id_employee']] ?? [], true)));
        return $this->people($mine, $date, $lang, $now);
    }

    /** @return list<array> osoby z odbiciami dnia : wejście, wyjście, minuty odbite, bez wyjścia */
    private function people(array $clocks, string $date, string $lang, ?int $now): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now) === $date;
        $out = [];
        foreach ($clocks as $k) {
            $id = (int) $k['id_employee'];
            $in = strtotime((string) $k['clock_in']);
            $end = $k['clock_out'] !== null ? strtotime((string) $k['clock_out']) : ($today ? max($in, $now) : null);
            $p = &$out[$id];
            $p ??= ['id' => $id, 'name' => $k['name'],
                'position' => $k['id_position'] !== null ? ['id' => (int) $k['id_position'], 'name' => $lang === 'fr' ? ($k['position_fr'] ?: $k['position_pl']) : $k['position_pl'],
                    'sort' => (int) $k['position_sort']] : null,
                'first_in' => $k['clock_in'], 'last_out' => null, 'open' => false, 'missing_out' => false, 'clocked_min' => 0.0];
            if ($k['clock_out'] === null) $today ? $p['open'] = true : $p['missing_out'] = true;
            if ($k['clock_out'] !== null && ($p['last_out'] === null || $k['clock_out'] > $p['last_out'])) $p['last_out'] = $k['clock_out'];
            if ($end !== null) $p['clocked_min'] += max(0, $end - $in) / 60;
            unset($p);
        }
        usort($out, fn($a, $b) => [$a['position']['sort'] ?? PHP_INT_MAX, $a['name']] <=> [$b['position']['sort'] ?? PHP_INT_MAX, $b['name']]);
        return $out;
    }

    /** Czas pracy, stanowiska, czas normatywny produktów i wydajność. */
    private function compute(int $groupId, string $date, array $people, array $outputs, int $cleaning): array
    {
        $break = $this->breakMin();
        $totals = ['clocked_min' => 0.0, 'break_min' => 0.0, 'cleaning_min' => 0.0, 'work_min' => 0.0, 'standard_min' => 0.0, 'produced' => 0.0, 'missing_standard' => 0];
        $positions = [];
        foreach ($people as &$p) {
            $present = $p['clocked_min'] > 0;
            $p['break_min'] = $present ? min($break, $p['clocked_min']) : 0;
            $p['cleaning_min'] = $present ? min($cleaning, $p['clocked_min'] - $p['break_min']) : 0;
            $p['work_min'] = round(max(0.0, $p['clocked_min'] - $p['break_min'] - $p['cleaning_min']), 1);
            $p['clocked_min'] = round($p['clocked_min'], 1);
            foreach (['clocked_min', 'break_min', 'cleaning_min', 'work_min'] as $f) $totals[$f] += $p[$f];
            if ($present) {
                $key = $p['position']['id'] ?? 0;
                $positions[$key] ??= ['id' => $p['position']['id'] ?? null, 'name' => $p['position']['name'] ?? null, 'sort' => $p['position']['sort'] ?? PHP_INT_MAX, 'people' => 0, 'work_min' => 0.0];
                $positions[$key]['people']++;
                $positions[$key]['work_min'] += $p['work_min'];
            }
            unset($p['position']['sort']);
        }
        unset($p);
        usort($positions, fn($a, $b) => [$a['sort'], (string) $a['name']] <=> [$b['sort'], (string) $b['name']]);
        $positions = array_map(function ($s) use ($totals) {
            unset($s['sort']);
            return $s + ['pct' => $totals['work_min'] > 0 ? round($s['work_min'] / $totals['work_min'] * 100, 1) : null];
        }, $positions);

        [$items, $routings] = $this->catalog();
        $products = [];
        foreach ($outputs as $o) {
            $id = (int) $o['id_item'];
            $qty = (float) $o['quantity'];
            if ($qty <= 0) continue;
            $item = $items[$id] ?? null;
            $std = $item !== null ? $this->standardMinutes($item, $routings[$id] ?? [], $qty) : null;
            $products[] = [
                'item_id' => $id, 'name' => $item['name'] ?? ('#' . $id), 'unit' => $item['base_unit'] ?? 'szt',
                'produced' => round($qty, 3), 'standard_min' => $std !== null ? round($std['min'], 1) : null,
                'min_per_unit' => $std !== null ? round($std['min'] / $qty, 3) : null, 'source' => $std['source'] ?? null,
            ];
            $totals['produced'] += $qty;
            if ($std === null) $totals['missing_standard']++;
            else $totals['standard_min'] += $std['min'];
        }
        usort($products, fn($a, $b) => [$a['standard_min'] === null, $a['name']] <=> [$b['standard_min'] === null, $b['name']]);
        foreach (['clocked_min', 'break_min', 'cleaning_min', 'work_min', 'standard_min'] as $f) $totals[$f] = round($totals[$f], 1);
        $totals['produced'] = round($totals['produced'], 3);
        return [
            'people' => array_values($people),
            'positions' => $positions,
            'products' => $products,
            'totals' => $totals,
            'efficiency_pct' => $totals['work_min'] > 0 && $totals['standard_min'] > 0 ? round($totals['standard_min'] / $totals['work_min'] * 100, 1) : null,
        ];
    }

    /** @return array{min: float, source: string}|null minuty normatywne ilości (partia z karty, inaczej marszruta) */
    private function standardMinutes(array $item, array $steps, float $qty): ?array
    {
        $batch = PlanningService::batchHours($item);
        if ($batch !== null) return ['min' => $qty / $batch[0] * $batch[1] * 60, 'source' => 'batch'];
        if (!$steps) return null;
        $labour = StandardTime::routing($steps, $qty)['totals']['labour_s'];
        return $labour > 0 ? ['min' => $labour / 60, 'source' => 'routing'] : null;
    }

    private function catalog(): array
    {
        if ($this->catalog === null) {
            $items = [];
            foreach ($this->planning->items() as $i) $items[(int) $i['id']] = $i;
            $this->catalog = [$items, $this->planning->routings()];
        }
        return $this->catalog;
    }

    private function breakMin(): int
    {
        return max(0, (int) $this->params->get('efficiency.break_min_per_person', '15'));
    }
}
