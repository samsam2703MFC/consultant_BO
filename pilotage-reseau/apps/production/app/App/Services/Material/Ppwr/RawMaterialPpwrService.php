<?php

namespace App\Services\Material\Ppwr;

use App\Exceptions\CustomException;
use App\Repositories\Material\Ppwr\RawMaterialPpwrRepository;

class RawMaterialPpwrService
{
    private const LEVELS = ['SALES', 'INNER', 'GROUPED', 'TRANSPORT', 'E_COMMERCE'];
    private const IDENTIFICATION_SOURCES = ['OWN', 'SUPPLIER'];
    private const PACKAGING_TYPES = [
        'CARTON_BOX', 'BAG', 'DOYPACK', 'FILM', 'STRETCH_FILM', 'TRAY', 'CUP_WITH_LID',
        'MOULD', 'LABEL', 'WRAPPING_PAPER', 'PAPER_BAND', 'ADHESIVE_TAPE', 'RIBBON', 'OTHER',
    ];
    private const MATERIALS = ['PLASTIC', 'PAPER_CARDBOARD', 'GLASS', 'ALUMINIUM', 'FERROUS_METAL', 'WOOD', 'MULTIMATERIAL', 'OTHER'];
    private const FOOD_CONTACT = ['YES', 'NO', 'UNKNOWN'];
    private const RECYCLED_CONTENT_STATUS = ['NOT_APPLICABLE', 'NOT_ASSESSED', 'DECLARED', 'VERIFIED'];
    private const RECYCLABILITY_STATUS = ['NOT_ASSESSED', 'ASSESSED', 'VERIFIED', 'NOT_RECYCLABLE'];
    private const REUSE_STATUS = ['NOT_APPLICABLE', 'SINGLE_USE', 'REUSABLE', 'REFILL'];
    private const MINIMISATION_STATUS = ['NOT_ASSESSED', 'ASSESSED', 'VERIFIED'];
    private const MEASUREMENT_SOURCES = ['SUPPLIER', 'INTERNAL_MEASUREMENT', 'SPECIFICATION', 'LEGACY_DESCRIPTION'];

    public function __construct(private RawMaterialPpwrRepository $repository)
    {
    }

    public function getAudit(): array
    {
        return array_map(function (array $row): array {
            $issues = $this->getIssues($row);
            $row['issues'] = $issues;
            $row['computed_status'] = empty($issues) ? 'COMPLETE' : 'REQUIRES_DATA';
            return $row;
        }, $this->repository->getPackagingMaterialsWithProfiles());
    }

    public function saveProfile(int $materialId, array $payload): array
    {
        $material = $this->repository->getPackagingMaterialById($materialId);
        if (!$material || empty($material['is_part_of_package'])) {
            throw new CustomException('Wybrany surowiec nie jest oznaczony jako element opakowania.', 'errors', 422);
        }

        $profileData = $this->normaliseProfile($payload);
        $dimensionData = $this->normaliseDimension($payload);
        $unitWeight = $this->positiveDecimal($payload, 'packaging_unit_weight_g');
        $existing = $this->repository->getProfileByMaterialId($materialId);
        $profileId = $existing
            ? (int)$existing['id']
            : (int)$this->repository->insert(['id_raw_material' => $materialId]);

        $auditRow = array_merge($profileData, $dimensionData, ['id_profile' => $profileId, 'packaging_unit_weight_g' => $unitWeight]);
        $issues = $this->getIssues($auditRow);
        $profileData['verification_status'] = empty($issues) ? 'COMPLETE' : 'REQUIRES_DATA';

        $this->repository->update($profileId, $profileData);
        $this->repository->saveActiveDimension($profileId, $dimensionData);
        $this->repository->updatePackagingUnitWeight($materialId, $unitWeight);

        return [
            'id_profile' => $profileId,
            'verification_status' => $profileData['verification_status'],
            'issues' => $issues,
        ];
    }

    private function normaliseProfile(array $payload): array
    {
        $data = [
            'identification_number' => $this->text($payload, 'identification_number', 255),
            'identification_source' => $this->enum($payload, 'identification_source', self::IDENTIFICATION_SOURCES),
            'packaging_level' => $this->enum($payload, 'packaging_level', self::LEVELS),
            'packaging_type' => $this->enum($payload, 'packaging_type', self::PACKAGING_TYPES),
            'dominant_material' => $this->enum($payload, 'dominant_material', self::MATERIALS),
            'food_contact_status' => $this->enum($payload, 'food_contact_status', self::FOOD_CONTACT, 'UNKNOWN'),
            'recycled_content_status' => $this->enum($payload, 'recycled_content_status', self::RECYCLED_CONTENT_STATUS, 'NOT_ASSESSED'),
            'recyclability_status' => $this->enum($payload, 'recyclability_status', self::RECYCLABILITY_STATUS, 'NOT_ASSESSED'),
            'recyclability_grade' => $this->enum($payload, 'recyclability_grade', ['A', 'B', 'C']),
            'recyclability_assessment_date' => $this->date($payload, 'recyclability_assessment_date'),
            'reuse_status' => $this->enum($payload, 'reuse_status', self::REUSE_STATUS, 'NOT_APPLICABLE'),
            'target_reuse_cycles' => $this->positiveInteger($payload, 'target_reuse_cycles'),
            'return_system_description' => $this->text($payload, 'return_system_description', 500),
            'minimisation_status' => $this->enum($payload, 'minimisation_status', self::MINIMISATION_STATUS, 'NOT_ASSESSED'),
            'minimisation_justification' => $this->text($payload, 'minimisation_justification'),
        ];
        $data['recycled_content_percent'] = $this->percentage($payload, 'recycled_content_percent');

        if ($data['dominant_material'] !== 'PLASTIC') {
            $data['recycled_content_status'] = 'NOT_APPLICABLE';
            $data['recycled_content_percent'] = null;
        }
        if (!in_array($data['reuse_status'], ['REUSABLE', 'REFILL'], true)) {
            $data['target_reuse_cycles'] = null;
            $data['return_system_description'] = null;
        }

        return $data;
    }

