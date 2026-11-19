<?php
declare(strict_types=1);
namespace App\Repositories\Document;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [311] Podpisy na CMR : pole 22 (nadawca = osoba z ERP, która wystawiła wysyłkę, jej zapisany podpis) i pole 23
 * (przewoźnik = kierowca, podpis złożony raz na tablecie przy starcie trasy dnia).
 */
final class CmrSignatureRepository extends WorkshopRepository
{
    /** Wystawca wysyłki (shipment_log) i jego podpis ; null, gdy nieznany albo bez podpisu. */
    public function sender(int $cmrId): ?array
    {
        return $this->all("SELECT u.id, COALESCE(NULLIF(TRIM(u.displayed_name), ''), TRIM(CONCAT(u.name, ' ', u.surname))) AS name, us.image_png AS signature
            FROM shipment_log sl JOIN n_shipment_issuer si ON si.id_shipment_log = sl.id
            JOIN user u ON u.id = si.id_user JOIN n_user_signature us ON us.id_user = u.id
            WHERE sl.id_cmr = ? ORDER BY sl.id DESC LIMIT 1", [$cmrId])[0] ?? null;
    }

    /** Podpis kierowcy trasy, która wiezie ten CMR (dzień wysyłki + pojazd CMR albo pojazd przydzielony klientowi). */
    public function carrier(int $cmrId): ?array
    {
        return $this->all("SELECT rs.driver_name AS name, rs.image_png AS signature, rs.signed_at
            FROM document_cmr c JOIN shipment_log sl ON sl.id_cmr = c.id
            LEFT JOIN n_shipping_assignment a ON a.ship_date = sl.shipment_date AND a.id_customer = sl.id_customer
            JOIN n_delivery_run_signature rs ON rs.ship_date = sl.shipment_date
                AND rs.id_carrier_vehicle = COALESCE(NULLIF(c.id_carrier_vehicle, 0), a.id_carrier_vehicle)
            WHERE c.id = ? ORDER BY sl.id DESC LIMIT 1", [$cmrId])[0] ?? null;
    }

    public function setIssuer(int $shipmentLogId, int $userId): void
    {
        $this->db->prepare('INSERT INTO n_shipment_issuer (id_shipment_log, id_user) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_user = VALUES(id_user)')
            ->execute([$shipmentLogId, $userId]);
    }

    public function userSignature(int $userId): ?array
    {
        return $this->all('SELECT image_png, updated_at FROM n_user_signature WHERE id_user = ?', [$userId])[0] ?? null;
    }

    public function saveUserSignature(int $userId, string $png): void
    {
        $this->db->prepare('INSERT INTO n_user_signature (id_user, image_png) VALUES (?, ?) ON DUPLICATE KEY UPDATE image_png = VALUES(image_png), updated_at = NOW()')
            ->execute([$userId, $png]);
    }

    public function deleteUserSignature(int $userId): void
    {
        $this->db->prepare('DELETE FROM n_user_signature WHERE id_user = ?')->execute([$userId]);
    }

    public function runSignature(string $date, int $vehicleId): ?array
    {
        return $this->all('SELECT driver_name, signed_at FROM n_delivery_run_signature WHERE ship_date = ? AND id_carrier_vehicle = ?', [$date, $vehicleId])[0] ?? null;
    }

    public function saveRunSignature(string $date, int $vehicleId, string $png, ?string $driver, ?int $deviceId): void
    {
        $this->db->prepare('INSERT INTO n_delivery_run_signature (ship_date, id_carrier_vehicle, driver_name, image_png, id_device) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE driver_name = VALUES(driver_name), image_png = VALUES(image_png), id_device = VALUES(id_device), signed_at = NOW()')
            ->execute([$date, $vehicleId, $driver, $png, $deviceId]);
    }
}
