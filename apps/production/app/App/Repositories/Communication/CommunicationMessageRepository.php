<?php
namespace App\Repositories\Communication;

use App\Core\Helpers\BaseRepository;

class CommunicationMessageRepository extends BaseRepository
{
    protected string $table = 'communication_message';
    protected $allowedColumns = ['message_type','template_id','template_version','id_customer','id_difference','id_wdt','id_proforma','id_invoice','id_email_account','recipient_email','sender_email','reply_to_email','subject_text','body_text','variables_json','was_edited','status','provider_message_id','message_id_header','error_message','created_by','queued_at','locked_at','locked_by','attempts','next_try_at','sent_at'];

    public function recent(): array
    {
        return $this->query('SELECT m.*, c.company FROM communication_message m LEFT JOIN customer c ON c.id = m.id_customer ORDER BY m.created_at DESC LIMIT 100');
    }

    /**
     * Przypomnienia proform już wysłane (lub w kolejce) : id proformy => [level, last_at].
     * proforma_reminder_N = poziom N ; dawne proforma_payment_reminder = 1, proforma_payment_reminder_final = 5.
     */
    public function proformaReminderLevels(array $proformaIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $proformaIds)));
        if (!$ids) return [];
        $rows = $this->query("SELECT id_proforma, message_type, MAX(created_at) AS last_at FROM communication_message
                              WHERE id_proforma IN (" . implode(',', $ids) . ") AND (message_type LIKE 'proforma\\_reminder\\_%' OR message_type LIKE 'proforma\\_payment\\_reminder%')
                                AND status NOT IN ('failed', 'cancelled', 'draft')
                              GROUP BY id_proforma, message_type") ?: [];
        $out = [];
        foreach ($rows as $r) {
            $level = match (true) {
                (bool) preg_match('/^proforma_reminder_([1-5])$/', $r['message_type'], $m) => (int) $m[1],
                $r['message_type'] === 'proforma_payment_reminder_final' => 5,
                default => 1,
            };
            $id = (int) $r['id_proforma'];
            if (!isset($out[$id]) || $level > $out[$id]['level']) $out[$id] = ['level' => $level, 'last_at' => $r['last_at']];
        }
        return $out;
    }

    public function find(int $id): array
    {
        $rows = $this->query('SELECT m.*, c.company FROM communication_message m LEFT JOIN customer c ON c.id = m.id_customer WHERE m.id = :id LIMIT 1', ['id' => $id]);
        return $rows[0] ?? [];
    }

    public function findIdByHeader(string $messageId): ?int
    {
        $rows = $this->query('SELECT id FROM communication_message WHERE message_id_header = :header LIMIT 1', ['header' => $messageId]);
        return isset($rows[0]['id']) ? (int)$rows[0]['id'] : null;
    }

    public function queue(int $id): bool
    {
        return $this->executeStatement(
            "UPDATE communication_message SET status = 'queued', queued_at = NOW(), next_try_at = NOW(), error_message = NULL, locked_at = NULL, locked_by = NULL WHERE id = :id AND status = 'draft'",
            ['id' => $id]
        );
    }

    /** Atomically reserves one due message for a worker. */
    public function claimNext(string $workerId): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM communication_message WHERE status = 'queued' AND next_try_at <= NOW() ORDER BY next_try_at, id LIMIT 1 FOR UPDATE");
            $stmt->execute();
            $message = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            if ($message === []) {
                $this->db->commit();
                return [];
            }
            $update = $this->db->prepare("UPDATE communication_message SET status = 'processing', locked_at = NOW(), locked_by = :worker, attempts = attempts + 1 WHERE id = :id");
            $update->execute(['worker' => $workerId, 'id' => $message['id']]);
            $message['attempts'] = (int)$message['attempts'] + 1;
            $message['status'] = 'processing';
            $this->db->commit();
            return $message;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Atomically reserves one specific queued message for an immediate delivery attempt. */
    public function claimById(int $id, string $workerId): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM communication_message WHERE id = :id AND status = 'queued' LIMIT 1 FOR UPDATE");
            $stmt->execute(['id' => $id]);
            $message = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            if ($message === []) {
                $this->db->commit();
                return [];
            }
            $update = $this->db->prepare("UPDATE communication_message SET status = 'processing', locked_at = NOW(), locked_by = :worker, attempts = attempts + 1 WHERE id = :id");
            $update->execute(['worker' => $workerId, 'id' => $id]);
            $message['attempts'] = (int)$message['attempts'] + 1;
            $message['status'] = 'processing';
            $this->db->commit();
            return $message;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function markSent(int $id, string $providerMessageId): void
    {
        $this->executeStatement("UPDATE communication_message SET status = 'sent', provider_message_id = :provider, sent_at = NOW(), error_message = NULL, locked_at = NULL, locked_by = NULL WHERE id = :id", ['id' => $id, 'provider' => $providerMessageId]);
    }

    public function scheduleRetry(array $message, string $error, int $delaySeconds, bool $final): void
    {
        $this->executeStatement(
            "UPDATE communication_message SET status = :status, error_message = :error, next_try_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), locked_at = NULL, locked_by = NULL WHERE id = :id",
            ['id' => $message['id'], 'status' => $final ? 'failed' : 'queued', 'error' => mb_substr($error, 0, 65000), 'delay' => $delaySeconds]
        );
    }

    public function recordAttempt(int $messageId, int $attempt, string $status, ?string $providerId, ?string $error, string $startedAt): void
    {
        $this->executeStatement(
            'INSERT INTO communication_message_attempt (id_message, attempt_no, status, provider_message_id, error_message, started_at, finished_at) VALUES (:message, :attempt, :status, :provider, :error, :started, NOW())',
            ['message' => $messageId, 'attempt' => $attempt, 'status' => $status, 'provider' => $providerId, 'error' => $error, 'started' => $startedAt]
        );
    }

    public function resetStaleLocks(): void
    {
        $this->executeStatement("UPDATE communication_message SET status = 'queued', locked_at = NULL, locked_by = NULL WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    }
}
