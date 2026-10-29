<?php
declare(strict_types=1);

namespace App\Services\DevCard;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\DevCardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Finances\StorePrice\StoreSellabilityService;

/**
 * Nowy produkt testowy, krok 4 « Koszt i cena » [198] : receptura z karty przypisana do naszych surowców (dopasowanie
 * nazw, pamięć wyborów, ilość w jednostce surowca) → koszt surowców partii i sztuki (cena surowca = najwyższa
 * zatwierdzona, jak w kalkulacji receptur : ilość × cena) ; cena fabryczna = koszt × współczynnik (2,5 / 3,0 / 4,0) ;
 * cena w sklepie netto = cena fabryczna × mnożnik (× 3 / 4 / 5), zaokrąglona, do zmiany ; sprzedawalność w Belgii
 * (sklep ÷ fabryka, progi z Marż) ; czy produkt jest cobotyzowalny i które etapy robi cobot.
 */
final class DevCardCostService
{
    public const STATUSES = ['auto', 'check', 'missing', 'ok', 'free', 'estimate'];
    private const COBOT = ['yes', 'no', 'check'];
    private const MAX_QTY = 1_000_000;
    private const MAX_PRICE_KG = 100_000;

    public function __construct(
        private DevCardRepository $cards,
        private ParamRepository $params,
        private StoreSellabilityService $sellability,
        private TransactionManager $tx,
    ) {
    }

    /** Stan kroku : linie (karta → surowiec, ilość, koszt), partia, ceny, sprzedawalność, cobot. */
    public function cost(int $id): array
    {
        [$c, $card] = $this->load($id);
        $materials = $this->materials();
        return $this->compute($c, $card, $this->lines($c, $card, $materials), $materials);
    }

