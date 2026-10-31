<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Moce produkcyjne [234] : maszyny (stanowiska) z ustawieniami mocy, zmiany, zmiany zespołów grup, dostępność maszyny na
 * zmianie, ceny produktów (wartość tego, co jeszcze zmieści się w tygodniu).
 */
final class CapacityRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_workstation';

    /** @return array<int, array> */
    public function workstations(): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT w.id, w.code, w.name_pl, w.name_fr, w.type, w.id_group, w.capacity_per_pass, w.capacity_unit, w.efficiency_pct, w.cycle_s, w.capacity_view,
                    w.oee_tracked, w.active, g.code AS group_code, g.name_pl AS group_name, g.color AS group_color
             FROM n_prod_workstation w LEFT JOIN n_prod_group g ON g.id = w.id_group
             WHERE w.active = 1 ORDER BY g.sort_order, g.name_pl, FIELD(w.type, \'froid\', \'surgelation\', \'four\', \'machine\', \'fermentation\', \'robot\', \'manuel\'), w.name_pl') as $w) {
            $out[(int) $w['id']] = $w;
        }
        return $out;
    }

    /** Zmiany (szablony) aktywne. @return array<int, array> */
    public function shifts(): array
    {
        $out = [];
        foreach ($this->all('SELECT id, code, name_pl, start_time, end_time, active_days FROM n_prod_shift_template WHERE active = 1 ORDER BY sort_order, id') as $s) $out[(int) $s['id']] = $s;
        return $out;
    }

    /** Grupa → zmiany, na których ma aktywny zespół. @return array<int, array<int, true>> */
    public function teamShifts(): array
    {
        $out = [];
        foreach ($this->all('SELECT DISTINCT id_group, id_shift_template FROM n_prod_team WHERE active = 1') as $r) $out[(int) $r['id_group']][(int) $r['id_shift_template']] = true;
        return $out;
    }

    /** Grupa → zmiana → osoby w aktywnych zespołach (obsada planowana). @return array<int, array<int, int>> */
    public function teamHeadcount(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_group, id_shift_template, SUM(planned_headcount) AS n FROM n_prod_team WHERE active = 1 GROUP BY id_group, id_shift_template') as $r) {
            $out[(int) $r['id_group']][(int) $r['id_shift_template']] = (int) $r['n'];
        }
        return $out;
    }

    /** Maszyna → zmiana → dostępna (0/1), gdy ustawione. @return array<int, array<int, int>> */
    public function machineShifts(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_workstation, id_shift_template, available FROM n_prod_workstation_shift') as $r) $out[(int) $r['id_workstation']][(int) $r['id_shift_template']] = (int) $r['available'];
        return $out;
    }

    /** Produkt (pozycja planowania) → cena standardowa kartonu, sztuk w kartonie, kartonów na paletę. @return array<int, array> */
    public function prices(): array
    {
        $out = [];
        foreach ($this->all('SELECT i.id, fp.standard_price, fp.ppc, fp.cpp FROM n_prod_item i LEFT JOIN final_product fp ON fp.id = i.id_final_product') as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    public function saveMachine(int $id, array $fields): void
    {
        $allowed = ['capacity_per_pass', 'capacity_unit', 'efficiency_pct', 'cycle_s', 'capacity_view'];
        $set = array_values(array_intersect($allowed, array_keys($fields)));
        if (!$set) return;
        $this->db->prepare('UPDATE n_prod_workstation SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $set)) . ' WHERE id = ?')
            ->execute([...array_map(fn($c) => $fields[$c], $set), $id]);
    }

    public function setMachineShift(int $id, int $shiftId, bool $available): void
    {
        $this->db->prepare('INSERT INTO n_prod_workstation_shift (id_workstation, id_shift_template, available) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE available = VALUES(available)')
            ->execute([$id, $shiftId, $available ? 1 : 0]);
    }
}
