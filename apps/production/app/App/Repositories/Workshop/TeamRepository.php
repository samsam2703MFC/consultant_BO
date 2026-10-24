<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

final class TeamRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_team';
    protected array $writable = ['code', 'id_group', 'id_shift_template', 'name', 'planned_headcount', 'active'];

    public function list(?int $groupId, ?int $shiftId, ?bool $active): array
    {
        $where = [];
        $params = [];
        if ($groupId !== null) { $where[] = 't.id_group = ?'; $params[] = $groupId; }
        if ($shiftId !== null) { $where[] = 't.id_shift_template = ?'; $params[] = $shiftId; }
        if ($active !== null) { $where[] = 't.active = ?'; $params[] = (int) $active; }
        // Liczba członków = pracownicy aktywni (jeden wskaźnik z Budżetem personelu)
        $sql = 'SELECT t.*, (SELECT COUNT(*) FROM n_prod_team_member m JOIN n_prod_employee e ON e.id = m.id_employee AND e.active = 1 WHERE m.id_team = t.id) AS member_count
                FROM n_prod_team t
                JOIN n_prod_group g ON g.id = t.id_group
                JOIN n_prod_shift_template s ON s.id = t.id_shift_template'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY g.sort_order, s.sort_order, t.code';
        return $this->all($sql, $params);
    }

    public function memberCount(int $teamId): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM n_prod_team_member m JOIN n_prod_employee e ON e.id = m.id_employee AND e.active = 1 WHERE m.id_team = ?', [$teamId])[0]['n'] ?? 0);
    }

    public function members(int $teamId): array
    {
        return $this->all(
            'SELECT m.id_employee, m.is_lead, m.valid_from, m.valid_to,
                    e.id_production_employee, pe.name, pe.surname, e.level_order, e.level_name, e.active
             FROM n_prod_team_member m
             JOIN n_prod_employee e ON e.id = m.id_employee
             JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE m.id_team = ?
             ORDER BY m.is_lead DESC, pe.surname, pe.name',
            [$teamId]
        );
    }

    /** @param list<array{id_employee:int,is_lead:bool,valid_from:?string,valid_to:?string}> $members */
    public function replaceMembers(int $teamId, array $members): void
    {
        $this->db->prepare('DELETE FROM n_prod_team_member WHERE id_team = ?')->execute([$teamId]);
        $insert = $this->db->prepare('INSERT INTO n_prod_team_member (id_team, id_employee, is_lead, valid_from, valid_to) VALUES (?, ?, ?, ?, ?)');
        foreach ($members as $m) {
            $insert->execute([$teamId, $m['id_employee'], (int) $m['is_lead'], $m['valid_from'], $m['valid_to']]);
        }
    }
}
