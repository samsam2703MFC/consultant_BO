<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DailyRegistrationIn » — docs/dimona/openapi_dimona_v2.yaml, ligne 740.
 * Block containing the information necessary for a daily registration declaration for entry of service
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DailyRegistrationIn extends AbstractDto
{
    public const SCHEMA = 'DailyRegistrationIn';

    public ?References $references = null;
    /** Identification number of the Dimona period */
    public ?int $periodId = null;
    /** Start date of the daily registration */
    public ?string $startDate = null;
    /** Starting time of the daily registration */
    public ?string $startHour = null;
    /** End date of the daily registration */
    public ?string $endDate = null;
    /** Finishing time of the daily registration */
    public ?string $endHour = null;

    public static function fields(): array
    {
        return [
            'references' => ['ref' => References::class],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'startHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'endHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
        ];
    }

    public static function required(): array
    {
        return ['periodId', 'startDate', 'startHour'];
    }
}
