<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Données de l'ordonnancement : shifts, disponibilités des postes, équipes, OF, opérations. */
final class SchedulingRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_operation';
    protected array $writable = ['id_order', 'id_routing_step', 'kind', 'id_workstation', 'id_team', 'planned_start', 'planned_end', 'status', 'locked', 'updated_by'];

    public function shifts(): array
    {
        return $this->all('SELECT * FROM tfb_prod_shift_template WHERE active = 1 ORDER BY start_time');
    }

    public function workstations(): array
    {
        return $this->all('SELECT w.*, g.code AS group_code, g.name_pl AS group_name_pl, g.name_fr AS group_name_fr, g.color AS group_color
                           FROM tfb_prod_workstation w JOIN tfb_prod_group g ON g.id = w.id_group WHERE w.active = 1 ORDER BY g.sort_order, w.id');
    }

    /** @return array<int,array<int,bool>> poste => shift => disponible */
    public function availability(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_workstation, id_shift_template, available FROM tfb_prod_workstation_shift') as $r) {
            $out[(int) $r['id_workstation']][(int) $r['id_shift_template']] = (bool) $r['available'];
        }
        return $out;
    }

    public function teams(): array
    {
        return $this->all('SELECT t.*, s.is_night FROM tfb_prod_team t JOIN tfb_prod_shift_template s ON s.id = t.id_shift_template WHERE t.active = 1');
    }

    public function groups(): array
    {
        return $this->all('SELECT id, code, name_pl, name_fr, color, sort_order FROM tfb_prod_group WHERE active = 1 ORDER BY sort_order');
    }

    public function plan(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_plan WHERE id = ?', [$id])[0] ?? null;
    }

    public function orders(int $planId): array
    {
        return $this->all(
            "SELECT o.*, COALESCE(fp.name, pr.name) AS name FROM tfb_prod_order o
             JOIN tfb_prod_item i ON i.id = o.id_item
             LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             WHERE o.id_plan = ? AND o.status IN ('planned', 'released') ORDER BY o.due_at, o.id",
            [$planId]
        );
    }

    /** @return array<int,list<array>> article => étapes */
    public function routings(array $itemIds): array
    {
        if (!$itemIds) return [];
        $in = implode(', ', array_fill(0, count($itemIds), '?'));
        $out = [];
        foreach ($this->all("SELECT * FROM tfb_prod_routing_step WHERE id_item IN ({$in}) ORDER BY id_item, step_no", array_values($itemIds)) as $s) {
            $out[(int) $s['id_item']][] = $s;
        }
        return $out;
    }

    /** @return array<int,list<array>> étape => alternatives */
    public function alternatives(array $stepIds): array
    {
        if (!$stepIds) return [];
        $in = implode(', ', array_fill(0, count($stepIds), '?'));
        $out = [];
        foreach ($this->all("SELECT * FROM tfb_prod_routing_alternative WHERE id_step IN ({$in}) ORDER BY priority", array_values($stepIds)) as $a) {
            $out[(int) $a['id_step']][] = $a;
        }
        return $out;
    }

    /** Opérations déjà posées sur la période, hors opérations non verrouillées des OF donnés. */
    public function existingOperations(string $from, string $to, array $exceptOrderIds): array
    {
        $params = [$to, $from];
        $except = '';
        if ($exceptOrderIds) {
            $except = ' AND NOT (o.id_order IN (' . implode(', ', array_fill(0, count($exceptOrderIds), '?')) . ') AND o.locked = 0)';
            $params = [...$params, ...$exceptOrderIds];
        }
        return $this->all("SELECT o.*, rs.capacity_per_pass FROM tfb_prod_operation o LEFT JOIN tfb_prod_routing_step rs ON rs.id = o.id_routing_step
                           WHERE o.status IN ('planned', 'in_progress', 'paused') AND o.planned_start < ? AND o.planned_end > ?" . $except, $params);
    }

    public function deleteUnlocked(array $orderIds): void
    {
        if (!$orderIds) return;
        $in = implode(', ', array_fill(0, count($orderIds), '?'));
        $this->db->prepare("DELETE FROM tfb_prod_operation WHERE locked = 0 AND status = 'planned' AND id_order IN ({$in})")->execute(array_values($orderIds));
    }

    /** Opérations d'une période pour le Gantt, avec OF, article, poste, groupe et équipe. */
    public function operations(string $from, string $to, ?int $groupId): array
    {
        $params = [$to, $from];
        $where = '';
        if ($groupId !== null) { $where = ' AND w.id_group = ?'; $params[] = $groupId; }
        return $this->all(
            "SELECT op.*, o.code AS order_code, o.origin, o.quantity AS order_qty, o.due_at, o.id_item, o.id_parent_order,
                    COALESCE(fp.name, pr.name) AS item_name, i.base_unit,
                    w.code AS ws_code, w.name_pl AS ws_name_pl, w.name_fr AS ws_name_fr, w.type AS ws_type, w.id_group,
                    rs.step_no, rs.name_pl AS step_name_pl, rs.name_fr AS step_name_fr, rs.operators_required, t.code AS team_code
             FROM tfb_prod_operation op
             JOIN tfb_prod_order o ON o.id = op.id_order
             JOIN tfb_prod_item i ON i.id = o.id_item
             LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             JOIN tfb_prod_workstation w ON w.id = op.id_workstation
             LEFT JOIN tfb_prod_routing_step rs ON rs.id = op.id_routing_step
             LEFT JOIN tfb_prod_team t ON t.id = op.id_team
             WHERE op.status <> 'cancelled' AND op.planned_start < ? AND op.planned_end > ?{$where}
             ORDER BY w.id_group, w.id, op.planned_start",
            $params
        );
    }

    public function operation(int $id): ?array
    {
        return $this->all('SELECT op.*, w.id_group FROM tfb_prod_operation op JOIN tfb_prod_workstation w ON w.id = op.id_workstation WHERE op.id = ?', [$id])[0] ?? null;
    }
}
