<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [439] Różnice kursowe : wyciągi (n_bank_statement), wpłaty klientów w walucie (n_bank_statement_line), przypisanie do
 * dokumentów (n_fx_allocation) — seules tables écrites. Lecture des documents de l'ERP, sans second registre :
 *  - faktura = WDT (document_wdt : doc_num, id_customer, currency, invoiced_date sinon established_date — date de la
 *    facture comme dans KSeF P_1 ; montant = lignes document_wdt_product total_net, comme KSeF, jamais l'en-tête ; WDT
 *    remplacée → n_wdt_superseded.superseded_by) ;
 *  - proforma = document_proforma (doc_num, established_date, status) + document_proforma_product.total_price_net, devise
 *    du client (customer.default_currency, EUR par défaut, comme Płatności dnia et Płynność) ;
 *  - proforma → facture : customer_order.id_proforma / id_wdt (commande payée d'avance, facturée à l'expédition) ;
 *  - wpłata proformy enregistrée dans Płatności dnia : n_proforma_payment (paid_on, amount) — affichée, jamais écrite.
 */
final class FxDifferenceRepository extends WorkshopRepository
{
    protected string $table = 'n_bank_statement_line';

    /** Numéro normalisé : lettres et chiffres en majuscules (« FV/2026/11/0088 » → « FV2026110088 »). */
    private const NORM_SQL = "UPPER(REGEXP_REPLACE(%s, '[^0-9A-Za-z]', ''))";

    // --- Wyciągi ----------------------------------------------------------------------------------------------------

