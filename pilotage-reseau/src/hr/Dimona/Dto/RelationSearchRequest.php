<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « RelationSearchRequest » — docs/dimona/openapi_dimona_v2.yaml, ligne 2093.
 * Block containing the different search parameters available for relation search. The employer or/and
 * the worker block have to be provided
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class RelationSearchRequest extends AbstractDto
{
    public const SCHEMA = 'RelationSearchRequest';

    public ?EmployerSearch $employer = null;
    public ?WorkerSearch $worker = null;
    /** Search for active periods starting from this date */
    public ?string $activeFromDate = null;
    /** Search for active periods up to this date */
    public ?string $activeToDate = null;

    public static function fields(): array
    {
        return [
            'employer' => ['ref' => EmployerSearch::class],
            'worker' => ['ref' => WorkerSearch::class],
            'activeFromDate' => ['type' => 'string', 'format' => 'date'],
            'activeToDate' => ['type' => 'string', 'format' => 'date'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
