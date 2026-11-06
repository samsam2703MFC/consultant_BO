<?php

require __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Material\Ppwr\RawMaterialPpwrRepository;
use App\Services\Material\Ppwr\RawMaterialPpwrService;

final class InMemoryRawMaterialPpwrRepository extends RawMaterialPpwrRepository
{
    public array $profile = [];
    public array $dimension = [];
    public ?float $weight = null;

    public function __construct()
    {
    }

    public function getPackagingMaterialById(int $materialId): array
    {
        return $materialId === 11 ? ['id' => 11, 'name' => 'Karton testowy', 'is_part_of_package' => 1] : [];
    }

    public function getProfileByMaterialId(int $materialId): array
    {
        return $this->profile;
    }

    public function insert(array $data): int|bool
    {
        $this->profile = ['id' => 71, ...$data];
        return 71;
    }

    public function update(mixed $id, array $data, $id_column = 'id'): bool
    {
        $this->profile = ['id' => $id, ...$this->profile, ...$data];
        return true;
    }

    public function saveActiveDimension(int $profileId, array $data): void
    {
        $this->dimension = ['id_raw_material_ppwr_profile' => $profileId, ...$data];
    }

    public function updatePackagingUnitWeight(int $materialId, ?float $weight): void
    {
        $this->weight = $weight;
    }
}

function ppwrExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repository = new InMemoryRawMaterialPpwrRepository();
$service = new RawMaterialPpwrService($repository);
$result = $service->saveProfile(11, [
    'packaging_level' => 'TRANSPORT',
    'identification_number' => 'SUP-BOX-11',
    'identification_source' => 'SUPPLIER',
    'packaging_type' => 'CARTON_BOX',
    'dominant_material' => 'PLASTIC',
    'food_contact_status' => 'NO',
    'recycled_content_percent' => '0',
    'recycled_content_status' => 'VERIFIED',
    'recyclability_status' => 'ASSESSED',
    'recyclability_grade' => 'B',
    'minimisation_status' => 'ASSESSED',
    'reuse_status' => 'SINGLE_USE',
    'length_mm' => '100',
    'width_mm' => '200',
    'height_mm' => '300',
    'measurement_source' => 'SUPPLIER',
    'packaging_unit_weight_g' => '52.5',
]);

ppwrExpect($result['verification_status'] === 'COMPLETE', 'Complete PPWR profile should be marked COMPLETE.');
ppwrExpect($repository->dimension['outer_volume_cm3'] === 6000.0, 'Outer volume should be calculated from dimensions.');
ppwrExpect($repository->profile['recycled_content_percent'] === 0.0, 'Zero recycled content must be valid data.');
ppwrExpect($repository->weight === 52.5, 'Packaging unit weight must be stored only in raw_material.');

echo "raw_material_ppwr_service_test: OK\n";
