<?php
declare(strict_types=1);

namespace App\Services\DevCard;

/**
 * Koszt nowego produktu [198] : składnik z karty (« 500 g » + « mąki pszennej ») → nasz surowiec i ilość w jednostce
 * surowca. Nazwy po polsku : słowa bez opisów (« letniego », « startego »…), porównanie rdzeni (mąki ↔ mąka,
 * soli ↔ sól, czosnku ↔ czosnek ; mąka ≠ mak), kilka synonimów (kaşar → ser żółty), pamięć wyborów operatora.
 * Ilości : g, kg, dag, ml, l, łyżka ≈ 15 g, łyżeczka ≈ 5 g, szklanka ≈ 250 ml, szczypta ≈ 1 g, garść ≈ 30 g,
 * sztuki z wagą typową (jajko ≈ 55 g, ząbek czosnku ≈ 5 g).
 */
final class IngredientMatcher
{
    /** Opisy, przyimki, stany — bez znaczenia dla dopasowania (po złożeniu akcentów). */
    private const STOP = ['w', 'z', 'ze', 'do', 'na', 'i', 'oraz', 'lub', 'albo', 'np', 'dla', 'od', 'po', 'przez', 'ok', 'okolo', 'bez',
        'temp', 'temperaturze', 'temperatura', 'pokojowej', 'pokojowa', 'letniego', 'letniej', 'letnia', 'letnie', 'cieplego', 'ciepla', 'cieple',
        'cieplej', 'zimnego', 'zimnej', 'zimna', 'zimne', 'startego', 'starty', 'startej', 'tartego', 'tarty', 'posiekanej', 'posiekana',
        'posiekany', 'posiekane', 'posiekanego', 'drobno', 'grubo', 'swiezego', 'swieza', 'swieze', 'swiezej', 'swiezy', 'roztopionego',
        'roztopione', 'roztopionej', 'miekkiego', 'miekkie', 'pokrojonego', 'pokrojony', 'pokrojone', 'pokrojonej', 'kostce', 'kostke',
        'plasterkach', 'plastry', 'duzej', 'duza', 'duze', 'duzy', 'duzego', 'malej', 'mala', 'male', 'maly', 'malego', 'sredniej', 'srednia',
        'sredni', 'kilka', 'troche', 'odrobina', 'smaku', 'opcjonalnie', 'posypania', 'posmarowania', 'smarowania', 'dekoracji', 'podsypania',
        'wedlug', 'uznania', 'jak', 'potrzeby', 'szt', 'sztuki', 'sztuk', 'gram', 'gramow', 'typ', 'typu', 'czerwona', 'czerwonej', 'czerwony',
        'czerwone', 'zielona', 'zielonej', 'zielony', 'zielone', 'zolta', 'zoltej', 'zolty', 'zolte', 'biala', 'bialej', 'bialy', 'biale', 'bialego',
        'czarna', 'czarnej', 'czarny', 'czarne', 'suchych', 'suche', 'suchej', 'suchy', 'instant', 'przesianej', 'przesiana', 'przesianego',
        'neutralnego', 'neutralny', 'neutralnej', 'rafinowanego', 'odlozonego', 'odlozonej', 'sztuke', 'sztuka',
        // Jednostki w nazwie (« 1 » + « plaster sera ») : liczone w ilości, nie w dopasowaniu.
        'plaster', 'plastra', 'plastry', 'plasterek', 'plasterki', 'plasterka', 'lyzka', 'lyzki', 'lyzek', 'lyzeczka', 'lyzeczki', 'lyzeczek',
        'szklanka', 'szklanki', 'szczypta', 'szczypty', 'garsc', 'zabek', 'zabki', 'zabkow', 'kostka', 'kostki'];

