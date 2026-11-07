<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Exceptions\CustomException;
use App\Repositories\Stock\StockPeriodRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Okresy stanów wyjątkowych [216] (Magazyn › Prognoza zapasu, makiety 2 i 3) : okres (np. Boże Narodzenie 15.11 – 15.01) ze
 * stanami min. / maks. produktów MTS z prognozy planu logistyki (StockForecastService::periodLevels) ; projekt (stany do
 * poprawienia) → zatwierdzony (obowiązuje od pierwszego dnia okresu, StockLevels) → po ostatnim dniu stany normalne wracają same.
 * Kontrole : zapas ponad normalny (kartony, palety, wartość po koszcie surowców), zamrażarka (stock.freezer_pallet_places),
 * produkcja ponad normę na tydzień do szczytu, szczyt w tygodniu z mniej niż 5 dniami pracy. Okresy zatwierdzone nie nakładają się.
 */
final class StockPeriodService
{
    public function __construct(private StockPeriodRepository $repo, private StockForecastService $forecasts, private ParamRepository $params)
    {
    }

    /** Strona : prognoza (makieta 1), okresy (makieta 3), wybrany okres (makieta 2). */
    public function page(?int $periodId = null, ?string $today = null): array
    {
        $forecast = $this->forecasts->forecast($today);
        return ['forecast' => $forecast, 'periods' => $this->periods($today), 'period' => $periodId !== null ? $this->show($periodId, $forecast, $today) : null,
            'freezer_places' => $this->freezerPlaces()];
    }

    /** Lista okresów ze stanem (Projekt / Zaplanowany / Aktywny / Zakończony) i zapasem ponad normalny. */
    public function periods(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $extras = $this->repo->extras();
        return array_map(fn($p) => $this->head($p, $today) + ['products' => (int) $p['products'], 'extra_cartons' => (int) round($extras[(int) $p['id']] ?? 0)], $this->repo->periods());
    }

    /** Okres : produkty (normalne dziś na karcie, wyjątkowe, prognoza), sumy, zamrażarka, ostrzeżenia. */
    public function show(int $id, ?array $forecast = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $p = $this->repo->period($id) ?? throw new CustomException('Nie znaleziono okresu.', 'errors', 404);
        $forecast ??= $this->forecasts->forecast($today);
        $products = [];
        foreach ($this->repo->mtsProducts() as $mp) $products[(int) $mp['item_id']] = $mp;
        $byItem = [];
        foreach ($forecast['products'] as $fp) $byItem[$fp['item_id']] = $fp;
        try {
            $range = $this->forecasts->periodLevels($p['date_from'], $p['date_to'], $forecast);
        } catch (CustomException) {
            $range = ['levels' => [], 'weeks' => [], 'indexes' => [], 'beyond' => true, 'unapproved' => []];
        }
        $rows = [];
        $extra = 0.0;
        $pallets = 0.0;
        $value = 0.0;
        $warnings = [];
        foreach ($this->repo->items($id) as $it) {
            $mp = $products[(int) $it['id_item']] ?? null;
            if ($mp === null) continue;
            $ppc = max(1, (int) $mp['ppc']);
            $cpp = (int) $mp['cpp'];
            $c = fn($q) => $q === null ? null : round((float) $q / $ppc, 1);
            $normalMax = $c($mp['normal_stock_max']);
            $min = $c($it['stock_min']);
            $max = $c($it['stock_max']);
            $delta = $max - (float) ($normalMax ?? 0);
            $extra += $delta;
            if ($cpp > 0) $pallets += $delta / $cpp;
            if ((float) $mp['cost_eur'] > 0) $value += $delta * (float) $mp['cost_eur'];
            $lvl = $range['levels'][(int) $it['id_item']] ?? null;
            $rows[] = ['item_id' => (int) $it['id_item'], 'name' => $mp['name'], 'ppc' => $ppc, 'cpp' => $cpp ?: null,
                'normal_min' => $c($mp['normal_stock_min']), 'normal_max' => $normalMax, 'min' => $min, 'max' => $max, 'delta' => round($delta, 1),
                'forecast_avg' => $it['forecast_avg'] !== null ? (float) $it['forecast_avg'] : null, 'forecast_peak' => $it['forecast_peak'] !== null ? (float) $it['forecast_peak'] : null,
                'proposed_min' => $lvl['min'] ?? null, 'proposed_max' => $lvl['max'] ?? null, 'peak_week' => $lvl['peak_week'] ?? null];
            if ($lvl !== null && $lvl['peak_days'] !== null && $lvl['peak_days'] < 5 && $delta > 0) {
                $warnings[] = ['code' => 'peak_short_week', 'text' => $mp['name'] . ' : szczyt ' . self::n($lvl['peak']) . ' kart. w tyg. ' . $lvl['peak_week'] . ' — '
                    . $lvl['peak_days'] . ' ' . ($lvl['peak_days'] === 1 ? 'dzień' : 'dni') . ' pracy ; zapas budować wcześniej.'];
            }
        }
        usort($rows, fn($a, $b) => [$b['delta'], $a['name']] <=> [$a['delta'], $b['name']]);
        // Produkcja ponad normę : zapas wyjątkowy do zrobienia w tygodniach z produkcją przed szczytem okresu.
        $peakIndex = null;
        $best = -1;
        foreach ($range['indexes'] as $i) if (($forecast['need'][$i] ?? 0) > $best) { $best = $forecast['need'][$i]; $peakIndex = $i; }
        $prodWeeks = 0;
        if ($peakIndex !== null) for ($i = 0; $i < $peakIndex; $i++) if ($forecast['weeks'][$i]['working_days'] > 0) $prodWeeks++;
        $places = $this->freezerPlaces();
        $now = round($this->repo->stockPallets(), 1);
        if ($range['unapproved']) $warnings[] = ['code' => 'plan_unapproved', 'text' => 'Plan logistyki niezatwierdzony dla tyg. ' . implode(', ', $range['unapproved']) . ' — prognoza wstępna.'];
        if ($range['beyond']) $warnings[] = ['code' => 'beyond_plan', 'text' => 'Koniec okresu poza planem logistyki (do ' . date('d.m.Y', strtotime($forecast['params']['to'])) . ') — prognoza tylko do tego dnia.'];
        $freezer = ['places' => $places, 'now' => $now, 'after' => round($now + max(0.0, $pallets), 1), 'ok' => $places > 0 ? $now + max(0.0, $pallets) <= $places : null];
        return $this->head($p, $today) + [
            'items' => $rows,
            'totals' => ['extra_cartons' => (int) round($extra), 'extra_pallets' => round($pallets, 1), 'extra_value_eur' => round($value, 0),
                'per_week' => $prodWeeks > 0 ? (int) ceil(max(0.0, $extra) / $prodWeeks) : null, 'prod_weeks' => $prodWeeks,
                'peak_week' => $peakIndex !== null ? $forecast['weeks'][$peakIndex]['iso_week'] : null,
                'normal_min' => (int) round(array_sum(array_column($rows, 'normal_min'))), 'normal_max' => (int) round(array_sum(array_column($rows, 'normal_max'))),
                'min' => (int) round(array_sum(array_column($rows, 'min'))), 'max' => (int) round(array_sum(array_column($rows, 'max')))],
            'freezer' => $freezer, 'weeks' => $range['weeks'], 'warnings' => $warnings,
        ];
    }

