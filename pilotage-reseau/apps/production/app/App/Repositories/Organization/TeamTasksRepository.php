<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Zadania zespołu [232] : przypomnienia (osoba : dzwonek + e-mail ; tablet grupy : komunikat potwierdzany PIN-em),
 * odbiorcy (użytkownicy stanowiska, obecni w grupie) i komunikaty tabletu (nieprzeczytane, zadanie niezaliczone).
 */
final class TeamTasksRepository extends WorkshopRepository
{
    protected string $table = 'n_task_reminder';

    public function insertReminder(array $r): int
    {
        $cols = ['batch', 'id_task', 'id_occurrence', 'due_date', 'title', 'message', 'urgent', 'target_kind', 'id_user', 'audience', 'target_label',
            'channels', 'id_notification', 'id_message', 'sent_by', 'sent_at'];
        $this->db->prepare('INSERT INTO n_task_reminder (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_map(fn($c) => $r[$c] ?? null, $cols));
        return (int) $this->db->lastInsertId();
    }

    public function setNotification(int $id, ?int $notificationId, ?int $messageId): void
    {
        $this->db->prepare('UPDATE n_task_reminder SET id_notification = ?, id_message = ? WHERE id = ?')->execute([$notificationId, $messageId, $id]);
    }

    /**
     * Przypomnienia terminów [from, to] (najnowsze najpierw) z przeczytaniem : tablet (PIN, kto) albo dzwonek osoby
     * (potwierdzony w ERP). @return list<array>
     */
    public function remindersBetween(string $from, string $to): array
    {
        return $this->all(
            "SELECT r.*, COALESCE(r.read_at, n.acknowledged_at) AS read_when, IF(r.read_at IS NULL AND n.acknowledged_at IS NOT NULL, 'bell', r.read_via) AS read_how,
                    TRIM(CONCAT(pe.name, ' ', pe.surname)) AS read_by_name, u.displayed_name AS sent_by_name
             FROM n_task_reminder r
             LEFT JOIN app_notification n ON n.id = r.id_notification
             LEFT JOIN n_prod_employee e ON e.id = r.read_by_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN user u ON u.id = r.sent_by
             WHERE r.due_date BETWEEN ? AND ? ORDER BY r.sent_at DESC, r.id DESC",
            [$from, $to]
        );
    }

    /** Przypomnienia wysłane w dniu : ile i ile przeczytanych (tablet albo dzwonek). */
    public function sentOn(string $day): array
    {
        $r = $this->all(
            'SELECT COUNT(*) AS n, SUM(r.read_at IS NOT NULL OR n.acknowledged_at IS NOT NULL) AS seen
             FROM n_task_reminder r LEFT JOIN app_notification n ON n.id = r.id_notification WHERE r.sent_at >= ? AND r.sent_at < ? + INTERVAL 1 DAY',
            [$day . ' 00:00:00', $day . ' 00:00:00']
        )[0] ?? [];
        return ['sent' => (int) ($r['n'] ?? 0), 'read' => (int) ($r['seen'] ?? 0)];
    }

    /** Komunikaty tabletu dla odbiorców (np. group:3, shift) : nieprzeczytane, niezamknięte, z ostatnich dni. */
    public function tabletMessages(array $audiences, string $since): array
    {
        if (!$audiences) return [];
        $in = implode(', ', array_fill(0, count($audiences), '?'));
        return $this->all(
            "SELECT r.*, u.displayed_name AS sent_by_name FROM n_task_reminder r LEFT JOIN user u ON u.id = r.sent_by
             WHERE r.target_kind = 'tablet' AND r.audience IN ({$in}) AND r.read_at IS NULL AND r.closed_at IS NULL AND r.sent_at >= ?
             ORDER BY r.urgent DESC, r.sent_at DESC",
            [...$audiences, $since]
        );
    }

    public function reminder(int $id): ?array
    {
        return $this->all('SELECT * FROM n_task_reminder WHERE id = ?', [$id])[0] ?? null;
    }

    public function markRead(int $id, int $employeeId, string $via): void
    {
        $this->db->prepare('UPDATE n_task_reminder SET read_at = NOW(), read_by_employee = ?, read_via = ? WHERE id = ? AND read_at IS NULL')->execute([$employeeId, $via, $id]);
    }

    public function close(int $id): void
    {
        $this->db->prepare('UPDATE n_task_reminder SET closed_at = NOW() WHERE id = ? AND closed_at IS NULL')->execute([$id]);
    }

    /** Użytkownicy ERP na stanowisku (odbiorcy dzwonka i e-maila). */
    public function positionUsers(int $positionId): array
    {
        return $this->all(
            'SELECT u.id, u.displayed_name, u.login, u.email FROM user u JOIN n_user_position up ON up.id_user = u.id WHERE up.id_position = ? ORDER BY u.displayed_name, u.login',
            [$positionId]
        );
    }

    /** Grupy produkcji z liczbą osób i obecnych (tablet grupy). @return array<int, array> */
    public function groups(): array
    {
        $member = 'e.active = 1 AND (e.id_primary_group = g.id OR e.id IN (SELECT m.id_employee FROM n_prod_team_member m JOIN n_prod_team t ON t.id = m.id_team WHERE t.id_group = g.id AND t.active = 1))';
        $out = [];
        foreach ($this->all(
            "SELECT g.id, g.code, g.name_pl, g.color,
                    (SELECT COUNT(*) FROM n_prod_employee e WHERE {$member}) AS people,
                    (SELECT COUNT(*) FROM n_prod_employee e WHERE {$member} AND EXISTS (SELECT 1 FROM n_prod_clock k WHERE k.id_employee = e.id AND k.clock_out IS NULL)) AS present
             FROM n_prod_group g WHERE g.active = 1 ORDER BY g.sort_order, g.name_pl") as $g) $out[(int) $g['id']] = $g;
        return $out;
    }

    /** Osoby stanowiska pracowni (pakowanie, kierowca, brygadzista) : ile i ile obecnych. */
    public function positionPeople(int $positionId): array
    {
        $r = $this->all(
            'SELECT COUNT(*) AS people, SUM(EXISTS (SELECT 1 FROM n_prod_clock k WHERE k.id_employee = e.id AND k.clock_out IS NULL)) AS present
             FROM n_prod_employee e WHERE e.active = 1 AND e.id_position = ?', [$positionId])[0] ?? [];
        return ['people' => (int) ($r['people'] ?? 0), 'present' => (int) ($r['present'] ?? 0)];
    }

    /** Pracownicy pracowni (filtr « Osoba ») : stanowisko i grupa główna. */
    public function employees(): array
    {
        return $this->all(
            "SELECT e.id, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, e.id_position, e.id_primary_group
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.active = 1 ORDER BY pe.surname, pe.name"
        );
    }

    /** Obecny pracownik (zegar otwarty) — potwierdzenie dotknięciem, gdy osoba nie ma PIN-u. */
    public function isPresent(int $employeeId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_clock WHERE id_employee = ? AND clock_out IS NULL LIMIT 1', [$employeeId]);
    }

    public function hasPin(int $employeeId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_employee_credential WHERE id_employee = ? AND pin_hash IS NOT NULL', [$employeeId]);
    }

    public function employeeName(int $employeeId): ?string
    {
        $v = $this->all("SELECT TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.id = ?", [$employeeId])[0]['name'] ?? null;
        return $v === null ? null : (string) $v;
    }
}
