<?php
declare(strict_types=1);

namespace App\Repositories\Mail;

use App\Repositories\Workshop\WorkshopRepository;

/** Skrzynka użytkownika (n_user_mailbox), wiadomości ze skrzynki (n_user_mail) i wysłane z ERP (n_user_mail_sent). */
final class UserMailRepository extends WorkshopRepository
{
    protected string $table = 'n_user_mail';

    public const MAILBOX_FIELDS = ['email', 'display_name', 'provider', 'imap_host', 'imap_port', 'imap_security', 'imap_username', 'imap_secret',
        'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_secret', 'tls_verify', 'signature', 'sent_folder', 'last_sync_at', 'last_error'];
    private const MAIL_FIELDS = ['id_user', 'folder', 'uid_validity', 'uid', 'message_id', 'in_reply_to', 'from_email', 'from_name', 'to_list', 'cc_list',
        'subject', 'sent_at', 'size', 'seen', 'answered', 'flagged', 'has_attachments', 'snippet', 'body_text', 'id_customer', 'id_supplier'];

    public function mailbox(int $userId): ?array
    {
        return $this->all('SELECT * FROM n_user_mailbox WHERE id_user = ?', [$userId])[0] ?? null;
    }

    public function saveMailbox(int $userId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::MAILBOX_FIELDS));
        $cols = array_keys($data);
        $sql = 'INSERT INTO n_user_mailbox (id_user, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')'
            . ($cols ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)) : '');
        $this->db->prepare($sql)->execute(array_merge([$userId], array_values($data)));
    }

    /** Zmiana kilku pól istniejącej skrzynki (stan synchronizacji, folder wysłanych). */
    public function updateMailbox(int $userId, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::MAILBOX_FIELDS));
        if (!$fields) return;
        $this->db->prepare('UPDATE n_user_mailbox SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($fields))) . ' WHERE id_user = ?')
            ->execute(array_merge(array_values($fields), [$userId]));
    }

    public function deleteMailbox(int $userId): void
    {
        $this->db->prepare('DELETE FROM n_user_mail WHERE id_user = ?')->execute([$userId]);
        $this->db->prepare('DELETE FROM n_user_mailbox WHERE id_user = ?')->execute([$userId]);
    }

    /** Najwyższy znany UID folderu przy tym UIDVALIDITY (0 = nic) ; inny UIDVALIDITY = folder do pobrania od nowa. */
    public function maxUid(int $userId, string $folder, int $uidValidity): int
    {
        $other = $this->all('SELECT COUNT(*) AS n FROM n_user_mail WHERE id_user = ? AND folder = ? AND uid_validity <> ?', [$userId, $folder, $uidValidity])[0]['n'] ?? 0;
        if ((int) $other > 0) $this->db->prepare('DELETE FROM n_user_mail WHERE id_user = ? AND folder = ? AND uid_validity <> ?')->execute([$userId, $folder, $uidValidity]);
        return (int) ($this->all('SELECT COALESCE(MAX(uid), 0) AS m FROM n_user_mail WHERE id_user = ? AND folder = ? AND uid_validity = ?', [$userId, $folder, $uidValidity])[0]['m'] ?? 0);
    }

    public function insertMail(array $row): void
    {
        $row = array_intersect_key($row, array_flip(self::MAIL_FIELDS));
        $cols = array_keys($row);
        $this->db->prepare('INSERT IGNORE INTO n_user_mail (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')
            ->execute(array_values($row));
    }

    public function updateMail(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::MAIL_FIELDS));
        if (!$fields) return;
        $this->db->prepare('UPDATE n_user_mail SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($fields))) . ' WHERE id = ?')
            ->execute(array_merge(array_values($fields), [$id]));
    }

    /** Flagi z serwera (przeczytana, z odpowiedzią) dla już znanych wiadomości folderu. */
    public function syncFlags(int $userId, string $folder, array $flagsByUid): void
    {
        $stmt = $this->db->prepare('UPDATE n_user_mail SET seen = ?, answered = ?, flagged = ? WHERE id_user = ? AND folder = ? AND uid = ?');
        foreach ($flagsByUid as $uid => $f) $stmt->execute([(int) $f['seen'], (int) $f['answered'], (int) $f['flagged'], $userId, $folder, $uid]);
    }

    /** Wiadomości usunięte ze skrzynki znikają też z ERP. */
    public function forgetMissing(int $userId, string $folder, int $uidValidity, int $fromUid, array $presentUids): int
    {
        $known = array_map('intval', array_column($this->all('SELECT uid FROM n_user_mail WHERE id_user = ? AND folder = ? AND uid_validity = ? AND uid >= ?',
            [$userId, $folder, $uidValidity, $fromUid]), 'uid'));
        $gone = array_values(array_diff($known, $presentUids));
        if (!$gone) return 0;
        $this->db->prepare('DELETE FROM n_user_mail WHERE id_user = ? AND folder = ? AND uid_validity = ? AND uid IN (' . implode(',', $gone) . ')')
            ->execute([$userId, $folder, $uidValidity]);
        return count($gone);
    }

    /** Lista folderu, najnowsze najpierw ; q = wyszukiwanie pełnotekstowe (temat, nadawca, adresaci, treść). */
    public function list(int $userId, string $folder, string $q, int $offset, int $limit): array
    {
        [$where, $params] = $this->searchWhere($q);
        $rows = $this->all("SELECT m.id, m.folder, m.uid, m.from_email, m.from_name, m.to_list, m.subject, m.sent_at, m.seen, m.answered, m.flagged,
                                   m.has_attachments, m.snippet, m.id_customer, m.id_supplier, c.company AS customer_name, s.name AS supplier_name
                            FROM n_user_mail m
                            LEFT JOIN customer c ON c.id = m.id_customer
                            LEFT JOIN supplier s ON s.id = m.id_supplier
                            WHERE m.id_user = ? AND m.folder = ? {$where}
                            ORDER BY m.sent_at DESC, m.uid DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            array_merge([$userId, $folder], $params));
        $total = (int) ($this->all("SELECT COUNT(*) AS n FROM n_user_mail m WHERE m.id_user = ? AND m.folder = ? {$where}", array_merge([$userId, $folder], $params))[0]['n'] ?? 0);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Słowa od 3 znaków : indeks pełnotekstowy (każde słowo wymagane, początek słowa) ; krótsze : LIKE.
     * @return array{0:string, 1:list<string>}
     */
    private function searchWhere(string $q, string $alias = 'm', array $columns = ['subject', 'from_name', 'from_email', 'to_list', 'body_text'], array $likeColumns = ['subject', 'from_email', 'from_name']): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [], fn($w) => mb_strlen($w) >= 3));
        if ($words) {
            $cols = implode(', ', array_map(fn($c) => "{$alias}.{$c}", $columns));
            return [" AND MATCH({$cols}) AGAINST (? IN BOOLEAN MODE)", [implode(' ', array_map(fn($w) => '+' . $w . '*', $words))]];
        }
        if (trim($q) === '') return ['', []];
        $like = '%' . addcslashes(trim($q), '\\%_') . '%';
        return [' AND (' . implode(' OR ', array_map(fn($c) => "{$alias}.{$c} LIKE ?", $likeColumns)) . ')', array_fill(0, count($likeColumns), $like)];
    }

    public function mail(int $userId, int $id): ?array
    {
        return $this->all('SELECT m.*, c.company AS customer_name, s.name AS supplier_name FROM n_user_mail m
                           LEFT JOIN customer c ON c.id = m.id_customer LEFT JOIN supplier s ON s.id = m.id_supplier
                           WHERE m.id = ? AND m.id_user = ?', [$id, $userId])[0] ?? null;
    }

    public function unread(int $userId): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_user_mail WHERE id_user = ? AND folder = 'INBOX' AND seen = 0", [$userId])[0]['n'] ?? 0);
    }

    /** @return array<string, int> folder => nieprzeczytane */
    public function folderCounts(int $userId): array
    {
        $out = [];
        foreach ($this->all('SELECT folder, SUM(seen = 0) AS unread FROM n_user_mail WHERE id_user = ? GROUP BY folder', [$userId]) as $r) $out[$r['folder']] = (int) $r['unread'];
        return $out;
    }

    public function logSent(array $row): int
    {
        $cols = array_keys($row);
        $this->db->prepare('INSERT INTO n_user_mail_sent (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($row));
        return (int) $this->db->lastInsertId();
    }

    public function sentList(int $userId, string $q, int $offset, int $limit): array
    {
        [$where, $params] = $this->searchWhere($q, 's', ['subject', 'to_list', 'body_text'], ['subject', 'to_list']);
        $rows = $this->all("SELECT s.id, s.to_list, s.cc_list, s.subject, s.body_text, s.attachments, s.mode, s.status, s.error, s.created_at, s.id_customer, c.company AS customer_name
                            FROM n_user_mail_sent s LEFT JOIN customer c ON c.id = s.id_customer
                            WHERE s.id_user = ? {$where} ORDER BY s.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset, array_merge([$userId], $params));
        $total = (int) ($this->all("SELECT COUNT(*) AS n FROM n_user_mail_sent s WHERE s.id_user = ? {$where}", array_merge([$userId], $params))[0]['n'] ?? 0);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Klient i dostawca po adresie e-mail (karta klienta : e-mail szefa, handlowca, księgowości ; dostawca). */
    public function contact(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') return ['id_customer' => null, 'id_supplier' => null];
        $like = '%' . addcslashes($email, '\\%_') . '%';
        $customer = $this->all('SELECT id FROM customer WHERE LOWER(email_head) LIKE ? OR LOWER(email_salesman) LIKE ? OR LOWER(email_accountant) LIKE ?
                                ORDER BY active DESC, id DESC LIMIT 1', [$like, $like, $like])[0]['id'] ?? null;
        $supplier = $this->all('SELECT id FROM supplier WHERE LOWER(email) LIKE ? ORDER BY is_active DESC, id DESC LIMIT 1', [$like])[0]['id'] ?? null;
        return ['id_customer' => $customer !== null ? (int) $customer : null, 'id_supplier' => $supplier !== null ? (int) $supplier : null];
    }

    /** Wyszukiwarka z górnego paska : wiadomości użytkownika (odebrane i wysłane ze skrzynki). */
    public function search(int $userId, string $q, int $limit): array
    {
        [$where, $params] = $this->searchWhere($q);
        return $this->all("SELECT m.id, m.folder, m.subject, m.from_email, m.from_name, m.to_list, m.sent_at FROM n_user_mail m
                           WHERE m.id_user = ? {$where} ORDER BY m.sent_at DESC LIMIT " . (int) $limit, array_merge([$userId], $params));
    }
}
