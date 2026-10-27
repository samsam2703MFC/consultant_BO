<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

/**
 * Odczyt faktur zakupowych (bez bazy danych) : XML KSeF (schemat FA(2) / FA(3) — Podmiot1 = sprzedawca,
 * Fa/P_1 data, P_2 numer, FaWiersz : P_7 nazwa, P_8A jednostka, P_8B ilość, P_9A cena netto, P_11 wartość netto,
 * P_12 stawka VAT) albo tekst PDF z pdftotext -layout (najlepiej jak się da : linia = nazwa + ilość × cena
 * = wartość, sprawdzone arytmetycznie ; reszta do uzupełnienia ręcznie). Normalizacja nazw i jednostek
 * do przypisywania nazw dostawcy do naszych surowców.
 */
final class InvoiceParser
{
    /** Jednostki faktury → jednostka znormalizowana. */
    private const UNITS = [
        'szt' => 'szt', 'sztuk' => 'szt', 'sztuka' => 'szt', 'pcs' => 'szt', 'kg' => 'kg', 'g' => 'g', 'gr' => 'g', 'dag' => 'dag',
        'l' => 'l', 'litr' => 'l', 'ltr' => 'l', 'ml' => 'ml', 'op' => 'op', 'opak' => 'op', 'opakowanie' => 'op', 'kpl' => 'kpl',
        'karton' => 'karton', 'kart' => 'karton', 'krt' => 'karton', 'worek' => 'worek', 'wor' => 'worek', 'but' => 'but', 'butelka' => 'but',
        'rolka' => 'rolka', 'rol' => 'rolka', 'm' => 'm', 'mb' => 'm', 'pal' => 'pal', 'paleta' => 'pal', 'wiadro' => 'wiadro', 'kanister' => 'kanister', 'tona' => 't', 't' => 't',
    ];
    /** Jednostki opakowania dostawcy : przelicznik domyślny = opakowanie surowca. */
    private const PACKAGE_UNITS = ['op', 'kpl', 'karton', 'worek', 'but', 'rolka', 'pal', 'wiadro', 'kanister'];
    private const CURRENCY = '/^(z[łl]|PLN|EUR|€)$/iu';
    private const SKIP = '/\b(razem|suma|do zap[łl]aty|w tym|stawka|podsumowanie|og[óo][łl]em|warto[śs][ćc] brutto|zap[łl]acono|pozosta[łl]o)\b/iu';

    // --- XML KSeF -------------------------------------------------------------------------------------------------

