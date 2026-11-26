<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

use App\Exceptions\CustomException;
use App\Repositories\Finances\FxDifferenceRepository;
use App\Services\Finances\PaymentProof\PaymentProofCipher;
use App\Services\Finances\PaymentProof\PaymentProofParser;

/**
 * [439] Wyciągi bankowe et wpłaty klientów w walucie (Różnice kursowe) :
 *  - wyciąg (PDF) : enregistré chiffré comme les potwierdzenia wpłat (PaymentProofCipher, AES-256-GCM, p1:), texte lu par
 *    pdftotext (chiffré lui aussi), compte EUR / PLN choisi à l'envoi ; lecture par BankStatementParser (ING : format pas
 *    encore pris en charge → fichier gardé, lignes à saisir) ; seuls les crédits en devise deviennent des wpłaty, les
 *    autres lignes sont comptées ;
 *  - wpłata saisie ou corrigée à la main (date, montant, devise, klient, payeur, titre ; compte PLN : kurs ING ou montant
 *    en zł) ; payeur et titre chiffrés (données du client) ;
 *  - dopasowanie : numéro de facture (WDT) ou de proforma dans le titre d'abord (« FV 0150 + 0151 » : numéros courts des
 *    factures du klient), sinon klient + montant (une seule pièce du montant) ; incertain = « do potwierdzenia » avec les
 *    pièces candidates ; un virement pour plusieurs factures est réparti dans l'ordre des dates, paiement partiel permis,
 *    excédent laissé « do wyjaśnienia ».
 * Écrit seulement n_bank_statement, n_bank_statement_line, n_fx_allocation. Aucun e-mail. Rien de personnel dans les journaux.
 */
