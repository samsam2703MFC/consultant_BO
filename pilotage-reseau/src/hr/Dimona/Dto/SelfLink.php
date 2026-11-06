<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « SelfLink » — docs/dimona/openapi_dimona_v2.yaml, ligne 2559.
 * A base type representing a link to the resource's own location within its representation
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class SelfLink extends AbstractDto
{
    public const SCHEMA = 'SelfLink';

    /** Any absolute URI that is using http or https protocol */
    public ?string $self = null;

    public static function fields(): array
    {
        return [
            'self' => ['type' => 'string', 'format' => 'uri'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
