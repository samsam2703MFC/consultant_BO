<?php
declare(strict_types=1);

namespace App\Services\Finances\StorePrice;

use App\Exceptions\CustomException;
use App\Repositories\Finances\StorePriceRepository;
use App\Services\Organization\PageAccessService;

/**
 * Sprzedawalność w Belgii : czy franczyzobiorca zarobi na produkcie fabryki.
 * Cena fabryczna za sztukę = cena kartonu (EUR netto) ÷ sztuk w kartonie ; cel = cena fabryczna × mnożnik (2,5) ;
 * współczynnik = cena w sklepie netto ÷ cena fabryczna za sztukę (jak w panelu : cena netto ÷ koszt).
 * Werdykt : ≥ mnożnik sprzedawalny, od limitu (2,2) na granicy, poniżej za drogi ; od rezerwy (3,2) fabryka może podnieść cenę.
 * Produkt sklepu : powiązanie ręczne albo dopasowanie po nazwie (te same słowa bez gramatur i wariantów « cru », « ind. »).
 */
final class StoreSellabilityService
{
    private const STOP = ['de', 'du', 'des', 'la', 'le', 'les', 'au', 'aux', 'a', 'et', 'en', 'd', 'l', 'x', 'cru', 'crue', 'ind', 'individuel', 'individuelle',
        'pc', 'pcs', 'szt', 'piece', 'pieces', 'pers', 'personne', 'personnes', 'g', 'gr', 'kg', 'ml', 'cl'];

    /** @var array<string, array>|null */
    private ?array $storeIndex = null;

    public function __construct(private StorePriceRepository $repo, private PageAccessService $access)
    {
    }

    /** @return array{multiplier: float, limit: float, reserve: float} */
    public function params(): array
    {
        $c = $this->repo->config();
        return ['multiplier' => (float) $c['multiplier'], 'limit' => (float) $c['limit_coef'], 'reserve' => (float) $c['reserve_coef']];
    }

    /** Klucz nazwy : małe litery bez akcentów, bez gramatur, liczb i słów wariantu ; słowa posortowane. */
    public static function nameKey(string $name): string
    {
        return implode(' ', self::tokens($name));
    }

