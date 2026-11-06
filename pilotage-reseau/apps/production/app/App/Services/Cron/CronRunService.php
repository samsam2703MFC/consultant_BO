<?php
declare(strict_types=1);

namespace App\Services\Cron;

use PDO;
use Throwable;

/**
 * Persists the outcome of a CLI job and queues an independent Slack notification.
 */
final class CronRunService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param callable():void $task
     * @param array<string, scalar|null> $context
     */
    public function run(string $jobName, callable $task, array $context = [], bool $queueNotification = true): int
    {
        $startedAt = microtime(true);
        $context += [
            'host' => gethostname() ?: 'unknown',
            'sapi' => PHP_SAPI,
            'pid' => getmypid(),
        ];

        $insert = $this->pdo->prepare(
            'INSERT INTO cron_runs (job_name, status, started_at, context_json) VALUES (:job_name, \'running\', NOW(), :context_json)'
        );
        $insert->execute([
            ':job_name' => $jobName,
            ':context_json' => $this->encodeJson($context),
        ]);
        $runId = (int) $this->pdo->lastInsertId();

        $status = 'success';
        $exitCode = 0;
        $errorMessage = null;

        try {
            $task();
        } catch (CronSkippedException $exception) {
            $status = 'skipped';
            $errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            $status = 'failed';
            $exitCode = 1;
            $errorMessage = $this->formatError($exception);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $update = $this->pdo->prepare(
            'UPDATE cron_runs
             SET status = :status, finished_at = NOW(), duration_ms = :duration_ms,
                 exit_code = :exit_code, error_message = :error_message
             WHERE id = :id'
        );
        $update->execute([
            ':status' => $status,
            ':duration_ms' => $durationMs,
            ':exit_code' => $exitCode,
            ':error_message' => $errorMessage,
            ':id' => $runId,
        ]);

        if ($queueNotification) {
            $this->queueNotification($runId, $jobName, $status, $durationMs, $errorMessage, $context);
        }

        return $exitCode;
    }

    /** @param array<string, scalar|null> $context */
    private function queueNotification(
        int $runId,
        string $jobName,
        string $status,
        int $durationMs,
        ?string $errorMessage,
        array $context
    ): void {
        $payload = [
            'run_id' => $runId,
            'job_name' => $jobName,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
            'host' => $context['host'] ?? null,
            'environment' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'unknown',
        ];

        $statement = $this->pdo->prepare(
            'INSERT INTO cron_notification_outbox (cron_run_id, payload_json, status, next_try_at)
             VALUES (:cron_run_id, :payload_json, \'pending\', NOW())'
        );
        $statement->execute([
            ':cron_run_id' => $runId,
            ':payload_json' => $this->encodeJson($payload),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function formatError(Throwable $exception): string
    {
        return mb_substr(sprintf('%s: %s', get_class($exception), $exception->getMessage()), 0, 4000);
    }
}
