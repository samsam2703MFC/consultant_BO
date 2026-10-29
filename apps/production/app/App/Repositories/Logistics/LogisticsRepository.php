<?php
namespace App\Repositories\Logistics;

use App\Domain\Client\Order\OpenOrders;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Logistyka : pojazdy przewoźników ERP (carrier_vehicle) z pojemnością (n_vehicle), trasy z ich
 * pojazdami i klientami (n_tour*), zamówienia dnia wysyłki z danymi paletowymi produktów (rodzaj
 * kartonu z karty opakowań, kategoria), palety już zeskanowane (palets_delivery) i przydział na dzień.
 */
final class LogisticsRepository extends WorkshopRepository
{
    protected string $table = 'n_vehicle';
    protected array $writable = ['pallet_places', 'max_load_kg', 'departure_time', 'active', 'notes', 'updated_by'];

    public function vehicles(): array
    {
        return $this->all(
            'SELECT cv.id, cv.id_carrier, c.name AS carrier, c.active AS carrier_active, cv.regis_num,
                    TRIM(CONCAT(cv.driver_name, " ", cv.driver_surname)) AS driver, cv.driver_phone,
                    v.pallet_places, v.max_load_kg, TIME_FORMAT(v.departure_time, "%H:%i") AS departure_time,
                    COALESCE(v.active, 0) AS active, v.notes, v.id_carrier_vehicle IS NOT NULL AS configured, v.id_driver
             FROM carrier_vehicle cv JOIN carrier c ON c.id = cv.id_carrier
             LEFT JOIN n_vehicle v ON v.id_carrier_vehicle = cv.id
             ORDER BY COALESCE(v.active, 0) DESC, c.name, cv.regis_num'
        );
    }

