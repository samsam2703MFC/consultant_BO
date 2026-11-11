<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « UserEnterpriseSearch » — docs/dimona/openapi_dimona_v2.yaml, ligne 2234.
 * Block for identification data of the employing company where the person concerned is employed as a
 * temporary worker
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class UserEnterpriseSearch extends AbstractDto
{
    public const SCHEMA = 'UserEnterpriseSearch';

    /** Joint commission number. Watch the administration instructions at: https://www.socialsecurity.be/employer/instructions/dmfa/fr/latest/instructions/obligations/o */
    public ?string $jointCommissionNumber = null;
    /** The name of the company where an interim works */
    public ?string $name = null;
    /** Identifier issued by CBE for a registered organization */
    public ?string $enterpriseNumber = null;

    public static function fields(): array
    {
        return [
            'jointCommissionNumber' => ['type' => 'string', 'maxLength' => 3, 'minLength' => 0],
            'name' => ['type' => 'string'],
            'enterpriseNumber' => ['type' => 'string', 'pattern' => '^[0|1]?\\d{9}$'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
