<?php
// bin/shipments_retry.php
use App\Repositories\Client\Order\ShipmentOutboxRepository;
use App\Repositories\Client\ClientRepository;

require __DIR__ . '/../public/vendor/autoload.php';


use Dotenv\Dotenv;

$root = dirname(__DIR__); // katalog projektu
if (file_exists($root.'/.env')) {
    $dotenv = Dotenv::createImmutable($root);
    $dotenv->safeLoad(); // nie wywala, gdy brak .env
}

// autoload + DI
$container = require __DIR__ . '/../App/Core/bootstrap.php';

$outbox = $container->get(ShipmentOutboxRepository::class);
$clients = $container->get(ClientRepository::class);

// prosty backoff: 1m, 5m, 15m, 1h, 6h, 24h
$backoff = [60, 300, 900, 3600, 21600, 86400];
$maxAttempts = count($backoff);

$batch = $outbox->fetchDue(50);


foreach ($batch as $row) {
    if (!$outbox->claim((int)$row['id'])) {
        continue; // ktoś inny zabrał
    }

    try {
        // odczytaj klienta po shop_uid (możesz też trzymać id_client w outboxie, jeśli wygodniej)
        $client = $clients->first(['franchisee_uid' => $row['shop_uid']]);
        $credentials = json_decode($client['credentials'] ?? '', true);
        $secretHex   = $credentials['secret'] ?? null;
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new \RuntimeException('Missing or invalid secret for shop '.$row['shop_uid']);
        }

        $raw = $row['payload_json'];
        $headers = [
            'Content-Type: application/json',
            'X-Event-ID: '.$row['event_id'],
            'X-Event-Type: shipment.sent',
            'X-Shop-Uid: '.$row['shop_uid'],
            'X-Signature: '.hash_hmac('sha256', $raw, hex2bin($secretHex)),
        ];



        $ch = curl_init($row['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $raw,
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errStr = $err ? curl_error($ch) : '';
        curl_close($ch);

        if ($err === 0 && $code >= 200 && $code < 300) {
            $outbox->markSent((int)$row['id']);
            continue;
        }

        // niepowodzenie → backoff
        $attempts = ((int)$row['attempts']) + 1;
        if ($attempts >= $maxAttempts) {
            $outbox->failPermanently((int)$row['id'], "HTTP $code $errStr");
        } else {
            $outbox->postpone((int)$row['id'], $attempts, "HTTP $code $errStr", $backoff[$attempts-1]);
        }
    } catch (\Throwable $e) {
        $attempts = ((int)$row['attempts']) + 1;
        if ($attempts >= $maxAttempts) {
            $outbox->failPermanently((int)$row['id'], $e->getMessage());
        } else {
            $outbox->postpone((int)$row['id'], $attempts, $e->getMessage(), $backoff[$attempts-1]);
        }
    }
}
