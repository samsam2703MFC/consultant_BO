#!/usr/bin/env php
<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
require_once __DIR__ . '/../public/vendor/autoload.php';

use Dotenv\Dotenv;
use Aws\S3\S3Client;

// ─── Config ─────────────────────────────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(__DIR__ . '/../', null, false);
$dotenv->load();

const BATCH_SIZE = 50;         // ile rekordów na przebieg
const MAX_ATTEMPTS = 6;          // po tylu próbach oznaczamy jako "dead"
const DEFAULT_BACKOFF = [5, 30, 120, 600, 1800, 7200]; // sek: 5s, 30s, 2m, 10m, 30m, 2h

$workerId = 'outbox-worker-' . getmypid();

// ─── Infra init ─────────────────────────────────────────────────────────────────
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$s3 = new S3Client([
    'version' => 'latest',
    'region' => $_ENV['AWS_REGION'],
    'credentials' => [
        'key' => $_ENV['AWS_ACCESS_KEY_ID'],
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
    ],
]);

// Tu podepnij swoje serwisy z aplikacji (przykład – zmień na własne):
$proformaService = app_service('App\Services\Document\ProformaService');
$proformaRepo = app_service('App\Repositories\Document\Proforma\ProformaRepository');
$complaintService = app_service('App\Services\Client\Complaint\ComplaintService');

// ─── Run one batch and exit (idealne pod Task Scheduler) ────────────────────────
$jobs = lockBatch($pdo, BATCH_SIZE, $workerId);
if (!$jobs) {
//    logMsg("No jobs to XD.");
    exit(0);
}

foreach ($jobs as $job) {
    try {
        processJob($job);
        markDone($pdo, (int)$job['id']);
    } catch (Throwable $e) {
        scheduleRetry($pdo, $job, $e);
    }
}
exit(0);

// ─── Logic ─────────────────────────────────────────────────────────────────────

