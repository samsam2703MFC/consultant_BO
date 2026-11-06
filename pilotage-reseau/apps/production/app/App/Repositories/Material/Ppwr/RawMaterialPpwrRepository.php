<?php

namespace App\Repositories\Material\Ppwr;

use App\Core\Helpers\BaseRepository;

class RawMaterialPpwrRepository extends BaseRepository
{
    protected string $table = 'raw_material_ppwr_profile';

    protected $allowedColumns = [
        'id_raw_material', 'identification_number', 'identification_source', 'packaging_level', 'packaging_type', 'dominant_material',
        'food_contact_status', 'recycled_content_percent', 'recycled_content_status', 'recyclability_status',
        'recyclability_grade', 'recyclability_assessment_date', 'reuse_status', 'target_reuse_cycles',
        'return_system_description', 'minimisation_status', 'minimisation_justification',
        'verification_status', 'verified_at',
    ];

    public function getPackagingMaterialsWithProfiles(): array
    {
        return $this->query(
            "SELECT rm.id AS id_raw_material, rm.name AS material_name, u.name AS unit_name,
                    rm.bdo_material_category, rm.packaging_usage_type, rm.packaging_unit_weight_g,
                    p.id AS id_profile, p.identification_number, p.identification_source, p.packaging_level, p.packaging_type,
                    p.dominant_material, p.food_contact_status, p.recycled_content_percent,
                    p.recycled_content_status, p.recyclability_status, p.recyclability_grade,
                    p.recyclability_assessment_date, p.reuse_status, p.target_reuse_cycles,
                    p.return_system_description, p.minimisation_status, p.minimisation_justification,
                    p.verification_status,
                    d.id AS id_dimension, d.length_mm, d.width_mm, d.height_mm,
                    d.outer_volume_cm3, d.inner_volume_cm3, d.measurement_source, d.measured_at
             FROM raw_material rm
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN raw_material_ppwr_profile p ON p.id_raw_material = rm.id
             LEFT JOIN raw_material_ppwr_dimension d
                    ON d.id_raw_material_ppwr_profile = p.id AND d.is_active = 1
             WHERE rm.is_part_of_package = 1
             ORDER BY rm.name",
        ) ?: [];
    }

    public function getPackagingMaterialById(int $materialId): array
    {
        $rows = $this->query(
            "SELECT rm.id, rm.name, u.name AS unit_name, rm.is_part_of_package, rm.packaging_unit_weight_g
             FROM raw_material rm
             LEFT JOIN unit u ON u.id = rm.id_unit
             WHERE rm.id = :id",
            ['id' => $materialId]
        );

        return $rows[0] ?? [];
    }

    public function getProfileByMaterialId(int $materialId): array
    {
        return $this->first(['id_raw_material' => $materialId]);
    }

    public function getActiveDimensionByProfileId(int $profileId): array
    {
        $rows = $this->query(
            'SELECT * FROM raw_material_ppwr_dimension WHERE id_raw_material_ppwr_profile = :profile_id AND is_active = 1 LIMIT 1',
            ['profile_id' => $profileId]
        );

        return $rows[0] ?? [];
    }

    public function saveActiveDimension(int $profileId, array $data): void
    {
        $dimension = $this->getActiveDimensionByProfileId($profileId);
        $fields = [
            'length_mm', 'width_mm', 'height_mm', 'outer_volume_cm3', 'inner_volume_cm3',
            'measurement_source', 'measured_at',
        ];
        $payload = array_intersect_key($data, array_flip($fields));

        if ($dimension) {
            $set = implode(', ', array_map(static fn(string $field): string => "{$field} = :{$field}", array_keys($payload)));
            $payload['id'] = (int)$dimension['id'];
            $this->customUpdate("UPDATE raw_material_ppwr_dimension SET {$set} WHERE id = :id", $payload);
            return;
        }

        $payload['id_raw_material_ppwr_profile'] = $profileId;
        $payload['is_active'] = 1;
        $fields = array_keys($payload);
        $this->customInsert(
            'INSERT INTO raw_material_ppwr_dimension (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')',
            $payload
        );
    }

    public function updatePackagingUnitWeight(int $materialId, ?float $weight): void
    {
        $this->customUpdate(
            'UPDATE raw_material SET packaging_unit_weight_g = :weight WHERE id = :id',
            ['weight' => $weight, 'id' => $materialId]
        );
    }
}
