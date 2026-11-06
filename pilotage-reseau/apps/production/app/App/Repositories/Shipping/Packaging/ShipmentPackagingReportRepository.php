<?php

namespace App\Repositories\Shipping\Packaging;

use App\Core\Helpers\BaseRepository;

class ShipmentPackagingReportRepository extends BaseRepository
{
    protected string $table = 'shipment_log';

    /** @return list<array<string, mixed>> */
    public function getSummary(string $dateFrom, string $dateTo): array
    {
        return $this->query(
            "SELECT spi.bdo_material_category_snapshot AS category,
                    spi.packaging_usage_type_snapshot AS usage_type,
                    SUM(spi.total_weight_g) AS total_weight_g
             FROM shipment_log sl
             INNER JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
             INNER JOIN shipment_packaging_item spi ON spi.id_shipment_packaging_record = spr.id
             WHERE sl.shipment_date >= :date_from
               AND sl.shipment_date <= :date_to
               AND spi.calculation_status = 'COMPLETE'
             GROUP BY spi.bdo_material_category_snapshot, spi.packaging_usage_type_snapshot
             ORDER BY spi.bdo_material_category_snapshot, spi.packaging_usage_type_snapshot",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        ) ?: [];
    }

    /** @return array<string, mixed> */
    public function getCoverage(string $dateFrom, string $dateTo): array
    {
        $rows = $this->query(
            "SELECT COUNT(sl.id) AS shipment_count,
                    SUM(CASE WHEN spr.id IS NULL THEN 1 ELSE 0 END) AS without_evidence_count,
                    SUM(CASE WHEN spr.status = 'REQUIRES_DATA' THEN 1 ELSE 0 END) AS requires_data_count,
                    SUM(CASE WHEN spr.status IN ('GENERATED', 'CONFIRMED') THEN 1 ELSE 0 END) AS complete_evidence_count,
                    SUM(CASE WHEN spr.evidence_basis = 'CURRENT_DATA_RECONSTRUCTION' THEN 1 ELSE 0 END) AS current_data_evidence_count
             FROM shipment_log sl
             LEFT JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
             WHERE sl.shipment_date >= :date_from
               AND sl.shipment_date <= :date_to",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );

        return $rows[0] ?? [
            'shipment_count' => 0,
            'without_evidence_count' => 0,
            'requires_data_count' => 0,
            'complete_evidence_count' => 0,
            'current_data_evidence_count' => 0,
        ];
    }
}
