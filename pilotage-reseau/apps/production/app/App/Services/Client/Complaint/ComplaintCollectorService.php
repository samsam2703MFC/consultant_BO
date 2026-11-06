<?php
namespace App\Services\Client\Complaint;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Complaint\ComplaintAttachmentRepository;
use App\Repositories\Complaint\ComplaintRepository;
use GuzzleHttp\Psr7\CachingStream;

class ComplaintCollectorService
{

    public function __construct(
        private ClientRepository $clientRepository,
        private ComplaintService $complaintService,
        private ComplaintRepository $complaintRepository,
        private ComplaintAttachmentRepository $complaintAttachmentRepository
    )
    {
    }


    public function runCollector(int $limit = 50)
    {
        $franchiseeClients = $this->clientRepository->getFranchisee();
        if (empty($franchiseeClients)) {
            return;
        }

        foreach ($franchiseeClients as $franchiseeClient) {
            $credentials = json_decode($franchiseeClient['credentials'] ?? '', true) ?? [];
            $secretHex = $credentials['secret'] ?? null;
            $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;

            if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
                error_log(date('Y-m-d H:i:s') . '[collector] missing or invalid webhook_base_url for client id=' . $franchiseeClient['id']);
                continue;
            }
            if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
                error_log(date('Y-m-d H:i:s') . '[collector] missing or invalid secret for client id=' . $franchiseeClient['id']);
                continue;
            }

            // Skąd shopUid? Najlepiej z kolumny klienta:
            $shopUid = $franchiseeClient['franchisee_uid'] ?? null;

            if (!$shopUid) {
                // bez shop_uid nie jedziemy dalej
                error_log(date('Y-m-d H:i:s') . '[collector] missing shop_uid for client id=' . $franchiseeClient['id']);
                continue;
            }

