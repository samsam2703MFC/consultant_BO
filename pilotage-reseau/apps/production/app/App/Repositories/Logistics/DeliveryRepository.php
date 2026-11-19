<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Dostawy z tabletu kierowcy : przejazd pojazdu w dniu wysyłki (start / koniec), przyjazd do klienta (zdjęcia przed
 * rozładunkiem), potwierdzenie albo problem u każdego klienta (jedno na klienta i dzień), zdjęcia po rozładunku,
 * podpis odbiorcy, numery palet ze skanera.
 */
final class DeliveryRepository extends WorkshopRepository
{
    protected string $table = 'n_delivery_stop';
    protected array $writable = ['ship_date', 'id_customer', 'id_carrier_vehicle', 'status', 'reason', 'receiver_name', 'pallets_delivered', 'pallets_returned',
        'note', 'lat', 'lng', 'recorded_at', 'arrived_at', 'driver_name', 'id_device', 'receipt_mode', 'pallets_note'];

    public function run(string $date, int $vehicleId): ?array
    {
        return $this->all('SELECT * FROM n_delivery_run WHERE ship_date = ? AND id_carrier_vehicle = ?', [$date, $vehicleId])[0] ?? null;
    }

    /** @return list<array> przejazdy dnia */
    public function runs(string $date): array
    {
        return $this->all('SELECT * FROM n_delivery_run WHERE ship_date = ? ORDER BY started_at, id', [$date]);
    }

