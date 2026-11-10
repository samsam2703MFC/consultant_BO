<?php
declare(strict_types=1);

namespace App\Services\Material\Nutrition;

use App\Exceptions\CustomException;
use App\Repositories\Material\NutritionRepository;
use App\Services\Organization\PageAccessService;

/**
 * Wartości odżywcze surowców z internetu (Open Food Facts) : nazwa surowca → kategorie OFF (nazwy polskie,
 * francuskie, angielskie ; odmiana polska po wspólnym początku słowa), wartości na 100 g = mediana produktów
 * kategorii (najczęściej skanowanych, co najmniej 3 z wartością). Propozycja ; zapis zatwierdza użytkownik,
 * źródło (kategoria, liczba produktów) zapamiętane przy surowcu.
 */
final class NutritionLookupService
{
    /** kolumna raw_material => pole OFF (na 100 g) */
    public const FIELDS = [
        'energy_100g' => 'energy-kcal_100g', 'fat_100g' => 'fat_100g', 'saturated_fat_100g' => 'saturated-fat_100g', 'carbohydrates_100g' => 'carbohydrates_100g',
        'sugars_100g' => 'sugars_100g', 'fiber_100g' => 'fiber_100g', 'proteins_100g' => 'proteins_100g', 'salt_100g' => 'salt_100g',
    ];
    public const PAGE = '/materials/nutritional-values';
    private const TAXONOMY_DAYS = 30;
    private const CACHE_DAYS = 30;
    private const MIN_SAMPLE = 3;
    private const STOP = ['bez', 'dla', 'typ', 'type', 'extra', 'blok', 'worek', 'luz', 'szt', 'the', 'and', 'with', 'des', 'les', 'aux', 'pour', 'sans', 'avec', 'bio'];
    private const FOLD = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'è' => 'e', 'é' => 'e',
        'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'ě' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
        'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ś' => 's', 'š' => 's', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'ý' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z'];

