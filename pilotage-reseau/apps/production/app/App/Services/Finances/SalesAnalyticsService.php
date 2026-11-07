<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\SalesAnalyticsRepository;
use App\Services\Dashboard\ProfitabilityService;
use DateTimeImmutable;

/**
 * Analiza sprzedaży (WDT). [254] Strona « czy sprzedajemy więcej niż rok temu ? » (makiety 32 + 34) : werdykt,
 * przyciski działów (sprzedaż, zmiana vs rok wcześniej, marża działu z Wyniku grup), miesiące obok tych samych
 * miesięcy rok wcześniej, produkty w górę / w dół, alarm zbyt niskiego ostatniego miesiąca.
 */
class SalesAnalyticsService
{
    /** Marża działu poniżej tej wartości = czerwona (między nią a celem działu = pomarańczowa). */
    public const MARGIN_LOW = 10.0;
    /** Ostatni zamknięty miesiąc tyle % poniżej roku wcześniej (i rok wcześniej ≥ ALERT_MIN €) → alarm « czy WDT wystawione ? ». */
    public const ALERT_DROP = -30.0;
    public const ALERT_MIN = 1000.0;
    private const MONTHS_SHORT = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
    private const MONTHS_LONG = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(
        private SalesAnalyticsRepository $repo,
        private ProfitabilityService $profitability
    ) {
    }

    /**
     * Okres i okres porównawczy (te same dni rok wcześniej). Zakres sięgający dziś kończy się dziś — bieżący
     * miesiąc porównany z tymi samymi dniami rok wcześniej, nie z całym miesiącem.
     * @return array{from: string, to: string, prev_from: string, prev_to: string, partial: bool, empty: bool}
     */
    public static function bounds(string $ymFrom, string $ymTo, DateTimeImmutable $today): array
    {
        [$from, $to] = SalesAnalyticsRepository::bounds($ymFrom, $ymTo);
        $now = $today->format('Y-m-d');
        $partial = $from <= $now && $to >= $now;
        if ($to > $now) $to = $now;
        return ['from' => $from, 'to' => $to, 'prev_from' => self::yearBefore($from), 'prev_to' => self::yearBefore($to),
            'partial' => $partial, 'empty' => $to < $from];
    }

    /** Ta sama data rok wcześniej (29 lutego → 28 lutego). */
    public static function yearBefore(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $days = (int) date('t', mktime(0, 0, 0, $m, 1, $y - 1));
        return sprintf('%04d-%02d-%02d', $y - 1, $m, min($d, $days));
    }

    public static function monthShort(string $ym): string
    {
        return self::MONTHS_SHORT[(int) substr($ym, 5, 2) - 1];
    }

    public static function monthLong(string $ym): string
    {
        return self::MONTHS_LONG[(int) substr($ym, 5, 2) - 1];
    }

    /** Zmiana w % (null, gdy rok wcześniej nic). */
    public static function change(float $now, float $before): ?float
    {
        return $before > 0 ? ($now / $before - 1) * 100 : null;
    }

