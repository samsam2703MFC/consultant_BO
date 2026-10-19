<?php

namespace App\Services\Client\PriceList;

use App\Exceptions\CustomException;
use App\Models\Client\PriceList\PriceListItemModel;
use App\Repositories\Client\ClientRepository;

class ExternalPriceListService
{
    public function __construct(
        private ClientRepository         $clientRepository
    )
    {
    }



    public function getPriceListForClientByDate($clientId, $date = null)
    {
        $rawData = $this->pullFromExternalSource($clientId, $date);
        if(!$rawData){
            return [];
        }

        $castedToPriceListObjs = [];

        foreach ($rawData as &$item){
            $castedToPriceListObjs[] = new PriceListItemModel([
                'id_product' => $item['sku'],
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'price_net' => $item['price_net'],
                'vat_rate' => $item['vat_rate'],
                'package_size' => $item['package_size'],
                'package_unit' => $item['package_unit'],
                'is_active' => $item['is_active'],
                'valid_from' => $item['valid_from'],
            ]);
        }

        return $castedToPriceListObjs;
    }

    public function getActiveByClient($clientId, $date = null)
    {
        $elems = $this->getPriceListForClientByDate($clientId, $date);
        $activeElems = [];
        foreach ($elems as $elem){
            if($elem->getIsActive()){
                $activeElems[] = $elem;
            }
        }
        return $activeElems;
    }


    private function pullFromExternalSource($clientId, $date = null)
    {
        $date = $date ?? date('Y-m-d');

        $client = $this->clientRepository->first(['id' => $clientId]);
        if (empty($client)) {
            throw new CustomException("MISSING_CLIENT", 'errors', 404);
        }

        if ($client['franchisee_uid'] == null) {
            throw new CustomException("CLIENT_NOT_INTEGRATED", 'errors', 404);
        }


        $credentials = json_decode($client['credentials'] ?? '', true) ?? [];
        $secretHex = $credentials['secret'] ?? null;
        $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;

        if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
            error_log(date('Y-m-d H:i:s') . '[price_list_poller] missing or invalid webhook_base_url for client id=' . $client['id']);
            throw new CustomException("INVALID_WEBHOOK_URL", 'errors', 404);
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            error_log(date('Y-m-d H:i:s') . '[price_list_poller] missing or invalid secret for client id=' . $client['id']);
            throw new CustomException("INVALID_SECRET", 'errors', 404);
        }

        $shopUid = $client['franchisee_uid'];

        try {
            return $this->collectFromClient($shopUid, $webhookBaseUrl, $secretHex, $date);
        } catch (\Throwable $e) {
            error_log(date('Y-m-d H:i:s') . '[price_list_poller] collect error for ' . $shopUid . ': ' . $e->getMessage());
            throw new CustomException("POLLING_PRICELIST_ERROR", 'errors', 404);
        }

    }


    private function collectFromClient($shopUid, $baseUrl, $secretHex, $date)
    {
        if (!$baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        $pullBody = [
            'date' => $date
        ];
        $raw = json_encode($pullBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 2) Podpis HMAC (secret w HEX) – podpisujemy samo body (KISS)
        $sig = hash_hmac('sha256', $raw, hex2bin($secretHex));

        // 3) PULL
        $pullUrl = rtrim($baseUrl, '/') . '/webhooks/material-orders/price-list';
        [$code, $body, $curlErr] = $this->httpPostJson($pullUrl, $raw, [
            'X-Shop-Uid: ' . $shopUid,
            'X-Signature: ' . $sig,
        ]);

        if ($curlErr || $code < 200 || $code >= 300) {
            error_log(date('Y-m-d H:i:s') . '[price_list_poller] pull failed code=' . $code . ' err=' . $curlErr . ' url=' . $pullUrl);
            return false;
        }

        $resp = json_decode($body, true) ?: [];

        $items = $resp['data'] ?? [];
        if (!$items) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        return $items;
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
}