<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Dostawy z tabletu kierowcy : przejazd pojazdu w dniu wysyłki (start / koniec), potwierdzenie albo problem
 * u każdego klienta (jedno na klienta i dzień), podpis odbiorcy i zdjęcia.
 */
final class DeliveryRepository extends WorkshopRepository
{
    protected string $table = 'n_delivery_stop';
    protected array $writable = ['ship_date', 'id_customer', 'id_carrier_vehicle', 'status', 'reason', 'receiver_name', 'pallets_delivered', 'pallets_returned',
        'note', 'lat', 'lng', 'recorded_at', 'driver_name', 'id_device'];

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
        if ($row) $row['media'] = $this->all('SELECT id, kind, mime FROM n_delivery_media WHERE id_stop = ? ORDER BY id', [$id]);
        return $row;
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
            "SELECT sl.id_customer, wz.doc_num AS wz, cmr.doc_num AS cmr, wdt.doc_num AS wdt
             FROM shipment_log sl
             LEFT JOIN document_wz wz ON wz.id = sl.id_wz LEFT JOIN document_cmr cmr ON cmr.id = sl.id_cmr LEFT JOIN document_wdt wdt ON wdt.id = sl.id_wdt
             WHERE sl.shipment_date = ? ORDER BY sl.id",
            [$date]
        ) as $r) {
            foreach (['wz' => 'WZ', 'cmr' => 'CMR', 'wdt' => 'WDT'] as $k => $label) {
                if ($r[$k]) $out[(int) $r['id_customer']][] = ['type' => $label, 'num' => (string) $r[$k]];
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
             FROM n_delivery_stop WHERE ship_date BETWEEN ? AND ? GROUP BY status, reason',
            [$from, $to]
        );
    }
}
