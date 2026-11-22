<?php
declare(strict_types=1);

namespace App\Repositories\Mail;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Poczta od klientów » [383] : lecture
 *  - de la boîte Poczta d'une personne (n_user_mailbox, migration 071 : email, display_name, last_sync_at, last_error —
 *    JAMAIS les colonnes imap_* / smtp_* ni les mots de passe chiffrés imap_secret / smtp_secret) ;
 *  - des e-mails de clients reçus (n_user_mail : folder 'INBOX', id_customer non nul — reconnu à la synchronisation par
 *    UserMailRepository::contact —, sent_at, seen, answered, message_id ; from_email, from_name, subject et customer.company
 *    comme la liste de Poczta, UserMailRepository::list ; jamais body_text ni snippet) ;
 *  - des réponses envoyées dans le fil : n_user_mail_sent (id_reply_to, status 'sent', mode, created_at — dziennik « Wysłane
 *    z ERP » écrit par UserMailService::send) et n_user_mail du dossier 'SENT' de la même boîte (in_reply_to = message_id).
 * Une source dont la table manque (base partielle) est rendue vide. N'écrit que n_customer_mail_check.
 */
final class CustomerMailCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_customer_mail_check';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Boîte de la personne, sans aucun réglage de connexion ni mot de passe ; null = pas de boîte. */
    public function mailbox(int $userId): ?array
    {
        if (!$this->hasTable('n_user_mailbox')) return null;
        return $this->all('SELECT id_user, email, display_name, last_sync_at, last_error FROM n_user_mailbox WHERE id_user = ?', [$userId])[0] ?? null;
    }

    /** E-mails de clients reçus dans la boîte depuis $since, du plus ancien au plus récent. */
    public function customerInbox(int $userId, string $since): array
    {
        if (!$this->hasTable('n_user_mail')) return [];
        return $this->all("SELECT m.id, m.sent_at, m.seen, m.answered, m.message_id, m.from_email, m.from_name, m.subject, m.id_customer, c.company AS customer_name
            FROM n_user_mail m LEFT JOIN customer c ON c.id = m.id_customer
            WHERE m.id_user = ? AND m.folder = 'INBOX' AND m.id_customer IS NOT NULL AND m.sent_at >= ?
            ORDER BY m.sent_at, m.id", [$userId, $since]);
    }

    /** Réponses envoyées depuis Poczta (« Wysłane z ERP ») aux messages donnés : id_reply_to => liste [id, created_at, mode]. */
    public function erpReplies(int $userId, array $mailIds): array
    {
        $mailIds = array_values(array_unique(array_map('intval', $mailIds)));
        if (!$mailIds || !$this->hasTable('n_user_mail_sent')) return [];
        $out = [];
        foreach ($this->all("SELECT id, id_reply_to, created_at, mode FROM n_user_mail_sent WHERE id_user = ? AND status = 'sent' AND id_reply_to IN ("
            . implode(', ', array_fill(0, count($mailIds), '?')) . ') ORDER BY created_at, id', array_merge([$userId], $mailIds)) as $r) {
            $out[(int) $r['id_reply_to']][] = $r;
        }
        return $out;
    }

    /** Réponses du dossier Wysłane de la même boîte (synchronisé) : in_reply_to => liste [id, sent_at]. */
    public function sentReplies(int $userId, array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('strval', $messageIds), fn($m) => $m !== '')));
        if (!$messageIds || !$this->hasTable('n_user_mail')) return [];
        $out = [];
        foreach ($this->all("SELECT id, in_reply_to, sent_at FROM n_user_mail WHERE id_user = ? AND folder = 'SENT' AND in_reply_to IN ("
            . implode(', ', array_fill(0, count($messageIds), '?')) . ') ORDER BY sent_at, id', array_merge([$userId], $messageIds)) as $r) {
            $out[(string) $r['in_reply_to']][] = $r;
        }
        return $out;
    }

    /** Décisions de la boîte : id_mail => décision, avec l'auteur, la personne et le poste (« Przekazane »). */
    public function decisions(int $mailboxUser): array
    {
        $out = [];
        foreach ($this->all("SELECT c.id_mail, c.checked_on, c.decision, c.note, c.reply_source, c.id_reply, c.id_responsible, c.id_position, c.flags, c.updated_at,
                COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS decided_by, COALESCE(NULLIF(TRIM(r.displayed_name), ''), r.login) AS responsible_name, p.name_pl AS position_name
            FROM n_customer_mail_check c LEFT JOIN user u ON u.id = c.id_user LEFT JOIN user r ON r.id = c.id_responsible LEFT JOIN n_position p ON p.id = c.id_position
            WHERE c.id_mailbox = ?", [$mailboxUser]) as $r) {
            $out[(int) $r['id_mail']] = $r;
        }
        return $out;
    }

    /** Postes actifs (« Przekazane » : poste facultatif, comme l'assistant OTIF). @return list<array{id:int, name:string}> */
    public function positions(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name_pl']],
            $this->all('SELECT id, name_pl FROM n_position WHERE active = 1 ORDER BY sort_order, name_pl'));
    }

    /** Une décision par message : la dernière enregistrée remplace la précédente. */
    public function saveDecision(int $mailId, int $mailboxUser, string $checkedOn, string $decision, ?string $note, ?string $replySource, ?int $replyId,
        ?int $responsible, ?int $position, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_customer_mail_check (id_mail, id_mailbox, checked_on, decision, note, reply_source, id_reply, id_responsible, id_position, flags, id_user)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id_mailbox = VALUES(id_mailbox), checked_on = VALUES(checked_on), decision = VALUES(decision), note = VALUES(note),
                reply_source = VALUES(reply_source), id_reply = VALUES(id_reply), id_responsible = VALUES(id_responsible), id_position = VALUES(id_position),
                flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$mailId, $mailboxUser, $checkedOn, $decision, $note, $replySource, $replyId, $responsible, $position, mb_substr($flags, 0, 255), $userId]);
    }
}
