<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Stan kreatorów podpiętych pod zadania [227] : czy kreator jest zakończony w okresie zadania [from, to), wynik liczbowy
 * (podsumowanie na karcie) i krok, gdy jest w toku. Każda metoda czyta tabele swojego kreatora — nic nie zapisuje.
 * Uruchomienia automatyczne zadań (n_task_cron_run).
 */
final class TaskWizardRepository extends WorkshopRepository
{
    protected string $table = 'n_task_cron_run';

    /** Faktury : pozycje sprawdzone w okresie (i nic nie czeka). */
    public function invChecked(string $from, string $to): array
    {
        $r = $this->all("SELECT COUNT(*) AS n, SUM(check_status = 'disputed') AS up FROM n_inv_line WHERE checked_at >= ? AND checked_at < ?", [$from, $to])[0] ?? [];
        $waiting = (int) ($this->all("SELECT COUNT(*) AS n FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv WHERE i.kind = 'goods' AND i.status = 'open' AND l.check_status IS NULL")[0]['n'] ?? 0);
        return ['checked' => (int) ($r['n'] ?? 0), 'disputed' => (int) ($r['up'] ?? 0), 'waiting' => $waiting];
    }

    /** Przeliczenia kosztów i marż w okresie (ostatnie). */
    public function costsRecalc(string $from, string $to): ?array
    {
        return $this->all('SELECT run_at FROM n_margin_recalc_run WHERE run_at >= ? AND run_at < ? ORDER BY run_at DESC LIMIT 1', [$from, $to])[0] ?? null;
    }

