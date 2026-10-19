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

    /** @return array<string,array<int,array<int,array{headcount:int, overtime_h:float, source:string}>>> jour => groupe => shift */
    public function staffing(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM tfb_prod_staffing WHERE work_date BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[$r['work_date']][(int) $r['id_group']][(int) $r['id_shift_template']] = ['headcount' => (int) $r['headcount'], 'overtime_h' => (float) $r['overtime_h'], 'source' => $r['source']];
        }
        return $out;
    }

    public function saveStaffing(string $date, int $groupId, int $shiftId, ?int $headcount, float $overtime, string $source, ?string $note, ?int $userId): void
    {
        if ($headcount === null) {
            $this->db->prepare('DELETE FROM tfb_prod_staffing WHERE work_date = ? AND id_group = ? AND id_shift_template = ?')->execute([$date, $groupId, $shiftId]);
            return;
        }
        $this->db->prepare(
            'INSERT INTO tfb_prod_staffing (work_date, id_group, id_shift_template, headcount, overtime_h, source, note, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE headcount = VALUES(headcount), overtime_h = VALUES(overtime_h), source = VALUES(source), note = VALUES(note), updated_by = VALUES(updated_by)'
        )->execute([$date, $groupId, $shiftId, $headcount, $overtime, $source, $note, $userId]);
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

    /** OF ajoutés puis non placés (optimiseur) : supprimés avec leurs opérations. */
    public function deleteOrders(array $orderIds): void
    {
        if (!$orderIds) return;
        $in = implode(', ', array_fill(0, count($orderIds), '?'));
        $this->db->prepare("DELETE FROM tfb_prod_operation WHERE id_order IN ({$in})")->execute(array_values($orderIds));
        $this->db->prepare("DELETE FROM tfb_prod_order WHERE id IN ({$in}) AND status = 'planned'")->execute(array_values($orderIds));
    }

    public function deleteUnlocked(array $orderIds): void
    {
        if (!$orderIds) return;
        $in = implode(', ', array_fill(0, count($orderIds), '?'));
        $this->db->prepare("DELETE FROM tfb_prod_operation WHERE locked = 0 AND status = 'planned' AND id_order IN ({$in})")->execute(array_values($orderIds));
    }

    private const OPERATION_SELECT = "SELECT op.*, o.code AS order_code, o.origin, o.quantity AS order_qty, o.due_at, o.id_item, o.id_parent_order,
                    COALESCE(fp.name, pr.name) AS item_name, i.base_unit,
                    w.code AS ws_code, w.name_pl AS ws_name_pl, w.name_fr AS ws_name_fr, w.type AS ws_type, w.id_group,
                    w.robot_mode AS ws_robot_mode, w.capacity_per_pass AS ws_capacity,
                    rs.step_no, rs.name_pl AS step_name_pl, rs.name_fr AS step_name_fr, rs.operators_required, rs.id_workstation AS step_workstation,
                    t.code AS team_code
             FROM tfb_prod_operation op
             JOIN tfb_prod_order o ON o.id = op.id_order
             JOIN tfb_prod_item i ON i.id = o.id_item
             LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             JOIN tfb_prod_workstation w ON w.id = op.id_workstation
             LEFT JOIN tfb_prod_routing_step rs ON rs.id = op.id_routing_step
             LEFT JOIN tfb_prod_team t ON t.id = op.id_team";

    /** Opérations d'une période pour le Gantt, avec OF, article, poste, groupe et équipe. */
    public function operations(string $from, string $to, ?int $groupId): array
    {
        $params = [$to, $from];
        $where = '';
        if ($groupId !== null) { $where = ' AND w.id_group = ?'; $params[] = $groupId; }
        return $this->all(
            self::OPERATION_SELECT . " WHERE op.status <> 'cancelled' AND op.planned_start < ? AND op.planned_end > ?{$where}
             ORDER BY w.id_group, w.id, op.planned_start",
            $params
        );
    }

    public function operation(int $id): ?array
    {
        return $this->all(self::OPERATION_SELECT . ' WHERE op.id = ?', [$id])[0] ?? null;
    }

    /** Opérations d'un OF, dans l'ordre où elles ont été placées. */
    public function orderOperations(int $orderId): array
    {
        return $this->all("SELECT id, kind, planned_start, planned_end FROM tfb_prod_operation WHERE id_order = ? AND status <> 'cancelled' ORDER BY planned_start, id", [$orderId]);
    }

    /** Début de l'OF parent (première opération) et fin des semi-finis enfants. */
    public function parentStart(int $parentOrderId): ?string
    {
        return $this->all("SELECT MIN(planned_start) AS s FROM tfb_prod_operation WHERE id_order = ? AND status <> 'cancelled'", [$parentOrderId])[0]['s'] ?? null;
    }

    /** @return list<array{code:string, end:string}> */
    public function childrenEnds(int $orderId): array
    {
        return $this->all(
            "SELECT o.code, MAX(op.planned_end) AS end FROM tfb_prod_order o JOIN tfb_prod_operation op ON op.id_order = o.id AND op.status <> 'cancelled'
             WHERE o.id_parent_order = ? GROUP BY o.id, o.code",
            [$orderId]
        );
    }

    /** Autres opérations d'un poste qui chevauchent [from, to[. */
    public function workstationOperations(int $workstationId, string $from, string $to, int $exceptId): array
    {
        return $this->all(
            "SELECT op.id, op.kind, op.planned_start, op.planned_end, o.code AS order_code FROM tfb_prod_operation op JOIN tfb_prod_order o ON o.id = op.id_order
             WHERE op.id_workstation = ? AND op.id <> ? AND op.status IN ('planned', 'in_progress', 'paused') AND op.planned_start < ? AND op.planned_end > ?",
            [$workstationId, $exceptId, $to, $from]
        );
    }

    /** Postes possibles d'une étape : poste principal et alternatives. */
    public function stepWorkstations(int $stepId): array
    {
        return array_map('intval', array_column($this->all(
            'SELECT id_workstation FROM tfb_prod_routing_step WHERE id = ? UNION SELECT id_workstation FROM tfb_prod_routing_alternative WHERE id_step = ?',
            [$stepId, $stepId]
        ), 'id_workstation'));
    }

    public function workstation(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_workstation WHERE id = ? AND active = 1', [$id])[0] ?? null;
    }
}
