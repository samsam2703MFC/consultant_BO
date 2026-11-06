<?php
declare(strict_types=1);

namespace App\Services\Forecast;

use App\Exceptions\CustomException;
use App\Repositories\Forecast\ForecastRepository;
use App\Services\Finances\StorePrice\PanelApiClient;

/**
 * Gamy sezonowe panelu TF Buddy [220] — tylko odczyt (GET), nic nie jest zmieniane w panelu :
 *  - /product-availability-periods : gamy z datami (cykliczne : from_md → to_md, także przez Nowy Rok) ;
 *  - /product-availability-periods/{id}/products : produkty sklepów w gamie ;
 *  - /material-suppliers (SL Food) → /material-suppliers/{id}/catalog/products : SKU naszych produktów w panelu
 *    (SKU = final_product.id) — prognoza bierze tylko produkty z tego katalogu (« tylko producent SL Food ») ;
 *  - /material-suppliers/{id}/analytics/raw-material-sales-all-shops?from=…&to=… : sprzedaż naszych produktów we wszystkich
 *    sklepach (sztuki) na tydzień — tygodnie trendu (ostatnie i rok temu) i tygodnie gam sezonowych rok temu ; tydzień
 *    zamknięty raz odczytany zostaje w pamięci (następny odczyt pyta tylko o nowe tygodnie).
 * Gama sezonowa = aktywna, nie dłuższa niż SEASON_MAX_DAYS dni ; gamy całoroczne (np. « Gamme Standard ») dają listę
 * produktów dostępnych cały rok — te nie są sezonowe, nawet gdy są też w gamie sezonowej. Odczyt zapisany w
 * n_forecast_panel_read (prognoza czyta ostatni udany ; odczyt co poniedziałek przy otwarciu strony albo na żądanie).
 */
final class PanelSeasonService
{
    public const SEASON_MAX_DAYS = 200;
    /** Odczyt starszy niż tyle godzin : strona czyta panel od nowa (gdy konto API jest skonfigurowane). */
    public const STALE_HOURS = 24 * 7;
    /** Najwięcej tygodni sprzedaży pytanych w jednym odczycie (reszta przy następnym). */
    public const MAX_SALES_CALLS = 40;
    /** Czas na tygodnie sprzedaży w jednym odczycie (s) ; reszta przy następnym. */
    public const SALES_SECONDS = 120;

    public function __construct(private PanelApiClient $api, private ForecastRepository $repo)
    {
    }

    public function configured(): bool
    {
        return $this->api->configured();
    }

    /** Ostatni udany odczyt (dane) albo null ; stale = starszy niż tydzień albo sprzed ostatniego poniedziałku 6:00. */
    public function last(?int $now = null): ?array
    {
        $row = $this->repo->lastPanelRead();
        if ($row === null) return null;
        $data = json_decode((string) $row['payload'], true);
        if (!is_array($data)) return null;
        $now ??= time();
        $monday = strtotime('monday this week 06:00', $now);
        if ($monday > $now) $monday -= 7 * 86400;
        $read = strtotime((string) $row['read_at']);
        return $data + ['read_at' => $row['read_at'], 'read_by' => $row['read_by_name'] ?? null,
            'stale' => $read < $monday || $read < $now - self::STALE_HOURS * 3600];
    }

    /** Ostatnia próba odczytu (błąd do pokazania). */
    public function lastAttempt(): ?array
    {
        return $this->repo->lastPanelAttempt();
    }