    /** Rdzeń słowa (po złożeniu akcentów) → słowa dodane do szukania (nazwy obce, części jajka). */
    private const SYNONYMS = ['kasar' => ['ser', 'zolty'], 'kaszar' => ['ser', 'zolty'], 'ementaler' => ['ser'], 'emmentaler' => ['ser'], 'gouda' => ['ser'],
        'edam' => ['ser'], 'cheddar' => ['ser'], 'mozzarell' => ['mozzarella', 'ser'], 'biber' => ['papryka'], 'salcas' => ['pasta'],
        'zoltk' => ['jajka'], 'zoltek' => ['jajka'], 'bialko' => ['jajka'], 'bialka' => ['jajka'], 'jaj' => ['jajka']];

    /** Woda, lód : bez kosztu. */
    private const FREE = ['woda', 'wody', 'wode', 'lod', 'lodu', 'wrzatku', 'wrzatek', 'wrzatkiem', 'wrzacej', 'wrzaca'];

    /** Waga typowa sztuki (g) : rdzeń nazwy → gramy. */
    private const PIECE_G = ['zoltk' => 18, 'zoltek' => 18, 'bialk' => 35, 'jajk' => 55, 'jajek' => 55, 'jaja' => 55, 'jajo' => 55, 'cebul' => 100, 'papryk' => 150,
        'pomidor' => 120, 'ziemniak' => 150, 'cytryn' => 100, 'limonk' => 60, 'pomarancz' => 200, 'jablk' => 180, 'jablek' => 180, 'gruszk' => 170,
        'banan' => 120, 'marchew' => 80, 'marchw' => 80, 'ogor' => 150, 'pieczark' => 20, 'cukini' => 250, 'baklazan' => 300, 'por' => 150,
        'czosnek' => 50, 'czosnku' => 50, 'parowk' => 50, 'kielbask' => 60];

    /** Jednostka w ilości → [rodzaj, ile g / ml / szt w jednej, przybliżenie ?]. */
    private const UNITS = [
        ['~^(kg|kilogram\w*)$~u', 'g', 1000, false], ['~^(dag|dkg)$~u', 'g', 10, false], ['~^(g|gr|gram\w*)$~u', 'g', 1, false],
        ['~^(l|litr\w*)$~u', 'ml', 1000, false], ['~^(ml|mililitr\w*)$~u', 'ml', 1, false], ['~^(cl)$~u', 'ml', 10, false],
        ['~^(łyżecz\w*|lyzecz\w*|łyż\.?\s*mała|tsp)$~u', 'g', 5, true], ['~^(łyż\w*|lyz\w*|ł\.|tbsp)$~u', 'g', 15, true],
        ['~^(szklan\w*)$~u', 'ml', 250, true], ['~^(cups?)$~u', 'ml', 240, true], ['~^(szczypt\w*)$~u', 'g', 1, true], ['~^(garś\w*|gars\w*)$~u', 'g', 30, true],
        ['~^(ząb\w*|zab\w*)$~u', 'g', 5, true], ['~^(plast\w*)$~u', 'g', 20, true], ['~^(szt\.?|sztuk\w*)$~u', 'szt', 1, false],
    ];

    // --- Nazwy ---------------------------------------------------------------------------------------------

