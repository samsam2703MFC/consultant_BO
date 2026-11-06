<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DailyRegistrationUpdate » — docs/dimona/openapi_dimona_v2.yaml, ligne 792.
 * Block containing the information necessary for a daily registration modification
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DailyRegistrationUpdate extends AbstractDto
{
    public const SCHEMA = 'DailyRegistrationUpdate';

    public ?References $references = null;
    /** Number that uniquely identifies a daily registration */
    public ?int $dailyRegistrationId = null;
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
            'dailyRegistrationId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'startHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'endHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
        ];
    }

    public static function required(): array
    {
        return ['dailyRegistrationId'];
    }
}
