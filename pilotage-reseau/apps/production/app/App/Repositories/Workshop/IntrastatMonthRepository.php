<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Intrastat miesiąca » [398] : lecture
 *  - des WDT d'une période (document_wdt : consignor_nip / consignor_name = vendeur, consignee_nip = VAT UE du destinataire,
 *    currency, shipping_date sinon established_date ; non remplacées — n_wdt_superseded) et de leurs lignes
 *    (document_wdt_product : quantity en cartons, price_carton, weight_carton_total en kg) avec la fiche produit
 *    (final_product : weight en g / pièce, ppc) — valeur = lignes, jamais le total de l'en-tête (comme WdtSalesRepository) ;
 *  - des factures d'achat d'une période (n_inv : issue_date, currency, seller_nip / seller_name, sinon supplier.nip / name) et
 *    de leurs lignes (n_inv_line : quantity × factor = quantité en unité de base du surowiec, net_value, id_raw_material ;
 *    raw_material.id_unit → unit.name) ;
 *  - de la société de l'ERP (erp_company_data id 1 : wdt_company_nip, wdt_company_name — une seule dans l'ERP).
 * Écrit seulement ses tables : n_intrastat_code (codes CN, masse par unité), n_intrastat_rate (kursy), n_intrastat_review.
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »).
 */
