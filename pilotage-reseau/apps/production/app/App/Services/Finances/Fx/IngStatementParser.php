<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

/**
 * [444] Relevé ING Bank Śląski (« Wyciąg z rachunku », PDF lu par pdftotext -layout), écrit sur un relevé réel du compte
 * « OKO Business w EUR » (n° 279 du 23.11.2026, reçu le 26.11.2026 ; il n'est pas dans le dépôt).
 *
 * Mise en page lue :
 *  - en-tête : « Numer wyciągu », « Data wyciągu », « Data poprzedniego wyciągu » (période = lendemain du relevé précédent
 *    → date du relevé), « Waluta rachunku », totaux « Suma uznań (+) / Liczba uznań (+) » et « Suma obciążeń (-) / Liczba
 *    obciążeń (-) » ;
 *  - tableau à 5 colonnes (Data księgowania + Nr transakcji · Dane Kontrahenta · Tytuł operacji · Szczegóły operacji ·
 *    Kwota) : une opération commence par une ligne « jj.mm.aaaa <compte du contrepartie> … <montant> », la ligne suivante
 *    porte le n° de transaction ; montant « -5 520,00 » (débit) ou sans signe (crédit) ;
 *  - le pied de page de la banque (BFG, « Dokument wygenerowany… », « Strona n z m ») recouvre parfois la dernière
 *    opération de la page dans le PDF même : l'opération est close au premier mot du pied de page.
 *
 * Contrôle : le nombre et la somme des crédits et des débits lus doivent égaler les totaux de l'en-tête, sinon le relevé
 * est refusé (rien n'est perdu en silence : le PDF reste enregistré, les wpłaty se saisissent à la main).
 * Compte PLN : la forme d'une wpłata en devise convertie par ING (montant d'origine, kurs) n'est pas encore connue (aucun
 * relevé PLN reçu) — refusé avec un message clair, pas deviné.
 */
final class IngStatementParser implements BankStatementParser
{
    public const MESSAGE = 'Nie rozpoznano układu wyciągu ING (brak nagłówka « Waluta rachunku » albo tabeli operacji) — plik zapisany, wpisz wpłaty ręcznie (« Dodaj wpłatę »).';
    public const MESSAGE_PLN = 'Wyciąg z rachunku PLN : odczyt wpłat w walucie przewalutowanych przez ING jeszcze w przygotowaniu (czekamy na przykładowy wyciąg PLN z taką wpłatą) — plik zapisany, wpisz wpłaty ręcznie z kursem ING.';

    /** Début du pied de page de la banque (il clôt l'opération en cours). */
    private const FOOTER = '/Środki pieniężne zgromadzone|obowiązkowego systemu gwarantowania|systemie gwarantowania depozytów|jednego deponenta|www\.bfg\.pl|Dokument wygenerowany elektronicznie|Ustawy Prawo Bankowe|Strona\s+\d+\s+z\s+\d+/u';
    /** Ligne de début d'opération : date de comptabilisation … montant en fin de ligne. */
    private const START = '/^\s*(\d{2})\.(\d{2})\.(\d{4})(\s.*?)\s{2,}([+-]?\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2})\s*$/u';
    /** Mentions techniques de la colonne « Szczegóły operacji » quand pdftotext les colle au titre. */
    private const DETAILS = '/\s*(?:Data transakcji:\s*\d{2}\.\d{2}\.\d{4}|ID transakcji:\s*\S+|Opcja kosztowa:\s*\S+|Ref\. END TO END:\s*(?:nie podano|NOTPROVIDED))\s*/u';

    public function name(): string
    {
        return 'ing';
    }

    /** @return array{account_currency: ?string, period_from: ?string, period_to: ?string, number: ?string} */
    public function header(string $text): array
    {
        $date = static function (string $label) use ($text): ?string {
            if (!preg_match('/' . $label . ':\s*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) return null;
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        };
        $to = $date('Data wyciągu');
        $prev = $date('Data poprzedniego wyciągu');
        return [
            'account_currency' => preg_match('/Waluta rachunku:\s*([A-Z]{3})\b/u', $text, $m) ? $m[1] : null,
            'period_from' => $prev !== null ? date('Y-m-d', strtotime($prev . ' +1 day')) : null,
            'period_to' => $to,
            'number' => preg_match('/Numer wyciągu:\s*(\S+)/u', $text, $m) ? $m[1] : null,
        ];
    }

    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text);
        $head = $this->header($text);
        if (!str_contains($text, 'ING Bank') || !str_contains($text, 'Wyciąg z rachunku') || $head['account_currency'] === null) throw new StatementFormatNotSupported(self::MESSAGE);
        if ($head['account_currency'] === 'PLN') throw new StatementFormatNotSupported(self::MESSAGE_PLN);

        // Opérations : lignes brutes de chaque opération, closes au pied de page ou à l'opération suivante.
        $ops = [];
        $cur = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match(self::START, $line, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                if ($cur !== null) $ops[] = $cur;
                $cur = ['booked_on' => $m[3] . '-' . $m[2] . '-' . $m[1], 'amount' => $m[5], 'first' => $line, 'lines' => []];
                continue;
            }
            if ($cur === null) continue;
            if (preg_match(self::FOOTER, $line)) { $ops[] = $cur; $cur = null; continue; }
            $cur['lines'][] = $line;
        }
        if ($cur !== null) $ops[] = $cur;
        if ($ops === []) throw new StatementFormatNotSupported(self::MESSAGE);

