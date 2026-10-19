<?php
namespace App\Repositories\Communication;

use App\Repositories\Workshop\WorkshopRepository;

/** Règles d'e-mails automatiques (tfb_mail_rule) et journal de ce qu'elles ont produit. */
final class MailRuleRepository extends WorkshopRepository
{
    protected string $table = 'tfb_mail_rule';
    protected array $writable = ['recipient_kind', 'fixed_address', 'language', 'attach_document', 'days_before', 'enabled', 'start_ref_id', 'enabled_at', 'updated_by'];

    public function list(): array
    {
        return $this->all(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM tfb_mail_dispatch d WHERE d.id_rule = r.id AND d.status = 'queued') AS queued_count,
                    (SELECT COUNT(*) FROM tfb_mail_dispatch d WHERE d.id_rule = r.id AND d.status <> 'queued') AS problem_count,
                    (SELECT MAX(d.created_at) FROM tfb_mail_dispatch d WHERE d.id_rule = r.id) AS last_dispatch_at
             FROM tfb_mail_rule r ORDER BY r.sort_order, r.id"
        );
    }

    public function enabled(): array
    {
        return $this->all('SELECT * FROM tfb_mail_rule WHERE enabled = 1 ORDER BY sort_order, id');
    }

    public function dispatched(int $ruleId, string $refKey): bool
    {
        return (bool) $this->all('SELECT 1 FROM tfb_mail_dispatch WHERE id_rule = ? AND ref_key = ?', [$ruleId, $refKey]);
    }

    public function recordDispatch(int $ruleId, string $refKey, ?int $messageId, string $status, ?string $reason): void
    {
        $this->db->prepare('INSERT IGNORE INTO tfb_mail_dispatch (id_rule, ref_key, id_message, status, reason) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ruleId, $refKey, $messageId, $status, $reason === null ? null : mb_substr($reason, 0, 500)]);
    }

    /** Oublie un échec pour que la règle le retente au prochain passage. */
    public function forgetDispatch(int $dispatchId): void
    {
        $this->db->prepare("DELETE FROM tfb_mail_dispatch WHERE id = ? AND status <> 'queued'")->execute([$dispatchId]);
    }

    public function recentDispatches(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->all(
            "SELECT d.*, r.code AS rule_code, m.recipient_email, m.subject_text, m.status AS message_status, m.provider_message_id
             FROM tfb_mail_dispatch d
             JOIN tfb_mail_rule r ON r.id = d.id_rule
             LEFT JOIN communication_message m ON m.id = d.id_message
             ORDER BY d.id DESC LIMIT {$limit}"
        );
    }
}
