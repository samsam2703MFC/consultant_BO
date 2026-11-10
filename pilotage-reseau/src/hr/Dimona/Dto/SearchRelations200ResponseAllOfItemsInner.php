<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « searchRelations_200_response_allOf_items_inner » — docs/dimona/openapi_dimona_v2.yaml, ligne 2617.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class SearchRelations200ResponseAllOfItemsInner extends AbstractDto
{
    public const SCHEMA = 'searchRelations_200_response_allOf_items_inner';

    /** Any absolute URI that is using http or https protocol */
    public ?string $href = null;
    public ?Employer $employer = null;
    public ?Worker $worker = null;
    /** Start date of the Dimona period */
    public ?string $startDate = null;
    /** End date of the Dimona period */
    public ?string $endDate = null;
    /** Number of periods between the worker and the employer */
    public ?int $numberOfPeriods = null;
    /** Number of periods canceled between the worker and the employer */
    public ?int $numberOfPeriodsCanceled = null;
    /** Number of Dimona features between the worker and the employer */
    public ?int $numberOfFeatures = null;
    /** Number of periods as a temporary worker between the worker and the employer */
    public ?int $numberOfPeriodsTemporaryWorker = null;
    public ?array $jointCommissionNumbers = null;
    public ?array $workerTypes = null;
    public ?string $lastUpdateDate = null;

    public static function fields(): array
    {
        return [
            'href' => ['type' => 'string', 'format' => 'uri'],
            'employer' => ['ref' => Employer::class],
            'worker' => ['ref' => Worker::class],
            'startDate' => ['type' => 'string', 'format' => 'date'],
            'endDate' => ['type' => 'string', 'format' => 'date'],
            'numberOfPeriods' => ['type' => 'integer', 'format' => 'int32'],
            'numberOfPeriodsCanceled' => ['type' => 'integer', 'format' => 'int32'],
            'numberOfFeatures' => ['type' => 'integer', 'format' => 'int32'],
            'numberOfPeriodsTemporaryWorker' => ['type' => 'integer', 'format' => 'int32'],
            'jointCommissionNumbers' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0, 'alias' => 'JointCommissionNumber']],
            'workerTypes' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0, 'alias' => 'WorkerType']],
            'lastUpdateDate' => ['type' => 'string', 'format' => 'date'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