        $titleCol = $this->titleColumn($ops);
        $detailsCol = $this->detailsColumn($ops, $titleCol);
        $out = [];
        $sum = ['in' => 0.0, 'out' => 0.0, 'nin' => 0, 'nout' => 0];
        foreach ($ops as $op) {
            $value = self::amount($op['amount']);
            $incoming = $value > 0;
            [$party, $title, $refs] = [[], [], []];
            // Ligne 1 : date, compte du contrepartie, titre, type d'opération, montant (retiré).
            $first = (string) preg_replace('/\s{2,}[+-]?\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2}\s*$/u', '', $op['first']);
            $first = (string) preg_replace_callback('/^\s*\d{2}\.\d{2}\.\d{4}/u', fn($m) => str_repeat(' ', mb_strlen($m[0])), $first);
            foreach (array_merge([$first], $op['lines']) as $i => $line) {
                if ($i === 1) $line = (string) preg_replace_callback('/^\s*\d{6,16}(?=\s)/u', fn($m) => str_repeat(' ', mb_strlen($m[0])), $line); // n° de transaction
                foreach (self::segments($line) as [$col, $seg]) {
                    if ($col < $titleCol - 3) {
                        if ($i > 0 || !preg_match('/^[A-Z]{0,2}[\d ]{10,}$/', $seg)) $party[] = $seg; // ligne 1 : le compte, pas un nom
                    } elseif ($detailsCol !== null && $col >= $detailsCol - 3) {
                        if (preg_match('/Ref\. END TO END:\s*(.+)$/u', $seg, $r) && !preg_match('/^(nie podano|NOTPROVIDED)$/u', trim($r[1]))) $refs[] = 'Ref. ' . trim($r[1]);
                    } else {
                        $title[] = $seg;
                    }
                }
            }
            $t = trim((string) preg_replace([self::DETAILS, '/\s+/u'], [' ', ' '], implode(' ', array_merge($title, $refs))));
            $p = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $party)));
            $out[] = ['booked_on' => $op['booked_on'], 'amount' => abs($value), 'currency' => $head['account_currency'], 'amount_pln' => null, 'bank_rate' => null,
                'payer' => $p !== '' ? mb_substr($p, 0, 255) : null, 'title' => $t !== '' ? mb_substr($t, 0, 500) : null, 'incoming' => $incoming];
            if ($incoming) { $sum['in'] += $value; $sum['nin']++; } else { $sum['out'] -= $value; $sum['nout']++; }
        }
        $this->checkTotals($text, $sum);
        return $out;
    }

    /** Totaux de l'en-tête (s'ils sont lus) = opérations lues, sinon refus. */
    private function checkTotals(string $text, array $sum): void
    {
        $num = '([+-]?\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2})';
        $want = [];
        if (preg_match('/Suma uznań \(\+\):\s*' . $num . '/u', $text, $m)) $want['in'] = self::amount($m[1]);
        if (preg_match('/Liczba uznań \(\+\):\s*(\d+)/u', $text, $m)) $want['nin'] = (int) $m[1];
        if (preg_match('/Suma obciążeń \(-\):\s*' . $num . '/u', $text, $m)) $want['out'] = abs(self::amount($m[1]));
        if (preg_match('/Liczba obciążeń \(-\):\s*(\d+)/u', $text, $m)) $want['nout'] = (int) $m[1];
        foreach ($want as $k => $v) {
            if (abs((float) $sum[$k] - (float) $v) > 0.005) {
                throw new StatementFormatNotSupported('Odczyt wyciągu ING niepełny : odczytano ' . $sum['nin'] . ' uznań (' . self::money($sum['in']) . ') i ' . $sum['nout']
                    . ' obciążeń (' . self::money($sum['out']) . '), wyciąg podaje ' . ($want['nin'] ?? '?') . ' (' . self::money($want['in'] ?? 0) . ') i ' . ($want['nout'] ?? '?')
                    . ' (' . self::money($want['out'] ?? 0) . ') — plik zapisany, wpisz wpłaty ręcznie.');
            }
        }
    }

    /** Colonne « Tytuł operacji » : début le plus fréquent du segment qui suit le compte, sur la ligne 1 des opérations. */
    private function titleColumn(array $ops): int
    {
        $cols = [];
        foreach ($ops as $op) {
            $first = (string) preg_replace('/\s{2,}[+-]?\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2}\s*$/u', '', $op['first']);
            $seg = self::segments($first);
            // [0] = « jj.mm.aaaa <compte> » (un seul espace entre les deux), [1] = titre (ou type d'opération si pas de titre).
            if (count($seg) >= 2) $cols[] = $seg[1][0];
        }
        if ($cols === []) throw new StatementFormatNotSupported(self::MESSAGE);
        $n = array_count_values($cols);
        arsort($n);
        return (int) array_key_first($n);
    }

    /** Colonne « Szczegóły operacji » : début le plus fréquent d'un 2e segment à droite du titre (null si aucun). */
    private function detailsColumn(array $ops, int $titleCol): ?int
    {
        $cols = [];
        foreach ($ops as $op) {
            foreach ($op['lines'] as $line) {
                $right = array_values(array_filter(self::segments($line), fn($s) => $s[0] >= $titleCol - 3));
                foreach ($right as $s) if ($s[0] > $titleCol + 8) $cols[] = $s[0];
            }
        }
        if ($cols === []) return null;
        $n = array_count_values($cols);
        arsort($n);
        return (int) array_key_first($n);
    }

    /** Segments d'une ligne séparés par 2 espaces ou plus : [colonne (caractères), texte]. @return list<array{0: int, 1: string}> */
    private static function segments(string $line): array
    {
        $out = [];
        if (!preg_match_all('/\S+(?: \S+)*/u', $line, $m, PREG_OFFSET_CAPTURE)) return [];
        foreach ($m[0] as [$seg, $byte]) $out[] = [mb_strlen(substr($line, 0, $byte)), $seg];
        return $out;
    }

    /** « -5 520,00 » → -5520.0 */
    private static function amount(string $s): float
    {
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $s);
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }
}