    /** Wyciągi du mois : période qui chevauche le mois, ou lignes du mois (sans le contenu). */
    public function statements(string $from, string $to): array
    {
        return $this->all("SELECT s.id, s.bank, s.account_currency, s.period_from, s.period_to, s.filename, s.size_bytes, s.parser, s.parse_status, s.parse_message,
                s.lines_other, s.created_at, (s.content IS NOT NULL) AS has_file,
                (SELECT COUNT(*) FROM n_bank_statement_line l WHERE l.id_statement = s.id AND l.match_state <> 'ignored') AS lines_customers
            FROM n_bank_statement s
            WHERE (s.period_from IS NOT NULL AND s.period_from <= ? AND COALESCE(s.period_to, s.period_from) >= ?)
               OR EXISTS (SELECT 1 FROM n_bank_statement_line l WHERE l.id_statement = s.id AND l.booked_on >= ? AND l.booked_on <= ?)
               OR (s.period_from IS NULL AND s.created_at >= ? AND s.created_at < DATE_ADD(?, INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM n_bank_statement_line l WHERE l.id_statement = s.id))
            ORDER BY s.account_currency, COALESCE(s.period_from, DATE(s.created_at)), s.id", [$to, $from, $from, $to, $from, $to]);
    }

    /** Wyciągi do wyboru w formularzu (ostatnie). */
    public function recentStatements(int $limit = 30): array
    {
        return $this->all('SELECT id, account_currency, period_from, period_to, filename, parse_status FROM n_bank_statement ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    public function statement(int $id): ?array
    {
        return $this->all('SELECT * FROM n_bank_statement WHERE id = ?', [$id])[0] ?? null;
    }

    public function statementBySha(string $sha): ?array
    {
        return $this->all('SELECT id, account_currency, period_from, period_to FROM n_bank_statement WHERE content_sha256 = ?', [$sha])[0] ?? null;
    }

    public function insertStatement(array $f): int
    {
        return $this->insertRow('n_bank_statement', $f);
    }

    public function updateStatement(int $id, array $f): void
    {
        $this->updateRow('n_bank_statement', $id, $f);
    }

    /** Wyciąg usunięty avec ses lignes et leurs przypisania. */
    public function deleteStatement(int $id): void
    {
        $this->db->prepare('DELETE a FROM n_fx_allocation a JOIN n_bank_statement_line l ON l.id = a.id_line WHERE l.id_statement = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM n_bank_statement_line WHERE id_statement = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM n_bank_statement WHERE id = ?')->execute([$id]);
    }

    // --- Wpłaty -----------------------------------------------------------------------------------------------------

    /**
     * Wpłaty à examiner pour le mois : comptabilisées dans le mois, ou plus tôt (jusqu'à $since) — une przedpłata peut être
     * rozliczona dans le mois de la facture, une wpłata non réglée reste à expliquer.
     */
    public function lines(string $since, string $to): array
    {
        return $this->all("SELECT l.*, c.company AS customer_name, COALESCE(NULLIF(c.default_currency, ''), 'EUR') AS customer_currency
            FROM n_bank_statement_line l LEFT JOIN customer c ON c.id = l.id_customer
            WHERE l.booked_on >= ? AND l.booked_on <= ? ORDER BY l.booked_on, l.id", [$since, $to]);
    }

    public function line(int $id): ?array
    {
        return $this->all("SELECT l.*, c.company AS customer_name FROM n_bank_statement_line l LEFT JOIN customer c ON c.id = l.id_customer WHERE l.id = ?", [$id])[0] ?? null;
    }

    public function insertLine(array $f): int
    {
        return $this->insertRow('n_bank_statement_line', $f);
    }

    public function updateLine(int $id, array $f): void
    {
        $this->updateRow('n_bank_statement_line', $id, $f);
    }

    public function deleteLine(int $id): void
    {
        $this->db->prepare('DELETE FROM n_fx_allocation WHERE id_line = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM n_bank_statement_line WHERE id = ?')->execute([$id]);
    }

    // --- Przypisania ------------------------------------------------------------------------------------------------

    /** @param list<int> $lineIds @return array<int, list<array>> ligne → przypisania */
    public function allocations(array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if (!$lineIds) return [];
        $out = [];
        foreach ($this->all('SELECT id, id_line, doc_kind, id_doc, amount, source FROM n_fx_allocation WHERE id_line IN (' . implode(',', array_fill(0, count($lineIds), '?')) . ') ORDER BY id', $lineIds) as $a) {
            $out[(int) $a['id_line']][] = $a;
        }
        return $out;
    }

    /** @param list<array{doc_kind: string, id_doc: int, amount: float, source: string}> $rows */
    public function replaceAllocations(int $lineId, array $rows, ?int $userId): void
    {
        $this->db->prepare('DELETE FROM n_fx_allocation WHERE id_line = ?')->execute([$lineId]);
        $st = $this->db->prepare('INSERT INTO n_fx_allocation (id_line, doc_kind, id_doc, amount, source, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) $st->execute([$lineId, $r['doc_kind'], (int) $r['id_doc'], round((float) $r['amount'], 2), $r['source'], $userId]);
    }

    /**
     * Sommes déjà przypisane aux documents par d'autres wpłaty.
     * @return array<string, float> « wdt:12 » / « proforma:5 » → montant
     */
    public function allocatedTo(array $wdtIds, array $proformaIds, ?int $exceptLine): array
    {
        $out = [];
        foreach (['wdt' => $wdtIds, 'proforma' => $proformaIds] as $kind => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!$ids) continue;
            $rows = $this->all("SELECT a.id_doc, SUM(a.amount) AS s FROM n_fx_allocation a JOIN n_bank_statement_line l ON l.id = a.id_line
                WHERE a.doc_kind = ? AND l.match_state <> 'ignored' AND a.id_line <> ? AND a.id_doc IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY a.id_doc',
                [$kind, $exceptLine ?? 0, ...$ids]);
            foreach ($rows as $r) $out[$kind . ':' . (int) $r['id_doc']] = (float) $r['s'];
        }
        return $out;
    }

    // --- Dokumenty ERP ----------------------------------------------------------------------------------------------

    private static function wdtSelect(): string
    {
        return "SELECT w.id, w.doc_num, w.id_customer, COALESCE(w.invoiced_date, w.established_date) AS doc_date,
                UPPER(COALESCE(NULLIF(TRIM(w.currency), ''), 'EUR')) AS currency,
                (SELECT ROUND(SUM(ROUND(COALESCE(p.total_net, p.quantity * p.price_carton), 2)), 2) FROM document_wdt_product p WHERE p.id_document_wdt = w.id) AS total,
                (SELECT s.superseded_by FROM n_wdt_superseded s WHERE s.id_document_wdt = w.id) AS superseded_by, c.company AS customer_name
            FROM document_wdt w LEFT JOIN customer c ON c.id = w.id_customer";
    }

    private static function proformaSelect(): string
    {
        return "SELECT p.id, p.doc_num, p.id_customer, p.established_date AS doc_date, p.status,
                UPPER(COALESCE(NULLIF(TRIM(c.default_currency), ''), 'EUR')) AS currency,
                (SELECT ROUND(SUM(pp.total_price_net), 2) FROM document_proforma_product pp WHERE pp.id_proforma = p.id) AS total,
                (SELECT pd.paid_on FROM n_proforma_payment pd WHERE pd.id_proforma = p.id) AS pd_paid_on,
                (SELECT pd.amount FROM n_proforma_payment pd WHERE pd.id_proforma = p.id) AS pd_amount, c.company AS customer_name
            FROM document_proforma p LEFT JOIN customer c ON c.id = p.id_customer";
    }

    /** @return array<int, array> id → WDT */
    public function wdts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        return array_column($this->all(self::wdtSelect() . ' WHERE w.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), null, 'id');
    }

    /** @return array<int, array> id → proforma */
    public function proformas(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        return array_column($this->all(self::proformaSelect() . ' WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), null, 'id');
    }

    /** Proforma → WDT (commandes : customer_order.id_proforma / id_wdt). @return array<int, list<int>> */
    public function proformaWdts(array $proformaIds): array
    {
        $proformaIds = array_values(array_unique(array_filter(array_map('intval', $proformaIds))));
        if (!$proformaIds) return [];
        $out = [];
        foreach ($this->all('SELECT DISTINCT id_proforma, id_wdt FROM customer_order WHERE id_wdt IS NOT NULL AND id_proforma IN ('
            . implode(',', array_fill(0, count($proformaIds), '?')) . ') ORDER BY id_proforma, id_wdt', $proformaIds) as $r) {
            $out[(int) $r['id_proforma']][] = (int) $r['id_wdt'];
        }
        return $out;
    }

    /** WDT → proformas liées (pour le reste à payer d'une facture). @return array<int, list<int>> */
    public function wdtProformas(array $wdtIds): array
    {
        $wdtIds = array_values(array_unique(array_filter(array_map('intval', $wdtIds))));
        if (!$wdtIds) return [];
        $out = [];
        foreach ($this->all('SELECT DISTINCT id_wdt, id_proforma FROM customer_order WHERE id_proforma IS NOT NULL AND id_wdt IN ('
            . implode(',', array_fill(0, count($wdtIds), '?')) . ')', $wdtIds) as $r) {
            $out[(int) $r['id_wdt']][] = (int) $r['id_proforma'];
        }
        return $out;
    }

    /**
     * Documents dont le numéro normalisé (6 caractères au moins) figure dans le titre normalisé — WDT puis proformas
     * (annulées et archivées exclues), depuis $since. Le contrôle des bords (chiffre collé) se fait dans le service.
     * @return array{0: list<array>, 1: list<array>} [WDT, proformas]
     */
    public function docsInTitle(string $normTitle, string $since): array
    {
        if (strlen($normTitle) < 6) return [[], []];
        $wNorm = sprintf(self::NORM_SQL, 'w.doc_num');
        $pNorm = sprintf(self::NORM_SQL, 'p.doc_num');
        $w = $this->all(self::wdtSelect() . " WHERE LENGTH({$wNorm}) >= 6 AND INSTR(?, {$wNorm}) > 0 AND COALESCE(w.invoiced_date, w.established_date) >= ?", [$normTitle, $since]);
        $p = $this->all(self::proformaSelect() . " WHERE LENGTH({$pNorm}) >= 6 AND INSTR(?, {$pNorm}) > 0 AND p.established_date >= ? AND COALESCE(p.status, '') NOT IN ('cancelled', 'archived')", [$normTitle, $since]);
        return [array_map(fn($r) => ['kind' => 'wdt'] + $r, $w), array_map(fn($r) => ['kind' => 'proforma'] + $r, $p)];
    }

    /** Documents du client depuis $since (WDT, puis proformas non annulées). @return array{0: list<array>, 1: list<array>} */
    public function customerDocs(int $customerId, string $since): array
    {
        $w = $this->all(self::wdtSelect() . ' WHERE w.id_customer = ? AND COALESCE(w.invoiced_date, w.established_date) >= ? ORDER BY doc_date, w.id', [$customerId, $since]);
        $p = $this->all(self::proformaSelect() . " WHERE p.id_customer = ? AND p.established_date >= ? AND COALESCE(p.status, '') NOT IN ('cancelled', 'archived') ORDER BY doc_date, p.id", [$customerId, $since]);
        return [array_map(fn($r) => ['kind' => 'wdt'] + $r, $w), array_map(fn($r) => ['kind' => 'proforma'] + $r, $p)];
    }

    // --- Klienci ----------------------------------------------------------------------------------------------------

    /** Klienci (nom affiché comme les autres pages Finanse, devise) : liste du formulaire, payeur → klient. */
    public function customers(): array
    {
        return $this->all("SELECT id, COALESCE(NULLIF(TRIM(company), ''), CONCAT('Klient #', id)) AS name, COALESCE(NULLIF(default_currency, ''), 'EUR') AS currency
            FROM customer ORDER BY name LIMIT 3000");
    }

    public function customer(int $id): ?array
    {
        return $this->all("SELECT id, COALESCE(NULLIF(TRIM(company), ''), CONCAT('Klient #', id)) AS name, COALESCE(NULLIF(default_currency, ''), 'EUR') AS currency FROM customer WHERE id = ?", [$id])[0] ?? null;
    }

    // --- pomocnicze -------------------------------------------------------------------------------------------------

    private function insertRow(string $table, array $f): int
    {
        $cols = array_keys($f);
        $this->db->prepare("INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($f));
        return (int) $this->db->lastInsertId();
    }

    private function updateRow(string $table, int $id, array $f): void
    {
        if (!$f) return;
        $this->db->prepare("UPDATE {$table} SET " . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
    }
}
