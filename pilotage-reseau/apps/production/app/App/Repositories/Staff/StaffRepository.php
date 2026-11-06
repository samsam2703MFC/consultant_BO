<?php
namespace App\Repositories\Staff;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Personel : pracownicy produkcji ERP (production_employee) z ich wynagrodzeniem minimalnym
 * i udziałem w premiach (n_staff_pay). « Aktywny » = jeden wskaźnik pracownika, wspólny z modułem
 * produkcji (n_prod_employee.active : tablet, pointage, zespoły) — brak profilu = aktywny.
 */
final class StaffRepository extends WorkshopRepository
{
    protected string $table = 'n_staff_pay';
    protected array $writable = ['base_monthly', 'bonus_eligible', 'notes', 'updated_by'];

    public function employees(): array
    {
        return $this->all(
            'SELECT e.id, e.name, e.surname, e.cost, e.id_section, s.name AS section_name,
                    p.base_monthly, COALESCE(p.bonus_eligible, 1) AS bonus_eligible, COALESCE(pe.active, 1) AS active, p.notes
             FROM production_employee e
             JOIN production_sections s ON s.id = e.id_section
             LEFT JOIN n_staff_pay p ON p.id_production_employee = e.id
             LEFT JOIN n_prod_employee pe ON pe.id_production_employee = e.id
             ORDER BY s.id, e.surname, e.name'
        );
    }

    /** Jeden wskaźnik « aktywny » pracownika (profil produkcji tworzony, gdy go jeszcze nie ma). */
    public function setActive(int $employeeId, bool $active): void
    {
        $this->db->prepare('INSERT INTO n_prod_employee (id_production_employee, active) VALUES (?, ?) ON DUPLICATE KEY UPDATE active = VALUES(active)')
            ->execute([$employeeId, (int) $active]);
    }

    public function employee(int $id): ?array
    {
        return $this->all('SELECT e.id, e.cost, e.id_section FROM production_employee e WHERE e.id = ?', [$id])[0] ?? null;
    }

    /** Koszt miesięczny pracodawcy (production_employee.cost) : wpisywany tylko w Budżecie personelu. */
    public function setCost(int $employeeId, float $cost): void
    {
        $this->db->prepare('UPDATE production_employee SET cost = ? WHERE id = ?')->execute([$cost, $employeeId]);
    }

    public function savePay(int $employeeId, array $data): void
    {
        $data = $this->filter($data);
        if (!$data) return;
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO n_staff_pay (id_production_employee, %s) VALUES (:id, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute(['id' => $employeeId] + $data);
    }
}
