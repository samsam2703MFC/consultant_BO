<?php
declare(strict_types=1);

namespace App\Services\Finances;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Finances\ClientPriceRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\PageAccessService;

/**
 * Ceny klientów wg marży [212] (Marża kategorii › Ceny klientów, makieta 2 « klient po kliencie »).
 * Cena celu produktu = koszt pełny ÷ (1 − marża docelowa kategorii) (ta sama co « cena wg celu » na stronie marż) ;
 * cel klienta = cena celu × min(1, cena klienta ÷ cena standardowa), gdy rabat zachowany ;
 * nowa cena = max(obecna, min(cel klienta, obecna × (1 + limit))) zaokrąglona w górę — nigdy niżej niż obecna.
 * Efekt na miesiąc = różnica × średnia sprzedaż kartonów klienta na miesiąc (WDT, ostatnie 12 miesięcy).
 * Cena podniesiona tu (n_product_price_change, margin_clients) znowu w górę najwcześniej po prices.client_interval_days
 * dniach (status recent) — dwa kliknięcia nie dają dwóch podwyżek.
 */
final class ClientPriceService
{
    public const SOURCE = 'margin_clients';
    private const SALES_MONTHS = 12;

    public function __construct(
        private ClientPriceRepository $repo,
        private WdtSalesRepository $sales,
        private ParamRepository $params,
        private PageAccessService $access,
    ) {
    }

    /** @return array{cap_pct: float, round_eur: float, keep_discount: bool, interval_days: int} */
    public function params(): array
    {
        $num = fn(string $code, string $def) => (float) str_replace(',', '.', $this->params->get($code, $def));
        return [
            'cap_pct' => max(0.0, $num('prices.client_cap_pct', '8')),
            'round_eur' => max(0.01, $num('prices.client_round_eur', '0.05')),
            'keep_discount' => $this->params->get('prices.client_keep_discount', '1') === '1',
            'interval_days' => max(0, (int) $this->params->get('prices.client_interval_days', '90')),
        ];
    }

    /**
     * Reguła jednej ceny. $target = cena celu produktu (EUR / karton), $std = cena standardowa.
     * status : up (do celu), limited (limit podwyżki, reszta później), locked (cel ≤ obecna : bez zmian), none (brak celu).
     * @return array{target: ?float, new: float, status: string, gap_pct: ?float}
     */
    public static function rule(float $cur, ?float $target, float $std, array $p): array
    {
        if ($target === null || $target <= 0 || $cur <= 0) return ['target' => null, 'new' => $cur, 'status' => 'none', 'gap_pct' => null];
        $t = $p['keep_discount'] && $std > 0 ? $target * min(1.0, $cur / $std) : $target;
        if ($t <= $cur + 0.005) return ['target' => round($t, 2), 'new' => $cur, 'status' => 'locked', 'gap_pct' => 0.0];
        $cap = $cur * (1 + $p['cap_pct'] / 100);
        $limited = $t > $cap + 0.005;
        $step = $p['round_eur'];
        $new = max($cur, round(ceil(round(min($t, $cap) / $step, 6)) * $step, 2));
        return ['target' => round($t, 2), 'new' => $new, 'status' => $limited ? 'limited' : 'up',
            'gap_pct' => $limited ? round(($t - $new) / $new * 100, 1) : 0.0];
    }

