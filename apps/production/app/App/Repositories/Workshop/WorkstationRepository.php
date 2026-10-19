<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

final class WorkstationRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_workstation';
    protected array $writable = ['code', 'id_group', 'name_pl', 'name_fr', 'type', 'robot_mode', 'capacity_per_pass', 'capacity_unit', 'hourly_cost', 'photo_url', 'notes', 'active'];

    public function list(?int $groupId, ?string $type, ?bool $active): array
    {
        $where = [];
        $params = [];
        if ($groupId !== null) { $where[] = 'w.id_group = ?'; $params[] = $groupId; }
        if ($type !== null) { $where[] = 'w.type = ?'; $params[] = $type; }
        if ($active !== null) { $where[] = 'w.active = ?'; $params[] = (int) $active; }
        $sql = 'SELECT w.* FROM tfb_prod_workstation w JOIN tfb_prod_group g ON g.id = w.id_group'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY g.sort_order, w.code';
        return $this->all($sql, $params);
    }

    /** @return array<int,array<int,bool>> id poste => [id shift => disponible] (absence = disponible) */
    public function availability(array $workstationIds): array
    {
        if (!$workstationIds) return [];
        $in = implode(',', array_fill(0, count($workstationIds), '?'));
        $map = [];
        foreach ($this->all("SELECT * FROM tfb_prod_workstation_shift WHERE id_workstation IN ({$in})", array_values($workstationIds)) as $row) {
            $map[(int) $row['id_workstation']][(int) $row['id_shift_template']] = (bool) $row['available'];
        }
        return $map;
    }

    /** @param array<int,bool> $availability id shift => disponible */
    public function replaceAvailability(int $workstationId, array $availability): void
    {
        $this->db->prepare('DELETE FROM tfb_prod_workstation_shift WHERE id_workstation = ?')->execute([$workstationId]);
        $insert = $this->db->prepare('INSERT INTO tfb_prod_workstation_shift (id_workstation, id_shift_template, available) VALUES (?, ?, ?)');
        foreach ($availability as $shiftId => $available) {
            $insert->execute([$workstationId, $shiftId, (int) $available]);
        }
    }
}
