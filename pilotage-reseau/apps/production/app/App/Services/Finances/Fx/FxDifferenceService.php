<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

use App\Exceptions\CustomException;
use App\Repositories\Finances\FxDifferenceRepository;
use App\Services\Utils\TCPDFService;

/**
 * [440] Różnice kursowe (Finanse) — écart de change par facture sur les paiements des clients en devise :
 *  - kurs faktury = kurs średni NBP (tabela A) du dernier jour ouvré avant la date de la facture (WDT : invoiced_date,
 *    sinon established_date) ;
 *  - kurs wpłaty : compte EUR (devise) → NBP du dernier jour ouvré avant la wpłata ; compte PLN (la banque a converti) →
 *    kurs ING de la ligne du relevé (sinon montant en zł ÷ montant en devise) ;
 *  - różnica = montant en devise × (kurs wpłaty − kurs faktury), en zł arrondi au grosz (moitié : loin de zéro) ; > 0
 *    dodatnia, < 0 ujemna ;
 *  - przedpłata (wpłata sur proforma avant la facture) : calculée quand la facture existe (proforma → commande → WDT),
 *    rattachée au mois de la facture ; sans facture = « czeka na fakturę » ; un virement pour plusieurs factures et les
 *    paiements partiels : une ligne par facture (n_fx_allocation) ;
 *  - mois de la różnica = le plus tardif de la wpłata et de la facture ; le mois montre les wpłaty du mois, celles d'avant
 *    rozliczone dans le mois et celles d'avant encore à expliquer.
 * Zestawienie miesiąca : PDF (TCPDFService, gabarit des documents) et CSV pour la comptabilité. Lecture seule hors des
 * tables des wyciągi (BankStatementService) et des kursy NBP (NbpRateService).
 */
final class FxDifferenceService
{
    public const PATH = '/finances/fx-differences';
    public const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    public const STATES = [
        'counted' => ['policzone', 'ok'], 'to_confirm' => ['do potwierdzenia', 'warn'], 'waiting' => ['czeka na fakturę', 'neutral'],
        'no_rate' => ['brak kursu', 'warn'], 'overpaid' => ['nadwyżka bez dokumentu', 'warn'], 'other_month' => ['inny miesiąc', 'neutral'],
    ];
    /** Wpłaty plus anciennes examinées (przedpłata rozliczona plus tard, wpłata encore à expliquer). */
    public const SINCE_DAYS = 400;
    private const OPEN = ['to_confirm', 'no_rate', 'waiting', 'overpaid'];

    public function __construct(private FxDifferenceRepository $repo, private BankStatementService $statements, private NbpRateService $nbp)
    {
    }

    /** Données de la page ; sans droit aux montants ($values = false) : seulement le cadre. */
    public function data(?string $month, bool $values, ?string $today = null, ?int $userId = null): array
    {
        $today ??= date('Y-m-d');
        $month = self::month($month, $today);
        $base = ['month' => $month, 'month_label' => self::monthLabel($month), 'nav' => self::nav($month, $today), 'values' => $values, 'today' => $today,
            'accounts' => BankStatementService::ACCOUNTS, 'states' => array_map(fn($s) => ['label' => $s[0], 'tone' => $s[1]], self::STATES)];
        if (!$values) return $base;
        $c = $this->compute($month, $today, $userId);
        $mainCur = $c['main_currency'];
        $last = $this->nbp->lastCheck();
        return $base + [
            'rows' => $c['rows'], 'kpi' => $c['kpi'], 'totals' => $c['totals'],
            'statements' => $c['statements'], 'statement_choices' => array_map(fn($s) => ['id' => (int) $s['id'], 'account' => $s['account_currency'],
                'label' => self::statementLabel($s)], $this->repo->recentStatements()),
            'customers' => array_map(fn($x) => ['id' => (int) $x['id'], 'name' => (string) $x['name'], 'currency' => (string) $x['currency']], $this->repo->customers()),
            'rates' => ['currency' => $mainCur, 'list' => array_map(fn($r) => ['date' => $r['date'], 'mid' => $r['mid'], 'table' => NbpRateService::tableLabel($r['table_no'])],
                $this->nbp->latest($mainCur, min(self::monthEnd($month), $today), 4)),
                'host' => $this->nbp->host(), 'last_check' => $last, 'error' => $c['nbp_error']],
        ];
    }

