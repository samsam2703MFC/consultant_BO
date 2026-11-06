<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « HttpLink » — docs/dimona/openapi_dimona_v2.yaml, ligne 2520.
 * A base type of objects representing links to resources.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class HttpLink extends AbstractDto
{
    public const SCHEMA = 'HttpLink';

    /** Any absolute URI that is using http or https protocol */
    public ?string $href = null;

    public static function fields(): array
    {
        return [
            'href' => ['type' => 'string', 'format' => 'uri'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
