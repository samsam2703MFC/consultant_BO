<?php
declare(strict_types=1);

namespace App\Services\Cron;

use PDO;
use RuntimeException;
use Throwable;

/** Delivers persisted cron-run notifications to a Discord channel webhook. */
final class CronDiscordNotificationWorker
{
    private const MAX_ATTEMPTS = 8;
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 10800, 43200, 86400, 86400];

    public function __construct(private PDO $pdo)
    {
    }

    public function processDue(int $limit = 50): void
    {
        $webhookUrl = $_ENV['DISCORD_CRON_WEBHOOK_URL'] ?? getenv('DISCORD_CRON_WEBHOOK_URL');
        if (!is_string($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Missing or invalid DISCORD_CRON_WEBHOOK_URL.');
        }

        foreach ($this->claimDue($limit) as $notification) {
            try {
                $payload = json_decode($notification['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $this->send($webhookUrl, $payload);
                $this->markSent((int) $notification['id']);
            } catch (Throwable $exception) {
                $this->scheduleRetry($notification, $exception->getMessage());
            }
        }
    }

    /** @return list<array{id: int, payload_json: string, attempts: int}> */
    private function claimDue(int $limit): array
    {
        $limit = max(1, min($limit, 100));
        $workerId = sprintf('cron-discord-%s', getmypid());
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                "SELECT id, payload_json, attempts FROM cron_notification_outbox
                 WHERE status = 'pending' AND next_try_at <= NOW()
                 ORDER BY id ASC LIMIT :limit FOR UPDATE"
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll();

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE cron_notification_outbox SET status = 'processing', locked_at = NOW(), locked_by = ? WHERE id IN ($placeholders)"
                );
                $update->execute(array_merge([$workerId], $ids));
            }

            $this->pdo->commit();
            return $rows;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    private function send(string $webhookUrl, array $payload): void
    {
        $color = ['success' => 3066993, 'failed' => 15158332, 'skipped' => 9807270][$payload['status']] ?? 9807270;
        $description = sprintf(
            "**Status:** %s\n**Czas:** %d ms\n**Host:** %s\n**Środowisko:** %s",
            $payload['status'],
            $payload['duration_ms'],
            $payload['host'] ?? 'unknown',
            $payload['environment'] ?? 'unknown'
        );
        if (!empty($payload['error_message'])) {
            $description .= "\n**Błąd:** ```" . mb_substr((string) $payload['error_message'], 0, 1500) . '```';
        }

        $body = json_encode(['embeds' => [[
            'title' => sprintf('Cron: %s (run #%d)', $payload['job_name'], $payload['run_id']),
            'description' => $description,
            'color' => $color,
            'timestamp' => gmdate('c'),
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $handle = curl_init($webhookUrl);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize Discord HTTP request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('Discord delivery failed (HTTP %d): %s %s', $httpCode, $error, (string) $response));
        }
    }

    private function markSent(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE cron_notification_outbox SET status = 'sent', sent_at = NOW(), locked_at = NULL, locked_by = NULL WHERE id = ?"
        );
        $statement->execute([$id]);
    }

    /** @param array{id: int, attempts: int} $notification */
    private function scheduleRetry(array $notification, string $error): void
    {
        $attempts = (int) $notification['attempts'] + 1;
        $status = $attempts >= self::MAX_ATTEMPTS ? 'dead' : 'pending';
        $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
        $statement = $this->pdo->prepare(
            'UPDATE cron_notification_outbox
             SET status = :status, attempts = :attempts, next_try_at = :next_try_at,
                 last_error = :last_error, locked_at = NULL, locked_by = NULL
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':attempts' => $attempts,
            ':next_try_at' => date('Y-m-d H:i:s', time() + $delay),
            ':last_error' => mb_substr($error, 0, 4000),
            ':id' => $notification['id'],
        ]);
    }
}
