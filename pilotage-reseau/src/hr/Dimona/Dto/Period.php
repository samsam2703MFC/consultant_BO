<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Period » — docs/dimona/openapi_dimona_v2.yaml, ligne 1661.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Period extends AbstractDto
{
    public const SCHEMA = 'Period';

    public ?Employer $employer = null;
    public ?Worker $worker = null;
    /** Identification number of the Dimona period */
    public ?int $periodId = null;
    /** Start date of the Dimona period */
    public ?string $startDate = null;
    /** Starting time of the Dimona period */
    public ?string $startHour = null;
    /** End date of the Dimona period */
    public ?string $endDate = null;
    /** Finishing time of the Dimona period */
    public ?string $endHour = null;
    /** Filter to be used for Old Horeca Declaration */
    public ?string $serviceType = null;
    /** Number of work days planned by the employer over the declared Dimona period */
    public ?int $plannedDaysNumber = null;
    /** Number of hours of work planned by the employer over the declared Dimona period */
    public ?int $plannedHoursNumber = null;
    public ?DimonaFeatures $dimonaFeatures = null;
    public ?ConstructionControlCards $constructionControlCards = null;
    public ?StudentPlaceOfWork $studentPlaceOfWork = null;
    public ?SmallStatutesInformation $smallStatutesInformation = null;
    /** Flag specifies if the period is canceled */
    public ?bool $isCanceled = null;
    /** Flag specifies if the period cannot be modified/updated by an employer */
    public ?bool $isLocked = null;
    /** Flag for periods cancelled by NSSO */
    public ?bool $isCanceledNsso = null;
    /** Flag for periods which are late compared to the service entry date */
    public ?bool $isLate = null;
    /** Flag for validated flexi-jobs periods */
    public ?bool $isAllowedFlexiJob = null;
    /** Flag for starter job: specific conditions need to be fulfilled */
    public ?bool $isAllowedStarterJob = null;
    public ?string $creationDate = null;
    public ?string $lastUpdateDate = null;
    /** Last declaration type done for specified period */
    public ?string $lastDimonaType = null;
    /** List of anomalies */
    public ?array $anomalies = null;
    /** Type of anomaly encountered for the period */
    public ?string $anomalySeverity = null;
    /** List of quota features */
    public ?array $quotaFeatures = null;

    public static function fields(): array
    {
        return [
            'employer' => ['ref' => Employer::class],
            'worker' => ['ref' => Worker::class],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'startHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'endHour' => ['type' => 'string', 'pattern' => '^[0-2][0-9][0-5][0-9]$'],
            'serviceType' => ['type' => 'string', 'enum' => ['short', 'long']],
            'plannedDaysNumber' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1, 'maximum' => 999],
            'plannedHoursNumber' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 1, 'maximum' => 999],
            'dimonaFeatures' => ['ref' => DimonaFeatures::class],
            'constructionControlCards' => ['ref' => ConstructionControlCards::class],
            'studentPlaceOfWork' => ['ref' => StudentPlaceOfWork::class],
            'smallStatutesInformation' => ['ref' => SmallStatutesInformation::class],
            'isCanceled' => ['type' => 'boolean'],
            'isLocked' => ['type' => 'boolean'],
            'isCanceledNsso' => ['type' => 'boolean'],
            'isLate' => ['type' => 'boolean'],
            'isAllowedFlexiJob' => ['type' => 'boolean'],
            'isAllowedStarterJob' => ['type' => 'boolean'],
            'creationDate' => ['type' => 'string', 'format' => 'date'],
            'lastUpdateDate' => ['type' => 'string', 'format' => 'date'],
            'lastDimonaType' => ['type' => 'string', 'enum' => ['in', 'out', 'update', 'cancel', 'delete']],
            'anomalies' => ['type' => 'array', 'items' => ['ref' => Anomaly::class]],
            'anomalySeverity' => ['type' => 'string', 'enum' => ['blocking', 'warning', 'none']],
            'quotaFeatures' => ['type' => 'array', 'items' => ['ref' => QuotaFeatures::class]],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