    /**
     * Zapis : przypisania (wybór operatora = « ok », zapamiętany dla następnych kart), partia, współczynnik,
     * mnożnik, cena końcowa w sklepie, cobot.
     */
    public function save(int $id, array $in, ?int $userId): array
    {
        [$c, $card] = $this->load($id);
        $materials = $this->materials();
        $lines = $this->lines($c, $card, $materials);
        $f = [];
        $byKey = [];
        foreach ((array) ($in['lines'] ?? []) as $l) if (is_array($l) && isset($l['key'])) $byKey[(string) $l['key']] = $l;
        $remember = [];
        foreach ($lines as $i => $line) {
            $l = $byKey[$line['key']] ?? null;
            if ($l === null) continue;
            $rm = ($l['id_raw_material'] ?? null) === null || $l['id_raw_material'] === '' ? null : filter_var($l['id_raw_material'], FILTER_VALIDATE_INT);
            if ($rm === false || ($rm !== null && !isset($materials[$rm]))) { $f['lines.' . $i] = 'Nieznany surowiec.'; continue; }
            $qty = self::number($l['qty'] ?? 0);
            if ($qty === null || $qty < 0 || $qty > self::MAX_QTY) { $f['lines.' . $i] = 'Ilość : liczba od 0.'; continue; }
            $priceKg = ($l['price_kg'] ?? null) === null || $l['price_kg'] === '' ? null : self::number($l['price_kg']);
            if ($priceKg !== null && ($priceKg < 0 || $priceKg > self::MAX_PRICE_KG)) { $f['lines.' . $i] = 'Cena szacunkowa : zł/kg od 0.'; continue; }
            // Status : surowiec wybrany (ok / auto / check) ; bez surowca : woda, cena szacunkowa albo brak.
            $status = (string) ($l['status'] ?? $line['status']);
            if (!in_array($status, self::STATUSES, true)) $status = $line['status'];
            if ($rm !== null) {
                if (in_array($status, ['missing', 'free', 'estimate'], true) || $rm !== $line['id_raw_material']) $status = 'ok';
            } elseif ($status !== 'free') {
                $status = $priceKg !== null ? 'estimate' : 'missing';
            }
            $lines[$i] = ['id_raw_material' => $rm, 'qty' => round($qty, 3), 'price_kg' => $rm === null ? $priceKg : null, 'status' => $status,
                'note' => $rm === $line['id_raw_material'] && !($qty > 0 && str_starts_with((string) $line['note'], 'Podaj')) ? $line['note'] : null] + $lines[$i];
            if ($rm !== null && $status === 'ok') $remember[] = [IngredientMatcher::nameKey($line['name']), $line['name'], $rm];
        }
        $yield = ($in['yield_pcs'] ?? null) === null || $in['yield_pcs'] === '' ? null
            : filter_var($in['yield_pcs'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($yield === false) $f['yield_pcs'] = 'Sztuk z partii : od 1.';
        $coef = self::number($in['factory_coef'] ?? null);
        if ($coef === null || $coef < 1 || $coef > 10) $f['factory_coef'] = 'Współczynnik : od 1 do 10.';
        $mult = self::number($in['store_mult'] ?? null);
        if ($mult === null || $mult < 1 || $mult > 10) $f['store_mult'] = 'Mnożnik : od 1 do 10.';
        $store = ($in['store_price_eur'] ?? null) === null || $in['store_price_eur'] === '' ? null : self::number($in['store_price_eur']);
        if ($store !== null && ($store < 0 || $store > 10000)) $f['store_price_eur'] = 'Cena w sklepie : od 0 do 10 000 €.';
        $cobot = ($in['cobot'] ?? null) === null || $in['cobot'] === '' ? null : (string) $in['cobot'];
        if ($cobot !== null && !in_array($cobot, self::COBOT, true)) $f['cobot'] = 'Cobot : tak, nie albo do sprawdzenia.';
        $steps = array_values(array_unique(array_filter(array_map(fn($s) => trim(str_replace('|', '/', (string) $s)), (array) ($in['cobot_steps'] ?? [])), fn($s) => $s !== '')));
        if (count($steps) > 12 || array_filter($steps, fn($s) => mb_strlen($s) > 120)) $f['cobot_steps'] = 'Etapy cobota : najwyżej 12, każdy do 120 znaków.';
        $stepsText = $cobot === 'yes' && $steps ? implode('|', $steps) : null;
        if ($stepsText !== null && mb_strlen($stepsText) > 500) $f['cobot_steps'] = 'Etapy cobota : za długi opis.';
        if ($f) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $f]);

        $c = ['yield_pcs' => $yield, 'factory_coef' => $coef, 'store_mult' => $mult, 'store_price_eur' => $store !== null ? round($store, 2) : null] + $c;
        $state = $this->compute($c, $card, $lines, $materials);
        $this->tx->execute(function () use ($id, $lines, $yield, $coef, $mult, $store, $state, $cobot, $stepsText, $remember, $userId) {
            $this->cards->save($id, [
                'cost_json' => json_encode(array_map(fn($l) => ['key' => $l['key'], 'src' => $l['src'], 'id_raw_material' => $l['id_raw_material'],
                    'qty' => $l['qty'], 'price_kg' => $l['price_kg'], 'status' => $l['status'], 'note' => $l['note']], $lines), JSON_UNESCAPED_UNICODE),
                'yield_pcs' => $yield, 'factory_coef' => $coef, 'store_mult' => $mult,
                'store_price_eur' => $store !== null ? round($store, 2) : $state['store']['final_eur'],
                'cost_pc_pln' => $state['cost_pc_pln'], 'cost_at' => date('Y-m-d H:i:s'), 'cobot' => $cobot, 'cobot_steps' => $stepsText,
            ]);
            foreach ($remember as [$key, $name, $rm]) if ($key !== '') $this->cards->rememberIngredient($key, $name, $rm, $userId);
        });
        return $this->cost($id);
    }

