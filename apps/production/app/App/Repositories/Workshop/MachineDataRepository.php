<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Dane maszyn [240] (makiety 12–13) : produkty i ich etapy na maszynach (n_prod_routing_step) — co jest uzupełnione,
 * zapis z kreatora maszyny (wsad, wsadów na godzinę, osób przy wsadzie).
 */
final class MachineDataRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_routing_step';

    /** Grupy produkcji aktywne (kolejność menu). @return list<array{id:int, code:string, name_pl:string}> */
    public function groups(): array
    {
        return array_map(fn($g) => ['id' => (int) $g['id'], 'code' => (string) $g['code'], 'name_pl' => (string) $g['name_pl']],
            $this->all('SELECT id, code, name_pl FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl'));
    }

    /** Produkty produkcji (z ustawieniami), bez produktów ERP wyłączonych. @return list<array> */
    public function products(): array
    {
        return $this->all(
            "SELECT i.id, COALESCE(fp.name, pr.name, CONCAT('#', i.id)) AS name, i.item_type, i.base_unit, s.id_primary_group, fp.ppc
             FROM n_prod_item i
             JOIN n_prod_product_settings s ON s.id_item = i.id
             LEFT JOIN final_product fp ON fp.id = i.id_final_product
             LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             WHERE fp.id IS NULL OR fp.active = 1
             ORDER BY name"
        );
    }

    /** Etapy wszystkich produktów (z nazwą maszyny). @return list<array> */
    public function steps(): array
    {
        return $this->all(
            'SELECT s.id, s.id_item, s.step_no, s.name_pl, s.id_workstation, s.capacity_per_pass, s.fixed_time_s, s.variable_time_s, s.operators_required,
                    w.name_pl AS workstation
             FROM n_prod_routing_step s JOIN n_prod_workstation w ON w.id = s.id_workstation
             ORDER BY s.id_item, s.step_no'
        );
    }

    public function workstation(int $id): ?array
    {
        return $this->all(
            'SELECT w.id, w.name_pl, w.name_fr, w.type, w.id_group, w.capacity_per_pass, w.capacity_unit, w.efficiency_pct, w.active, g.name_pl AS group_name
             FROM n_prod_workstation w LEFT JOIN n_prod_group g ON g.id = w.id_group WHERE w.id = ?',
            [$id]
        )[0] ?? null;
    }

    public function stepOn(int $itemId, int $workstationId): ?array
    {
        return $this->all('SELECT * FROM n_prod_routing_step WHERE id_item = ? AND id_workstation = ? ORDER BY step_no LIMIT 1', [$itemId, $workstationId])[0] ?? null;
    }

    public function nextStepNo(int $itemId): int
    {
        return (int) ($this->all('SELECT COALESCE(MAX(step_no), 0) + 1 AS n FROM n_prod_routing_step WHERE id_item = ?', [$itemId])[0]['n'] ?? 1);
    }

    public function updateStep(int $id, array $f): void
    {
        $this->db->prepare('UPDATE n_prod_routing_step SET capacity_per_pass = ?, fixed_time_s = ?, variable_time_s = ?, operators_required = ? WHERE id = ?')
            ->execute([$f['capacity_per_pass'], $f['fixed_time_s'], $f['variable_time_s'], $f['operators_required'], $id]);
    }

    public function insertStep(array $f): int
    {
        $this->db->prepare('INSERT INTO n_prod_routing_step (id_item, step_no, name_pl, name_fr, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, operators_required)
                            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)')
            ->execute([$f['id_item'], $f['step_no'], $f['name_pl'], $f['name_fr'], $f['id_workstation'], $f['fixed_time_s'], $f['capacity_per_pass'], $f['operators_required']]);
        return (int) $this->db->lastInsertId();
    }
}
