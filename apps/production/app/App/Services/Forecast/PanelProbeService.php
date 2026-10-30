<?php
declare(strict_types=1);

namespace App\Services\Forecast;

use App\Exceptions\CustomException;
use App\Services\Finances\StorePrice\PanelApiClient;
use App\Services\Organization\PageAccessService;

/**
 * Sonda API panelu TF Buddy [220] (tylko administrator, tylko GET) : struktura odpowiedzi tras potrzebnych prognozie 13 tygodni —
 * gamy sezonowe, produkty gamy, dostawca SL Food, jego katalog i przypisania, sprzedaż jego materiałów w sklepach, inwentaryzacje.
 * Zwraca tylko klucze i do 2 przykładów (wartości skrócone) ; nic nie zapisuje w panelu.
 */
final class PanelProbeService
{
    public function __construct(private PanelApiClient $api, private PageAccessService $access)
    {
    }

    public function probe(?int $actorId): array
    {
        if (!$this->access->isAdmin($actorId)) throw new CustomException('Sondę panelu uruchamia administrator.', 'errors', 403);
        if (!$this->api->configured()) throw new CustomException('Konto API panelu nie jest skonfigurowane.', 'errors', 422);
        $out = [];
        $periods = $this->read('/product-availability-periods', $out);
        $rows = PanelApiClient::rows($periods);
        $pick = null;
        foreach ($rows as $p) {
            if (preg_match('/no[eë]l|christmas|boże|swi[eę]t|kerst/iu', (string) ($p['name'] ?? ''))) { $pick = $p; break; }
        }
        $pick ??= $rows[0] ?? null;
        if ($pick && isset($pick['id'])) {
            $detail = $this->read('/product-availability-periods/' . (int) $pick['id'], $out);
            $this->read('/product-availability-periods/' . (int) $pick['id'] . '/products', $out);
            $first = PanelApiClient::rows($detail)[0] ?? (is_array($detail['products'] ?? null) ? ($detail['products'][0] ?? null) : null);
            if (is_array($first) && isset($first['id'])) $this->read('/products/' . (int) $first['id'], $out);
        }
        $suppliers = PanelApiClient::rows($this->read('/material-suppliers', $out, 8));
        $sl = null;
        foreach ($suppliers as $s) {
            if (preg_match('/s\.?\s*l\.?\s*food|slfood/i', (string) ($s['name'] ?? ''))) { $sl = (int) $s['id']; break; }
        }
        $out['_sl_food_supplier_id'] = $sl;
        if ($sl) {
            $year = (int) date('Y') - 1;
            $range = '?date_from=' . $year . '-12-01&date_to=' . $year . '-12-31';
            foreach (['/catalog-mappings', '/catalog/products', '/connected-materials', '/materials', '/analytics/raw-material-sales-all-shops',
                         '/analytics/raw-material-sales-all-shops' . $range, '/analytics/raw-material-sales' . $range, '/analytics/top-products' . $range,
                         '/analytics/summary' . $range] as $suffix) {
                $this->read('/material-suppliers/' . $sl . $suffix, $out);
            }
        }
        $shops = PanelApiClient::rows($this->read('/shops', $out, 0));
        $shop = (int) ($shops[0]['id'] ?? 0);
        if ($shop) {
            $this->read('/shops/' . $shop . '/materials/stocktakings', $out);
            $this->read('/shops/' . $shop . '/material-requisitions', $out);
        }
        return $out;
    }

