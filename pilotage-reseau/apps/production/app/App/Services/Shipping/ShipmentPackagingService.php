<?php

namespace App\Services\Shipping;

use App\Exceptions\CustomException;
use App\Repositories\Document\Wz\WZRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Repositories\Shipping\Packaging\ShipmentPackagingItemRepository;
use App\Repositories\Shipping\Packaging\ShipmentPackagingRecordRepository;
use App\Repositories\Shipping\Packaging\ShipmentPackagingSourceRepository;
use App\Repositories\Material\MaterialRepository;

class ShipmentPackagingService
{
    public function __construct(
        private ShipmentPackagingRecordRepository $recordRepository,
        private ShipmentPackagingItemRepository $itemRepository,
        private ShipmentPackagingSourceRepository $sourceRepository,
        private ShippingLogRepository $shippingLogRepository,
        private WZRepository $wzRepository,
        private MaterialRepository $materialRepository
    ) {
    }

    public function createSnapshot(
        int $shipmentId,
        int $wzId,
        ?int $employeeId = null,
        string $evidenceBasis = 'SHIPMENT_SNAPSHOT'
    ): int
    {
        $existing = $this->recordRepository->getByShipmentId($shipmentId);
        if ($existing) {
            return (int)$existing['id'];
        }

        $items = $this->calculateItems($wzId);
        $requiresData = count(array_filter(
            $items,
            static fn(array $item): bool => $item['calculation_status'] !== 'COMPLETE'
        )) > 0;

        $recordId = $this->recordRepository->insert([
            'id_shipment_log' => $shipmentId,
            'status' => $requiresData ? 'REQUIRES_DATA' : 'GENERATED',
            'evidence_basis' => $evidenceBasis,
            'ppwr_evidence_basis' => $evidenceBasis === 'SHIPMENT_SNAPSHOT' ? 'SHIPMENT_SNAPSHOT' : 'CURRENT_DATA_RECONSTRUCTION',
            'ppwr_generated_at' => date('Y-m-d H:i:s'),
            'id_employee_generated' => $employeeId,
        ]);

        if (!$recordId || !is_numeric($recordId)) {
            throw new CustomException('SHIPMENT_PACKAGING_RECORD_INSERT_FAILED', 'errors', 500);
        }

        foreach ($items as $item) {
            $item['id_shipment_packaging_record'] = (int)$recordId;
            $inserted = $this->itemRepository->insert($item);
            if (!$inserted) {
                throw new CustomException('SHIPMENT_PACKAGING_ITEM_INSERT_FAILED', 'errors', 500);
            }
        }

        return (int)$recordId;
    }

