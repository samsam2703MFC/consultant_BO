<?php
namespace App\Services\Communication;

use App\Domain\Client\Order\OpenOrders;

use App\Exceptions\CustomException;
use PDO;

/**
 * Événements qui déclenchent les e-mails automatiques, lus dans les tables de l'ERP
 * (rien n'est modifié dans les processus existants) : commande reçue, livraison prévue,
 * expédition partie, WDT / facture / proforma émise. Pour chacun : la liste des cas à
 * traiter et le contexte du message (variables du modèle, adresses du client, pièce jointe).
 */
final class MailEventSource
{
    /** Table de référence et préfixe de clé de chaque événement (null : événement daté). */
    private const SOURCES = [
        'order_received' => ['customer_order', 'order'],
        'shipment_sent' => ['shipment_log', 'shipment'],
        'wdt_issued' => ['document_wdt', 'wdt'],
        'invoice_issued' => ['document_invoice', 'invoice'],
        'proforma_issued' => ['document_proforma', 'proforma'],
        'delivery_planned' => [null, 'delivery'],
    ];

    public function __construct(
        private PDO $db,
        private DocumentEmailContextService $documents,
        private ProformaEmailContextService $proformas,
    ) {
    }

    /** Dernier identifiant existant : une règle activée ne traite que ce qui vient après. */
    public function lastRefId(string $event): ?int
    {
        $table = self::SOURCES[$event][0] ?? null;
        if ($table === null) return null;
        return (int) $this->db->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
    }