final class IntrastatMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_intrastat_review';

    /** @var array<string, bool> */
    private array $tables = [];
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $this->columns[$table] ??= array_column($this->all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'c');
        return in_array($column, $this->columns[$table], true);
    }

    /** Lignes des WDT dont la date de mouvement est du $from au $to inclus (null : tables absentes). */
    public function wdtLines(string $from, string $to): ?array
    {
        if (!$this->hasTable('document_wdt') || !$this->hasTable('document_wdt_product')) return null;
        $date = $this->hasColumn('document_wdt', 'shipping_date') ? 'COALESCE(w.shipping_date, w.established_date)' : 'w.established_date';
        $weight = $this->hasColumn('document_wdt_product', 'weight_carton_total') ? 'p.weight_carton_total' : 'NULL';
        $fp = $this->hasTable('final_product');
        $superseded = $this->hasTable('n_wdt_superseded') ? 'AND NOT EXISTS (SELECT 1 FROM n_wdt_superseded sup WHERE sup.id_document_wdt = w.id)' : '';
        return $this->all("SELECT w.id AS id_wdt, w.doc_num, w.id_customer, {$date} AS move_date, UPPER(COALESCE(NULLIF(TRIM(w.currency), ''), 'EUR')) AS currency,
                w.consignor_nip, w.consignor_name, w.consignee_nip, w.consignee_name,
                p.id AS id_line, p.id_product, p.name AS line_name, p.quantity, p.price_carton, {$weight} AS weight_total_kg,
                " . ($fp ? 'fp.name' : 'NULL') . " AS product_name, " . ($fp ? 'fp.weight' : 'NULL') . " AS product_weight_g, " . ($fp ? 'fp.ppc' : 'NULL') . " AS product_ppc
            FROM document_wdt w
            JOIN document_wdt_product p ON p.id_document_wdt = w.id
            " . ($fp ? 'LEFT JOIN final_product fp ON fp.id = p.id_product' : '') . "
            WHERE {$date} >= ? AND {$date} <= ? {$superseded}
            ORDER BY move_date, w.id, p.id", [$from, $to]);
    }

    /** Lignes des factures d'achat émises du $from au $to inclus (null : tables absentes). */
    public function invoiceLines(string $from, string $to): ?array
    {
        if (!$this->hasTable('n_inv') || !$this->hasTable('n_inv_line')) return null;
        $sup = $this->hasTable('supplier');
        $supNip = $sup && $this->hasColumn('supplier', 'nip') ? 's.nip' : 'NULL';
        $rm = $this->hasTable('raw_material');
        $unit = $rm && $this->hasTable('unit');
        return $this->all("SELECT i.id AS id_inv, i.number, i.issue_date, UPPER(COALESCE(NULLIF(TRIM(i.currency), ''), 'PLN')) AS currency, i.id_supplier,
                COALESCE(NULLIF(TRIM(i.seller_nip), ''), {$supNip}) AS seller_vat, COALESCE(NULLIF(TRIM(i.seller_name), ''), " . ($sup ? 's.name' : 'NULL') . ") AS seller_name,
                l.id AS id_line, l.line_no, l.name AS line_name, l.quantity, l.unit, l.unit_price, l.net_value, l.id_raw_material, l.factor,
                " . ($rm ? 'rm.name' : 'NULL') . " AS material_name, " . ($unit ? 'u.name' : 'NULL') . " AS base_unit
            FROM n_inv i
            JOIN n_inv_line l ON l.id_inv = i.id
            " . ($sup ? 'LEFT JOIN supplier s ON s.id = i.id_supplier' : '') . "
            " . ($rm ? 'LEFT JOIN raw_material rm ON rm.id = l.id_raw_material' : '') . "
            " . ($unit ? 'LEFT JOIN unit u ON u.id = rm.id_unit' : '') . "
            WHERE i.issue_date >= ? AND i.issue_date <= ?
            ORDER BY i.issue_date, i.id, l.line_no, l.id", [$from, $to]);
    }

    /** Société de l'ERP (vendeur des WDT par défaut, acheteur des factures d'achat) ; null si non configurée. */
    public function erpCompany(): ?array
    {
        if (!$this->hasTable('erp_company_data') || !$this->hasColumn('erp_company_data', 'wdt_company_nip')) return null;
        $r = $this->all('SELECT wdt_company_nip AS nip, wdt_company_name AS name FROM erp_company_data ORDER BY id LIMIT 1')[0] ?? null;
        return $r && trim((string) $r['nip']) !== '' ? ['nip' => (string) $r['nip'], 'name' => trim((string) $r['name'])] : null;
    }

    /** @return array<int, array{name:string, tax_number:?string}> customer.id → nom (comme les autres assistants) et NIP / VAT de la fiche */
    public function customers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->hasTable('customer')) return [];
        $tax = $this->hasColumn('customer', 'tax_number') ? 'tax_number' : 'NULL';
        $out = [];
        foreach ($this->all("SELECT id, COALESCE(NULLIF(TRIM(company), ''), TRIM(CONCAT(COALESCE(name, ''), ' ', COALESCE(surname, '')))) AS n, {$tax} AS tax_number FROM customer WHERE id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) $out[(int) $r['id']] = ['name' => (string) $r['n'], 'tax_number' => $r['tax_number'] !== null ? (string) $r['tax_number'] : null];
        return $out;
    }

    /** @return array<string, array<int, array{cn_code:?string, unit_mass_kg:?float}>> rodzaj → id → kod CN / masa jednostki */
    public function codes(): array
    {
        $out = ['product' => [], 'material' => []];
        foreach ($this->all('SELECT item_kind, item_id, cn_code, unit_mass_kg FROM n_intrastat_code') as $r) {
            $out[(string) $r['item_kind']][(int) $r['item_id']] = ['cn_code' => $r['cn_code'] !== null && $r['cn_code'] !== '' ? (string) $r['cn_code'] : null,
                'unit_mass_kg' => $r['unit_mass_kg'] !== null ? (float) $r['unit_mass_kg'] : null];
        }
        return $out;
    }

    public function saveCode(string $kind, int $id, ?string $cn, ?float $unitMass, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_intrastat_code (item_kind, item_id, cn_code, unit_mass_kg, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE cn_code = VALUES(cn_code), unit_mass_kg = VALUES(unit_mass_kg), updated_by = VALUES(updated_by), updated_at = NOW()')
            ->execute([$kind, $id, $cn, $unitMass, $userId]);
    }

    /** @return array<string, array<string, float>> mois (YYYY-MM) → devise → kurs, du $from au $to (premiers jours) */
    public function rates(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT month_start, currency, rate FROM n_intrastat_rate WHERE month_start >= ? AND month_start <= ?', [$from, $to]) as $r) {
            $out[substr((string) $r['month_start'], 0, 7)][strtoupper((string) $r['currency'])] = (float) $r['rate'];
        }
        return $out;
    }

    public function saveRate(string $monthStart, string $currency, float $rate, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_intrastat_rate (month_start, currency, rate, updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rate = VALUES(rate), updated_by = VALUES(updated_by), updated_at = NOW()')->execute([$monthStart, $currency, $rate, $userId]);
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_intrastat_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<string> éléments décidés pour le mois (restent à l'écran même sans signal). */
    public function decidedIn(string $monthStart): array
    {
        return array_column($this->all('SELECT item_key FROM n_intrastat_review WHERE month_start = ?', [$monthStart]), 'item_key');
    }

    /** Une décision par (mois, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, string $kind, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_intrastat_review (month_start, item_key, kind, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $kind, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
