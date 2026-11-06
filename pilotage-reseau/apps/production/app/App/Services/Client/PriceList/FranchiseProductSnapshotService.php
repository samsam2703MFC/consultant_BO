<?php

namespace App\Services\Client\PriceList;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\PriceList\FranchiseProductSnapshotItemRepository;
use App\Repositories\Client\PriceList\FranchiseProductSnapshotRepository;

class FranchiseProductSnapshotService
{
    public function __construct(
        private ClientRepository $clientRepository,
        private FranchiseProductSnapshotRepository $snapshotRepository,
        private FranchiseProductSnapshotItemRepository $itemRepository,
        private TransactionManager $transactionManager
    ) {
    }

    public function refreshForClient(int $clientId): array
    {
        $client = $this->clientRepository->first(['id' => $clientId]);
        if (!$client) {
            throw new CustomException('MISSING_CLIENT', 'errors', 404);
        }

        $requestedDate = date('Y-m-d');
        $franchiseeUid = $client['franchisee_uid'] ?? null;

        try {
            $pullResult = $this->pullPriceList($client, $requestedDate);
            if (!$pullResult['ok']) {
                return $this->storeFailedSnapshot($clientId, $franchiseeUid, $requestedDate, $pullResult);
            }

            return $this->storeSuccessfulSnapshot($clientId, $franchiseeUid, $requestedDate, $pullResult);
        } catch (CustomException $e) {
            return $this->storeFailedSnapshot($clientId, $franchiseeUid, $requestedDate, [
                'http_code' => $e->getCode() ?: 500,
                'error_code' => $e->getMessage(),
                'error_message' => $e->getMessage(),
                'duration_ms' => null,
                'raw_response' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->storeFailedSnapshot($clientId, $franchiseeUid, $requestedDate, [
                'http_code' => 500,
                'error_code' => 'INTERNAL_ERROR',
                'error_message' => $e->getMessage(),
                'duration_ms' => null,
                'raw_response' => null,
            ]);
        }
    }

    public function getLatestSuccessfulForClient(int $clientId): array
    {
        return $this->withItems($this->snapshotRepository->latestSuccessfulForClient($clientId));
    }

    public function getLatestAttemptForClient(int $clientId): array
    {
        return $this->withItems($this->snapshotRepository->latestAttemptForClient($clientId));
    }

    private function pullPriceList(array $client, string $date): array
    {
        if (empty($client['franchisee_uid'])) {
            throw new CustomException('CLIENT_NOT_INTEGRATED', 'errors', 400);
        }

        $credentials = json_decode($client['credentials'] ?? '', true);
        if (!is_array($credentials)) {
            throw new CustomException('INVALID_CREDENTIALS_JSON', 'errors', 400);
        }

        $baseUrl = $credentials['webhooks_base_url'] ?? null;
        if (!$baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException('INVALID_WEBHOOK_URL', 'errors', 400);
        }

        $secretHex = $credentials['secret'] ?? null;
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException('INVALID_SECRET', 'errors', 400);
        }

        $raw = json_encode(['date' => $date], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $raw, hex2bin($secretHex));
        $url = rtrim($baseUrl, '/') . '/webhooks/material-orders/price-list';
        $startedAt = microtime(true);

        [$httpCode, $body, $curlError] = $this->httpPostJson($url, $raw, [
            'X-Shop-Uid: ' . $client['franchisee_uid'],
            'X-Signature: ' . $signature,
        ]);
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'items' => [],
                'http_code' => $httpCode,
                'error_code' => $curlError ? 'HTTP_CLIENT_ERROR' : ($httpCode === 401 || $httpCode === 403 ? 'AUTH_ERROR' : 'HTTP_ERROR'),
                'error_message' => $curlError ?: ('HTTP ' . $httpCode),
                'duration_ms' => $durationMs,
                'raw_response' => $body,
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return [
                'ok' => false,
                'items' => [],
                'http_code' => $httpCode,
                'error_code' => 'INVALID_JSON',
                'error_message' => json_last_error_msg(),
                'duration_ms' => $durationMs,
                'raw_response' => $body,
            ];
        }

        if (!array_key_exists('data', $decoded) || !is_array($decoded['data'])) {
            return [
                'ok' => false,
                'items' => [],
                'http_code' => $httpCode,
                'error_code' => 'INVALID_PAYLOAD',
                'error_message' => 'Response data is missing or is not an array',
                'duration_ms' => $durationMs,
                'raw_response' => $body,
            ];
        }

        return [
            'ok' => true,
            'items' => $decoded['data'],
            'http_code' => $httpCode,
            'error_code' => null,
            'error_message' => null,
            'duration_ms' => $durationMs,
            'raw_response' => $body,
        ];
    }

