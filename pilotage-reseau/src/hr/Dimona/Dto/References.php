<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « References » — docs/dimona/openapi_dimona_v2.yaml, ligne 2020.
 * Optional block to provide various declaration references, filled in by the person responsible for
 * creating the declaration.Useful for referencing declarations internally.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class References extends AbstractDto
{
    public const SCHEMA = 'References';

    /** Declaration Reference */
    public ?string $declaration = null;
    /** To specify an employer reference */
    public ?string $employer = null;
    /** To specify a worker reference */
    public ?string $worker = null;

    public static function fields(): array
    {
        return [
            'declaration' => ['type' => 'string', 'maxLength' => 20, 'minLength' => 0],
            'employer' => ['type' => 'string', 'maxLength' => 20, 'minLength' => 0],
            'worker' => ['type' => 'string', 'maxLength' => 20, 'minLength' => 0],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
