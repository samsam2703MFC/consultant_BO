<?php

namespace App\Services\Client\Order;

use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Client\Order\OrderProductRepository;

class OrderSupplementPusherService
{
    public function __construct(
        private ClientRepository       $clientRepository,
        private OrderRepository        $orderRepository,
        private OrderProductRepository $orderProductRepository,
        private OrderService           $orderService,
    ) {}

    /**
     * Wysyla domowienie do API panelu (push).
     * Wywolywane automatycznie przy tworzeniu zamowienia SUPPLEMENT
     * oraz recznie z poziomu kontrolera.
     */
    public function push(int $idOrder): bool
    {
        $order = $this->orderRepository->getById($idOrder);
        if (!$order || ($order['order_type'] ?? 'STANDARD') !== 'SUPPLEMENT') {
            return false;
        }

        $client = $this->clientRepository->first(['id' => $order['id_customer']]);
        if (!$client) {
            return false;
        }

        $credentials    = json_decode($client['credentials'] ?? '', true) ?? [];
        $secretHex      = $credentials['secret'] ?? null;
        $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;
        $shopUid        = $client['franchisee_uid'] ?? null;

        if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
            error_log('[supplement_pusher] missing webhook_base_url for order id=' . $idOrder);
            $this->orderService->markSupplementPushStatus($idOrder, [
                'supplement_push_status' => 'failed',
                'supplement_push_at'     => date('Y-m-d H:i:s'),
            ], 'missing_webhook_base_url');
            return false;
        }

        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            error_log('[supplement_pusher] missing/invalid secret for order id=' . $idOrder);
            $this->orderService->markSupplementPushStatus($idOrder, [
                'supplement_push_status' => 'failed',
                'supplement_push_at'     => date('Y-m-d H:i:s'),
            ], 'missing_or_invalid_secret');
            return false;
        }

        if (!$shopUid) {
            error_log('[supplement_pusher] missing franchisee_uid for order id=' . $idOrder);
            return false;
        }

        // Generuj order_key jezeli jeszcze nie nadany
        $orderKey = $order['supplement_order_key'] ?? null;
        if (!$orderKey) {
            $orderKey = sprintf(
                'SUPP-%s-%s-%s',
                $shopUid,
                date('Ymd'),
                bin2hex(random_bytes(3))
            );
            $this->orderService->markSupplementPushStatus($idOrder, [
                'supplement_order_key' => $orderKey,
            ], 'order_key_generated');
        }

        // Pobierz produkty zamowienia
        $products = $this->orderProductRepository->getById($idOrder);

        $payload = [
            'order_key'     => $orderKey,
            'reference'     => $orderKey,
            'supplier_name' => $this->resolveSupplierName(),
            'order_date'    => date('Y-m-d', strtotime($order['create_order_date'] ?? 'now')),
            'expected_date' => $order['expected_shipping_date'] ?? null,
            'products'      => array_values(array_map(function ($p) {
                return [
                    'sku'                    => (string)($p['id_product'] ?? ''),
                    'name'                   => $p['product_name'] ?? '',
                    'pack_quantity'          => (int)($p['quantity_carton'] ?? 0),
                    'pack_size_in_base_unit' => (float)($p['pack_size_in_base_unit'] ?? 1),
                    'pack_price_net'         => (float)($p['purchase_price'] ?? 0),
                ];
            }, $products)),
        ];

        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig = hash_hmac('sha256', $raw, hex2bin($secretHex));

        $url = rtrim($webhookBaseUrl, '/') . '/webhooks/material-orders/supplement/push';
        [$code, , $err] = $this->httpPostJson($url, $raw, [
            'X-Shop-Uid: ' . $shopUid,
            'X-Signature: ' . $sig,
        ]);

        if ($err || $code < 200 || $code >= 300) {
            error_log('[supplement_pusher] push failed code=' . $code . ' err=' . $err . ' order_id=' . $idOrder);
            $this->orderService->markSupplementPushStatus($idOrder, [
                'supplement_push_status' => 'failed',
                'supplement_push_at'     => date('Y-m-d H:i:s'),
            ], sprintf('http_push_failed code=%d err=%s', $code, (string)$err));
            return false;
        }

        $this->orderService->markSupplementPushStatus($idOrder, [
            'supplement_push_status' => 'sent',
            'supplement_push_at'     => date('Y-m-d H:i:s'),
        ], 'push_ok');

        return true;
    }

    private function resolveSupplierName(): string
    {
        // Mozna wczytac z konfiguracji aplikacji lub ustalic statycznie
        return defined('APP_COMPANY_NAME') ? APP_COMPANY_NAME : 'Dostawca zintegrowany';
    }

    private function httpPostJson(string $url, string $rawJson, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_POSTFIELDS      => $rawJson,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch) ?: null;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $body ?: '', $err];
    }
}

