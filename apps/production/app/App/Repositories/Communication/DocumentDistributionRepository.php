<?php
namespace App\Repositories\Communication;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Dystrybucja dokumentów (n_doc_distribution) : wiersz = klient × dokument (e-mail na który adres, papier z dostawą,
 * SMS) ; id_customer 0 = ustawienia domyślne, -1 = biuro rachunkowe SL Food. Moment wysłania dokumentu
 * (n_doc_distribution_moment) jest wspólny dla wszystkich klientów.
 */
final class DocumentDistributionRepository extends WorkshopRepository
{
    protected string $table = 'n_doc_distribution';

    /** @return array<int, array<string, array>> id_customer => doc_key => wiersz */
    public function cells(?int $customerId = null): array
    {
        $rows = $customerId === null
            ? $this->all('SELECT * FROM n_doc_distribution')
            : $this->all('SELECT * FROM n_doc_distribution WHERE id_customer IN (?, 0, -1)', [$customerId]);
        $cells = [];
        foreach ($rows as $r) $cells[(int) $r['id_customer']][$r['doc_key']] = $r;
        return $cells;
    }

    /** @return array<string, string> doc_key => moment */
    public function moments(): array
    {
        return array_column($this->all('SELECT doc_key, moment FROM n_doc_distribution_moment'), 'moment', 'doc_key');
    }

    public function saveCell(int $customerId, string $doc, string $mailTo, ?string $address, int $paper, bool $sms, ?string $author): void
    {
        $this->db->prepare('INSERT INTO n_doc_distribution (id_customer, doc_key, mail_to, mail_address, paper_copies, sms, updated_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE mail_to = VALUES(mail_to), mail_address = VALUES(mail_address),
                                paper_copies = VALUES(paper_copies), sms = VALUES(sms), updated_by = VALUES(updated_by)')
            ->execute([$customerId, $doc, $mailTo, $address, $paper, (int) $sms, $author]);
    }

    public function saveMoment(string $doc, string $moment, ?string $author): void
    {
        $this->db->prepare('INSERT INTO n_doc_distribution_moment (doc_key, moment, updated_by) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE moment = VALUES(moment), updated_by = VALUES(updated_by)')
            ->execute([$doc, $moment, $author]);
    }

    /** Aktywni klienci z adresami i telefonem (do tabeli i ostrzeżeń « brak adresu »). */
    public function customers(?int $customerId = null): array
    {
        $where = $customerId === null ? 'c.active = 1' : 'c.id = ?';
        return $this->all(
            "SELECT c.id, c.company, c.communication_lang, c.email_head, c.email_accountant, c.email_salesman, c.phone_head, c.phone_alt,
                    (c.franchise_network_id IS NOT NULL OR c.type = 1) AS franchise
             FROM customer c WHERE {$where} ORDER BY c.company",
            $customerId === null ? [] : [$customerId]
        );
    }

    /** Reguły e-maili sterowane dystrybucją, po zdarzeniu. */
    public function distributionRules(): array
    {
        $rules = [];
        foreach ($this->all("SELECT * FROM n_mail_rule WHERE recipient_kind = 'distribution' ORDER BY sort_order, id") as $r) $rules[$r['event']] = $r;
        return $rules;
    }

    public function setRuleEnabled(int $ruleId, bool $enabled, ?int $startRefId, ?string $author): void
    {
        $sql = $enabled
            ? 'UPDATE n_mail_rule SET enabled = 1, start_ref_id = ?, enabled_at = NOW(), updated_by = ? WHERE id = ? AND enabled = 0'
            : 'UPDATE n_mail_rule SET enabled = 0, updated_by = ? WHERE id = ?';
        $this->db->prepare($sql)->execute($enabled ? [$startRefId, $author, $ruleId] : [$author, $ruleId]);
    }

    /** Wysyłki dnia (Logistyka › Dokumenty dostawy). */
    public function shipmentsOfDay(string $date): array
    {
        return $this->all(
            'SELECT sl.id, sl.id_customer, sl.id_wz, sl.id_cmr, sl.id_wdt, sl.id_diff, sl.shipment_date, sl.insert_timestamp, c.company,
                    wz.doc_num AS wz_number, wdt.doc_num AS wdt_number, ks.status AS ksef_status, COALESCE(ks.resolved_at, ks.updated_at) AS ksef_at,
                    EXISTS (SELECT 1 FROM document_order_shipping_difference_products p WHERE p.id_document_order_shipping_difference = sl.id_diff
                            AND p.invoiced_quantity * p.price_during_invoicing <> p.quantity_ordered * p.price_during_ordering) AS has_difference,
                    EXISTS (SELECT 1 FROM document_order_shipping_difference_products p WHERE p.id_document_order_shipping_difference = sl.id_diff) AS diff_rows
             FROM shipment_log sl
             JOIN customer c ON c.id = sl.id_customer
             LEFT JOIN document_wz wz ON wz.id = sl.id_wz
             LEFT JOIN document_wdt wdt ON wdt.id = sl.id_wdt
             LEFT JOIN ksef_submission ks ON ks.id_document_wdt = sl.id_wdt
             WHERE sl.shipment_date = ?
             ORDER BY c.company, sl.id',
            [$date]
        );
    }

    /** Ostatnie dni z wysyłkami (wybór daty). */
    public function shipmentDays(int $limit = 20): array
    {
        $limit = max(1, min(60, $limit));
        return array_column($this->all("SELECT shipment_date, COUNT(*) AS n FROM shipment_log GROUP BY shipment_date ORDER BY shipment_date DESC LIMIT {$limit}"), 'n', 'shipment_date');
    }

    /** @return array<string, array> ref_key => wpis (wysyłki dla podanych zdarzeń, z wiadomościami) */
    public function dispatchesFor(array $refKeys): array
    {
        if (!$refKeys) return [];
        $likes = implode(' OR ', array_fill(0, count($refKeys), 'd.ref_key = ? OR d.ref_key LIKE ?'));
        $params = [];
        foreach ($refKeys as $key) array_push($params, $key, $key . '>%');
        $rows = $this->all(
            "SELECT d.*, r.code AS rule_code, m.recipient_email, m.status AS message_status, m.provider_message_id
             FROM n_mail_dispatch d JOIN n_mail_rule r ON r.id = d.id_rule
             LEFT JOIN communication_message m ON m.id = d.id_message
             WHERE r.recipient_kind = 'distribution' AND ({$likes}) ORDER BY d.id",
            $params
        );
        $out = [];
        foreach ($rows as $r) $out[$r['ref_key']] = $r;
        return $out;
    }
}
