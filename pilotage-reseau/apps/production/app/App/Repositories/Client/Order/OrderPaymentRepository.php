<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Repositories\Workshop\WorkshopRepository;

/** [312] Zamówienia klientów : płatność proformy zamówienia (przełącznik) i przypomnienia e-mail / SMS. */
final class OrderPaymentRepository extends WorkshopRepository
{
    /** Zamówienie z proformą, kwotą i kontaktem klienta (null = brak zamówienia). */
    public function order(int $orderId): ?array
    {
        return $this->all("SELECT co.id, co.id_proforma, co.is_freebie, doc.doc_num, LOWER(TRIM(doc.status)) AS proforma_status,
                c.id AS id_customer, c.company, c.phone_head, c.phone_alt, COALESCE(NULLIF(c.communication_lang, ''), 'fr') AS language,
                COALESCE(NULLIF(c.default_currency, ''), 'EUR') AS currency,
                (SELECT ROUND(SUM(p.total_price_net), 2) FROM document_proforma_product p WHERE p.id_proforma = co.id_proforma) AS proforma_amount
            FROM customer_order co JOIN customer c ON c.id = co.id_customer LEFT JOIN document_proforma doc ON doc.id = co.id_proforma
            WHERE co.id = ?", [$orderId])[0] ?? null;
    }

    public function setProformaStatus(int $proformaId, string $status): void
    {
        $this->db->prepare('UPDATE document_proforma SET status = ? WHERE id = ?')->execute([$status, $proformaId]);
    }

    public function companyName(): string
    {
        return (string) ($this->all('SELECT proforma_company_name FROM erp_company_data WHERE id = 1')[0]['proforma_company_name'] ?? '');
    }

    public function logSms(array $row): void
    {
        $this->db->prepare('INSERT INTO n_proforma_sms (id_proforma, id_order, recipient, language, body, status, provider, error, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$row['id_proforma'], $row['id_order'], $row['recipient'], $row['language'], $row['body'], $row['status'], $row['provider'], $row['error'], $row['created_by']]);
    }

    /** Ostatnie przypomnienie (e-mail albo SMS) proform listy : id_proforma => ['at', 'channel']. */
    public function lastReminders(array $proformaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $proformaIds))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_proforma AS id, MAX(created_at) AS at FROM communication_message
                WHERE message_type LIKE 'proforma%reminder%' AND status NOT IN ('draft', 'failed') AND id_proforma IN ($in) GROUP BY id_proforma", $ids) as $r) {
            $out[(int) $r['id']] = ['at' => $r['at'], 'channel' => 'email'];
        }
        foreach ($this->all("SELECT id_proforma AS id, MAX(created_at) AS at FROM n_proforma_sms WHERE status <> 'failed' AND id_proforma IN ($in) GROUP BY id_proforma", $ids) as $r) {
            if (!isset($out[(int) $r['id']]) || $r['at'] > $out[(int) $r['id']]['at']) $out[(int) $r['id']] = ['at' => $r['at'], 'channel' => 'sms'];
        }
        return $out;
    }
}