    /** Podsumowanie zapisanego kosztu (karta produktu, krok 3) — bez przeliczania przypisań. */
    public function summary(array $c): ?array
    {
        if ($c['cost_at'] === null && $c['cobot'] === null) return null;
        $rate = $this->cards->eurRate();
        $costPc = $c['cost_pc_pln'] !== null ? (float) $c['cost_pc_pln'] : null;
        $coef = $c['factory_coef'] !== null ? (float) $c['factory_coef'] : null;
        $factory = $costPc !== null && $coef !== null && $rate ? round($costPc * $coef / $rate, 2) : null;
        $store = $c['store_price_eur'] !== null ? (float) $c['store_price_eur'] : null;
        return ['cost_pc_pln' => $costPc !== null ? round($costPc, 2) : null, 'cost_pc_eur' => $costPc !== null && $rate ? round($costPc / $rate, 2) : null,
            'factory_coef' => $coef, 'factory_eur' => $factory, 'store_mult' => $c['store_mult'] !== null ? (float) $c['store_mult'] : null, 'store_eur' => $store,
            'ratio' => $factory && $store !== null ? round($store / $factory, 2) : null,
            'verdict' => StoreSellabilityService::verdict($factory && $store !== null ? $store / $factory : null, $this->sellability->params()),
            'yield_pcs' => $c['yield_pcs'] !== null ? (int) $c['yield_pcs'] : null, 'cobot' => $c['cobot'], 'cobot_steps' => self::steps($c['cobot_steps']),
            'at' => $c['cost_at']];
    }

    /** Współczynniki i mnożniki z parametrów (lista + domyślny), zaokrąglenie ceny w sklepie. */
    public function pricingParams(): array
    {
        $list = fn(string $code, string $fallback): array => self::parseList($this->params->get($code, $fallback)) ?? self::parseList($fallback);
        $coefs = $list('new_product.factory_coefs', '2.5,3,4');
        $mults = $list('new_product.store_mults', '3,4,5');
        $def = function (string $code, string $fallback, array $among): float {
            $v = self::number($this->params->get($code, $fallback));
            return $v !== null && $v >= 1 && $v <= 10 ? round($v, 2) : (float) $among[intdiv(count($among), 2)];
        };
        $round = self::number($this->params->get('new_product.store_round_eur', '0.1'));
        return ['coefs' => $coefs, 'coef' => $def('new_product.factory_coef', '3', $coefs), 'mults' => $mults, 'mult' => $def('new_product.store_mult', '4', $mults),
            'round' => $round !== null && $round >= 0 && $round <= 1 ? $round : 0.1];
    }

