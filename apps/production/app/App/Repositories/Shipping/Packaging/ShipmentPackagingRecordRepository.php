<?php

namespace App\Repositories\Shipping\Packaging;

use App\Core\Helpers\BaseRepository;

class ShipmentPackagingRecordRepository extends BaseRepository
{
    protected string $table = 'shipment_packaging_record';

    protected $allowedColumns = [
        'id_shipment_log', 'status', 'evidence_basis', 'ppwr_evidence_basis', 'ppwr_generated_at',
        'id_employee_generated', 'confirmed_at', 'id_employee_confirmed'
    ];

    public function getByShipmentId(int $shipmentId): array
    {
        return $this->first(['id_shipment_log' => $shipmentId]);
    }
}
