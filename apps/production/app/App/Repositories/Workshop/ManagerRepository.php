<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use App\Repositories\Analysis\WdtSalesRepository;

/**
 * Kierownik produkcji : plan roku, cele (wersje), wartości ręczne, zdarzenia HACCP, nowe produkty, działania,
 * audyty kompetencji ; odczyty danych ERP do wskaźników (straty, dostawy, kartony, nadgodziny, koszty logistyki).
 */
final class ManagerRepository extends WorkshopRepository
{
    protected string $table = 'tfb_mgr_plan';
    protected array $writable = ['year', 'holder_user_id', 'holder_name', 'holder_start', 'salary_monthly', 'envelope_pct', 'currency', 'team_organisation',
        'auditor', 'audit_threshold', 'margin_threshold_pct', 'price_reference_date', 'workplace', 'contract_type', 'working_time', 'monthly_hours', 'mission_hours', 'updated_by'];

    public function plan(int $year): ?array
    {
        return $this->all('SELECT * FROM tfb_mgr_plan WHERE year = ?', [$year])[0] ?? null;
    }

    public function savePlan(int $year, array $data): int
    {
        $plan = $this->plan($year);
        if ($plan === null) return $this->insert(['year' => $year] + $data);
        $this->update((int) $plan['id'], $data);
        return (int) $plan['id'];
    }

    /** Wszystkie wersje celów planu (najnowsze najpierw). */
    public function objectives(int $planId): array
    {
        return $this->all('SELECT id, indicator, target, validated_at, validated_by, created_by, created_at FROM tfb_mgr_objective WHERE id_plan = ? ORDER BY id DESC', [$planId]);
    }

    /** Nowy szkic celu (poprzedni szkic tego wskaźnika znika ; wersje zatwierdzone zostają). */
    public function saveDraft(int $planId, string $indicator, ?float $target, ?int $userId): void
    {
        $this->db->prepare('DELETE FROM tfb_mgr_objective WHERE id_plan = ? AND indicator = ? AND validated_at IS NULL')->execute([$planId, $indicator]);
        if ($target === null) return;
        $this->db->prepare('INSERT INTO tfb_mgr_objective (id_plan, indicator, target, created_by) VALUES (?, ?, ?, ?)')->execute([$planId, $indicator, $target, $userId]);
    }

    /** Zatwierdza szkice planu ; zwraca ich liczbę. */
    public function validateDrafts(int $planId, ?int $userId, string $at): int
    {
        $st = $this->db->prepare('UPDATE tfb_mgr_objective SET validated_at = ?, validated_by = ? WHERE id_plan = ? AND validated_at IS NULL');
        $st->execute([$at, $userId, $planId]);
        return $st->rowCount();
    }

    /** @return array<string, array<string, array{value:float, note:?string, entered_by:?int}>> miesiąc → wskaźnik → wartość */
    public function values(string $fromMonth, string $toMonth): array
    {
        $out = [];
        foreach ($this->all('SELECT month, indicator, value, note, entered_by, entered_at FROM tfb_mgr_value WHERE month BETWEEN ? AND ?', [$fromMonth, $toMonth]) as $r) {
            $out[$r['month']][$r['indicator']] = ['value' => (float) $r['value'], 'note' => $r['note'], 'entered_by' => $r['entered_by'] !== null ? (int) $r['entered_by'] : null, 'entered_at' => $r['entered_at']];
        }
        return $out;
    }

    public function saveValue(string $month, string $indicator, ?float $value, ?string $note, ?int $userId): void
    {
        if ($value === null) {
            $this->db->prepare('DELETE FROM tfb_mgr_value WHERE month = ? AND indicator = ?')->execute([$month, $indicator]);
            return;
        }
        $this->db->prepare('INSERT INTO tfb_mgr_value (month, indicator, value, note, entered_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE value = VALUES(value), note = VALUES(note), entered_by = VALUES(entered_by)')
            ->execute([$month, $indicator, $value, $note, $userId]);
    }

    public function haccpEvents(string $from, string $to): array
    {
        return $this->all('SELECT * FROM tfb_mgr_haccp_event WHERE event_date BETWEEN ? AND ? ORDER BY event_date, id', [$from, $to]);
    }