    /** Oś wykresu : okrągły szczyt i najwyżej 5 przedziałów (0, 50 tys., 100 tys.…). @return array{top: float, ticks: list<float>} */
    public static function axis(float $max): array
    {
        if ($max <= 0) return ['top' => 1.0, 'ticks' => [0.0]];
        // Najmniejszy okrągły krok (1, 2, 2,5, 5 × 10ⁿ) dający najwyżej 5 przedziałów.
        $raw = $max / 5;
        $mag = 10 ** floor(log10($raw));
        $norm = $raw / $mag;
        $step = ($norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 2.5 ? 2.5 : ($norm <= 5 ? 5 : 10)))) * $mag;
        $top = $step * ceil($max / $step);
        $ticks = [];
        for ($v = 0.0; $v <= $top + $step / 2; $v += $step) $ticks[] = $v;
        return ['top' => $top, 'ticks' => $ticks];
    }

    /** Kolor marży działu : ok (≥ cel), mid, bad (< MARGIN_LOW). */
    public static function marginLevel(?float $pct, float $target): ?string
    {
        if ($pct === null) return null;
        return $pct >= $target ? 'ok' : ($pct >= self::MARGIN_LOW ? 'mid' : 'bad');
    }

    /**
     * Wszystkie dane strony. Marże działów tylko dla całej firmy (bez filtra klienta : personel nie dzieli się na klientów).
     * @return array<string, mixed>
     */
    public function overview(string $ymFrom, string $ymTo, ?int $customerId = null, ?int $sectionId = null, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $b = self::bounds($ymFrom, $ymTo, $today);
        if ($b['empty']) return ['bounds' => $b, 'empty' => true];

        $cur = $this->repo->getSummary($b['from'], $b['to'], $customerId, $sectionId);
        $prev = $this->repo->getSummary($b['prev_from'], $b['prev_to'], $customerId, $sectionId);
        $months = $this->months($b, $customerId, $sectionId, $today);
        $sections = $this->sections($b, $ymFrom, $ymTo, $customerId, $today);
        $products = $this->products($b, $customerId, $sectionId);

        $selected = null;
        if ($sectionId !== null) {
            foreach ($sections['items'] as $item) if ($item['id'] === $sectionId) $selected = $item;
            $selected ??= ['id' => $sectionId, 'name' => $this->sectionName($sectionId), 'margin_pct' => null];
        }
        return [
            'bounds' => $b,
            'empty' => false,
            'total' => ['value' => $cur['total_value'], 'prev' => $prev['total_value'], 'diff' => $cur['total_value'] - $prev['total_value'],
                'change' => self::change($cur['total_value'], $prev['total_value']), 'cartons' => $cur['total_cartons'], 'prev_cartons' => $prev['total_cartons']],
            'months' => $months['rows'],
            'axis' => $months['axis'],
            'alert' => $months['alert'],
            'sections' => $sections,
            'selected' => $selected,
            'movers' => $products['movers'],
            'products' => $products['rows'],
            'label' => self::rangeLabel($b),
        ];
    }

    /** « sty – paź 2026 » / « lis 2025 – paź 2026 » / « listopad 2026 (do 5.11) ». */
    public static function rangeLabel(array $b): string
    {
        $f = substr($b['from'], 0, 7); $t = substr($b['to'], 0, 7);
        $until = $b['partial'] ? ' (do ' . (int) substr($b['to'], 8, 2) . '.' . substr($b['to'], 5, 2) . ')' : '';
        if ($f === $t) return self::monthLong($f) . ' ' . substr($f, 0, 4) . $until;
        $left = self::monthShort($f) . (substr($f, 0, 4) === substr($t, 0, 4) ? '' : ' ' . substr($f, 0, 4));
        return $left . ' – ' . self::monthShort($t) . ' ' . substr($t, 0, 4) . $until;
    }

    /** Miesiące zakresu : teraz i ten sam miesiąc rok wcześniej, oś, alarm ostatniego zamkniętego miesiąca. */
    private function months(array $b, ?int $customerId, ?int $sectionId, DateTimeImmutable $today): array
    {
        $cur = array_column($this->repo->getMonthlyRevenue($b['from'], $b['to'], $customerId, $sectionId), 'total_value', 'period');
        $prev = array_column($this->repo->getMonthlyRevenue($b['prev_from'], $b['prev_to'], $customerId, $sectionId), 'total_value', 'period');
        $rows = [];
        $max = 0.0;
        $currentYm = $today->format('Y-m');
        for ($d = new DateTimeImmutable(substr($b['from'], 0, 7) . '-01'); $d->format('Y-m') <= substr($b['to'], 0, 7); $d = $d->modify('+1 month')) {
            $ym = $d->format('Y-m');
            $py = self::yearBefore($ym . '-01');
            $now = (float) ($cur[$ym] ?? 0); $before = (float) ($prev[substr($py, 0, 7)] ?? 0);
            $max = max($max, $now, $before);
            $rows[] = ['ym' => $ym, 'label' => self::monthShort($ym), 'year' => substr($ym, 0, 4), 'value' => $now, 'prev' => $before,
                'change' => self::change($now, $before), 'partial' => $ym === $currentYm];
        }
        // Alarm : ostatni zamknięty miesiąc zakresu dużo poniżej roku wcześniej (brakujące WDT ?).
        $alert = null;
        $closed = array_values(array_filter($rows, fn($r) => !$r['partial']));
        $last = $closed ? $closed[count($closed) - 1] : null;
        if ($last !== null && $last['prev'] >= self::ALERT_MIN && $last['change'] !== null && $last['change'] <= self::ALERT_DROP
            && $last['ym'] >= (new DateTimeImmutable($currentYm . '-01'))->modify('-2 months')->format('Y-m')) {
            $alert = $last + ['name' => self::monthLong($last['ym'])];
        }
        return ['rows' => $rows, 'axis' => self::axis($max), 'alert' => $alert];
    }

    /**
     * Przyciski działów : sprzedaż teraz / rok wcześniej (z filtrem klienta), marża działu (Wynik grup, cała firma),
     * odznaki (najwięcej sprzedaje, najszybciej rośnie, najlepsza marża, traci) i miejsca w rankingach.
     */
    private function sections(array $b, string $ymFrom, string $ymTo, ?int $customerId, DateTimeImmutable $today): array
    {
        $margins = $this->profitability->range(substr($b['from'], 0, 7), substr($b['to'], 0, 7), $today);
        $groups = $margins['groups'];
        $cur = []; $prev = [];
        foreach ($this->repo->getSectionTotals($b['from'], $b['to'], $customerId) as $r) if ($r['section_id'] !== null) $cur[$r['section_id']] = $r;
        foreach ($this->repo->getSectionTotals($b['prev_from'], $b['prev_to'], $customerId) as $r) if ($r['section_id'] !== null) $prev[$r['section_id']] = $r;
        $withMargin = $customerId === null;
        $items = [];
        // Działy produkcyjne = grupy Wyniku grup (działy wspólne — administracja, pakowanie — bez przycisku).
        foreach ($groups as $id => $g) {
            $value = (float) ($cur[$id]['total_value'] ?? 0);
            $before = (float) ($prev[$id]['total_value'] ?? 0);
            if ($value <= 0 && $before <= 0) continue;
            $items[] = ['id' => (int) $id, 'name' => $g['name'], 'value' => $value, 'prev' => $before, 'change' => self::change($value, $before),
                'products' => (int) ($cur[$id]['products'] ?? 0),
                'margin_pct' => $withMargin ? $g['margin_pct'] : null, 'margin_eur' => $withMargin ? $g['contribution'] : null,
                'gross_pct' => $withMargin ? $g['gross_pct'] : null, 'margin_target' => (float) $g['margin_target'],
                'margin_level' => $withMargin ? self::marginLevel($g['margin_pct'], (float) $g['margin_target']) : null, 'badges' => []];
        }
        usort($items, fn($a, $b) => $b['value'] <=> $a['value']);
        $salesRank = self::ranks($items, 'value'); $marginRank = self::ranks($items, 'margin_pct'); $growthRank = self::ranks($items, 'change');
        foreach ($items as &$item) {
            $item['rank_sales'] = $salesRank[$item['id']] ?? null;
            $item['rank_margin'] = $marginRank[$item['id']] ?? null;
            if ($item['rank_sales'] === 1 && count($items) > 1) $item['badges'][] = 'sales';
            if (($growthRank[$item['id']] ?? null) === 1 && count($items) > 1 && $item['change'] > 0) $item['badges'][] = 'growth';
            if ($item['rank_margin'] === 1 && count($items) > 1 && $item['margin_pct'] > 0) $item['badges'][] = 'margin';
            if ($item['margin_pct'] !== null && $item['margin_pct'] < 0) $item['badges'][] = 'loss';
            $item['fastest'] = in_array('growth', $item['badges'], true);
        }
        unset($item);
        $revenue = array_sum(array_map(fn($g) => (float) $g['revenue'], $groups));
        $contribution = array_sum(array_map(fn($g) => (float) $g['contribution'], $groups));
        $target = $groups ? (float) reset($groups)['margin_target'] : 40.0;
        $companyMargin = $withMargin && $revenue > 0 ? $contribution / $revenue * 100 : null;
        // Przycisk « Cała firma » : sprzedaż bez filtra działu (nawet gdy wybrany jest dział).
        $all = (float) $this->repo->getSummary($b['from'], $b['to'], $customerId)['total_value'];
        $allPrev = (float) $this->repo->getSummary($b['prev_from'], $b['prev_to'], $customerId)['total_value'];
        return ['items' => $items, 'count' => count($items), 'with_margin' => $withMargin,
            'company_value' => $all, 'company_change' => self::change($all, $allPrev),
            'company_margin' => $companyMargin, 'company_level' => self::marginLevel($companyMargin, $target),
            'warnings' => $withMargin ? $margins['warnings'] : []];
    }

    /** Miejsce działu w rankingu (1 = najwyżej) ; działy bez wartości pominięte. @return array<int, int> id => miejsce */
    private static function ranks(array $items, string $key): array
    {
        $sorted = array_values(array_filter($items, fn($i) => $i[$key] !== null));
        usort($sorted, fn($a, $b) => $b[$key] <=> $a[$key]);
        $out = [];
        foreach ($sorted as $n => $i) $out[$i['id']] = $n + 1;
        return $out;
    }

    /** Produkty : teraz i rok wcześniej, 5 największych wzrostów i spadków w €, tabela wszystkich sprzedanych teraz. */
    private function products(array $b, ?int $customerId, ?int $sectionId): array
    {
        $cur = array_column($this->repo->getAverageProductSales($b['from'], $b['to'], $customerId, $sectionId), null, 'product_id');
        $prev = array_column($this->repo->getAverageProductSales($b['prev_from'], $b['prev_to'], $customerId, $sectionId), null, 'product_id');
        $all = [];
        foreach ($cur + $prev as $id => $p) {
            $now = (float) ($cur[$id]['total_value'] ?? 0); $before = (float) ($prev[$id]['total_value'] ?? 0);
            $all[$id] = ['product_id' => (int) $id, 'name' => $p['product_name'], 'section' => $p['section_name'], 'category' => $p['category_name'],
                'value' => $now, 'prev' => $before, 'diff' => $now - $before, 'change' => self::change($now, $before),
                'cartons' => (int) ($cur[$id]['total_quantity'] ?? 0), 'months' => (int) ($cur[$id]['months_count'] ?? 0), 'new' => $before <= 0];
        }
        $up = array_values(array_filter($all, fn($p) => $p['diff'] > 0.5));
        usort($up, fn($a, $b) => $b['diff'] <=> $a['diff']);
        $down = array_values(array_filter($all, fn($p) => $p['diff'] < -0.5));
        usort($down, fn($a, $b) => $a['diff'] <=> $b['diff']);
        $rows = array_values(array_filter($all, fn($p) => $p['value'] > 0));
        usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
        $max = $rows ? $rows[0]['value'] : 0;
        foreach ($rows as &$r) $r['share'] = $max > 0 ? $r['value'] / $max * 100 : 0;
        unset($r);
        return ['movers' => ['up' => self::bars(array_slice($up, 0, 5)), 'down' => self::bars(array_slice($down, 0, 5))], 'rows' => $rows];
    }

    /** Długość paska zmiany w % największej zmiany listy. */
    private static function bars(array $list): array
    {
        $max = 0.0;
        foreach ($list as $p) $max = max($max, abs($p['diff']));
        return array_map(fn($p) => $p + ['bar' => $max > 0 ? abs($p['diff']) / $max * 100 : 0], $list);
    }

    private function sectionName(int $id): string
    {
        foreach ($this->repo->getSections() as $s) if ((int) $s['id'] === $id) return $s['name'];
        return 'Dział';
    }

    /**
     * Zwraca domyślny zakres dat: ostatnie 12 miesięcy.
     */
    public function getDefaultDateRange(?int $customerId = null): array
    {
        $range = $this->repo->getAvailableDateRange($customerId);
        $maxDate = $range['date_max'] ?? date('Y-m');

        // 12 miesięcy wstecz od max daty
        $maxTs = strtotime($maxDate . '-01');
        $minTs = strtotime('-11 months', $maxTs);

        return [
            'from' => date('Y-m', $minTs),
            'to'   => $maxDate,
        ];
    }

    /**
     * KPI podsumowania dla zakresu.
     */
    public function getSummary(string $dateFrom, string $dateTo, ?int $customerId = null, ?int $sectionId = null): array
    {
        return $this->repo->getSummary($dateFrom, $dateTo, $customerId, $sectionId);
    }

    /** Sprzedaż wg działów : kartony, wartość, udział w wartości zakresu. */
    public function getSectionTotals(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $rows = $this->repo->getSectionTotals($dateFrom, $dateTo, $customerId);
        $total = array_sum(array_map(fn($r) => (float) $r['total_value'], $rows));
        return array_map(function ($r) use ($total) {
            $r['total_value'] = round((float) $r['total_value'], 2);
            $r['share'] = $total > 0 ? round((float) $r['total_value'] / $total * 100, 1) : null;
            return $r;
        }, $rows);
    }

    public function getSections(): array
    {
        return $this->repo->getSections();
    }

    /**
     * Dane wykresu miesięcznego przychodu (labels + quantity_values + value_values).
     */
    public function getMonthlyChartData(string $dateFrom, string $dateTo, ?int $customerId = null, ?int $sectionId = null): array
    {
        $rows = $this->repo->getMonthlyRevenue($dateFrom, $dateTo, $customerId, $sectionId);

        $labels  = [];
        $values  = [];
        $cartons = [];

        foreach ($rows as $row) {
            $labels[]  = $row['period'];
            $values[]  = round((float)$row['total_value'], 2);
            $cartons[] = (int)$row['total_cartons'];
        }

        return compact('labels', 'values', 'cartons');
    }

    /**
     * Średnia miesięczna sprzedaż produktów.
     */
    public function getAverageProductSales(string $dateFrom, string $dateTo, ?int $customerId = null, ?int $sectionId = null): array
    {
        $rows = $this->repo->getAverageProductSales($dateFrom, $dateTo, $customerId, $sectionId);

        return array_map(function ($row) {
            $row['avg_quantity'] = round((float)$row['avg_quantity'], 2);
            $row['avg_value']    = round((float)$row['avg_value'], 2);
            $row['total_value']  = round((float)$row['total_value'], 2);
            return $row;
        }, $rows);
    }

    /**
     * Trend sprzedaży wybranego produktu.
     */
    public function getProductTrend(int $productId, string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $product = $this->repo->getProductById($productId);
        $rows    = $this->repo->getProductSalesTrend($productId, $dateFrom, $dateTo, $customerId);

        $labels   = [];
        $quantities = [];
        $values   = [];

        foreach ($rows as $row) {
            $labels[]     = $row['period'];
            $quantities[] = (float)$row['quantity'];
            $values[]     = round((float)$row['value'], 2);
        }

        return [
            'product'    => $product,
            'labels'     => $labels,
            'quantities' => $quantities,
            'values'     => $values,
        ];
    }

    public function getFilterableCustomers(): array
    {
        return $this->repo->getFilterableCustomers();
    }
}

