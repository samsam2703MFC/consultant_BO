<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DimonaIn » — docs/dimona/openapi_dimona_v2.yaml, ligne 1276.
 * Block containing the information necessary for a Dimona declaration of entry into service
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DimonaIn extends AbstractDto
{
    public const SCHEMA = 'DimonaIn';

    public ?References $references = null;
    /** Start date of the Dimona period */
    public ?string $startDate = null;
    /** Starting time of the Dimona period in HHMM format */
    public ?string $startHour = null;
    /** End date of the Dimona period */
    public ?string $endDate = null;
    /** Finishing time of the Dimona period */
    public ?string $endHour = null;
    /** Number of work days planned by the employer over the declared Dimona period */
    public ?int $plannedDaysNumber = null;
    /** Number of hours of work planned by the employer over the declared Dimona period */
    public ?int $plannedHoursNumber = null;
    public ?DimonaFeatures $features = null;
    public ?ConstructionControlCards $constructionControlCards = null;
    public ?StudentPlaceOfWork $studentPlaceOfWork = null;
    public ?SmallStatutesInformation $smallStatutesInformation = null;

    public static function fields(): array
    {
        return [
            'references' => ['ref' => References::class],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'startHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'endHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'plannedDaysNumber' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1, 'maximum' => 999],
            'plannedHoursNumber' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1, 'maximum' => 999],
            'features' => ['ref' => DimonaFeatures::class],
            'constructionControlCards' => ['ref' => ConstructionControlCards::class],
            'studentPlaceOfWork' => ['ref' => StudentPlaceOfWork::class],
            'smallStatutesInformation' => ['ref' => SmallStatutesInformation::class],
        ];
    }

    public static function required(): array
    {
        return ['features', 'startDate'];
    }
}