    private function normaliseDimension(array $payload): array
    {
        $data = [];
        foreach (['length_mm', 'width_mm', 'height_mm', 'outer_volume_cm3', 'inner_volume_cm3'] as $field) {
            $data[$field] = $this->positiveDecimal($payload, $field);
        }
        $data['measurement_source'] = $this->enum($payload, 'measurement_source', self::MEASUREMENT_SOURCES);
        $data['measured_at'] = $this->date($payload, 'measured_at');

        if ($data['length_mm'] && $data['width_mm'] && $data['height_mm']) {
            $data['outer_volume_cm3'] = round(($data['length_mm'] * $data['width_mm'] * $data['height_mm']) / 1000);
        }
        if ($data['inner_volume_cm3'] && $data['outer_volume_cm3'] && $data['inner_volume_cm3'] > $data['outer_volume_cm3']) {
            throw new CustomException('Objętość wewnętrzna nie może być większa od zewnętrznej.', 'errors', 422);
        }

        return $data;
    }

    private function getIssues(array $row): array
    {
        $issues = [];
        if (empty($row['identification_number'])) $issues[] = 'MISSING_IDENTIFICATION_NUMBER';
        if (empty($row['identification_source'])) $issues[] = 'MISSING_IDENTIFICATION_SOURCE';
        if (empty($row['packaging_unit_weight_g'])) $issues[] = 'MISSING_UNIT_WEIGHT';
        if (empty($row['id_profile']) || empty($row['packaging_level'])) $issues[] = 'MISSING_PACKAGING_LEVEL';
        if (empty($row['packaging_type'])) $issues[] = 'MISSING_PACKAGING_TYPE';
        if (empty($row['dominant_material'])) $issues[] = 'MISSING_DOMINANT_MATERIAL';
        if (($row['dominant_material'] ?? null) === 'PLASTIC' && ($row['recycled_content_status'] ?? 'NOT_ASSESSED') === 'NOT_ASSESSED') $issues[] = 'MISSING_RECYCLED_CONTENT';
        if (($row['recyclability_status'] ?? 'NOT_ASSESSED') === 'NOT_ASSESSED') $issues[] = 'MISSING_RECYCLABILITY';
        if (($row['minimisation_status'] ?? 'NOT_ASSESSED') === 'NOT_ASSESSED') $issues[] = 'MISSING_MINIMISATION';
        if (in_array($row['packaging_level'] ?? null, ['GROUPED', 'TRANSPORT', 'E_COMMERCE'], true) && empty($row['outer_volume_cm3'])) $issues[] = 'MISSING_DIMENSIONS';
        if (in_array($row['reuse_status'] ?? null, ['REUSABLE', 'REFILL'], true) && empty($row['target_reuse_cycles'])) $issues[] = 'MISSING_REUSE_CYCLES';
        return $issues;
    }

    private function enum(array $payload, string $key, array $values, ?string $default = null): ?string
    {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value === '') return $default;
        if (!in_array($value, $values, true)) throw new CustomException("Nieprawidłowa wartość pola {$key}.", 'errors', 422);
        return $value;
    }

    private function text(array $payload, string $key, ?int $maxLength = null): ?string
    {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value === '') return null;
        if ($maxLength && mb_strlen($value) > $maxLength) throw new CustomException("Pole {$key} jest za długie.", 'errors', 422);
        return $value;
    }

    private function date(array $payload, string $key): ?string
    {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new CustomException("Nieprawidłowa data pola {$key}.", 'errors', 422);
        return $value;
    }

    private function positiveDecimal(array $payload, string $key): ?float
    {
        $value = str_replace(',', '.', trim((string)($payload[$key] ?? '')));
        if ($value === '') return null;
        if (!is_numeric($value) || (float)$value <= 0) throw new CustomException("Pole {$key} musi być liczbą większą od 0.", 'errors', 422);
        return (float)$value;
    }

    private function percentage(array $payload, string $key): ?float
    {
        $value = str_replace(',', '.', trim((string)($payload[$key] ?? '')));
        if ($value === '') return null;
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
            throw new CustomException('Zawartość recyklatu musi być liczbą od 0 do 100%.', 'errors', 422);
        }
        return (float)$value;
    }

    private function positiveInteger(array $payload, string $key): ?int
    {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value === '') return null;
        if (!ctype_digit($value) || (int)$value < 1) throw new CustomException("Pole {$key} musi być liczbą całkowitą większą od 0.", 'errors', 422);
        return (int)$value;
    }
}
