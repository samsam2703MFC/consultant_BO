<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Information » — docs/dimona/openapi_dimona_v2.yaml, ligne 1596.
 * Block containing the details of an additional information
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Information extends AbstractDto
{
    public const SCHEMA = 'Information';

    /** Type of additional information */
    public ?string $type = null;
    /** Value of additional information */
    public ?string $value = null;

    public static function fields(): array
    {
        return [
            'type' => ['type' => 'string'],
            'value' => ['type' => 'string'],
        ];
    }

    public static function required(): array
    {
        return ['type', 'value'];
    }
}