    /** @return list<string> */
    public static function tokens(string $name): array
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'ç' => 'c', 'ć' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'ę' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'õ' => 'o', 'ś' => 's', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => "'"]);
        $s = preg_replace('/\d+(?:[.,]\d+)?\s*(?:kg|gr|g|ml|cl|cm|mm|l)\b\.?/u', ' ', $s);
        $words = preg_split('/[^a-z0-9]+/', (string) $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn($w) => !ctype_digit($w) && !in_array($w, self::STOP, true));
        $words = array_values(array_unique($words));
        sort($words);
        return $words;
    }

    public static function similarity(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        $inter = count(array_intersect($a, $b));
        return $inter / count(array_unique(array_merge($a, $b)));
    }

    /** ok / warn / bad / none (brak ceny w sklepie) */
    public static function verdict(?float $coef, array $p): string
    {
        if ($coef === null) return 'none';
        return $coef >= $p['multiplier'] ? 'ok' : ($coef >= $p['limit'] ? 'warn' : 'bad');
    }

    /**
     * Ocena produktów fabryki.
     *
     * @param list<int>|null $productIds null = wszystkie aktywne
     * @return array<int, array>
     */
    public function evaluate(?array $productIds = null): array
    {
        $p = $this->params();
        $links = $this->repo->links();
        $out = [];
        foreach ($this->repo->factoryProducts($productIds) as $f) {
            $id = (int) $f['id'];
            $carton = (float) $f['standard_price'];
            $ppc = (int) $f['ppc'];
            $piece = $carton > 0 && $ppc > 0 ? $carton / $ppc : null;
            [$store, $link] = $this->storeFor($id, (string) $f['name'], $links);
            $net = $store !== null && $store['price_net'] !== null ? (float) $store['price_net'] : null;
            // Werdykt na współczynniku pokazanym na ekranie (2 miejsca po przecinku).
            $coef = $piece !== null && $net !== null ? round($net / $piece, 2) : null;
            $vat = $store !== null && $store['vat'] !== null ? (float) $store['vat'] : null;
            $target = $piece !== null ? $piece * $p['multiplier'] : null;
            $maxFactory = $net !== null ? $net / $p['multiplier'] : null;
            $out[$id] = [
                'product_id' => $id, 'name' => $f['name'], 'category_id' => (int) $f['id_category'], 'category' => $f['category'],
                'carton' => round($carton, 2), 'ppc' => $ppc, 'piece' => $piece !== null ? round($piece, 4) : null,
                'target' => $target !== null ? round($target, 2) : null,
                'target_gross' => $target !== null && $vat !== null ? round($target * (1 + $vat / 100), 2) : null,
                'link' => $link,
                'store' => $store === null ? null : self::storeShape($store),
                'coef' => $coef !== null ? round($coef, 2) : null,
                'verdict' => $piece === null ? 'noprice' : self::verdict($coef, $p),
                'reserve' => $coef !== null && $coef >= $p['reserve'],
                'max_factory' => $maxFactory !== null ? round($maxFactory, 2) : null,
                'max_carton' => $maxFactory !== null && $ppc > 0 ? round($maxFactory * $ppc, 2) : null,
                'delta' => $maxFactory !== null && $piece !== null ? round($maxFactory - $piece, 2) : null,
            ];
        }
        return $out;
    }

    /** Produkt sklepu w odpowiedziach (cena netto i brutto za sztukę, sklepy, min./maks., VAT). */
    private static function storeShape(array $s): array
    {
        return ['key' => $s['name_key'], 'name' => $s['name'], 'category' => $s['category'], 'shops' => (int) $s['shops'],
            'net' => $s['price_net'] !== null ? (float) $s['price_net'] : null, 'gross' => $s['price_gross'] !== null ? (float) $s['price_gross'] : null,
            'min' => $s['price_gross_min'] !== null ? (float) $s['price_gross_min'] : null, 'max' => $s['price_gross_max'] !== null ? (float) $s['price_gross_max'] : null,
            'vat' => $s['vat'] !== null ? (float) $s['vat'] : null, 'id' => (int) $s['id']];
    }

    /**
     * Ocena ceny fabrycznej za sztukę wobec ceny w sklepie (działanie rozwoju, kreator) : cel × mnożnik (netto, brutto),
     * współczynnik, werdykt, maks. cena fabryczna.
     */
    public static function priceCheck(float $piece, ?array $store, array $p): array
    {
        $net = $store['net'] ?? null;
        $vat = $store['vat'] ?? null;
        $coef = $piece > 0 && $net ? round($net / $piece, 2) : null;
        return ['target' => $piece > 0 ? round($piece * $p['multiplier'], 2) : null,
            'target_gross' => $piece > 0 && $vat !== null ? round($piece * $p['multiplier'] * (1 + $vat / 100), 2) : null,
            'coef' => $coef, 'verdict' => $piece > 0 ? self::verdict($coef, $p) : 'noprice', 'reserve' => $coef !== null && $coef >= $p['reserve'],
            'max_factory' => $net ? round($net / $p['multiplier'], 2) : null];
    }

    /**
     * Rozwój produktów grupy (działania) : ceny w sklepach produktów grupy i ocena ceny każdego działania — produkt
     * istniejący : jego produkt sklepu (powiązanie albo nazwa) ; nowy produkt : dopasowanie nazwy do produktów sklepów.
     *
     * @param list<int> $productIds
     * @param list<array{id: int, final_product_id: ?int, product: ?string, selling_price_eur: float}> $actions
     */
    public function forGrowth(array $productIds, array $actions): array
    {
        $p = $this->params();
        $ids = array_merge($productIds, array_values(array_filter(array_map(fn($a) => $a['final_product_id'] ?? null, $actions))));
        $rows = $ids ? $this->evaluate(array_values(array_unique($ids))) : [];
        $products = [];
        foreach ($productIds as $id) $products[$id] = $rows[$id]['store'] ?? null;
        $out = [];
        foreach ($actions as $a) {
            if (!empty($a['final_product_id'])) {
                $store = $rows[$a['final_product_id']]['store'] ?? null;
                $link = $rows[$a['final_product_id']]['link'] ?? null;
            } else {
                [$raw, $link] = $this->storeFor(0, (string) ($a['product'] ?? ''), []);
                $store = $raw !== null ? self::storeShape($raw) : null;
            }
            $out[$a['id']] = self::priceCheck((float) $a['selling_price_eur'], $store, $p) + ['store' => $store, 'link' => $link];
        }
        $synced = $this->repo->lastSuccessfulSync();
        return ['params' => $p, 'synced_at' => $synced['started_at'] ?? null, 'products' => $products, 'actions' => $out];
    }

    /**
     * Widok « Sprzedawalność w Belgii » strony Marża kategorii : ocena produktów kategorii i liczniki według kategorii.
     */
    public function forCategories(array $result, ?int $userId): array
    {
        $ids = [];
        foreach ($result['categories'] ?? [] as $cat) foreach ($cat['products'] ?? [] as $pr) $ids[] = (int) $pr['product_id'];
        $rows = $this->evaluate($ids);
        $counts = [];
        foreach ($result['categories'] ?? [] as $cat) {
            $c = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'none' => 0];
            foreach ($cat['products'] ?? [] as $pr) {
                $v = $rows[(int) $pr['product_id']]['verdict'] ?? 'none';
                $c[$v === 'noprice' ? 'none' : $v]++;
            }
            $counts[$cat['category_id']] = $c;
        }
        return $this->context($userId) + ['products' => $rows, 'categories' => $counts];
    }

    /** Karta « Belgia » strony Marża produktu : ocena, ceny według sklepu, sklepy poniżej celu. */
    public function productCard(int $productId, ?array $analysis, ?int $userId): ?array
    {
        $row = $this->evaluate([$productId])[$productId] ?? null;
        if ($row === null) return null;
        $p = $this->params();
        $shops = [];
        if ($row['store'] !== null && $row['piece']) {
            foreach ($this->repo->shopPrices($row['store']['id']) as $s) {
                $net = $s['price_net'] !== null ? (float) $s['price_net'] : null;
                $coef = $net !== null ? round($net / $row['piece'], 2) : null;
                $shops[] = ['name' => $s['shop_name'] ?: 'Sklep ' . $s['shop_id'], 'gross' => $s['price_gross'] !== null ? (float) $s['price_gross'] : null,
                    'net' => $net, 'coef' => $coef, 'verdict' => self::verdict($coef, $p)];
            }
            usort($shops, fn($a, $b) => [$a['coef'] ?? 99, $a['name']] <=> [$b['coef'] ?? 99, $b['name']]);
        }
        $cost = null;
        $kpi = $analysis['kpi'] ?? null;
        if ($kpi && $row['ppc'] > 0 && (float) ($kpi['exchange_rate'] ?? 0) > 0) {
            $cost = round((float) $kpi['total_cost_per_carton'] / (float) $kpi['exchange_rate'] / $row['ppc'], 2);
        }
        return $this->context($userId) + ['row' => $row, 'shops' => $shops, 'below' => array_values(array_filter($shops, fn($s) => in_array($s['verdict'], ['warn', 'bad'], true))),
            'cost_piece' => $cost, 'factory_margin' => $kpi['margin_percent'] ?? null];
    }

    /** Lista produktów sklepów do powiązania (szukaj po nazwie ; najpierw najbardziej podobne). */
    public function candidates(int $productId, string $q): array
    {
        $f = $this->repo->factoryProducts([$productId])[0] ?? null;
        if ($f === null) throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        $mine = self::tokens((string) $f['name']);
        $qt = self::tokens($q);
        $rows = [];
        foreach ($this->storeIndex() as $key => $s) {
            $t = explode(' ', $key);
            if ($qt && count(array_intersect($qt, $t)) < count($qt) && mb_stripos((string) $s['name'], trim($q)) === false) continue;
            $rows[] = ['key' => $key, 'name' => $s['name'], 'category' => $s['category'], 'shops' => (int) $s['shops'],
                'gross' => $s['price_gross'] !== null ? (float) $s['price_gross'] : null, 'net' => $s['price_net'] !== null ? (float) $s['price_net'] : null,
                'score' => round(self::similarity($mine, $t), 2)];
        }
        usort($rows, fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        $links = $this->repo->links();
        return ['product' => ['id' => (int) $f['id'], 'name' => $f['name']], 'mode' => array_key_exists((int) $f['id'], $links) ? ($links[(int) $f['id']] === null ? 'excluded' : 'manual') : 'auto',
            'current' => $links[(int) $f['id']] ?? null, 'items' => array_slice($rows, 0, 60)];
    }

    /**
     * Powiązanie : mode auto (dopasowanie po nazwie), excluded (nie sprzedawany w sklepach), manual + key.
     */
    public function setLink(int $productId, array $input, ?int $userId): array
    {
        if (!$this->access->canSee($userId, '/finances/category-margin')) throw new CustomException('Brak dostępu do marż.', 'errors', 403);
        if (!$this->repo->productExists($productId)) throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        $mode = (string) ($input['mode'] ?? '');
        if ($mode === 'auto') {
            $this->repo->deleteLink($productId);
        } elseif ($mode === 'excluded') {
            $this->repo->setLink($productId, null, $userId);
        } elseif ($mode === 'manual') {
            $key = trim((string) ($input['key'] ?? ''));
            if ($key === '' || $this->repo->storeProductByKey($key) === null) throw new CustomException('Wybierz produkt sklepu z listy.', 'errors', 422);
            $this->repo->setLink($productId, $key, $userId);
        } else {
            throw new CustomException('Nieznany tryb powiązania.', 'errors', 422);
        }
        return $this->evaluate([$productId])[$productId];
    }

    /** Progi (administrator) : mnożnik, limit « na granicy », rezerwa. */
    public function saveParams(array $input, ?int $userId): array
    {
        if (!$this->access->isAdmin($userId)) throw new CustomException('Progi zmienia administrator.', 'errors', 403);
        $num = function (string $k, string $label) use ($input): float {
            $v = str_replace([' ', ','], ['', '.'], (string) ($input[$k] ?? ''));
            if (!is_numeric($v) || (float) $v < 1 || (float) $v > 10) throw new CustomException($label . ' : liczba od 1 do 10.', 'errors', 422);
            return round((float) $v, 2);
        };
        $m = $num('multiplier', 'Mnożnik franczyzy');
        $l = $num('limit', 'Na granicy od');
        $r = $num('reserve', 'Rezerwa od');
        if (!($l <= $m && $m <= $r)) throw new CustomException('Kolejność progów : na granicy ≤ mnożnik ≤ rezerwa.', 'errors', 422);
        $this->repo->saveConfig(['multiplier' => $m, 'limit_coef' => $l, 'reserve_coef' => $r, 'updated_by' => $userId]);
        return $this->params();
    }

    /** Wspólne dane widoków : progi, konto skonfigurowane, ostatnia synchronizacja, uprawnienia. */
    private function context(?int $userId): array
    {
        $c = $this->repo->config();
        return ['params' => $this->params(), 'configured' => trim((string) $c['phone']) !== '' && trim((string) $c['password_enc']) !== '',
            'sync' => $this->repo->lastSync(), 'synced' => $this->repo->lastSuccessfulSync(), 'is_admin' => $this->access->isAdmin($userId)];
    }

    /** @return array{0: ?array, 1: ?string} produkt sklepu i tryb (manual / auto / excluded / null) */
    private function storeFor(int $productId, string $name, array $links): array
    {
        $index = $this->storeIndex();
        if (array_key_exists($productId, $links)) {
            if ($links[$productId] === null) return [null, 'excluded'];
            return [$index[$links[$productId]] ?? null, 'manual'];
        }
        $key = self::nameKey($name);
        if ($key !== '' && isset($index[$key])) return [$index[$key], 'auto'];
        $mine = self::tokens($name);
        if (count($mine) < 2) return [null, null];
        // Najbliższa nazwa : te same słowa w ≥ 75 % albo wszystkie słowa fabryki + najwyżej jedno słowo sklepu (« tranche ») ;
        // tylko gdy wyraźnie lepsza od drugiej.
        $best = null; $bestScore = 0.0; $second = 0.0; $bestOk = false;
        foreach ($index as $k => $s) {
            $theirs = explode(' ', $k);
            $score = self::similarity($mine, $theirs);
            if ($score > $bestScore) {
                $second = $bestScore; $bestScore = $score; $best = $s;
                $bestOk = $score >= 0.75 || (!array_diff($mine, $theirs) && count($theirs) - count($mine) <= 1);
            } elseif ($score > $second) {
                $second = $score;
            }
        }
        return $best !== null && $bestOk && $bestScore - $second >= 0.1 ? [$best, 'auto'] : [null, null];
    }

    /** @return array<string, array> klucz nazwy => produkt sklepu */
    private function storeIndex(): array
    {
        if ($this->storeIndex === null) {
            $this->storeIndex = [];
            foreach ($this->repo->storeProducts() as $s) $this->storeIndex[$s['name_key']] = $s;
        }
        return $this->storeIndex;
    }
}