            try {
                $this->collectFromClient($shopUid, $webhookBaseUrl, $secretHex, $limit);
            } catch (\Throwable $e) {
                error_log(date('Y-m-d H:i:s') . '[collector] collect error for ' . $shopUid . ': ' . $e->getMessage());
            }
        }
    }

    public function runManualCollector(int $limit = 50)
    {
        $franchiseeClients = $this->clientRepository->getFranchisee();

        if (empty($franchiseeClients)) {
            return;
        }

        foreach ($franchiseeClients as $franchiseeClient) {
            $credentials = json_decode($franchiseeClient['credentials'] ?? '', true) ?? [];
            $secretHex = $credentials['secret'] ?? null;
            $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;


            if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
                error_log(date('Y-m-d H:i:s') . '[collector] missing or invalid webhook_base_url for client id=' . $franchiseeClient['id']);
                continue;
            }
            if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
                error_log(date('Y-m-d H:i:s') . '[collector] missing or invalid secret for client id=' . $franchiseeClient['id']);
                continue;
            }

            // Skąd shopUid? Najlepiej z kolumny klienta:
            $shopUid = $franchiseeClient['franchisee_uid'] ?? null;

            if (!$shopUid) {
                // bez shop_uid nie jedziemy dalej
                error_log(date('Y-m-d H:i:s') . '[collector] missing shop_uid for client id=' . $franchiseeClient['id']);
                continue;
            }

            try {
                $this->collectFromClient($shopUid, $webhookBaseUrl, $secretHex, $limit);
            } catch (\Throwable $e) {
                error_log(date('Y-m-d H:i:s') . '[collector] collect error for ' . $shopUid . ': ' . $e->getMessage());
            }
        }

        return true;
    }

    public function collectFromClient(string $shopUid, string $baseUrl, ?string $secretHex, int $limit = 50): bool
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
        $pullUrl = rtrim($baseUrl, '/') . '/webhooks/material-suppliers/complaints/pull';
        [$code, $body, $curlErr] = $this->httpPostJson($pullUrl, $raw, [
            'X-Shop-Uid: ' . $shopUid,
            'X-Signature: ' . $sig,
        ]);

        if ($curlErr || $code < 200 || $code >= 300) {
            error_log(date('Y-m-d H:i:s') . '[complaint_collector] pull failed code=' . $code . ' err=' . $curlErr . ' url=' . $pullUrl);
            return false;
        }

        $resp = json_decode($body, true) ?: [];
        $items = $resp['items'] ?? [];
        if (!$items) {
            return true; // nic do roboty
        }

        // 4) Import
        $doneKeys = [];

        foreach ($items as $item) {
            $complaintUuid = $item['complaint_uuid'] ?? ($item['payload']['complaint_uuid'] ?? null);
            $payload = $item['payload'] ?? null;
            if (!$payload && isset($item['payload_json'])) {
                $payload = json_decode($item['payload_json'], true);
            }

            if (!$complaintUuid || !is_array($payload)) {
                error_log(date('Y-m-d H:i:s') . '[complaint_collector] invalid row shape');
                continue;
            }

            try {
                $complaintType = $payload['complaint_type'] ?? 'PRODUCT';
                if (!in_array($complaintType, ['PRODUCT', 'NON_PRODUCT'], true)) {
                    throw new CustomException('INVALID_COMPLAINT_TYPE', 'errors', 400);
                }

                // 1 Import samej reklamacji
                $this->complaintService->insertFromCollector([
                    'franchisee_uid' => $shopUid,
                    'complaint_type' => $complaintType,
                    'complaint_key' => $payload['complaint_key'],
                    'complaint_uuid' => $complaintUuid,
                    'order_key' => $payload['order_key'] ?? null,
                    'product_sku' => $payload['supplier_sku'] ?? null,
                    'product_quantity' => $payload['product_quantity'] ?? null,
                    'reason_code' => $payload['reason_code'] ?? null,
                    'description' => $payload['description'] ?? "",
                    'requested_action' => $payload['requested_action'] ?? "",
                    'reported_at' => $payload['reported_at'] ?? null,
                    'callback_url' => "/webhooks/material-suppliers/complaints/confirmation",
                    'secret' => $secretHex
                ], $payload['attachments'] ?? []);

                $doneKeys[] = $complaintUuid;
            } catch (\Throwable $e) {
                error_log(date('Y-m-d H:i:s') . '[complaint_collector] import failed for ' . $complaintUuid . ': ' . $e->getMessage());
                continue;
            }
        }

        // 5) ACK (DONE)
        if ($doneKeys) {
            $ackBody = [
                'complaint_uids' => array_values(array_unique($doneKeys)),
                'action' => 'done',
            ];
            $ackRaw = json_encode($ackBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ackSig = hash_hmac('sha256', $ackRaw, hex2bin($secretHex));
            $ackUrl = rtrim($baseUrl, '/') . '/webhooks/material-suppliers/complaints/ack';

            [$ackCode, , $ackErr] = $this->httpPostJson($ackUrl, $ackRaw, [
                'X-Shop-Uid: ' . $shopUid,
                'X-Signature: ' . $ackSig,
            ]);

            if ($ackErr || $ackCode < 200 || $ackCode >= 300) {
                // nic nie psujemy – import MUSI być idempotentny
                error_log(date('Y-m-d H:i:s') . '[complaint_collector] ack(done) failed code=' . $ackCode . ' err=' . $ackErr);
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

    public function getAttachmentPresigned($id)
    {
        $complaintAttachment = $this->complaintAttachmentRepository->first(['id' => $id]);
        if(empty($complaintAttachment)){
            return null;
        }

        $complaint = $this->complaintRepository->first(['id' => $complaintAttachment['complaint_id']]);
        if(empty($complaint)){
            return null;
        }

        $franchiseeClient = $this->clientRepository->first(['id' => $complaint['id_customer']]);
        if(empty($franchiseeClient)){
            return null;
        }
        $credentials = json_decode($franchiseeClient['credentials'] ?? '', true) ?? [];
        $secretHex = $credentials['secret'] ?? null;
        $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;


        if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
            error_log(date('Y-m-d H:i:s') . '[complaint_presigned_url] missing or invalid webhook_base_url for client id=' . $franchiseeClient['id']);
            return null;
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            error_log(date('Y-m-d H:i:s') . '[complaint_presigned_url] missing or invalid secret for client id=' . $franchiseeClient['id']);
            return null;
        }

        $shopUid = $franchiseeClient['franchisee_uid'] ?? null;
        if (!$shopUid) {
            // bez shop_uid nie jedziemy dalej
            error_log(date('Y-m-d H:i:s') . '[complaint_presigned_url] missing shop_uid for client id=' . $franchiseeClient['id']);
            return null;
        }


        if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        // 1) BODY do PULL
        $pullBody = [
            'object_key' => $complaintAttachment['object_key'],
            'attachment_id' => $complaintAttachment['client_attachment_id']
        ];
        $raw = json_encode($pullBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 2) Podpis HMAC (secret w HEX) – podpisujemy samo body (KISS)
        $sig = hash_hmac('sha256', $raw, hex2bin($secretHex));

        // 3) PULL
        $pullUrl = rtrim($webhookBaseUrl, '/') . '/webhooks/material-suppliers/complaints/attachments/presigned-url';
        [$code, $body, $curlErr] = $this->httpPostJson($pullUrl, $raw, [
            'X-Shop-Uid: ' . $shopUid,
            'X-Signature: ' . $sig,
        ]);

        if ($curlErr || $code < 200 || $code >= 300) {
            error_log(date('Y-m-d H:i:s') . '[complaint_presigned_url] pull failed code=' . $code . ' err=' . $curlErr . ' url=' . $pullUrl);
            return false;
        }

        $resp = json_decode($body, true) ?: [];

        return $resp;
    }

    public function sendComplaintUpdate($id)
    {
        $complaint = $this->complaintRepository->first(['id' => $id]);
        if (!$complaint) {
            throw new CustomException("COMPLAINT_NOT_FOUND", 'errors', 500);
        }
        $franchiseeClient = $this->clientRepository->first(['id' => $complaint['id_customer']]);
        if (!isset($franchiseeClient) || empty($franchiseeClient)) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 500);
        }

        if(is_null($franchiseeClient['credentials']) || $franchiseeClient['credentials'] == ""){
            return true;
        }

        $credentials = json_decode($franchiseeClient['credentials'] ?? '', true) ?? [];
        $secretHex = $credentials['secret'] ?? null;
        $webhookBaseUrl = $credentials['webhooks_base_url'] ?? null;


        if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
            error_log(date('Y-m-d H:i:s') . '[manual_complaint_update] missing or invalid webhook_base_url for client id=' . $franchiseeClient['id']);
            return null;
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            error_log(date('Y-m-d H:i:s') . '[manual_complaint_update] missing or invalid secret for client id=' . $franchiseeClient['id']);
            return null;
        }

        $shopUid = $franchiseeClient['franchisee_uid'] ?? null;
        if (!$shopUid) {
            // bez shop_uid nie jedziemy dalej
            error_log(date('Y-m-d H:i:s') . '[manual_complaint_update] missing shop_uid for client id=' . $franchiseeClient['id']);
            return null;
        }

        if (!$webhookBaseUrl || !filter_var($webhookBaseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        $updatePayload = [
            'complaint_key' => $complaint['complaint_key'],
            'acceptance_status' => $complaint['acceptance_status'],
            'production_response' => $complaint['production_response'] ?? '',
            'closed_on' => $complaint['closed_on'] ?? null,
            'franchisee_uid' => $franchiseeClient['franchisee_uid'],
            'secret' => $credentials['secret'] ?? '',
        ];
        if (($complaint['complaint_type'] ?? 'PRODUCT') === 'PRODUCT') {
            $updatePayload['compensation_method'] = $complaint['compensation_method'];
            $updatePayload['accepted_qty'] = $complaint['accepted_quantity'] ?? 0;
        }
        $jsonPayload = json_encode($updatePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 2) Podpis HMAC (secret w HEX) – podpisujemy samo body (KISS)
        $sig = hash_hmac('sha256', $jsonPayload, hex2bin($secretHex));

        // 3) PULL
        $pullUrl = rtrim($webhookBaseUrl, '/') . '/webhooks/material-suppliers/complaints/update';
        [$code, $body, $curlErr] = $this->httpPostJson($pullUrl, $jsonPayload, [
            'X-Shop-Uid: ' . $shopUid,
            'X-Signature: ' . $sig,
        ]);

        if ($curlErr || $code < 200 || $code >= 300) {
            error_log(date('Y-m-d H:i:s') . '[manual_complaint_update] pull failed code=' . $code . ' err=' . $curlErr . ' url=' . $pullUrl);
            return false;
        }

        return json_decode($body, true) ?: [];
    }
}
