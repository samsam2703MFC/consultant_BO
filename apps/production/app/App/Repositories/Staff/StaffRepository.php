<?php
namespace App\Repositories\Staff;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Personel : pracownicy produkcji ERP (production_employee) z ich wynagrodzeniem minimalnym
 * i udziałem w premiach (tfb_staff_pay), oraz kompetencje ERP (recipe_competency) z poziomem
 * każdej osoby (tfb_employee_competency) i recepturami działów, które ich wymagają.
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

    /** Kompetencje ERP z liczbą receptur aktywnych produktów, które ich wymagają. */
    public function competencies(): array
    {
        return $this->all(
            'SELECT c.id, c.name, c.description, COUNT(DISTINCT fp.id_recipe) AS recipes
             FROM recipe_competency c
             LEFT JOIN recipe_competency_connection rc ON rc.competency_id = c.id
             LEFT JOIN final_product fp ON fp.id_recipe = rc.recipe_id AND fp.active = 1
             GROUP BY c.id, c.name, c.description
             ORDER BY c.name'
        );
    }

    public function competencyExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM recipe_competency WHERE id = ?', [$id]);
    }

    public function competencyNameTaken(string $name, int $exceptId = 0): bool
    {
        return (bool) $this->all('SELECT 1 FROM recipe_competency WHERE LOWER(TRIM(name)) = LOWER(?) AND id <> ?', [trim($name), $exceptId]);
    }

    public function addCompetency(string $name, string $description): int
    {
        $this->db->prepare('INSERT INTO recipe_competency (name, description) VALUES (?, ?)')->execute([$name, $description]);
        return (int) $this->db->lastInsertId();
    }

    public function renameCompetency(int $id, string $name, string $description): void
    {
        $this->db->prepare('UPDATE recipe_competency SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
    }

    /** Usunięcie kompetencji : poziomy pracowników i przypisania do receptur znikają razem z nią (FK CASCADE). */
    public function deleteCompetency(int $id): void
    {
        $this->db->prepare('DELETE FROM recipe_competency WHERE id = ?')->execute([$id]);
    }

    /** @return array<int,array<int,int>> pracownik => kompetencja => poziom */
    public function levels(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_production_employee, id_competency, level FROM tfb_employee_competency') as $r) {
            $out[(int) $r['id_production_employee']][(int) $r['id_competency']] = (int) $r['level'];
        }
        return $out;
    }

    public function setLevel(int $employeeId, int $competencyId, int $level, ?int $userId): void
    {
        if ($level === 0) {
            $this->db->prepare('DELETE FROM tfb_employee_competency WHERE id_production_employee = ? AND id_competency = ?')->execute([$employeeId, $competencyId]);
            return;
        }
        $this->db->prepare(
            'INSERT INTO tfb_employee_competency (id_production_employee, id_competency, level, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)'
        )->execute([$employeeId, $competencyId, $level, $userId]);
    }

    /**
     * Receptury aktywnych produktów działu z wymaganymi kompetencjami.
     * @return array<int,array<int,list<int>>> dział => receptura => kompetencje
     */
    public function sectionRecipes(): array
    {
        $out = [];
        $rows = $this->all(
            'SELECT DISTINCT fp.id_section, fp.id_recipe, rc.competency_id
             FROM final_product fp
             LEFT JOIN recipe_competency_connection rc ON rc.recipe_id = fp.id_recipe
             WHERE fp.active = 1 AND fp.id_recipe IS NOT NULL AND fp.id_section IS NOT NULL'
        );
        foreach ($rows as $r) {
            $recipes = &$out[(int) $r['id_section']][(int) $r['id_recipe']];
            $recipes ??= [];
            if ($r['competency_id'] !== null) $recipes[] = (int) $r['competency_id'];
            unset($recipes);
        }
        return $out;
    }
}
