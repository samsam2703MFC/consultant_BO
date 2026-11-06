<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DeclarationStatus_period » — docs/dimona/openapi_dimona_v2.yaml, ligne 2643.
 * Identification of the Dimona period
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DeclarationStatusPeriod extends AbstractDto
{
    public const SCHEMA = 'DeclarationStatus_period';

    /** Any absolute URI that is using http or https protocol */
    public ?string $href = null;
    /** Identification number of the Dimona period */
    public ?int $id = null;

    public static function fields(): array
    {
        return [
            'href' => ['type' => 'string', 'format' => 'uri'],
            'id' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
