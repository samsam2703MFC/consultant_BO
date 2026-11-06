<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « QuotaFeatures » — docs/dimona/openapi_dimona_v2.yaml, ligne 1977.
 * Details of quota characteristics
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class QuotaFeatures extends AbstractDto
{
    public const SCHEMA = 'QuotaFeatures';

    /** Quota type */
    public ?string $quotaType = null;
    /** Number of hours exceeded */
    public ?int $exceedingQuotaHours = null;
    /** Number of days exceeded */
    public ?int $exceedingQuotaDays = null;

    public static function fields(): array
    {
        return [
            'quotaType' => ['type' => 'string', 'enum' => ['a17_pubtv', 'a17_year_sport', 'a17_quarter_sport', 'a17_student_sport', 'a17_year_other', 'a17_quarter_other', 'a17_student_other', 'article17', 'horecaWorker', 'horecaEmployer', 'student']],
            'exceedingQuotaHours' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 0, 'maximum' => 999],
            'exceedingQuotaDays' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 0, 'maximum' => 999],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