    /** Małe litery bez akcentów (polskie, tureckie, francuskie). */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ñ' => 'n', 'œ' => 'oe']);
    }

    /**
     * Słowa nazwy : [złożone, oryginalne (małe litery), zamienniki] — co najmniej 3 litery, bez cyfr i słów z listy STOP ;
     * zamienniki z synonimów (kaşaru → ser, żółty) liczą się jak to samo słowo.
     * @return list<array{0:string, 1:string, 2:list<array{0:string, 1:string}>}>
     */
    public static function words(string $name): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $f = self::fold($w);
            if (mb_strlen($w) < 3 || preg_match('/\d/', $w) || in_array($f, self::STOP, true) || isset($out[$f])) continue;
            $alts = [];
            foreach (self::SYNONYMS as $stem => $add) {
                if (str_starts_with($f, $stem)) foreach ($add as $a) $alts[] = [$a, $a];
            }
            $out[$f] = [$f, $w, $alts];
        }
        return array_values($out);
    }

    /** Słowo składnika ma ten sam rdzeń co słowo surowca : 1 ; przez zamiennik (synonim) : 0,8 ; inaczej 0. */
    private static function hits(array $q, array $m): float
    {
        if (self::sameStem($q, $m)) return 1.0;
        foreach ($q[2] ?? [] as $alt) if (self::sameStem($alt, $m)) return 0.8;
        return 0.0;
    }

    /** Klucz pamięci : słowa nazwy (bez opisów) posortowane. */
    public static function nameKey(string $name): string
    {
        $w = array_map(fn($x) => $x[0], self::words(self::mainPart($name)));
        sort($w);
        return mb_substr(implode(' ', $w), 0, 190);
    }

    /**
     * Ten sam rdzeń : wspólny początek ≥ 3 litery dla słów do 4 liter, inaczej ≥ max(4, krótsze − 2) ; ruchome « e »
     * (cukier ↔ cukru, czosnek ↔ czosnku). Krótki wspólny początek (3 litery) musi się zgadzać z akcentami
     * (mąki ↔ mąka tak, mak ↔ mąka nie) ; ó = o (sól ↔ soli).
     */
    public static function sameStem(array $a, array $b): bool
    {
        if ($a[0] === $b[0]) return true;
        foreach (self::variants($a) as $x) foreach (self::variants($b) as $y) if (self::prefixMatch($x, $y)) return true;
        return false;
    }

    /** Słowo i wersja bez ruchomego « e » przed ostatnią spółgłoską (cukier → cukr, puder → pudr, czosnek → czosnk). */
    private static function variants(array $w): array
    {
        $out = [[$w[0], $w[1]]];
        if (preg_match('/^(.{3,}?)i?e([bcdfghjklmnprstwz])$/', $w[0], $m)) {
            $u = preg_replace('/i?e(\p{L})$/u', '$1', $w[1]);
            $out[] = [$m[1] . $m[2], (string) $u];
        }
        return $out;
    }

    private static function prefixMatch(array $a, array $b): bool
    {
        [$fa, $ua] = $a;
        [$fb, $ub] = $b;
        $n = min(strlen($fa), strlen($fb));
        $p = 0;
        while ($p < $n && $fa[$p] === $fb[$p]) $p++;
        if ($p < ($n <= 4 ? 3 : max(4, $n - 2))) return false;
        // Dłuższe słowo : po wspólnym początku najwyżej 4 litery albo przymiotnik « -owy » (pomidorowego ↔ pomidory) ;
        // inaczej to inne słowo (pasty ≠ pasteryzowane, musu ≠ muszkatołowa).
        $rest = substr(strlen($fa) >= strlen($fb) ? $fa : $fb, $p);
        if (strlen($rest) > 4 && !preg_match('/^k?ow/', $rest)) return false;
        if ($p < 4) {
            $o = fn(string $s) => str_replace('ó', 'o', mb_substr($s, 0, $p));
            return $o($ua) === $o($ub);
        }
        return true;
    }

    /** Pierwszy składnik linii (« 1 ząbek czosnku, pieprz, oregano » → « czosnku ») bez nawiasów. */
    public static function mainPart(string $name): string
    {
        $parts = preg_split('/\s*[,+;]\s*|\s+(?:oraz|albo|lub)\s+/u', trim((string) preg_replace('/\([^)]*\)/u', ' ', $name))) ?: [];
        foreach ($parts as $p) if (self::words($p)) return trim($p);
        return trim($name);
    }

    /** « 1 parówka na sztukę » : ilość na jedną sztukę produktu (× sztuk z partii). */
    public static function perPiece(string $name): bool
    {
        return (bool) preg_match('/\bna\s+(?:sztuk[eęi]|szt\b|każd)/u', mb_strtolower($name, 'UTF-8'));
    }

    public static function isFree(string $name): bool
    {
        $w = array_map(fn($x) => $x[0], self::words(self::mainPart($name)));
        return $w !== [] && !array_diff($w, self::FREE);
    }

    // --- Dopasowanie ------------------------------------------------------------------------------------------

    /**
     * Najlepsze surowce dla nazwy składnika : wynik = część słów składnika znaleziona w nazwie surowca (+ 0,15 × część
     * słów surowca) ; przy remisie częściej używany w recepturach.
     * @param list<array{id:int, name:string, uses?:int}> $materials
     * @return list<array{id:int, score:float, qc:float, uses:int}>
     */
    public static function rank(string $name, array $materials, int $limit = 5): array
    {
        $q = self::words(self::mainPart($name));
        $alt = self::words(preg_replace('/[()]/u', ' ', $name) ?? '');
        $scored = self::score($q, $materials);
        // Nic z pierwszego składnika : spróbuj całej linii z nawiasem (« kaşaru (lub ementalera) »).
        if ((!$scored || $scored[0]['qc'] < 0.5) && $alt !== $q) {
            $more = self::score($alt, $materials);
            if ($more && (!$scored || $more[0]['score'] > $scored[0]['score'])) $scored = $more;
        }
        return array_slice($scored, 0, $limit);
    }

    private static function score(array $q, array $materials): array
    {
        if (!$q) return [];
        $out = [];
        foreach ($materials as $m) {
            $mw = $m['_words'] ?? self::words((string) $m['name']);
            if (!$mw) continue;
            // Pierwsze słowo (zwykle rzeczownik : « masła solonego ») waży 1,5.
            $mq = 0.0;
            $weights = 0.0;
            foreach ($q as $i => $w) {
                $best = 0.0;
                foreach ($mw as $v) $best = max($best, self::hits($w, $v));
                $mq += ($i === 0 ? 1.5 : 1.0) * $best;
                $weights += $i === 0 ? 1.5 : 1.0;
            }
            if ($mq <= 0) continue;
            $mm = 0;
            foreach ($mw as $v) foreach ($q as $w) if (self::hits($w, $v) > 0) { $mm++; break; }
            $qc = $mq / $weights;
            $out[] = ['id' => (int) $m['id'], 'score' => round($qc + 0.15 * $mm / count($mw), 4), 'qc' => $qc, 'uses' => (int) ($m['uses'] ?? 0)];
        }
        usort($out, fn($a, $b) => [$b['score'], $b['uses'], $a['id']] <=> [$a['score'], $a['uses'], $b['id']]);
        return $out;
    }

    /**
     * Pewność najlepszego : « auto » gdy wszystkie słowa składnika są w nazwie i nie ma bliskiego drugiego
     * (albo pierwszy jest dużo częściej używany) ; « check » gdy częściowo albo niejednoznacznie ; « missing » gdy brak.
     */
    public static function confidence(array $ranked): string
    {
        if (!$ranked) return 'missing';
        $best = $ranked[0];
        if ($best['qc'] < 1) return 'check';
        $second = $ranked[1] ?? null;
        if ($second === null || $second['score'] <= $best['score'] - 0.1 || $best['uses'] >= 2 * max(1, $second['uses'])) return 'auto';
        return 'check';
    }

    // --- Ilości -----------------------------------------------------------------------------------------------

    /**
     * Ilość z karty : liczba (ułamki, ½, zakres → średnia) i jednostka.
     * @return array{amount: ?float, kind: ?string, approx: bool, word: ?string}  kind : g, ml, szt, kostka, null (nie wiadomo)
     */
    public static function parseQty(string $qty, string $name = ''): array
    {
        // Jednostka na początku nazwy (« 1 » + « plaster sera ») : dołączona do ilości.
        if (preg_match('/^\s*[\d½¼¾⅓⅔]/u', $qty) && !preg_match('/\p{L}/u', $qty) && preg_match('/^(\p{L}+)\s/u', mb_strtolower($name, 'UTF-8'), $m)) {
            foreach (self::UNITS as [$re]) if (preg_match($re, $m[1])) { $qty .= ' ' . $m[1]; break; }
            if (preg_match('~^(kost\w*)$~u', $m[1])) $qty .= ' ' . $m[1];
        }
        $s = trim(strtr(mb_strtolower($qty, 'UTF-8'), ['½' => ' 0.5', '¼' => ' 0.25', '¾' => ' 0.75', '⅓' => ' 0.333', '⅔' => ' 0.667']));
        // Gramatura w nawiasie (« 2½ cup (ok. 310 g) », « 1 łyżeczka (5 ml) ») : ważniejsza niż miara kuchenna.
        if (preg_match('/\((?:ok\.?|około|ca\.?|≈|~)?\s*(\d+(?:[.,]\d+)?)\s*(?:[-–]\s*(\d+(?:[.,]\d+)?)\s*)?(kg|g|ml|l)\b/u', $s, $m)) {
            $a = (float) str_replace(',', '.', $m[1]);
            if (isset($m[2]) && $m[2] !== '') $a = ($a + (float) str_replace(',', '.', $m[2])) / 2;
            $per = ['kg' => 1000, 'g' => 1, 'ml' => 1, 'l' => 1000][$m[3]];
            return ['amount' => $a * $per, 'kind' => in_array($m[3], ['ml', 'l'], true) ? 'ml' : 'g', 'approx' => true, 'word' => null];
        }
        $s = (string) preg_replace('/\b(ok\.?|około|ca\.?|~)\s*/u', '', $s);
        $s = (string) preg_replace('/\bpół\b/u', '0.5', $s);
        $amount = null;
        if (preg_match('~^(\d+(?:[.,]\d+)?)\s*/\s*(\d+)~u', $s, $m) && (float) $m[2] > 0) {
            $amount = (float) str_replace(',', '.', $m[1]) / (float) $m[2];
            $s = trim(mb_substr($s, mb_strlen($m[0])));
        } elseif (preg_match('~^(\d+(?:[.,]\d+)?)(?:\s+(\d(?:[.,]\d+)?)(?=\s|$))?(?:\s*[-–]\s*(\d+(?:[.,]\d+)?))?~u', $s, $m)) {
            $a = (float) str_replace(',', '.', $m[1]) + (isset($m[2]) && $m[2] !== '' ? (float) str_replace(',', '.', $m[2]) : 0.0);
            $amount = isset($m[3]) && $m[3] !== '' ? ($a + (float) str_replace(',', '.', $m[3])) / 2 : $a;
            $s = trim(mb_substr($s, mb_strlen($m[0])));
        }
        $word = preg_split('/\s+/u', $s)[0] ?? '';
        $word = rtrim($word, ',;');
        if ($word !== '' && preg_match('~^(kost\w*)$~u', $word)) return ['amount' => $amount ?? 1.0, 'kind' => 'kostka', 'approx' => true, 'word' => $word];
        foreach (self::UNITS as [$re, $kind, $per, $approx]) {
            if ($word !== '' && preg_match($re, $word)) {
                return ['amount' => ($amount ?? 1.0) * $per, 'kind' => $kind, 'approx' => $approx, 'word' => $word];
            }
        }
        if ($amount !== null) return ['amount' => $amount, 'kind' => 'szt', 'approx' => false, 'word' => null];
        return ['amount' => null, 'kind' => null, 'approx' => false, 'word' => $word !== '' ? $word : null];
    }

    /** Waga typowa jednej sztuki (g) z nazwy składnika albo surowca. */
    public static function pieceGrams(string ...$names): ?float
    {
        foreach ($names as $n) {
            foreach (self::words($n) as [$f]) {
                foreach (self::PIECE_G as $stem => $g) if (str_starts_with($f, $stem)) return (float) $g;
            }
        }
        return null;
    }

    /**
     * Ilość w jednostce surowca (g, ml, szt, kg, l) z ilości karty.
     * @return array{qty: float, known: bool, note: ?string}
     */
    public static function toMaterial(array $q, string $unit, string $srcName, string $matName): array
    {
        $unit = mb_strtolower(trim($unit));
        $mass = in_array($unit, ['g', 'ml'], true) ? 1.0 : (in_array($unit, ['kg', 'l'], true) ? 1000.0 : null);
        $fs = self::fold($srcName);
        $fm = self::fold($matName);
        $notes = [];
        if ($q['kind'] === null || $q['amount'] === null) return ['qty' => 0.0, 'known' => false, 'note' => 'Podaj ilość'];
        $amount = (float) $q['amount'];
        $kind = $q['kind'];
        if ($kind === 'kostka') {
            // Kostka masła 200 g, drożdży 100 g ; inaczej nie wiadomo.
            $g = str_contains($fs, 'masl') || str_contains($fm, 'masl') ? 200.0 : (str_contains($fs, 'drozdz') || str_contains($fm, 'drozdz') ? 100.0 : null);
            if ($g === null) return ['qty' => 0.0, 'known' => false, 'note' => 'Podaj ilość (kostka)'];
            $amount *= $g;
            $kind = 'g';
            $notes[] = '1 kostka ≈ ' . self::num($g) . ' g';
        } elseif ($q['approx'] && $q['word'] !== null) {
            $notes[] = $q['word'] . ' ≈ ' . self::num(self::perUnit($q['word'])) . ' ' . $kind;
        }
        // Drożdże suche (instant) w przepisie, świeże w magazynie : × 3.
        if ((str_contains($fs, 'such') || str_contains($fs, 'instant')) && str_contains($fs, 'drozdz') && str_contains($fm, 'drozdz')
            && !str_contains($fm, 'such') && !str_contains($fm, 'instant')) {
            $amount *= 3;
            $notes[] = 'suche → świeże × 3';
        }
        if ($unit === 'szt') {
            if ($kind === 'szt') return ['qty' => $amount, 'known' => true, 'note' => self::notes($notes)];
            $g = self::pieceGrams($srcName, $matName);
            if ($g === null) return ['qty' => 0.0, 'known' => false, 'note' => 'Podaj liczbę sztuk'];
            return ['qty' => round($amount / $g, 2), 'known' => true, 'note' => self::notes([...$notes, '1 szt. ≈ ' . self::num($g) . ' g'])];
        }
        if ($mass === null) return ['qty' => 0.0, 'known' => false, 'note' => 'Nieznana jednostka surowca'];
        if ($kind === 'szt') {
            $g = self::pieceGrams($srcName, $matName);
            if ($g === null) return ['qty' => 0.0, 'known' => false, 'note' => 'Podaj ilość w ' . ($unit === 'ml' || $unit === 'l' ? 'ml' : 'g')];
            $notes[] = '1 szt. ≈ ' . self::num($g) . ' g';
            $amount *= $g;
        }
        return ['qty' => round($amount / $mass, 3), 'known' => true, 'note' => self::notes($notes)];
    }

    /** Sztuk z wydajności karty : « 2 płaty 35×45 cm ≈ 16 szt. » → 16 ; « 12 bułeczek » → 12. */
    public static function yieldPieces(string $yield): ?int
    {
        $y = mb_strtolower($yield, 'UTF-8');
        if (preg_match('/(\d+)\s*(?:[-–]\s*(\d+)\s*)?(?:szt|sztuk|kawał|porcj|bułecz|bulecz|bułek|rogal|muffin|pączk|paczk|ciastek|ciasteczek|babecz|drożdżów|precl|pizz|kawałk|kul|plack|naleśnik|gofr)/u', $y, $m)) {
            $n = isset($m[2]) && $m[2] !== '' ? intdiv((int) $m[1] + (int) $m[2], 2) : (int) $m[1];
            return $n > 0 ? $n : null;
        }
        if (preg_match('/^\s*(\d+)\b(?!\s*(?:cm|mm|g|kg|ml|l|°|x|×))/u', $y, $m) && (int) $m[1] > 0) return (int) $m[1];
        return null;
    }

    private static function perUnit(string $word): float
    {
        foreach (self::UNITS as [$re, , $per]) if (preg_match($re, $word)) return (float) $per;
        return 1.0;
    }

    private static function notes(array $n): ?string
    {
        return $n ? implode(' · ', $n) : null;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