    /**
     * Calcul du mois : lignes (une par wpłata, pièces = une par facture), indicateurs, totaux.
     * @return array{rows: list<array>, kpi: array, totals: array, statements: list<array>, pieces: list<array>, open: list<array>, main_currency: string, nbp_error: ?string}
     */
    public function compute(string $month, ?string $today = null, ?int $userId = null): array
    {
        $today ??= date('Y-m-d');
        $from = $month . '-01';
        $to = self::monthEnd($month);
        $since = BankStatementService::addDays($from, -self::SINCE_DAYS);
        $lines = $this->repo->lines($since, $to);
        if ($this->statements->retry($lines, $userId) > 0) $lines = $this->repo->lines($since, $to);
        $allocs = $this->repo->allocations(array_map(fn($l) => (int) $l['id'], $lines));

        // Documents : factures directes, proformas et leurs factures, remplacements (n_wdt_superseded).
        $pIds = $wIds = [];
        foreach ($allocs as $list) foreach ($list as $a) { if ($a['doc_kind'] === 'wdt') $wIds[] = (int) $a['id_doc']; else $pIds[] = (int) $a['id_doc']; }
        $proformas = $this->repo->proformas($pIds);
        $pw = $this->repo->proformaWdts($pIds);
        foreach ($pw as $ws) foreach ($ws as $w) $wIds[] = $w;
        $wdts = $this->repo->wdts($wIds);
        for ($i = 0; $i < 5; $i++) {
            $more = array_diff(array_filter(array_map(fn($w) => (int) $w['superseded_by'], $wdts)), array_keys($wdts));
            if (!$more) break;
            $wdts += $this->repo->wdts($more);
        }
        $resolve = function (int $id) use ($wdts): ?array {
            for ($i = 0, $w = $wdts[$id] ?? null; $w && !empty($w['superseded_by']) && $i < 5; $i++) $w = $wdts[(int) $w['superseded_by']] ?? $w;
            return $w;
        };

        // Kursy NBP : une seule demande pour les jours encore inconnus.
        $dates = [];
        foreach ($lines as $l) if ($l['match_state'] !== 'ignored') {
            if ($l['account_currency'] === 'EUR') $dates[] = (string) $l['booked_on'];
            foreach ($allocs[(int) $l['id']] ?? [] as $a) {
                $ids = $a['doc_kind'] === 'wdt' ? [(int) $a['id_doc']] : ($pw[(int) $a['id_doc']] ?? []);
                foreach ($ids as $wid) if ($w = $resolve($wid)) $dates[] = substr((string) $w['doc_date'], 0, 10);
            }
        }
        $dates = array_filter($dates);
        $nbpOk = !$dates || $this->nbp->ensure(BankStatementService::addDays(min($dates), -NbpRateService::LOOKBACK), BankStatementService::addDays(max($dates), -1), $today);

        $rows = [];
        $pieces = [];
        $open = [];
        foreach ($lines as $l) {
            if ($l['match_state'] === 'ignored') continue;
            $row = $this->row($l, $allocs[(int) $l['id']] ?? [], $proformas, $pw, $resolve, $month);
            $booked = (string) $l['booked_on'];
            $inMonth = $booked >= $from;
            $realizedHere = (bool) array_filter($row['pieces'], fn($p) => $p['state'] === 'counted' && $p['in_month']);
            if (!$inMonth && !$realizedHere && !in_array($row['state'], self::OPEN, true)) continue;
            if ($row['state'] === 'to_confirm') $row['candidates'] = $this->statements->candidates($l);
            $row['earlier'] = !$inMonth;
            $rows[] = $row;
            foreach ($row['pieces'] as $p) if ($p['state'] === 'counted' && $p['in_month']) $pieces[] = $p + ['row' => $row];
            if (in_array($row['state'], self::OPEN, true)) $open[] = $row;
        }

        // Indicateurs.
        $cur = [];
        $count = 0;
        $stmts = [];
        foreach ($rows as $r) if (!$r['earlier']) {
            $cur[$r['currency']] = round(($cur[$r['currency']] ?? 0) + $r['amount'], 2);
            $count++;
            if ($r['statement_id'] !== null) $stmts[$r['statement_id']] = true;
        }
        arsort($cur);
        $pos = $neg = 0.0;
        $posRows = $negRows = [];
        $invoices = [];
        foreach ($pieces as $p) {
            if ($p['diff'] > 0) { $pos += $p['diff']; $posRows[$p['row']['id']] = true; }
            if ($p['diff'] < 0) { $neg += $p['diff']; $negRows[$p['row']['id']] = true; }
            $invoices[$p['invoice']['id']] = true;
        }
        $countedRows = count(array_unique(array_map(fn($p) => $p['row']['id'], $pieces)));
        $byState = array_count_values(array_map(fn($r) => $r['state'], $open));
        $kpi = [
            'paid' => array_map(fn($c, $v) => ['currency' => $c, 'amount' => $v], array_keys($cur), array_values($cur)), 'payments' => $count, 'statements' => count($stmts),
            'counted' => $countedRows, 'invoices' => count($invoices),
            'open' => count($open), 'open_by_state' => $byState,
            'positive' => round($pos, 2), 'positive_rows' => count($posRows), 'negative' => round($neg, 2), 'negative_rows' => count($negRows), 'balance' => round($pos + $neg, 2),
        ];
        $statements = array_map(fn($s) => [
            'id' => (int) $s['id'], 'account' => $s['account_currency'], 'label' => self::statementLabel($s), 'filename' => $s['filename'], 'has_file' => (bool) $s['has_file'],
            'customers' => (int) $s['lines_customers'], 'other' => (int) $s['lines_other'], 'status' => $s['parse_status'], 'message' => $s['parse_message'],
            'chip' => match ($s['parse_status']) { 'parsed' => ['wczytany', 'ok'], 'unsupported' => ['do wpisania ręcznie', 'warn'], 'no_text' => ['PDF bez tekstu', 'warn'], default => ['wpisany ręcznie', 'neutral'] },
        ], $this->repo->statements($from, $to));
        return ['rows' => $rows, 'kpi' => $kpi, 'totals' => ['positive' => $kpi['positive'], 'negative' => $kpi['negative'], 'balance' => $kpi['balance']],
            'statements' => $statements, 'pieces' => $pieces, 'open' => $open, 'main_currency' => array_key_first($cur) ?? 'EUR',
            'nbp_error' => $nbpOk ? null : $this->nbp->lastError()];
    }

