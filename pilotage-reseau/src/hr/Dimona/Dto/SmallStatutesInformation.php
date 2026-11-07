<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « SmallStatutesInformation » — docs/dimona/openapi_dimona_v2.yaml, ligne 2128.
 * Block for the data necessary for the calculation of risk premiums for small status (internship and
 * professional training).
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class SmallStatutesInformation extends AbstractDto
{
    public const SCHEMA = 'SmallStatutesInformation';

    /** The nature of the work */
    public ?string $employmentNature = null;
    /** Activity versus risk. See Annex 6 - Activity versus risk List */
    public ?int $activityWithRisk = null;
    /** Code that specifies the risk coverage regime and compensation of the worker. This code can be F1 or F2. See Annex 21 - Worker status */
    public ?string $workerStatus = null;

    public static function fields(): array
    {
        return [
            'employmentNature' => ['type' => 'string', 'enum' => ['employee', 'worker']],
            'activityWithRisk' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1.0, 'maximum' => 999.0],
            'workerStatus' => ['type' => 'string', 'pattern' => '^[F][1,2]$'],
        ];
    }

    public static function required(): array
    {
        return ['employmentNature', 'workerStatus'];
    }
}