    /**
     * Słowniczek surowców (początek słowa bez znaków diakrytycznych => słowa angielskie) : kategorie OFF mają mało nazw
     * polskich (brak « Truskawki », « Ziemniaki »…) ; nazwa przetłumaczona szuka w nazwach angielskich. Klucz z « = » :
     * tylko całe słowo (mak ≠ mąka) ; pusta wartość : słowo bez znaczenia dla kategorii (masa jajowa = jaja).
     */
    private const GLOSSARY = [
        'truskaw' => 'strawberries', 'malin' => 'raspberries', 'jezyn' => 'blackberries', 'borowk' => 'blueberries', 'jagod' => 'blueberries', 'porzeczk' => 'blackcurrants',
        'agrest' => 'gooseberries', 'wisni' => 'cherries', 'wisn' => 'cherries', 'czeresn' => 'cherries', 'morel' => 'apricots', 'brzoskwin' => 'peaches', 'sliwk' => 'plums',
        'gruszk' => 'pears', 'jablk' => 'apples', 'jablek' => 'apples', 'banan' => 'bananas', 'ananas' => 'pineapples', 'cytryn' => 'lemons', 'pomarancz' => 'oranges',
        'limonk' => 'limes', 'mandaryn' => 'mandarins', 'marakuj' => 'passion fruits', 'maracuj' => 'passion fruits', 'mango' => 'mangoes', 'kokos' => 'coconut',
        'daktyl' => 'dates', 'figi' => 'figs', 'rodzyn' => 'raisins', 'zurawin' => 'cranberries', 'rabarbar' => 'rhubarb', 'dyni' => 'pumpkins', 'dyn=' => 'pumpkins',
        'ziemniak' => 'potatoes', 'marchew' => 'carrots', 'cebul' => 'onions', 'czosn' => 'garlic', 'seler' => 'celery', 'pietruszk' => 'parsley', 'koper' => 'dill',
        'rukol' => 'rocket', 'szpinak' => 'spinach', 'kapust' => 'cabbages', 'brokul' => 'broccoli', 'kalafior' => 'cauliflowers', 'cukini' => 'zucchini',
        'papryk' => 'peppers', 'pomidor' => 'tomatoes', 'ogork' => 'cucumbers', 'pieczark' => 'mushrooms', 'grzyb' => 'mushrooms', 'szparag' => 'asparagus',
        'groszek' => 'peas', 'fasol' => 'beans', 'kukurydz' => 'corn', 'rzodkiew' => 'radishes', 'imbir' => 'ginger', 'chili' => 'chili peppers',
        'jajk' => 'eggs', 'jaja' => 'eggs', 'jajow' => 'eggs', 'zoltk' => 'egg yolks', 'bialk' => 'egg whites', 'mleczk' => 'milk', 'mlek' => 'milks', 'mleko' => 'milks',
        'smietan' => 'creams', 'maslank' => 'buttermilk', 'maslo' => 'butters', 'masla' => 'butters', 'twarog' => 'quark', 'jogurt' => 'yogurts', 'mascarpon' => 'mascarpone',
        'mozzarell' => 'mozzarella', 'ricot' => 'ricotta', 'gorgonzol' => 'gorgonzola', 'parmezan' => 'parmesan', 'ser=' => 'cheeses', 'sery' => 'cheeses',
        'kurczak' => 'chicken', 'kaczk' => 'duck', 'indyk' => 'turkey', 'wolowin' => 'beef', 'cielec' => 'veal', 'wieprzow' => 'pork', 'schab' => 'pork',
        'boczek' => 'bacon', 'szynk' => 'hams', 'losos' => 'salmon', 'tunczyk' => 'tuna', 'krewet' => 'shrimps', 'jagniec' => 'lamb',
        'mak=' => 'poppy seeds', 'maka' => 'flours', 'maki' => 'flours', 'drozdz' => 'yeasts', 'zelatyn' => 'gelatin', 'pektyn' => 'pectins', 'kakao' => 'cocoa powders', 'czekolad' => 'chocolates', 'cukier' => 'sugars',
        'cukr' => 'sugars', 'miod' => 'honeys', 'sol=' => 'salts', 'pieprz' => 'pepper', 'cynamon' => 'cinnamon', 'wanili' => 'vanilla', 'muszkatol' => 'nutmeg',
        'gozdzik' => 'cloves', 'kardamon' => 'cardamom', 'bazyli' => 'basil', 'rozmaryn' => 'rosemary', 'tymian' => 'thyme', 'szalwi' => 'sage', 'estragon' => 'tarragon',
        'sezam' => 'sesame seeds', 'slonecznik' => 'sunflower seeds', 'siemie' => 'flax seeds', 'migdal' => 'almonds', 'orzech' => 'nuts', 'laskow' => 'hazelnuts',
        'wloski' => 'walnuts', 'kukurydzian' => 'corn', 'pszenn' => 'wheat', 'semolin' => 'semolina', 'durum' => 'durum',
        'cassonad' => 'brown sugars', 'perl' => 'pearl', 'masa=' => '', 'filet' => '', 'mix=' => '', 'pasteryz' => '', 'swieze' => 'fresh', 'pistacj' => 'pistachios', 'pekan' => 'pecan nuts', 'owsian' => 'oat', 'kasz' => 'groats', 'ryz=' => 'rices', 'ryzu' => 'rices',
        'makaron' => 'pastas', 'olej' => 'vegetable oils', 'oliw' => 'olive oils', 'margaryn' => 'margarines', 'smalec' => 'lard', 'ocet' => 'vinegars',
        'musztard' => 'mustards', 'majonez' => 'mayonnaises', 'skrobi' => 'starches', 'glukoz' => 'glucose', 'syrop' => 'syrups', 'marcepan' => 'marzipan',
        'sok=' => 'juices', 'kawa' => 'coffees', 'herbat' => 'teas', 'rozpuszczaln' => 'instant', 'puder' => 'powdered', 'trzcin' => 'cane', 'brazow' => 'brown',
        'bial' => 'white', 'mleczn' => 'milk', 'gorzk' => 'dark', 'deserow' => 'dark', 'ciemn' => 'dark', 'mrozon' => 'frozen', 'suszon' => 'dried', 'swiez' => 'fresh',
        'puszk' => 'canned', 'puszc' => 'canned', 'konserwow' => 'canned', 'wedzon' => 'smoked', 'mielon' => 'ground', 'kandyzow' => 'candied', 'solon' => 'salted',
        'naturaln' => 'plain', 'skondensow' => 'condensed', 'proszk' => 'powder', 'plynn' => 'liquid', 'kozi' => 'goat', 'kokosow' => 'coconut', 'czerwon' => 'red',
        'zielon' => 'green', 'zolt' => 'yellow', 'ziemniacz' => 'potato', 'zytni' => 'rye', 'orkisz' => 'spelt',
        'curry' => 'curry powders', 'sezam=' => 'sesame', 'wanilin' => 'vanilla', 'smietank' => 'creams', 'uht' => 'uht',
    ];

