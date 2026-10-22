<?php
namespace App\Repositories\Staff;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Personel : pracownicy produkcji ERP (production_employee) z ich wynagrodzeniem minimalnym
 * i udziałem w premiach (tfb_staff_pay).
 */
final class StaffRepository extends WorkshopRepository
{
    protected string $table = 'tfb_staff_pay';
    protected array $writable = ['base_monthly', 'bonus_eligible', 'active', 'notes', 'updated_by'];

    public function employees(): array
    {
        return $this->all(
            'SELECT e.id, e.name, e.surname, e.cost, e.id_section, s.name AS section_name,
                    p.base_monthly, COALESCE(p.bonus_eligible, 1) AS bonus_eligible, COALESCE(p.active, 1) AS active, p.notes
             FROM production_employee e
             JOIN production_sections s ON s.id = e.id_section
             LEFT JOIN tfb_staff_pay p ON p.id_production_employee = e.id
             ORDER BY s.id, e.surname, e.name'
        );
    }

    public function employee(int $id): ?array
    {
        return $this->all('SELECT e.id, e.cost, e.id_section FROM production_employee e WHERE e.id = ?', [$id])[0] ?? null;
    }

    public function savePay(int $employeeId, array $data): void
    {
        $data = $this->filter($data);
        if (!$data) return;
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO tfb_staff_pay (id_production_employee, %s) VALUES (:id, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute(['id' => $employeeId] + $data);
    }
}
