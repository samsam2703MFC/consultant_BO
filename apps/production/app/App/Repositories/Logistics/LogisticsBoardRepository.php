<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Domain\Client\Order\OpenOrders;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Logistyka i pakowanie : zamówione kartony dnia wysyłki na klienta i produkt, kartony na paletach (skan palet
 * + wpisy z tabletu), stan kartonów, normy pakowania (etap « Pakowanie » karty produktu), obecni w grupie pakowania,
 * licznik pakowania, plan naczepy (wersja robocza / wysłana) i potwierdzenia załadunku kierowcy.
 */
final class LogisticsBoardRepository extends WorkshopRepository
{
    protected string $table = 'n_load_plan';

    /** @return list<array> zamówione kartony dnia : klient × produkt (zamówienia do załadunku) */
    public function orderLines(string $date): array
    {
        return $this->all(
            "SELECT co.id_customer, cop.id_product, SUM(cop.quantity_carton) AS cartons, fp.name AS product, COALESCE(fp.ppc, 0) AS ppc,
                    cat.name AS category
             FROM customer_order co
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             LEFT JOIN category_product cat ON cat.id = fp.id_category
             WHERE DATE(co.expected_shipping_date) = ? AND " . OpenOrders::loadingSql('co') . " AND cop.quantity_carton > 0
             GROUP BY co.id_customer, cop.id_product, fp.name, fp.ppc, cat.name
             ORDER BY cat.name, fp.name",
            [$date]
        );
    }

