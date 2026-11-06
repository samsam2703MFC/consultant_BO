<?php
declare(strict_types=1);

namespace App\Services\Finances\PaymentProof;

/**
 * Wpłata z e-maila [233] : odczyt potwierdzenia przelewu (tekst PDF albo OCR zdjęcia) — bez bazy.
 *
 *  - numery proform FP-00001/28/07/2026 (także po OCR : O → 0, l/I → 1, spacje) ;
 *  - kwota : najpierw w linii z etykietą (Kwota, Montant, Amount, Bedrag, Betrag…) i z walutą, potem z walutą, potem
 *    jakakolwiek kwota z groszami (1 234,56 · 1.234,56 · 1,234.56) ; daty i numery proform nie są kwotami ;
 *  - data operacji : linia z etykietą (Data operacji, Date d'exécution, Uitvoeringsdatum…), inaczej najczęstsza data
 *    (nie w przyszłości, najwyżej 400 dni wstecz, bez dat z numerów proform) ;
 *  - rachunki (IBAN, NRB 26 cyfr), płacący (Zleceniodawca, Donneur d'ordre, Opdrachtgever, Payer…), stan « zrealizowany ».
 */
final class PaymentProofParser
{
    private const AMOUNT_LABELS = '/(kwota|kwota przelewu|kwota operacji|suma|montant|amount|bedrag|betrag|total|importe)/iu';
    private const DATE_STRONG = '/(data operacji|data realizacji|data wykonania|data transakcji|data księgowania|data ksiegowania|data obciążenia|date d.ex[ée]cution|date de l.op[ée]ration|date valeur|date d.op[ée]ration|uitvoeringsdatum|datum uitvoering|valutadatum|execution date|transaction date|value date|booking date|ausf[üu]hrungsdatum|buchungsdatum)/iu';
    private const DATE_LABELS = '/(data zlecenia|^\s*data\b|^\s*date\b|^\s*datum\b)/iu';
    private const PAYER_LABELS = '/^\s*(?:dane\s+)?(zleceniodawca|nadawca|nazwa nadawcy|płatnik|platnik|donneur d.ordre|[ée]metteur|payeur|d[ée]biteur|opdrachtgever|naam opdrachtgever|ordering party|ordering customer|payer|debtor|auftraggeber)\b\s*[:\-–]?\s*(.*)$/imu';
    private const EXECUTED = '/(zrealizowan|wykonan|zaksi[ęe]gowan|executed|completed|ex[ée]cut[ée]|effectu[ée]|uitgevoerd|ausgef[üu]hrt|booked)/iu';