    /**
     * Lista współczynników : « 2.5,3,4 » albo « 2,5 ; 3 ; 4 » (przecinek dziesiętny, gdy wartości oddziela średnik,
     * spacja albo « · ») ; od 1 do 6 liczb od 1 do 10, posortowane. null = zapis niepoprawny.
     * @return list<float>|null
     */
    public static function parseList(string $raw): ?array
    {
        $raw = trim($raw);
        $vals = [];
        foreach (preg_split(preg_match('/[;·|\s]/u', $raw) ? '/[;·|\s]+/u' : '/,/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $v) {
            $v = self::number($v);
            if ($v === null || $v < 1 || $v > 10) return null;
            $vals[] = round($v, 2);
        }
        $vals = array_values(array_unique($vals));
        sort($vals);
        return $vals && count($vals) <= 6 ? $vals : null;
    }

    // ---------------------------------------------------------------------------------------------------------

    /** @return array{0: array, 1: array} karta (wiersz) i treść karty */
    private function load(int $id): array
    {
        $c = $this->cards->card($id) ?? throw new CustomException('Nie znaleziono karty.', 'errors', 404);
        $card = json_decode((string) $c['card_json'], true);
        if (!is_array($card)) throw new CustomException('Najpierw wygeneruj kartę (albo wgraj gotową .docx).', 'errors', 422);
        return [$c, $card];
    }

    /** @return array<int, array> surowce wg id, ze słowami nazwy (dopasowanie) */
    private function materials(): array
    {
        $out = [];
        foreach ($this->cards->costMaterials() as $m) {
            $out[(int) $m['id']] = ['id' => (int) $m['id'], 'name' => trim((string) $m['name']), 'unit' => mb_strtolower(trim((string) $m['unit'])) ?: 'g',
                'price' => max(0.0, (float) $m['price_per_unit']), 'uses' => (int) $m['uses'], '_words' => IngredientMatcher::words((string) $m['name'])];
        }
        return $out;
    }

    /**
     * Linie karty : zapisane przypisanie (ten sam tekst składnika) albo podpowiedź (pamięć, dopasowanie nazw).
     * Zmiana tekstu karty (poprawki, ponowne generowanie) = nowa podpowiedź tylko dla zmienionych linii.
     */
    private function lines(array $c, array $card, array $materials): array
    {
        $saved = [];
        foreach ((array) (json_decode((string) ($c['cost_json'] ?? ''), true) ?: []) as $l) if (is_array($l) && isset($l['key'])) $saved[(string) $l['key']] = $l;
        $memory = $this->cards->ingredientMemory();
        $yield = $c['yield_pcs'] !== null ? (int) $c['yield_pcs'] : IngredientMatcher::yieldPieces((string) ($card['yield'] ?? ''));
        $out = [];
        $seen = [];
        foreach ((array) ($card['ingredients'] ?? []) as $g) {
            // Linia z kilkoma składnikami (« 120 g cukru · 80 g cukru brązowego ») : osobne linie kosztu.
            $items = [];
            foreach ((array) ($g['items'] ?? []) as $it) {
                $parts = preg_split('/\s+·\s+/u', trim(trim((string) ($it['qty'] ?? '')) . ' ' . trim((string) ($it['name'] ?? '')))) ?: [];
                if (count($parts) < 2) { $items[] = $it; continue; }
                foreach ($parts as $part) $items[] = CardSchema::splitQty($part);
            }
            foreach ($items as $it) {
                $qty = trim((string) ($it['qty'] ?? ''));
                $name = trim((string) ($it['name'] ?? ''));
                if ($name === '') continue;
                $base = IngredientMatcher::fold(preg_replace('/\s+/u', ' ', $qty . '|' . $name) ?? '');
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $key = mb_substr($base, 0, 300) . ($seen[$base] > 1 ? '#' . $seen[$base] : '');
                $line = ['key' => $key, 'group' => (string) ($g['group'] ?? ''), 'qty_text' => $qty, 'name' => $name, 'src' => trim($qty . ' ' . $name)];
                $ranked = IngredientMatcher::rank($name, $materials);
                $line['suggestions'] = array_column($ranked, 'id');
                $s = $saved[$key] ?? null;
                if ($s !== null) {
                    $rm = isset($s['id_raw_material']) && isset($materials[(int) $s['id_raw_material']]) ? (int) $s['id_raw_material'] : null;
                    $status = in_array($s['status'] ?? '', self::STATUSES, true) ? $s['status'] : 'check';
                    if ($rm === null && !in_array($status, ['free', 'estimate'], true)) $status = 'missing';
                    $out[] = $line + ['id_raw_material' => $rm, 'qty' => (float) ($s['qty'] ?? 0), 'price_kg' => isset($s['price_kg']) ? (float) $s['price_kg'] : null,
                        'status' => $status, 'note' => $s['note'] ?? null];
                    continue;
                }
                $out[] = $line + $this->suggest($qty, $name, $ranked, $materials, $memory, $yield);
            }
        }
        return $out;
    }

    /** Podpowiedź dla nowej linii : woda bez kosztu ; pamięć operatora ; najlepsze dopasowanie nazwy. */
    private function suggest(string $qty, string $name, array $ranked, array $materials, array $memory, ?int $yield): array
    {
        $q = IngredientMatcher::parseQty($qty, $name);
        // « 1 parówka na sztukę » : ilość × sztuk z partii.
        $each = IngredientMatcher::perPiece($name) && $yield && $q['amount'] !== null;
        if ($each) $q['amount'] *= $yield;
        if (IngredientMatcher::isFree($name)) {
            $conv = IngredientMatcher::toMaterial($q, 'g', $name, $name);
            return ['id_raw_material' => null, 'qty' => $conv['qty'], 'price_kg' => null, 'status' => 'free', 'note' => 'Bez kosztu'];
        }
        $rm = null;
        $status = IngredientMatcher::confidence($ranked);
        $notes = [];
        $key = IngredientMatcher::nameKey($name);
        if ($key !== '' && isset($memory[$key]) && isset($materials[(int) $memory[$key]])) {
            $rm = (int) $memory[$key];
            $status = 'auto';
            $notes[] = 'zapamiętane';
        } elseif ($ranked) {
            $rm = $ranked[0]['id'];
        }
        $perPiece = $each ? '× ' . $yield . ' szt. (na sztukę)' : null;
        if ($rm === null) {
            $conv = IngredientMatcher::toMaterial($q, 'g', $name, $name);
            return ['id_raw_material' => null, 'qty' => $conv['qty'], 'price_kg' => null, 'status' => 'missing',
                'note' => implode(' · ', array_filter([$perPiece, $conv['note']])) ?: null];
        }
        $m = $materials[$rm];
        $conv = IngredientMatcher::toMaterial($q, $m['unit'], $name, $m['name']);
        if ($perPiece) $notes[] = $perPiece;
        if ($conv['note']) $notes[] = $conv['note'];
        // Ilość nieznana albo przeliczenie suchych drożdży : do sprawdzenia.
        if ((!$conv['known'] || str_contains((string) $conv['note'], 'suche')) && $status === 'auto') $status = 'check';
        return ['id_raw_material' => $rm, 'qty' => $conv['qty'], 'price_kg' => null, 'status' => $status, 'note' => $notes ? implode(' · ', $notes) : null];
    }

    private function compute(array $c, array $card, array $lines, array $materials): array
    {
        $p = $this->pricingParams();
        $rate = $this->cards->eurRate();
        $total = 0.0;
        $counts = ['auto' => 0, 'check' => 0, 'missing' => 0, 'ok' => 0, 'free' => 0, 'estimate' => 0];
        foreach ($lines as $i => $l) {
            $m = $l['id_raw_material'] !== null ? $materials[$l['id_raw_material']] : null;
            $cost = $m !== null ? $l['qty'] * $m['price']
                : ($l['status'] === 'estimate' && $l['price_kg'] !== null ? $l['qty'] * $l['price_kg'] / 1000 : 0.0);
            $total += $cost;
            $counts[$l['status']]++;
            $lines[$i] = $l + ['material' => $m['name'] ?? null, 'unit' => $m['unit'] ?? 'g', 'price_unit' => $m['price'] ?? null,
                'price_label' => $m !== null ? self::priceLabel($m['unit'], $m['price']) : null, 'cost' => round($cost, 4)];
        }
        $yieldGuess = IngredientMatcher::yieldPieces((string) ($card['yield'] ?? ''));
        $yield = $c['yield_pcs'] !== null ? (int) $c['yield_pcs'] : $yieldGuess;
        $costPc = $yield ? $total / $yield : null;
        $coef = $c['factory_coef'] !== null ? (float) $c['factory_coef'] : $p['coef'];
        $mult = $c['store_mult'] !== null ? (float) $c['store_mult'] : $p['mult'];
        $factoryPln = $costPc !== null ? $costPc * $coef : null;
        $factoryEur = $factoryPln !== null && $rate ? $factoryPln / $rate : null;
        $storeEur = $factoryEur !== null ? $factoryEur * $mult : null;
        $final = $c['store_price_eur'] !== null ? (float) $c['store_price_eur'] : self::round($storeEur, $p['round']);
        $sp = $this->sellability->params();
        $ratio = $factoryEur && $final !== null ? $final / $factoryEur : null;
        $steps = [];
        foreach ((array) ($card['photos'] ?? []) as $ph) if (trim((string) ($ph['caption'] ?? '')) !== '') $steps[] = trim((string) $ph['caption']);
        if (!$steps) foreach (array_slice((array) ($card['steps'] ?? []), 0, 8) as $s) $steps[] = mb_strimwidth(trim((string) $s), 0, 48, '…');
        $chosen = self::steps($c['cobot_steps'] ?? null);
        return [
            'id' => (int) $c['id'], 'name' => $c['name'], 'status' => $c['status'],
            'lines' => array_map(fn($l) => array_intersect_key($l, array_flip(['key', 'group', 'qty_text', 'name', 'src', 'id_raw_material', 'material', 'unit', 'qty',
                'price_unit', 'price_label', 'price_kg', 'cost', 'status', 'note', 'suggestions'])), $lines),
            'counts' => $counts + ['total' => count($lines), 'matched' => $counts['auto'] + $counts['ok'] + $counts['free']],
            'yield_pcs' => $yield, 'yield_guess' => $yieldGuess, 'yield_text' => (string) ($card['yield'] ?? ''),
            'total_pln' => round($total, 2), 'cost_pc_pln' => $costPc !== null ? round($costPc, 4) : null, 'cost_pc_eur' => $costPc !== null && $rate ? round($costPc / $rate, 4) : null,
            'rate' => $rate,
            'factory' => ['coefs' => $p['coefs'], 'coef' => $coef, 'price_pln' => $factoryPln !== null ? round($factoryPln, 4) : null,
                'price_eur' => $factoryEur !== null ? round($factoryEur, 4) : null],
            'store' => ['mults' => $p['mults'], 'mult' => $mult, 'price_eur' => $storeEur !== null ? round($storeEur, 4) : null, 'final_eur' => $final, 'round' => $p['round'],
                'manual' => $c['store_price_eur'] !== null && $storeEur !== null && abs((float) $c['store_price_eur'] - (float) self::round($storeEur, $p['round'])) > 0.004],
            'sellability' => ['ratio' => $ratio !== null ? round($ratio, 2) : null, 'verdict' => StoreSellabilityService::verdict($ratio, $sp),
                'multiplier' => $sp['multiplier'], 'limit' => $sp['limit']],
            'food_share' => ['factory_pct' => $coef > 0 && $costPc !== null ? round(100 / $coef, 1) : null,
                'store_pct' => $costPc !== null && $rate && $final ? round($costPc / $rate / $final * 100, 1) : null],
            'cobot' => $c['cobot'] ?? null, 'cobot_steps' => $chosen, 'step_choices' => array_values(array_unique([...$steps, ...$chosen])),
            'materials' => array_values(array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name'], 'unit' => $m['unit'], 'price' => $m['price'],
                'price_label' => self::priceLabel($m['unit'], $m['price'])], $materials)),
            'saved_at' => $c['cost_at'] ?? null,
        ];
    }

    /** Cena surowca do pokazania : zł/kg dla g, zł/l dla ml, zł/szt, zł/kg dla kg. */
    private static function priceLabel(string $unit, float $price): string
    {
        return match ($unit) {
            'g' => number_format($price * 1000, 2, ',', ' ') . ' zł/kg',
            'ml' => number_format($price * 1000, 2, ',', ' ') . ' zł/l',
            'kg' => number_format($price, 2, ',', ' ') . ' zł/kg',
            'l' => number_format($price, 2, ',', ' ') . ' zł/l',
            default => number_format($price, 2, ',', ' ') . ' zł/' . $unit,
        };
    }

    private static function round(?float $v, float $step): ?float
    {
        if ($v === null) return null;
        return $step > 0 ? round(round($v / $step) * $step, 2) : round($v, 2);
    }

    /** @return list<string> */
    private static function steps(?string $v): array
    {
        return $v === null || $v === '' ? [] : array_values(array_filter(explode('|', $v), fn($s) => $s !== ''));
    }

    private static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (!is_string($v)) return null;
        $v = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim($v));
        return is_numeric($v) ? (float) $v : null;
    }
}