    /**
     * Lista klientów (efekt na miesiąc, liczba cen do zmiany) i cennik wybranego klienta (domyślnie największy efekt).
     * @param array $result wynik ProductMarginService::calculateCategoryOverview
     */
    public function overview(array $result, ?int $customerId): array
    {
        $p = $this->params();
        $targets = self::targets($result);
        $names = $this->repo->customers();
        $qty = $this->monthlyCartons();
        $recent = $this->recent($p);
        $byClient = [];
        foreach ($this->repo->prices() as $r) {
            if (!isset($targets[$r['id_product']], $names[$r['id_customer']])) continue;
            $byClient[$r['id_customer']][] = $this->row($r, $targets[$r['id_product']], $qty, $p, $recent);
        }
        $clients = [];
        foreach ($byClient as $id => $rows) {
            $up = array_filter($rows, fn($x) => $x['delta'] > 0);
            $clients[] = ['id' => $id, 'name' => $names[$id], 'prices' => count($rows), 'changes' => count($up),
                'avg_pct' => $up ? round(array_sum(array_column($up, 'delta_pct')) / count($up), 1) : 0.0,
                'month_eur' => round(array_sum(array_column($rows, 'month_eur')), 2)];
        }
        usort($clients, fn($a, $b) => [$b['month_eur'], $b['changes'], $a['name']] <=> [$a['month_eur'], $a['changes'], $b['name']]);
        $selected = $customerId !== null && isset($byClient[$customerId]) ? $customerId : ($clients[0]['id'] ?? null);
        $rows = $selected !== null ? $byClient[$selected] : [];
        usort($rows, fn($a, $b) => [$b['delta'] > 0, $b['month_eur'], $b['delta_pct'], $a['name']] <=> [$a['delta'] > 0, $a['month_eur'], $a['delta_pct'], $b['name']]);
        $client = null;
        foreach ($clients as $c) if ($c['id'] === $selected) $client = $c;
        return ['params' => $p, 'clients' => $clients, 'client' => $client, 'rows' => $rows,
            'totals' => ['clients' => count($clients), 'changes' => array_sum(array_column($clients, 'changes')),
                'month_eur' => round(array_sum(array_column($clients, 'month_eur')), 2)]];
    }