    /** Odczyt panelu (GET) i zapis ; błąd też zapisany (bez danych), poprzedni udany odczyt zostaje. */
    public function refresh(?int $userId, ?int $now = null, int $weeks = 13, int $trendWeeks = 8): array
    {
        if (!$this->api->configured()) throw new CustomException('Konto API panelu nie jest skonfigurowane (Ustawienia › Panel TF Buddy).', 'errors', 422);
        $now ??= time();
        $at = date('Y-m-d H:i:s', $now);
        try {
            $data = $this->read(date('Y-m-d', $now), $weeks, $trendWeeks, $this->last($now)['sales'] ?? []);
        } catch (\Throwable $e) {
            $this->repo->insertPanelRead($at, $userId, false, $e->getMessage(), null);
            throw $e instanceof CustomException ? $e : new CustomException('Odczyt panelu nie udał się : ' . mb_substr($e->getMessage(), 0, 200), 'errors', 502);
        }
        $this->repo->insertPanelRead($at, $userId, true, null, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this->last($now) ?? $data;
    }

    /**
     * @param array<string, array{total:float, skus:array<string, float>}> $cache tygodnie z poprzedniego odczytu
     * @return array{supplier:array{id:int, name:string}, periods:list<array>, products:array<int, array{name:string, category:?string}>,
     *   always:list<int>, catalog:list<string>, sales:array<string, array{total:float, skus:array<string, float>}>, sales_failed:list<string>}
     */
    private function read(string $today, int $weeks, int $trendWeeks, array $cache): array
    {
        $periods = PanelApiClient::rows($this->api->get('/product-availability-periods'));
        $supplier = null;
        foreach (PanelApiClient::rows($this->api->get('/material-suppliers')) as $s) {
            if (preg_match('/s\.?\s*l\.?\s*food/i', (string) ($s['name'] ?? ''))) { $supplier = ['id' => (int) $s['id'], 'name' => trim((string) $s['name'])]; break; }
        }
        if ($supplier === null) throw new CustomException('W panelu nie ma dostawcy SL Food (/material-suppliers).', 'errors', 502);
        $catalog = [];
        foreach (PanelApiClient::rows($this->api->get('/material-suppliers/' . $supplier['id'] . '/catalog/products')) as $c) {
            $sku = trim((string) ($c['sku'] ?? ''));
            if ($sku !== '') $catalog[$sku] = true;
        }
        $active = array_values(array_filter($periods, fn($p) => (int) ($p['is_active'] ?? 1) === 1 && isset($p['id'])));
        $lists = $this->api->getMany(array_combine(array_map(fn($p) => (int) $p['id'], $active), array_map(fn($p) => '/product-availability-periods/' . (int) $p['id'] . '/products', $active)) ?: []);
        $out = [];
        $products = [];
        $always = [];
        foreach ($active as $p) {
            $id = (int) $p['id'];
            $span = SeasonMatcher::spanDays($p);
            $rows = PanelApiClient::rows($lists[$id] ?? null);
            $ids = [];
            foreach ($rows as $r) {
                if (!isset($r['id']) || (isset($r['available']) && !(int) $r['available'])) continue;
                $pid = (int) $r['id'];
                $ids[] = $pid;
                $products[$pid] ??= ['name' => trim((string) ($r['name'] ?? '')), 'category' => isset($r['category_name']) ? trim((string) $r['category_name']) : null];
            }
            $kind = $span <= 1 ? 'skip' : ($span > self::SEASON_MAX_DAYS ? 'always' : 'season');
            if ($kind === 'always') foreach ($ids as $pid) $always[$pid] = true;
            $out[] = ['id' => $id, 'name' => trim((string) ($p['name'] ?? ('#' . $id))), 'kind' => $kind, 'span_days' => $span,
                'is_recurring' => (int) ($p['is_recurring'] ?? 1), 'from_md' => (int) ($p['from_md'] ?? 0), 'to_md' => (int) ($p['to_md'] ?? 0),
                'start_date' => $p['start_date'] ?? null, 'end_date' => $p['end_date'] ?? null, 'products' => $ids, 'list_ok' => array_key_exists($id, $lists) && $lists[$id] !== null];
        }
        [$sales, $failed] = $this->sales($supplier['id'], $out, $today, $weeks, $trendWeeks, $cache);
        return ['supplier' => $supplier, 'periods' => $out, 'products' => $products, 'always' => array_keys($always), 'catalog' => array_map('strval', array_keys($catalog)),
            'sales' => $sales, 'sales_failed' => $failed];
    }

    /**
     * Sprzedaż naszych produktów w sklepach na tydzień (pn–nd) : tygodnie trendu (ostatnie i te same rok temu) i tygodnie gam
     * sezonowych okna rok temu. Tydzień zamknięty z poprzedniego odczytu nie jest pytany drugi raz ; najwyżej MAX_SALES_CALLS.
     * @return array{0: array<string, array{total:float, skus:array<string, float>}>, 1: list<string>}
     */
    private function sales(int $supplierId, array $periods, string $today, int $weeks, int $trendWeeks, array $cache): array
    {
        $start = SeasonMatcher::monday($today);
        $end = date('Y-m-d', strtotime($start . ' +' . ($weeks * 7 - 1) . ' days'));
        $need = [];
        for ($i = 1; $i <= $trendWeeks; $i++) {
            $m = date('Y-m-d', strtotime($start . " -{$i} weeks"));
            $need[$m] = true;
            $need[SeasonMatcher::lastYearWeek($m)] = true;
        }
        foreach ($periods as $p) {
            if ($p['kind'] !== 'season') continue;
            foreach (SeasonMatcher::occurrences($p, $start, $end) as $o) {
                if ($o['n1_from'] === null) continue;
                for ($m = SeasonMatcher::monday($o['n1_from']); $m <= $o['n1_to']; $m = date('Y-m-d', strtotime($m . ' +7 days'))) $need[$m] = true;
            }
        }
        ksort($need);
        $sales = [];
        $paths = [];
        foreach (array_keys($need) as $m) {
            if (date('Y-m-d', strtotime($m . ' +6 days')) >= $today) continue; // tylko tygodnie zamknięte
            if (isset($cache[$m])) { $sales[$m] = $cache[$m]; continue; }
            if (count($paths) < self::MAX_SALES_CALLS) $paths[$m] = '/material-suppliers/' . $supplierId . '/analytics/raw-material-sales-all-shops?from=' . $m . '&to=' . date('Y-m-d', strtotime($m . ' +6 days'));
        }
        $failed = [];
        $results = [];
        $deadline = microtime(true) + self::SALES_SECONDS;
        foreach (array_chunk($paths, 8, true) as $chunk) {
            if (microtime(true) > $deadline) { foreach (array_keys($chunk) as $m) $failed[] = (string) $m; continue; }
            $results += $this->api->getMany($chunk);
        }
        foreach ($results as $m => $res) {
            if ($res === null) { $failed[] = (string) $m; continue; }
            $skus = [];
            $total = 0.0;
            foreach (PanelApiClient::rows($res) as $r) {
                $q = (float) ($r['total_sold_quantity'] ?? 0);
                $sku = trim((string) ($r['catalog_product_sku'] ?? ''));
                $total += $q;
                if ($sku !== '' && $q > 0) $skus[$sku] = round(($skus[$sku] ?? 0.0) + $q, 2);
            }
            $sales[(string) $m] = ['total' => round($total, 2), 'skus' => $skus];
        }
        ksort($sales);
        sort($failed);
        return [$sales, $failed];
    }
}
