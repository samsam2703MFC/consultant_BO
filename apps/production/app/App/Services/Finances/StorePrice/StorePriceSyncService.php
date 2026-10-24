<?php
declare(strict_types=1);

namespace App\Services\Finances\StorePrice;

use App\Exceptions\CustomException;
use App\Repositories\Finances\StorePriceRepository;

/**
 * Synchronizacja cen w sklepach z API panelu (tylko odczyt) : sklepy konta (/consultant/shops, tylko Belgia, gdy panel
 * podaje kraj), dla każdego produkty dostępne (/shops/{id}/products/available) z ceną pratiquée brutto i netto ;
 * produkt na porcje = cena porcji ÷ udział porcji (cena całej sztuki). Wynik : mediana według nazwy produktu w sieci.
 */
final class StorePriceSyncService
{
    public function __construct(private StorePriceRepository $repo, private PanelApiClient $api)
    {
    }

    /** @return array{status: string, shops: int, shops_failed: int, products: int, message: ?string, at: string} */
    public function sync(?int $userId): array
    {
        if (!$this->api->configured()) {
            throw new CustomException('Konto API panelu nie jest skonfigurowane (Ustawienia → Konto API panelu).', 'errors', 422);
        }
        $at = date('Y-m-d H:i:s');
        $run = $this->repo->syncStart($userId, $at);
        try {
            $shops = self::shops(PanelApiClient::rows($this->api->get('/consultant/shops')));
            if (!$shops) throw new CustomException('API panelu nie zwraca żadnego sklepu w Belgii dla tego konta.', 'errors', 502);
            $paths = [];
            foreach ($shops as $id => $name) $paths[$id] = '/shops/' . $id . '/products/available';
            $perShop = [];
            $failed = 0;
            foreach ($this->api->getMany($paths) as $id => $res) {
                if ($res === null) { $failed++; continue; }
                $perShop[$id] = PanelApiClient::rows($res);
            }
            if (!$perShop) throw new CustomException('API panelu nie zwróciło produktów żadnego sklepu.', 'errors', 502);
            $products = self::aggregate($perShop, $shops);
            if (!$products) throw new CustomException('Sklepy nie mają produktów z ceną.', 'errors', 502);
            $this->repo->replaceStoreProducts($products, $at);
            $status = $failed ? 'partial' : 'ok';
            $message = $failed ? $failed . ' sklep(y) bez odpowiedzi — ich ceny pominięte.' : null;
            $this->repo->syncFinish($run, $status, count($shops), $failed, count($products), $message);
            return ['status' => $status, 'shops' => count($shops), 'shops_failed' => $failed, 'products' => count($products), 'message' => $message, 'at' => $at];
        } catch (\Throwable $e) {
            $this->repo->syncFinish($run, 'failed', 0, 0, 0, $e instanceof CustomException ? $e->getMessage() : 'Błąd synchronizacji.');
            throw $e;
        }
    }

    /**
     * Sklepy z listy panelu : id => nazwa ; pomija nieaktywne i spoza Belgii (gdy kraj podany).
     *
     * @return array<int, string>
     */
    public static function shops(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = (int) (self::num($r, ['id', 'shop_id', 'id_shop']) ?? 0);
            if ($id <= 0) continue;
            foreach (['is_active', 'active'] as $k) if (isset($r[$k]) && !$r[$k]) continue 2;
            $country = strtoupper(trim((string) self::text($r, ['country_code', 'country_iso', 'country'])));
            if ($country !== '' && !in_array($country, ['BE', 'BEL', 'BELGIUM', 'BELGIQUE', 'BELGIË', 'BELGIE', 'BELGIA'], true)) continue;
            $out[$id] = self::text($r, ['name', 'base_name', 'shop_name', 'title']) ?: 'Sklep ' . $id;
        }
        return $out;
    }

    /**
     * Mediana według produktu (klucz nazwy) : netto i brutto za całą sztukę, min./maks. brutto, VAT, sklepy.
     *
     * @param array<int, list<array>> $perShop id sklepu => produkty dostępne
     * @param array<int, string> $shopNames
     */
    public static function aggregate(array $perShop, array $shopNames): array
    {
        $acc = [];
        foreach ($perShop as $shopId => $rows) {
            $seen = [];
            foreach ($rows as $l) {
                if (isset($l['is_active']) && !$l['is_active']) continue;
                $name = self::text($l, ['base_name', 'name', 'product_name']);
                $key = StoreSellabilityService::nameKey($name);
                if ($key === '' || isset($seen[$key])) continue;
                $part = 1.0;
                $size = self::num($l, ['portion_size']);
                if ((int) ($l['is_divisible'] ?? 0) === 1 && $size !== null && $size > 0 && $size <= 1) $part = $size;
                $vat = self::num($l, ['tax_value_percent', 'tax_val_perc', 'vat']);
                $gross = self::num($l, ['portion_price_gross', 'portion_price']);
                $net = self::num($l, ['portion_price_net']);
                if (($net === null || $net <= 0) && $gross !== null && $gross > 0 && $vat !== null) $net = $gross / (1 + $vat / 100);
                if (($gross === null || $gross <= 0) && $net !== null && $net > 0 && $vat !== null) $gross = $net * (1 + $vat / 100);
                if (($net === null || $net <= 0) && ($gross === null || $gross <= 0)) continue;
                $seen[$key] = true;
                $cat = self::text($l, ['base_category_name', 'category_name']);
                if ($cat === '' && isset($l['category']) && is_array($l['category'])) $cat = self::text($l['category'], ['base_name', 'name']);
                $a = &$acc[$key];
                $a['name'] ??= $name;
                $a['category'] ??= $cat !== '' ? $cat : null;
                $a['vat'][] = $vat;
                $a['shops'][] = ['id' => (int) $shopId, 'name' => $shopNames[$shopId] ?? null,
                    'gross' => $gross !== null && $gross > 0 ? round($gross / $part, 2) : null, 'net' => $net !== null && $net > 0 ? round($net / $part, 4) : null];
                unset($a);
            }
        }
        $out = [];
        foreach ($acc as $key => $a) {
            $gross = array_values(array_filter(array_column($a['shops'], 'gross'), fn($v) => $v !== null));
            $net = array_values(array_filter(array_column($a['shops'], 'net'), fn($v) => $v !== null));
            $vat = array_values(array_filter($a['vat'], fn($v) => $v !== null));
            $out[$key] = ['name' => $a['name'], 'category' => $a['category'], 'shops' => $a['shops'],
                'price_net' => $net ? round(self::median($net), 4) : null, 'price_gross' => $gross ? round(self::median($gross), 2) : null,
                'min' => $gross ? min($gross) : null, 'max' => $gross ? max($gross) : null, 'vat' => $vat ? round(self::median($vat), 2) : null];
        }
        ksort($out);
        return $out;
    }

    public static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
    }

    private static function num(array $x, array $keys): ?float
    {
        foreach ($keys as $k) if (isset($x[$k]) && is_numeric($x[$k])) return (float) $x[$k];
        return null;
    }

    private static function text(array $x, array $keys): string
    {
        foreach ($keys as $k) if (!empty($x[$k]) && is_string($x[$k])) return trim($x[$k]);
        return '';
    }
}
