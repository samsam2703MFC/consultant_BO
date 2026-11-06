<?php

require __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Document\Wz\WZRepository;
use App\Repositories\Material\MaterialRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Repositories\Shipping\Packaging\ShipmentPackagingItemRepository;
use App\Repositories\Shipping\Packaging\ShipmentPackagingRecordRepository;
use App\Repositories\Shipping\Packaging\ShipmentPackagingSourceRepository;
use App\Services\Shipping\ShipmentPackagingService;

final class PpwrSnapshotSourceRepository extends ShipmentPackagingSourceRepository
{
    public function __construct() {}

    public function getPackagingInputsByWzId(int $wzId): array
    {
        return [[
            'id_product' => 7, 'product_name' => 'Tarty', 'product_ppc' => 6,
            'shipped_cartons' => 10, 'id_recipe' => 20, 'material_id' => 31,
            'units_per_carton' => 1, 'material_name' => 'Karton tart',
            'bdo_material_category' => 'PAPER_CARDBOARD', 'packaging_unit_weight_g' => 120,
            'packaging_usage_type' => 'SINGLE_USE', 'unit_name' => 'szt',
            'selected_sales_material_id' => 31, 'sales_packaging_verification_status' => 'COMPLETE',
            'ppwr_profile_id' => 40, 'ppwr_identification_number' => 'SUP-CARTON-31',
            'ppwr_identification_source' => 'SUPPLIER', 'ppwr_packaging_level' => 'SALES',
            'ppwr_packaging_type' => 'CARTON_BOX', 'ppwr_dominant_material' => 'PAPER_CARDBOARD',
            'ppwr_recycled_content_percent' => null, 'ppwr_recycled_content_status' => 'NOT_APPLICABLE',
            'ppwr_recyclability_status' => 'VERIFIED', 'ppwr_recyclability_grade' => 'A',
            'ppwr_recyclability_assessment_date' => '2026-08-01', 'ppwr_reuse_status' => 'SINGLE_USE',
            'ppwr_target_reuse_cycles' => null, 'ppwr_minimisation_status' => 'VERIFIED',
            'ppwr_profile_verification_status' => 'VERIFIED', 'ppwr_outer_volume_cm3' => 9600,
            'ppwr_inner_volume_cm3' => 8400,
        ]];
    }
}

function ppwrSnapshotExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$emptyRecordRepository = new class extends ShipmentPackagingRecordRepository { public function __construct() {} };
$emptyItemRepository = new class extends ShipmentPackagingItemRepository { public function __construct() {} };
$emptyLogRepository = new class extends ShippingLogRepository { public function __construct() {} };
$emptyWzRepository = new class extends WZRepository { public function __construct() {} };
$emptyMaterialRepository = new class extends MaterialRepository { public function __construct() {} };
$service = new ShipmentPackagingService(
    $emptyRecordRepository,
    $emptyItemRepository,
    new PpwrSnapshotSourceRepository(),
    $emptyLogRepository,
    $emptyWzRepository,
    $emptyMaterialRepository
);

$item = $service->calculateItems(999)[0];
ppwrSnapshotExpect($item['product_ppc_snapshot'] === 6, 'PPC must be snapshotted with the shipment.');
ppwrSnapshotExpect($item['is_sales_packaging_snapshot'] === 1, 'Selected sales carton must be identified in the snapshot.');
ppwrSnapshotExpect($item['ppwr_identification_number_snapshot'] === 'SUP-CARTON-31', 'PPWR material identifier must be snapshotted.');
ppwrSnapshotExpect($item['ppwr_packaging_type_snapshot'] === 'CARTON_BOX', 'PPWR type must be snapshotted.');
ppwrSnapshotExpect($item['ppwr_recyclability_status_snapshot'] === 'VERIFIED', 'PPWR recyclability status must be snapshotted.');
ppwrSnapshotExpect($item['ppwr_outer_volume_cm3_snapshot'] === 9600.0, 'PPWR volume must be snapshotted.');
ppwrSnapshotExpect($item['ppwr_total_weight_g'] === 1200.0, 'PPWR weight must be calculated independently from BDO status.');
ppwrSnapshotExpect($item['ppwr_calculation_status'] === 'COMPLETE', 'Complete PPWR data must have an independent PPWR status.');
ppwrSnapshotExpect($item['total_weight_g'] === 1200.0, 'Existing BDO mass calculation must stay unchanged.');

echo "shipment_packaging_ppwr_snapshot_test: OK\n";
