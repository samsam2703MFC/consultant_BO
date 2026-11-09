<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Municipality » — docs/dimona/openapi_dimona_v2.yaml, ligne 1646.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Municipality extends AbstractDto
{
    public const SCHEMA = 'Municipality';

    public ?string $name = null;
    /** Numeric code to identify a Belgian municipality */
    public ?int $code = null;

    public static function fields(): array
    {
        return [
            'name' => ['type' => 'string'],
            'code' => ['type' => 'integer', 'minimum' => 10000, 'maximum' => 99999],
        ];
    }

    public static function required(): array
    {
        return ['name'];
    }
}
