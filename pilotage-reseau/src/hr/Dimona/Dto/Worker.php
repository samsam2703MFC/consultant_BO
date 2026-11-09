<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Worker » — docs/dimona/openapi_dimona_v2.yaml, ligne 2259.
 * Block for worker data
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Worker extends AbstractDto
{
    public const SCHEMA = 'Worker';

    /** Social Security Identification Number issued by the National Register or CBSS */
    public ?string $ssin = null;
    /** The last name of the worker */
    public ?string $familyName = null;
    /** The first name of the worker */
    public ?string $givenName = null;
    /** The worker first name and any other given names */
    public ?string $givenNames = null;
    /** The birthday of the worker */
    public ?string $birthDate = null;
    /** NIS code representing a country as defined by statbel.fgov.be */
    public ?int $nationality = null;
    public ?int $gender = null;

    public static function fields(): array
    {
        return [
            'ssin' => ['type' => 'string', 'pattern' => '^\\d{11}$'],
            'familyName' => ['type' => 'string', 'maxLength' => 128, 'minLength' => 0],
            'givenName' => ['type' => 'string', 'maxLength' => 48, 'minLength' => 0],
            'givenNames' => ['type' => 'string'],
            'birthDate' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-1][0-9]-[0-3][0-9]$'],
            'nationality' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 999],
            'gender' => ['type' => 'integer', 'enum' => [0, 1, 2], 'alias' => 'GenderCode'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