    /** Publikacje cen zaplanowane w okresie (bez anulowanych). */
    public function pricesPlanned(string $from, string $to): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM n_price_publication WHERE created_at >= ? AND created_at < ? AND status <> 'cancelled'", [$from, $to])[0]['n'] ?? 0);
    }

    /** Zakupy tygodnia : runda wysłana w okresie albo szkic (w toku). */
    public function buyRound(string $from, string $to): array
    {
        $sent = $this->all('SELECT code, sent_at FROM n_buy_round WHERE sent_at >= ? AND sent_at < ? ORDER BY sent_at DESC LIMIT 1', [$from, $to])[0] ?? null;
        $draft = $this->all("SELECT code, created_at FROM n_buy_round WHERE status = 'draft' AND created_at >= ? AND created_at < ? ORDER BY id DESC LIMIT 1", [$from, $to])[0] ?? null;
        return ['sent' => $sent, 'draft' => $draft];
    }

    /** Prognoza 13 tygodni zatwierdzona w okresie. */
    public function forecastApproved(string $from, string $to): ?array
    {
        return $this->all("SELECT r.cartons, r.approved_at, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS by_name
                FROM n_forecast_run r LEFT JOIN user u ON u.id = r.approved_by WHERE r.approved_at >= ? AND r.approved_at < ? ORDER BY r.approved_at DESC LIMIT 1", [$from, $to])[0] ?? null;
    }

    /** Miesiąc kosztowy (kalkulator) : status i data zamknięcia. */
    public function costMonth(int $year, int $month): ?array
    {
        return $this->all('SELECT id, status FROM cost_month WHERE year = ? AND month = ?', [$year, $month])[0] ?? null;
    }

    /** Koszty usług miesiąca zamknięte w Finansach. */
    public function servicesClosed(string $ym): ?array
    {
        return $this->all('SELECT total, closed_at FROM n_service_month WHERE ym = ?', [$ym])[0] ?? null;
    }

    /** Plan produkcji : zatwierdzony w okresie albo propozycja z okresu (w toku). */
    public function weekPlan(string $from, string $to): array
    {
        $ok = $this->all("SELECT period_start, period_end, version, validated_at FROM n_prod_plan WHERE validated_at >= ? AND validated_at < ? ORDER BY validated_at DESC LIMIT 1", [$from, $to])[0] ?? null;
        $draft = $this->all("SELECT period_start, period_end FROM n_prod_plan WHERE status = 'proposed' AND created_at >= ? AND created_at < ? ORDER BY id DESC LIMIT 1", [$from, $to])[0] ?? null;
        return ['validated' => $ok, 'draft' => $draft];
    }

    /** Plan logistyki : tygodnie zatwierdzone w okresie. */
    public function logisticsApproved(string $from, string $to): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM n_logistics_plan_week WHERE approved_at >= ? AND approved_at < ?', [$from, $to])[0]['n'] ?? 0);
    }

    /** Wysyłka dnia : palety dnia (1 = czeka, 2 = gotowe bez dokumentów, 3 = wysłane) i klienci. */
    public function dayShipping(string $day): array
    {
        $r = $this->all('SELECT SUM(status IN (1, 2)) AS waiting, SUM(status = 3) AS shipped, COUNT(DISTINCT id_customer) AS clients, COUNT(*) AS pallets
                FROM palets_delivery WHERE delivery_date = ?', [$day])[0] ?? [];
        return ['waiting' => (int) ($r['waiting'] ?? 0), 'shipped' => (int) ($r['shipped'] ?? 0), 'clients' => (int) ($r['clients'] ?? 0), 'pallets' => (int) ($r['pallets'] ?? 0)];
    }

    /** Dzień zatwierdzony na tabletach : grupy, które zamknęły dzień. */
    public function dayClosedGroups(string $day): int
    {
        return (int) ($this->all('SELECT COUNT(DISTINCT id_group) AS n FROM n_prod_day_close WHERE work_date = ?', [$day])[0]['n'] ?? 0);
    }

    // --- Tablety [229] : dzień grupy, zmiana, pakowanie, trasa ------------------------------------------------------

    /** Dzień grupy (kod grupy) : zatwierdzony na tablecie (godzina) i pozycje wpisane (zrobione, plan → zrobione). */
    public function groupDay(string $day, string $groupCode): array
    {
        $g = $this->all('SELECT id FROM n_prod_group WHERE code = ?', [$groupCode])[0]['id'] ?? null;
        if ($g === null) return ['group' => false, 'closed_at' => null, 'lines' => 0, 'produced' => 0];
        $closed = $this->all('SELECT MIN(closed_at) AS at FROM n_prod_day_close WHERE work_date = ? AND id_group = ?', [$day, $g])[0]['at'] ?? null;
        $l = $this->all('SELECT COUNT(*) AS n, COALESCE(SUM(produced), 0) AS produced FROM n_prod_day_line WHERE work_date = ? AND id_group = ?', [$day, $g])[0] ?? [];
        return ['group' => true, 'closed_at' => $closed, 'lines' => (int) ($l['n'] ?? 0), 'produced' => (float) ($l['produced'] ?? 0)];
    }

    /** Zmiana : grupy z produkcją w dniu (pozycje na tablecie) i ile z nich zatwierdziło dzień. */
    public function shiftDay(string $day): array
    {
        $worked = array_map('intval', array_column($this->all('SELECT DISTINCT id_group FROM n_prod_day_line WHERE work_date = ?', [$day]), 'id_group'));
        $closed = array_map('intval', array_column($this->all('SELECT DISTINCT id_group FROM n_prod_day_close WHERE work_date = ?', [$day]), 'id_group'));
        $all = array_values(array_unique([...$worked, ...$closed]));
        return ['groups' => count($all), 'closed' => count(array_intersect($all, $closed))];
    }

    /** Pakowanie dnia wysyłki : produkty, spakowane do końca, kartony spakowane / do spakowania, palety dnia. */
    public function packingDay(string $day): array
    {
        $r = $this->all('SELECT COUNT(*) AS n, SUM(packed >= base_count) AS done, COALESCE(SUM(LEAST(packed, base_count)), 0) AS packed, COALESCE(SUM(base_count), 0) AS base
                FROM n_packing_count WHERE ship_date = ? AND base_count > 0', [$day])[0] ?? [];
        $pallets = (int) ($this->all('SELECT COUNT(*) AS n FROM palets_delivery WHERE delivery_date = ?', [$day])[0]['n'] ?? 0);
        return ['products' => (int) ($r['n'] ?? 0), 'done' => (int) ($r['done'] ?? 0), 'packed' => (int) ($r['packed'] ?? 0), 'base' => (int) ($r['base'] ?? 0), 'pallets' => $pallets];
    }

    /** Trasa dnia : przejazdy (rozpoczęte, zakończone), dostawy potwierdzone, palety dnia. */
    public function routeDay(string $day): array
    {
        $r = $this->all('SELECT COUNT(*) AS runs, SUM(started_at IS NOT NULL) AS started, SUM(finished_at IS NOT NULL) AS finished, MAX(finished_at) AS last FROM n_delivery_run WHERE ship_date = ?', [$day])[0] ?? [];
        $stops = $this->all("SELECT COUNT(*) AS n, SUM(status = 'delivered') AS delivered, SUM(status = 'problem') AS problems FROM n_delivery_stop WHERE ship_date = ?", [$day])[0] ?? [];
        $pallets = (int) ($this->all('SELECT COUNT(*) AS n FROM palets_delivery WHERE delivery_date = ?', [$day])[0]['n'] ?? 0);
        return ['runs' => (int) ($r['runs'] ?? 0), 'started' => (int) ($r['started'] ?? 0), 'finished' => (int) ($r['finished'] ?? 0), 'last' => $r['last'] ?? null,
            'stops' => (int) ($stops['n'] ?? 0), 'delivered' => (int) ($stops['delivered'] ?? 0), 'problems' => (int) ($stops['problems'] ?? 0), 'pallets' => $pallets];
    }

    /** Grupy produkcji aktywne (tablety). */
    public function activeGroups(): array
    {
        return $this->all('SELECT id, code, name_pl FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    /** Dni zatwierdzone na tabletach w przedziale [from, to] : grupa i godzina. */
    public function groupCloses(string $from, string $to): array
    {
        return $this->all('SELECT work_date, id_group, MIN(closed_at) AS closed_at FROM n_prod_day_close WHERE work_date BETWEEN ? AND ? GROUP BY work_date, id_group', [$from, $to]);
    }

    // --- Uruchomienia automatyczne ------------------------------------------------------------------------------

    /** @return list<array> uruchomienia zadania w okresie crona (najnowsze najpierw) */
    public function runs(int $taskId, string $periodKey): array
    {
        return $this->all('SELECT * FROM n_task_cron_run WHERE id_task = ? AND period_key = ? ORDER BY id DESC', [$taskId, $periodKey]);
    }

    /** @return array<int, list<array>> ostatnie uruchomienia na zadanie (najnowsze najpierw) */
    public function lastRuns(array $taskIds, int $limit = 3): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT * FROM n_task_cron_run WHERE id_task IN (' . implode(',', $ids) . ') ORDER BY id DESC LIMIT ' . (count($ids) * $limit * 4)) as $r) {
            $id = (int) $r['id_task'];
            if (count($out[$id] ?? []) < $limit) $out[$id][] = $r;
        }
        return $out;
    }

    /** @return list<array> uruchomienia w przedziale [from, to) (widok przełożonego) */
    public function runsBetween(string $from, string $to): array
    {
        return $this->all('SELECT r.*, t.title_pl FROM n_task_cron_run r LEFT JOIN n_position_task t ON t.id = r.id_task WHERE r.started_at >= ? AND r.started_at < ? ORDER BY r.started_at', [$from, $to]);
    }

    public function startRun(int $taskId, string $periodKey, string $action, string $at): int
    {
        $this->db->prepare('INSERT INTO n_task_cron_run (id_task, period_key, action, started_at) VALUES (?, ?, ?, ?)')->execute([$taskId, $periodKey, $action, $at]);
        return (int) $this->db->lastInsertId();
    }

    public function finishRun(int $id, bool $ok, ?string $summary, ?string $error, string $at): void
    {
        $this->db->prepare('UPDATE n_task_cron_run SET finished_at = ?, ok = ?, summary = ?, error = ? WHERE id = ?')
            ->execute([$at, $ok ? 1 : 0, $summary !== null ? mb_substr($summary, 0, 255) : null, $error !== null ? mb_substr($error, 0, 255) : null, $id]);
    }

    /** Zadania z cronem włączonym (aktywne). */
    public function cronTasks(): array
    {
        return $this->all("SELECT t.*, p.name_pl AS position_name FROM n_position_task t JOIN n_position p ON p.id = t.id_position
                WHERE t.active = 1 AND t.cron_on = 1 AND t.cron_action IS NOT NULL AND t.cron_time IS NOT NULL ORDER BY t.id");
    }

    // --- Przypomnienia i spóźnienia ------------------------------------------------------------------------------

    /** Otwarte wystąpienia z terminem do dnia (z zadaniem i stanowiskiem) — przypomnienia i przełożony. */
    public function openUntil(string $day): array
    {
        return $this->all("SELECT o.*, t.title_pl, t.remind_time, t.escalate_days, t.due_time, t.id_position, p.name_pl AS position_name, p.id_reports_to
                FROM n_task_occurrence o JOIN n_position_task t ON t.id = o.id_task AND t.active = 1 JOIN n_position p ON p.id = t.id_position
                WHERE o.status = 'open' AND o.due_date <= ? ORDER BY o.due_date, o.id", [$day]);
    }

    public function markReminded(int $occurrenceId, string $at): void
    {
        $this->db->prepare('UPDATE n_task_occurrence SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL')->execute([$at, $occurrenceId]);
    }

    public function markEscalated(int $occurrenceId, string $at): void
    {
        $this->db->prepare('UPDATE n_task_occurrence SET escalated_at = ? WHERE id = ? AND escalated_at IS NULL')->execute([$at, $occurrenceId]);
    }

    /** Nazwy osób na stanowisku (przypomnienie, przełożony). @return list<string> */
    public function positionPeople(int $positionId): array
    {
        return array_map(fn($r) => (string) $r['name'], $this->all("SELECT COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS name FROM n_user_position up JOIN user u ON u.id = up.id_user WHERE up.id_position = ? ORDER BY name", [$positionId]));
    }

    public function positionName(?int $positionId): ?string
    {
        if ($positionId === null) return null;
        return $this->all('SELECT name_pl FROM n_position WHERE id = ?', [$positionId])[0]['name_pl'] ?? null;
    }
}