    public function saveRun(string $date, int $vehicleId, array $data): void
    {
        $columns = array_keys($data);
        $this->db->prepare(sprintf(
            'INSERT INTO n_delivery_run (ship_date, id_carrier_vehicle, %s) VALUES (?, ?, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?')),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        ))->execute([$date, $vehicleId, ...array_values($data)]);
    }

    /** Ostatni niezakończony przejazd pojazdu (kierowca wraca po północy : dzień wysyłki = wczoraj). */
    public function openRun(int $vehicleId, string $since): ?array
    {
        return $this->all('SELECT * FROM n_delivery_run WHERE id_carrier_vehicle = ? AND ship_date >= ? AND started_at IS NOT NULL AND finished_at IS NULL
                           ORDER BY ship_date DESC LIMIT 1', [$vehicleId, $since])[0] ?? null;
    }

    public function stopFor(string $date, int $customerId): ?array
    {
        return $this->all('SELECT * FROM n_delivery_stop WHERE ship_date = ? AND id_customer = ?', [$date, $customerId])[0] ?? null;
    }

    /** Postoje dnia (wszystkie albo jednego pojazdu) z nazwą klienta i identyfikatorami podpisu i zdjęć. */
    public function stops(string $date, ?int $vehicleId = null): array
    {
        $params = [$date];
        $where = 's.ship_date = ?';
        if ($vehicleId !== null) { $where .= ' AND s.id_carrier_vehicle = ?'; $params[] = $vehicleId; }
        return $this->all(
            "SELECT s.*, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname))) AS customer,
                    (SELECT m.id FROM n_delivery_media m WHERE m.id_stop = s.id AND m.kind = 'signature' ORDER BY m.id DESC LIMIT 1) AS signature_id,
                    (SELECT GROUP_CONCAT(m.id ORDER BY m.id) FROM n_delivery_media m WHERE m.id_stop = s.id AND m.kind = 'photo') AS photo_ids
             FROM n_delivery_stop s LEFT JOIN customer cu ON cu.id = s.id_customer
             WHERE {$where} ORDER BY s.recorded_at, s.id",
            $params
        );
    }

    public function stopDetail(int $id): ?array
    {
        $row = $this->all(
            "SELECT s.*, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname))) AS customer,
                    cu.fac_street, cu.fac_street_num, cu.fac_zip, cu.fac_town, cu.fac_country,
                    cv.regis_num, ca.name AS carrier
             FROM n_delivery_stop s LEFT JOIN customer cu ON cu.id = s.id_customer
             LEFT JOIN carrier_vehicle cv ON cv.id = s.id_carrier_vehicle LEFT JOIN carrier ca ON ca.id = cv.id_carrier
             WHERE s.id = ?",
            [$id]
        )[0] ?? null;
        if ($row) $row['media'] = $this->all('SELECT id, kind, mime, taken_at, created_at FROM n_delivery_media WHERE id_stop = ? ORDER BY COALESCE(taken_at, created_at), id', [$id]);
        return $row;
    }

    /** Przyjazd : pierwsza godzina zostaje (kolejne zdjęcia « przed » jej nie zmieniają). */
    public function markArrived(int $stopId, string $at): void
    {
        $this->db->prepare('UPDATE n_delivery_stop SET arrived_at = COALESCE(arrived_at, ?) WHERE id = ?')->execute([$at, $stopId]);
    }

    /**
     * Zdjęcia i podpis postojów (bez treści) : [id_stop => [rodzaj => [[id, taken_at], …]]].
     * @return array<int, array<string, list<array{id:int, taken_at:?string}>>>
     */
    public function mediaIndex(array $stopIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stopIds))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id, id_stop, kind, COALESCE(taken_at, created_at) AS taken_at FROM n_delivery_media WHERE id_stop IN ({$in})
                             ORDER BY COALESCE(taken_at, created_at), id", $ids) as $m) {
            $out[(int) $m['id_stop']][$m['kind']][] = ['id' => (int) $m['id'], 'taken_at' => $m['taken_at']];
        }
        return $out;
    }

    /** Zdjęcie przed / po rozładunku ; to samo client_uuid (kolejka offline wysłana drugi raz) = ten sam zapis. */
    public function addMedia(int $stopId, string $kind, string $mime, string $content, ?string $takenAt, ?string $uuid, ?int $palletNo = null, ?int $incidentId = null): int
    {
        if ($uuid !== null) {
            $existing = $this->all('SELECT id FROM n_delivery_media WHERE id_stop = ? AND client_uuid = ?', [$stopId, $uuid])[0]['id'] ?? null;
            if ($existing !== null) return (int) $existing;
        }
        $this->db->prepare('INSERT INTO n_delivery_media (id_stop, kind, pallet_no, id_incident, mime, content, taken_at, client_uuid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$stopId, $kind, $palletNo, $incidentId, $mime, $content, $takenAt, $uuid]);
        return (int) $this->db->lastInsertId();
    }

    // --- [310] Rozładunek palet, zgłoszenia, dokumenty punktu ------------------------------------------------------

    /** Skan palety (jeden na paletę i postój ; ponowny skan / ta sama kolejka = ten sam zapis). @return int id skanu */
    public function addPalletScan(int $stopId, int $palletNo, string $method, ?int $mediaId, string $at, $lat, $lng, ?string $driver, ?string $uuid): int
    {
        $row = $this->all('SELECT id FROM n_delivery_pallet_scan WHERE id_stop = ? AND pallet_no = ?', [$stopId, $palletNo])[0] ?? null;
        if ($row) return (int) $row['id'];
        $this->db->prepare('INSERT INTO n_delivery_pallet_scan (id_stop, pallet_no, method, id_media, scanned_at, lat, lng, driver_name, client_uuid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$stopId, $palletNo, $method, $mediaId, $at, $lat, $lng, $driver, $uuid]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int, list<array{pallet_no:int, method:string, scanned_at:string, media_id:?int}>> postój → skany */
    public function palletScans(array $stopIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stopIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT id_stop, pallet_no, method, scanned_at, id_media FROM n_delivery_pallet_scan WHERE id_stop IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                             ORDER BY scanned_at, id', $ids) as $r) {
            $out[(int) $r['id_stop']][] = ['pallet_no' => (int) $r['pallet_no'], 'method' => $r['method'], 'scanned_at' => $r['scanned_at'],
                'media_id' => $r['id_media'] !== null ? (int) $r['id_media'] : null];
        }
        return $out;
    }

    public function addIncident(int $stopId, string $kind, ?string $note, string $at, ?string $driver, ?string $uuid): int
    {
        if ($uuid !== null) {
            $existing = $this->all('SELECT id FROM n_delivery_incident WHERE client_uuid = ?', [$uuid])[0]['id'] ?? null;
            if ($existing !== null) return (int) $existing;
        }
        $this->db->prepare('INSERT INTO n_delivery_incident (id_stop, kind, note, recorded_at, driver_name, client_uuid) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$stopId, $kind, $note, $at, $driver, $uuid]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int, list<array>> postój → zgłoszenia (z identyfikatorami zdjęć) */
    public function incidents(array $stopIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stopIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT i.id, i.id_stop, i.kind, i.note, i.recorded_at, i.driver_name,
                    (SELECT GROUP_CONCAT(m.id ORDER BY m.id) FROM n_delivery_media m WHERE m.id_incident = i.id) AS photo_ids
                FROM n_delivery_incident i WHERE i.id_stop IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY i.recorded_at, i.id', $ids) as $r) {
            $out[(int) $r['id_stop']][] = ['id' => (int) $r['id'], 'kind' => $r['kind'], 'note' => $r['note'], 'recorded_at' => $r['recorded_at'],
                'driver_name' => $r['driver_name'], 'photo_ids' => $r['photo_ids'] ? array_map('intval', explode(',', (string) $r['photo_ids'])) : []];
        }
        return $out;
    }

    /** Wysyłki dnia klienta (dokumenty z fakturowania) : [id_wz, id_cmr, id_wdt, numery]. @return list<array> */
    public function shipments(string $date, int $customerId): array
    {
        return $this->all(
            "SELECT sl.id, sl.id_wz, sl.id_cmr, sl.id_wdt, wz.doc_num AS wz, cmr.doc_num AS cmr, wdt.doc_num AS wdt
             FROM shipment_log sl LEFT JOIN document_wz wz ON wz.id = sl.id_wz LEFT JOIN document_cmr cmr ON cmr.id = sl.id_cmr LEFT JOIN document_wdt wdt ON wdt.id = sl.id_wdt
             WHERE sl.shipment_date = ? AND sl.id_customer = ? ORDER BY sl.id", [$date, $customerId]);
    }

    /** Palety dnia innych klientów : numer → [id_customer, nazwa] (komunikat « cudza paleta »). */
    public function palletOwner(string $date, int $palletNo): ?array
    {
        return $this->all("SELECT pd.id_customer, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname))) AS customer
            FROM palets_delivery pd LEFT JOIN customer cu ON cu.id = pd.id_customer WHERE pd.delivery_date = ? AND pd.id_palet = ?", [$date, $palletNo])[0] ?? null;
    }

    public function countMedia(int $stopId, string $kind): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM n_delivery_media WHERE id_stop = ? AND kind = ?', [$stopId, $kind])[0]['n'] ?? 0);
    }

    public function mediaExists(int $stopId, string $uuid): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_delivery_media WHERE id_stop = ? AND client_uuid = ?', [$stopId, $uuid]);
    }

    /** Numery palet ze skanera magazynu (palets_delivery) na klienta w dniu dostawy. @return array<int, list<int>> */
    public function palletNumbers(string $date): array
    {
        $out = [];
        foreach ($this->all('SELECT id_customer, id_palet FROM palets_delivery WHERE delivery_date = ? ORDER BY id_palet', [$date]) as $r) {
            $out[(int) $r['id_customer']][] = (int) $r['id_palet'];
        }
        return $out;
    }

    /** [310] Palety dnia do etykiet : numer, klient (nazwa z palety, gdy brak w planie). */
    public function labelPallets(string $date, ?int $customerId = null): array
    {
        $sql = 'SELECT pd.id_palet, pd.id_customer, COALESCE(c.company, pd.client) AS customer FROM palets_delivery pd
                LEFT JOIN customer c ON c.id = pd.id_customer WHERE pd.delivery_date = ?';
        $args = [$date];
        if ($customerId !== null) { $sql .= ' AND pd.id_customer = ?'; $args[] = $customerId; }
        return $this->all($sql . ' ORDER BY pd.id_customer, pd.id_palet', $args);
    }

    /** [310] Zawartość palet : numer palety => [[produkt, kartony]] (kartony połączone z paletą). */
    public function palletContents(array $palletIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $palletIds))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT cpc.id_palet, COALESCE(fp.name, CONCAT(\'#\', cs.id_product)) AS product, COUNT(*) AS n
                FROM carton_pallet_connection cpc JOIN carton_stock cs ON cs.id_carton = cpc.id_carton
                LEFT JOIN final_product fp ON fp.id = cs.id_product
                WHERE cpc.id_palet IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                GROUP BY cpc.id_palet, cs.id_product, fp.name ORDER BY cpc.id_palet, n DESC', $ids) as $r) {
            $out[(int) $r['id_palet']][] = ['product' => (string) $r['product'], 'cartons' => (int) $r['n']];
        }
        return $out;
    }

    /** Zapis postoju : nowy albo nadpisanie (ponowne potwierdzenie, np. problem → dostarczono później). */
    public function saveStop(array $data): int
    {
        $data = array_intersect_key($data, array_flip($this->writable));
        $columns = array_keys($data);
        $this->db->prepare(sprintf(
            'INSERT INTO n_delivery_stop (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s, id = LAST_INSERT_ID(id)',
            implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?')),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", array_diff($columns, ['ship_date', 'id_customer'])))
        ))->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function replaceMedia(int $stopId, string $kind, string $mime, string $content): void
    {
        $this->db->prepare('DELETE FROM n_delivery_media WHERE id_stop = ? AND kind = ?')->execute([$stopId, $kind]);
        $this->db->prepare('INSERT INTO n_delivery_media (id_stop, kind, mime, content) VALUES (?, ?, ?, ?)')->execute([$stopId, $kind, $mime, $content]);
    }

    public function media(int $id): ?array
    {
        return $this->all('SELECT id, id_stop, kind, mime, content FROM n_delivery_media WHERE id = ?', [$id])[0] ?? null;
    }

    /** Adres i telefon klientów ; miejsce dostawy z ostatniego CMR, gdy jest. @return array<int,array> */
    public function customers(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all(
            "SELECT cu.id, cu.fac_street, cu.fac_street_num, cu.fac_zip, cu.fac_town, cu.fac_country, cu.phone_head, cu.phone_alt,
                    (SELECT c.delivery_place FROM document_cmr c WHERE c.id_customer = cu.id AND TRIM(COALESCE(c.delivery_place, '')) <> '' ORDER BY c.id DESC LIMIT 1) AS delivery_place
             FROM customer cu WHERE cu.id IN ({$in})",
            $ids
        ) as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    /** Numery dokumentów wysyłki dnia (WZ, CMR, WDT) na klienta. @return array<int, list<array{type:string,num:string}>> */
    public function documents(string $date): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT sl.id_customer, sl.id_wz, sl.id_cmr, sl.id_wdt, wz.doc_num AS wz, cmr.doc_num AS cmr, wdt.doc_num AS wdt
             FROM shipment_log sl
             LEFT JOIN document_wz wz ON wz.id = sl.id_wz LEFT JOIN document_cmr cmr ON cmr.id = sl.id_cmr LEFT JOIN document_wdt wdt ON wdt.id = sl.id_wdt
             WHERE sl.shipment_date = ? ORDER BY sl.id",
            [$date]
        ) as $r) {
            foreach (['wz' => 'WZ', 'cmr' => 'CMR', 'wdt' => 'WDT'] as $k => $label) {
                if ($r[$k]) $out[(int) $r['id_customer']][] = ['type' => $label, 'num' => (string) $r[$k], 'id' => (int) $r['id_' . $k]];
            }
        }
        return $out;
    }

    /** @return array<int, array{label:string, driver:?string, phone:?string}> pojazdy (do listy i nagłówka). */
    public function vehicles(): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT cv.id, cv.regis_num, ca.name AS carrier, TRIM(CONCAT(COALESCE(cv.driver_name, ''), ' ', COALESCE(cv.driver_surname, ''))) AS driver, cv.driver_phone
             FROM carrier_vehicle cv LEFT JOIN carrier ca ON ca.id = cv.id_carrier ORDER BY cv.regis_num"
        ) as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'label' => trim((string) $r['regis_num']) !== '' ? (string) $r['regis_num'] : (string) $r['carrier'],
                'carrier' => $r['carrier'], 'driver' => $r['driver'] !== '' ? $r['driver'] : null, 'phone' => $r['driver_phone'] ?: null];
        }
        return $out;
    }

    /** Liczby dostaw w okresie (raport miesięczny) : potwierdzone, problemy według przyczyny. */
    public function summary(string $from, string $to): array
    {
        return $this->all(
            'SELECT status, reason, COUNT(*) AS n, SUM(COALESCE(pallets_returned, 0)) AS pallets_returned
             FROM n_delivery_stop WHERE ship_date BETWEEN ? AND ? AND status IN (\'delivered\', \'problem\') GROUP BY status, reason',
            [$from, $to]
        );
    }
}