    /** @return list<array{key:string,ref:array}> cas non encore traités par la règle */
    public function candidates(array $rule, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        [$table, $prefix] = self::SOURCES[$rule['event']];
        $notDone = "NOT EXISTS (SELECT 1 FROM tfb_mail_dispatch d WHERE d.id_rule = :rule AND d.ref_key = CONCAT('{$prefix}:', %s))";

        if ($rule['event'] === 'delivery_planned') {
            $sql = "SELECT co.id_customer, co.expected_shipping_date AS day
                    FROM customer_order co
                    WHERE " . OpenOrders::sql('co') . "
                      AND co.expected_shipping_date BETWEEN CURDATE() AND CURDATE() + INTERVAL :days DAY
                      AND " . sprintf($notDone, "co.id_customer, ':', co.expected_shipping_date") . "
                    GROUP BY co.id_customer, co.expected_shipping_date
                    ORDER BY co.expected_shipping_date, co.id_customer
                    LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['rule' => $rule['id'], 'days' => (int) $rule['days_before']]);
            return array_map(fn($r) => ['key' => "delivery:{$r['id_customer']}:{$r['day']}", 'ref' => $r], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $extra = $rule['event'] === 'proforma_issued' ? " AND t.status <> 'cancelled'" : '';
        $sql = "SELECT t.id FROM {$table} t
                WHERE t.id > :start{$extra} AND " . sprintf($notDone, 't.id') . "
                ORDER BY t.id LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['rule' => $rule['id'], 'start' => (int) ($rule['start_ref_id'] ?? PHP_INT_MAX)]);
        return array_map(fn($r) => ['key' => "{$prefix}:{$r['id']}", 'ref' => ['id' => (int) $r['id']]], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Le cas le plus récent, déjà traité ou non : sert à l'aperçu d'une règle. */
    public function latest(string $event): ?array
    {
        [$table, $prefix] = self::SOURCES[$event];
        if ($event === 'delivery_planned') {
            $row = $this->db->query("SELECT id_customer, expected_shipping_date AS day FROM customer_order
                                     WHERE " . OpenOrders::sql('customer_order') . " ORDER BY expected_shipping_date >= CURDATE() DESC, expected_shipping_date, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $row ? ['key' => "delivery:{$row['id_customer']}:{$row['day']}", 'ref' => $row] : null;
        }
        $id = $this->db->query("SELECT MAX(id) FROM {$table}" . ($event === 'proforma_issued' ? " WHERE status <> 'cancelled'" : ''))->fetchColumn();
        return $id ? ['key' => "{$prefix}:{$id}", 'ref' => ['id' => (int) $id]] : null;
    }

    /**
     * @return array{id_customer:int, emails:array{head:string,accountant:string,salesman:string},
     *               variables:array, attachment:?array{source:string,id:int}, links:array}
     */
    public function context(string $event, array $ref): array
    {
        return match ($event) {
            'order_received' => $this->orderContext((int) $ref['id']),
            'delivery_planned' => $this->deliveryContext((int) $ref['id_customer'], (string) $ref['day']),
            'shipment_sent' => $this->shipmentContext((int) $ref['id']),
            'wdt_issued' => $this->documentContext('wdt', $this->documents->forWdt((int) $ref['id']), 'Faktura WDT'),
            'invoice_issued' => $this->documentContext('invoice', $this->documents->forInvoice((int) $ref['id']), 'Faktura'),
            'proforma_issued' => $this->documentContext('proforma', $this->proformas->forProforma((int) $ref['id']), 'Proforma'),
            default => throw new CustomException('Nieznane zdarzenie: ' . $event, 'errors', 422),
        };
    }

    private function orderContext(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT co.*, (SELECT COALESCE(SUM(quantity_carton), 0) FROM customer_order_product WHERE id_order = co.id) AS cartons
                                    FROM customer_order co WHERE co.id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new CustomException('Nie znaleziono zamówienia.', 'errors', 404);
        $client = $this->client((int) $order['id_customer']);
        return [
            'id_customer' => (int) $order['id_customer'],
            'emails' => $this->emails($client),
            'variables' => [
                'client' => $this->clientVariables($client),
                'order' => [
                    'number' => '#' . $order['id'],
                    'reference' => (string) ($order['reference'] ?: '#' . $order['id']),
                    'delivery_date' => self::date($order['expected_shipping_date']),
                    'cartons' => (string) (int) $order['cartons'],
                ],
                'company' => ['name' => $this->companyName()],
                'today' => ['date' => date('d/m/Y')],
            ],
            'attachment' => $order['id_proforma'] ? ['source' => 'proforma', 'id' => (int) $order['id_proforma']] : null,
            'links' => ['id_proforma' => $order['id_proforma'] ? (int) $order['id_proforma'] : null],
        ];
    }

    private function deliveryContext(int $customerId, string $day): array
    {
        $stmt = $this->db->prepare('SELECT co.id, (SELECT COALESCE(SUM(quantity_carton), 0) FROM customer_order_product WHERE id_order = co.id) AS cartons
                                    FROM customer_order co WHERE co.id_customer = ? AND co.expected_shipping_date = ? AND ' . OpenOrders::sql('co') . ' ORDER BY co.id');
        $stmt->execute([$customerId, $day]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$orders) throw new CustomException('Brak zamówień na ten dzień.', 'errors', 404);
        $client = $this->client($customerId);
        return [
            'id_customer' => $customerId,
            'emails' => $this->emails($client),
            'variables' => [
                'client' => $this->clientVariables($client),
                'delivery' => [
                    'date' => self::date($day),
                    'orders' => implode(', ', array_map(fn($o) => '#' . $o['id'], $orders)),
                    'cartons' => (string) array_sum(array_map(fn($o) => (int) $o['cartons'], $orders)),
                ],
                'company' => ['name' => $this->companyName()],
                'today' => ['date' => date('d/m/Y')],
            ],
            'attachment' => null,
            'links' => [],
        ];
    }

    private function shipmentContext(int $shipmentId): array
    {
        $stmt = $this->db->prepare('SELECT sl.*, wz.doc_num AS wz_number, wdt.doc_num AS wdt_number
                                    FROM shipment_log sl
                                    LEFT JOIN document_wz wz ON wz.id = sl.id_wz
                                    LEFT JOIN document_wdt wdt ON wdt.id = sl.id_wdt
                                    WHERE sl.id = ?');
        $stmt->execute([$shipmentId]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new CustomException('Nie znaleziono wysyłki.', 'errors', 404);
        $client = $this->client((int) $shipment['id_customer']);
        return [
            'id_customer' => (int) $shipment['id_customer'],
            'emails' => $this->emails($client),
            'variables' => [
                'client' => $this->clientVariables($client),
                'shipment' => [
                    'date' => self::date($shipment['shipment_date']),
                    'wz_number' => (string) ($shipment['wz_number'] ?? ''),
                    'wdt_number' => (string) ($shipment['wdt_number'] ?? ''),
                ],
                'company' => ['name' => $this->companyName()],
                'today' => ['date' => date('d/m/Y')],
            ],
            'attachment' => $shipment['id_wz'] ? ['source' => 'wz', 'id' => (int) $shipment['id_wz']] : null,
            'links' => ['id_wdt' => $shipment['id_wdt'] ? (int) $shipment['id_wdt'] : null],
        ];
    }

    private function documentContext(string $source, array $context, string $label): array
    {
        $client = $this->client((int) $context['id_customer']);
        $variables = $context['variables'];
        $variables['document']['label'] = $label;
        $variables['client'] = array_merge($this->clientVariables($client), $variables['client'] ?? []);
        if (($variables['company']['name'] ?? '') === '') $variables['company']['name'] = $this->companyName();
        return [
            'id_customer' => (int) $context['id_customer'],
            'emails' => $this->emails($client),
            'variables' => $variables,
            'attachment' => ['source' => $source, 'id' => (int) $context['document']['id']],
            'links' => [
                'id_wdt' => $context['id_wdt'] ?? null,
                'id_invoice' => $context['id_invoice'] ?? null,
                'id_proforma' => $context['id_proforma'] ?? null,
            ],
        ];
    }

    private function client(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, company, name, surname, email_head, email_accountant, email_salesman FROM customer WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
    }

    private function emails(array $client): array
    {
        $clean = fn($v) => filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL) ? trim((string) $v) : '';
        return ['head' => $clean($client['email_head'] ?? ''), 'accountant' => $clean($client['email_accountant'] ?? ''), 'salesman' => $clean($client['email_salesman'] ?? '')];
    }

    private function clientVariables(array $client): array
    {
        return ['company' => (string) $client['company'], 'contact_name' => trim(($client['name'] ?? '') . ' ' . ($client['surname'] ?? ''))];
    }

    private function companyName(): string
    {
        static $name = null;
        return $name ??= (string) ($this->db->query('SELECT wdt_company_name FROM erp_company_data ORDER BY id LIMIT 1')->fetchColumn() ?: '');
    }

    private static function date(?string $value): string
    {
        $t = strtotime((string) $value);
        return $t ? date('d/m/Y', $t) : (string) $value;
    }
}