    /**
     * Zastosowanie u klienta : serwer liczy ceny od nowa (te same parametry) i podnosi tylko wybrane produkty
     * z podwyżką ; cena zmieniona w międzyczasie zostaje. @param list<int> $productIds
     * @return array{applied: int, month_eur: float, message: string}
     */
    public function apply(array $result, int $customerId, array $productIds, ?int $userId): array
    {
        if (!$this->access->canSee($userId, '/finances/category-margin')) throw new CustomException('Brak dostępu do marż.', 'errors', 403);
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($id) => $id > 0)));
        if (!$productIds) throw new CustomException('Zaznacz produkty do podwyżki.', 'errors', 422);
        $p = $this->params();
        $targets = self::targets($result);
        $qty = $this->monthlyCartons($customerId);
        $recent = $this->recent($p, $customerId);
        $changes = [];
        $month = [];
        foreach ($this->repo->prices($customerId) as $r) {
            if (!in_array($r['id_product'], $productIds, true) || !isset($targets[$r['id_product']])) continue;
            $row = $this->row($r, $targets[$r['id_product']], $qty, $p, $recent);
            if ($row['delta'] <= 0) continue;
            $changes[$r['id_product']] = ['old' => $row['cur'], 'new' => $row['new']];
            $month[$r['id_product']] = $row['month_eur'];
        }
        if (!$changes) throw new CustomException('Brak cen do podwyżki u tego klienta (cel osiągnięty, ceny zmienione albo podniesione niedawno).', 'errors', 422);
        $done = $this->repo->raise($customerId, $changes, self::SOURCE, $userId);
        $sum = round(array_sum(array_intersect_key($month, array_flip($done))), 2);
        $skipped = count($changes) - count($done);
        $n = count($done);
        $word = $n === 1 ? 'cenę' : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? 'ceny' : 'cen');
        $message = "Podwyższono {$n} {$word}" . ($sum > 0 ? ' — efekt ok. +' . number_format($sum, 0, ',', ' ') . ' € / mies.' : '.')
            . ($skipped ? " Zmienione w międzyczasie, bez podwyżki : {$skipped}." : '');
        return ['applied' => count($done), 'month_eur' => $sum, 'message' => $message];
    }

    public function isAdmin(?int $userId): bool
    {
        return $this->access->isAdmin($userId);
    }

    /** Parametry reguły (administrator). @return array{cap_pct: float, round_eur: float, keep_discount: bool, interval_days: int} */
    public function saveParams(array $input, ?int $userId): array
    {
        if (!$this->access->isAdmin($userId)) throw new CustomException('Parametry zmienia administrator.', 'errors', 403);
        $values = [];
        $labels = ['cap_pct' => 'Limit podwyżki', 'round_eur' => 'Zaokrąglenie', 'interval_days' => 'Następna podwyżka po'];
        foreach (['cap_pct' => 'prices.client_cap_pct', 'round_eur' => 'prices.client_round_eur', 'interval_days' => 'prices.client_interval_days'] as $key => $code) {
            $raw = str_replace([' ', ','], ['', '.'], trim((string) ($input[$key] ?? '')));
            if ($err = ParamRules::rangeError($code, $raw)) throw new CustomException($labels[$key] . ' : ' . $err, 'errors', 422);
            $values[$code] = (string) round((float) $raw, 2);
        }
        $values['prices.client_keep_discount'] = !empty($input['keep_discount']) ? '1' : '0';
        foreach ($values as $code => $v) $this->params->setValue($code, $v, $userId);
        return $this->params();
    }

    /** Cena celu (EUR / karton, bez zaokrąglenia) i dane produktu dla kategorii z marżą docelową. */
    private static function targets(array $result): array
    {
        $rate = (float) ($result['exchange_rate'] ?? 0);
        $out = [];
        foreach ($result['categories'] ?? [] as $cat) {
            $tm = (float) $cat['target_margin'];
            foreach ($cat['products'] as $p) {
                $target = $rate > 0 && $tm > 0 && $tm < 100 && $p['suggested_price_pln'] !== null ? (float) $p['suggested_price_pln'] / $rate : null;
                $out[(int) $p['product_id']] = ['name' => $p['name'], 'category' => $cat['category_name'], 'target_margin' => $tm,
                    'target' => $target, 'std' => (float) $p['sale_price_eur'], 'cost' => $p['total_cost_eur'] !== null ? (float) $p['total_cost_eur'] : null];
            }
        }
        return $out;
    }

    /** Średnia sprzedaż kartonów na miesiąc : [klient][produkt]. */
    private function monthlyCartons(?int $customerId = null): array
    {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . self::SALES_MONTHS . ' months'));
        $out = [];
        foreach ($this->sales->sales($from, $to, $customerId !== null ? ['customer_id' => $customerId] : [], ['customer', 'product']) as $r) {
            if ($r['id_customer'] === null || $r['product_id'] === null) continue;
            $out[(int) $r['id_customer']][(int) $r['product_id']] = (float) $r['cartons'] / self::SALES_MONTHS;
        }
        return $out;
    }

    /** Podwyżki wg marży w okresie przerwy : [klient][produkt] => data. */
    private function recent(array $p, ?int $customerId = null): array
    {
        if ($p['interval_days'] <= 0) return [];
        return $this->repo->lastRaises(self::SOURCE, date('Y-m-d H:i:s', strtotime('-' . $p['interval_days'] . ' days')), $customerId);
    }

    private function row(array $r, array $t, array $qty, array $p, array $recent): array
    {
        $rule = self::rule($r['price'], $t['target'], $t['std'], $p);
        $raisedAt = $recent[$r['id_customer']][$r['id_product']] ?? null;
        if ($raisedAt !== null && in_array($rule['status'], ['up', 'limited'], true)) {
            // Podniesiona niedawno : bez nowej podwyżki do końca przerwy ; cel pokazany.
            $rule = ['target' => $rule['target'], 'new' => $r['price'], 'status' => 'recent', 'gap_pct' => round(($rule['target'] - $r['price']) / $r['price'] * 100, 1)];
        }
        $q = $qty[$r['id_customer']][$r['id_product']] ?? 0.0;
        $delta = round($rule['new'] - $r['price'], 2);
        return ['id' => $r['id_product'], 'name' => $t['name'], 'category' => $t['category'], 'target_margin' => $t['target_margin'],
            'cost' => $t['cost'], 'std' => $t['std'], 'cur' => $r['price'], 'target' => $rule['target'], 'new' => $rule['new'],
            'delta' => $delta, 'delta_pct' => $r['price'] > 0 ? round($delta / $r['price'] * 100, 1) : 0.0,
            'status' => $rule['status'], 'gap_pct' => $rule['gap_pct'], 'qty' => round($q, 1), 'month_eur' => round($delta * $q, 2),
            'raised_at' => $raisedAt, 'next_at' => $raisedAt !== null ? date('Y-m-d', strtotime($raisedAt . ' +' . $p['interval_days'] . ' days')) : null,
            'margin_now' => $t['cost'] !== null && $r['price'] > 0 ? round(($r['price'] - $t['cost']) / $r['price'] * 100, 1) : null,
            'margin_new' => $t['cost'] !== null && $rule['new'] > 0 ? round(($rule['new'] - $t['cost']) / $rule['new'] * 100, 1) : null];
    }
}
