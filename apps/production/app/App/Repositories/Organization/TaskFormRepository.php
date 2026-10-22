<?php
namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** Formulaires des tâches (protocoles de réunion) et actions décidées, suivies d'une réunion à l'autre. */
final class TaskFormRepository extends WorkshopRepository
{
    protected string $table = 'tfb_task_form';
    protected array $writable = ['id_occurrence', 'form_code', 'meeting_date', 'period_from', 'period_to', 'data', 'status', 'created_by', 'updated_by'];

    private const FORM = "SELECT f.*, t.title_pl, t.title_fr, o.period_key, o.due_date, o.status AS occurrence_status, cu.displayed_name AS created_by_name, uu.displayed_name AS updated_by_name
        FROM tfb_task_form f
        JOIN tfb_task_occurrence o ON o.id = f.id_occurrence
        JOIN tfb_position_task t ON t.id = o.id_task
        LEFT JOIN user cu ON cu.id = f.created_by
        LEFT JOIN user uu ON uu.id = f.updated_by";

    public function byOccurrence(int $occurrenceId): ?array
    {
        return $this->all(self::FORM . ' WHERE f.id_occurrence = ?', [$occurrenceId])[0] ?? null;
    }

    public function form(int $id): ?array
    {
        return $this->all(self::FORM . ' WHERE f.id = ?', [$id])[0] ?? null;
    }

    /** Protokoły (najnowsze najpierw), opcjonalnie jednego rodzaju formularza. */
    public function forms(?string $code = null, int $limit = 200): array
    {
        $where = $code !== null ? ' WHERE f.form_code = ?' : '';
        return $this->all(self::FORM . $where . ' ORDER BY f.meeting_date DESC, f.id DESC LIMIT ' . max(1, min(500, $limit)), $code !== null ? [$code] : []);
    }

    /** Ostatni zakończony protokół tego rodzaju przed datą (podpowiedź uczestników). */
    public function previous(string $code, int $exceptId): ?array
    {
        return $this->all(self::FORM . " WHERE f.form_code = ? AND f.status = 'final' AND f.id <> ? ORDER BY f.meeting_date DESC, f.id DESC LIMIT 1", [$code, $exceptId])[0] ?? null;
    }

    private const ACTION = "SELECT a.*, u.displayed_name AS user_name, f.meeting_date, du.displayed_name AS done_by_name
        FROM tfb_task_action a JOIN tfb_task_form f ON f.id = a.id_form LEFT JOIN user u ON u.id = a.id_user LEFT JOIN user du ON du.id = a.done_by";

    /** Działania protokołu. */
    public function actions(int $formId): array
    {
        return $this->all(self::ACTION . ' WHERE a.id_form = ? ORDER BY a.id', [$formId]);
    }

    /** Działania otwarte z innych protokołów tego rodzaju + zamknięte na tym protokole (do pokazania na zebraniu). */
    public function carried(string $code, int $formId): array
    {
        return $this->all(self::ACTION . " WHERE f.form_code = ? AND a.id_form <> ? AND (a.status = 'open' OR a.done_in_form = ?)
                                           ORDER BY a.due_date IS NULL, a.due_date, a.id", [$code, $formId, $formId]);
    }

    public function action(int $id): ?array
    {
        return $this->all(self::ACTION . ' WHERE a.id = ?', [$id])[0] ?? null;
    }

    public function insertAction(int $formId, string $title, ?int $userId, ?string $dueDate): int
    {
        $this->db->prepare('INSERT INTO tfb_task_action (id_form, title, id_user, due_date) VALUES (?, ?, ?, ?)')->execute([$formId, $title, $userId, $dueDate]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteAction(int $id): void
    {
        $this->db->prepare('DELETE FROM tfb_task_action WHERE id = ?')->execute([$id]);
    }

    public function setActionDone(int $id, bool $done, ?int $userId, ?int $formId): void
    {
        $this->db->prepare('UPDATE tfb_task_action SET status = ?, done_at = ?, done_by = ?, done_in_form = ? WHERE id = ?')
            ->execute([$done ? 'done' : 'open', $done ? date('Y-m-d H:i:s') : null, $done ? $userId : null, $done ? $formId : null, $id]);
    }

    /** Aktywni użytkownicy ERP (uczestnicy, właściciele działań). */
    public function users(): array
    {
        return $this->all("SELECT u.id, COALESCE(NULLIF(u.displayed_name, ''), u.login) AS name, p.name_pl AS position_name
                           FROM user u LEFT JOIN tfb_user_position up ON up.id_user = u.id LEFT JOIN tfb_position p ON p.id = up.id_position
                           ORDER BY name");
    }
}
