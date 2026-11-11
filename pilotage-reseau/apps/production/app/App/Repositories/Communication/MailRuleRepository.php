<?php
namespace App\Repositories\Communication;

use App\Repositories\Workshop\WorkshopRepository;

/** Règles d'e-mails automatiques (n_mail_rule) et journal de ce qu'elles ont produit. */
final class MailRuleRepository extends WorkshopRepository
{
    protected string $table = 'n_mail_rule';
    protected array $writable = ['recipient_kind', 'fixed_address', 'language', 'attach_document', 'days_before', 'enabled', 'start_ref_id', 'enabled_at', 'updated_by'];

    public function list(): array
    {
        return $this->all(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM n_mail_dispatch d WHERE d.id_rule = r.id AND d.status = 'queued') AS queued_count,
                    (SELECT COUNT(*) FROM n_mail_dispatch d WHERE d.id_rule = r.id AND d.status <> 'queued') AS problem_count,
                    (SELECT MAX(d.created_at) FROM n_mail_dispatch d WHERE d.id_rule = r.id) AS last_dispatch_at
             FROM n_mail_rule r ORDER BY r.sort_order, r.id"
        );
    }

    public function enabled(): array
    {
        return $this->all('SELECT * FROM n_mail_rule WHERE enabled = 1 ORDER BY sort_order, id');
    }

    public function dispatched(int $ruleId, string $refKey): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_mail_dispatch WHERE id_rule = ? AND ref_key = ?', [$ruleId, $refKey]);
    }

    public function recordDispatch(int $ruleId, string $refKey, ?int $messageId, string $status, ?string $reason): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_mail_dispatch (id_rule, ref_key, id_message, status, reason) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ruleId, $refKey, $messageId, $status, $reason === null ? null : mb_substr($reason, 0, 500)]);
    }

    public function findDispatch(int $ruleId, string $refKey): ?array
    {
        return $this->all('SELECT * FROM n_mail_dispatch WHERE id_rule = ? AND ref_key = ?', [$ruleId, $refKey])[0] ?? null;
    }

    public function deleteDispatchKey(int $ruleId, string $refKey): void
    {
        $this->db->prepare('DELETE FROM n_mail_dispatch WHERE id_rule = ? AND ref_key = ?')->execute([$ruleId, $refKey]);
    }

    /**
     * Oublie un échec pour que la règle le retente au prochain passage. Envoi d'une règle « distribution »
     * (clé « cas>destinataire ») : le cas lui-même est aussi oublié, les envois déjà partis restent notés.
     */
    public function forgetDispatch(int $dispatchId): void
    {
        $row = $this->all("SELECT * FROM n_mail_dispatch WHERE id = ? AND status <> 'queued'", [$dispatchId])[0] ?? null;
        if ($row === null) return;
        $this->db->prepare('DELETE FROM n_mail_dispatch WHERE id = ?')->execute([$dispatchId]);
        $case = strstr($row['ref_key'], '>', true);
        if ($case !== false) $this->db->prepare('DELETE FROM n_mail_dispatch WHERE id_rule = ? AND ref_key = ?')->execute([$row['id_rule'], $case]);
    }

    /** Derniers événements ; $hideReason : cas sans envoi à masquer (« ne concerne pas ce client »). */
    public function recentDispatches(int $limit = 50, ?string $hideReason = null): array
    {
        $limit = max(1, min(200, $limit));
        $where = $hideReason === null ? '' : 'WHERE NOT (d.status = \'skipped\' AND d.reason <=> ?)';
        return $this->all(
            "SELECT d.*, r.code AS rule_code, r.event, m.recipient_email, m.subject_text, m.status AS message_status, m.provider_message_id
             FROM n_mail_dispatch d
             JOIN n_mail_rule r ON r.id = d.id_rule
             LEFT JOIN communication_message m ON m.id = d.id_message
             {$where}
             ORDER BY d.id DESC LIMIT {$limit}",
            $hideReason === null ? [] : [$hideReason]
        );
    }
}
