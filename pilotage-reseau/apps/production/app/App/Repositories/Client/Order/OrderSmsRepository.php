<?php
declare(strict_types=1);

namespace App\Repositories\Client\Order;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [372] SMS de commande aux clients (assistants « Zamówienia dnia » et « Klienci bez zamówienia ») : lecture du client,
 * de la commande et du dernier SMS envoyé. L'écriture du SMS passe par la passerelle (n_sms_log), jamais ici.
 */
final class OrderSmsRepository extends WorkshopRepository
{
    /** Commande avec contact et langue du client, cartons (null = pas de commande). */
    public function order(int $orderId): ?array
    {
        return $this->all("SELECT co.id, co.reference, DATE(co.expected_shipping_date) AS shipping_date, c.id AS id_customer, c.company,
                c.phone_head, c.phone_alt, COALESCE(NULLIF(c.communication_lang, ''), 'fr') AS language,
                (SELECT COALESCE(SUM(p.quantity_carton), 0) FROM customer_order_product p WHERE p.id_order = co.id) AS cartons
            FROM customer_order co JOIN customer c ON c.id = co.id_customer WHERE co.id = ?", [$orderId])[0] ?? null;
    }

    /** Client avec contact et langue (null = pas de client). */
    public function customer(int $customerId): ?array
    {
        return $this->all("SELECT c.id AS id_customer, c.company, c.phone_head, c.phone_alt, COALESCE(NULLIF(c.communication_lang, ''), 'fr') AS language
            FROM customer c WHERE c.id = ?", [$customerId])[0] ?? null;
    }

    public function companyName(): string
    {
        return (string) ($this->all('SELECT proforma_company_name FROM erp_company_data WHERE id = 1')[0]['proforma_company_name'] ?? '');
    }

    /**
     * Dernier SMS par référence pour des contextes donnés, depuis une date.
     * @param list<string> $contexts
     * @param list<int> $refs
     * @return array<int, array{context:string, status:string, created_at:string, created_by:?string}>
     */
    public function last(array $contexts, array $refs, string $since): array
    {
        $refs = array_values(array_unique(array_map('intval', $refs)));
        if (!$contexts || !$refs) return [];
        $in = fn(array $a) => implode(',', array_fill(0, count($a), '?'));
        $rows = $this->all('SELECT l.ref_id, l.context, l.status, l.created_at, l.created_by FROM n_sms_log l
            WHERE l.context IN (' . $in($contexts) . ') AND l.ref_id IN (' . $in($refs) . ') AND l.created_at >= ? ORDER BY l.created_at, l.id',
            [...$contexts, ...$refs, $since]);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['ref_id']] = ['context' => (string) $r['context'], 'status' => (string) $r['status'], 'created_at' => (string) $r['created_at'],
            'created_by' => $r['created_by'] !== null ? (string) $r['created_by'] : null];
        return $out;
    }
}
