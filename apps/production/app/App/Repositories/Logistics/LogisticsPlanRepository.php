<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Plan logistyki na 12 tygodni [200] : linie zamówień (historia i przyszłość) do palet, plan tygodni (wyjazd,
 * zatwierdzenie), klienci → palety i ciężarówki, ciężarówki w tygodniu (kierowca, dostępność), dodatkowe palety,
 * współczynniki klientów, urlopy kierowców, dni wolne dodane / pominięte.
 */
final class LogisticsPlanRepository extends WorkshopRepository
{
    protected string $table = 'n_logistics_plan_week';

    private const CUSTOMER = "COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname)))";

    /**
     * Linie zamówień w okresie (data wysyłki) z danymi paletowymi produktu — jak dzień wysyłki ; zamówienia
     * zrealizowane, w transporcie, oczekujące i zarchiwizowane, bez gratisów.
     */
    public function orderLines(string $from, string $to): array
    {
        return $this->all(
            'SELECT co.id_customer, DATE(co.expected_shipping_date) AS ship_date, cop.id_product AS product_id, fp.name AS product,
                    cop.quantity_carton AS cartons, fp.cpp, fp.cpl, fp.lpp, fp.ppc, fp.weight AS weight_g,
                    sp.id_outer_raw_material AS carton_type_id, cat.name AS category
             FROM customer_order co
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             LEFT JOIN final_product_ppwr_sales_packaging sp ON sp.id_final_product = fp.id
             LEFT JOIN category_product cat ON cat.id = fp.id_category
             WHERE co.expected_shipping_date >= ? AND co.expected_shipping_date < DATE_ADD(?, INTERVAL 1 DAY)
               AND co.status IN (1, 2, 3, 5) AND COALESCE(co.is_freebie, 0) = 0 AND cop.quantity_carton > 0
             ORDER BY co.id_customer, ship_date',
            [$from, $to]
        );
    }

    /** @return array<int, array{id:int, name:string, town:?string, country:?string}> */
    public function customers(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all('SELECT cu.id, ' . self::CUSTOMER . " AS name, cu.fac_town AS town, cu.fac_country AS country
                FROM customer cu WHERE cu.id IN ({$in}) ORDER BY name", array_values($ids)) as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'town' => $r['town'], 'country' => $r['country']];
        }
        return $out;
    }

    // --- Plan ---------------------------------------------------------------------------------------

    /** @return array<string, array> poniedziałek => wiersz */
    public function weeks(string $from, string $to): array
    {
        return array_column($this->all('SELECT week_start, ship_date, approved_at, approved_by FROM n_logistics_plan_week WHERE week_start BETWEEN ? AND ?', [$from, $to]), null, 'week_start');
    }

    public function saveWeek(string $week, array $data, ?int $userId): void
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_logistics_plan_week (week_start, ' . implode(', ', $cols) . ', updated_by) VALUES (?, '
            . implode(', ', array_fill(0, count($cols), '?')) . ', ?) ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)) . ', updated_by = VALUES(updated_by)')
            ->execute([$week, ...array_values($data), $userId]);
    }

    /** Zmiana w zatwierdzonym tygodniu : zatwierdzenie do ponowienia. */
    public function unapprove(string $week): void
    {
        $this->db->prepare('UPDATE n_logistics_plan_week SET approved_at = NULL, approved_by = NULL WHERE week_start = ?')->execute([$week]);
    }

    /** @return list<array{week_start:string, id_customer:int, pallets:?int, id_carrier_vehicle:?int}> */
    public function loads(string $from, string $to): array
    {
        return array_map(fn($r) => ['week_start' => $r['week_start'], 'id_customer' => (int) $r['id_customer'],
            'pallets' => $r['pallets'] !== null ? (int) $r['pallets'] : null, 'id_carrier_vehicle' => $r['id_carrier_vehicle'] !== null ? (int) $r['id_carrier_vehicle'] : null],
            $this->all('SELECT week_start, id_customer, pallets, id_carrier_vehicle FROM n_logistics_plan_load WHERE week_start BETWEEN ? AND ?', [$from, $to]));
    }

    /** « Wszystko do rozplanowania » : ciężarówki zdjęte w tygodniach (palety wpisane ręcznie zostają). @return int klientów zdjętych */
    public function clearVehicles(array $weeks): int
    {
        if (!$weeks) return 0;
        $in = implode(', ', array_fill(0, count($weeks), '?'));
        $st = $this->db->prepare("UPDATE n_logistics_plan_load SET id_carrier_vehicle = NULL WHERE id_carrier_vehicle IS NOT NULL AND week_start IN ({$in})");
        $st->execute($weeks);
        $this->db->prepare("DELETE FROM n_logistics_plan_load WHERE pallets IS NULL AND id_carrier_vehicle IS NULL AND week_start IN ({$in})")->execute($weeks);
        return $st->rowCount();
    }

