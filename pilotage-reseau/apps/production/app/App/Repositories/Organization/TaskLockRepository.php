<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** [280] Zadania obowiązkowe : tâches obligatoires d'un compte, occurrences ouvertes récentes, déblocages du jour. */
final class TaskLockRepository extends WorkshopRepository
{
    protected string $table = 'n_task_unblock';

    /** Tâches obligatoires actives des postes du compte (poste propre, pas les remplacements). */
    public function mandatoryTasks(int $userId): array
    {
        return $this->all('SELECT t.*, p.code AS position_code, p.id_reports_to FROM n_position_task t JOIN n_user_position up ON up.id_position = t.id_position
                           JOIN n_position p ON p.id = t.id_position WHERE up.id_user = ? AND t.active = 1 AND t.mandatory = 1 ORDER BY t.sort_order, t.id', [$userId]);
    }

    /** [289] Tâches obligatoires d'un poste (remplaçant pendant un congé validé). */
    public function mandatoryTasksOfPosition(int $positionId): array
    {
        return $this->all('SELECT t.*, p.code AS position_code, p.id_reports_to FROM n_position_task t JOIN n_position p ON p.id = t.id_position
                           WHERE t.id_position = ? AND t.active = 1 AND t.mandatory = 1 ORDER BY t.sort_order, t.id', [$positionId]);
    }

    /** Occurrences ouvertes d'une tâche dont le terme est entre $from et $to. */
    public function openOccurrences(int $taskId, string $from, string $to): array
    {
        return $this->all("SELECT * FROM n_task_occurrence WHERE id_task = ? AND status = 'open' AND due_date BETWEEN ? AND ? ORDER BY due_date", [$taskId, $from, $to]);
    }

    public function unblocked(int $userId, int $taskId, string $day): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_task_unblock WHERE id_user = ? AND id_task = ? AND day = ?', [$userId, $taskId, $day]);
    }

    public function unblock(int $userId, int $taskId, string $day, string $reason, ?int $by): void
    {
        $this->db->prepare('INSERT INTO n_task_unblock (id_user, id_task, day, reason, created_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by), created_at = NOW()')
            ->execute([$userId, $taskId, $day, $reason, $by]);
    }

    /** Comptes du poste supérieur (déblocage) — vide si le poste n'a pas de supérieur. @return list<int> */
    public function usersOfPosition(?int $positionId): array
    {
        if (!$positionId) return [];
        return array_map('intval', array_column($this->all('SELECT id_user FROM n_user_position WHERE id_position = ?', [$positionId]), 'id_user'));
    }

    public function userName(int $userId): ?string
    {
        return $this->all('SELECT displayed_name FROM user WHERE id = ?', [$userId])[0]['displayed_name'] ?? null;
    }

    public function task(int $taskId): ?array
    {
        return $this->all('SELECT t.*, p.id_reports_to FROM n_position_task t JOIN n_position p ON p.id = t.id_position WHERE t.id = ?', [$taskId])[0] ?? null;
    }

    /** Le compte occupe-t-il le poste de la tâche ? */
    public function userHasPosition(int $userId, int $positionId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_user_position WHERE id_user = ? AND id_position = ?', [$userId, $positionId]);
    }
}