    /** Une wpłata : kurs wpłaty, pièces (une par facture), état. */
    private function row(array $l, array $allocs, array $proformas, array $pw, callable $resolve, string $month): array
    {
        $cur = (string) $l['currency'];
        $amount = round((float) $l['amount'], 2);
        $booked = (string) $l['booked_on'];
        $pay = null;
        if ($l['account_currency'] === 'EUR') {
            $r = $this->nbp->rateBefore($cur, $booked, false);
            if ($r) $pay = ['rate' => $r['mid'], 'source' => 'nbp', 'date' => $r['date'], 'table' => NbpRateService::tableLabel($r['table_no'])];
        } else {
            $rate = $l['bank_rate'] !== null ? (float) $l['bank_rate'] : ($l['amount_pln'] !== null && $amount > 0 ? round((float) $l['amount_pln'] / $amount, 6) : null);
            if ($rate) $pay = ['rate' => $rate, 'source' => 'bank', 'date' => $booked, 'table' => null];
        }
        $pieces = [];
        $waiting = [];
        $allocated = 0.0;
        foreach ($allocs as $a) {
            $part = round((float) $a['amount'], 2);
            $allocated += $part;
            $proforma = null;
            $targets = [];
            if ($a['doc_kind'] === 'wdt') {
                if ($w = $resolve((int) $a['id_doc'])) $targets[] = [$w, $part];
            } else {
                $pf = $proformas[(int) $a['id_doc']] ?? null;
                $proforma = $pf ? ['id' => (int) $pf['id'], 'number' => (string) $pf['doc_num'], 'date' => self::d($pf['doc_date']),
                    'pd_paid_on' => self::d($pf['pd_paid_on'] ?? null)] : ['id' => (int) $a['id_doc'], 'number' => '#' . $a['id_doc'], 'date' => null, 'pd_paid_on' => null];
                $ws = array_values(array_filter(array_map(fn($id) => $resolve($id), $pw[(int) $a['id_doc']] ?? [])));
                $ws = array_values(array_column($ws, null, 'id'));
                usort($ws, fn($x, $y) => [(string) $x['doc_date'], (int) $x['id']] <=> [(string) $y['doc_date'], (int) $y['id']]);
                $rest = $part;
                foreach ($ws as $i => $w) {
                    $take = $i === count($ws) - 1 ? $rest : round(min($rest, (float) $w['total']), 2);
                    if ($take <= 0.005) continue;
                    $targets[] = [$w, $take];
                    $rest = round($rest - $take, 2);
                }
                if (!$ws) $waiting[] = ['proforma' => $proforma, 'amount' => $part];
            }
            foreach ($targets as [$w, $take]) {
                $invDate = self::d($w['doc_date']);
                $inv = $invDate ? $this->nbp->rateBefore($cur, $invDate, false) : null;
                $realized = max($booked, (string) $invDate);
                $diff = $pay && $inv ? self::diff($take, $pay['rate'], $inv['mid']) : null;
                $pieces[] = [
                    'invoice' => ['id' => (int) $w['id'], 'number' => (string) $w['doc_num'], 'date' => $invDate, 'currency' => (string) $w['currency']],
                    'proforma' => $proforma, 'amount' => $take, 'source' => $a['source'],
                    'inv_rate' => $inv ? ['rate' => $inv['mid'], 'date' => $inv['date'], 'table' => NbpRateService::tableLabel($inv['table_no'])] : null,
                    'diff' => $diff, 'realized' => $realized, 'in_month' => substr($realized, 0, 7) === $month,
                    'month_label' => self::monthLabel(substr($realized, 0, 7)), 'state' => $diff === null ? 'no_rate' : 'counted',
                ];
            }
        }
        $remainder = round($amount - $allocated, 2);
        $state = match (true) {
            $l['match_state'] === 'to_confirm' => 'to_confirm',
            (bool) array_filter($pieces, fn($p) => $p['state'] === 'no_rate') || ($pieces && !$pay) => 'no_rate',
            (bool) $waiting => 'waiting',
            $remainder > 0.005 => 'overpaid',
            default => 'counted',
        };
        if ($state === 'counted' && $pieces && !array_filter($pieces, fn($p) => $p['in_month'])) $state = 'other_month';
        $sum = 0.0;
        foreach ($pieces as $p) if ($p['state'] === 'counted' && $p['in_month']) $sum += $p['diff'];
        $title = $this->statements->title($l);
        return [
            'id' => (int) $l['id'], 'booked_on' => $booked, 'account' => $l['account_currency'], 'currency' => $cur, 'amount' => $amount,
            'amount_pln' => $l['amount_pln'] !== null ? (float) $l['amount_pln'] : null,
            'customer' => $l['id_customer'] !== null ? ['id' => (int) $l['id_customer'], 'name' => (string) ($l['customer_name'] ?? ('Klient #' . $l['id_customer']))] : null,
            'title' => $title, 'refs' => $l['ref_numbers'] ? array_map('trim', explode(',', (string) $l['ref_numbers'])) : [],
            'statement_id' => $l['id_statement'] !== null ? (int) $l['id_statement'] : null, 'source' => $l['source'],
            'pay_rate' => $pay, 'pieces' => $pieces, 'waiting' => $waiting, 'remainder' => $remainder > 0.005 ? $remainder : 0.0,
            'diff' => round($sum, 2), 'state' => $state, 'state_label' => $state === 'other_month' && $pieces ? 'liczy się : ' . $pieces[0]['month_label'] : self::STATES[$state][0],
            'tone' => self::STATES[$state][1], 'note' => $l['match_note'], 'candidates' => [],
        ];
    }

