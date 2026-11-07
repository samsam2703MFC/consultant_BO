<?php
namespace App\Services\Finances;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;

class PricelistPusher
{

    private ClientProductPriceService $clientProductPriceService;
    private ClientRepository $clientRepository;

    public function __construct(
        ClientProductPriceService $clientProductPriceService,
        ClientRepository $clientRepository
    )
    {
        $this->clientProductPriceService = $clientProductPriceService;
        $this->clientRepository = $clientRepository;
    }

    public function pushPriceList($idClient)
    {
// 1) Klient i podstawowe dane
        $client = $this->clientRepository->first(['id' => $idClient]);
        if (!$client || empty($client['franchisee_uid'])) {
            throw new CustomException("MISSING_CLIENT", 'errors', 404);
        }

        $credentials = json_decode($client['credentials'] ?? '', true);
        $secretHex   = $credentials['secret'] ?? null;
        $baseUrl     = $credentials['webhooks_base_url'] ?? null;
        $lastHash    = $credentials['pricelist_last_hash'] ?? null; // <-- NOWE

        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }
        if (!$baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }


        $currentVersion = (int)($client['pricelist_version'] ?? 0);

        // 2) Zbuduj snapshot (upewnij się, że kolejność jest stabilna!)
        $items = $this->clientProductPriceService->selectProductPriceArrByCustomer($idClient);

        $sendedElements = [];

        foreach ($items as &$it) {
            $sendedElements[] = [
                'name' => $it['name'],
                'price_net' => round((float)$it['price'], 2),
                'sku' => $it['id']
            ];
        }
        usort($sendedElements, fn($a,$b) => strcmp($a['sku'],$b['sku']));     // stabilna kolejność



        $currency = $client['default_currency'] ?: 'PLN';

        // 3) HASH TREŚCI (bez pola version) → decyduje czy bumpować
        $contentRaw  = json_encode(['currency' => $currency, 'items' => $sendedElements],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contentHash = hash('sha256', $contentRaw);

        $version = ($contentHash === $lastHash) ? $currentVersion : $currentVersion + 1;


        $payload = [
            // opcjonalnie, jeśli chcesz "ostatni wygrywa": 'version' => pricelistRepoBumpVersion($clientId),
            'currency' => $client['default_currency'] ?: 'PLN',
            'version'  => $version,
            'items'    => $sendedElements,
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 3) Idempotencja: deterministyczne event_id na bazie RAW body
        $eventId = hash('sha256', $client['franchisee_uid'].'|'.$version);

        // 4) Podpis HMAC (secret w HEX)
        $sig = hash_hmac('sha256', $raw, hex2bin($secretHex));

        // 5) POST
        $url = rtrim($baseUrl, '/') . '/webhooks/material-suppliers/price-list';
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Shop-Uid: '  . $client['franchisee_uid'],
                'X-Event-Id: '  . $eventId,
                'X-Signature: ' . $sig
            ],
            CURLOPT_POSTFIELDS     => $raw,
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== 0 || $code < 200 || $code >= 300) {
            // nie wiadomo, czy doszło → NIE zapisuj wersji
            return false;
        }

        // 5) 2xx – sukces idempotentny: podbij wersję lokalnie
        $resp = json_decode($body, true) ?: [];
        $ackVersion = (int)($resp['last_version'] ?? $version);

        $credentials['pricelist_last_hash'] = $contentHash; // zapamiętaj ostatnią treść
        $this->clientRepository->update($idClient, [
            'credentials' => json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pricelist_version' => $ackVersion
        ]);

        return true;
    }
}