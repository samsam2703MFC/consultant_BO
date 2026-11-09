<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Employés de l'atelier : la fiche reste celle de l'ERP (production_employee, dont le coût
 * salarial n'est jamais lu ici) ; le profil de production est dans n_prod_employee.
 */
final class EmployeeRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_employee';
    protected array $writable = ['id_production_employee', 'panel_employee_id', 'id_primary_group', 'level_order', 'level_name', 'level_synced_at', 'night_supervisor', 'active'];

    private const SELECT = 'SELECT pe.id AS production_employee_id, pe.name, pe.surname, pe.id_section AS section_id,
                e.id AS profile_id, e.panel_employee_id, e.id_primary_group, e.level_order, e.level_name,
                e.level_synced_at, e.night_supervisor, e.active
         FROM production_employee pe
         LEFT JOIN n_prod_employee e ON e.id_production_employee = pe.id';

    public function list(?int $groupId, ?bool $active): array
    {
        $where = [];
        $params = [];
        if ($groupId !== null) { $where[] = 'e.id_primary_group = ?'; $params[] = $groupId; }
        if ($active !== null) { $where[] = 'COALESCE(e.active, 1) = ?'; $params[] = (int) $active; }
        return $this->all(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY pe.surname, pe.name', $params);
    }

    public function byProductionEmployee(int $productionEmployeeId): ?array
    {
        return $this->all(self::SELECT . ' WHERE pe.id = ?', [$productionEmployeeId])[0] ?? null;
    }

    /** Profil pracowni (n_prod_employee.id) z imieniem, nazwiskiem i stanowiskiem. */
    public function byProfile(int $profileId): ?array
    {
        return $this->all('SELECT e.id AS profile_id, pe.id AS production_employee_id, pe.name, pe.surname, e.id_position, e.active
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.id = ?', [$profileId])[0] ?? null;
    }

    public function panelIdTaken(int $panelId, int $exceptProductionEmployeeId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_employee WHERE panel_employee_id = ? AND id_production_employee <> ?', [$panelId, $exceptProductionEmployeeId]);
    }

    /** Crée le profil de production s'il n'existe pas ; renvoie son id. */
    public function ensureProfile(int $productionEmployeeId, ?int $primaryGroupId = null): int
    {
        $row = $this->all('SELECT id FROM n_prod_employee WHERE id_production_employee = ?', [$productionEmployeeId])[0] ?? null;
        if ($row) return (int) $row['id'];
        return $this->insert(['id_production_employee' => $productionEmployeeId, 'id_primary_group' => $primaryGroupId]);
    }

    /** Stanowisko pracownika (n_position, kind atelier) ; null = bez stanowiska. */
    public function setPosition(int $profileId, ?int $positionId): void
    {
        $this->db->prepare('UPDATE n_prod_employee SET id_position = ? WHERE id = ?')->execute([$positionId, $profileId]);
    }
}
