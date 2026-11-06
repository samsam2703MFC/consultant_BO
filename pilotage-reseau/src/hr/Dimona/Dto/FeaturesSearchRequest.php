<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « FeaturesSearchRequest » — docs/dimona/openapi_dimona_v2.yaml, ligne 1549.
 * Block to declare the characteristics of the Dimona period
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class FeaturesSearchRequest extends AbstractDto
{
    public const SCHEMA = 'FeaturesSearchRequest';

    public ?array $jointCommissionNumbers = null;
    public ?array $excludedJointCommissionNumbers = null;
    public ?array $workerTypes = null;
    public ?array $excludedWorkerTypes = null;
    /** For employers ORPSS you need to define sometimes the sub entity number of the employer. */
    public ?string $subEntityNumber = null;
    public ?UserEnterpriseSearch $userEnterpriseSearch = null;

    public static function fields(): array
    {
        return [
            'jointCommissionNumbers' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0, 'alias' => 'JointCommissionNumber']],
            'excludedJointCommissionNumbers' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0, 'alias' => 'JointCommissionNumber']],
            'workerTypes' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0, 'alias' => 'WorkerType']],
            'excludedWorkerTypes' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0, 'alias' => 'WorkerType']],
            'subEntityNumber' => ['type' => 'string', 'pattern' => '^[0-9]{1,10}$'],
            'userEnterpriseSearch' => ['ref' => UserEnterpriseSearch::class],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