    public function __construct(private NutritionRepository $repo, private OffClient $off, private PageAccessService $access)
    {
    }

    /**
     * Słowa nazwy : [bez akcentów, oryginał], bez liczb, gramatur, kodów i słów pomocniczych ; kolejność zachowana
     * (pierwsze słowo = rzeczownik główny : « Mąka pszenna », « Ser mascarpone »).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function words(string $name): array
    {
        $raw = mb_strtolower(trim($name), 'UTF-8');
        $raw = preg_replace('/\d+(?:[.,]\d+)?\s*(?:kg|gr|g|ml|cl|l|mm|cm|%)(?![\p{L}])/u', ' ', $raw);
        $out = [];
        foreach (preg_split('/[^\p{L}]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $f = strtr($w, self::FOLD);
            if (!preg_match('/^[a-z]{3,}$/', $f) || in_array($f, self::STOP, true)) continue;
            $out[] = [$f, $w];
        }
        return $out;
    }

    /**
     * Podobieństwo słów z odmianą (masło / masła, mąka / mąki, truskawka / truskawki) : wspólny początek ≥ 3 litery,
     * reszta ≤ 3 litery w obu słowach (dłuższe słowa : początek ≥ 4) ; różne znaki diakrytyczne w początku obniżają wynik.
     *
     * @param array{0: string, 1: string} $a
     * @param array{0: string, 1: string} $b
     */
    public static function wordScore(array $a, array $b): float
    {
        [$fa, $ra] = $a;
        [$fb, $rb] = $b;
        if ($fa === $fb) return $ra === $rb ? 1.0 : 0.9;
        $la = strlen($fa);
        $lb = strlen($fb);
        $p = 0;
        while ($p < $la && $p < $lb && $fa[$p] === $fb[$p]) $p++;
        if ($p < 3 || $p < min($la, $lb) - 2 || $la - $p > 3 || $lb - $p > 3 || (min($la, $lb) >= 5 && $p < 4)) return 0.0;
        $score = $p / max($la, $lb);
        return mb_substr($ra, 0, $p) === mb_substr($rb, 0, $p) ? $score : $score * 0.85;
    }

