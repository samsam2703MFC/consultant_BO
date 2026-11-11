<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « PeriodSearchRequest » — docs/dimona/openapi_dimona_v2.yaml, ligne 1879.
 * Block containing all the parameters which can be used to search fo a period
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class PeriodSearchRequest extends AbstractDto
{
    public const SCHEMA = 'PeriodSearchRequest';

    public ?EmployerSearch $employer = null;
    public ?WorkerSearch $worker = null;
    public ?FeaturesSearchRequest $features = null;
    /** Search for active periods starting from this date */
    public ?string $activeFromDate = null;
    /** Search for active periods up to this date */
    public ?string $activeToDate = null;
    /** Parameter to be used in order to avoid intercalation with begin and end date */
    public ?bool $inOrOutOnly = null;
    /** Filter to include modifications starting from a specific date */
    public ?string $modificationFromStartDate = null;
    /** Filter to include modifications ending at a specific date */
    public ?string $modificationToEndDate = null;
    /** Filter to include cancelled periods. By default, all are returned */
    public ?bool $isCanceled = null;
    /** Filter to include periods where declaration is made by the NSSO and not modifiable */
    public ?bool $isLocked = null;
    /** Filter to include periods cancelled by NSSO */
    public ?bool $isCanceledNsso = null;
    /** Filter to include late periods */
    public ?bool $isLate = null;
    /** Filter to include validated flexi job periods */
    public ?bool $isAllowedFlexiJob = null;

    public static function fields(): array
    {
        return [
            'employer' => ['ref' => EmployerSearch::class],
            'worker' => ['ref' => WorkerSearch::class],
            'features' => ['ref' => FeaturesSearchRequest::class],
            'activeFromDate' => ['type' => 'string', 'format' => 'date'],
            'activeToDate' => ['type' => 'string', 'format' => 'date'],
            'inOrOutOnly' => ['type' => 'boolean'],
            'modificationFromStartDate' => ['type' => 'string', 'format' => 'date'],
            'modificationToEndDate' => ['type' => 'string', 'format' => 'date'],
            'isCanceled' => ['type' => 'boolean'],
            'isLocked' => ['type' => 'boolean'],
            'isCanceledNsso' => ['type' => 'boolean'],
            'isLate' => ['type' => 'boolean'],
            'isAllowedFlexiJob' => ['type' => 'boolean'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
