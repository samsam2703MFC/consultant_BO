<?php
declare(strict_types=1);

namespace App\Services\Finances;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\MarginRecalcRepository;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Finances\PriceWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\DocumentDistributionService;
use App\Services\Finances\StorePrice\StoreSellabilityService;
use App\Services\Organization\PageAccessService;
use App\Services\Recipe\RecipeCostRecalculationService;

/**
 * Kreator cen [218] (Finanse › Marże i koszty › Kreator cen, makieta zatwierdzona) :
 *  1. koszty i marże po fakturach — przeliczenie receptur po bieżących cenach surowców, marża przed → po, przyczyny ;
 *  2. nowe ceny standardowe — cena celu = wyższa z : koszt pełny ÷ (1 − marża bazowa kategorii) i koszt surowców ×
 *     współczynnik kategorii ; nigdy niżej niż obecna, najwyżej + prices.client_cap_pct % naraz (reszta przy następnej
 *     zmianie), zaokrąglenie w górę do prices.client_round_eur ;
 *  3. publikacja od daty — ceny klientów : równe standardowej idą za nią, wynegocjowane o ten sam % (rabat zachowany),
 *     ten sam limit ; e-mail z cennikiem przed datą, strona franczyzy w dniu zmiany (PricePublicationService).
 * Ceny w EUR za karton (cennik standardowy) ; koszty z marż kategorii (PLN) przeliczone kursem.
 */
final class PriceWizardService
{
    private const SALES_MONTHS = 12;
    private const PAGE = '/finances/price-wizard';

    public function __construct(
        private ProductMarginService $margins,
        private PriceWizardRepository $repo,
        private MarginRecalcRepository $recalc,
        private RecipeCostRecalculationService $recipeCosts,
        private ParamRepository $params,
        private WdtSalesRepository $sales,
        private StoreSellabilityService $sellability,
        private PageAccessService $access,
        private DocumentDistributionService $distribution,
    ) {
    }

    public function assertAccess(?int $userId): void
    {
        if (!$this->access->canSee($userId, self::PAGE)) throw new CustomException('Brak dostępu do marż i cen.', 'errors', 403);
    }

    /** @return array{cap_pct: float, round_eur: float, material_coef_default: float, notice_days: int, keep_discount: bool, interval_days: int} */
    public function params(): array
    {
        $num = fn(string $code, string $def) => (float) str_replace(',', '.', (string) $this->params->get($code, $def));
        return [
            'cap_pct' => max(0.0, $num('prices.client_cap_pct', '8')),
            'round_eur' => max(0.01, $num('prices.client_round_eur', '0.05')),
            'material_coef_default' => max(0.0, $num('prices.material_coef_default', '2.5')),
            'notice_days' => max(0, (int) $num('prices.notice_days', '7')),
            'keep_discount' => (string) $this->params->get('prices.client_keep_discount', '1') === '1',
            'interval_days' => max(0, (int) $num('prices.client_interval_days', '90')),
        ];
    }

    /** Zaokrąglenie w górę do kroku (np. 0,05 €). */
    public static function roundUp(float $v, float $step): float
    {
        return round(ceil(round($v / $step, 6)) * $step, 2);
    }

    /**
     * Reguła jednej ceny standardowej (EUR / karton). status : up (do ceny celu), cap (limit podwyżki, reszta później),
     * keep (cel ≤ obecna : bez zmian, nigdy niżej), none (brak marży bazowej i współczynnika albo kosztu).
     * @return array{by_margin: ?float, by_coef: ?float, target: ?float, new: float, status: string, gap_pct: ?float}
     */
    public static function rule(float $food, float $full, float $cur, float $margin, ?float $coef, array $p): array
    {
        $byMargin = $margin > 0 && $margin < 100 && $full > 0 ? $full / (1 - $margin / 100) : null;
        $byCoef = $coef !== null && $coef > 0 && $food > 0 ? $food * $coef : null;
        $target = $byMargin === null && $byCoef === null ? null : max($byMargin ?? 0.0, $byCoef ?? 0.0);
        $out = ['by_margin' => $byMargin !== null ? round($byMargin, 2) : null, 'by_coef' => $byCoef !== null ? round($byCoef, 2) : null,
            'target' => $target !== null ? round($target, 2) : null, 'new' => $cur, 'status' => 'none', 'gap_pct' => null];
        if ($target === null || $cur <= 0) return $out;
        if ($target <= $cur + 0.005) return ['status' => 'keep', 'gap_pct' => 0.0] + $out;
        $cap = $cur * (1 + $p['cap_pct'] / 100);
        $limited = $target > $cap + 0.005;
        $new = max($cur, self::roundUp(min($target, $cap), $p['round_eur']));
        return ['new' => $new, 'status' => $limited ? 'cap' : 'up', 'gap_pct' => $limited ? round(($target - $new) / $new * 100, 1) : 0.0] + $out;
    }

