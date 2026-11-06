<?php

namespace App\Repositories\Shipping\Packaging;

use App\Core\Helpers\BaseRepository;

class ShipmentPackagingItemRepository extends BaseRepository
{
    protected string $table = 'shipment_packaging_item';

    protected $allowedColumns = [
        'id_shipment_packaging_record', 'id_document_wz', 'id_product', 'product_name_snapshot', 'product_ppc_snapshot',
        'id_raw_material', 'is_sales_packaging_snapshot', 'sales_packaging_verification_status_snapshot',
        'material_name_snapshot', 'ppwr_profile_id_snapshot',
        'ppwr_identification_number_snapshot', 'ppwr_identification_source_snapshot', 'ppwr_packaging_level_snapshot',
        'ppwr_packaging_type_snapshot', 'ppwr_dominant_material_snapshot', 'ppwr_recycled_content_percent_snapshot',
        'ppwr_recycled_content_status_snapshot', 'ppwr_recyclability_status_snapshot', 'ppwr_recyclability_grade_snapshot',
        'ppwr_recyclability_assessment_date_snapshot', 'ppwr_reuse_status_snapshot', 'ppwr_target_reuse_cycles_snapshot',
        'ppwr_minimisation_status_snapshot', 'ppwr_profile_verification_status_snapshot',
        'ppwr_outer_volume_cm3_snapshot', 'ppwr_inner_volume_cm3_snapshot', 'bdo_material_category_snapshot',
        'packaging_usage_type_snapshot', 'source_unit_snapshot', 'units_per_carton_snapshot',
        'shipped_cartons', 'packaging_units_total', 'ppwr_packaging_units_total', 'unit_weight_g_snapshot',
        'total_weight_g', 'ppwr_total_weight_g', 'calculation_status', 'ppwr_calculation_status'
    ];

    public function getByRecordId(int $recordId): array
    {
        return $this->query(
            "SELECT * FROM shipment_packaging_item WHERE id_shipment_packaging_record = :record_id ORDER BY product_name_snapshot, material_name_snapshot",
            ['record_id' => $recordId]
        ) ?: [];
    }

    /** @return array<string, mixed> */
    public function getById(int $itemId): array
    {
        return $this->first(['id' => $itemId]);
    }

    public function getSummaryByRecordId(int $recordId): array
    {
        return $this->query(
            "SELECT bdo_material_category_snapshot AS category,
                    packaging_usage_type_snapshot AS usage_type,
                    SUM(ppwr_total_weight_g) AS total_weight_g
             FROM shipment_packaging_item
             WHERE id_shipment_packaging_record = :record_id
               AND ppwr_total_weight_g IS NOT NULL
             GROUP BY bdo_material_category_snapshot, packaging_usage_type_snapshot
             ORDER BY bdo_material_category_snapshot, packaging_usage_type_snapshot",
            ['record_id' => $recordId]
        ) ?: [];
    }

    public function getPpwrSummaryByRecordId(int $recordId): array
    {
        return $this->query(
            "SELECT COALESCE(ppwr_dominant_material_snapshot, 'UNKNOWN') AS material,
                    SUM(total_weight_g) AS total_weight_g
             FROM shipment_packaging_item
             WHERE id_shipment_packaging_record = :record_id
               AND calculation_status = 'COMPLETE'
             GROUP BY COALESCE(ppwr_dominant_material_snapshot, 'UNKNOWN')
             ORDER BY material",
            ['record_id' => $recordId]
        ) ?: [];
    }
}
