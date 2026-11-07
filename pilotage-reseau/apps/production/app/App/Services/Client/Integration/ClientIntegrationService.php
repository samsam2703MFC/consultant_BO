<?php

namespace App\Services\Client\Integration;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\FranchiseNetwork\FranchiseNetworkRepository;

class ClientIntegrationService
{

    private FranchiseNetworkRepository $franchiseNetworkRepository;
    private ClientRepository $clientRepository;

    public function __construct(
        FranchiseNetworkRepository $franchiseNetworkRepository,
        ClientRepository $clientRepository
    )
    {
        $this->franchiseNetworkRepository = $franchiseNetworkRepository;
        $this->clientRepository = $clientRepository;
    }

    public function activateIntegration(int $idClient, string $url)
    {
        // 2) GET JSON
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ProdIntegrator/1.0'
            ]
        ]);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new CustomException("API_ERROR", 'errors', 500);
        }

        $http = (int)($info['http_code'] ?? 0);
        if ($http !== 200 && !$body) {
            throw new CustomException("INTERNAL", 'errors', $http);
        }

        if ($http !== 200) {
            $data = json_decode($body, true);
            throw new CustomException("API_ERROR: " . $data['description'], 'errors', 500);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new CustomException("MISSING_DATA", 'errors', 404);
        }

        // --- 2) walidacje z FRZ
        $shopUid = $data['shop_uid'] ?? $data['franchisee_uid'] ?? null;
        $secret = $data['secret'] ?? null;
        $baseUrl = $data['webhooks_base_url'] ?? null; // NOWE

        if (!$shopUid || !$secret) {
            throw new CustomException("MISSING_DATA", 'errors', 404);
        }
        if (!ctype_xdigit($secret) || strlen($secret) !== 64) {
            throw new CustomException("WRONG_SECRET", 'errors', 400);
        }
//        if ($baseUrl && !preg_match('#^https://#i', $baseUrl)) {
//            throw new CustomException("BAD_WEBHOOKS_BASE_URL", 'errors', 400);
//        }

        // --- 3) wczytaj istniejące credentials z tabeli customers
        $client = $this->clientRepository->first(['id' => $idClient]); // musi zwrócić m.in. kolumnę 'credentials'
        $creds = [];
        if (!empty($client['credentials'])) {
            $tmp = json_decode($client['credentials'], true);
            if (is_array($tmp)) $creds = $tmp;
        }

        // --- 4) zmerge’uj nowe pola do JSON-a
        $creds['secret'] = $secret;
        if ($baseUrl) {
            $creds['webhooks_base_url'] = rtrim($baseUrl, '/');
        }

        // --- 5) zapisz: franchisee_uid + credentials JSON + status + znacznik czasu
        $saved = $this->clientRepository->update($idClient, [
            'franchisee_uid' => $shopUid,
            'credentials' => json_encode($creds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$saved) {
            throw new CustomException("UPDATE_ERROR", 'errors', 500);
        }

        return [
            'ok' => true,
            'shop_uid' => $shopUid,
            'webhooks_base_url' => $creds['webhooks_base_url'] ?? null,
            'code' => 200,
        ];
    }

}