<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DailyRegistration » — docs/dimona/openapi_dimona_v2.yaml, ligne 654.
 * Block for the data necessary for daily registrations
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DailyRegistration extends AbstractDto
{
    public const SCHEMA = 'DailyRegistration';

    /** Number that uniquely identifies a daily registration */
    public ?int $dailyRegistrationId = null;
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
    /** Creation date of the daily registration */
    public ?string $creationDate = null;
    /** Filter to include cancelled daily registrations. By default, all are returned */
    public ?bool $isCanceled = null;

    public static function fields(): array
    {
        return [
            'dailyRegistrationId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'startHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'endHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'creationDate' => ['type' => 'string', 'format' => 'date'],
            'isCanceled' => ['type' => 'boolean'],
        ];
    }

    public static function required(): array
    {
        return ['periodId'];
    }
}