    /** Palety i / albo ciężarówka klienta w tygodniu (tylko podane pola) ; pusty wiersz usunięty. */
    public function saveLoad(string $week, int $customerId, array $data, ?int $userId): void
    {
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO n_logistics_plan_load (week_start, id_customer, ' . implode(', ', $cols) . ', updated_by) VALUES (?, ?, '
            . implode(', ', array_fill(0, count($cols), '?')) . ', ?) ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)) . ', updated_by = VALUES(updated_by)')
            ->execute([$week, $customerId, ...array_values($data), $userId]);
        $this->db->prepare('DELETE FROM n_logistics_plan_load WHERE week_start = ? AND id_customer = ? AND pallets IS NULL AND id_carrier_vehicle IS NULL')
            ->execute([$week, $customerId]);
    }

    /** @return array<string, array<int, array{id_driver:?int, unavailable:bool}>> tydzień => pojazd => … */
    public function vehicleWeeks(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT week_start, id_carrier_vehicle, id_driver, unavailable FROM n_logistics_plan_vehicle WHERE week_start BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[$r['week_start']][(int) $r['id_carrier_vehicle']] = ['id_driver' => $r['id_driver'] !== null ? (int) $r['id_driver'] : null, 'unavailable' => (bool) (int) $r['unavailable']];
        }
        return $out;
    }

    public function saveVehicleWeek(string $week, int $vehicleId, ?int $driverId, bool $unavailable, ?int $userId): void
    {
        if ($driverId === null && !$unavailable) {
            $this->db->prepare('DELETE FROM n_logistics_plan_vehicle WHERE week_start = ? AND id_carrier_vehicle = ?')->execute([$week, $vehicleId]);
            return;
        }
        $this->db->prepare('INSERT INTO n_logistics_plan_vehicle (week_start, id_carrier_vehicle, id_driver, unavailable, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id_driver = VALUES(id_driver), unavailable = VALUES(unavailable), updated_by = VALUES(updated_by)')
            ->execute([$week, $vehicleId, $driverId, $unavailable ? 1 : 0, $userId]);
    }

    // --- Dodatkowe palety, współczynniki --------------------------------------------------------------

