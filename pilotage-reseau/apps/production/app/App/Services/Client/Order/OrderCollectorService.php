<?php

namespace App\Services\Client\Order;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderCollectIssueRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Document\Proforma\ProformaOutboxRepository;
use App\Services\Document\ProformaService;

class OrderCollectorService
{

    public function __construct(
        private ClientRepository $clientRepository,
        private OrderService     $orderService,
        private ProformaService  $proformaService,
        private ProformaOutboxRepository $proformaOutboxRepository,
        private OrderCollectIssueRepository $issues,
    )
    {
    }

    public function runCollector(int $limit = 50)
    {
        $franchiseeClients = $this->clientRepository->getFranchisee();
        if (empty($franchiseeClients)) {
            return;
        }
        $this->collectAll($franchiseeClients, 'order_collector', $limit);
    }

    public function runManualCollector(int $limit = 50)
    {
        $franchiseeClients = $this->clientRepository->getFranchisee();
        if (empty($franchiseeClients)) {
            throw new CustomException("MISSING_FRANCHISE", 'errors', 500);
        }
        $this->collectAll($franchiseeClients, 'order_manual_collector', $limit);
        return true;
    }

    /**
     * Boutiques une par une : une boutique mal configurée ou qui ne répond pas n'arrête pas les autres. Chaque échec
     * va dans le journal du serveur ET dans Stan wysyłek (n_order_collect_issue) ; il se ferme seul au prochain succès.
     */
    private function collectAll(array $franchiseeClients, string $tag, int $limit): void
    {
        foreach ($franchiseeClients as $franchiseeClient) {
            $clientId = (int) $franchiseeClient['id'];
            $credentials = json_decode($franchiseeClient['credentials'] ?? '', true) ?? [];
            $secretHex = $credentials['secret'] ?? null;
            $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;
            $shopUid = (string) ($franchiseeClient['franchisee_uid'] ?? '');

            $config = match (true) {
                !$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL) => ['missing or invalid webhook_base_url', 'Brak adresu webhooków sklepu w karcie klienta — zamówienia nie są pobierane.'],
                !$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex) => ['missing or invalid secret', 'Brak lub zły klucz sklepu w karcie klienta — zamówienia nie są pobierane.'],
                $shopUid === '' => ['missing shop_uid', 'Brak identyfikatora sklepu (franchisee_uid) — zamówienia nie są pobierane.'],
                default => null,
            };
            if ($config !== null) {
                error_log(date('Y-m-d H:i:s') . '[' . $tag . '] ' . $config[0] . ' for client id=' . $clientId);
                $this->issues->record('config', $shopUid, $clientId, null, $config[1]);
                continue;
            }

