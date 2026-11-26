<?php
/**
 * [444] Lecture des relevés ING (Różnice kursowe). Données : tests/fixtures/ing_wyciag_eur.txt = texte pdftotext -layout
 * d'un relevé réel du compte EUR (n° 279 du 23.11.2026 : 6 opérations de kantor et 1 virement sortant), noms, adresses,
 * comptes, soldes et références remplacés par des valeurs fictives de même longueur (colonnes intactes). Les crédits de
 * clients sont ajoutés ici (fictifs) : le relevé réel n'en a pas.
 * Sans base de données.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Finances\Fx\IngStatementParser;
use App\Services\Finances\Fx\StatementFormatNotSupported;

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$refused = function (string $text) { try { (new IngStatementParser())->parse($text); return null; } catch (StatementFormatNotSupported $e) { return $e->getMessage(); } };
$p = new IngStatementParser();
$real = (string) file_get_contents(__DIR__ . '/fixtures/ing_wyciag_eur.txt');

// Relevé réel (anonymisé) : en-tête et 7 débits.
$h = $p->header($real);
$check($h === ['account_currency' => 'EUR', 'period_from' => '2026-11-22', 'period_to' => '2026-11-23', 'number' => '279'], 'header : account EUR, period = day after the previous statement → statement date, number');
$r = $p->parse($real);
$check(count($r) === 7 && array_column($r, 'amount') === [5520.0, 50.0, 50.0, 50.0, 150.0, 850.0, 2000.0] && array_unique(array_column($r, 'incoming')) === [false]
    && array_unique(array_column($r, 'booked_on')) === ['2026-11-23'] && array_unique(array_column($r, 'currency')) === ['EUR'], 'real layout : 7 debits (« -5 520,00 »…), date, account currency');
$check($r[5]['payer'] === 'DOSTAWCA SPZ ul. Testowa 2 00-001 Testowo PL' && $r[5]['title'] === '1/09/2026', 'transfer : counterparty (name + address, account number left out), title without the « Szczegóły » column');
$check(str_starts_with((string) $r[6]['title'], 'kupno PLN kwota 8764.60') && $r[6]['amount'] === 2000.0, 'last operation of the page, overlapped by the bank footer in the PDF : closed at the footer, amount kept');
$check(!str_contains(implode(' ', array_column($r, 'title')), 'Data transakcji') && !str_contains(implode(' ', array_column($r, 'title')), 'nie podano'), 'technical details (Data transakcji, Ref. « nie podano ») not in the title');

// Crédits de clients (fictifs) dans la même mise en page : colonnes du relevé réel.
$row = function (array $cells): string { $s = ''; foreach ($cells as [$col, $txt]) $s = mb_str_pad($s, $col) . $txt; return $s; };
$amountEnd = mb_strlen(rtrim(explode("\n", $real)[array_key_first(preg_grep('/^\s*23\.11\.2026 /u', explode("\n", $real)))]));
$at = fn(string $amount) => [$amountEnd - mb_strlen($amount), $amount];
$credit = implode("\n", [
    $row([[4, '20.11.2026'], [15, 'BE00 0000 0000 0001'], [61, 'FV/2026/11/0142'], [89, 'PRZELEW SEPA PRZYCHODZĄCY'], $at('2 400,00')]),
    $row([[2, '97000000999'], [15, 'BISTRO TEST B SPRL'], [61, 'zapłata za towar'], [89, 'Data transakcji: 20.11.2026']]),
    $row([[17, 'RUE DE TEST 1'], [89, 'Ref. END TO END: PF-2026-035']]),
    $row([[17, '1000 BRUXELLES']]),
]) . "\n";
$page2 = "\f" . $row([[115, 'Wyciąg z rachunku']]) . "\nING Bank Śląski S.A.\n\n" . $row([[7, 'Data'], [31, 'Dane Kontrahenta'], [68, 'Tytuł operacji'], [102, 'Szczegóły operacji'], [140, 'Kwota']]) . "\n   księgowania\n  Nr transakcji\n"
    . $row([[4, '24.11.2026'], [15, 'FR00 0000 0000 0002'], [61, 'Facture 0150 + 0151'], [89, 'PRZELEW SEPA PRZYCHODZĄCY'], $at('1 510,00')]) . "\n"
    . $row([[2, '97000001000'], [15, 'SKLEP TESTOWY A SPRL'], [89, 'Ref. END TO END: NOTPROVIDED']]) . "\n"
    . $row([[17, '1 PLACE DU TEST']]) . "\n"
    . "Dokument wygenerowany elektronicznie, nie wymaga pieczęci ani podpisu.\n" . $row([[115, 'Strona     2        z       2']]) . "\n";
$first = array_key_first(preg_grep('/^\s*23\.11\.2026 /u', explode("\n", $real)));
$lines = explode("\n", $real);
array_splice($lines, $first, 0, explode("\n", rtrim($credit, "\n")));
$two = str_replace(['Suma uznań (+):                   0,00', 'Liczba uznań (+): 0'], ['Suma uznań (+):                   3 910,00', 'Liczba uznań (+): 2'], implode("\n", $lines)) . $page2;
$r2 = $p->parse($two);
$in = array_values(array_filter($r2, fn($l) => $l['incoming']));
$check(count($r2) === 9 && count($in) === 2, 'two pages : 7 debits + 2 customer credits (amount without sign = credit), totals of the header match');
$check($in[0] === ['booked_on' => '2026-11-20', 'amount' => 2400.0, 'currency' => 'EUR', 'amount_pln' => null, 'bank_rate' => null,
    'payer' => 'BISTRO TEST B SPRL RUE DE TEST 1 1000 BRUXELLES', 'title' => 'FV/2026/11/0142 zapłata za towar Ref. PF-2026-035', 'incoming' => true],
    'credit : payer, title with the invoice number and the END TO END reference (operation type left out)');
$check($in[1]['booked_on'] === '2026-11-24' && $in[1]['amount'] === 1510.0 && $in[1]['title'] === 'Facture 0150 + 0151' && $in[1]['payer'] === 'SKLEP TESTOWY A SPRL 1 PLACE DU TEST',
    'second page : header repeated, credit read, « NOTPROVIDED » left out, closed at « Dokument wygenerowany… »');

// Contrôles : rien n'est perdu en silence.
$missing = preg_replace('/^.*DOSTAWCA SPZ.*\n/mu', '', preg_replace('/^\s*23\.11\.2026 00 1140.*\n/mu', '', $real));
$msg = $refused((string) $missing);
$check($msg !== null && str_contains($msg, 'niepełny') && str_contains($msg, 'odczytano 0 uznań (0,00) i 6 obciążeń (7 820,00)') && str_contains($msg, 'podaje 0 (0,00) i 7 (8 670,00)'),
    'one operation not read → refused with the counts (header totals checked)');
$check($refused(str_replace('Suma uznań (+):                   3 910,00', 'Suma uznań (+):                   3 900,00', $two)) !== null, 'credit total different from the header → refused');
$pln = str_replace('Waluta rachunku:                                     EUR', 'Waluta rachunku:                                     PLN', $real);
$check($refused($pln) === IngStatementParser::MESSAGE_PLN && $p->header($pln)['account_currency'] === 'PLN', 'PLN account : header read, lines refused with a clear message (layout of converted payments not known yet)');
$check($refused("ING Bank Śląski\nWyciąg z rachunku EUR 16.11.2026") === IngStatementParser::MESSAGE && $refused('mBank wyciąg') === IngStatementParser::MESSAGE
    && $p->header('inny tekst') === ['account_currency' => null, 'period_from' => null, 'period_to' => null, 'number' => null], 'other layout or bank → refused ; header of an unknown text : nothing read');

echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