    /** Nowy okres (projekt) ze stanami z prognozy planu logistyki. */
    public function create(array $input, ?int $userId): array
    {
        $head = $this->validate($input, null);
        [$items, $forecast] = $this->fromForecast($head['date_from'], $head['date_to']);
        if (!$items) throw new CustomException('Brak produktów MTS z prognozą w tym okresie.', 'errors', 422);
        $id = $this->repo->save(null, $head, $items, $userId);
        return $this->show($id, $forecast);
    }

    /**
     * Zmiana projektu : nazwa / daty (nowe daty → stany od nowa z prognozy, także « recompute ») albo stany produktów
     * w kartonach (items : item_id, min, max). Okres zatwierdzony najpierw « Cofnij zatwierdzenie ».
     */
    public function update(int $id, array $input): array
    {
        $p = $this->repo->period($id) ?? throw new CustomException('Nie znaleziono okresu.', 'errors', 404);
        if ($p['status'] === 'approved') throw new CustomException('Okres zatwierdzony : najpierw « Cofnij zatwierdzenie ».', 'errors', 409);
        $head = $this->validate($input + ['name' => $p['name'], 'date_from' => $p['date_from'], 'date_to' => $p['date_to']], $id);
        $items = null;
        $forecast = null;
        if ($head['date_from'] !== $p['date_from'] || $head['date_to'] !== $p['date_to'] || !empty($input['recompute'])) {
            [$items, $forecast] = $this->fromForecast($head['date_from'], $head['date_to']);
        } elseif (isset($input['items']) && is_array($input['items'])) {
            $products = [];
            foreach ($this->repo->mtsProducts() as $mp) $products[(int) $mp['item_id']] = $mp;
            $current = array_column($this->repo->items($id), null, 'id_item');
            $edits = [];
            foreach ($input['items'] as $e) $edits[(int) ($e['item_id'] ?? 0)] = $e;
            $items = [];
            foreach ($current as $itemId => $row) {
                $ppc = max(1, (int) ($products[(int) $itemId]['ppc'] ?? 1));
                $min = (float) $row['stock_min'];
                $max = (float) $row['stock_max'];
                if (isset($edits[(int) $itemId])) {
                    $e = $edits[(int) $itemId];
                    $cMin = self::number($e['min'] ?? null);
                    $cMax = self::number($e['max'] ?? null);
                    $name = $products[(int) $itemId]['name'] ?? ('#' . $itemId);
                    if ($cMin === null || $cMax === null || $cMin < 0 || $cMax < 0) throw new CustomException("{$name} : podaj stany min. i maks. w kartonach.", 'errors', 422);
                    if ($cMax < $cMin) throw new CustomException("{$name} : stan maks. mniejszy niż min.", 'errors', 422);
                    [$min, $max] = [$cMin * $ppc, $cMax * $ppc];
                }
                $items[] = ['item_id' => (int) $itemId, 'stock_min' => $min, 'stock_max' => $max, 'normal_min' => $row['normal_min'], 'normal_max' => $row['normal_max'],
                    'forecast_avg' => $row['forecast_avg'], 'forecast_peak' => $row['forecast_peak']];
            }
        }
        $this->repo->save($id, $head, $items, null);
        return $this->show($id, $forecast);
    }