    /** Najwyższa dozwolona nowa cena (limit podwyżki, zaokrąglony w górę). */
    public static function maxPrice(float $cur, array $p): float
    {
        return self::roundUp($cur * (1 + $p['cap_pct'] / 100), $p['round_eur']);
    }

    // ── Krok 1 : koszty i marże ──────────────────────────────────────────────────────────────────────────────

    /** Przeliczenie receptur po bieżących cenach surowców (ceny sprzedaży bez zmian), zapis zmian kosztu kartonu. */
    public function recalculate(?int $userId): array
    {
        $before = $this->recalc->currentCosts();
        $run = $this->recipeCosts->recalcForAll();
        $after = $this->recalc->currentCosts();
        $recipes = (int) ($run['recipes'] ?? 0);
        $saved = $this->recalc->save($before, $after, $userId, $recipes);
        $message = $saved['changed']
            ? "Przeliczono koszty {$recipes} receptur — zmiana kosztu u {$saved['changed']} z {$saved['products']} produktów."
            : "Przeliczono koszty {$recipes} receptur — ceny surowców bez zmian od ostatniego przeliczenia.";
        return ['recipes' => $recipes, 'products' => $saved['products'], 'changed' => $saved['changed'], 'message' => $message];
    }

    /** Krok 1 : faktury, ostatnie przeliczenie, marża kategorii przed → po, największe zmiany kosztu i ich przyczyny. */
    public function costs(int $months = 12): array
    {
        $runs = $this->repo->lastRuns();
        $last = $runs[0] ?? null;
        $prev = $runs[1] ?? null;
        $now = date('Y-m-d H:i:s');
        $pending = $last !== null ? $this->repo->materialChanges((string) $last['run_at'], $now) : [];
        if ($pending || $last === null) {
            $from = $last['run_at'] ?? date('Y-m-d H:i:s', strtotime('-31 days'));
            $window = ['from' => $from, 'to' => $now, 'pending' => $last !== null];
            $materials = $pending ?: $this->repo->materialChanges($from, $now);
        } else {
            $from = $prev['run_at'] ?? date('Y-m-d H:i:s', strtotime((string) $last['run_at'] . ' -31 days'));
            $window = ['from' => $from, 'to' => (string) $last['run_at'], 'pending' => false];
            $materials = $this->repo->materialChanges($from, (string) $last['run_at']);
        }

        $result = $this->margins->calculateCategoryOverview($months);
        $rate = (float) ($result['exchange_rate'] ?? 0);
        $rows = $this->recalc->last();
        $lastAt = $last['run_at'] ?? null;
        $categories = [];
        $changed = [];
        $all = ['before' => [], 'after' => []];
        foreach ($result['categories'] ?? [] as $cat) {
            $c = ['id' => (int) $cat['category_id'], 'name' => $cat['category_name'], 'target' => (float) $cat['target_margin'],
                'products' => count($cat['products']), 'changed' => 0, 'before' => [], 'after' => [], 'month_eur' => 0.0];
            foreach ($cat['products'] as $p) {
                $sale = (float) $p['sale_price_pln'];
                if ($sale <= 0) continue;
                $r = $rows[$p['product_id']] ?? null;
                $delta = $r !== null && $lastAt !== null && (string) $r['recalculated_at'] === (string) $lastAt
                    ? (float) $r['new_cost_carton'] - (float) $r['old_cost_carton'] : 0.0;
                $costNow = (float) $p['total_cost'];
                $mAfter = ($sale - $costNow) / $sale * 100;
                $mBefore = ($sale - ($costNow - $delta)) / $sale * 100;
                $c['before'][] = $mBefore;
                $c['after'][] = $mAfter;
                $all['before'][] = $mBefore;
                $all['after'][] = $mAfter;
                if (abs($delta) < 0.00005) continue;
                $c['changed']++;
                $month = $rate > 0 ? -$delta * (float) $p['avg_sold_qty_per_month'] / $rate : 0.0;
                $c['month_eur'] += $month;
                $old = (float) $r['old_cost_carton'];
                $changed[] = ['id' => (int) $p['product_id'], 'name' => $p['name'], 'category' => $cat['category_name'],
                    'old_eur' => $rate > 0 ? round($old / $rate, 2) : null, 'new_eur' => $rate > 0 ? round((float) $r['new_cost_carton'] / $rate, 2) : null,
                    'pct' => $old > 0 ? round($delta / $old * 100, 1) : null, 'qty' => round((float) $p['avg_sold_qty_per_month'], 1),
                    'month_eur' => round($month, 2), 'margin_before' => round($mBefore, 1), 'margin_after' => round($mAfter, 1), 'reasons' => []];
            }
            if (!$c['after']) continue;
            $c['before'] = round(array_sum($c['before']) / count($c['before']), 1);
            $c['after'] = round(array_sum($c['after']) / count($c['after']), 1);
            $c['gap'] = $c['target'] > 0 ? round($c['after'] - $c['target'], 1) : null;
            $c['month_eur'] = round($c['month_eur'], 2);
            $categories[] = $c;
        }
        usort($categories, fn($a, $b) => [$a['month_eur'], $a['gap'] ?? 999, $a['name']] <=> [$b['month_eur'], $b['gap'] ?? 999, $b['name']]);
        usort($changed, fn($a, $b) => [$a['month_eur'], -abs((float) $a['pct']), $a['name']] <=> [$b['month_eur'], -abs((float) $b['pct']), $b['name']]);

        // Przyczyny : surowce receptury produktu, których cena zmieniła się w oknie (największa zmiana najpierw).
        $ingredients = $this->repo->ingredientsOf(array_column($changed, 'id'));
        foreach ($changed as &$ch) {
            $reasons = [];
            foreach ($ingredients[$ch['id']] ?? [] as $mid) if (isset($materials[$mid])) $reasons[] = $materials[$mid];
            usort($reasons, fn($a, $b) => abs((float) $b['pct']) <=> abs((float) $a['pct']));
            $ch['reasons'] = array_map(fn($m) => ['name' => $m['name'], 'pct' => $m['pct']], array_slice($reasons, 0, 3));
        }
        unset($ch);

        $up = count(array_filter($materials, fn($m) => ($m['pct'] ?? 1) > 0));
        $mat = array_values($materials);
        usort($mat, fn($a, $b) => abs((float) $b['pct']) <=> abs((float) $a['pct']));
        $avg = fn(array $v) => $v ? round(array_sum($v) / count($v), 1) : null;
        return [
            'invoices' => $this->repo->openInvoices(),
            'invoices_with_prices' => $this->repo->invoicesWithPrices($window['from'], $window['to']),
            'last_run' => $last, 'window' => $window,
            'materials' => ['up' => $up, 'down' => count($materials) - $up, 'list' => array_slice($mat, 0, 30)],
            'totals' => ['products' => count($all['after']), 'changed' => count($changed),
                'margin_before' => $avg($all['before']), 'margin_after' => $avg($all['after']),
                'month_eur' => round(array_sum(array_column($changed, 'month_eur')), 2)],
            'categories' => $categories,
            'products' => array_slice($changed, 0, 60),
            'rate' => $rate,
        ];
    }

