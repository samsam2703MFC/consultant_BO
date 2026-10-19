<?php
namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Logistyka : pojazdy przewoźników ERP (carrier_vehicle) z pojemnością (tfb_vehicle), zamówienia
 * dnia wysyłki z danymi paletowymi produktów, palety już zeskanowane (palets_delivery), stała trasa
 * klienta i przydział na dzień.
 */
final class LogisticsRepository extends WorkshopRepository
{
    protected string $table = 'tfb_vehicle';
    protected array $writable = ['pallet_places', 'max_load_kg', 'departure_time', 'active', 'notes', 'updated_by'];

    public function vehicles(): array
    {
        return $this->all(
            'SELECT cv.id, cv.id_carrier, c.name AS carrier, c.active AS carrier_active, cv.regis_num,
                    TRIM(CONCAT(cv.driver_name, " ", cv.driver_surname)) AS driver, cv.driver_phone,
                    v.pallet_places, v.max_load_kg, TIME_FORMAT(v.departure_time, "%H:%i") AS departure_time,
                    COALESCE(v.active, 0) AS active, v.notes, v.id_carrier_vehicle IS NOT NULL AS configured
             FROM carrier_vehicle cv JOIN carrier c ON c.id = cv.id_carrier
             LEFT JOIN tfb_vehicle v ON v.id_carrier_vehicle = cv.id
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

    public function addVehicle(int $carrierId, string $regis, string $driverName, string $driverSurname, string $driverPhone): int
    {
        $this->db->prepare('INSERT INTO carrier_vehicle (id_carrier, regis_num, driver_name, driver_surname, driver_phone, driver_email) VALUES (?, ?, ?, ?, ?, "")')
            ->execute([$carrierId, $regis, $driverName, $driverSurname, $driverPhone]);
        return (int) $this->db->lastInsertId();
    }

    public function saveVehicle(int $vehicleId, array $data): void
    {
        $data = $this->filter($data);
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO tfb_vehicle (id_carrier_vehicle, %s) VALUES (:id, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute(['id' => $vehicleId] + $data);
    }

    /** Dni wysyłki z zamówieniami (bez zarchiwizowanych) od podanej daty. */
    public function shippingDates(string $from, int $limit = 30): array
    {
        return $this->all(
            "SELECT DATE(co.expected_shipping_date) AS ship_date, COUNT(*) AS orders, COUNT(DISTINCT co.id_customer) AS customers
             FROM customer_order co WHERE co.status < 5 AND co.expected_shipping_date >= ? AND COALESCE(co.is_freebie, 0) = 0
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
                    fp.cpp, fp.cpl, fp.lpp, fp.ppc, fp.weight AS weight_g
             FROM customer_order co
             JOIN customer cu ON cu.id = co.id_customer
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             WHERE DATE(co.expected_shipping_date) = ? AND co.status < 5 AND COALESCE(co.is_freebie, 0) = 0 AND cop.quantity_carton > 0
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

    /** @return array<int,array{vehicle:?int, stop:?int}> */
    public function assignments(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, id_carrier_vehicle, stop_order FROM tfb_shipping_assignment WHERE ship_date = ?', [$date]) as $r) {
            $out[(int) $r['id_customer']] = ['vehicle' => $r['id_carrier_vehicle'] !== null ? (int) $r['id_carrier_vehicle'] : null, 'stop' => $r['stop_order'] !== null ? (int) $r['stop_order'] : null];
        }
        return $out;
    }

    /** @return array<int,array{vehicle:?int, stop:?int}> */
    public function routes(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, id_carrier_vehicle, stop_order FROM tfb_customer_route') as $r) {
            $out[(int) $r['id_customer']] = ['vehicle' => $r['id_carrier_vehicle'] !== null ? (int) $r['id_carrier_vehicle'] : null, 'stop' => $r['stop_order'] !== null ? (int) $r['stop_order'] : null];
        }
        return $out;
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

    public function saveAssignment(string $date, int $customerId, ?int $vehicleId, ?int $stop, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO tfb_shipping_assignment (ship_date, id_customer, id_carrier_vehicle, stop_order, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id_carrier_vehicle = VALUES(id_carrier_vehicle), stop_order = VALUES(stop_order), updated_by = VALUES(updated_by)'
        )->execute([$date, $customerId, $vehicleId, $stop, $userId]);
    }

    public function saveRoute(int $customerId, ?int $vehicleId, ?int $stop, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO tfb_customer_route (id_customer, id_carrier_vehicle, stop_order, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id_carrier_vehicle = VALUES(id_carrier_vehicle), stop_order = VALUES(stop_order), updated_by = VALUES(updated_by)'
        )->execute([$customerId, $vehicleId, $stop, $userId]);
    }

    public function product(int $id): ?array
    {
        return $this->all('SELECT id, name, cpl, lpp, cpp FROM final_product WHERE id = ?', [$id])[0] ?? null;
    }

    public function savePalletData(int $productId, int $cpl, int $lpp, int $cpp): void
    {
        $this->db->prepare('UPDATE final_product SET cpl = ?, lpp = ?, cpp = ? WHERE id = ?')->execute([$cpl, $lpp, $cpp, $productId]);
    }
}
