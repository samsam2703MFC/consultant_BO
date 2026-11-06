<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « StudentPlaceOfWork » — docs/dimona/openapi_dimona_v2.yaml, ligne 2164.
 * Block for declaring information about the workplace of a student. Mandatory if it is not the
 * company's head office
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class StudentPlaceOfWork extends AbstractDto
{
    public const SCHEMA = 'StudentPlaceOfWork';

    /** Name of the company or agency of the student's workplace */
    public ?string $name = null;
    public ?Address $address = null;

    public static function fields(): array
    {
        return [
            'name' => ['type' => 'string'],
            'address' => ['ref' => Address::class],
        ];
    }

    public static function required(): array
    {
        return ['address', 'name'];
    }
}