    /** @return array<int, array<int, int>> klient → produkt → kartony zeskanowane na palety tego dnia */
    public function scanned(string $date): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT pd.id_customer, cs.id_product, COUNT(*) AS n FROM palets_delivery pd
             JOIN carton_pallet_connection c ON c.id_palet = pd.id_palet JOIN carton_stock cs ON cs.id_carton = c.id_carton
             WHERE pd.delivery_date = ? GROUP BY pd.id_customer, cs.id_product', [$date]) as $r) {
            $out[(int) $r['id_customer']][(int) $r['id_product']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, array<int, int>> klient → produkt → kartony wpisane na tablecie paletyzacji */
    public function entered(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, id_product, SUM(cartons) AS n FROM n_pallet_entry WHERE ship_date = ? GROUP BY id_customer, id_product', [$date]) as $r) {
            $out[(int) $r['id_customer']][(int) $r['id_product']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array> palety dnia (zeskanowane) : numer, klient, status, kartony */
    public function pallets(string $date): array
    {
        return $this->all(
            'SELECT pd.id_palet, pd.id_customer, pd.status, pd.last_update_date, COUNT(c.id_carton) AS cartons
             FROM palets_delivery pd LEFT JOIN carton_pallet_connection c ON c.id_palet = pd.id_palet
             WHERE pd.delivery_date = ? GROUP BY pd.id_palet, pd.id_customer, pd.status, pd.last_update_date ORDER BY pd.id_customer, pd.id_palet',
            [$date]
        );
    }

    /** Ostatni ruch dnia : skan palety albo wpis z tabletu. */
    public function lastActivity(string $date): ?string
    {
        $r = $this->all('SELECT GREATEST(COALESCE((SELECT MAX(last_update_date) FROM palets_delivery WHERE delivery_date = ?), \'1970-01-01\'),
                                       COALESCE((SELECT MAX(created_at) FROM n_pallet_entry WHERE ship_date = ?), \'1970-01-01\')) AS t', [$date, $date])[0]['t'] ?? null;
        return $r && $r > '1970-01-02' ? (string) $r : null;
    }

    /** @return array<int, int> produkt → kartony wolne na stanie (status 1) */
    public function stock(array $productIds): array
    {
        return $this->countByProduct($productIds, 'status = 1');
    }

    /** @return array<int, int> produkt → kartony na stanie albo na paletach (status 1, 2) */
    public function stockOrPallet(array $productIds): array
    {
        return $this->countByProduct($productIds, 'status IN (1, 2)');
    }

    private function countByProduct(array $productIds, string $where): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all("SELECT id_product, COUNT(*) AS n FROM carton_stock WHERE {$where} AND id_product IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY id_product', $ids) as $r) {
            $out[(int) $r['id_product']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, array{fixed:float, per_piece:float}> norma pakowania z karty produktu (etap « Pakowanie ») */
    public function packNorms(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all("SELECT i.id_final_product, rs.fixed_time_s, rs.variable_time_s FROM n_prod_item i
                JOIN n_prod_routing_step rs ON rs.id_item = i.id AND rs.name_pl = 'Pakowanie'
                WHERE i.item_type = 'product' AND i.id_final_product IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int) $r['id_final_product']] ??= ['fixed' => (float) $r['fixed_time_s'], 'per_piece' => (float) $r['variable_time_s']];
        }
        return $out;
    }

    /** Średnia norma pakowania (produkty bez normy) : czas stały i na sztukę. */
    public function averagePackNorm(): ?array
    {
        $r = $this->all("SELECT AVG(fixed_time_s) AS f, AVG(variable_time_s) AS v, COUNT(*) AS n FROM n_prod_routing_step WHERE name_pl = 'Pakowanie'")[0] ?? null;
        return $r && (int) $r['n'] > 0 ? ['fixed' => (float) $r['f'], 'per_piece' => (float) $r['v']] : null;
    }

    /** Obecni (odbici, bez wyjścia) w grupie produkcji o kodzie. */
    public function presentInGroup(string $code): int
    {
        return (int) ($this->all(
            'SELECT COUNT(DISTINCT e.id) AS n FROM n_prod_group g
             JOIN n_prod_employee e ON e.active = 1 AND (e.id_primary_group = g.id OR e.id IN (SELECT m.id_employee FROM n_prod_team_member m JOIN n_prod_team t ON t.id = m.id_team WHERE t.id_group = g.id AND t.active = 1))
             WHERE g.code = ? AND EXISTS (SELECT 1 FROM n_prod_clock k WHERE k.id_employee = e.id AND k.clock_out IS NULL)', [$code])[0]['n'] ?? 0);
    }

    /** @return array<int, array{packed:int, base_count:int}> */
    public function packing(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT id_product, packed, base_count FROM n_packing_count WHERE ship_date = ?', [$date]) as $r) {
            $out[(int) $r['id_product']] = ['packed' => (int) $r['packed'], 'base_count' => (int) $r['base_count']];
        }
        return $out;
    }

    public function savePacking(string $date, int $productId, int $packed, int $baseCount, ?int $userId, ?int $deviceId = null): void
    {
        $this->db->prepare('INSERT INTO n_packing_count (ship_date, id_product, packed, base_count, updated_by, updated_device) VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE packed = VALUES(packed), updated_by = VALUES(updated_by), updated_device = VALUES(updated_device)')
            ->execute([$date, $productId, $packed, $baseCount, $userId, $deviceId]);
    }

    public function productExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM final_product WHERE id = ?', [$id]);
    }

    // --- Paletyzacja (wpisy z tabletu) -----------------------------------------------------------------

    /** @return list<array> wpisy dnia klienta : produkt, kartony, kiedy, kto (operator), źródło */
    public function palletEntries(string $date, int $customerId): array
    {
        return $this->all(
            "SELECT pe.id, pe.id_product, pe.cartons, pe.created_at, pe.source, TRIM(CONCAT(COALESCE(p.name, ''), ' ', COALESCE(p.surname, ''))) AS who
             FROM n_pallet_entry pe
             LEFT JOIN n_prod_employee e ON e.id = pe.id_employee
             LEFT JOIN production_employee p ON p.id = e.id_production_employee
             WHERE pe.ship_date = ? AND pe.id_customer = ? ORDER BY pe.id",
            [$date, $customerId]
        );
    }

    public function palletEntry(int $id): ?array
    {
        return $this->all('SELECT * FROM n_pallet_entry WHERE id = ?', [$id])[0] ?? null;
    }

    public function addPalletEntry(string $date, int $customerId, int $productId, int $cartons, ?int $employeeId, ?int $deviceId, ?int $userId): int
    {
        $this->db->prepare("INSERT INTO n_pallet_entry (ship_date, id_customer, id_product, cartons, source, id_employee, id_user, id_device) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$date, $customerId, $productId, $cartons, $userId !== null && $deviceId === null ? 'erp' : 'tablet', $employeeId, $userId, $deviceId]);
        return (int) $this->db->lastInsertId();
    }

    public function deletePalletEntry(int $id): void
    {
        $this->db->prepare('DELETE FROM n_pallet_entry WHERE id = ?')->execute([$id]);
    }

    public function employeeActive(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_prod_employee WHERE id = ? AND active = 1', [$id]);
    }

    /** @return list<array{id:int, name:string, group:?string, since:string}> osoby obecne (odbite, bez wyjścia) — operator tabletu */
    public function presentPeople(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'group' => $r['grp'], 'since' => $r['since']], $this->all(
            "SELECT e.id, TRIM(CONCAT(p.name, ' ', p.surname)) AS name, g.name_pl AS grp, MIN(k.clock_in) AS since
             FROM n_prod_clock k
             JOIN n_prod_employee e ON e.id = k.id_employee AND e.active = 1
             JOIN production_employee p ON p.id = e.id_production_employee
             LEFT JOIN n_prod_group g ON g.id = e.id_primary_group
             WHERE k.clock_out IS NULL
             GROUP BY e.id, p.name, p.surname, g.name_pl
             ORDER BY name"
        ));
    }

    // --- Plan naczepy ----------------------------------------------------------------------------------

    public function plan(string $date, int $vehicleId): ?array
    {
        return $this->all('SELECT * FROM n_load_plan WHERE ship_date = ? AND id_carrier_vehicle = ?', [$date, $vehicleId])[0] ?? null;
    }

    /** @return array<int, array> pojazd → plan dnia */
    public function plans(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_load_plan WHERE ship_date = ?', [$date]) as $r) $out[(int) $r['id_carrier_vehicle']] = $r;
        return $out;
    }

    public function saveLayout(string $date, int $vehicleId, ?array $layout, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_load_plan (ship_date, id_carrier_vehicle, layout, updated_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE layout = VALUES(layout), updated_by = VALUES(updated_by)')
            ->execute([$date, $vehicleId, $layout === null ? null : json_encode($layout), $userId]);
    }

    public function send(string $date, int $vehicleId, array $layout, ?int $userId): int
    {
        $json = json_encode($layout);
        $this->db->prepare('INSERT INTO n_load_plan (ship_date, id_carrier_vehicle, layout, sent_layout, version, sent_at, sent_by, updated_by) VALUES (?, ?, ?, ?, 1, NOW(), ?, ?)
                            ON DUPLICATE KEY UPDATE layout = VALUES(layout), sent_layout = VALUES(sent_layout), version = version + 1, sent_at = NOW(),
                                                    sent_by = VALUES(sent_by), updated_by = VALUES(updated_by)')
            ->execute([$date, $vehicleId, $json, $json, $userId, $userId]);
        return (int) $this->all('SELECT version FROM n_load_plan WHERE ship_date = ? AND id_carrier_vehicle = ?', [$date, $vehicleId])[0]['version'];
    }

    /** @return array<int, string> miejsce → kiedy załadowane */
    public function checks(string $date, int $vehicleId): array
    {
        return array_column($this->all('SELECT slot_no, loaded_at FROM n_load_check WHERE ship_date = ? AND id_carrier_vehicle = ? ORDER BY slot_no', [$date, $vehicleId]), 'loaded_at', 'slot_no');
    }

    public function setCheck(string $date, int $vehicleId, int $slot, bool $loaded, ?int $deviceId, ?int $employeeId): void
    {
        if ($loaded) {
            $this->db->prepare('INSERT INTO n_load_check (ship_date, id_carrier_vehicle, slot_no, loaded_at, id_device, id_employee) VALUES (?, ?, ?, NOW(), ?, ?)
                                ON DUPLICATE KEY UPDATE loaded_at = loaded_at')->execute([$date, $vehicleId, $slot, $deviceId, $employeeId]);
            return;
        }
        $this->db->prepare('DELETE FROM n_load_check WHERE ship_date = ? AND id_carrier_vehicle = ? AND slot_no = ?')->execute([$date, $vehicleId, $slot]);
    }

    /** Nowa wersja planu : potwierdzenia miejsc, na których zmieniła się paleta, przestają obowiązywać. */
    public function clearChecks(string $date, int $vehicleId, array $slots): void
    {
        $slots = array_values(array_unique(array_map('intval', $slots)));
        if (!$slots) return;
        $this->db->prepare('DELETE FROM n_load_check WHERE ship_date = ? AND id_carrier_vehicle = ? AND slot_no IN (' . implode(',', array_fill(0, count($slots), '?')) . ')')
            ->execute([$date, $vehicleId, ...$slots]);
    }

    /** Pojazd i kolejność rozładunku klienta na dzień (trasa dnia bez zmian). */
    public function setVehicleStop(string $date, int $customerId, ?int $vehicleId, ?int $stop, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_shipping_assignment (ship_date, id_customer, id_tour, tour_set, id_carrier_vehicle, vehicle_set, stop_order, updated_by) VALUES (?, ?, NULL, 0, ?, 1, ?, ?)
                            ON DUPLICATE KEY UPDATE id_carrier_vehicle = VALUES(id_carrier_vehicle), vehicle_set = 1, plan_set = 0, stop_order = VALUES(stop_order), updated_by = VALUES(updated_by)')
            ->execute([$date, $customerId, $vehicleId, $stop, $userId]);
    }

    public function userName(?int $id): ?string
    {
        if (!$id) return null;
        $r = $this->all('SELECT TRIM(CONCAT(name, " ", LEFT(surname, 1), ".")) AS n FROM user WHERE id = ?', [$id])[0] ?? null;
        return $r['n'] ?? null;
    }
}
