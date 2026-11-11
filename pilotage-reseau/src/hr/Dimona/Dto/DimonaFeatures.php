<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DimonaFeatures » — docs/dimona/openapi_dimona_v2.yaml, ligne 1227.
 * Block to declare the characteristics of the Dimona period
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DimonaFeatures extends AbstractDto
{
    public const SCHEMA = 'DimonaFeatures';

    /** Joint commission number. Watch the administration instructions at: https://www.socialsecurity.be/employer/instructions/dmfa/fr/latest/instructions/obligations/o */
    public ?string $jointCommissionNumber = null;
    /** The worker type. Watch the administration instructions at: https://www.socialsecurity.be/employer/instructions/dmfa/fr/latest/instructions/obligations/obligatio */
    public ?string $workerType = null;
    /** For employers ORPSS you need to define sometimes the sub entity number of the employer. */
    public ?string $subEntityNumber = null;
    public ?UserEnterprise $userEnterprise = null;

    public static function fields(): array
    {
        return [
            'jointCommissionNumber' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0],
            'workerType' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0],
            'subEntityNumber' => ['type' => 'string', 'pattern' => '^[0-9]{1,10}$'],
            'userEnterprise' => ['ref' => UserEnterprise::class],
        ];
    }

    public static function required(): array
    {
        return ['workerType'];
    }
}