    // ── Krok 2 : nowe ceny standardowe ───────────────────────────────────────────────────────────────────────

    /** Krok 2 : kategorie (marża bazowa, współczynnik) i propozycje cen wszystkich produktów z ceną standardową. */
    public function prices(int $months = 12): array
    {
        $p = $this->params();
        $result = $this->margins->calculateCategoryOverview($months);
        $rate = (float) ($result['exchange_rate'] ?? 0);
        $rules = $this->repo->rules();
        $planned = $this->repo->plannedProducts();
        $recent = $p['interval_days'] > 0 ? $this->repo->recentStandardChanges(date('Y-m-d H:i:s', strtotime('-' . $p['interval_days'] . ' days'))) : [];
        $ids = [];
        foreach ($result['categories'] ?? [] as $cat) foreach ($cat['products'] as $pr) if (!empty($pr['has_data'])) $ids[] = (int) $pr['product_id'];
        $be = $this->belgium($ids);
        $categories = [];
        $t = ['up' => 0, 'cap' => 0, 'keep' => 0, 'none' => 0, 'planned' => 0, 'recent' => 0, 'products' => 0];
        foreach ($result['categories'] ?? [] as $cat) {
            $cid = (int) $cat['category_id'];
            $coef = array_key_exists($cid, $rules) ? $rules[$cid] : null;
            $coefEff = $coef ?? ($p['material_coef_default'] > 0 ? $p['material_coef_default'] : null);
            $margin = (float) $cat['target_margin'];
            $products = [];
            foreach ($cat['products'] as $pr) {
                if (empty($pr['has_data']) || $rate <= 0) continue;
                $id = (int) $pr['product_id'];
                $cur = round((float) $pr['sale_price_eur'], 2);
                $food = (float) $pr['foodcost'] / $rate;
                $full = (float) $pr['total_cost'] / $rate;
                $r = self::rule($food, $full, $cur, $margin, $coefEff, $p);
                $status = $r['status'];
                if (isset($planned[$id])) $status = 'planned';
                elseif (isset($recent[$id]) && in_array($status, ['up', 'cap'], true)) $status = 'recent';
                $t[$status]++;
                $t['products']++;
                $qty = (float) $pr['avg_sold_qty_per_month'];
                $products[] = ['id' => $id, 'name' => $pr['name'], 'food' => round($food, 2), 'full' => round($full, 2), 'cur' => $cur,
                    'by_margin' => $r['by_margin'], 'by_coef' => $r['by_coef'], 'target' => $r['target'], 'new' => $r['new'], 'max' => self::maxPrice($cur, $p),
                    'status' => $status, 'gap_pct' => $r['gap_pct'], 'qty' => round($qty, 1),
                    'margin_now' => $cur > 0 ? round(($cur - $full) / $cur * 100, 1) : null,
                    'margin_new' => $r['new'] > 0 ? round(($r['new'] - $full) / $r['new'] * 100, 1) : null,
                    'planned' => $planned[$id] ?? null, 'recent' => $recent[$id] ?? null,
                    'be' => $be[$id] ?? null];
            }
            if (!$products) continue;
            usort($products, fn($a, $b) => [self::rank($a['status']), $a['name']] <=> [self::rank($b['status']), $b['name']]);
            $categories[] = ['id' => $cid, 'name' => $cat['category_name'], 'margin' => $margin, 'coef' => $coef, 'coef_effective' => $coefEff,
                'products' => $products];
        }
        usort($categories, fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        $sp = $this->sellability->params();
        return ['params' => $p, 'rate' => $rate, 'categories' => $categories, 'totals' => $t,
            'be_params' => ['multiplier' => $sp['multiplier'], 'limit' => $sp['limit']]];
    }

    private static function rank(string $status): int
    {
        return ['cap' => 0, 'up' => 1, 'recent' => 2, 'planned' => 3, 'keep' => 4, 'none' => 5][$status] ?? 6;
    }

    /** Współczynnik sklepu w Belgii (cena w sklepie netto ÷ cena fabryczna za sztukę) przy obecnej cenie. @return array<int, float> */
    private function belgium(array $ids): array
    {
        if (!$ids) return [];
        try {
            $out = [];
            foreach ($this->sellability->evaluate($ids) as $id => $e) if ($e['coef'] !== null) $out[(int) $id] = (float) $e['coef'];
            return $out;
        } catch (\Throwable $e) {
            error_log('[price-wizard] belgium: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Marża bazowa i współczynnik surowców kategorii (zapis z kroku 2).
     * @param list<array{id: int, margin: mixed, coef: mixed}> $rows
     */
    public function saveCategories(array $rows, ?int $userId): int
    {
        $this->assertAccess($userId);
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || !$this->repo->categoryExists($id)) throw new CustomException('Nie znaleziono kategorii.', 'errors', 404);
            $margin = self::decimal($row['margin'] ?? null);
            if ($margin === null || $margin < 0 || $margin > 90) throw new CustomException('Marża bazowa : od 0 do 90 % (0 = bez marży bazowej).', 'errors', 422);
            $coef = self::decimal($row['coef'] ?? null);
            if ($coef !== null && ($coef < 1 || $coef > 10)) throw new CustomException('Współczynnik surowców : od 1 do 10 (puste = domyślny).', 'errors', 422);
            $this->repo->saveRule($id, round($margin, 2), $coef !== null ? round($coef, 2) : null, $userId);
            $n++;
        }
        return $n;
    }

    /** Parametry reguły (administrator) : limit podwyżki, zaokrąglenie, domyślny współczynnik, wyprzedzenie e-maila. */
    public function saveParams(array $input, ?int $userId): array
    {
        if (!$this->access->isAdmin($userId)) throw new CustomException('Parametry zmienia administrator.', 'errors', 403);
        $labels = ['cap_pct' => ['prices.client_cap_pct', 'Limit podwyżki'], 'round_eur' => ['prices.client_round_eur', 'Zaokrąglenie'],
            'material_coef_default' => ['prices.material_coef_default', 'Domyślny współczynnik'], 'notice_days' => ['prices.notice_days', 'Wyprzedzenie e-maila']];
        $values = [];
        foreach ($labels as $key => [$code, $label]) {
            if (!array_key_exists($key, $input)) continue;
            $raw = str_replace([' ', ','], ['', '.'], trim((string) $input[$key]));
            if ($err = ParamRules::rangeError($code, $raw)) throw new CustomException($label . ' : ' . $err, 'errors', 422);
            $values[$code] = (string) round((float) $raw, 2);
        }
        foreach ($values as $code => $v) $this->params->setValue($code, $v, $userId);
        return $this->params();
    }

    public function isAdmin(?int $userId): bool
    {
        return $this->access->isAdmin($userId);
    }

    // ── Krok 3 : ceny klientów, kanały, plan ─────────────────────────────────────────────────────────────────

    /**
     * Wybrane ceny standardowe → ceny klientów i klienci (kanały, efekt). Serwer sprawdza każdą cenę : produkt aktywny,
     * bez innej zaplanowanej zmiany, wyżej niż obecna, najwyżej limit podwyżki.
     * @param list<array{product_id: mixed, price: mixed, manual?: mixed}> $raw
     */
    public function build(array $raw): array
    {
        $p = $this->params();
        $wanted = [];
        foreach ($raw as $r) {
            $id = (int) ($r['product_id'] ?? 0);
            $price = self::decimal($r['price'] ?? null);
            if ($id <= 0 || $price === null) continue;
            $wanted[$id] = ['price' => round($price, 2), 'manual' => !empty($r['manual'])];
        }
        if (!$wanted) throw new CustomException('Zaznacz ceny do zmiany.', 'errors', 422);
        $std = $this->repo->standardPrices(array_keys($wanted));
        $planned = $this->repo->plannedProducts();
        $standard = [];
        foreach ($wanted as $id => $w) {
            $s = $std[$id] ?? throw new CustomException('Produkt nieaktywny albo nieznany (#' . $id . ').', 'errors', 422);
            if (isset($planned[$id])) throw new CustomException('« ' . $s['name'] . ' » ma już zaplanowaną zmianę ceny od ' . date('d.m.Y', strtotime($planned[$id]['date'])) . '.', 'errors', 409);
            $cur = round($s['price'], 2);
            if ($cur <= 0) throw new CustomException('« ' . $s['name'] . ' » nie ma ceny standardowej.', 'errors', 422);
            if ($w['price'] <= $cur + 0.004) continue;
            $max = self::maxPrice($cur, $p);
            if ($w['price'] > $max + 0.004) {
                throw new CustomException('« ' . $s['name'] . ' » : najwyżej +' . self::num($p['cap_pct'], 1) . ' % naraz (do ' . self::num($max, 2) . ' €).', 'errors', 422);
            }
            $standard[$id] = ['id' => $id, 'name' => $s['name'], 'old' => $cur, 'new' => $w['price'], 'manual' => $w['manual']];
        }
        if (!$standard) throw new CustomException('Żadna z wybranych cen nie jest wyższa od obecnej.', 'errors', 422);

        $qty = $this->monthlyCartons();
        $byClient = [];
        $items = [];
        foreach ($this->repo->clientPrices(array_keys($standard)) as $row) {
            $s = $standard[$row['id_product']];
            $cur = round($row['price'], 2);
            if (abs($cur - $s['old']) < 0.005) {
                $new = $s['new'];
            } else {
                $target = $cur * $s['new'] / $s['old'];
                if (!$p['keep_discount']) $target = max($target, $s['new']);
                $new = max($cur, self::roundUp(min($target, $cur * (1 + $p['cap_pct'] / 100)), $p['round_eur']));
            }
            if ($new <= $cur + 0.004) continue;
            $cid = $row['id_customer'];
            $month = ($new - $cur) * ($qty[$cid][$row['id_product']] ?? 0.0);
            $items[] = ['id_product' => $row['id_product'], 'id_customer' => $cid, 'old' => $cur, 'new' => $new, 'manual' => false,
                'follower' => abs($cur - $s['old']) < 0.005];
            $byClient[$cid][] = ['pct' => ($new - $cur) / $cur * 100, 'month' => $month, 'follower' => abs($cur - $s['old']) < 0.005];
        }
        $customers = $this->repo->customers(array_keys($byClient));
        $clients = [];
        foreach ($byClient as $cid => $rows) {
            $c = $customers[$cid] ?? null;
            if ($c === null) continue;
            $franchise = trim((string) ($c['franchisee_uid'] ?? '')) !== '';
            $address = $this->priceAddress($cid, $c);
            $clients[] = ['id' => $cid, 'name' => (string) $c['label'], 'franchise' => $franchise, 'email' => $address,
                'lang' => strtolower((string) ($c['communication_lang'] ?? '')) ?: 'fr',
                'prices' => count($rows), 'followers' => count(array_filter($rows, fn($r) => $r['follower'])),
                'avg_pct' => round(array_sum(array_column($rows, 'pct')) / count($rows), 1),
                'month_eur' => round(array_sum(array_column($rows, 'month')), 2)];
        }
        usort($clients, fn($a, $b) => [$b['franchise'], $b['month_eur'], $a['name']] <=> [$a['franchise'], $a['month_eur'], $b['name']]);
        $pcts = array_map(fn($s) => ($s['new'] - $s['old']) / $s['old'] * 100, $standard);
        return [
            'params' => $p,
            'standard' => array_values($standard),
            'items' => $items,
            'clients' => $clients,
            'totals' => ['products' => count($standard), 'avg_pct' => round(array_sum($pcts) / count($pcts), 1),
                'client_prices' => count($items), 'followers' => count(array_filter($items, fn($i) => $i['follower'])),
                'clients' => count($clients), 'franchise' => count(array_filter($clients, fn($c) => $c['franchise'])),
                'emails' => count(array_filter($clients, fn($c) => $c['email'] !== '')),
                'month_eur' => round(array_sum(array_column($clients, 'month_eur')), 2)],
            'default_date' => self::defaultDate($p['notice_days']),
        ];
    }

    /**
     * Zaplanowanie publikacji : data (dziś albo później, najwyżej rok), ceny przeliczone od nowa (build), klienci
     * wyłączeni (ich ceny zostają), e-mail z cennikiem przed datą (opcja).
     * @param array{date: mixed, items: array, exclude?: array, notify?: mixed} $input
     */
    public function plan(array $input, ?int $userId): array
    {
        $this->assertAccess($userId);
        $date = (string) ($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Podaj datę zmiany cen.', 'errors', 422);
        if ($date < date('Y-m-d')) throw new CustomException('Data zmiany cen nie może być w przeszłości.', 'errors', 422);
        if ($date > date('Y-m-d', strtotime('+1 year'))) throw new CustomException('Data zmiany cen : najwyżej rok od dziś.', 'errors', 422);
        $b = $this->build((array) ($input['items'] ?? []));
        $exclude = array_flip(array_map('intval', (array) ($input['exclude'] ?? [])));
        $notify = !array_key_exists('notify', $input) || !empty($input['notify']);
        $clients = array_values(array_filter($b['clients'], fn($c) => !isset($exclude[$c['id']])));
        $kept = array_flip(array_column($clients, 'id'));
        $items = [];
        foreach ($b['standard'] as $s) $items[] = ['id_product' => $s['id'], 'id_customer' => 0, 'old' => $s['old'], 'new' => $s['new'], 'manual' => $s['manual']];
        foreach ($b['items'] as $i) if (isset($kept[$i['id_customer']])) $items[] = $i;
        $rows = array_map(fn($c) => ['id_customer' => $c['id'], 'site' => $c['franchise'], 'email' => $notify && $c['email'] !== ''], $clients);
        $id = $this->repo->createPublication([
            'effective_date' => $date, 'notify_email' => $notify, 'notice_days' => $b['params']['notice_days'],
            'products' => count($b['standard']), 'client_prices' => count($items) - count($b['standard']),
            'avg_pct' => $b['totals']['avg_pct'], 'month_eur' => round(array_sum(array_column($clients, 'month_eur')), 2), 'created_by' => $userId,
        ], $items, $rows);
        $notice = date('Y-m-d', strtotime($date . ' -' . $b['params']['notice_days'] . ' days'));
        return ['id' => $id, 'date' => $date, 'products' => count($b['standard']), 'client_prices' => count($items) - count($b['standard']),
            'clients' => count($clients), 'emails' => count(array_filter($rows, fn($r) => $r['email'])), 'sites' => count(array_filter($rows, fn($r) => $r['site'])),
            'notice_date' => $notice < date('Y-m-d') ? date('Y-m-d') : $notice,
            'message' => 'Zaplanowano zmianę ' . count($b['standard']) . ' cen standardowych i ' . (count($items) - count($b['standard'])) . ' cen klientów od '
                . date('d.m.Y', strtotime($date)) . '.'];
    }

    /** Domyślna data : pierwszy poniedziałek co najmniej $days dni od dziś. */
    public static function defaultDate(int $days): string
    {
        $t = strtotime('+' . max(1, $days) . ' days');
        while ((int) date('N', $t) !== 1) $t = strtotime('+1 day', $t);
        return date('Y-m-d', $t);
    }

    /** Adres e-mail cennika klienta wg Dystrybucji dokumentów (dokument « Cennik »), pusty = brak. */
    public function priceAddress(int $customerId, array $c): string
    {
        $clean = fn($v) => filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL) ? trim((string) $v) : '';
        $emails = ['head' => $clean($c['email_head'] ?? ''), 'accountant' => $clean($c['email_accountant'] ?? ''), 'salesman' => $clean($c['email_salesman'] ?? '')];
        try {
            $moment = $this->distribution->moments()['price'] ?? 'price_list_changed';
            $plan = $this->distribution->plan($moment, $customerId, $emails, ['price']);
            return (string) ($plan['client'][0]['address'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Średnia sprzedaż kartonów na miesiąc (WDT, 12 miesięcy) : [klient][produkt]. */
    private function monthlyCartons(): array
    {
        $out = [];
        foreach ($this->sales->sales(date('Y-m-d', strtotime('-' . self::SALES_MONTHS . ' months')), date('Y-m-d'), [], ['customer', 'product']) as $r) {
            if ($r['id_customer'] === null || $r['product_id'] === null) continue;
            $out[(int) $r['id_customer']][(int) $r['product_id']] = (float) $r['cartons'] / self::SALES_MONTHS;
        }
        return $out;
    }

    private static function decimal(mixed $v): ?float
    {
        if ($v === null) return null;
        $s = str_replace([' ', ','], ['', '.'], trim((string) $v));
        if ($s === '') return null;
        $f = filter_var($s, FILTER_VALIDATE_FLOAT);
        return $f === false ? null : (float) $f;
    }

    private static function num(float $v, int $d): string
    {
        return number_format($v, $d, ',', ' ');
    }
}