    /**
     * Kategorie pasujące do nazwy : każde słowo kategorii jest w nazwie, rzeczownik główny nazwy (pierwsze słowo) albo
     * słowo szczególne (≥ 6 liter : mascarpone, mozzarella) jest w kategorii ; wynik = podobieństwo słów × pokrycie nazwy,
     * dokładniejsza kategoria (więcej słów) wyżej, nazwa polska wyżej, inne języki niżej (chyba że słowa te same).
     * Drugie przejście : nazwa przetłumaczona słowniczkiem, w nazwach angielskich (« via » = en).
     *
     * @param list<array{tag: string, lang: string, name: string}> $categories
     * @return list<array{tag: string, name: string, lang: string, score: float, coverage: float, via: ?string}>
     */
    public static function match(string $name, array $categories, int $limit = 5): array
    {
        $best = [];
        $words = self::words($name);
        if (!$words) return [];
        self::collect($words, $categories, null, 1.0, $best);
        $english = self::translate($words);
        if ($english) self::collect($english, $categories, 'en', 0.92, $best);
        usort($best, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));
        return array_slice(array_values($best), 0, $limit);
    }

    /** Słowa przetłumaczone słowniczkiem (kolejność zachowana ; bez tłumaczenia : « ~słowo », nie pasuje do niczego). */
    public static function translate(array $words): array
    {
        $out = [];
        foreach ($words as [$f]) {
            $hit = null;
            foreach (self::GLOSSARY as $key => $en) {
                $exact = str_ends_with($key, '=');
                $k = rtrim($key, '=');
                if ($exact ? $f === $k : str_starts_with($f, $k)) { if ($hit === null || strlen($k) > $hit[0]) $hit = [strlen($k), $en]; }
            }
            // Słowo bez tłumaczenia zostaje jako niedopasowalne : liczy się do pokrycia nazwy.
            if ($hit) { if ($hit[1] !== '') foreach (explode(' ', $hit[1]) as $w) $out[] = [$w, $w]; }
            else $out[] = ['~' . $f, '~' . $f];
        }
        return array_filter($out, fn($w) => $w[0][0] !== '~') ? $out : [];
    }

    /** Słowo obce : identyczne (1) ; w tłumaczeniu także liczba mnoga angielska (strawberry / strawberries). */
    private static function sameWord(string $a, string $b, bool $plural): float
    {
        if ($a === $b) return 1.0;
        if (!$plural) return 0.0;
        [$s, $l] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
        return $l === $s . 's' || $l === $s . 'es' || (str_ends_with($s, 'y') && $l === substr($s, 0, -1) . 'ies') ? 0.95 : 0.0;
    }

    private static function collect(array $m, array $categories, ?string $onlyLang, float $factor, array &$best): void
    {
        $heads = array_values(array_unique(array_map(fn($x) => substr($x[0], 0, 3), array_filter($m, fn($x, $i) => $i === 0 || strlen($x[0]) >= 6, ARRAY_FILTER_USE_BOTH))));
        foreach ($categories as $c) {
            if ($onlyLang !== null && $c['lang'] !== $onlyLang) continue;
            $folded = strtr(mb_strtolower($c['name'], 'UTF-8'), self::FOLD);
            $near = false;
            foreach ($heads as $h) if (str_contains($folded, $h)) { $near = true; break; }
            if (!$near) continue;
            $cw = self::words($c['name']);
            if (!$cw) continue;
            // Polski : odmiana ; inne języki : to samo słowo (tłumaczenie : także liczba mnoga), bez fałszywych przyjaciół (morela ≠ morel).
            $score = fn(array $a, array $b): float => $c['lang'] === 'pl' ? self::wordScore($a, $b) : self::sameWord($a[0], $b[0], $onlyLang !== null);
            $sum = 0.0;
            $exact = count($cw) === count($m);
            foreach ($cw as $w) {
                $s = 0.0;
                foreach ($m as $x) $s = max($s, $score($w, $x));
                if ($s <= 0) continue 2;
                if ($s < 0.9) $exact = false;
                $sum += $s;
            }
            // Pokrycie nazwy ważone długością słów : « Mascarpone » przed « Cheeses » dla « ser mascarpone ».
            $anchor = false;
            $covered = 0;
            $total = 0;
            foreach ($m as $i => $x) {
                $total += strlen($x[0]);
                $s = 0.0;
                foreach ($cw as $w) $s = max($s, $score($x, $w));
                if ($s <= 0) continue;
                $covered += strlen($x[0]);
                if ($i === 0 || (strlen($x[0]) >= 6 && $s >= 0.85)) $anchor = true;
            }
            if (!$anchor) continue;
            $coverage = $covered / $total;
            $score = $sum / count($cw) * (0.55 + 0.45 * $coverage) + 0.03 * (count($cw) - 1);
            if ($onlyLang === null) $score = $c['lang'] === 'pl' ? $score + 0.03 : ($exact ? $score : $score * 0.85);
            $score = round($score * $factor, 3);
            // Jedna pozycja na kategorię : najlepszy wynik, nazwa polska do wyświetlenia, gdy jest.
            $old = $best[$c['tag']] ?? null;
            if ($old === null || $score > $old['score']) {
                $best[$c['tag']] = ['tag' => $c['tag'], 'name' => $c['name'], 'lang' => $c['lang'], 'score' => $score, 'coverage' => round($coverage, 2), 'via' => $onlyLang];
                if ($old !== null && $old['lang'] === 'pl' && $c['lang'] !== 'pl') $best[$c['tag']] = ['name' => $old['name'], 'lang' => 'pl'] + $best[$c['tag']];
            } elseif ($c['lang'] === 'pl' && $old['lang'] !== 'pl') {
                $best[$c['tag']] = ['name' => $c['name'], 'lang' => 'pl'] + $old;
            }
        }
    }

    /**
     * Mediana każdej wartości (na 100 g) z produktów, co najmniej 3 wartości ; energia z kJ, gdy brak kcal ;
     * tłuszcze nasycone ≤ tłuszcz, cukry ≤ węglowodany.
     *
     * @param list<array> $products produkty OFF z polem nutriments
     * @return array{values: array<string, ?float>, sample: int}
     */
    public static function medians(array $products): array
    {
        $lists = array_fill_keys(array_keys(self::FIELDS), []);
        foreach ($products as $p) {
            $n = is_array($p['nutriments'] ?? null) ? $p['nutriments'] : [];
            foreach (self::FIELDS as $col => $key) {
                $v = $n[$key] ?? null;
                if ($col === 'energy_100g' && !is_numeric($v) && is_numeric($n['energy_100g'] ?? null)) $v = (float) $n['energy_100g'] / 4.184;
                if (!is_numeric($v)) continue;
                $v = (float) $v;
                if ($v < 0 || $v > ($col === 'energy_100g' ? 900 : 100)) continue;
                $lists[$col][] = $v;
            }
        }
        $values = [];
        foreach ($lists as $col => $list) {
            if (count($list) < self::MIN_SAMPLE) { $values[$col] = null; continue; }
            sort($list);
            $k = intdiv(count($list), 2);
            $median = count($list) % 2 ? $list[$k] : ($list[$k - 1] + $list[$k]) / 2;
            $values[$col] = round($median, $col === 'energy_100g' ? 0 : ($col === 'salt_100g' ? 2 : 1));
        }
        if ($values['saturated_fat_100g'] !== null && $values['fat_100g'] !== null) $values['saturated_fat_100g'] = min($values['saturated_fat_100g'], $values['fat_100g']);
        if ($values['sugars_100g'] !== null && $values['carbohydrates_100g'] !== null) $values['sugars_100g'] = min($values['sugars_100g'], $values['carbohydrates_100g']);
        return ['values' => $values, 'sample' => count($lists['energy_100g'])];
    }

    /**
     * Propozycja dla surowca : kategorie pasujące do nazwy (albo frazy), wartości wybranej kategorii (podanej albo
     * pierwszej z co najmniej 3 produktami). « fetched » = zapytanie do OFF (przeglądarka odczekuje przed kolejnym).
     */
    public function lookup(int $materialId, ?string $query, ?string $tag, ?int $userId): array
    {
        $this->guard($userId);
        $material = $this->repo->material($materialId);
        if ($material === null) throw new CustomException('Nie ma takiego surowca.', 'errors', 404);
        $query = trim((string) $query) !== '' ? mb_substr(trim((string) $query), 0, 120) : (string) $material['name'];
        $this->ensureCategories();
        $candidates = self::match($query, $this->repo->categories());
        $fetched = false;
        $chosen = null;
        $tag = $tag !== null && trim($tag) !== '' ? trim($tag) : null;
        if ($tag !== null) {
            $name = $this->repo->categoryName($tag);
            if ($name === null) throw new CustomException('Nie ma takiej kategorii Open Food Facts.', 'errors', 422);
            $chosen = $this->category($tag, $name['name'], $fetched);
        } else {
            // Najwyżej jedno zapytanie do OFF na surowiec (limit 10 na minutę) : kategorie z pamięci sprawdzane bez limitu.
            foreach (array_slice($candidates, 0, 3) as $c) {
                if ($fetched && $this->repo->cache($c['tag']) === null) continue;
                $chosen = $this->category($c['tag'], $c['name'], $fetched);
                if ($chosen['sample'] >= self::MIN_SAMPLE) break;
            }
        }
        $current = [];
        foreach (array_keys(self::FIELDS) as $col) $current[$col] = (float) $material[$col];
        $top = $candidates[0] ?? null;
        return [
            'material' => ['id' => (int) $material['id'], 'name' => $material['name'], 'values' => $current], 'query' => $query,
            'candidates' => $candidates, 'chosen' => $chosen !== null && $chosen['sample'] >= self::MIN_SAMPLE ? $chosen : null,
            'confident' => $chosen !== null && $top !== null && $chosen['tag'] === $top['tag'] && $top['coverage'] >= 1.0 && $top['score'] >= 0.8 && $chosen['sample'] >= 5,
            'fetched' => $fetched,
        ];
    }

    /** Zapis wybranych wartości (zatwierdzonych przez użytkownika) i źródła. */
    public function apply(int $materialId, array $input, ?int $userId): array
    {
        $this->guard($userId);
        if ($this->repo->material($materialId) === null) throw new CustomException('Nie ma takiego surowca.', 'errors', 404);
        $values = [];
        foreach ((array) ($input['values'] ?? []) as $col => $v) {
            if (!array_key_exists($col, self::FIELDS)) throw new CustomException('Nieznane pole : ' . $col . '.', 'errors', 422);
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > ($col === 'energy_100g' ? 900 : 100)) throw new CustomException('Niepoprawna wartość : ' . $col . '.', 'errors', 422);
            $values[$col] = $col === 'energy_100g' ? (int) round((float) $v) : round((float) $v, 2);
        }
        if (!$values) throw new CustomException('Zaznacz wartości do zapisania.', 'errors', 422);
        $tag = trim((string) ($input['tag'] ?? ''));
        $name = $tag !== '' ? $this->repo->categoryName($tag) : null;
        if ($name === null) throw new CustomException('Brak kategorii źródła (Open Food Facts).', 'errors', 422);
        $cache = $this->repo->cache($tag);
        $this->repo->saveValues($materialId, $values);
        $this->repo->setSource($materialId, $tag, $name['name'], (int) ($cache['sample'] ?? 0), $userId, date('Y-m-d H:i:s'));
        return ['material' => $this->repo->material($materialId), 'source' => $this->repo->sources()[$materialId] ?? null];
    }

    /** Ręczna zmiana wartości : źródło z internetu już nie opisuje surowca. */
    public function manualEdit(int $materialId): void
    {
        $this->repo->deleteSource($materialId);
    }

    /** @return array<int, array> */
    public function sources(): array
    {
        return $this->repo->sources();
    }

    public static function categoryUrl(string $tag): string
    {
        return 'https://world.openfoodfacts.org/facets/categories/' . rawurlencode(str_starts_with($tag, 'en:') ? substr($tag, 3) : $tag);
    }

    /** Kategorie OFF : pobrane przy pierwszym użyciu, odświeżane co 30 dni (stare zostają, gdy OFF nie odpowiada). */
    private function ensureCategories(): void
    {
        $at = $this->repo->categoriesLoadedAt();
        if ($at !== null && strtotime($at) > time() - self::TAXONOMY_DAYS * 86400) return;
        try {
            $rows = $this->off->categories();
            if ($rows) $this->repo->replaceCategories($rows, date('Y-m-d H:i:s'));
        } catch (CustomException $e) {
            if ($at === null) throw $e;
        }
    }

    /** Mediany kategorii : z pamięci (30 dni) albo z OFF. */
    private function category(string $tag, string $name, bool &$fetched): array
    {
        $cache = $this->repo->cache($tag);
        if ($cache === null || strtotime((string) $cache['fetched_at']) < time() - self::CACHE_DAYS * 86400) {
            $r = $this->off->products($tag);
            $fetched = true;
            $m = self::medians($r['products']);
            $this->repo->saveCache($tag, $r['count'], $m['sample'], $m['values'], date('Y-m-d H:i:s'));
            $cache = ['products' => $r['count'], 'sample' => $m['sample'], 'values' => $m['values']];
        }
        return ['tag' => $tag, 'name' => $name, 'url' => self::categoryUrl($tag), 'products' => (int) $cache['products'], 'sample' => (int) $cache['sample'], 'values' => $cache['values']];
    }

    private function guard(?int $userId): void
    {
        if (!$this->access->canSee($userId, self::PAGE)) throw new CustomException('Brak dostępu do wartości odżywczych.', 'errors', 403);
    }
}
