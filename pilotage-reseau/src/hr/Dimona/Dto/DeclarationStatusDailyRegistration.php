<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DeclarationStatus_dailyRegistration » — docs/dimona/openapi_dimona_v2.yaml, ligne 2656.
 * Identification of the Daily Registration
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DeclarationStatusDailyRegistration extends AbstractDto
{
    public const SCHEMA = 'DeclarationStatus_dailyRegistration';

    /** Any absolute URI that is using http or https protocol */
    public ?string $href = null;
    /** Unique identification number of the Daily Registration Declaration */
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
