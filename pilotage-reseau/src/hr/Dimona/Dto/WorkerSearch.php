<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « WorkerSearch » — docs/dimona/openapi_dimona_v2.yaml, ligne 2371.
 * Block for worker search
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class WorkerSearch extends AbstractDto
{
    public const SCHEMA = 'WorkerSearch';

    /** Social Security Identification Number issued by the National Register or CBSS */
    public ?string $ssin = null;
    /** The last name of the worker */
    public ?string $familyName = null;
    /** The first name of the worker */
    public ?string $givenName = null;

    public static function fields(): array
    {
        return [
            'ssin' => ['type' => 'string', 'pattern' => '^\\d{11}$'],
            'familyName' => ['type' => 'string'],
            'givenName' => ['type' => 'string'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
