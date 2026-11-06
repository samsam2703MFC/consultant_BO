<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « LocalizedString » — docs/dimona/openapi_dimona_v2.yaml, ligne 2534.
 * A description specified in multiple languages
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class LocalizedString extends AbstractDto
{
    public const SCHEMA = 'LocalizedString';

    public ?string $nl = null;
    public ?string $fr = null;
    public ?string $de = null;

    public static function fields(): array
    {
        return [
            'nl' => ['type' => 'string'],
            'fr' => ['type' => 'string'],
            'de' => ['type' => 'string'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
