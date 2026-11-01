<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DimonaUpdate » — docs/dimona/openapi_dimona_v2.yaml, ligne 1415.
 * Block containing the information necessary for a Dimona declaration of modification of an
 * employer-worker relationship
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DimonaUpdate extends AbstractDto
{
    public const SCHEMA = 'DimonaUpdate';

    public ?References $references = null;
    /** Number that uniquely identifies a Dimona period. This number is automatically assigned to the Dimona IN declaration that generates the period */
    public ?int $periodId = null;
    /** Start date of the Dimona period */
    public ?string $startDate = null;
    /** Starting time of the Dimona period */
    public ?string $startHour = null;
    /** End date of the Dimona period */
    public ?string $endDate = null;
    /** Finishing time of the Dimona period */
    public ?string $endHour = null;
    /** Number of work days planned by the employer over the declared Dimona period */
    public ?int $plannedDaysNumber = null;
    /** Number of hours of work planned by the employer over the declared Dimona period */
    public ?int $plannedHoursNumber = null;

    public static function fields(): array
    {
        return [
            'references' => ['ref' => References::class],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'startHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'endHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'plannedDaysNumber' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1, 'maximum' => 999],
            'plannedHoursNumber' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1, 'maximum' => 999],
        ];
    }

    public static function required(): array
    {
        return ['periodId'];
    }
}
