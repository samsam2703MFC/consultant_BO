<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Chronométrages des étapes de gamme (tfb_prod_time_study). */
final class TimeStudyRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_time_study';
    protected array $writable = ['id_routing_step', 'id_workstation', 'component', 'quantity', 'passes', 'duration_s', 'unit_time_s',
        'measured_at', 'id_employee', 'measured_by', 'note', 'excluded', 'applied_at'];

    public function list(int $stepId, int $limit = 100): array
    {
        return $this->all(
            'SELECT t.*, e.id_production_employee, pe.name AS employee_name, pe.surname AS employee_surname, u.displayed_name AS measured_by_name
             FROM tfb_prod_time_study t
             LEFT JOIN tfb_prod_employee e ON e.id = t.id_employee
             LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN user u ON u.id = t.measured_by
             WHERE t.id_routing_step = ? ORDER BY t.measured_at DESC, t.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$stepId]
        );
    }

    /** Valeurs unitaires retenues d'une composante, de la plus récente à la plus ancienne. */
    public function latest(int $stepId, string $component, int $limit): array
    {
        return $this->all(
            'SELECT id, unit_time_s FROM tfb_prod_time_study
             WHERE id_routing_step = ? AND component = ? AND excluded = 0 AND id_workstation IS NULL
             ORDER BY measured_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$stepId, $component]
        );
    }

    public function markApplied(array $ids): void
    {
        if (!$ids) return;
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->prepare("UPDATE tfb_prod_time_study SET applied_at = NOW() WHERE id IN ({$in})")->execute(array_values($ids));
    }

    /** Profil de production (tfb_prod_employee.id) d'un employé de l'ERP, s'il existe. */
    public function employeeIdFor(int $productionEmployeeId): ?int
    {
        $id = $this->all('SELECT id FROM tfb_prod_employee WHERE id_production_employee = ?', [$productionEmployeeId])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }
}
