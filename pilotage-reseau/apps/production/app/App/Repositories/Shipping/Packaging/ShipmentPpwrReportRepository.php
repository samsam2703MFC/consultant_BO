<?php

namespace App\Repositories\Shipping\Packaging;

use App\Core\Helpers\BaseRepository;

class ShipmentPpwrReportRepository extends BaseRepository
{
    protected string $table = 'shipment_log';

    public function getSummary(string $dateFrom, string $dateTo): array
    {
        return $this->query(
            "SELECT COALESCE(spi.ppwr_dominant_material_snapshot, 'UNKNOWN') AS material,
                    SUM(spi.ppwr_total_weight_g) AS total_weight_g
             FROM shipment_log sl
             INNER JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
             INNER JOIN shipment_packaging_item spi ON spi.id_shipment_packaging_record = spr.id
             WHERE sl.shipment_date >= :date_from AND sl.shipment_date <= :date_to
               AND spi.ppwr_total_weight_g IS NOT NULL
             GROUP BY COALESCE(spi.ppwr_dominant_material_snapshot, 'UNKNOWN')
             ORDER BY material",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        ) ?: [];
    }

    public function getCustomerSummaries(string $dateFrom, string $dateTo): array
    {
        return $this->query(
            "SELECT sl.id_customer, c.company AS customer_name,
                    COALESCE(spi.ppwr_dominant_material_snapshot, 'UNKNOWN') AS material,
                    SUM(spi.ppwr_total_weight_g) AS total_weight_g
             FROM shipment_log sl
             INNER JOIN customer c ON c.id = sl.id_customer
             INNER JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
             INNER JOIN shipment_packaging_item spi ON spi.id_shipment_packaging_record = spr.id
             WHERE sl.shipment_date >= :date_from AND sl.shipment_date <= :date_to
               AND spi.ppwr_total_weight_g IS NOT NULL
             GROUP BY sl.id_customer, c.company, COALESCE(spi.ppwr_dominant_material_snapshot, 'UNKNOWN')
             ORDER BY c.company, material",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        ) ?: [];
    }

    public function getCustomers(string $dateFrom, string $dateTo): array
    {
        return $this->query(
            "SELECT DISTINCT sl.id_customer, c.company AS customer_name
             FROM shipment_log sl
             INNER JOIN customer c ON c.id = sl.id_customer
             WHERE sl.shipment_date >= :date_from AND sl.shipment_date <= :date_to
             ORDER BY c.company",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        ) ?: [];
    }

    public function getCoverage(string $dateFrom, string $dateTo): array
    {
        $rows = $this->query(
            "SELECT COUNT(DISTINCT sl.id) AS shipment_count,
                    COUNT(DISTINCT CASE WHEN spr.id IS NULL THEN sl.id END) AS without_evidence_count,
                    COUNT(DISTINCT CASE WHEN spr.status = 'REQUIRES_DATA' THEN sl.id END) AS requires_data_count,
                    COUNT(DISTINCT CASE WHEN spr.evidence_basis = 'CURRENT_DATA_RECONSTRUCTION' THEN sl.id END) AS current_data_evidence_count,
                    COUNT(DISTINCT CASE WHEN spr.ppwr_evidence_basis = 'CURRENT_DATA_RECONSTRUCTION' THEN sl.id END) AS current_ppwr_reconstruction_count,
                    SUM(CASE WHEN spi.ppwr_calculation_status <> 'COMPLETE' THEN 1 ELSE 0 END) AS missing_ppwr_snapshot_count
             FROM shipment_log sl
             LEFT JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
             LEFT JOIN shipment_packaging_item spi ON spi.id_shipment_packaging_record = spr.id
             WHERE sl.shipment_date >= :date_from AND sl.shipment_date <= :date_to",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );

        return $rows[0] ?? [];
    }
}
