<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

use App\Repositories\Workshop\ProductionSummaryRepository;

/**
 * Produkcja produktu ERP w skrócie (strona edycji produktu) : wydajność (sztuki na godzinę pracy),
 * etapy marszruty z czasami i odstępem do następnego etapu (od razu, + minuty albo następny dzień),
 * ilość tygodnia z planu i dni z Gantta tygodnia, operacje Gantta godzinowego.
 *
 * Partia odniesienia : jedna paleta (sztuki w kartonie × kartony na paletę), inaczej partia minimalna,
 * inaczej 1000 sztuk — czas stały etapu liczy się na przejście, więc wydajność zależy od ilości.
 * Czekanie po etapie od 8 h oznacza następny dzień (D+1).
 */
final class ProductionSummaryService
{
    public const NEXT_DAY_S = 8 * 3600;

    public function __construct(private ProductionSummaryRepository $repo, private \App\Repositories\Workshop\ParamRepository $params)
    {
    }

    public function forFinalProduct(int $productId, ?int $now = null): ?array
    {
        $item = $this->repo->item($productId);
        return $item === null ? null : $this->build($item, $now ?? time());
    }

    /** To samo dla karty produkcyjnej (także półproduktu). */
    public function forItem(int $itemId, ?int $now = null): ?array
    {
        $item = $this->repo->itemById($itemId);
        return $item === null ? null : $this->build($item, $now ?? time());
    }

    private function build(array $item, int $now): array
    {
        $itemId = (int) $item['id'];
        $steps = $this->repo->steps($itemId);
        $ppc = (int) $item['ppc'];
        $cpp = (int) $item['cpp'];
        [$ref, $refKind] = match (true) {
            $ppc > 0 && $cpp > 0 => [(float) ($ppc * $cpp), 'pallet'],
            (float) $item['batch_min'] > 0 => [(float) $item['batch_min'], 'batch'],
            default => [1000.0, 'thousand'],
        };
        $calc = StandardTime::routing($steps, $ref);
        $rows = [];
        $offset = 0.0;
        foreach ($steps as $i => $s) {
            $c = $calc['steps'][$i];
            $busy = $c['work_s'] + $c['cleaning_s'];
            $rows[] = [
                'step_no' => (int) $s['step_no'], 'name' => $s['name_pl'],
                'workstation' => $s['ws_code'] . ' · ' . $s['ws_name'], 'workstation_type' => $s['ws_type'],
                'operators' => (float) $s['operators_required'], 'fixed_s' => (int) $s['fixed_time_s'], 'variable_s' => (float) $s['variable_time_s'],
                'capacity' => (float) $s['capacity_per_pass'], 'cleaning_s' => (int) $s['cleaning_time_s'],
                'temperature_c' => $s['temperature_c'] !== null ? (float) $s['temperature_c'] : null,
                'passes' => $c['passes'], 'work_s' => $c['work_s'], 'labour_s' => $c['labour_s'],
                'per_hour' => $busy > 0 ? $ref / ($busy / 3600) : null,
                'start_s' => $offset,
                'delay_s' => (int) $s['delay_after_s'],
                'delay_place' => $s['delay_ws_code'] !== null ? $s['delay_ws_code'] . ' · ' . $s['delay_ws_name'] : null,
                'gap' => self::gap((int) $s['delay_after_s']),
            ];
            $offset += $c['elapsed_s'];
        }
        $labourS = $calc['totals']['labour_s'];
        $perHour = $labourS > 0 ? $ref / ($labourS / 3600) : null;
        $people = (int) $item['group_people'];
        return [
            'item_id' => $itemId, 'item_type' => $item['item_type'], 'unit' => $item['base_unit'],
            'group' => $item['group_name'], 'group_people' => $people, 'mode' => $item['mode'], 'status' => $item['status'], 'bake_state' => $item['bake_state'],
            'batch_min' => $item['batch_min'] !== null ? (float) $item['batch_min'] : null, 'batch_multiple' => $item['batch_multiple'] !== null ? (float) $item['batch_multiple'] : null,
            'stock_min' => $item['stock_min'] !== null ? (float) $item['stock_min'] : null, 'stock_max' => $item['stock_max'] !== null ? (float) $item['stock_max'] : null,
            'ppc' => $ppc ?: null,
            'reference' => ['quantity' => $ref, 'kind' => $refKind, 'cartons' => $ppc > 0 ? $ref / $ppc : null],
            'per_hour' => $perHour,
            'per_hour_group' => $perHour !== null && $people > 0 ? $perHour * $people : null,
            'minutes_per_carton' => $ppc > 0 && $ref > 0 ? $labourS / $ref * $ppc / 60 : null,
            'hours_per_1000' => $ref > 0 ? $labourS / $ref * 1000 / 3600 : null,
            'labour_s' => $labourS,
            'lead_time_s' => $calc['totals']['elapsed_s'],
            'next_day' => (bool) array_filter($rows, fn($r) => $r['gap']['kind'] === 'next_day'),
            'steps' => $rows,
            'week' => $this->week($itemId, $now),
        ];
    }

    /** Odstęp do następnego etapu po czekaniu : od razu, + minuty albo następny dzień (≥ 8 h). */
    public static function gap(int $delayS): array
    {
        if ($delayS <= 0) return ['kind' => 'none', 'seconds' => 0];
        return ['kind' => $delayS >= self::NEXT_DAY_S ? 'next_day' : 'minutes', 'seconds' => $delayS];
    }

    private function week(int $itemId, int $now): array
    {
        // Tydzień produkcji od week.start_day (jak Gantt tygodnia).
        $monday = \App\Services\Workshop\Planning\WeekStart::of($now, \App\Services\Workshop\Planning\WeekStart::day($this->params));
        $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
        $lines = $this->repo->planLines($itemId, $monday, $sunday);
        $ops = $this->repo->operations($itemId, $monday . ' 00:00:00', date('Y-m-d', strtotime($sunday . ' +1 day')) . ' 00:00:00');
        return [
            'monday' => $monday, 'sunday' => $sunday, 'iso_week' => (int) date('W', strtotime($monday)),
            'quantity' => array_sum(array_map(fn($l) => (float) $l['qty_final'], $lines)),
            'hours' => array_sum(array_map(fn($l) => (float) $l['standard_hours'], $lines)),
            'plan' => $lines ? ['id' => (int) $lines[0]['plan_id'], 'version' => (int) $lines[0]['version'], 'status' => $lines[0]['status']] : null,
            'due_dates' => array_values(array_unique(array_column($lines, 'due_date'))),
            'slots' => array_map(fn($s) => ['day' => $s['work_date'], 'quantity' => (float) $s['quantity']], $this->repo->slots($itemId, $monday)),
            'operations' => (int) ($ops['operations'] ?? 0), 'done' => (int) ($ops['done'] ?? 0), 'running' => (int) ($ops['running'] ?? 0),
            'first_start' => $ops['first_start'] ?? null, 'last_end' => $ops['last_end'] ?? null,
        ];
    }
}
