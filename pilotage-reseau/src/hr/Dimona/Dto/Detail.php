<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Detail » — docs/dimona/openapi_dimona_v2.yaml, ligne 1176.
 * Generic block which provides additional details about a parent block
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Detail extends AbstractDto
{
    public const SCHEMA = 'Detail';

    /** Provided details category */
    public mixed $kind = null;
    /** Message details */
    public mixed $message = null;
    /** Any specific references if applicable */
    public mixed $ref = null;
    /** Any values if applicable */
    public mixed $value = null;

    public static function fields(): array
    {
        return [
            'kind' => ['type' => 'mixed'],
            'message' => ['type' => 'mixed'],
            'ref' => ['type' => 'mixed'],
            'value' => ['type' => 'mixed'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