    // --- Zestawienie miesiąca ---------------------------------------------------------------------------------------

    /** PDF dla księgowości : jedna linia na fakturę, sumy, pozycje do wyjaśnienia osobno. @return array{name: string, content: string} */
    public function pdf(?string $month, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $month = self::month($month, $today);
        $c = $this->compute($month, $today);
        $from = $month . '-01';
        $to = self::monthEnd($month);
        $label = self::monthLabel($month);
        $curs = array_values(array_unique(array_merge(array_map(fn($p) => $p['row']['currency'], $c['pieces']), array_column($c['kpi']['paid'], 'currency'))));
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->brandPage('Różnice kursowe', ['ref' => 'RK ' . $month]);
        $pdf->brandHeader('Różnice kursowe', [['Miesiąc', mb_convert_case($label, MB_CASE_TITLE)], ['Wydruk', self::dmy($today)]], 'Wpłaty klientów w walucie — zestawienie dla księgowości');
        $pdf->brandSummary([
            ['Okres', substr(self::dmy($from), 0, 5) . ' – ' . self::dmy($to)],
            ['Wpłaty policzone', $c['kpi']['counted'] . ' (' . $c['kpi']['invoices'] . ' ' . self::pl($c['kpi']['invoices'], 'faktura', 'faktury', 'faktur') . ')'],
            ['Do wyjaśnienia', (string) $c['kpi']['open']],
            ['Waluta', $curs ? implode(', ', $curs) : 'EUR'],
        ]);
        $one = count($curs) <= 1;
        $rows = array_map(fn($p) => [
            self::dm($p['row']['booked_on']), $p['row']['account'], $p['row']['customer']['name'] ?? '—', $p['invoice']['number'],
            self::money($p['amount']) . ($one ? '' : ' ' . $p['row']['currency']), self::rate($p['inv_rate']['rate']),
            self::rate($p['row']['pay_rate']['rate']) . ($p['row']['pay_rate']['source'] === 'bank' ? ' (ING)' : ''), self::signed($p['diff']),
        ], $c['pieces']);
        if (!$rows) $rows[] = ['—', '', 'Brak wpłat policzonych w tym miesiącu', '', '', '', '', ''];
        $pdf->brandTable([
            ['label' => 'Data wpłaty', 'w' => 13], ['label' => 'Konto', 'w' => 8], ['label' => 'Klient', 'w' => 22], ['label' => 'Faktura', 'w' => 22],
            ['label' => 'Kwota' . ($one ? ' ' . ($curs[0] ?? 'EUR') : ''), 'w' => 14, 'align' => 'R'], ['label' => 'Kurs faktury', 'w' => 12, 'align' => 'R'],
            ['label' => 'Kurs wpłaty', 'w' => 15, 'align' => 'R'], ['label' => 'Różnica PLN', 'w' => 13, 'align' => 'R'],
        ], $rows, ['font' => 7.5]);
        $pdf->brandSummary([], [['Różnice dodatnie', self::signed($c['totals']['positive']) . ' zł'], ['Różnice ujemne', self::signed($c['totals']['negative']) . ' zł'],
            ['Saldo', self::signed($c['totals']['balance']) . ' zł']]);
        if ($c['open']) {
            $pdf->brandSection('Do wyjaśnienia (poza zestawieniem)', count($c['open']) . ' ' . self::pl(count($c['open']), 'pozycja', 'pozycje', 'pozycji'));
            $pdf->brandText(implode(' ; ', array_map(fn($r) => self::openText($r), $c['open'])) . '.', ['size' => 8.5]);
        }
        $pdf->brandNote('Kurs faktury : średni NBP (tabela A) z ostatniego dnia roboczego przed datą faktury ; kurs wpłaty : konto EUR — średni NBP z ostatniego dnia roboczego przed wpłatą, konto PLN — kurs zastosowany przez ING (z wyciągu).');
        $pdf->brandNote('Różnica = kwota w walucie × (kurs wpłaty − kurs faktury), zaokrąglona do grosza ; plus = dodatnia, minus = ujemna. Przedpłata przed fakturą liczy się w miesiącu wystawienia faktury.');
        return ['name' => 'ROZNICE_KURSOWE_' . $month . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    /** CSV (;) : une ligne par facture, totaux, puis les positions à expliquer. @return array{name: string, content: string} */
    public function csv(?string $month, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $month = self::month($month, $today);
        $c = $this->compute($month, $today);
        $num = fn(?float $v, int $dec) => $v === null ? '' : number_format($v, $dec, ',', '');
        $cell = fn($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $line = fn(array $cols) => implode(';', array_map($cell, $cols));
        $out = ["\u{FEFF}" . $line(['Miesiąc', 'Data wpłaty', 'Konto', 'Klient', 'Faktura', 'Data faktury', 'Proforma', 'Kwota', 'Waluta', 'Kurs faktury', 'Tabela NBP faktury',
            'Kurs wpłaty', 'Źródło kursu wpłaty', 'Różnica PLN', 'Stan'])];
        foreach ($c['pieces'] as $p) {
            $r = $p['row'];
            $out[] = $line([$month, $r['booked_on'], $r['account'], $r['customer']['name'] ?? '', $p['invoice']['number'], $p['invoice']['date'] ?? '', $p['proforma']['number'] ?? '',
                $num($p['amount'], 2), $r['currency'], $num($p['inv_rate']['rate'], 4), $p['inv_rate']['table'] . ' (' . $p['inv_rate']['date'] . ')',
                $num($r['pay_rate']['rate'], 4), $r['pay_rate']['source'] === 'bank' ? 'ING (przewalutowanie)' : 'NBP ' . $r['pay_rate']['table'] . ' (' . $r['pay_rate']['date'] . ')',
                $num($p['diff'], 2), 'policzone']);
        }
        $out[] = '';
        $out[] = $line([$month, '', '', 'Różnice dodatnie', '', '', '', '', '', '', '', '', '', $num($c['totals']['positive'], 2), '']);
        $out[] = $line([$month, '', '', 'Różnice ujemne', '', '', '', '', '', '', '', '', '', $num($c['totals']['negative'], 2), '']);
        $out[] = $line([$month, '', '', 'Saldo', '', '', '', '', '', '', '', '', '', $num($c['totals']['balance'], 2), '']);
        foreach ($c['open'] as $r) {
            $out[] = $line([$month, $r['booked_on'], $r['account'], $r['customer']['name'] ?? '', implode(', ', array_map(fn($w) => $w['proforma']['number'], $r['waiting'])), '', '',
                $num($r['amount'], 2), $r['currency'], '', '', '', '', '', 'do wyjaśnienia : ' . self::openReason($r)]);
        }
        return ['name' => 'ROZNICE_KURSOWE_' . $month . '.csv', 'content' => implode("\r\n", $out) . "\r\n"];
    }

    // --- Fonctions pures (testées) ----------------------------------------------------------------------------------

    /**
     * Różnica en zł : montant × (kurs wpłaty − kurs faktury), au grosz, moitié loin de zéro ; calcul en entiers (grosze de
     * la devise × kurs à 1e-8) : 1 250,00 × (4,2105 − 4,2380) = −34,375 → −34,38.
     */
    public static function diff(float $amount, float $payRate, float $invRate): float
    {
        $cents = (int) round($amount * 100);
        $d = (int) round($payRate * 100000000) - (int) round($invRate * 100000000);
        $v = $cents * $d;                                   // 1e-10 zł
        $q = intdiv(abs($v) + 50000000, 100000000);         // grosze
        return ($v < 0 ? -$q : $q) / 100;
    }

    /** Mois demandé (RRRR-MM) ; par défaut le mois en cours ; au plus le mois en cours. */
    public static function month(?string $m, string $today): string
    {
        $cur = substr($today, 0, 7);
        if ($m === null || $m === '') return $cur;
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $m)) throw new CustomException('Miesiąc w formacie RRRR-MM.', 'errors', 422);
        return $m > $cur ? $cur : $m;
    }

    public static function monthLabel(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
    }

    public static function monthEnd(string $ym): string
    {
        return (new \DateTimeImmutable($ym . '-01'))->modify('last day of this month')->format('Y-m-d');
    }

    /** Mois précédent, courant, suivant (le suivant seulement s'il n'est pas dans le futur). */
    public static function nav(string $month, string $today): array
    {
        $prev = (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
        $next = (new \DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');
        $cap = fn(string $s) => mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        return [
            ['month' => $prev, 'label' => $cap(self::MONTHS[(int) substr($prev, 5, 2) - 1]), 'on' => false],
            ['month' => $month, 'label' => $cap(self::monthLabel($month)), 'on' => true],
            ['month' => $next, 'label' => $cap(self::MONTHS[(int) substr($next, 5, 2) - 1]), 'on' => false, 'disabled' => $next > substr($today, 0, 7)],
        ];
    }

    public static function statementLabel(array $s): string
    {
        $acc = BankStatementService::ACCOUNTS[$s['account_currency']] ?? $s['account_currency'];
        $f = $s['period_from'] ?? null;
        $t = $s['period_to'] ?? null;
        if ($f && $t && substr($f, 0, 7) === substr($t, 0, 7)) {
            $whole = substr($f, 8, 2) === '01' && $t === self::monthEnd(substr($t, 0, 7));
            return $acc . ' · ' . ($whole ? self::MONTHS[(int) substr($f, 5, 2) - 1] : substr($f, 8, 2) . '–' . self::dm($t));
        }
        if ($f && $t) return $acc . ' · ' . self::dm($f) . '–' . self::dm($t);
        if ($f) return $acc . ' · od ' . self::dm($f);
        return $acc . ' · ' . ($s['filename'] ?? 'wyciąg');
    }

    private static function openReason(array $r): string
    {
        return match ($r['state']) {
            'to_confirm' => $r['note'] ?: 'do potwierdzenia',
            'waiting' => 'przedpłata, faktura jeszcze nie wystawiona',
            'no_rate' => 'brak kursu ' . ($r['pay_rate'] ? 'NBP dla faktury' : ($r['account'] === 'PLN' ? 'banku (konto PLN)' : 'NBP dla wpłaty')),
            'overpaid' => 'nadwyżka ' . self::money($r['remainder']) . ' ' . $r['currency'] . ' bez dokumentu',
            default => $r['state_label'],
        };
    }

    private static function openText(array $r): string
    {
        return self::dm($r['booked_on']) . ' ' . ($r['customer']['name'] ?? 'klient nieznany') . ' ' . self::money($r['amount']) . ' ' . $r['currency'] . ' — ' . self::openReason($r);
    }

    private static function d(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : substr((string) $v, 0, 10);
    }

    public static function dm(string $d): string
    {
        return substr($d, 8, 2) . '.' . substr($d, 5, 2);
    }

    public static function dmy(string $d): string
    {
        return substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4);
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    public static function rate(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 4, ',', ' ');
    }

    /** « +28,42 », « −57,60 » (signe moins typographique), « 0,00 ». */
    public static function signed(?float $v): string
    {
        if ($v === null) return '—';
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . self::money(abs($v));
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) return $one;
        return in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many;
    }
}