    /** Wszystkie wydarzenia (tydzień planu albo co roku z wcześniejszych lat). */
    public function events(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'week_start' => $r['week_start'], 'name' => $r['name'], 'pct' => (float) $r['pct'], 'yearly' => (bool) (int) $r['yearly']],
            $this->all('SELECT id, week_start, name, pct, yearly FROM n_logistics_event ORDER BY week_start'));
    }

    public function event(int $id): ?array
    {
        return $this->all('SELECT id, week_start, name, pct, yearly FROM n_logistics_event WHERE id = ?', [$id])[0] ?? null;
    }

    public function saveEvent(string $week, string $name, float $pct, bool $yearly, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_logistics_event (week_start, name, pct, yearly, created_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), pct = VALUES(pct), yearly = VALUES(yearly), id = LAST_INSERT_ID(id)')
            ->execute([$week, $name, $pct, $yearly ? 1 : 0, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteEvent(int $id): void
    {
        $this->db->prepare('DELETE FROM n_logistics_event WHERE id = ?')->execute([$id]);
    }

    /** @return array<int, float> klient => współczynnik wpisany */
    public function coefs(): array
    {
        return array_map('floatval', array_column($this->all('SELECT id_customer, coef FROM n_logistics_customer_coef'), 'coef', 'id_customer'));
    }

    public function saveCoef(int $customerId, ?float $coef, ?int $userId): void
    {
        if ($coef === null) {
            $this->db->prepare('DELETE FROM n_logistics_customer_coef WHERE id_customer = ?')->execute([$customerId]);
            return;
        }
        $this->db->prepare('INSERT INTO n_logistics_customer_coef (id_customer, coef, updated_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE coef = VALUES(coef), updated_by = VALUES(updated_by)')->execute([$customerId, $coef, $userId]);
    }

    // --- Kierowcy i urlopy ----------------------------------------------------------------------------

    /** Pracownicy (stanowisko kierowcy albo kierowca pojazdu) : id => imię i nazwisko. */
    public function drivers(): array
    {
        $out = [];
        foreach ($this->all("SELECT e.id, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS name, e.active,
                    (p.code = 'KIEROWCA') AS is_driver
                FROM n_prod_employee e
                JOIN production_employee pe ON pe.id = e.id_production_employee
                LEFT JOIN n_position p ON p.id = e.id_position
                WHERE (e.active = 1 AND p.code = 'KIEROWCA') OR e.id IN (SELECT id_driver FROM n_vehicle WHERE id_driver IS NOT NULL)
                ORDER BY is_driver DESC, name") as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (bool) (int) $r['active']];
        }
        return $out;
    }

    public function employeeExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_prod_employee WHERE id = ?', [$id]);
    }

    /** Urlopy zachodzące na okres. */
    public function absences(string $from, string $to): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'id_employee' => (int) $r['id_employee'], 'date_from' => $r['date_from'], 'date_to' => $r['date_to'], 'reason' => $r['reason']],
            $this->all('SELECT id, id_employee, date_from, date_to, reason FROM n_logistics_absence WHERE date_to >= ? AND date_from <= ? ORDER BY date_from, id', [$from, $to]));
    }

    public function addAbsence(int $employeeId, string $from, string $to, ?string $reason, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_logistics_absence (id_employee, date_from, date_to, reason, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$employeeId, $from, $to, $reason, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteAbsence(int $id): bool
    {
        $st = $this->db->prepare('DELETE FROM n_logistics_absence WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    // --- Dni wolne -------------------------------------------------------------------------------------

    /** @return array<string, array<string, array{name:string, off:bool}>> data => kraj => … */
    public function holidays(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT day, country, name, off FROM n_logistics_holiday WHERE day BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[$r['day']][$r['country']] = ['name' => $r['name'], 'off' => (bool) (int) $r['off']];
        }
        return $out;
    }

    public function saveHoliday(string $day, string $country, string $name, bool $off, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_logistics_holiday (day, country, name, off, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), off = VALUES(off), updated_by = VALUES(updated_by)')->execute([$day, $country, $name, $off ? 1 : 0, $userId]);
    }

    public function deleteHoliday(string $day, string $country): void
    {
        $this->db->prepare('DELETE FROM n_logistics_holiday WHERE day = ? AND country = ?')->execute([$day, $country]);
    }

    // --- Zatwierdzenie -----------------------------------------------------------------------------------

    /**
     * Pojazd klienta na dzień wyjazdu (Dzień wysyłki, Załadunek) — tylko gdy nie wybrano go tam ręcznie ; trasa
     * dnia bez zmian. @return bool zapisano (false : pojazd dnia wybrany wcześniej ręcznie)
     */
    public function planAssignment(string $date, int $customerId, int $vehicleId, ?int $userId): bool
    {
        $row = $this->all('SELECT vehicle_set, plan_set FROM n_shipping_assignment WHERE ship_date = ? AND id_customer = ?', [$date, $customerId])[0] ?? null;
        if ($row !== null && (int) $row['vehicle_set'] && !(int) $row['plan_set']) return false;
        $this->db->prepare('INSERT INTO n_shipping_assignment (ship_date, id_customer, id_tour, tour_set, id_carrier_vehicle, vehicle_set, plan_set, stop_order, updated_by)
            VALUES (?, ?, NULL, 0, ?, 1, 1, NULL, ?)
            ON DUPLICATE KEY UPDATE id_carrier_vehicle = VALUES(id_carrier_vehicle), vehicle_set = 1, plan_set = 1, updated_by = VALUES(updated_by)')
            ->execute([$date, $customerId, $vehicleId, $userId]);
        return true;
    }

    /**
     * Pojazdy dnia zapisane z planu (plan_set = 1) w dniach od $from do $to zdjęte : dzień wraca do trasy klienta ;
     * wybory ręczne w Dniu wysyłki / Załadunku bez zmian. @return int pozycji zdjętych
     */
    public function clearPlanAssignments(string $from, string $to): int
    {
        $st = $this->db->prepare('DELETE FROM n_shipping_assignment WHERE plan_set = 1 AND tour_set = 0 AND stop_order IS NULL AND ship_date BETWEEN ? AND ?');
        $st->execute([$from, $to]);
        $up = $this->db->prepare('UPDATE n_shipping_assignment SET id_carrier_vehicle = NULL, vehicle_set = 0, plan_set = 0 WHERE plan_set = 1 AND ship_date BETWEEN ? AND ?');
        $up->execute([$from, $to]);
        return $st->rowCount() + $up->rowCount();
    }
}
