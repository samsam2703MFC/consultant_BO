<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Gantt tygodnia : podział produktów tygodnia na dni, plany tygodnia i postęp ich operacji. */
final class WeekRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_week_slot';

    /** @return list<array{id_item:string, work_date:string, quantity:string, start_time:?string}> */
    public function slots(string $weekStart): array
    {
        return $this->all('SELECT id_item, work_date, quantity, start_time FROM n_prod_week_slot WHERE week_start = ? ORDER BY work_date, id_item', [$weekStart]);
    }

    /** Godzina startu partii w dniu (NULL = bez godziny). */
    public function setSlotTime(string $weekStart, int $itemId, string $day, ?string $time): void
    {
        $this->db->prepare('UPDATE n_prod_week_slot SET start_time = ? WHERE week_start = ? AND id_item = ? AND work_date = ?')->execute([$time, $weekStart, $itemId, $day]);
    }

    /** Zdejmuje produkty z dni od danego dnia (wracają do « Do zrobienia »). @param list<int> $itemIds */
    public function clearFrom(string $weekStart, array $itemIds, string $fromDay): int
    {
        if (!$itemIds) return 0;
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $st = $this->db->prepare("DELETE FROM n_prod_week_slot WHERE week_start = ? AND work_date >= ? AND id_item IN ({$in})");
        $st->execute([$weekStart, $fromDay, ...$itemIds]);
        return $st->rowCount();
    }

    /** Ustawia ilość produktu na dzień ; 0 usuwa wiersz. */
    public function setSlot(string $weekStart, int $itemId, string $day, float $quantity, ?int $userId): void
    {
        if ($quantity <= 0.0005) {
            $this->db->prepare('DELETE FROM n_prod_week_slot WHERE week_start = ? AND id_item = ? AND work_date = ?')->execute([$weekStart, $itemId, $day]);
            return;
        }
        $this->db->prepare('INSERT INTO n_prod_week_slot (week_start, id_item, work_date, quantity, updated_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_by = VALUES(updated_by)')
            ->execute([$weekStart, $itemId, $day, round($quantity, 3), $userId]);
    }

    /** @param list<int> $itemIds */
    public function clear(string $weekStart, array $itemIds): void
    {
        if (!$itemIds) return;
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $this->db->prepare("DELETE FROM n_prod_week_slot WHERE week_start = ? AND id_item IN ({$in})")->execute([$weekStart, ...$itemIds]);
    }

    /**
     * Stałe partie (📌) w tygodniu : ilość przypięta, ile dzień dostał z partii tygodnia (placed), released = dzień
     * zmieniony ręcznie. @return array<int, array<string, array{quantity:float, placed:float, released:bool}>>
     */
    public function fixedWeek(string $weekStart): array
    {
        $out = [];
        foreach ($this->all('SELECT id_item, work_date, quantity, placed, released FROM n_prod_fixed_batch_week WHERE week_start = ?', [$weekStart]) as $r) {
            $out[(int) $r['id_item']][$r['work_date']] = ['quantity' => (float) $r['quantity'], 'placed' => (float) $r['placed'], 'released' => (bool) $r['released']];
        }
        return $out;
    }

    /** Stała partia w dniu tygodnia : ilość przypięta, ile dostała, czy dzień jest zmieniony ręcznie. */
    public function setFixedWeek(string $weekStart, int $itemId, string $day, float $quantity, float $placed, bool $released = false): void
    {
        $this->db->prepare('INSERT INTO n_prod_fixed_batch_week (week_start, id_item, work_date, quantity, placed, released) VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), placed = VALUES(placed), released = VALUES(released)')
            ->execute([$weekStart, $itemId, $day, round($quantity, 3), round($placed, 3), (int) $released]);
    }

    /**
     * Przypięte dni od danego dnia zmienione ręcznie (« Wyczyść tydzień ») : nie biorą już partii tygodnia same,
     * placed = 0 (ilość w dniu, jeśli jest, liczy się odtąd jak ułożona ręcznie). @param list<int> $itemIds
     */
    public function releaseFixedFrom(string $weekStart, array $itemIds, string $fromDay): void
    {
        if (!$itemIds) return;
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $this->db->prepare("UPDATE n_prod_fixed_batch_week SET placed = 0, released = 1 WHERE week_start = ? AND work_date >= ? AND id_item IN ({$in})")
            ->execute([$weekStart, $fromDay, ...$itemIds]);
    }

    /** Przypięte dni od danego dnia znów biorą partie tygodnia same (« Ułóż tydzień »). @param list<int> $itemIds */
    public function restoreFixedFrom(string $weekStart, array $itemIds, string $fromDay): void
    {
        if (!$itemIds) return;
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $this->db->prepare("UPDATE n_prod_fixed_batch_week SET released = 0 WHERE released = 1 AND week_start = ? AND work_date >= ? AND id_item IN ({$in})")
            ->execute([$weekStart, $fromDay, ...$itemIds]);
    }

    /** Wejścia stałej partii (produkt, dzień tygodnia ISO) w tygodniach po danym tygodniu. */
    public function laterFixedWeeks(int $itemId, int $weekday, string $afterWeekStart): array
    {
        return $this->all('SELECT week_start, work_date, quantity, placed FROM n_prod_fixed_batch_week
                           WHERE id_item = ? AND WEEKDAY(work_date) + 1 = ? AND week_start > ?', [$itemId, $weekday, $afterWeekStart]);
    }

    public function deleteFixedWeek(string $weekStart, int $itemId, string $day): void
    {
        $this->db->prepare('DELETE FROM n_prod_fixed_batch_week WHERE week_start = ? AND id_item = ? AND work_date = ?')->execute([$weekStart, $itemId, $day]);
    }

    public function fixedRule(int $itemId, int $weekday): ?array
    {
        return $this->all('SELECT id, id_item, weekday, quantity, start_time FROM n_prod_fixed_batch WHERE id_item = ? AND weekday = ?', [$itemId, $weekday])[0] ?? null;
    }

    public function saveFixedRule(int $itemId, int $weekday, float $quantity, ?string $time, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_prod_fixed_batch (id_item, weekday, quantity, start_time, created_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), start_time = VALUES(start_time)')->execute([$itemId, $weekday, round($quantity, 3), $time, $userId]);
    }

    public function setFixedRuleTime(int $itemId, int $weekday, ?string $time): void
    {
        $this->db->prepare('UPDATE n_prod_fixed_batch SET start_time = ? WHERE id_item = ? AND weekday = ?')->execute([$time, $itemId, $weekday]);
    }

    public function deleteFixedRule(int $itemId, int $weekday): void
    {
        $this->db->prepare('DELETE FROM n_prod_fixed_batch WHERE id_item = ? AND weekday = ?')->execute([$itemId, $weekday]);
    }

    /** Ilość produktu w dniu (przydział Gantta tygodnia). */
    public function slotQuantity(string $weekStart, int $itemId, string $day): float
    {
        return (float) ($this->all('SELECT quantity FROM n_prod_week_slot WHERE week_start = ? AND id_item = ? AND work_date = ?', [$weekStart, $itemId, $day])[0]['quantity'] ?? 0);
    }

    /**
     * Plany zatwierdzone, zamrożone lub zamknięte, które zaczynają się w tym tygodniu produkcji (ostatnia wersja
     * pierwsza) — plan należy do tygodnia, w którym się zaczyna (plan z tygodnia od poniedziałku nie zamraża
     * kolejnego tygodnia od środy).
     */
    public function plans(string $start, string $end): array
    {
        return $this->all("SELECT id, period_start, period_end, status, version FROM n_prod_plan
                           WHERE status IN ('validated', 'frozen', 'closed') AND period_start BETWEEN ? AND ?
                           ORDER BY period_start, version DESC, id DESC", [$start, $end]);
    }

    /** Dobra ilość wyprodukowana w dniach (partie zapisane na tabletach minus straty, bez anulowanych). @return array<int, float> */
    public function produced(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT id_item, SUM(quantity) AS q FROM n_prod_output WHERE work_date BETWEEN ? AND ? AND cancelled_at IS NULL GROUP BY id_item', [$from, $to]) as $r) {
            $out[(int) $r['id_item']] = (float) $r['q'];
        }
        foreach ($this->all('SELECT id_item, SUM(quantity) AS q FROM n_prod_waste WHERE work_date BETWEEN ? AND ? AND cancelled_at IS NULL GROUP BY id_item', [$from, $to]) as $r) {
            $out[(int) $r['id_item']] = max(0.0, ($out[(int) $r['id_item']] ?? 0.0) - (float) $r['q']);
        }
        return $out;
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
             FROM n_prod_operation op
             JOIN n_prod_order o ON o.id = op.id_order
             LEFT JOIN n_prod_routing_step rs ON rs.id = op.id_routing_step
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
        foreach ($this->all("SELECT id_item, week_start, MAX(work_date) AS last_day FROM n_prod_week_slot
                             WHERE id_item IN ({$in}) AND week_start BETWEEN ? AND ? GROUP BY id_item, week_start", [...$itemIds, $from, $to]) as $r) {
            $out[(int) $r['id_item']][$r['week_start']] = $r['last_day'];
        }
        return $out;
    }
}
