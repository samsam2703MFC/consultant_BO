<?php
namespace App\Services\Shipping;

use App\Repositories\Document\Cmr\CMRRepository;
use App\Repositories\Shipping\Carrier\ProductCarrierRepository;
use App\Repositories\Shipping\PaletDeliveryRepository;

/**
 * Kreator „Wyślij dzień”: tylko odczyty. Dokumenty tworzy istniejące
 * POST /ajax/shipments (ShippingService::insertNewShipping), wołane przez stronę
 * kolejno dla każdego klienta.
 */
class ShippingWizardService
{
    public function __construct(
        private PaletDeliveryRepository  $paletDeliveryRepository,
        private ProductCarrierRepository $carrierRepository,
        private CMRRepository            $cmrRepository
    )
    {
    }

    /** Daty z paletami gotowymi do dokumentów (jak /shipping-documents/create). */
    public function readyDates(): array
    {
        $rows = $this->paletDeliveryRepository->getReadyDatesSummary() ?: [];

        return array_map(static fn(array $r) => [
            'date'    => (string) $r['delivery_date'],
            'clients' => (int) $r['clients'],
            'pallets' => (int) $r['pallets'],
        ], $rows);
    }

    /** Aktywni przewoźnicy (id, nazwa) do list wyboru. */
    public function activeCarriers(): array
    {
        $rows = $this->carrierRepository->getActive() ?: [];

        return array_map(static fn(array $r) => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $rows);
    }

    /**
     * Ostatni przewoźnik / pojazd z CMR klienta, albo null.
     * Przewoźnik dodatkowy tylko wtedy, gdy jest aktywny.
     */
    public function lastCarrierForCustomer(int $customerId): ?array
    {
        $row = $this->cmrRepository->getLastCarrierForCustomer($customerId);
        if ($row === null) {
            return null;
        }

        $toInt = static fn($v) => $v === null ? null : (int) $v;

        return [
            'id_cmr'                 => (int) $row['id_cmr'],
            'established_date'       => $row['established_date'],
            'id_carrier'             => (int) $row['id_carrier'],
            'carrier_name'           => $row['carrier_name'],
            'id_carrier_vehicle'     => $toInt($row['id_carrier_vehicle']),
            'regis_num'              => $row['regis_num'],
            'id_carrier_sec'         => $toInt($row['id_carrier_sec']),
            'carrier_sec_name'       => $row['carrier_sec_name'],
            'id_carrier_sec_vehicle' => $row['id_carrier_sec'] === null ? null : $toInt($row['id_carrier_sec_vehicle']),
            'sec_regis_num'          => $row['id_carrier_sec'] === null ? null : $row['sec_regis_num'],
        ];
    }
}
