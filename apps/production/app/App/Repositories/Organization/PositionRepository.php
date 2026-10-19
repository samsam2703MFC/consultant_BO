<?php
namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** Postes, tâches récurrentes par poste, poste de chaque utilisateur de l'ERP, occurrences. */
final class PositionRepository extends WorkshopRepository
{
    protected string $table = 'tfb_position';
    protected array $writable = ['code', 'name_pl', 'name_fr', 'description', 'sort_order', 'active'];
    private const TASK_COLUMNS = ['id_position', 'title_pl', 'title_fr', 'frequency', 'link_path', 'sort_order', 'active'];

    public function positions(): array
    {
        return $this->all(
            'SELECT p.*, (SELECT COUNT(*) FROM tfb_position_task t WHERE t.id_position = p.id AND t.active = 1) AS task_count,
                    (SELECT COUNT(*) FROM tfb_user_position up WHERE up.id_position = p.id) AS user_count
             FROM tfb_position p ORDER BY p.active DESC, p.sort_order, p.name_pl'
        );
    }

    public function tasks(?int $positionId = null, bool $activeOnly = false): array
    {
        $where = [];
        $params = [];
        if ($positionId !== null) { $where[] = 't.id_position = ?'; $params[] = $positionId; }
        if ($activeOnly) $where[] = 't.active = 1';
        return $this->all(
            "SELECT t.* FROM tfb_position_task t" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY t.id_position, FIELD(t.frequency, 'daily', 'weekly', 'monthly', 'quarterly'), t.sort_order, t.id",
            $params
        );
    }

    public function task(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_position_task WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertTask(array $data): int
    {
        $data = array_intersect_key($data, array_flip(self::TASK_COLUMNS));
        $columns = array_keys($data);
        $this->db->prepare(sprintf('INSERT INTO tfb_position_task (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))))
            ->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateTask(int $id, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::TASK_COLUMNS));
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE tfb_position_task SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    }

    /** Utilisateurs de l'ERP avec leur poste. */
    public function users(): array
    {
        return $this->all(
            'SELECT u.id, u.displayed_name, u.name, u.surname, u.email, u.login, up.id_position, up.since, p.name_pl AS position_name
             FROM user u LEFT JOIN tfb_user_position up ON up.id_user = u.id LEFT JOIN tfb_position p ON p.id = up.id_position
             ORDER BY u.displayed_name, u.login'
        );
    }

    public function user(int $id): ?array
    {
        return $this->all(
            'SELECT u.id, u.displayed_name, u.name, u.surname, u.email, u.phone, u.login, up.id_position, up.since,
                    p.name_pl AS position_name, p.name_fr AS position_name_fr, p.description AS position_description
             FROM user u LEFT JOIN tfb_user_position up ON up.id_user = u.id LEFT JOIN tfb_position p ON p.id = up.id_position
             WHERE u.id = ?',
            [$id]
        )[0] ?? null;
    }

    public function assign(int $userId, ?int $positionId, ?string $since): void
    {
        if ($positionId === null) {
            $this->db->prepare('DELETE FROM tfb_user_position WHERE id_user = ?')->execute([$userId]);
            return;
        }
        $this->db->prepare('INSERT INTO tfb_user_position (id_user, id_position, since) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_position = VALUES(id_position), since = VALUES(since)')
            ->execute([$userId, $positionId, $since]);
    }

    public function userExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM user WHERE id = ?', [$id]);
    }

    /** Crée l'occurrence d'une période si elle n'existe pas encore. */
    public function ensureOccurrence(int $taskId, string $periodKey, string $dueDate): void
    {
        $this->db->prepare('INSERT IGNORE INTO tfb_task_occurrence (id_task, period_key, due_date) VALUES (?, ?, ?)')->execute([$taskId, $periodKey, $dueDate]);
    }

    /** Occurrences des tâches données : période courante (clés) et toutes celles restées ouvertes avant aujourd'hui. */
    public function occurrences(array $taskIds, array $currentKeys, string $today): array
    {
        if (!$taskIds) return [];
        $in = implode(', ', array_fill(0, count($taskIds), '?'));
        $keys = $currentKeys ?: [''];
        $inKeys = implode(', ', array_fill(0, count($keys), '?'));
        return $this->all(
            "SELECT o.*, u.displayed_name AS done_by_name
             FROM tfb_task_occurrence o LEFT JOIN user u ON u.id = o.done_by
             WHERE o.id_task IN ({$in}) AND (o.period_key IN ({$inKeys}) OR (o.status = 'open' AND o.due_date < ?))
             ORDER BY o.due_date, o.id",
            [...$taskIds, ...$keys, $today]
        );
    }

    public function occurrence(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_task_occurrence WHERE id = ?', [$id])[0] ?? null;
    }

    public function setOccurrence(int $id, string $status, ?int $userId, ?string $note): void
    {
        $this->db->prepare('UPDATE tfb_task_occurrence SET status = ?, done_by = ?, done_at = ?, note = ? WHERE id = ?')
            ->execute([$status, $status === 'open' ? null : $userId, $status === 'open' ? null : date('Y-m-d H:i:s'), $note, $id]);
    }

    /** Dernières occurrences traitées des tâches d'un poste (historique de la fiche). */
    public function history(int $positionId, int $limit = 30): array
    {
        return $this->all(
            "SELECT o.*, t.title_pl, t.frequency, u.displayed_name AS done_by_name
             FROM tfb_task_occurrence o JOIN tfb_position_task t ON t.id = o.id_task LEFT JOIN user u ON u.id = o.done_by
             WHERE t.id_position = ? AND o.status <> 'open'
             ORDER BY o.done_at DESC, o.id DESC LIMIT " . max(1, min(200, $limit)),
            [$positionId]
        );
    }
}