    private function storeSuccessfulSnapshot(int $clientId, ?string $franchiseeUid, string $requestedDate, array $pullResult): array
    {
        $normalizedItems = [];
        $activeCount = 0;
        $inactiveCount = 0;

        foreach ($pullResult['items'] as $item) {
            $normalized = $this->normalizeItem($clientId, $item);
            $normalizedItems[] = $normalized;

            if (($normalized['is_active'] ?? null) === 1) {
                $activeCount++;
            } elseif (($normalized['is_active'] ?? null) === 0) {
                $inactiveCount++;
            }
        }

        $snapshotId = $this->transactionManager->execute(function () use ($clientId, $franchiseeUid, $requestedDate, $pullResult, $normalizedItems, $activeCount, $inactiveCount) {
            $snapshotId = (int)$this->snapshotRepository->insert([
                'id_customer' => $clientId,
                'franchisee_uid' => $franchiseeUid,
                'requested_date' => $requestedDate,
                'status' => 'success',
                'http_code' => $pullResult['http_code'],
                'error_code' => null,
                'error_message' => null,
                'duration_ms' => $pullResult['duration_ms'],
                'items_count' => count($normalizedItems),
                'active_items_count' => $activeCount,
                'inactive_items_count' => $inactiveCount,
                'content_hash' => hash('sha256', $pullResult['raw_response'] ?? json_encode($pullResult['items'])),
            ]);

            foreach ($normalizedItems as $item) {
                $item['snapshot_id'] = $snapshotId;
                $this->itemRepository->insert($item);
            }

            return $snapshotId;
        });

        return $this->withItems($this->snapshotRepository->first(['id' => $snapshotId]));
    }

    private function storeFailedSnapshot(int $clientId, ?string $franchiseeUid, string $requestedDate, array $errorData): array
    {
        $snapshotId = (int)$this->snapshotRepository->insert([
            'id_customer' => $clientId,
            'franchisee_uid' => $franchiseeUid,
            'requested_date' => $requestedDate,
            'status' => 'failed',
            'http_code' => $errorData['http_code'] ?? null,
            'error_code' => $errorData['error_code'] ?? 'UNKNOWN_ERROR',
            'error_message' => $errorData['error_message'] ?? null,
            'duration_ms' => $errorData['duration_ms'] ?? null,
            'items_count' => 0,
            'active_items_count' => 0,
            'inactive_items_count' => 0,
            'content_hash' => !empty($errorData['raw_response']) ? hash('sha256', $errorData['raw_response']) : null,
        ]);

        return $this->withItems($this->snapshotRepository->first(['id' => $snapshotId]));
    }

    private function normalizeItem(int $clientId, mixed $item): array
    {
        if (!is_array($item)) {
            return [
                'id_customer' => $clientId,
                'sku' => null,
                'id_product_external' => null,
                'product_name' => null,
                'price_net' => null,
                'vat_rate' => null,
                'package_size' => null,
                'package_unit' => null,
                'is_active' => null,
                'valid_from' => null,
                'diagnostic_status' => 'invalid_payload',
                'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $sku = $this->normalizeString($item['sku'] ?? null);
        $price = $item['price_net'] ?? null;
        $isActive = $this->normalizeBool($item['is_active'] ?? null);

        return [
            'id_customer' => $clientId,
            'sku' => $sku,
            'id_product_external' => $sku,
            'product_name' => $this->normalizeString($item['product_name'] ?? null),
            'price_net' => $this->normalizeDecimal($price),
            'vat_rate' => $this->normalizeDecimal($item['vat_rate'] ?? null),
            'package_size' => $this->normalizeDecimal($item['package_size'] ?? null),
            'package_unit' => $this->normalizeString($item['package_unit'] ?? null),
            'is_active' => $isActive,
            'valid_from' => $this->normalizeDate($item['valid_from'] ?? null),
            'diagnostic_status' => $this->diagnosticStatus($item, $sku, $price, $isActive),
            'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function diagnosticStatus(array $item, ?string $sku, mixed $price, ?int $isActive): string
    {
        if ($sku === null || $sku === '') {
            return 'missing_sku';
        }

        foreach (['product_name', 'package_size', 'package_unit', 'vat_rate', 'is_active', 'valid_from'] as $field) {
            if (!array_key_exists($field, $item)) {
                return 'invalid_payload';
            }
        }

        if ($isActive === 0) {
            return 'inactive_supplier';
        }

        if (!array_key_exists('price_net', $item) || $price === null || $price === '') {
            return 'missing_price';
        }

        if (is_numeric($price) && (float)$price == 0.0) {
            return 'zero_price';
        }

        if (!is_numeric($price) || $isActive === null) {
            return 'invalid_payload';
        }

        return 'ok';
    }

    private function withItems(array $snapshot): array
    {
        if (!$snapshot) {
            return [];
        }

        $snapshot['items'] = $this->itemRepository->bySnapshot((int)$snapshot['id']);
        return $snapshot;
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

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (string)$value;
    }

    private function normalizeBool(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized === null) {
            return null;
        }

        return $normalized ? 1 : 0;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!$value || !is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
