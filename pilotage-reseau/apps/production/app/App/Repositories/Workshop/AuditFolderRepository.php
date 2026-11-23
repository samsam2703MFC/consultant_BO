<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Teczka do audytu i kontroli » [406] : lecture
 *  - des tâches de poste actives qui portent un assistant (n_position_task.auto_code, fréquence) et du dernier « Zakończ »
 *    de chaque assistant (n_wizard_done) ;
 *  - des éléments ouverts : n_mgr_haccp_event (cold_chain_break non traité), n_nc (open / in_progress), complaint
 *    (acceptance_status NEW / IN_REVIEW, vide = NEW — règle de ComplaintsReviewRepository), n_sop (en vigueur, revue dépassée
 *    ou sans date) ;
 *  - du registre de l'assistant (n_audit_folder_item).
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que n_audit_folder_item et
 * n_audit_folder_review.
 */
final class AuditFolderRepository extends WorkshopRepository
{
    protected string $table = 'n_audit_folder_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** @return array<string, list<string>> assistant → fréquences de ses tâches de poste actives */
    public function wizardTasks(array $codes): array
    {
        if (!$codes || !$this->hasTable('n_position_task')) return [];
        $out = [];
        foreach ($this->all('SELECT DISTINCT auto_code, frequency FROM n_position_task WHERE active = 1 AND auto_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', array_values($codes)) as $r) {
            $out[(string) $r['auto_code']][] = (string) $r['frequency'];
        }
        return $out;
    }

    /** @return array<string, array{day:string, summary:?string}> dernier « Zakończ » de chaque assistant (jusqu'à aujourd'hui) */
    public function lastDone(array $codes, string $today): array
    {
        if (!$codes || !$this->hasTable('n_wizard_done')) return [];
        $out = [];
        foreach ($this->all('SELECT d.code, d.day, d.summary FROM n_wizard_done d JOIN (SELECT code, MAX(day) AS day FROM n_wizard_done WHERE day <= ? AND code IN ('
                . implode(',', array_fill(0, count($codes), '?')) . ') GROUP BY code) m ON m.code = d.code AND m.day = d.day', array_merge([$today], array_values($codes))) as $r) {
            $out[(string) $r['code']] = ['day' => substr((string) $r['day'], 0, 10), 'summary' => $r['summary']];
        }
        return $out;
    }

    /** Ruptures de la chaîne du froid non traitées (null : table absente). */
    public function haccpUntreated(): ?int
    {
        if (!$this->hasTable('n_mgr_haccp_event')) return null;
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_mgr_haccp_event WHERE kind = 'cold_chain_break' AND treated = 0")[0]['n'] ?? 0);
    }

    /** Niezgodności ouvertes ou en cours (null : table absente). */
    public function ncOpen(): ?int
    {
        if (!$this->hasTable('n_nc')) return null;
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_nc WHERE status IN ('open', 'in_progress')")[0]['n'] ?? 0);
    }

    /** Réclamations sans décision (null : table absente). */
    public function complaintsOpen(): ?int
    {
        if (!$this->hasTable('complaint')) return null;
        return (int) ($this->all("SELECT COUNT(*) AS n FROM complaint WHERE COALESCE(NULLIF(acceptance_status, ''), 'NEW') IN ('NEW', 'IN_REVIEW')")[0]['n'] ?? 0);
    }

    /** Procédures en vigueur après leur date de revue ou sans date (null : table absente). */
    public function sopLate(string $today): ?array
    {
        if (!$this->hasTable('n_sop')) return null;
        return $this->all("SELECT id, code, title, review_at FROM n_sop WHERE status = 'active' AND (review_at IS NULL OR review_at < ?) ORDER BY review_at IS NULL, review_at, code", [$today]);
    }

    public function items(): array
    {
        return $this->all('SELECT i.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS checked_by_name FROM n_audit_folder_item i LEFT JOIN user u ON u.id = i.checked_by
            WHERE i.active = 1 ORDER BY i.sort_order, i.id');
    }

    public function item(int $id): ?array
    {
        return $this->all('SELECT * FROM n_audit_folder_item WHERE id = ? AND active = 1', [$id])[0] ?? null;
    }

    public function addItem(array $f, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_audit_folder_item (title, area, format, owner, location, check_months, note, sort_order, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(x.sort_order), 0) + 10 FROM n_audit_folder_item x), ?, ?)')
            ->execute([$f['title'], $f['area'], $f['format'], $f['owner'], $f['location'], $f['check_months'], $f['note'], $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function updateItem(int $id, array $f, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_audit_folder_item SET title = ?, area = ?, format = ?, owner = ?, location = ?, check_months = ?, note = ?, updated_by = ? WHERE id = ?')
            ->execute([$f['title'], $f['area'], $f['format'], $f['owner'], $f['location'], $f['check_months'], $f['note'], $userId, $id]);
    }

    public function markChecked(int $id, string $day, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_audit_folder_item SET last_checked = ?, checked_by = ?, updated_by = ? WHERE id = ?')->execute([$day, $userId, $userId, $id]);
    }

    /** Document retiré de la liste (désactivé, jamais supprimé). */
    public function removeItem(int $id, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_audit_folder_item SET active = 0, updated_by = ? WHERE id = ?')->execute([$userId, $id]);
    }

    /** @return array<string, array> décision du trimestre par élément */
    public function decisions(string $quarterStart): array
    {
        return array_column($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
            FROM n_audit_folder_review r LEFT JOIN user u ON u.id = r.id_user WHERE r.quarter_start = ? ORDER BY r.id', [$quarterStart]), null, 'item_key');
    }

    /** Une décision par (trimestre, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $quarterStart, string $key, string $kind, string $decision, ?string $dueOn, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_audit_folder_review (quarter_start, item_key, kind, decision, due_on, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), due_on = VALUES(due_on), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$quarterStart, $key, $kind, $decision, $dueOn, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