function processJob(array $job): void
{
    global $s3, $proformaService, $proformaRepo, $complaintService;

    $type = $job['type'];
    $payload = json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);

    switch ($type) {
        case 'proforma.upload':
        {
            // 1) wygeneruj bytes PDF
            $uuid = $payload['proforma_uuid'];
            $tenant = $payload['franchisee_code'];
            $orderKey = $payload['order_key'];
            $s3Key = $payload['s3_key'];

            $pdfBytes = $proformaService->generateDocumentPDFBytesByUuid($uuid)['file_string'];
            if (strncmp($pdfBytes, '%PDF', 4) !== 0) {
                $trimmed = ltrim($pdfBytes);
                if (strncmp($trimmed, '%PDF', 4) === 0) {
                    $pdfBytes = $trimmed;
                }
            }

            $sha256Hex = hash('sha256', $pdfBytes);
            $contentMd5 = base64_encode(md5($pdfBytes, true));
            $tags = http_build_query(['tenant' => $tenant, 'order_key' => $orderKey]);

            // 2) upload do S3
            $bucket = $_ENV['AWS_S3_BUCKET'];
            try {
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $s3Key,
                    'Body' => $pdfBytes,
                    'ContentType' => 'application/pdf',
                    'ContentMD5' => $contentMd5,
                    'ServerSideEncryption' => 'AES256',
                    'Tagging' => $tags,
                ]);
            } catch (\Aws\Exception\AwsException $e) {
                logMsg('S3 error: ' . $e->getAwsErrorMessage());
                throw $e;
            }

            // 3) zapisz metadane PDF w DB
            $proformaRepo->update($uuid, [
                's3_bucket' => $bucket,
                's3_key' => $s3Key,
                'checksum_sha256' => $sha256Hex,
                'size_bytes' => strlen($pdfBytes),
            ], 'uuid');

            // 4) dodaj następne zadanie: notify
            addJobOutbox('proforma.notify', $payload + [
                    'checksum_sha256' => $sha256Hex,
                    'size_bytes' => strlen($pdfBytes),
                ], $payload['order_key'] . '|' . $uuid . '|notify');

            logMsg("Uploaded proforma to S3: $s3Key, sha256=$sha256Hex");
            break;
        }

        case 'proforma.notify':
        {
            $payload = array_change_key_case($payload, CASE_LOWER);

            $body = json_encode([
                'shop_uid' => $payload['franchisee_uid'],
                'franchisee_code' => $payload['franchisee_code'],
                'order_key' => $payload['order_key'],
                'proforma_uuid' => $payload['proforma_uuid'],
                's3_bucket' => $_ENV['AWS_S3_BUCKET'],
                's3_key' => $payload['s3_key'],
                'checksum_sha256' => $payload['checksum_sha256'],
                'size_bytes' => $payload['size_bytes'],
                'issued_at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $sig = hash_hmac('sha256', $body, hex2bin($payload['secret']));

            $headers = [
                'Idempotency-Key: ' . $payload['order_key'] . '|' . $payload['proforma_uuid'],
                'X-Shop-Uid: ' . $payload['franchisee_uid'],
                'X-Signature: ' . $sig
            ];

            $ok = httpPostJson($payload['callback_url'], $body, $headers);
            if (!$ok) {
                throw new RuntimeException('callback_failed');
            } else {
                // statusy w Twoich tabelach
                $proformaRepo->markNotified($payload['proforma_uuid']);
                logMsg("Notified franchise for order " . $payload['order_key'] . " proforma " . $payload['proforma_uuid']);
            }

            break;
        }

        case 'complaint.update':
        {
            $payload = array_change_key_case($payload, CASE_LOWER);

            $body = json_encode([
                'shop_uid' => $payload['franchisee_uid'],
                'complaint_key' => $payload['complaint_key'],
                'compensation_method' => $payload['compensation_method'],
                'acceptance_status' => $payload['acceptance_status'],
                'production_response' => $payload['production_response'],
                'closed_on' => $payload['closed_on'],
                'franchisee_uid' => $payload['franchisee_uid']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $sig = hash_hmac('sha256', $body, hex2bin($payload['secret']));

            $headers = [
                'Idempotency-Key: ' . $payload['complaint_key'] . '|' . $payload['proforma_uuid'],
                'X-Shop-Uid: ' . $payload['franchisee_uid'],
                'X-Signature: ' . $sig
            ];

            $ok = httpPostJson($payload['callback_url'], $body, $headers);
            if (!$ok) {
                throw new RuntimeException('callback_failed');
            } else {
                // statusy w Twoich tabelach
                $complaintService->markNotified($payload['complaint_key']);
                logMsg("Notified franchise for complaint " . $payload['order_key']);
            }

            break;
        }

        default:
            throw new RuntimeException('unknown job type: ' . $type);
    }
}

// ─── Outbox DB helpers ─────────────────────────────────────────────────────────

function lockBatch(PDO $pdo, int $limit, string $workerId): array
{
    // 1) Zajmij paczkę zadań atomowo
    $stmt = $pdo->prepare("
        UPDATE outbox
           SET status='processing',
               locked_at=NOW(),
               locked_by=:w
         WHERE status='pending'
           AND next_try_at <= NOW()
         ORDER BY next_try_at ASC, id ASC
         LIMIT :lim
    ");
    $stmt->bindValue(':w', $workerId, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    // 2) Pobierz właśnie zajęte
    $stmt = $pdo->prepare("
        SELECT id, type, payload_json, attempts
          FROM outbox
         WHERE status='processing' AND locked_by=:w
         ORDER BY locked_at ASC, id ASC
         LIMIT :lim
    ");
    $stmt->bindValue(':w', $workerId, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function markDone(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("UPDATE outbox SET status='done', locked_at=NULL, locked_by=NULL, updated_at=NOW() WHERE id=?");
    $stmt->execute([$id]);
}

function scheduleRetry(PDO $pdo, array $job, Throwable $e): void
{
    $attempts = (int)$job['attempts'] + 1;
    $next = backoffNextTry($attempts);

    $stmt = $pdo->prepare("
        UPDATE outbox
           SET status='pending',
               attempts=:a,
               next_try_at=:n,
               last_error=:err,
               locked_at=NULL,
               locked_by=NULL,
               updated_at=NOW()
         WHERE id=:id
    ");
    $stmt->execute([
        ':a' => $attempts,
        ':n' => $next,
        ':err' => mb_substr($e->getMessage(), 0, 1000),
        ':id' => $job['id'],
    ]);

    if ($attempts >= MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE outbox SET status='dead' WHERE id=?")->execute([$job['id']]);
        logMsg("Job {$job['id']} DEAD after $attempts attempts: " . $e->getMessage());
    } else {
        logMsg("Job {$job['id']} retry #$attempts at $next: " . $e->getMessage());
    }
}

function addJobOutbox(string $type, array $payload, string $dedupKey = null): void
{
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO outbox (type, payload_json, dedup_key)
        VALUES (:t, :p, :d)
        ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)
    ");
    $stmt->execute([
        ':t' => $type,
        ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':d' => $dedupKey,
    ]);
}

// ─── Utils ─────────────────────────────────────────────────────────────────────

function backoffNextTry(int $attempts): string
{
    $steps = DEFAULT_BACKOFF;
    $index = min($attempts - 1, count($steps) - 1);
    $sec = $steps[$index];
    $jitter = random_int(0, (int)round($sec * 0.2));
    return date('Y-m-d H:i:s', time() + $sec + $jitter);
}

function httpPostJson(string $url, string $jsonBody, array $headers): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
    ]);

    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);

    if ($errNo !== 0) {
        logMsg("HTTP error: $errMsg");
        return false;
    }
    if (!in_array($httpCode, [200, 201, 202, 204], true)) {
        logMsg("HTTP non-2xx ($httpCode): $url" . $result);
        return false;
    }
    return true;
}

function logMsg(string $msg): void
{
    global $workerId;
    file_put_contents(__DIR__ . '/outbox_worker.log',
        date('Y-m-d H:i:s') . " [$workerId] $msg\n", FILE_APPEND);
}

/**
 * Zamiennik DI: podłącz swoje serwisy z aplikacji.
 * Np. jeśli używasz własnego kontenera, pobierz z niego.
 * Tymczasowo można zaimplementować klasy proxy korzystające z Twoich istniejących serwisów.
 */
function app_service(string $name)
{
    static $container;

    if ($container === null) {
        $container = require __DIR__ . '/../App/Core/bootstrap.php';
    }

    if (!$container->has($name)) {
        throw new RuntimeException("Serwis $name nie jest zarejestrowany w kontenerze.");
    }

    return $container->get($name);
}
