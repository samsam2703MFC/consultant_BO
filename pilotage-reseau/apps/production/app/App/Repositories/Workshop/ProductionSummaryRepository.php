<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use App\Domain\Stock\StockLevels;

/** Karta produkcyjna produktu ERP w skrócie : ustawienia, marszruta, plan i Gantt tygodnia. */
final class ProductionSummaryRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_item';

    public function item(int $finalProductId): ?array
    {
        return $this->itemWhere('i.id_final_product = ?', $finalProductId);
    }

    public function itemById(int $itemId): ?array
    {
        return $this->itemWhere('i.id = ?', $itemId);
    }

    private function itemWhere(string $where, int $id): ?array
    {
        return $this->all(
            "SELECT i.id, i.item_type, i.base_unit, fp.ppc, fp.cpp, fp.weight,
                    s.mode, s.status, s.bake_state, s.batch_min, s.batch_multiple, COALESCE(xp.stock_min, s.stock_min) AS stock_min, COALESCE(xp.stock_max, s.stock_max) AS stock_max, s.id_primary_group,
                    g.name_pl AS group_name, g.color AS group_color,
                    (SELECT COALESCE(SUM(t.planned_headcount), 0) FROM n_prod_team t JOIN n_prod_shift_template sh ON sh.id = t.id_shift_template
                     WHERE t.id_group = s.id_primary_group AND t.active = 1 AND sh.is_night = 0) AS group_people
             FROM n_prod_item i
             LEFT JOIN final_product fp ON fp.id = i.id_final_product
             LEFT JOIN n_prod_product_settings s ON s.id_item = i.id
             " . StockLevels::join('i') . "
             LEFT JOIN n_prod_group g ON g.id = s.id_primary_group
             WHERE {$where} LIMIT 1",
            [$id]
        )[0] ?? null;
    }

    public function steps(int $itemId): array
    {
        return $this->all(
            'SELECT rs.*, w.code AS ws_code, w.name_pl AS ws_name, w.type AS ws_type, dw.code AS delay_ws_code, dw.name_pl AS delay_ws_name
             FROM n_prod_routing_step rs
             JOIN n_prod_workstation w ON w.id = rs.id_workstation
             LEFT JOIN n_prod_workstation dw ON dw.id = rs.delay_id_workstation
             WHERE rs.id_item = ? ORDER BY rs.step_no',
            [$itemId]
        );
    }

    /** Linie planów zatwierdzonych (zamrożonych) z terminem w tygodniu. */
    public function planLines(int $itemId, string $from, string $to): array
    {
        return $this->all(
            "SELECT l.due_date, l.qty_final, l.standard_hours, p.id AS plan_id, p.version, p.status
             FROM n_prod_plan_line l JOIN n_prod_plan p ON p.id = l.id_plan
             WHERE l.id_item = ? AND p.status IN ('validated', 'frozen') AND l.due_date BETWEEN ? AND ? ORDER BY l.due_date",
            [$itemId, $from, $to]
        );
    }

    public function slots(int $itemId, string $weekStart): array
    {
        return $this->all('SELECT work_date, quantity FROM n_prod_week_slot WHERE id_item = ? AND week_start = ? ORDER BY work_date', [$itemId, $weekStart]);
    }

    /** Operacje Gantta godzinowego w okresie : liczba, pierwsza i ostatnia, zakończone. */
    public function operations(int $itemId, string $from, string $to): array
    {
        return $this->all(
            "SELECT COUNT(*) AS operations, MIN(op.planned_start) AS first_start, MAX(op.planned_end) AS last_end,
                    SUM(op.status = 'done') AS done, SUM(op.status IN ('in_progress', 'paused')) AS running
             FROM n_prod_operation op JOIN n_prod_order o ON o.id = op.id_order
             WHERE o.id_item = ? AND op.status <> 'cancelled' AND op.kind <> 'delay' AND op.planned_start < ? AND op.planned_end > ?",
            [$itemId, $to, $from]
        )[0] ?? ['operations' => 0];
    }
}