    /**
     * @return array{numbers: list<string>, amount: ?float, currency: ?string, amounts: list<array{value: float, currency: ?string, labelled: bool}>,
     *               date: ?string, dates: list<string>, accounts: list<string>, payer: ?string, executed: bool}
     */
    public static function parse(string $text, string $today): array
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{202F}", "\u{2009}", "\t"], ["\n", "\n", ' ', ' ', ' ', ' '], $text);
        $numbers = [];
        $clean = preg_replace_callback('/F\s*P[\s\-–_]*([0-9OoIl|]{1,5})\s*\/\s*([0-9OoIl|]{1,2})\s*\/\s*([0-9OoIl|]{1,2})\s*\/\s*([0-9OoIl|]{4})/u', function ($m) use (&$numbers) {
            $d = fn(string $s) => (int) strtr($s, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1', '|' => '1']);
            $n = sprintf('FP-%05d/%02d/%02d/%04d', $d($m[1]), $d($m[2]), $d($m[3]), $d($m[4]));
            if (!in_array($n, $numbers, true)) $numbers[] = $n;
            return str_repeat(' ', mb_strlen($m[0]));
        }, $text) ?? $text;

        // Rachunki : IBAN (2 litery + 2 cyfry + grupy) albo NRB (26 cyfr, grupy po 4) ; usunięte przed szukaniem kwot.
        $accounts = [];
        $clean = preg_replace_callback('/\b(?:[A-Z]{2}\s?\d{2}|\d{2})(?:[ ]?[0-9A-Z]{4}){3,7}(?:[ ]?[0-9A-Z]{1,4})?\b/u', function ($m) use (&$accounts) {
            $a = preg_replace('/\s+/', '', $m[0]);
            if (strlen(preg_replace('/\D/', '', $a)) >= 14 && !in_array($a, $accounts, true)) $accounts[] = $a;
            return str_repeat(' ', mb_strlen($m[0]));
        }, $clean) ?? $clean;

        // Daty.
        $min = date('Y-m-d', strtotime($today . ' -400 days'));
        $found = [];
        $lines = explode("\n", $clean);
        foreach ($lines as $i => $line) {
            $prev = $i > 0 && !preg_match('/\d/', $lines[$i - 1]) ? $lines[$i - 1] : '';
            $labelled = preg_match(self::DATE_STRONG, $line) || ($prev !== '' && preg_match(self::DATE_STRONG, $prev)) ? 2
                : (preg_match(self::DATE_LABELS, $line) || ($prev !== '' && preg_match(self::DATE_LABELS, $prev)) ? 1 : 0);
            $cands = [];
            if (preg_match_all('/(?<!\d)(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?!\d)/', $line, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) $cands[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (preg_match_all('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $line, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) $cands[] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }
            foreach ($cands as $c) if ($c <= $today && $c >= $min) $found[] = ['date' => $c, 'labelled' => $labelled, 'line' => $i];
        }
        $date = null;
        foreach ([2, 1] as $strength) foreach ($found as $f) if ($f['labelled'] === $strength) { $date = $f['date']; break 2; }
        if ($date === null && $found) {
            $count = array_count_values(array_column($found, 'date'));
            uksort($count, fn($a, $b) => [$count[$b], $a] <=> [$count[$a], $b]);
            $date = array_key_first($count);
        }

        // Kwoty (daty usunięte, żeby « 30.10 » z daty nie było kwotą).
        $amounts = [];
        foreach ($lines as $i => $line) {
            $line = preg_replace('/(?<!\d)\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}(?!\d)|(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)|\d{1,2}:\d{2}(?::\d{2})?/', ' ', $line);
            $labelled = (bool) preg_match(self::AMOUNT_LABELS, $line) || ($i > 0 && preg_match(self::AMOUNT_LABELS, $lines[$i - 1]) && !preg_match('/\d[.,]\d{2}/', $lines[$i - 1]));
            if (!preg_match_all('/(?<![\d.,])(\d{1,3}(?:[ .,\']\d{3})+|\d+)([.,])(\d{2})(?![\d]|[.,]\d)/u', $line, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
            foreach ($mm as $m) {
                $value = (float) (preg_replace('/\D/', '', $m[1][0]) . '.' . $m[3][0]);
                if ($value <= 0) continue;
                $start = $m[0][1];
                $end = $start + strlen($m[0][0]);
                $after = substr($line, $end, 8);
                $before = substr($line, max(0, $start - 8), min(8, $start));
                $currency = null;
                if (preg_match('/^\s*(EUR|€|PLN|zł|ZŁ|USD|\$)/u', $after, $c) || preg_match('/(EUR|€|PLN|USD)\s*[:=]?\s*$/u', $before, $c)) {
                    $currency = match (mb_strtoupper($c[1])) { '€', 'EUR' => 'EUR', 'PLN', 'ZŁ' => 'PLN', default => 'USD' };
                }
                $amounts[] = ['value' => round($value, 2), 'currency' => $currency, 'labelled' => $labelled];
            }
        }
        $best = null;
        foreach ([[true, true], [true, false], [false, true]] as [$lab, $cur]) {
            foreach ($amounts as $a) if ($a['labelled'] === $lab && ($a['currency'] !== null) === $cur) { $best = $a; break 2; }
        }
        if ($best === null && $amounts) $best = $amounts[0];

        // Płacący : wartość po etykiecie albo w następnej niepustej linii.
        $payer = null;
        $orig = explode("\n", $text);
        foreach ($orig as $i => $line) {
            if (!preg_match(self::PAYER_LABELS, $line, $m)) continue;
            $v = trim($m[2], " \t:-–");
            if ($v === '') for ($j = $i + 1; $j < min(count($orig), $i + 3); $j++) if (trim($orig[$j]) !== '') { $v = trim($orig[$j]); break; }
            $v = trim((string) preg_replace('/\s{2,}.*$/u', '', $v));
            if ($v !== '' && !preg_match('/^\d/', $v)) { $payer = mb_substr($v, 0, 120); break; }
        }

        return [
            'numbers' => $numbers, 'amount' => $best['value'] ?? null, 'currency' => $best['currency'] ?? null, 'amounts' => $amounts,
            'date' => $date, 'dates' => array_values(array_unique(array_column($found, 'date'))), 'accounts' => $accounts,
            'payer' => $payer, 'executed' => (bool) preg_match(self::EXECUTED, $text),
        ];
    }

    /**
     * Nasz rachunek? Cyfry rachunku z dokumentu wobec naszych (dane proformy, WZ, WDT) : równe albo jeden kończy się
     * drugim (NRB bez PL). @param list<string> $found @param list<string> $ours @return ?bool null = brak rachunku w dokumencie
     */
    public static function ownAccount(array $found, array $ours): ?bool
    {
        $digits = fn(string $s) => preg_replace('/\D/', '', $s);
        $ours = array_values(array_filter(array_map($digits, $ours), fn($d) => strlen($d) >= 14));
        if (!$found) return null;
        foreach ($found as $f) {
            $d = $digits($f);
            foreach ($ours as $o) if ($d === $o || (strlen($d) >= 20 && (str_ends_with($o, $d) || str_ends_with($d, $o)))) return true;
        }
        return false;
    }

    /** Płacący ≈ klient (wspólne słowo nazwy, bez form prawnych). */
    public static function sameParty(?string $payer, ?string $company): bool
    {
        if ($payer === null || $company === null) return false;
        $words = function (string $s): array {
            $s = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
            $w = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $skip = ['sprl', 'srl', 'sa', 'nv', 'bv', 'bvba', 'sp', 'zoo', 'z', 'o', 'oo', 'spolka', 'sarl', 'sas', 'gmbh', 'ltd', 'the', 'and', 'et', 'de', 'la', 'le', 'les', 'van', 'der', 'den'];
            return array_values(array_filter($w, fn($x) => strlen($x) >= 3 && !in_array($x, $skip, true)));
        };
        return (bool) array_intersect($words($payer), $words($company));
    }
}