    /** @return array{number:?string, issue_date:?string, currency:string, seller_nip:?string, seller_name:?string, buyer_nip:?string, total_net:?float, total_gross:?float, lines:list<array>} */
    public static function ksefXml(string $xml): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || $doc->documentElement === null || $doc->documentElement->localName !== 'Faktura') throw new \InvalidArgumentException('NOT_KSEF_XML');
        $xp = new \DOMXPath($doc);
        $path = fn(string $p) => './' . implode('/', array_map(fn($n) => "*[local-name()='{$n}']", explode('/', $p)));
        $text = function (string $p, ?\DOMNode $ctx = null) use ($xp, $path, $doc): ?string {
            $n = $xp->query($path($p), $ctx ?? $doc->documentElement)->item(0);
            $v = $n ? trim($n->textContent) : '';
            return $v === '' ? null : $v;
        };
        $num = fn(?string $v) => $v === null ? null : (is_numeric($v) ? (float) $v : null);
        $lines = [];
        foreach ($xp->query($path('Fa/FaWiersz')) as $i => $row) {
            $qty = $num($text('P_8B', $row)) ?? 1.0;
            $rate = $text('P_12', $row);
            $net = $num($text('P_11', $row));
            $price = $num($text('P_9A', $row));
            if ($net === null && ($gross = $num($text('P_11A', $row))) !== null) $net = is_numeric($rate) ? round($gross / (1 + (float) $rate / 100), 2) : $gross;
            if ($price === null && ($grossPrice = $num($text('P_9B', $row))) !== null) $price = is_numeric($rate) ? $grossPrice / (1 + (float) $rate / 100) : $grossPrice;
            if ($price === null && $net !== null && $qty > 0) $price = $net / $qty;
            if ($net === null && $price !== null) $net = round($price * $qty, 2);
            $lines[] = ['line_no' => (int) ($text('NrWierszaFa', $row) ?? $i + 1), 'name' => (string) ($text('P_7', $row) ?? '—'),
                'code' => $text('Indeks', $row) ?? $text('GTIN', $row), 'quantity' => $qty, 'unit' => $text('P_8A', $row),
                'unit_price' => $price, 'net_value' => $net, 'vat_rate' => $rate];
        }
        $totalNet = null;
        foreach (['P_13_1', 'P_13_2', 'P_13_3', 'P_13_4', 'P_13_5', 'P_13_6_1', 'P_13_6_2', 'P_13_6_3', 'P_13_7', 'P_13_8', 'P_13_9', 'P_13_10', 'P_13_11'] as $f) {
            if (($v = $num($text('Fa/' . $f))) !== null) $totalNet = ($totalNet ?? 0.0) + $v;
        }
        return [
            'number' => $text('Fa/P_2'), 'issue_date' => self::date($text('Fa/P_1')), 'currency' => strtoupper($text('Fa/KodWaluty') ?? 'PLN'),
            'seller_nip' => self::nip($text('Podmiot1/DaneIdentyfikacyjne/NIP')), 'seller_name' => $text('Podmiot1/DaneIdentyfikacyjne/Nazwa'),
            'buyer_nip' => self::nip($text('Podmiot2/DaneIdentyfikacyjne/NIP')),
            'total_net' => $totalNet !== null ? round($totalNet, 2) : (array_sum(array_map(fn($l) => (float) ($l['net_value'] ?? 0), $lines)) ?: null),
            'total_gross' => $num($text('Fa/P_15')), 'lines' => $lines,
        ];
    }

    // --- Tekst PDF ------------------------------------------------------------------------------------------------

    /** Tekst z pdftotext -layout ; $ownNip = NIP firmy (sprzedawca = pierwszy inny NIP). */
    public static function pdfText(string $text, ?string $ownNip): array
    {
        $own = self::nip($ownNip);
        $nips = [];
        if (preg_match_all('/NIP\s*:?\s*(?:PL\s*)?([0-9][0-9\-\s]{8,14}[0-9])/iu', $text, $m)) foreach ($m[1] as $raw) if (($n = self::nip($raw)) && $n !== $own) $nips[] = $n;
        $number = null;
        foreach (['/Faktura\s+(?:VAT\s+)?(?:nr\.?|numer)\s*:?\s*([A-Z0-9][\w\/\-.]{1,40})/iu', '/(?:Nr|Numer)\s+faktury\s*:?\s*([A-Z0-9][\w\/\-.]{1,40})/iu',
                  '/Faktura\s+(?:VAT\s+)?([A-Z0-9]*\d[\w\-.]*\/[\w\/\-.]+)/iu'] as $re) {
            if (preg_match($re, $text, $mm)) { $number = rtrim($mm[1], '.'); break; }
        }
        $date = null;
        if (preg_match('/Data\s+wystawienia\s*:?\s*(\d{4}-\d{2}-\d{2}|\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4})/iu', $text, $mm)) $date = self::date($mm[1]);
        elseif (preg_match('/\b(\d{4}-\d{2}-\d{2}|\d{2}[.\-\/]\d{2}[.\-\/]\d{4})\b/u', $text, $mm)) $date = self::date($mm[1]);
        $seller = null;
        if (preg_match('/Sprzedawca\s*:?[ \t]*([^\n]*)\n?([^\n]*)/iu', $text, $mm)) {
            $first = trim(preg_split('/\s{2,}/', trim($mm[1]))[0] ?? '');
            $second = trim(preg_split('/\s{2,}/', trim($mm[2]))[0] ?? '');
            $seller = $first !== '' && !preg_match('/^(Nabywca|NIP)/iu', $first) ? $first : ($second !== '' && !preg_match('/^(Nabywca|NIP)/iu', $second) ? $second : null);
        }
        $gross = null;
        if (preg_match('/Do\s+zap[łl]aty\s*:?\s*([\d \x{00A0}.,]+\d)/iu', $text, $mm)) $gross = self::number(trim($mm[1]));
        $lines = [];
        $previousText = '';
        foreach (preg_split('/\R/u', $text) as $raw) {
            $line = trim(str_replace("\u{00A0}", ' ', $raw));
            if ($line === '') continue;
            $parsed = preg_match(self::SKIP, $line) ? null : self::pdfLine($line);
            if ($parsed === null) { if (!preg_match('/\d/', $line)) $previousText = $line; continue; }
            if ($parsed['name'] === '') $parsed['name'] = $previousText;
            if ($parsed['name'] === '') continue;
            $parsed['line_no'] = count($lines) + 1;
            $lines[] = $parsed;
        }
        $currency = preg_match('/\bEUR\b/u', $text) && !preg_match('/\bPLN\b|\bz[łl]\b/u', $text) ? 'EUR' : 'PLN';
        return ['number' => $number, 'issue_date' => $date, 'currency' => $currency, 'seller_nip' => $nips[0] ?? null, 'seller_name' => $seller, 'buyer_nip' => $own,
            'total_net' => $lines ? round(array_sum(array_column($lines, 'net_value')), 2) : null, 'total_gross' => $gross, 'lines' => $lines];
    }

    /** Czy PDF ma tekst (inaczej skan : obraz bez warstwy tekstu). */
    public static function hasText(string $text): bool
    {
        return preg_match_all('/[\p{L}\p{N}]/u', $text) >= 40;
    }

    /**
     * Tekst OCR (skan, zdjęcie) → jak tekst pdftotext -layout : kreski tabeli = odstęp kolumn, Lp pominięte, pomyłki
     * w liczbach (O → 0, l / I → 1 w liczbie zaczętej cyfrą, « 12. », « 3 ,50 »), « SZ » → szt. Potem pdfText.
     */
    public static function ocrText(string $text): string
    {
        $out = [];
        foreach (preg_split('/\R/u', str_replace(["\u{00A0}", "\t"], [' ', '   '], $text)) as $line) {
            $line = preg_replace('/\s*[|¦]\s*/u', '   ', $line);
            // Resztki kresek tabeli i kropki (« ! », « I », « [ », « , ») osobno, Lp na początku wiersza (« 1 », « [4 », « b ») przed kolumną.
            $line = preg_replace('/(?<=\s)[!Il\[\],.;:\'’_—-]{1,2}(?=\s|$)/u', ' ', ' ' . $line);
            $line = preg_replace('/^\s*(?:\S{1,2}\s{2,}){1,2}(?=\p{L}{4})/u', '', $line);
            $line = preg_replace('/(?<=\d)\.(?=\s)/u', '', $line);
            $line = preg_replace('/(?<=\s)s[z2][tl1i]?\.?(?=\s|$)/iu', 'szt', $line);
            $line = preg_replace_callback('/(?<![\p{L}\d\/-])\d[\dOolI.,]*[\dOo](?![\p{L}])/u', fn($m) => strtr($m[0], ['O' => '0', 'o' => '0', 'l' => '1', 'I' => '1']), $line);
            $line = preg_replace('/(?<=\d) ?, ?(?=\d{2}(?!\d))/u', ',', $line);
            $out[] = rtrim($line);
        }
        return mb_substr(trim(implode("\n", $out), "\n"), 0, 200000);
    }

    /** Linia pozycji : kolumny (2+ spacje) ; ilość × cena = wartość (tolerancja 0,5 %), nazwa przed liczbami ; waluta przy kwocie pomijana. */
    private static function pdfLine(string $line): ?array
    {
        $cols = preg_split('/\s{2,}/u', $line);
        if (count($cols) < 3) $cols = preg_split('/\s+/u', $line);
        $tokens = [];
        foreach ($cols as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (preg_match('/^(\d{1,2}(?:[.,]\d+)?)\s?%$/u', $c, $m)) { $tokens[] = ['vat', str_replace(',', '.', $m[1])]; continue; }
            if (preg_match('/^(zw|np|oo)\.?$/iu', $c)) { $tokens[] = ['vat', strtolower(rtrim($c, '.'))]; continue; }
            if (($n = self::number($c)) !== null) { $tokens[] = ['n', $n]; continue; }
            if (preg_match(self::CURRENCY, $c)) { $tokens[] = ['c', $c]; continue; }
            if (preg_match('/^(-?[\d \.,]+?)\s*(?:z[łl]|PLN|EUR|€)$/iu', $c, $m) && ($n = self::number(trim($m[1]))) !== null) { $tokens[] = ['n', $n]; continue; }
            if (preg_match('/^(-?[\d \.,]+?)\s*([a-ząęółśżźćń]+\.?)$/iu', $c, $m) && ($n = self::number(trim($m[1]))) !== null && self::unit($m[2]) !== null) {
                $tokens[] = ['n', $n]; $tokens[] = ['u', self::unit($m[2])]; continue;
            }
            if (self::unit($c) !== null) { $tokens[] = ['u', self::unit($c)]; continue; }
            $tokens[] = ['t', $c];
        }
        $nums = [];
        foreach ($tokens as $i => $tk) if ($tk[0] === 'n') $nums[] = $i;
        for ($k = 0; $k + 2 < count($nums); $k++) {
            [$a, $b, $c] = [$nums[$k], $nums[$k + 1], $nums[$k + 2]];
            // Między ilością, ceną i wartością tylko jednostka albo waluta (albo nic).
            foreach ([[$a, $b], [$b, $c]] as [$x, $y]) for ($j = $x + 1; $j < $y; $j++) if (!in_array($tokens[$j][0], ['u', 'c'], true)) continue 3;
            [$qty, $price, $value] = [$tokens[$a][1], $tokens[$b][1], $tokens[$c][1]];
            if ($qty <= 0 || $price <= 0 || $value <= 0 || abs($qty * $price - $value) > max(0.02, $value * 0.005)) continue;
            $nameParts = [];
            foreach (array_slice($tokens, 0, $a) as $j => $tk) {
                if ($tk[0] === 't') $nameParts[] = $tk[1];
                elseif ($tk[0] === 'n' && $j > 0 && $nameParts) $nameParts[] = rtrim(rtrim(number_format($tk[1], 3, '.', ''), '0'), '.');
            }
            $unit = null;
            for ($j = max(0, $a - 1); $j < $b; $j++) if ($tokens[$j][0] === 'u') $unit = $tokens[$j][1];
            $vat = null;
            for ($j = $c + 1; $j < count($tokens); $j++) if ($tokens[$j][0] === 'vat') { $vat = $tokens[$j][1]; break; }
            $name = trim(preg_replace(['/^\d{1,3}[.)]?\s+/u', '/^[^\p{L}\p{N}]+/u'], '', implode(' ', $nameParts)));
            return ['name' => mb_substr($name, 0, 500), 'code' => null, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $price, 'net_value' => round($value, 2), 'vat_rate' => $vat];
        }
        return null;
    }

    // --- Normalizacja ---------------------------------------------------------------------------------------------

    /** Liczba w zapisie polskim lub kropkowym : 1 234,56 · 1.234,56 · 1234.56 ; inaczej null. */
    public static function number(string $s): ?float
    {
        $s = trim(str_replace("\u{00A0}", ' ', $s));
        if (!preg_match('/^-?(\d{1,3}(?:[ .]\d{3})+|\d+)(?:,\d+)?$|^-?\d+(?:\.\d+)?$/u', $s)) return null;
        if (str_contains($s, ',')) $s = str_replace([' ', '.', ','], ['', '', '.'], $s);
        elseif (preg_match('/^-?\d{1,3}(?: \d{3})+$/u', $s)) $s = str_replace(' ', '', $s);
        elseif (preg_match('/^-?\d{1,3}(?:\.\d{3}){2,}$/u', $s)) $s = str_replace('.', '', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    public static function unit(?string $u): ?string
    {
        if ($u === null) return null;
        $k = rtrim(mb_strtolower(trim($u)), '.');
        return self::UNITS[$k] ?? null;
    }

    /** Jednostka do klucza przypisania : znana → znormalizowana, inaczej małe litery bez kropki. */
    public static function unitKey(?string $u): string
    {
        return self::unit($u) ?? mb_substr(rtrim(mb_strtolower(trim((string) $u)), '.'), 0, 20);
    }

    public static function nameKey(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $n)), 0, 300);
    }

    public static function nip(?string $s): ?string
    {
        $d = preg_replace('/\D/', '', (string) $s);
        return strlen($d) === 10 ? $d : null;
    }

    public static function date(?string $s): ?string
    {
        if ($s === null) return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $s, $m)) return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        return null;
    }

    /**
     * Przelicznik domyślny : ile jednostek bazowych surowca (g, ml, szt) w jednostce faktury — kg→g 1000, l→ml 1000,
     * sztuka / opakowanie z wagą w nazwie (« WOREK 25KG », « 10x1kg », « 0,5 L ») → ta waga, inaczej opakowanie
     * surowca ; do poprawienia przy przypisaniu.
     */
    public static function defaultFactor(?string $invoiceUnit, ?string $baseUnit, ?float $packageSize, ?string $lineName = null): float
    {
        $u = self::unit($invoiceUnit);
        $b = mb_strtolower((string) $baseUnit);
        $pack = $packageSize && $packageSize > 0 ? $packageSize : null;
        $named = ($u === null || $u === 'szt' || in_array($u, self::PACKAGE_UNITS, true)) && $lineName !== null ? self::packSize($lineName) : null;
        if ($named !== null) {
            $inBase = match (true) {
                $named[1] === 'g' && $b === 'g', $named[1] === 'ml' && $b === 'ml' => $named[0],
                $named[1] === 'g' && $b === 'kg', $named[1] === 'ml' && $b === 'l' => $named[0] / 1000,
                default => null,
            };
            if ($inBase !== null && $inBase > 0) return round($inBase, 4);
        }
        return match (true) {
            $u === 'kg' && $b === 'g', $u === 'l' && $b === 'ml' => 1000.0,
            $u === 't' && $b === 'g' => 1000000.0,
            $u === 't' && $b === 'kg' => 1000.0,
            $u === 'dag' && $b === 'g' => 10.0,
            $u === 'g' && $b === 'kg', $u === 'ml' && $b === 'l' => 0.001,
            in_array($u, self::PACKAGE_UNITS, true) => $pack ?? 1.0,
            $u === 'szt' && $b !== 'szt' && $pack !== null => $pack,
            default => 1.0,
        };
    }

    /**
     * Waga / objętość opakowania w nazwie linii : « WOREK 25KG » → [25000, 'g'], « 10x1kg » → [10000, 'g'],
     * « DROZDZE 0,5KG » → [500, 'g'], « 1L » → [1000, 'ml'] ; ostatnia w nazwie ; null gdy brak.
     * @return array{0: float, 1: 'g'|'ml'}|null
     */
    public static function packSize(string $name): ?array
    {
        $s = self::fold(mb_strtolower($name));
        if (!preg_match_all('/(?<![\p{L}\d.,])(?:(\d{1,3})\s*[x×*]\s*)?(\d+(?:[.,]\d+)?)\s*(kg|dag|gr|g|ml|l)(?![\p{L}\d])/u', $s, $m, PREG_SET_ORDER)) return null;
        $last = end($m);
        $qty = (float) str_replace(',', '.', $last[2]) * ($last[1] !== '' ? (int) $last[1] : 1);
        [$k, $unit] = match ($last[3]) { 'kg' => [1000, 'g'], 'dag' => [10, 'g'], 'gr', 'g' => [1, 'g'], 'l' => [1000, 'ml'], 'ml' => [1, 'ml'] };
        return $qty > 0 ? [round($qty * $k, 4), $unit] : null;
    }

    /** Bez polskich (i francuskich) znaków diakrytycznych : porównanie nazw z faktur pisanych bez ogonków. */
    private static function fold(string $s): string
    {
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'à' => 'a', 'â' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ö' => 'o', 'ä' => 'a']);
    }

    /**
     * Podobieństwo nazw 0…1 : wspólne słowa (≥ 3 znaki lub liczby) do mniejszego zbioru, bez polskich znaków
     * (« MASLO » = « Masło », faktury często bez ogonków). Wymiary « 590 x 390 x 210 »
     * = « 590x390x210 » ; słowa z cyframi muszą być równe (bez dopasowania początku : 590x290 ≠ 590x390).
     */
    public static function similarity(string $a, string $b): float
    {
        $words = fn(string $s) => array_values(array_unique(array_filter(explode(' ', self::nameKey(preg_replace('/(\d)\s*[x×*]\s*(?=\d)/u', '$1x', self::fold(mb_strtolower($s))))),
            fn($w) => mb_strlen($w) >= 3 || ctype_digit($w))));
        [$x, $y] = [$words($a), $words($b)];
        if (!$x || !$y) return 0.0;
        $common = 0;
        $letters = fn(string $w) => mb_strlen($w) >= 4 && !preg_match('/\d/', $w);
        foreach ($x as $w) {
            foreach ($y as $v) {
                if ($w === $v || ($letters($w) && $letters($v) && (str_starts_with($w, mb_substr($v, 0, 4)) || str_starts_with($v, mb_substr($w, 0, 4))))) { $common++; break; }
            }
        }
        return $common / min(count($x), count($y));
    }
}