    /** Zatwierdzenie : stany wyjątkowe obowiązują w dniach okresu ; okresy zatwierdzone nie mogą się nakładać. */
    public function approve(int $id, ?int $userId): array
    {
        $p = $this->repo->period($id) ?? throw new CustomException('Nie znaleziono okresu.', 'errors', 404);
        if ($p['status'] === 'approved') return $this->show($id);
        if (!$this->repo->items($id)) throw new CustomException('Okres bez produktów.', 'errors', 422);
        if ($p['date_to'] < date('Y-m-d')) throw new CustomException('Okres już minął.', 'errors', 422);
        $o = $this->repo->overlapping($p['date_from'], $p['date_to'], $id);
        if ($o) throw new CustomException('Okres nakłada się na zatwierdzony « ' . $o['name'] . ' » (' . date('d.m.Y', strtotime($o['date_from'])) . ' – ' . date('d.m.Y', strtotime($o['date_to'])) . ').', 'errors', 409);
        $this->repo->setStatus($id, 'approved', $userId);
        return $this->show($id);
    }

    /** Cofnięcie zatwierdzenia (projekt) : od razu wracają stany normalne. */
    public function reopen(int $id): array
    {
        $this->repo->period($id) ?? throw new CustomException('Nie znaleziono okresu.', 'errors', 404);
        $this->repo->setStatus($id, 'draft', null);
        return $this->show($id);
    }

    public function delete(int $id): void
    {
        $this->repo->period($id) ?? throw new CustomException('Nie znaleziono okresu.', 'errors', 404);
        $this->repo->delete($id);
    }

    // --- Pomocnicze -----------------------------------------------------------------------------------

    /** @return array{0: list<array>, 1: array} stany z prognozy (jednostka bazowa) i prognoza */
    private function fromForecast(string $from, string $to): array
    {
        $forecast = $this->forecasts->forecast();
        $range = $this->forecasts->periodLevels($from, $to, $forecast);
        $products = [];
        foreach ($this->repo->mtsProducts() as $mp) $products[(int) $mp['item_id']] = $mp;
        $items = [];
        foreach ($range['levels'] as $itemId => $l) {
            $mp = $products[$itemId] ?? null;
            if ($mp === null) continue;
            $ppc = max(1, (int) $mp['ppc']);
            $items[] = ['item_id' => $itemId, 'stock_min' => $l['min'] * $ppc, 'stock_max' => $l['max'] * $ppc,
                'normal_min' => $mp['normal_stock_min'], 'normal_max' => $mp['normal_stock_max'], 'forecast_avg' => $l['avg'], 'forecast_peak' => $l['peak']];
        }
        return [$items, $forecast];
    }

    private function validate(array $in, ?int $id): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) throw new CustomException('Nazwa okresu : od 1 do 80 znaków.', 'errors', 422);
        $from = (string) ($in['date_from'] ?? '');
        $to = (string) ($in['date_to'] ?? '');
        foreach ([$from, $to] as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) throw new CustomException('Podaj daty od i do.', 'errors', 422);
        if ($to < $from) throw new CustomException('Data « do » przed datą « od ».', 'errors', 422);
        if ((strtotime($to) - strtotime($from)) / 86400 > 366) throw new CustomException('Okres najwyżej rok.', 'errors', 422);
        return ['name' => $name, 'date_from' => $from, 'date_to' => $to];
    }

    private function head(array $p, string $today): array
    {
        $status = match (true) {
            $p['status'] !== 'approved' => 'draft',
            $today < $p['date_from'] => 'planned',
            $today > $p['date_to'] => 'ended',
            default => 'active',
        };
        return ['id' => (int) $p['id'], 'name' => $p['name'], 'date_from' => $p['date_from'], 'date_to' => $p['date_to'], 'status' => $status,
            'approved_at' => $p['approved_at'], 'approved_by' => $p['approved_by_name'] ?? null,
            'days_to_start' => $status === 'planned' ? (int) round((strtotime($p['date_from']) - strtotime($today)) / 86400) : null,
            'back_to_normal' => date('Y-m-d', strtotime($p['date_to'] . ' +1 day'))];
    }

    private function freezerPlaces(): int
    {
        return max(0, (int) $this->params->get('stock.freezer_pallet_places', '0'));
    }

    private static function number(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        $s = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], (string) $v);
        return is_numeric($s) ? (float) $s : -1.0;
    }

    private static function n(float|int $v): string
    {
        return number_format((float) $v, 0, ',', abs((float) $v) >= 10000 ? ' ' : '');
    }
}