final class BankStatementService
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const ACCOUNTS = ['EUR' => 'Konto EUR', 'PLN' => 'Konto PLN'];
    /** Documents examinés : depuis 400 jours avant la wpłata (przedpłata, paiement en retard). */
    public const DOC_DAYS = 400;
    private const LEGAL = ['sprl', 'srl', 'sa', 'nv', 'bv', 'bvba', 'sp', 'z', 'o', 'oo', 'zoo', 'spolka', 'sarl', 'sas', 'gmbh', 'ltd', 'sc', 'sk', 'snc', 'eurl'];

    /** @var list<array{id: int, name: string, currency: string}>|null */
    private ?array $customers = null;

    public function __construct(
        private FxDifferenceRepository $repo,
        private PaymentProofCipher $cipher,
        private BankStatementParser $parser,
    ) {
    }

    // --- Wyciąg PDF -------------------------------------------------------------------------------------------------

    /**
     * Wyciąg wgrany : fichier chiffré, texte lu, lignes si le format est connu.
     * $in : account (EUR|PLN), period_from, period_to (facultatifs).
     * @return array{id: int, status: string, message: string, lines: int, other: int}
     */
    public function upload(string $content, string $filename, array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if ($content === '') throw new CustomException('Wybierz plik PDF wyciągu.', 'errors', 422);
        if (strlen($content) > self::MAX_BYTES) throw new CustomException('Plik jest za duży (najwyżej 15 MB).', 'errors', 413);
        if (!str_starts_with($content, '%PDF')) throw new CustomException('To nie jest plik PDF — wgraj wyciąg ING w PDF.', 'errors', 422);
        $account = self::account($in['account'] ?? null);
        [$from, $to] = [self::date($in['period_from'] ?? null, 'Okres od'), self::date($in['period_to'] ?? null, 'Okres do')];
        if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
        $sha = hash('sha256', $content);
        if ($old = $this->repo->statementBySha($sha)) throw new CustomException('Ten wyciąg jest już wczytany (' . self::ACCOUNTS[$old['account_currency']] . ').', 'errors', 409);

        $text = self::pdfText($content);
        $status = 'parsed';
        $message = null;
        $rows = [];
        if (mb_strlen(trim((string) preg_replace('/\s+/', ' ', $text))) < 20) {
            $status = 'no_text';
            $message = 'PDF bez tekstu (skan) — wpisz wpłaty ręcznie.';
        } else {
            try {
                $rows = $this->parser->parse($text);
            } catch (StatementFormatNotSupported $e) {
                $status = 'unsupported';
                $message = mb_substr($e->getMessage(), 0, 255);
            }
        }
        $keep = [];
        $other = 0;
        foreach ($rows as $r) {
            $cur = strtoupper((string) ($r['currency'] ?? ''));
            $ok = !empty($r['incoming']) && preg_match('/^[A-Z]{3}$/', $cur) && $cur !== 'PLN' && (float) ($r['amount'] ?? 0) > 0
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['booked_on'] ?? ''));
            if ($ok) $keep[] = $r; else $other++;
        }
        if ($status === 'parsed' && $keep) {
            $dates = array_column($keep, 'booked_on');
            $from ??= min($dates);
            $to ??= max($dates);
        }
        $id = $this->repo->insertStatement([
            'bank' => 'ING', 'account_currency' => $account, 'period_from' => $from, 'period_to' => $to,
            'filename' => mb_substr($filename !== '' ? $filename : 'wyciag.pdf', 0, 255), 'mime' => 'application/pdf', 'size_bytes' => strlen($content),
            'content_sha256' => $sha, 'content' => $this->cipher->encrypt($content), 'text_enc' => $text !== '' ? $this->cipher->encrypt(mb_substr($text, 0, 500000)) : null,
            'parser' => $this->parser->name(), 'parse_status' => $status, 'parse_message' => $message, 'lines_other' => $other,
            'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        foreach ($keep as $r) {
            $lineId = $this->repo->insertLine($this->lineFields($account, [
                'booked_on' => $r['booked_on'], 'amount' => (float) $r['amount'], 'currency' => strtoupper((string) $r['currency']),
                'amount_pln' => isset($r['amount_pln']) ? (float) $r['amount_pln'] : null, 'bank_rate' => isset($r['bank_rate']) ? (float) $r['bank_rate'] : null,
                'payer' => $r['payer'] ?? null, 'title' => $r['title'] ?? null, 'id_customer' => null,
            ]) + ['id_statement' => $id, 'source' => 'pdf', 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
            $this->autoMatch($lineId, $userId);
        }
        $n = count($keep);
        $message ??= $n ? 'Wczytano ' . $n . ' ' . self::pl($n, 'wpłatę', 'wpłaty', 'wpłat') . ' klientów w walucie.' : 'Wyciąg zapisany — brak wpłat klientów w walucie.';
        return ['id' => $id, 'status' => $status, 'message' => $message, 'lines' => $n, 'other' => $other];
    }

    /** Plik wyciągu (odszyfrowany). @return array{filename: string, mime: string, content: string} */
    public function file(int $id): array
    {
        $s = $this->repo->statement($id) ?? throw new CustomException('Nie znaleziono wyciągu.', 'errors', 404);
        $content = $this->cipher->decrypt($s['content']);
        if ($content === null) throw new CustomException('Brak pliku wyciągu.', 'errors', 404);
        return ['filename' => (string) $s['filename'], 'mime' => (string) ($s['mime'] ?: 'application/pdf'), 'content' => $content];
    }

    public function deleteStatement(int $id): void
    {
        if (!$this->repo->statement($id)) throw new CustomException('Nie znaleziono wyciągu.', 'errors', 404);
        $this->repo->deleteStatement($id);
    }

    // --- Wpłata ręcznie ---------------------------------------------------------------------------------------------

    /**
     * Wpłata dodana albo poprawiona : $in = account, id_statement, booked_on, amount, currency, amount_pln, bank_rate,
     * id_customer, payer, title. Après l'enregistrement, dopasowanie automatique (les przypisania précédents sont refaits).
     */
    public function saveLine(?int $id, array $in, ?int $userId, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $old = null;
        if ($id !== null) $old = $this->repo->line($id) ?? throw new CustomException('Nie znaleziono wpłaty.', 'errors', 404);
        $account = self::account($in['account'] ?? $old['account_currency'] ?? null);
        $statementId = isset($in['id_statement']) && $in['id_statement'] !== '' && $in['id_statement'] !== null ? (int) $in['id_statement'] : null;
        if ($statementId !== null) {
            $s = $this->repo->statement($statementId) ?? throw new CustomException('Nie znaleziono wyciągu.', 'errors', 404);
            if ($s['account_currency'] !== $account) throw new CustomException('Wyciąg jest z rachunku ' . $s['account_currency'] . ' — wybierz ten sam rachunek.', 'errors', 422);
        }
        $date = self::date($in['booked_on'] ?? null, 'Data wpłaty') ?? throw new CustomException('Podaj datę wpłaty.', 'errors', 422);
        if ($date > $today) throw new CustomException('Data wpłaty nie może być z przyszłości.', 'errors', 422);
        $amount = self::num($in['amount'] ?? null);
        if ($amount === null || $amount <= 0 || $amount > 10000000) throw new CustomException('Kwota wpłaty : liczba większa od 0.', 'errors', 422);
        $currency = strtoupper(trim((string) ($in['currency'] ?? 'EUR')));
        if (!preg_match('/^[A-Z]{3}$/', $currency) || $currency === 'PLN') throw new CustomException('Waluta wpłaty : kod waluty obcej (np. EUR).', 'errors', 422);
        $rate = self::num($in['bank_rate'] ?? null);
        $pln = self::num($in['amount_pln'] ?? null);
        if ($account === 'PLN') {
            if ($rate !== null && ($rate <= 0 || $rate >= 1000)) throw new CustomException('Kurs banku : liczba większa od 0 (np. 4,2105).', 'errors', 422);
            if ($pln !== null && $pln <= 0) throw new CustomException('Kwota w zł : liczba większa od 0.', 'errors', 422);
            if ($rate === null && $pln === null) throw new CustomException('Rachunek PLN : podaj kurs zastosowany przez bank albo kwotę w zł z wyciągu.', 'errors', 422);
        }
        $customerId = isset($in['id_customer']) && $in['id_customer'] !== '' && $in['id_customer'] !== null ? (int) $in['id_customer'] : null;
        if ($customerId !== null && !$this->repo->customer($customerId)) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
        $fields = $this->lineFields($account, ['booked_on' => $date, 'amount' => $amount, 'currency' => $currency, 'amount_pln' => $pln, 'bank_rate' => $rate,
            'payer' => self::text($in['payer'] ?? null), 'title' => self::text($in['title'] ?? null), 'id_customer' => $customerId]) + ['id_statement' => $statementId];
        if ($id === null) {
            $id = $this->repo->insertLine($fields + ['source' => 'manual', 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->repo->updateLine($id, $fields + ['updated_by' => $userId]);
        }
        $this->autoMatch($id, $userId);
        return $id;
    }

    public function deleteLine(int $id): void
    {
        if (!$this->repo->line($id)) throw new CustomException('Nie znaleziono wpłaty.', 'errors', 404);
        $this->repo->deleteLine($id);
    }

    /** « Nie dotyczy » (pas un paiement de client à rozliczyć) ou retour au dopasowanie automatique. */
    public function ignore(int $id, bool $ignore, ?int $userId): void
    {
        if (!$this->repo->line($id)) throw new CustomException('Nie znaleziono wpłaty.', 'errors', 404);
        if ($ignore) {
            $this->repo->replaceAllocations($id, [], $userId);
            $this->repo->updateLine($id, ['match_state' => 'ignored', 'match_note' => 'nie dotyczy (oznaczone ręcznie)', 'updated_by' => $userId]);
            return;
        }
        $this->repo->updateLine($id, ['match_state' => 'to_confirm', 'match_note' => null, 'updated_by' => $userId]);
        $this->autoMatch($id, $userId);
    }

    /** Wpłata en clair pour le formulaire de correction (payeur et titre déchiffrés). */
    public function lineView(int $id): array
    {
        $l = $this->repo->line($id) ?? throw new CustomException('Nie znaleziono wpłaty.', 'errors', 404);
        return ['id' => (int) $l['id'], 'account' => $l['account_currency'], 'id_statement' => $l['id_statement'] !== null ? (int) $l['id_statement'] : null,
            'booked_on' => $l['booked_on'], 'amount' => (float) $l['amount'], 'currency' => $l['currency'],
            'amount_pln' => $l['amount_pln'] !== null ? (float) $l['amount_pln'] : null, 'bank_rate' => $l['bank_rate'] !== null ? (float) $l['bank_rate'] : null,
            'id_customer' => $l['id_customer'] !== null ? (int) $l['id_customer'] : null, 'customer' => $l['customer_name'],
            'payer' => $this->cipher->decrypt($l['payer_enc']), 'title' => $this->cipher->decrypt($l['title_enc']), 'source' => $l['source']];
    }

    /** Titre déchiffré (écran, PDF) ; null si absent. */
    public function title(array $line): ?string
    {
        return $this->cipher->decrypt($line['title_enc'] ?? null);
    }

    // --- Dopasowanie ------------------------------------------------------------------------------------------------

    /**
     * « Zapisz » d'une wpłata « do potwierdzenia » : pièces choisies (dans l'ordre), réparties jusqu'au reste à payer de
     * chacune. $docs : liste de « wdt:12 » / « proforma:5 » (ou [kind, id]).
     */
    public function allocate(int $id, array $docs, ?int $userId, ?string $today = null): void
    {
        $line = $this->repo->line($id) ?? throw new CustomException('Nie znaleziono wpłaty.', 'errors', 404);
        $wanted = [];
        foreach ($docs as $d) {
            $k = is_array($d) ? (($d['kind'] ?? $d[0] ?? '') . ':' . ($d['id'] ?? $d[1] ?? '')) : (string) $d;
            if (!preg_match('/^(wdt|proforma):(\d+)$/', $k, $m)) continue;
            $wanted[$k] = [$m[1], (int) $m[2]];
        }
        if (!$wanted) throw new CustomException('Zaznacz fakturę albo proformę, której dotyczy wpłata.', 'errors', 422);
        if (count($wanted) > 20) throw new CustomException('Najwyżej 20 dokumentów dla jednej wpłaty.', 'errors', 422);
        $w = $this->repo->wdts(array_map(fn($x) => $x[1], array_filter($wanted, fn($x) => $x[0] === 'wdt')));
        $p = $this->repo->proformas(array_map(fn($x) => $x[1], array_filter($wanted, fn($x) => $x[0] === 'proforma')));
        $list = [];
        foreach ($wanted as [$kind, $docId]) {
            $doc = $kind === 'wdt' ? ($w[$docId] ?? null) : ($p[$docId] ?? null);
            if (!$doc) throw new CustomException('Nie znaleziono dokumentu.', 'errors', 404);
            if ($doc['currency'] !== $line['currency']) throw new CustomException('Dokument ' . $doc['doc_num'] . ' jest w ' . $doc['currency'] . ', wpłata w ' . $line['currency'] . '.', 'errors', 422);
            if ($line['id_customer'] !== null && (int) $doc['id_customer'] !== (int) $line['id_customer']) throw new CustomException('Dokument ' . $doc['doc_num'] . ' należy do innego klienta.', 'errors', 422);
            $list[] = ['kind' => $kind] + $doc;
        }
        $open = $this->openAmounts($list, $id);
        foreach ($list as &$d) $d['open'] = $open[$d['kind'] . ':' . $d['id']];
        unset($d);
        [$rows, $rest] = self::spread((float) $line['amount'], $list, 'manual');
        if (!$rows) throw new CustomException('Wybrane dokumenty są już rozliczone innymi wpłatami.', 'errors', 422);
        $this->repo->replaceAllocations($id, $rows, $userId);
        $this->repo->updateLine($id, ['match_state' => 'matched', 'match_note' => 'wybrane ręcznie' . ($rest > 0.005 ? ' · nadwyżka ' . self::money($rest) . ' ' . $line['currency'] : ''),
            'id_customer' => $line['id_customer'] ?? (int) $list[0]['id_customer'], 'updated_by' => $userId]);
    }

    /**
     * Dopasowanie automatique d'une wpłata (pas « nie dotyczy ») : numéros dans le titre, sinon klient + montant.
     * @return array{state: string, note: ?string}
     */
    public function autoMatch(int $id, ?int $userId): array
    {
        $line = $this->repo->line($id) ?? throw new CustomException('Nie znaleziono wpłaty.', 'errors', 404);
        if ($line['match_state'] === 'ignored') return ['state' => 'ignored', 'note' => $line['match_note']];
        $r = $this->propose($line);
        $this->repo->replaceAllocations($id, $r['rows'], $userId);
        $this->repo->updateLine($id, ['match_state' => $r['state'], 'match_note' => $r['note'], 'ref_numbers' => $r['refs'] ? mb_substr(implode(', ', $r['refs']), 0, 255) : null,
            'id_customer' => $r['customer']]);
        return ['state' => $r['state'], 'note' => $r['note']];
    }

    /**
     * Wpłaty « do potwierdzenia » refaites (une facture a pu être wystawiona depuis) : seulement si le résultat devient sûr.
     * @param list<array> $lines lignes n_bank_statement_line
     */
    public function retry(array $lines, ?int $userId): int
    {
        $n = 0;
        foreach ($lines as $l) {
            if ($l['match_state'] !== 'to_confirm') continue;
            $r = $this->propose($l);
            if ($r['state'] !== 'matched') continue;
            $this->repo->replaceAllocations((int) $l['id'], $r['rows'], $userId);
            $this->repo->updateLine((int) $l['id'], ['match_state' => 'matched', 'match_note' => $r['note'], 'ref_numbers' => $r['refs'] ? mb_substr(implode(', ', $r['refs']), 0, 255) : null,
                'id_customer' => $r['customer']]);
            $n++;
        }
        return $n;
    }

    /**
     * Pièces candidates d'une wpłata « do potwierdzenia » : celles du montant, sinon toutes les pièces encore ouvertes du
     * klient (devise de la wpłata). @return list<array{key: string, kind: string, id: int, number: string, date: ?string, total: float, open: float}>
     */
    public function candidates(array $line): array
    {
        $customerId = $line['id_customer'] !== null ? (int) $line['id_customer'] : null;
        if ($customerId === null) return [];
        $docs = $this->openDocs($customerId, (string) $line['currency'], (string) $line['booked_on'], (int) $line['id']);
        $amount = (float) $line['amount'];
        $same = array_values(array_filter($docs, fn($d) => abs($d['open'] - $amount) < 0.005));
        $list = $same ?: $docs;
        return array_map(fn($d) => ['key' => $d['kind'] . ':' . $d['id'], 'kind' => $d['kind'], 'id' => (int) $d['id'], 'number' => (string) $d['doc_num'],
            'date' => $d['doc_date'] ? substr((string) $d['doc_date'], 0, 10) : null, 'total' => round((float) $d['total'], 2), 'open' => round($d['open'], 2)], array_slice($list, 0, 12));
    }

    /** @return array{state: string, note: ?string, rows: list<array>, refs: list<string>, customer: ?int} */
    private function propose(array $line): array
    {
        $currency = (string) $line['currency'];
        $amount = (float) $line['amount'];
        $since = self::addDays((string) $line['booked_on'], -self::DOC_DAYS);
        $title = (string) ($this->cipher->decrypt($line['title_enc']) ?? '');
        $payer = $this->cipher->decrypt($line['payer_enc']);
        $customerId = $line['id_customer'] !== null ? (int) $line['id_customer'] : null;
        $norm = self::norm($title);

        // 1. Numéros complets dans le titre (factures WDT, proformas ; numéros FP lus comme les potwierdzenia).
        $found = [];
        if ($norm !== '') {
            [$w, $p] = $this->repo->docsInTitle($norm, $since);
            foreach (array_merge($w, $p) as $d) if (self::inTitle($norm, (string) $d['doc_num'])) $found[$d['kind'] . ':' . $d['id']] = $d;
            foreach (PaymentProofParser::parse($title, (string) $line['booked_on'])['numbers'] as $fp) {
                foreach ($p as $d) if (self::norm((string) $d['doc_num']) === self::norm($fp)) $found['proforma:' . $d['id']] = $d;
            }
            // Numéro plus court contenu dans un plus long trouvé (« PF/1/01 » dans « PF/1/012 ») : on garde le long.
            foreach ($found as $k => $d) foreach ($found as $k2 => $d2) {
                if ($k !== $k2 && strlen(self::norm((string) $d2['doc_num'])) > strlen(self::norm((string) $d['doc_num'])) && str_contains(self::norm((string) $d2['doc_num']), self::norm((string) $d['doc_num']))) unset($found[$k]);
            }
        }
        $owners = array_values(array_unique(array_map(fn($d) => (int) $d['id_customer'], $found)));
        if ($customerId === null && count($owners) === 1) $customerId = $owners[0];
        if ($customerId === null && count($owners) > 1) {
            return ['state' => 'to_confirm', 'note' => 'numery dokumentów różnych klientów', 'rows' => [], 'refs' => array_values(array_map(fn($d) => (string) $d['doc_num'], $found)), 'customer' => null];
        }
        if ($customerId === null && $payer !== null) $customerId = $this->customerByPayer($payer);
        $other = $customerId !== null ? array_filter($found, fn($d) => (int) $d['id_customer'] !== $customerId) : [];
        $found = $customerId !== null ? array_filter($found, fn($d) => (int) $d['id_customer'] === $customerId) : $found;

        // 2. Numéros courts des factures du klient (« FV 0150 + 0151 »).
        $docs = $customerId !== null ? $this->openDocs($customerId, $currency, (string) $line['booked_on'], (int) $line['id'], true) : [];
        if (!$found && $customerId !== null) {
            $tokens = self::shortRefs($title);
            foreach ($docs as $d) {
                if ($d['kind'] !== 'wdt') continue;
                $g = self::lastGroup((string) $d['doc_num']);
                if ($g !== null && in_array($g, $tokens, true)) $found['wdt:' . $d['id']] = $d;
            }
        }
        $refs = array_values(array_map(fn($d) => (string) $d['doc_num'], $found));
        if ($found) {
            $wrong = array_filter($found, fn($d) => $d['currency'] !== $currency);
            $found = array_filter($found, fn($d) => $d['currency'] === $currency);
            // Proforma et sa facture citées ensemble : la facture suffit.
            $links = $this->repo->proformaWdts(array_map(fn($d) => (int) $d['id'], array_filter($found, fn($d) => $d['kind'] === 'proforma')));
            foreach ($found as $k => $d) {
                if ($d['kind'] === 'proforma' && array_intersect(array_map(fn($x) => 'wdt:' . $x, $links[(int) $d['id']] ?? []), array_keys($found))) unset($found[$k]);
            }
            if (!$found) return ['state' => 'to_confirm', 'note' => $wrong ? 'dokument w innej walucie' : 'numer z tytułu nie pasuje', 'rows' => [], 'refs' => $refs, 'customer' => $customerId];
            $list = array_values($found);
            $open = $this->openAmounts($list, (int) $line['id']);
            foreach ($list as &$d) $d['open'] = $open[$d['kind'] . ':' . $d['id']];
            unset($d);
            usort($list, fn($a, $b) => [(string) $a['doc_date'], $a['kind'] === 'wdt' ? 0 : 1, (int) $a['id']] <=> [(string) $b['doc_date'], $b['kind'] === 'wdt' ? 0 : 1, (int) $b['id']]);
            [$rows, $rest] = self::spread($amount, $list, 'number');
            if (!$rows) return ['state' => 'to_confirm', 'note' => 'dokument z tytułu już rozliczony', 'rows' => [], 'refs' => $refs, 'customer' => $customerId];
            return ['state' => 'matched', 'note' => 'numer w tytule' . ($rest > 0.005 ? ' · nadwyżka ' . self::money($rest) . ' ' . $currency : ''), 'rows' => $rows, 'refs' => $refs,
                'customer' => $customerId ?? (int) $list[0]['id_customer']];
        }
        if ($other) return ['state' => 'to_confirm', 'note' => 'numer dokumentu innego klienta', 'rows' => [], 'refs' => array_values(array_map(fn($d) => (string) $d['doc_num'], $other)), 'customer' => $customerId];

        // 3. Klient + montant : une seule pièce ouverte de ce montant.
        if ($customerId === null) return ['state' => 'to_confirm', 'note' => 'brak klienta i numeru dokumentu', 'rows' => [], 'refs' => [], 'customer' => null];
        $same = array_values(array_filter($docs, fn($d) => abs($d['open'] - $amount) < 0.005));
        if (count($same) === 1) {
            return ['state' => 'matched', 'note' => 'kwota klienta (bez numeru w tytule)', 'rows' => [['doc_kind' => $same[0]['kind'], 'id_doc' => (int) $same[0]['id'], 'amount' => round($amount, 2), 'source' => 'amount']],
                'refs' => [], 'customer' => $customerId];
        }
        return ['state' => 'to_confirm', 'note' => $same ? 'brak numeru — ' . count($same) . ' dokumenty o tej kwocie' : 'brak numeru dokumentu', 'rows' => [], 'refs' => [], 'customer' => $customerId];
    }

    /**
     * Pièces du klient encore ouvertes dans la devise (WDT, proformas sans facture ou avec facture non réglée).
     * $all : aussi celles déjà réglées (numéros courts).
     */
    private function openDocs(int $customerId, string $currency, string $bookedOn, int $exceptLine, bool $all = false): array
    {
        [$w, $p] = $this->repo->customerDocs($customerId, self::addDays($bookedOn, -self::DOC_DAYS));
        $list = array_values(array_filter(array_merge($w, $p), fn($d) => $d['currency'] === $currency && (float) $d['total'] > 0 && empty($d['superseded_by'])));
        $open = $this->openAmounts($list, $exceptLine);
        $out = [];
        foreach ($list as $d) {
            $d['open'] = $open[$d['kind'] . ':' . $d['id']];
            if ($all || $d['open'] > 0.005) $out[] = $d;
        }
        return $out;
    }

    /**
     * Reste à payer de chaque pièce : total − przypisania des autres wpłaty ; pour une facture, aussi ce qui est przypisane
     * à ses proformas (przedpłata) ; pour une proforma déjà facturée, aussi ce qui est przypisane à sa facture.
     * @return array<string, float> « wdt:12 » → reste
     */
    private function openAmounts(array $docs, int $exceptLine): array
    {
        $wIds = array_map(fn($d) => (int) $d['id'], array_filter($docs, fn($d) => $d['kind'] === 'wdt'));
        $pIds = array_map(fn($d) => (int) $d['id'], array_filter($docs, fn($d) => $d['kind'] === 'proforma'));
        $wp = $this->repo->wdtProformas($wIds);
        $pw = $this->repo->proformaWdts($pIds);
        $allP = array_merge($pIds, ...array_values($wp ?: [[]]));
        $allW = array_merge($wIds, ...array_values($pw ?: [[]]));
        $taken = $this->repo->allocatedTo($allW, $allP, $exceptLine);
        $out = [];
        foreach ($docs as $d) {
            $k = $d['kind'] . ':' . $d['id'];
            $used = $taken[$k] ?? 0.0;
            if ($d['kind'] === 'wdt') foreach ($wp[(int) $d['id']] ?? [] as $pid) $used += $taken['proforma:' . $pid] ?? 0.0;
            else foreach ($pw[(int) $d['id']] ?? [] as $wid) $used += $taken['wdt:' . $wid] ?? 0.0;
            $out[$k] = round(max(0.0, (float) $d['total'] - $used), 2);
        }
        return $out;
    }

    /** Klient dont tous les mots du nom figurent dans le payeur (le nom le plus précis l'emporte ; égalité → personne). */
    private function customerByPayer(string $payer): ?int
    {
        $pw = self::words($payer);
        if (!$pw) return null;
        $this->customers ??= $this->repo->customers();
        $best = null;
        $bestN = 0;
        $tie = false;
        foreach ($this->customers as $c) {
            $cw = self::words((string) $c['name']);
            if (!$cw || array_diff($cw, $pw)) continue;
            if (count($cw) > $bestN) { $best = (int) $c['id']; $bestN = count($cw); $tie = false; }
            elseif (count($cw) === $bestN) $tie = true;
        }
        return $tie ? null : $best;
    }

    // --- Fonctions pures (testées) ----------------------------------------------------------------------------------

    /**
     * Répartit le montant sur les pièces (dans l'ordre), chacune jusqu'à son reste ('open').
     * @return array{0: list<array{doc_kind: string, id_doc: int, amount: float, source: string}>, 1: float} [przypisania, reste]
     */
    public static function spread(float $amount, array $docs, string $source): array
    {
        $rest = round($amount, 2);
        $rows = [];
        foreach ($docs as $d) {
            if ($rest <= 0.005) break;
            $take = round(min($rest, max(0.0, (float) $d['open'])), 2);
            if ($take <= 0.005) continue;
            $rows[] = ['doc_kind' => $d['kind'], 'id_doc' => (int) $d['id'], 'amount' => $take, 'source' => $source];
            $rest = round($rest - $take, 2);
        }
        return [$rows, $rest];
    }

    /** « FV/2026/11/0088 — zapłata » → « FV2026110088ZAPATA » (lettres et chiffres). */
    public static function norm(string $s): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $s));
    }

    /** Numéro (6 caractères normalisés au moins) dans le titre normalisé, sans chiffre collé avant ou après. */
    public static function inTitle(string $normTitle, string $docNum): bool
    {
        $n = self::norm($docNum);
        if (strlen($n) < 6) return false;
        for ($pos = 0; ($p = strpos($normTitle, $n, $pos)) !== false; $pos = $p + 1) {
            $before = $p > 0 ? $normTitle[$p - 1] : '';
            $after = $normTitle[$p + strlen($n)] ?? '';
            if (ctype_digit($n[0]) && $before !== '' && ctype_digit($before)) continue;
            if (ctype_digit(substr($n, -1)) && $after !== '' && ctype_digit($after)) continue;
            return true;
        }
        return false;
    }

    /** Nombres de 3 chiffres et plus du titre, zéros de tête retirés, sans les années (19xx, 20xx). @return list<string> */
    public static function shortRefs(string $title): array
    {
        preg_match_all('/\d{3,}/', $title, $m);
        $out = [];
        foreach ($m[0] as $t) {
            if (strlen($t) === 4 && preg_match('/^(19|20)\d\d$/', $t)) continue;
            $v = ltrim($t, '0');
            if ($v !== '') $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    /** Dernier groupe de chiffres du numéro, zéros de tête retirés (« FV/2026/11/0150 » → « 150 »). */
    public static function lastGroup(string $docNum): ?string
    {
        if (!preg_match('/(\d+)\D*$/', $docNum, $m)) return null;
        $v = ltrim($m[1], '0');
        return $v === '' ? null : $v;
    }

    /** Mots d'un nom (minuscules, sans accents ni formes juridiques). @return list<string> */
    public static function words(string $s): array
    {
        $s = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        $w = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_diff($w, self::LEGAL)));
    }

    /** « 1 250,00 », « 1250.5 », 4.2105 → float ; vide → null. */
    public static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (!is_string($v)) return null;
        $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($v));
        if ($s === '') return null;
        if (str_contains($s, ',') && str_contains($s, '.')) $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : throw new CustomException('Nieprawidłowa liczba : ' . mb_substr($v, 0, 20) . '.', 'errors', 422);
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    public static function addDays(string $day, int $n): string
    {
        return (new \DateTimeImmutable(substr($day, 0, 10)))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    // --- pomocnicze -------------------------------------------------------------------------------------------------

    private function lineFields(string $account, array $v): array
    {
        $rate = $v['bank_rate'];
        $pln = $v['amount_pln'];
        if ($account === 'PLN') {
            if ($rate === null && $pln !== null && $v['amount'] > 0) $rate = round($pln / $v['amount'], 6);
            if ($pln === null && $rate !== null) $pln = round($v['amount'] * $rate, 2);
        } else {
            $rate = null;
            $pln = null;
        }
        return ['account_currency' => $account, 'booked_on' => $v['booked_on'], 'amount' => round($v['amount'], 2), 'currency' => $v['currency'],
            'amount_pln' => $pln !== null ? round($pln, 2) : null, 'bank_rate' => $rate !== null ? round($rate, 6) : null,
            'payer_enc' => $v['payer'] !== null && $v['payer'] !== '' ? $this->cipher->encrypt(mb_substr((string) $v['payer'], 0, 300)) : null,
            'title_enc' => $v['title'] !== null && $v['title'] !== '' ? $this->cipher->encrypt(mb_substr((string) $v['title'], 0, 600)) : null,
            'id_customer' => $v['id_customer'], 'match_state' => 'to_confirm', 'match_note' => null];
    }

    private static function account(mixed $v): string
    {
        $v = strtoupper(trim((string) $v));
        if (!isset(self::ACCOUNTS[$v])) throw new CustomException('Wybierz rachunek : EUR (walutowy) albo PLN (bank przewalutował).', 'errors', 422);
        return $v;
    }

    private static function date(mixed $v, string $label): ?string
    {
        if ($v === null || $v === '') return null;
        $v = trim((string) $v);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) $v = $m[3] . '-' . $m[2] . '-' . $m[1];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            throw new CustomException($label . ' : data w formacie RRRR-MM-DD.', 'errors', 422);
        }
        return $v;
    }

    private static function text(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        return $v === '' ? null : $v;
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) return $one;
        return in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many;
    }

    /** Texte du PDF (pdftotext -layout, 40 pages au plus) ; '' si l'outil manque ou échoue. */
    private static function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tfbstm');
        file_put_contents($tmp, $pdf);
        try {
            $proc = @proc_open(['pdftotext', '-layout', '-enc', 'UTF-8', '-l', '40', $tmp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) return '';
            $text = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($proc) === 0 ? mb_substr($text, 0, 500000) : '';
        } finally {
            @unlink($tmp);
        }
    }
}
