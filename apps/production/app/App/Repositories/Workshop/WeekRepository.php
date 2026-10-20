<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Gantt tygodnia : podział produktów tygodnia na dni, plany tygodnia i postęp ich operacji. */
final class WeekRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_week_slot';

    /** @return list<array{id_item:string, work_date:string, quantity:string}> */
    public function slots(string $weekStart): array
    {
        return $this->all('SELECT id_item, work_date, quantity FROM tfb_prod_week_slot WHERE week_start = ? ORDER BY work_date, id_item', [$weekStart]);
    }

    /** Ustawia ilość produktu na dzień ; 0 usuwa wiersz. */
    public function setSlot(string $weekStart, int $itemId, string $day, float $quantity, ?int $userId): void
    {
        if ($quantity <= 0.0005) {
            $this->db->prepare('DELETE FROM tfb_prod_week_slot WHERE week_start = ? AND id_item = ? AND work_date = ?')->execute([$weekStart, $itemId, $day]);
            return;
        }
        $this->db->prepare('INSERT INTO tfb_prod_week_slot (week_start, id_item, work_date, quantity, updated_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_by = VALUES(updated_by)')
            ->execute([$weekStart, $itemId, $day, round($quantity, 3), $userId]);
    }

    /** @param list<int> $itemIds */
    public function clear(string $weekStart, array $itemIds): void
    {
        if (!$itemIds) return;
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $this->db->prepare("DELETE FROM tfb_prod_week_slot WHERE week_start = ? AND id_item IN ({$in})")->execute([$weekStart, ...$itemIds]);
    }

    /** Plany zatwierdzone, zamrożone lub zamknięte, które obejmują tydzień (ostatnia wersja pierwsza). */
    public function plans(string $start, string $end): array
    {
        return $this->all("SELECT id, period_start, period_end, status, version FROM tfb_prod_plan
                           WHERE status IN ('validated', 'frozen', 'closed') AND period_start <= ? AND period_end >= ?
                           ORDER BY period_start, version DESC, id DESC", [$end, $start]);
    }

    /**
     * Operacje zleceń planów (bez anulowanych) : artykuł, rodzaj, status, czasy planowane i operatorzy etapu.
     * @param list<int> $planIds
     */
    public function operations(array $planIds): array
    {
        if (!$planIds) return [];
        $in = implode(',', array_fill(0, count($planIds), '?'));
        return $this->all(
            "SELECT o.id_item, o.id_plan, op.kind, op.status, op.planned_start, op.planned_end, op.actual_end, rs.operators_required
             FROM tfb_prod_operation op
             JOIN tfb_prod_order o ON o.id = op.id_order
             LEFT JOIN tfb_prod_routing_step rs ON rs.id = op.id_routing_step
             WHERE o.id_plan IN ({$in}) AND op.status <> 'cancelled' AND o.status <> 'cancelled'",
            $planIds
        );
    }

    /**
     * Ostatni dzień, na który przydzielono artykuł w tygodniu terminu — uszeregowanie kończy zlecenie
     * najpóźniej tego dnia. @param list<int> $itemIds @return array<int, array<string, string>> artykuł => [poniedziałek => dzień]
     */
    public function lastDays(array $itemIds, string $from, string $to): array
    {
        if (!$itemIds) return [];
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $out = [];
        foreach ($this->all("SELECT id_item, week_start, MAX(work_date) AS last_day FROM tfb_prod_week_slot
                             WHERE id_item IN ({$in}) AND week_start BETWEEN ? AND ? GROUP BY id_item, week_start", [...$itemIds, $from, $to]) as $r) {
            $out[(int) $r['id_item']][$r['week_start']] = $r['last_day'];
        }
        return $out;
    }
}
