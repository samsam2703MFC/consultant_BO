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