    public function vehicleExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM carrier_vehicle WHERE id = ?', [$id]);
    }

    public function carriers(): array
    {
        return $this->all('SELECT id, name FROM carrier WHERE active = 1 ORDER BY name');
    }

    public function carrierExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM carrier WHERE id = ? AND active = 1', [$id]);
    }

    public function addVehicle(int $carrierId, string $regis, string $driverName, string $driverSurname, string $driverPhone, string $driverEmail = ''): int
    {
        $this->db->prepare('INSERT INTO carrier_vehicle (id_carrier, regis_num, driver_name, driver_surname, driver_phone, driver_email) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$carrierId, $regis, $driverName, $driverSurname, $driverPhone, $driverEmail]);
        return (int) $this->db->lastInsertId();
    }

    public function saveVehicle(int $vehicleId, array $data): void
    {
        $data = $this->filter($data);
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO n_vehicle (id_carrier_vehicle, %s) VALUES (:id, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute(['id' => $vehicleId] + $data);
    }

    /** Kierowca pojazdu spośród pracowników (n_prod_employee) ; wiersz n_vehicle już istnieje (addVehicle). */
    public function setVehicleDriver(int $vehicleId, ?int $driverId, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_vehicle SET id_driver = ?, updated_by = ? WHERE id_carrier_vehicle = ?')->execute([$driverId, $userId, $vehicleId]);
    }

    /** Dni wysyłki z zamówieniami (bez zarchiwizowanych) od podanej daty. */
    public function shippingDates(string $from, int $limit = 30): array
    {
        return $this->all(
            "SELECT DATE(co.expected_shipping_date) AS ship_date, COUNT(*) AS orders, COUNT(DISTINCT co.id_customer) AS customers
             FROM customer_order co WHERE " . OpenOrders::loadingSql('co') . " AND co.expected_shipping_date >= ?
             GROUP BY ship_date ORDER BY ship_date LIMIT " . max(1, $limit),
            [$from]
        );
    }

    /** Pozycje zamówień dnia z danymi paletowymi produktu. */
    public function dayLines(string $date): array
    {
        return $this->all(
            "SELECT co.id AS order_id, co.status, co.id_customer,
                    COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname))) AS customer,
                    cu.fac_town AS town, cu.fac_country AS country,
                    cop.id_product AS product_id, fp.name AS product, cop.quantity_carton AS cartons,
                    fp.cpp, fp.cpl, fp.lpp, fp.ppc, fp.weight AS weight_g,
                    sp.id_outer_raw_material AS carton_type_id,
                    COALESCE(CONCAT('Karton ', cd.length, ' × ', cd.width, ' × ', cd.height), rm.name) AS carton_type,
                    cat.name AS category
             FROM customer_order co
             JOIN customer cu ON cu.id = co.id_customer
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             LEFT JOIN final_product_ppwr_sales_packaging sp ON sp.id_final_product = fp.id
             LEFT JOIN carton_dimension cd ON cd.id = sp.id_outer_raw_material
             LEFT JOIN raw_material rm ON rm.id = sp.id_outer_raw_material
             LEFT JOIN category_product cat ON cat.id = fp.id_category
             WHERE DATE(co.expected_shipping_date) = ? AND " . OpenOrders::loadingSql('co') . " AND cop.quantity_carton > 0
             ORDER BY customer, co.id, fp.name",
            [$date]
        );
    }

    /** @return array<int,int> klient => palety zeskanowane na ten dzień */
    public function scannedPallets(string $date): array
    {
        return array_map('intval', array_column($this->all(
            'SELECT id_customer, COUNT(*) AS n FROM palets_delivery WHERE delivery_date = ? GROUP BY id_customer', [$date]
        ), 'n', 'id_customer'));
    }

    /** @return array<int,array{tour_set:bool, tour:?int, vehicle_set:bool, vehicle:?int, stop:?int}> */
    public function assignments(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, id_tour, tour_set, id_carrier_vehicle, vehicle_set, stop_order FROM n_shipping_assignment WHERE ship_date = ?', [$date]) as $r) {
            $out[(int) $r['id_customer']] = [
                'tour_set' => (bool) (int) $r['tour_set'], 'tour' => $r['id_tour'] !== null ? (int) $r['id_tour'] : null,
                'vehicle_set' => (bool) (int) $r['vehicle_set'], 'vehicle' => $r['id_carrier_vehicle'] !== null ? (int) $r['id_carrier_vehicle'] : null,
                'stop' => $r['stop_order'] !== null ? (int) $r['stop_order'] : null,
            ];
        }
        return $out;
    }

    // --- Trasy -------------------------------------------------------------------------------------

    public function tours(): array
    {
        return $this->all('SELECT id, name, weekdays, TIME_FORMAT(departure_time, "%H:%i") AS departure_time, color, active, notes FROM n_tour ORDER BY active DESC, name');
    }

    public function tour(int $id): ?array
    {
        return $this->all('SELECT id, name, weekdays, TIME_FORMAT(departure_time, "%H:%i") AS departure_time, color, active, notes FROM n_tour WHERE id = ?', [$id])[0] ?? null;
    }

    /** Pojazdy tras w kolejności wypełniania. */
    public function tourVehicles(): array
    {
        return $this->all('SELECT id_tour, id_carrier_vehicle, position FROM n_tour_vehicle ORDER BY id_tour, position, id_carrier_vehicle');
    }

    /** Klienci tras w kolejności rozładunku ; trasy po nazwie (pierwsza wygrywa, gdy klient jest w kilku). */
    public function tourCustomers(): array
    {
        return $this->all(
            "SELECT tc.id_tour, tc.id_customer, tc.stop_order,
                    COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname))) AS customer, cu.fac_town AS town, cu.fac_country AS country, cu.active
             FROM n_tour_customer tc JOIN n_tour t ON t.id = tc.id_tour JOIN customer cu ON cu.id = tc.id_customer
             ORDER BY t.name, tc.id_tour, tc.stop_order IS NULL, tc.stop_order, customer"
        );
    }

    public function tourNameTaken(string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_tour WHERE name = ? AND id <> ?', [$name, $exceptId ?? 0]);
    }

    public function insertTour(array $data): int
    {
        $columns = array_keys($data);
        $this->db->prepare(sprintf('INSERT INTO n_tour (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))))->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateTour(int $id, array $data): void
    {
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE n_tour SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    }

    public function deleteTour(int $id): void
    {
        $this->db->prepare('DELETE FROM n_tour WHERE id = ?')->execute([$id]);
    }

    /** @param list<int> $vehicleIds w kolejności wypełniania */
    public function setTourVehicles(int $tourId, array $vehicleIds): void
    {
        $this->transaction(function () use ($tourId, $vehicleIds) {
            $this->db->prepare('DELETE FROM n_tour_vehicle WHERE id_tour = ?')->execute([$tourId]);
            $insert = $this->db->prepare('INSERT INTO n_tour_vehicle (id_tour, id_carrier_vehicle, position) VALUES (?, ?, ?)');
            foreach (array_values($vehicleIds) as $i => $vehicleId) $insert->execute([$tourId, $vehicleId, $i + 1]);
        });
    }

    /**
     * Klienci trasy ; każdy znika z innych tras jeżdżących w te same dni tygodnia (jak w załadunku dnia).
     * @param list<array{id:int, stop:?int}> $customers
     * @return list<array{customer:string, tour:string}> przeniesieni z innych tras
     */
    public function setTourCustomers(int $tourId, array $customers, int $weekdays): array
    {
        return $this->transaction(function () use ($tourId, $customers, $weekdays) {
            $moved = [];
            $ids = array_map(fn($c) => (int) $c['id'], $customers);
            if ($ids && $weekdays) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $moved = $this->all(
                    "SELECT COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname))) AS customer, t.name AS tour
                     FROM n_tour_customer tc JOIN n_tour t ON t.id = tc.id_tour JOIN customer cu ON cu.id = tc.id_customer
                     WHERE tc.id_tour <> ? AND (t.weekdays & ?) <> 0 AND tc.id_customer IN ($in) ORDER BY customer, t.name",
                    array_merge([$tourId, $weekdays], $ids)
                );
                $this->db->prepare("DELETE tc FROM n_tour_customer tc JOIN n_tour t ON t.id = tc.id_tour WHERE tc.id_tour <> ? AND (t.weekdays & ?) <> 0 AND tc.id_customer IN ($in)")
                    ->execute(array_merge([$tourId, $weekdays], $ids));
            }
            $this->db->prepare('DELETE FROM n_tour_customer WHERE id_tour = ?')->execute([$tourId]);
            $insert = $this->db->prepare('INSERT INTO n_tour_customer (id_tour, id_customer, stop_order) VALUES (?, ?, ?)');
            foreach ($customers as $c) $insert->execute([$tourId, $c['id'], $c['stop']]);
            return $moved;
        });
    }

    public function tourStop(int $tourId, int $customerId): ?array
    {
        return $this->all('SELECT stop_order FROM n_tour_customer WHERE id_tour = ? AND id_customer = ?', [$tourId, $customerId])[0] ?? null;
    }

    public function nextTourStop(int $tourId): int
    {
        return (int) $this->all('SELECT COALESCE(MAX(stop_order), 0) + 1 AS n FROM n_tour_customer WHERE id_tour = ?', [$tourId])[0]['n'];
    }

    /** Klient na stałe w trasie ; usunięty z innych tras jeżdżących w te same dni tygodnia. */
    public function putCustomerOnTour(int $tourId, int $customerId, int $stop, int $weekdays): void
    {
        $this->transaction(function () use ($tourId, $customerId, $stop, $weekdays) {
            $this->db->prepare('DELETE tc FROM n_tour_customer tc JOIN n_tour t ON t.id = tc.id_tour WHERE tc.id_customer = ? AND tc.id_tour <> ? AND (t.weekdays & ?) <> 0')
                ->execute([$customerId, $tourId, $weekdays]);
            $this->db->prepare('INSERT INTO n_tour_customer (id_tour, id_customer, stop_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stop_order = VALUES(stop_order)')
                ->execute([$tourId, $customerId, $stop]);
        });
    }

    public function removeCustomerFromTour(int $tourId, int $customerId): void
    {
        $this->db->prepare('DELETE FROM n_tour_customer WHERE id_tour = ? AND id_customer = ?')->execute([$tourId, $customerId]);
    }

    /** Klienci do dodania do trasy : aktywni najpierw. */
    public function customers(): array
    {
        return $this->all(
            "SELECT id, COALESCE(NULLIF(TRIM(company), ''), TRIM(CONCAT(name, ' ', surname))) AS name, fac_town AS town, fac_country AS country, active
             FROM customer ORDER BY active DESC, name"
        );
    }

    /** @return array<int,int> klient => pojazd z ostatniego CMR */
    public function lastVehicles(array $customerIds): array
    {
        if (!$customerIds) return [];
        $in = implode(', ', array_fill(0, count($customerIds), '?'));
        return array_map('intval', array_column($this->all(
            "SELECT cmr.id_customer, cmr.id_carrier_vehicle FROM document_cmr cmr
             JOIN (SELECT id_customer, MAX(id) AS id FROM document_cmr WHERE id_customer IN ({$in}) AND id_carrier_vehicle IS NOT NULL AND id_carrier_vehicle > 0 GROUP BY id_customer) last ON last.id = cmr.id
             JOIN carrier_vehicle cv ON cv.id = cmr.id_carrier_vehicle",
            array_values($customerIds)
        ), 'id_carrier_vehicle', 'id_customer'));
    }

    public function customerExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM customer WHERE id = ?', [$id]);
    }

    public function saveAssignment(string $date, int $customerId, bool $tourSet, ?int $tourId, bool $vehicleSet, ?int $vehicleId, ?int $stop, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_shipping_assignment (ship_date, id_customer, id_tour, tour_set, id_carrier_vehicle, vehicle_set, stop_order, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id_tour = VALUES(id_tour), tour_set = VALUES(tour_set), id_carrier_vehicle = VALUES(id_carrier_vehicle),
                                     vehicle_set = VALUES(vehicle_set), plan_set = 0, stop_order = VALUES(stop_order), updated_by = VALUES(updated_by)'
        )->execute([$date, $customerId, $tourSet ? $tourId : null, $tourSet ? 1 : 0, $vehicleSet ? $vehicleId : null, $vehicleSet ? 1 : 0, $stop, $userId]);
    }

    public function product(int $id): ?array
    {
        return $this->all('SELECT id, name, cpl, lpp, cpp FROM final_product WHERE id = ?', [$id])[0] ?? null;
    }

    public function savePalletData(int $productId, int $cpl, int $lpp, int $cpp): void
    {
        $this->db->prepare('UPDATE final_product SET cpl = ?, lpp = ?, cpp = ? WHERE id = ?')->execute([$cpl, $lpp, $cpp, $productId]);
    }

    private function transaction(callable $fn): mixed
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $result = $fn();
            if ($own) $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }
}