    /**
     * Krok 2 : powiązanie gam z produktami SL Food — tryby dostępności katalogu SL Food (z polem availability), produkty
     * wszystkich gam, nazwy parametrów dat w sprzedaży materiałów (suma na wariant), jeden pełny wiersz sprzedaży (sklepy
     * bez nazw), szczegół produktu sklepu, sprzedaż sklepu wg produktów w grudniu N-1, szczegół inwentaryzacji (klucze).
     */
    public function deep(?int $actorId): array
    {
        if (!$this->access->isAdmin($actorId)) throw new CustomException('Sondę panelu uruchamia administrator.', 'errors', 403);
        if (!$this->api->configured()) throw new CustomException('Konto API panelu nie jest skonfigurowane.', 'errors', 422);
        $out = [];
        $try = function (string $path) use (&$out) {
            try { return $this->api->get($path); } catch (\Throwable $e) { $out['errors'][$path] = mb_substr($e->getMessage(), 0, 160); return null; }
        };
        $periods = PanelApiClient::rows($try('/product-availability-periods'));
        $out['periods'] = array_map(fn($p) => array_intersect_key($p, array_flip(['id', 'name', 'start_date', 'end_date', 'is_recurring', 'is_active', 'from_md', 'to_md'])), $periods);
        foreach ($periods as $p) {
            $rows = PanelApiClient::rows($try('/product-availability-periods/' . (int) $p['id'] . '/products'));
            $out['period_products'][(int) $p['id']] = ['count' => count($rows), 'names' => array_slice(array_map(fn($r) => mb_substr((string) ($r['name'] ?? ''), 0, 50) . ' #' . ($r['id'] ?? '?') . (isset($r['available']) && !$r['available'] ? ' (off)' : ''), $rows), 0, 25)];
        }
        $cat = PanelApiClient::rows($try('/material-suppliers/1/catalog/products'));
        $modes = [];
        foreach ($cat as $c) $modes[(string) ($c['availability_mode'] ?? 'null') . '|' . (string) ($c['stock_mode'] ?? 'null') . '|active=' . (int) ($c['is_active'] ?? 0)] = ($modes[(string) ($c['availability_mode'] ?? 'null') . '|' . (string) ($c['stock_mode'] ?? 'null') . '|active=' . (int) ($c['is_active'] ?? 0)] ?? 0) + 1;
        $out['catalog_modes'] = $modes;
        $out['catalog_not_always'] = array_slice(array_values(array_map(fn($c) => ['sku' => $c['sku'] ?? null, 'name' => mb_substr((string) ($c['name'] ?? ''), 0, 50), 'mode' => $c['availability_mode'] ?? null,
            'active' => $c['is_active'] ?? null, 'pack' => ($c['package_size'] ?? '') . ' ' . ($c['package_unit'] ?? ''), 'per_carton' => $c['packagings_per_carton'] ?? null,
            'category' => $c['category_name'] ?? null, 'availability' => mb_substr(json_encode($c['availability'] ?? null, JSON_UNESCAPED_UNICODE), 0, 400)],
            array_filter($cat, fn($c) => ($c['availability_mode'] ?? 'ALWAYS') !== 'ALWAYS'))), 0, 80);
        $out['catalog_availability_samples'] = array_slice(array_values(array_filter(array_map(fn($c) => $c['availability'] ?? null, $cat), fn($a) => $a !== null && $a !== [])), 0, 3);
        $out['catalog_categories'] = array_count_values(array_map(fn($c) => (string) ($c['category_name'] ?? '—'), $cat));
        $y = (int) date('Y') - 1;
        foreach (['', "?date_from={$y}-12-01&date_to={$y}-12-31", "?from={$y}-12-01&to={$y}-12-31", "?start_date={$y}-12-01&end_date={$y}-12-31",
                     "?dateFrom={$y}-12-01&dateTo={$y}-12-31", "?date_start={$y}-12-01&date_end={$y}-12-31", "?period_from={$y}-12-01&period_to={$y}-12-31",
                     "?start={$y}-12-01&end={$y}-12-31", "?days=30", "?period=month"] as $q) {
            $rows = PanelApiClient::rows($try('/material-suppliers/1/analytics/raw-material-sales-all-shops' . $q));
            $out['sales_param'][$q ?: '(none)'] = ['rows' => count($rows), 'sum' => round(array_sum(array_map(fn($r) => (float) ($r['total_sold_quantity'] ?? 0), $rows)), 1)];
            if ($q === '' && $rows) {
                $r = $rows[0];
                $r['shops'] = array_map(fn($s) => is_array($s) ? array_diff_key($s, ['shop_name' => 1]) : $s, array_slice((array) ($r['shops'] ?? []), 0, 3));
                $out['sales_full_row'] = $r;
            }
        }
        $shops = PanelApiClient::rows($try('/shops'));
        $shop = (int) ($shops[0]['id'] ?? 0);
        $out['shop_ids'] = array_map(fn($s) => (int) ($s['id'] ?? 0), $shops);
        if ($shop) {
            $st = PanelApiClient::rows($try("/shops/{$shop}/statistics/sales/product-category-groups?date_from={$y}-12-01&date_to={$y}-12-31"));
            $out['shop_sales_dec'] = ['rows' => count($st), 'keys' => $st ? array_keys($st[0]) : null, 'samples' => array_slice(array_map(fn($r) => self::short($r), array_values(array_filter($st, fn($r) => preg_match('/b[uû]che|galette|no[eë]l/iu', (string) ($r['product_name'] ?? $r['name'] ?? ''))))), 0, 6)];
            $takes = PanelApiClient::rows($try("/shops/{$shop}/materials/stocktakings"));
            if ($takes) {
                $d = $try("/shops/{$shop}/materials/stocktakings/" . (int) $takes[0]['id']);
                $items = is_array($d) ? (PanelApiClient::rows($d['items'] ?? $d['data']['items'] ?? $d) ?: []) : [];
                $out['stocktaking_detail'] = ['top_keys' => is_array($d) ? array_slice(array_keys($d), 0, 20) : null, 'items' => count($items), 'item_keys' => $items ? array_keys($items[0]) : null,
                    'item_sample' => $items ? self::short($items[0]) : null];
            }
        }
        $first = null;
        foreach ($periods as $p) if (preg_match('/no[eë]l/iu', (string) $p['name'])) { $first = PanelApiClient::rows($try('/product-availability-periods/' . (int) $p['id'] . '/products'))[0]['id'] ?? null; break; }
        if ($first) {
            $prod = $try('/products/' . (int) $first);
            $out['store_product'] = is_array($prod) ? ['keys' => array_slice(array_keys($prod), 0, 60), 'sample' => self::short($prod)] : null;
        }
        return $out;
    }

