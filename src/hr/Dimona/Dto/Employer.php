<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Employer » — docs/dimona/openapi_dimona_v2.yaml, ligne 1487.
 * Block for declaring employer identification data. Required for a Dimona IN. When retrieving a
 * declaration this is filled up by the service. You need to fill at least employerId or
 * enterpriseNumber.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Employer extends AbstractDto
{
    public const SCHEMA = 'Employer';

    /** Definitive or provisional NSSO number, assigned to each registered employer or local or provincial administration. */
    public ?int $employerId = null;
    /** Identifier issued by CBE for a registered organization */
    public ?string $enterpriseNumber = null;

    public static function fields(): array
    {
        return [
            'employerId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 197, 'maximum' => 5999999999],
            'enterpriseNumber' => ['type' => 'string', 'pattern' => '^[0|1]?\\d{9}$'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