    public function haccpEvent(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_mgr_haccp_event WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertHaccp(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO tfb_mgr_haccp_event (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateHaccp(int $id, array $data): void
    {
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE tfb_mgr_haccp_event SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    }

    public function deleteHaccp(int $id): void
    {
        $this->db->prepare('DELETE FROM tfb_mgr_haccp_event WHERE id = ?')->execute([$id]);
    }

    /** Nowe produkty : z celami wprowadzenia albo utworzone w ERP od danego dnia. */
    public function launches(string $createdSince): array
    {
        return $this->all(
            "SELECT fp.id AS product_id, fp.name, fp.ppc, DATE(fp.create_timestamp) AS created, fp.active,
                    l.launch_date, l.target_cost, l.target_price, l.min_margin_pct, l.expected_week_shop, l.decision, l.decision_note, l.updated_by
             FROM final_product fp
             LEFT JOIN tfb_mgr_launch l ON l.id_product = fp.id
             WHERE l.id IS NOT NULL OR fp.create_timestamp >= ?
             ORDER BY COALESCE(l.launch_date, DATE(fp.create_timestamp)) DESC, fp.name",
            [$createdSince]
        );
    }

    public function productExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM final_product WHERE id = ?', [$id]);
    }

    public function saveLaunch(int $productId, array $data): void
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO tfb_mgr_launch (id_product, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')
                            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)))
            ->execute([$productId, ...array_values($data)]);
    }

    public function actions(string $month): array
    {
        return $this->all('SELECT position, text, owner, due_date, status, created_by FROM tfb_mgr_action WHERE month = ? ORDER BY position', [$month]);
    }

    /** @param list<array{text:string, owner:?string, due_date:?string, status:string}> $actions */
    public function saveActions(string $month, array $actions, ?int $userId): void
    {
        $this->db->prepare('DELETE FROM tfb_mgr_action WHERE month = ?')->execute([$month]);
        $st = $this->db->prepare('INSERT INTO tfb_mgr_action (month, position, text, owner, due_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (array_values($actions) as $i => $a) $st->execute([$month, $i + 1, $a['text'], $a['owner'], $a['due_date'], $a['status'], $userId]);
    }

    public function audits(): array
    {
        return $this->all('SELECT * FROM tfb_mgr_competency_audit ORDER BY audit_date DESC, id DESC');
    }

    public function insertAudit(array $data): int
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO tfb_mgr_competency_audit (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    /** Konta ERP (do wyboru osoby na stanowisku). */
    public function users(): array
    {
        return $this->all("SELECT id, TRIM(CONCAT(COALESCE(name, ''), ' ', COALESCE(surname, ''))) AS name, login FROM user ORDER BY name, login");
    }

    // --- Dane ERP do wskaźników -------------------------------------------------------------------------------

    /** Straty pracowni : sztuki dobre i odrzucone zakończonych operacji (tablety) na [from, to]. */
    /** Dobre i stracone : operacje zleceń zakończone na tabletach + partie dnia i straty zapisane przez zespoły. */
    public function scrap(string $from, string $to): array
    {
        $r = $this->all("SELECT COUNT(*) AS n, COALESCE(SUM(qty_good), 0) AS good, COALESCE(SUM(qty_scrap), 0) AS scrap FROM tfb_prod_operation
                         WHERE status = 'done' AND kind = 'work' AND actual_end BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59'])[0];
        $o = $this->all('SELECT COUNT(*) AS n, COALESCE(SUM(quantity), 0) AS q FROM tfb_prod_output WHERE cancelled_at IS NULL AND work_date BETWEEN ? AND ?', [$from, $to])[0];
        $w = (float) $this->all('SELECT COALESCE(SUM(quantity), 0) AS q FROM tfb_prod_waste WHERE cancelled_at IS NULL AND work_date BETWEEN ? AND ?', [$from, $to])[0]['q'];
        return ['operations' => (int) $r['n'] + (int) $o['n'], 'good' => (float) $r['good'] + max(0.0, (float) $o['q'] - $w), 'scrap' => (float) $r['scrap'] + $w];
    }

    /**
     * Zamówienia z terminem wysyłki w [from, to] (do dzisiaj) : na czas = WDT wysłane najpóźniej w terminie ;
     * kompletne = żadna linia nie wysłana w mniejszej ilości niż zamówiona.
     */
    public function deliveries(string $from, string $to): array
    {
        $r = $this->all(
            "SELECT COUNT(*) AS n,
                    SUM(w.shipping_date IS NOT NULL AND w.shipping_date <= o.expected_shipping_date) AS on_time,
                    SUM(w.shipping_date IS NOT NULL AND w.shipping_date <= o.expected_shipping_date
                        AND NOT EXISTS (SELECT 1 FROM customer_order_product p WHERE p.id_order = o.id AND p.expected_quantity_carton < p.quantity_carton)) AS otif
             FROM customer_order o
             LEFT JOIN document_wdt w ON w.id = o.id_wdt
             WHERE o.status IN (2, 3) AND o.expected_shipping_date BETWEEN ? AND ? AND o.expected_shipping_date <= CURDATE()",
            [$from, $to]
        )[0];
        return ['orders' => (int) $r['n'], 'on_time' => (int) $r['on_time'], 'otif' => (int) $r['otif']];
    }

    /** Kartony wyprodukowane (etykieta = wejście na magazyn) na [from, to]. */
    public function cartonsMade(string $from, string $to): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM carton_stock WHERE date_add BETWEEN ? AND ?', [$from, $to])[0]['n'] ?? 0);
    }

    /** Kartony na magazynie w danym dniu : weszły do tego dnia i nie wyjechały (paleta wysłana po tym dniu albo jeszcze nie). */
    public function cartonsInStock(string $day): int
    {
        return (int) ($this->all("SELECT COUNT(*) AS n FROM carton_stock WHERE date_add <= ? AND (status IN (1, 2) OR (status = 3 AND date_pallet > ?))",
            [$day, $day . ' 23:59:59'])[0]['n'] ?? 0);
    }

    /** Kartony sprzedane (WDT) na [from, to]. */
    public function cartonsSold(string $from, string $to): float
    {
        return (float) ($this->all('SELECT COALESCE(SUM(s.cartons), 0) AS n FROM (' . WdtSalesRepository::linesSql() . ') s
                                    WHERE s.sale_date BETWEEN ? AND ?', [$from, $to])[0]['n'] ?? 0);
    }

    /** Wejścia i wyjścia (zamknięte) z [from, to]. */
    public function clocks(string $from, string $to): array
    {
        return $this->all('SELECT id_employee, clock_in, clock_out FROM tfb_prod_clock WHERE clock_out IS NOT NULL AND clock_in BETWEEN ? AND ?', [$from . ' 00:00:00', $to . ' 23:59:59']);
    }

    /** Koszty logistyki z kalkulatora kosztów (PLN) dla miesięcy 'YYYY-MM' ; brak miesiąca = brak klucza. @return array<string, float> */
    public function logisticsCosts(array $months): array
    {
        $out = [];
        foreach ($this->all('SELECT cm.year, cm.month, COALESCE(SUM(l.amount), 0) AS amount, COUNT(l.id) AS n FROM cost_month cm
                             LEFT JOIN cost_month_logistics l ON l.month_id = cm.id GROUP BY cm.id, cm.year, cm.month') as $r) {
            $ym = sprintf('%04d-%02d', $r['year'], $r['month']);
            if (in_array($ym, $months, true) && (int) $r['n'] > 0) $out[$ym] = (float) $r['amount'];
        }
        return $out;
    }

    /** Sprzedaż produktów (WDT) na [from, to] : kartony i liczba klientów (sklepów / franczyzobiorców), którzy kupili. */
    public function productShops(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT s.id_product, SUM(s.cartons) AS cartons, COUNT(DISTINCT s.id_customer) AS shops FROM (' . WdtSalesRepository::linesSql() . ') s
                             WHERE s.sale_date BETWEEN ? AND ?
                             GROUP BY s.id_product', [$from, $to]) as $r) {
            $out[(int) $r['id_product']] = ['cartons' => (float) $r['cartons'], 'shops' => (int) $r['shops']];
        }
        return $out;
    }
}