    /** GET : kod (ok / błąd), liczba wierszy, klucze, do $samples przykładów ze skróconymi wartościami. */
    private function read(string $path, array &$out, int $samples = 2): mixed
    {
        try {
            $res = $this->api->get($path);
        } catch (\Throwable $e) {
            $out[$path] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
            return null;
        }
        $rows = PanelApiClient::rows($res);
        $out[$path] = [
            'ok' => true,
            'type' => is_array($res) ? (array_is_list($res) ? 'list' : 'object') : gettype($res),
            'top_keys' => is_array($res) && !array_is_list($res) ? array_slice(array_keys($res), 0, 30) : null,
            'rows' => count($rows),
            'row_keys' => $rows ? array_keys($rows[0]) : null,
            'samples' => array_map(fn($r) => self::short($r), array_slice($rows, 0, $samples)),
            'object' => !$rows && is_array($res) ? self::short($res) : null,
        ];
        return $res;
    }

    private static function short(mixed $v, int $depth = 0): mixed
    {
        if (is_string($v)) return mb_substr($v, 0, 60);
        if (!is_array($v)) return $v;
        if ($depth >= 2) return array_is_list($v) ? '[' . count($v) . ' el.]' : '{' . implode(',', array_slice(array_keys($v), 0, 12)) . '}';
        $out = [];
        foreach (array_slice($v, 0, 15, true) as $k => $x) $out[$k] = self::short($x, $depth + 1);
        return $out;
    }
}