    /**
     * Creates evidence for an already shipped order using the current recipe and
     * current packaging master data. It intentionally never replaces an existing record.
     *
     * @return array{record_id:int,created:bool}
     */
    public function createCurrentDataEvidence(int $shipmentId): array
    {
        $shipment = $this->shippingLogRepository->first(['id' => $shipmentId]);
        if (!$shipment) {
            throw new CustomException('Nie znaleziono wysyłki.', 'errors', 404);
        }
        if (empty($shipment['id_wz'])) {
            throw new CustomException('Wysyłka nie ma dokumentu WZ, dlatego nie można utworzyć ewidencji opakowań.', 'errors', 422);
        }

        $existing = $this->recordRepository->getByShipmentId($shipmentId);
        if ($existing) {
            return ['record_id' => (int)$existing['id'], 'created' => false];
        }

        return [
            'record_id' => $this->createSnapshot(
                $shipmentId,
                (int)$shipment['id_wz'],
                null,
                'CURRENT_DATA_RECONSTRUCTION'
            ),
            'created' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function calculateItems(int $wzId): array
    {
        $inputs = $this->sourceRepository->getPackagingInputsByWzId($wzId);
        $items = [];

        foreach ($inputs as $input) {
            $cartons = (int)$input['shipped_cartons'];
            $unit = $this->normaliseUnit((string)($input['unit_name'] ?? ''));

            $item = [
                'id_document_wz' => $wzId,
                'id_product' => (int)$input['id_product'],
                'product_name_snapshot' => (string)$input['product_name'],
                'product_ppc_snapshot' => $input['product_ppc'] !== null ? (int)$input['product_ppc'] : null,
                'id_raw_material' => $input['material_id'] !== null ? (int)$input['material_id'] : null,
                'is_sales_packaging_snapshot' => $input['material_id'] !== null
                    && (int)$input['selected_sales_material_id'] === (int)$input['material_id'] ? 1 : 0,
                'sales_packaging_verification_status_snapshot' => $input['sales_packaging_verification_status'] ?: null,
                'material_name_snapshot' => $input['material_name'] ?: null,
                'ppwr_profile_id_snapshot' => $input['ppwr_profile_id'] !== null ? (int)$input['ppwr_profile_id'] : null,
                'ppwr_identification_number_snapshot' => $input['ppwr_identification_number'] ?: null,
                'ppwr_identification_source_snapshot' => $input['ppwr_identification_source'] ?: null,
                'ppwr_packaging_level_snapshot' => $input['ppwr_packaging_level'] ?: null,
                'ppwr_packaging_type_snapshot' => $input['ppwr_packaging_type'] ?: null,
                'ppwr_dominant_material_snapshot' => $input['ppwr_dominant_material'] ?: null,
                'ppwr_recycled_content_percent_snapshot' => $input['ppwr_recycled_content_percent'] !== null ? (float)$input['ppwr_recycled_content_percent'] : null,
                'ppwr_recycled_content_status_snapshot' => $input['ppwr_recycled_content_status'] ?: null,
                'ppwr_recyclability_status_snapshot' => $input['ppwr_recyclability_status'] ?: null,
                'ppwr_recyclability_grade_snapshot' => $input['ppwr_recyclability_grade'] ?: null,
                'ppwr_recyclability_assessment_date_snapshot' => $input['ppwr_recyclability_assessment_date'] ?: null,
                'ppwr_reuse_status_snapshot' => $input['ppwr_reuse_status'] ?: null,
                'ppwr_target_reuse_cycles_snapshot' => $input['ppwr_target_reuse_cycles'] !== null ? (int)$input['ppwr_target_reuse_cycles'] : null,
                'ppwr_minimisation_status_snapshot' => $input['ppwr_minimisation_status'] ?: null,
                'ppwr_profile_verification_status_snapshot' => $input['ppwr_profile_verification_status'] ?: null,
                'ppwr_outer_volume_cm3_snapshot' => $input['ppwr_outer_volume_cm3'] !== null ? (float)$input['ppwr_outer_volume_cm3'] : null,
                'ppwr_inner_volume_cm3_snapshot' => $input['ppwr_inner_volume_cm3'] !== null ? (float)$input['ppwr_inner_volume_cm3'] : null,
                'bdo_material_category_snapshot' => $input['bdo_material_category'] ?: null,
                'packaging_usage_type_snapshot' => $input['packaging_usage_type'] ?: null,
                'source_unit_snapshot' => $unit ?: null,
                'units_per_carton_snapshot' => $input['units_per_carton'] !== null ? (float)$input['units_per_carton'] : null,
                'shipped_cartons' => $cartons,
                'packaging_units_total' => null,
                'unit_weight_g_snapshot' => $input['packaging_unit_weight_g'] !== null ? (float)$input['packaging_unit_weight_g'] : null,
                'total_weight_g' => null,
                'calculation_status' => 'COMPLETE',
                ...$this->ppwrCalculationData($input, $cartons, $unit),
            ];

            if ($input['id_recipe'] === null) {
                $item['calculation_status'] = 'MISSING_RECIPE';
                $items[] = $item;
                continue;
            }
            if ($input['material_id'] === null) {
                $item['calculation_status'] = 'MISSING_PACKAGING';
                $items[] = $item;
                continue;
            }
            if (!$input['bdo_material_category'] || !$input['packaging_usage_type']) {
                $item['calculation_status'] = 'MISSING_CATEGORY';
                $items[] = $item;
                continue;
            }

            $calculation = ShipmentPackagingCalculator::calculate(
                $cartons,
                (float)$input['units_per_carton'],
                $unit,
                $input['packaging_unit_weight_g'] !== null ? (float)$input['packaging_unit_weight_g'] : null
            );
            $item['packaging_units_total'] = $calculation['packaging_units_total'];
            if ($calculation['requires_unit_weight'] && $calculation['total_weight_g'] === null) {
                $item['calculation_status'] = 'MISSING_WEIGHT';
                $items[] = $item;
                continue;
            }
            $item['total_weight_g'] = $calculation['total_weight_g'];
            $items[] = $item;
        }

        return $items;
    }

    /** @return array{shipment: array<string,mixed>, record: array<string,mixed>, items: list<array<string,mixed>>, summary: list<array<string,mixed>>} */
    public function getSnapshotForShipment(int $shipmentId): array
    {
        $shipment = $this->shippingLogRepository->first(['id' => $shipmentId]);
        if (!$shipment) {
            throw new CustomException('SHIPMENT_NOT_FOUND', 'errors', 404);
        }

        $record = $this->recordRepository->getByShipmentId($shipmentId);
        if (!$record) {
            throw new CustomException('SHIPMENT_PACKAGING_NOT_FOUND', 'errors', 404);
        }

        $wz = $this->wzRepository->first(['id' => (int)$shipment['id_wz']]);
        if (!$wz) {
            throw new CustomException('WZ_NOT_FOUND', 'errors', 404);
        }

        return [
            'shipment' => $shipment,
            'wz' => $wz,
            'record' => $record,
            'items' => $this->itemRepository->getByRecordId((int)$record['id']),
            'summary' => $this->itemRepository->getSummaryByRecordId((int)$record['id']),
            'ppwr_summary' => $this->itemRepository->getPpwrSummaryByRecordId((int)$record['id']),
        ];
    }

    /** @return array{record_id:int,created:bool,reconstructed:bool} */
    public function createCurrentPpwrEvidence(int $shipmentId): array
    {
        $shipment = $this->shippingLogRepository->first(['id' => $shipmentId]);
        if (!$shipment) throw new CustomException('Nie znaleziono wysyłki.', 'errors', 404);
        if (empty($shipment['id_wz'])) throw new CustomException('Wysyłka nie ma dokumentu WZ.', 'errors', 422);

        $record = $this->recordRepository->getByShipmentId($shipmentId);
        if (!$record) {
            return [
                'record_id' => $this->createSnapshot($shipmentId, (int)$shipment['id_wz'], null, 'CURRENT_DATA_RECONSTRUCTION'),
                'created' => true,
                'reconstructed' => true,
            ];
        }
        if (($record['ppwr_evidence_basis'] ?? null) === 'SHIPMENT_SNAPSHOT') {
            return ['record_id' => (int)$record['id'], 'created' => false, 'reconstructed' => false];
        }

        $inputs = [];
        foreach ($this->sourceRepository->getPackagingInputsByWzId((int)$shipment['id_wz']) as $input) {
            if ($input['material_id'] !== null) {
                $inputs[(int)$input['id_product'] . ':' . (int)$input['material_id']] = $input;
            }
        }
        foreach ($this->itemRepository->getByRecordId((int)$record['id']) as $item) {
            if (empty($item['id_raw_material'])) continue;
            $key = (int)$item['id_product'] . ':' . (int)$item['id_raw_material'];
            if (isset($inputs[$key])) {
                $input = $inputs[$key];
                $this->itemRepository->update((int)$item['id'], [
                    ...$this->ppwrSnapshotData($input),
                    ...$this->ppwrCalculationData($input, (int)$item['shipped_cartons'], $this->normaliseUnit((string)$item['source_unit_snapshot'])),
                ]);
            }
        }
        $this->recordRepository->update((int)$record['id'], [
            'ppwr_evidence_basis' => 'CURRENT_DATA_RECONSTRUCTION',
            'ppwr_generated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['record_id' => (int)$record['id'], 'created' => false, 'reconstructed' => true];
    }

    public function getDataIssues(): array
    {
        return $this->sourceRepository->getDataIssues();
    }

    /** @return list<array<string, mixed>> */
    public function getMissingItemsForShipment(int $shipmentId): array
    {
        $snapshot = $this->getSnapshotForShipment($shipmentId);
        $items = [];
        foreach ($snapshot['items'] as $item) {
            if ($item['calculation_status'] === 'COMPLETE') {
                continue;
            }

            $material = !empty($item['id_raw_material'])
                ? $this->materialRepository->getById((int)$item['id_raw_material'])
                : false;
            $unit = $this->normaliseUnit((string)($item['source_unit_snapshot'] ?? ''));
            $items[] = [
                'id' => (int)$item['id'],
                'product_name' => $item['product_name_snapshot'],
                'material_name' => $item['material_name_snapshot'],
                'issue' => $item['calculation_status'],
                'editable' => (bool)$material,
                'unit_name' => $item['source_unit_snapshot'],
                'defaults' => [
                    'bdo_material_category' => $material['bdo_material_category'] ?? $item['bdo_material_category_snapshot'],
                    'packaging_usage_type' => $material['packaging_usage_type'] ?? $item['packaging_usage_type_snapshot'],
                    'packaging_unit_weight_g' => $unit === 'g'
                        ? null
                        : ($material['packaging_unit_weight_g'] ?? $item['unit_weight_g_snapshot']),
                ],
                'material_has_existing_data' => (bool)$material && (
                    !empty($material['bdo_material_category'])
                    || !empty($material['packaging_usage_type'])
                    || $material['packaging_unit_weight_g'] !== null
                ),
            ];
        }

        return $items;
    }

    /** @return array{record_status:string,material_updated:bool} */
    public function updateMissingItemForShipment(int $shipmentId, int $itemId, array $payload): array
    {
        $record = $this->recordRepository->getByShipmentId($shipmentId);
        if (!$record) {
            throw new CustomException('Nie znaleziono ewidencji BDO dla tej wysyłki.', 'errors', 404);
        }

        $item = $this->itemRepository->getById($itemId);
        if (!$item || (int)$item['id_shipment_packaging_record'] !== (int)$record['id']) {
            throw new CustomException('Wybrana pozycja nie należy do tej wysyłki.', 'errors', 404);
        }
        if (empty($item['id_raw_material'])) {
            throw new CustomException('Tej pozycji nie można uzupełnić w tym formularzu, ponieważ brakuje powiązanego surowca opakowaniowego.', 'errors', 422);
        }

        $values = $this->resolvePackagingValues((string)$item['source_unit_snapshot'], $payload);
        $calculation = ShipmentPackagingCalculator::calculate(
            (int)$item['shipped_cartons'],
            (float)$item['units_per_carton_snapshot'],
            $this->normaliseUnit((string)$item['source_unit_snapshot']),
            $values['packaging_unit_weight_g']
        );
        if ($calculation['total_weight_g'] === null) {
            throw new CustomException('Nie udało się wyliczyć masy opakowania.', 'errors', 422);
        }

        $this->itemRepository->update($itemId, [
            'bdo_material_category_snapshot' => $values['bdo_material_category'],
            'packaging_usage_type_snapshot' => $values['packaging_usage_type'],
            'unit_weight_g_snapshot' => $values['packaging_unit_weight_g'],
            'packaging_units_total' => $calculation['packaging_units_total'],
            'total_weight_g' => $calculation['total_weight_g'],
            'calculation_status' => 'COMPLETE',
        ]);

        $materialUpdated = filter_var($payload['update_material'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($materialUpdated) {
            $this->materialRepository->update((int)$item['id_raw_material'], $values);
        }

        $hasIncompleteItems = count(array_filter(
            $this->itemRepository->getByRecordId((int)$record['id']),
            static fn(array $recordItem): bool => $recordItem['calculation_status'] !== 'COMPLETE'
        )) > 0;
        $status = $hasIncompleteItems ? 'REQUIRES_DATA' : 'GENERATED';
        $this->recordRepository->update((int)$record['id'], ['status' => $status]);

        return ['record_status' => $status, 'material_updated' => $materialUpdated];
    }

    /** @return array<string,mixed> */
    public function updatePackagingMaterial(int $materialId, array $payload): array
    {
        $material = $this->materialRepository->getById($materialId);
        if (!$material || empty($material['is_part_of_package'])) {
            throw new CustomException('Wybrany surowiec nie jest elementem opakowania.', 'errors', 422);
        }

        $values = $this->resolvePackagingValues((string)($material['unit_name'] ?? ''), $payload);

        $this->materialRepository->update($materialId, [
            ...$values,
        ]);

        return [
            'id' => $materialId,
            ...$values,
            'unit_name' => $material['unit_name'] ?? null,
        ];
    }

    /** @return array{bdo_material_category:string, packaging_usage_type:string, packaging_unit_weight_g:?float} */
    private function resolvePackagingValues(string $unitName, array $payload): array
    {
        $categories = ['PLASTIC', 'ALUMINIUM', 'FERROUS_METAL', 'PAPER_CARDBOARD', 'GLASS', 'WOOD', 'MULTIMATERIAL', 'OTHER'];
        $usageTypes = ['SINGLE_USE', 'REUSABLE'];
        $category = (string)($payload['bdo_material_category'] ?? '');
        $usageType = (string)($payload['packaging_usage_type'] ?? '');
        if (!in_array($category, $categories, true) || !in_array($usageType, $usageTypes, true)) {
            throw new CustomException('Wybierz kategorię BDO oraz rodzaj użycia opakowania.', 'errors', 422);
        }

        $unitWeight = $payload['packaging_unit_weight_g'] ?? null;
        if ($this->normaliseUnit($unitName) === 'g') {
            $unitWeight = null;
        } elseif (!is_numeric($unitWeight) || (float)$unitWeight <= 0) {
            throw new CustomException('Dla materiału liczonego w sztukach podaj masę jednej sztuki większą od 0 g.', 'errors', 422);
        } else {
            $unitWeight = round((float)$unitWeight, 4);
        }

        return [
            'bdo_material_category' => $category,
            'packaging_usage_type' => $usageType,
            'packaging_unit_weight_g' => $unitWeight,
        ];
    }

    private function normaliseUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        return in_array($unit, ['g', 'gram', 'gramy'], true) ? 'g' : $unit;
    }

    private function ppwrSnapshotData(array $input): array
    {
        return [
            'product_ppc_snapshot' => $input['product_ppc'] !== null ? (int)$input['product_ppc'] : null,
            'is_sales_packaging_snapshot' => $input['material_id'] !== null && (int)$input['selected_sales_material_id'] === (int)$input['material_id'] ? 1 : 0,
            'sales_packaging_verification_status_snapshot' => $input['sales_packaging_verification_status'] ?: null,
            'ppwr_profile_id_snapshot' => $input['ppwr_profile_id'] !== null ? (int)$input['ppwr_profile_id'] : null,
            'ppwr_identification_number_snapshot' => $input['ppwr_identification_number'] ?: null,
            'ppwr_identification_source_snapshot' => $input['ppwr_identification_source'] ?: null,
            'ppwr_packaging_level_snapshot' => $input['ppwr_packaging_level'] ?: null,
            'ppwr_packaging_type_snapshot' => $input['ppwr_packaging_type'] ?: null,
            'ppwr_dominant_material_snapshot' => $input['ppwr_dominant_material'] ?: null,
            'ppwr_recycled_content_percent_snapshot' => $input['ppwr_recycled_content_percent'] !== null ? (float)$input['ppwr_recycled_content_percent'] : null,
            'ppwr_recycled_content_status_snapshot' => $input['ppwr_recycled_content_status'] ?: null,
            'ppwr_recyclability_status_snapshot' => $input['ppwr_recyclability_status'] ?: null,
            'ppwr_recyclability_grade_snapshot' => $input['ppwr_recyclability_grade'] ?: null,
            'ppwr_recyclability_assessment_date_snapshot' => $input['ppwr_recyclability_assessment_date'] ?: null,
            'ppwr_reuse_status_snapshot' => $input['ppwr_reuse_status'] ?: null,
            'ppwr_target_reuse_cycles_snapshot' => $input['ppwr_target_reuse_cycles'] !== null ? (int)$input['ppwr_target_reuse_cycles'] : null,
            'ppwr_minimisation_status_snapshot' => $input['ppwr_minimisation_status'] ?: null,
            'ppwr_profile_verification_status_snapshot' => $input['ppwr_profile_verification_status'] ?: null,
            'ppwr_outer_volume_cm3_snapshot' => $input['ppwr_outer_volume_cm3'] !== null ? (float)$input['ppwr_outer_volume_cm3'] : null,
            'ppwr_inner_volume_cm3_snapshot' => $input['ppwr_inner_volume_cm3'] !== null ? (float)$input['ppwr_inner_volume_cm3'] : null,
        ];
    }

    private function ppwrCalculationData(array $input, int $cartons, string $unit): array
    {
        $data = ['ppwr_packaging_units_total' => null, 'ppwr_total_weight_g' => null, 'ppwr_calculation_status' => 'COMPLETE'];
        if ($input['id_recipe'] === null) { $data['ppwr_calculation_status'] = 'MISSING_RECIPE'; return $data; }
        if ($input['material_id'] === null) { $data['ppwr_calculation_status'] = 'MISSING_PACKAGING'; return $data; }
        $calculation = ShipmentPackagingCalculator::calculate($cartons, (float)$input['units_per_carton'], $unit, $input['packaging_unit_weight_g'] !== null ? (float)$input['packaging_unit_weight_g'] : null);
        $data['ppwr_packaging_units_total'] = $calculation['packaging_units_total'];
        $data['ppwr_total_weight_g'] = $calculation['total_weight_g'];
        if ($calculation['total_weight_g'] === null) { $data['ppwr_calculation_status'] = 'MISSING_WEIGHT'; return $data; }
        if ($input['ppwr_profile_id'] === null) { $data['ppwr_calculation_status'] = 'MISSING_PROFILE'; return $data; }
        if (empty($input['ppwr_identification_number'])) $data['ppwr_calculation_status'] = 'MISSING_IDENTIFIER';
        return $data;
    }
}
