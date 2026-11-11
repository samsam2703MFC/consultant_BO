<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « EmployerSearch » — docs/dimona/openapi_dimona_v2.yaml, ligne 1507.
 * Block for employer search
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class EmployerSearch extends AbstractDto
{
    public const SCHEMA = 'EmployerSearch';

    /** Definitive or provisional NSSO number, assigned to each registered employer or local or provincial administration. */
    public ?int $employerId = null;
    /** Identifier issued by CBE for a registered organization */
    public ?string $enterpriseNumber = null;
    /** List of sectors to be included in the search. */
    public ?array $includedSectorIndicators = null;
    /** List of sectors to be excluded from the search. */
    public ?array $excludedSectorIndicators = null;

    public static function fields(): array
    {
        return [
            'employerId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 197, 'maximum' => 5999999999],
            'enterpriseNumber' => ['type' => 'string', 'pattern' => '^[0|1]?\\d{9}$'],
            'includedSectorIndicators' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['publicPla', 'publicFedReg', 'private']]],
            'excludedSectorIndicators' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['publicPla', 'publicFedReg', 'private']]],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
