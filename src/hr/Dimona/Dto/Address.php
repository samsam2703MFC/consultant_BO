<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Address » — docs/dimona/openapi_dimona_v2.yaml, ligne 577.
 * Address
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Address extends AbstractDto
{
    public const SCHEMA = 'Address';

    public ?string $street = null;
    public ?string $houseNumber = null;
    public ?string $boxNumber = null;
    public ?string $postCode = null;
    public ?Municipality $municipality = null;
    /** NIS code representing a country as defined by statbel.fgov.be */
    public ?int $country = null;

    public static function fields(): array
    {
        return [
            'street' => ['type' => 'string'],
            'houseNumber' => ['type' => 'string'],
            'boxNumber' => ['type' => 'string'],
            'postCode' => ['type' => 'string'],
            'municipality' => ['ref' => Municipality::class],
            'country' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 999],
        ];
    }

    public static function required(): array
    {
        return ['houseNumber', 'municipality', 'postCode', 'street'];
    }
}