            try {
                $this->collectFromClient($shopUid, $webhookBaseUrl, $secretHex, $limit, $clientId);
            } catch (\Throwable $e) {
                error_log(date('Y-m-d H:i:s') . '[' . $tag . '] collect error for ' . $shopUid . ': ' . $e->getMessage());
                $this->issues->record('pull', $shopUid, $clientId, null, 'Błąd pobierania zamówień : ' . $e->getMessage());
            }
        }
    }

    public function collectFromClient(string $shopUid, string $baseUrl, ?string $secretHex, int $limit = 50, ?int $clientId = null): bool
    {
        if (!$baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        // 1) BODY do PULL
        $pullBody = [
            'shop_uid' => $shopUid,
            'batch' => max(1, min($limit, 200)),
        ];
        $raw = json_encode($pullBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 2) Podpis HMAC (secret w HEX) – podpisujemy samo body (KISS)
        $sig = hash_hmac('sha256', $raw, hex2bin($secretHex));

        // 3) PULL
        $pullUrl = rtrim($baseUrl, '/') . '/webhooks/material-orders/pull';
        [$code, $body, $curlErr] = $this->httpPostJson($pullUrl, $raw, [
            'X-Shop-Uid: ' . $shopUid,
            'X-Signature: ' . $sig,
        ]);

        if ($curlErr || $code < 200 || $code >= 300) {
            error_log(date('Y-m-d H:i:s') . '[order_collector] pull failed code=' . $code . ' err=' . $curlErr . ' url=' . $pullUrl);
            $this->issues->record('pull', $shopUid, $clientId, null, 'Sklep nie odpowiada na pobieranie zamówień : ' . ($curlErr ?: 'HTTP ' . $code));
            return false;
        }
        // La boutique répond : ses erreurs de configuration et de récupération sont réglées.
        $this->issues->close(['config', 'pull'], $shopUid);

        $resp = json_decode($body, true) ?: [];
        $items = $resp['items'] ?? [];
        if (!$items) {
            return true; // nic do roboty
        }


        // 4) Import
        $doneKeys = [];
        $failKeys = [];
        $failReasons = [];

        foreach ($items as $item) {
            $orderKey = $item['order_key'] ?? ($item['payload']['order_key'] ?? null);
            $payload = $item['payload'] ?? null;
            if (!$payload && isset($item['payload_json'])) {
                $payload = json_decode($item['payload_json'], true);
            }

            if (!$orderKey || !is_array($payload)) {
                if ($orderKey) {
                    $failKeys[] = $orderKey;
                    $failReasons[$orderKey] = 'bad-row-shape';
                    $this->issues->record('import', $shopUid, $clientId, (string) $orderKey, 'Zamówienie ' . $orderKey . ' odrzucone : nieprawidłowe dane od sklepu.');
                }
                continue;
            }

            try {
                // Twoja logika – idempotentnie po order_key!
                $this->orderService->insertOrderFromCollector([
                    'franchisee_uid' => $shopUid,
                    'order_batch_key' => $payload['order_batch_key'] ?? null,
                    'batch_index' => $payload['batch_index'] ?? null,
                    'order_key' => $orderKey,
                    'expected_date' => $payload['expected_date'] ?? null,
                    'order_date' => $payload['order_date'] ?? null,
                    'products' => $payload['products'] ?? [],
                    'order_description' => $payload['order_description'] ?? "",
                    'callback_url' => "/webhooks/material-orders/confirmation",
                    'secret' => $secretHex
                ]);
                $doneKeys[] = $orderKey;
                $this->issues->close(['import'], $shopUid, (string) $orderKey);
            } catch (\Throwable $e) {
                $failKeys[] = $orderKey;
                $failReasons[$orderKey] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
                error_log(date('Y-m-d H:i:s') . '[order_collector] import failed for ' . $orderKey . ': ' . $e->getMessage());
                $this->issues->record('import', $shopUid, $clientId, (string) $orderKey, 'Zamówienie ' . $orderKey . ' nie zostało zaimportowane : ' . $failReasons[$orderKey]);
                continue;
            }
        }

        // 5) ACK (DONE)
        if ($doneKeys) {
            $ackBody = [
                'order_keys' => array_values(array_unique($doneKeys)),
                'action' => 'done',
            ];
            $ackRaw = json_encode($ackBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ackSig = hash_hmac('sha256', $ackRaw, hex2bin($secretHex));
            $ackUrl = rtrim($baseUrl, '/') . '/webhooks/material-orders/ack';

            [$ackCode, , $ackErr] = $this->httpPostJson($ackUrl, $ackRaw, [
                'X-Shop-Uid: ' . $shopUid,
                'X-Signature: ' . $ackSig,
            ]);
            if ($ackErr || $ackCode < 200 || $ackCode >= 300) {
                // nic nie psujemy – import MUSI być idempotentny
                error_log(date('Y-m-d H:i:s') . '[order_collector] ack(done) failed code=' . $ackCode . ' err=' . $ackErr);
            }
        }

        // 6) ACK (FAIL)
        if ($failKeys) {
            $reason = $this->compactReasons($failReasons);
            $ackBody = [
                'order_keys' => array_values(array_unique($failKeys)),
                'action' => 'fail',
                'reason' => $reason,
            ];
            $ackRaw = json_encode($ackBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ackSig = hash_hmac('sha256', $ackRaw, hex2bin($secretHex));
            $ackUrl = rtrim($baseUrl, '/') . '/webhooks/material-orders/ack';

            [$ackCode, , $ackErr] = $this->httpPostJson($ackUrl, $ackRaw, [
                'X-Shop-Uid: ' . $shopUid,
                'X-Signature: ' . $ackSig,
            ]);
            if ($ackErr || $ackCode < 200 || $ackCode >= 300) {
                error_log(date('Y-m-d H:i:s') . '[order_collector] ack(fail) failed code=' . $ackCode . ' err=' . $ackErr);
            }
        }

        return true;
    }

    private function httpPostJson(string $url, string $rawJson, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $rawJson,
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch) ?: null;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $body ?: '', $err];
    }

    private function compactReasons(array $reasons): string
    {
        $parts = [];
        foreach ($reasons as $k => $v) {
            $parts[] = $k . ': ' . $v;
            if (strlen(implode('; ', $parts)) > 500) break;
        }
        return substr(implode('; ', $parts), 0, 500);
    }

    public function enqueueProformaForRetry(string $shopUid, string $orderKey, string $uuid): void {
        $this->proformaOutboxRepository->insertOnDuplicate([
            'shop_uid'      => $shopUid,
            'order_key'     => $orderKey,
            'proforma_uuid' => $uuid,
            'status'        => 'pending',
        ]);
    }
}
