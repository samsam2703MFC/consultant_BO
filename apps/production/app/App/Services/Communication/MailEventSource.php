<?php
namespace App\Services\Communication;

use App\Domain\Client\Order\OpenOrders;

use App\Exceptions\CustomException;
use PDO;

/**
 * Événements qui déclenchent les e-mails automatiques, lus dans les tables de l'ERP
 * (rien n'est modifié dans les processus existants) : commande reçue, livraison prévue,
 * expédition partie, WDT / facture / proforma émise, WDT acceptée dans KSeF, écarts de
 * livraison, changement du tarif du client, début du mois. Pour chacun : la liste des cas à
 * traiter et le contexte du message (variables du modèle, adresses du client, pièce jointe,
 * « docs » = documents de la Dystrybucja dokumentów disponibles pour ce cas).
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
        'difference_issued' => ['document_order_shipping_difference', 'diff'],
        'delivery_planned' => [null, 'delivery'],
        'wdt_ksef_accepted' => [null, 'wdtksef'],
        'price_list_changed' => [null, 'price'],
        'month_start' => [null, 'month'],
    ];
    /** Événements sans point de départ (start_ref_id) : datés, ou détectés depuis l'activation de la règle. */
    public const DATED = ['delivery_planned', 'wdt_ksef_accepted', 'price_list_changed', 'month_start'];
    /** Commande sans proforma : on attend sa création avant d'envoyer (au plus ce délai). */
    private const PROFORMA_WAIT_HOURS = 48;

    public function __construct(
        private PDO $db,
        private DocumentEmailContextService $documents,
        private ProformaEmailContextService $proformas,
        private PaymentReminderContextService $differences,
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
        $notDone = "NOT EXISTS (SELECT 1 FROM n_mail_dispatch d WHERE d.id_rule = :rule AND d.ref_key = CONCAT('{$prefix}:', %s))";

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

        if ($rule['event'] === 'wdt_ksef_accepted') {
            $stmt = $this->db->prepare("SELECT s.id_document_wdt AS id FROM ksef_submission s
                    WHERE s.status = 'accepted' AND COALESCE(s.resolved_at, s.updated_at) >= :since
                      AND " . sprintf($notDone, 's.id_document_wdt') . "
                    ORDER BY s.id LIMIT {$limit}");
            $stmt->execute(['rule' => $rule['id'], 'since' => $rule['enabled_at'] ?: date('Y-m-d H:i:s')]);
            return array_map(fn($r) => ['key' => "wdtksef:{$r['id']}", 'ref' => ['id' => (int) $r['id']]], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($rule['event'] === 'price_list_changed') return $this->priceCandidates($rule, $limit);
        if ($rule['event'] === 'month_start') return $this->monthCandidates($rule, $limit);

        $extra = match ($rule['event']) {
            'proforma_issued' => " AND t.status <> 'cancelled'",
            // écarts réels (une ligne au moins) d'une expédition enregistrée
            'difference_issued' => ' AND EXISTS (SELECT 1 FROM shipment_log sl WHERE sl.id_diff = t.id)
                AND EXISTS (SELECT 1 FROM document_order_shipping_difference_products p WHERE p.id_document_order_shipping_difference = t.id
                            AND p.invoiced_quantity * p.price_during_invoicing <> p.quantity_ordered * p.price_during_ordering)',
            default => '',
        };
        $sql = "SELECT t.id FROM {$table} t
                WHERE t.id > :start{$extra} AND " . sprintf($notDone, 't.id') . "
                ORDER BY t.id LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['rule' => $rule['id'], 'start' => (int) ($rule['start_ref_id'] ?? PHP_INT_MAX)]);
        return array_map(fn($r) => ['key' => "{$prefix}:{$r['id']}", 'ref' => ['id' => (int) $r['id']]], $stmt->fetchAll(PDO::FETCH_ASSOC));
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
            'wdt_ksef_accepted' => $this->documentContext('wdt', $this->documents->forWdt((int) $ref['id']), 'Faktura WDT'),
            'difference_issued' => $this->differenceContext((int) $ref['id']),
            'price_list_changed' => $this->customerContext((int) $ref['id_customer'], ['price' => ['id' => (int) $ref['id_customer']]]),
            'month_start' => $this->monthContext((int) $ref['id_customer'], (string) $ref['month']),
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
            'docs' => $order['id_proforma'] ? ['proforma' => ['id' => (int) $order['id_proforma']]] : [],
            // proforma pas encore générée : on attend (commande récente)
            'wait' => !$order['id_proforma'] && strtotime((string) $order['create_order_date']) > time() - self::PROFORMA_WAIT_HOURS * 3600,
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
            'docs' => ['notice' => ['id_customer' => $customerId, 'day' => $day]],
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
            'docs' => array_filter([
                'wz' => $shipment['id_wz'] ? ['id' => (int) $shipment['id_wz']] : null,
                'cmr' => $shipment['id_cmr'] ? ['id' => (int) $shipment['id_cmr']] : null,
                'ppwr' => ['id' => $shipmentId],
            ]),
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
            'docs' => [$source => ['id' => (int) $context['document']['id']]],
        ];
    }

    /** Variables d'un document pour la copie de la comptabilité (modèle accounting_document). */
    public function documentVariables(string $source, int $id): array
    {
        $context = match ($source) {
            'wdt' => $this->documentContext('wdt', $this->documents->forWdt($id), 'Faktura WDT'),
            'invoice' => $this->documentContext('invoice', $this->documents->forInvoice($id), 'Faktura'),
            'proforma' => $this->documentContext('proforma', $this->proformas->forProforma($id), 'Proforma'),
            default => throw new CustomException('Brak kopii dla biura rachunkowego: ' . $source, 'errors', 422),
        };
        return $context['variables'];
    }

    /** Après traitement d'un cas : le tarif envoyé (ou non concerné) devient l'état connu du client. */
    public function acknowledge(string $event, array $ref): void
    {
        if ($event !== 'price_list_changed' || ($ref['hash'] ?? '') === '') return;
        $this->db->prepare('INSERT INTO n_price_list_state (id_customer, hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE hash = VALUES(hash)')
            ->execute([(int) $ref['id_customer'], $ref['hash']]);
    }

    private function differenceContext(int $differenceId): array
    {
        $context = $this->differences->forDifference($differenceId);
        $client = $this->client((int) $context['id_customer']);
        $variables = $context['variables'];
        $variables['client'] = array_merge($this->clientVariables($client), $variables['client'] ?? []);
        $variables['company']['name'] = $this->companyName() ?: ($variables['company']['name'] ?? '');
        return [
            'id_customer' => (int) $context['id_customer'],
            'emails' => $this->emails($client),
            'variables' => $variables,
            'attachment' => null,
            'links' => ['id_wdt' => $context['id_wdt'] ?? null],
            'docs' => ['diff' => ['id' => $differenceId]],
        ];
    }

    private function customerContext(int $customerId, array $docs): array
    {
        $client = $this->client($customerId);
        return [
            'id_customer' => $customerId,
            'emails' => $this->emails($client),
            'variables' => [
                'client' => $this->clientVariables($client),
                'company' => ['name' => $this->companyName()],
                'today' => ['date' => date('d/m/Y')],
            ],
            'attachment' => null,
            'links' => [],
            'docs' => $docs,
        ];
    }

    /** Rapport du mois écoulé (récapitulatif des ventes WDT, objectif de CA) : seulement si le client a acheté. */
    private function monthContext(int $customerId, string $month): array
    {
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM document_wdt w WHERE w.id_customer = ? AND w.established_date BETWEEN ? AND ?
                                    AND NOT EXISTS (SELECT 1 FROM n_wdt_superseded s WHERE s.id_document_wdt = w.id)');
        $stmt->execute([$customerId, $from, $to]);
        $ref = ['id' => $customerId, 'month' => $month, 'from' => $from, 'to' => $to];
        $context = $this->customerContext($customerId, (int) $stmt->fetchColumn() > 0 ? ['summary' => $ref, 'goal' => $ref] : []);
        $context['variables']['report'] = ['month' => $month, 'period' => $month, 'documents' => ''];
        return $context;
    }

    /** Clients dont le tarif a changé depuis le dernier passage ; un client vu pour la première fois est seulement noté. */
    private function priceCandidates(array $rule, int $limit): array
    {
        $known = array_column($this->db->query('SELECT id_customer, hash FROM n_price_list_state')->fetchAll(PDO::FETCH_ASSOC), 'hash', 'id_customer');
        $done = $this->db->prepare('SELECT 1 FROM n_mail_dispatch WHERE id_rule = ? AND ref_key = ?');
        $out = [];
        foreach ($this->priceHashes() as $customerId => $hash) {
            if (!isset($known[$customerId])) {
                $this->acknowledge('price_list_changed', ['id_customer' => $customerId, 'hash' => $hash]);
                continue;
            }
            if ($known[$customerId] === $hash) continue;
            // une fois par jour au plus pour un même tarif (un échec est retenté le lendemain ou via « Ponów »)
            $key = sprintf('price:%d:%s:%s', $customerId, substr($hash, 0, 8), date('Ymd'));
            $done->execute([$rule['id'], $key]);
            if ($done->fetchColumn()) continue;
            $out[] = ['key' => $key, 'ref' => ['id_customer' => $customerId, 'hash' => $hash]];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** @return array<int, string> empreinte du tarif (produits actifs et prix imprimés sur le cennik) par client actif */
    private function priceHashes(): array
    {
        $rows = $this->db->query('SELECT p.id_customer, p.id_final_product, p.price FROM final_product_customer_price p
                                  JOIN final_product fp ON fp.id = p.id_final_product AND fp.active = 1
                                  JOIN customer c ON c.id = p.id_customer AND c.active = 1
                                  WHERE p.available = 1 AND p.price >= 10
                                  ORDER BY p.id_customer, p.id_final_product')->fetchAll(PDO::FETCH_NUM);
        $parts = [];
        foreach ($rows as [$customerId, $productId, $price]) $parts[(int) $customerId][] = $productId . ':' . $price;
        return array_map(fn($p) => md5(implode(';', $p)), $parts);
    }

    /** Le 1er du mois : rapport du mois écoulé pour chaque client actif (pas pour le mois où la règle a été activée). */
    private function monthCandidates(array $rule, int $limit): array
    {
        $month = self::previousMonth();
        if ($rule['enabled_at'] !== null && strtotime((string) $rule['enabled_at']) >= strtotime(date('Y-m-01'))) return [];
        $stmt = $this->db->prepare("SELECT c.id FROM customer c WHERE c.active = 1
                                    AND NOT EXISTS (SELECT 1 FROM n_mail_dispatch d WHERE d.id_rule = ? AND d.ref_key = CONCAT('month:', c.id, ':', ?))
                                    ORDER BY c.id LIMIT {$limit}");
        $stmt->execute([$rule['id'], $month]);
        return array_map(fn($id) => ['key' => "month:{$id}:{$month}", 'ref' => ['id_customer' => (int) $id, 'month' => $month]], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function previousMonth(): string
    {
        return date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
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
