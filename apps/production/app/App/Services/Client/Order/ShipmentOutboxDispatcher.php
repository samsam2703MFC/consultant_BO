<?php
declare(strict_types=1);

namespace App\Services\Client\Order;

use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\ShipmentOutboxRepository;

/**
 * File « Wysyłka → sklep » (webhook_outbox_shipments) : avis « shipment.sent » aux boutiques des franchisés, mis en
 * file par Wyślij dzień et « Oznacz jako wysłane » (OrderFulfilmentService). Chaque ligne est réservée (claim) avant
 * l'envoi : Bin/shipments_retry.php et Bin/mail_dispatch.php peuvent tourner en même temps sans double envoi.
 * Sans clé de boutique valable (copie de test : identifiants effacés), rien ne part : la ligne est reportée.
 */
class ShipmentOutboxDispatcher
{
    /** Reports après échec : 1 min, 5 min, 15 min, 1 h, 6 h, 24 h ; ensuite « failed » (Stan wysyłek › Ponów). */
    public const BACKOFF = [60, 300, 900, 3600, 21600, 86400];

    public function __construct(private ShipmentOutboxRepository $outbox, private ClientRepository $clients)
    {
    }

    /** @return array{sent:int, postponed:int, failed:int} */
    public function dispatchDue(int $limit = 50): array
    {
        $result = ['sent' => 0, 'postponed' => 0, 'failed' => 0];
        foreach ($this->outbox->fetchDue($limit) ?: [] as $row) {
            if (!$this->outbox->claim((int) $row['id'])) continue;   // déjà pris par un autre passage
            try {
                $client = $this->clients->first(['franchisee_uid' => $row['shop_uid']]);
                $credentials = json_decode((string) ($client['credentials'] ?? ''), true);
                $secretHex = is_array($credentials) ? ($credentials['secret'] ?? null) : null;
                if (!is_string($secretHex) || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
                    throw new \RuntimeException('Missing or invalid secret for shop ' . $row['shop_uid']);
                }
                $raw = (string) $row['payload_json'];
                $ch = curl_init((string) $row['url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-Event-ID: ' . $row['event_id'],
                        'X-Event-Type: shipment.sent',
                        'X-Shop-Uid: ' . $row['shop_uid'],
                        'X-Signature: ' . hash_hmac('sha256', $raw, hex2bin($secretHex)),
                    ],
                    CURLOPT_POSTFIELDS => $raw,
                ]);
                curl_exec($ch);
                $err = curl_errno($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errStr = $err ? curl_error($ch) : '';
                curl_close($ch);
                if ($err === 0 && $code >= 200 && $code < 300) {
                    $this->outbox->markSent((int) $row['id']);
                    $result['sent']++;
                    continue;
                }
                $result[$this->retryLater($row, "HTTP {$code} {$errStr}")]++;
            } catch (\Throwable $e) {
                $result[$this->retryLater($row, $e->getMessage())]++;
            }
        }
        return $result;
    }

    private function retryLater(array $row, string $error): string
    {
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= count(self::BACKOFF)) {
            $this->outbox->failPermanently((int) $row['id'], $error);
            return 'failed';
        }
        $this->outbox->postpone((int) $row['id'], $attempts, $error, self::BACKOFF[$attempts - 1]);
        return 'postponed';
    }
}
