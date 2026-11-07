<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « WorkerDeclaration » — docs/dimona/openapi_dimona_v2.yaml, ligne 2306.
 * Block for declaring worker identification data. Required for a Dimona IN. To be able to declare a
 * worker, you must complete either the SSIN or the familyName,    the givenName, the birthDate, the
 * placeOfBirth, the countryOfBirth, the nationality, the gender and the address.    When retrieving a
 * declaration, following data will be displayed: familyName, givenName, birthDate, nationality and
 * gender.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class WorkerDeclaration extends AbstractDto
{
    public const SCHEMA = 'WorkerDeclaration';

    /** Social Security Identification Number issued by the National Register or CBSS */
    public ?string $ssin = null;
    /** The last name of the worker */
    public ?string $familyName = null;
    /** The first name of the worker */
    public ?string $givenName = null;
    /** The worker first name and any other given names */
    public ?string $givenNames = null;
    /** The initials of the worker */
    public ?string $initial = null;
    /** The birthday of the worker */
    public ?string $birthDate = null;
    /** The place (municipality) of birth of the worker */
    public ?string $placeOfBirth = null;
    /** NIS code representing a country as defined by statbel.fgov.be */
    public ?int $countryOfBirth = null;
    /** NIS code representing a country as defined by statbel.fgov.be */
    public ?int $nationality = null;
    public ?int $gender = null;
    public ?Address $address = null;

    public static function fields(): array
    {
        return [
            'ssin' => ['type' => 'string', 'pattern' => '^\\d{11}$'],
            'familyName' => ['type' => 'string', 'maxLength' => 128, 'minLength' => 0],
            'givenName' => ['type' => 'string', 'maxLength' => 48, 'minLength' => 0],
            'givenNames' => ['type' => 'string'],
            'initial' => ['type' => 'string', 'maxLength' => 1, 'minLength' => 0],
            'birthDate' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-1][0-9]-[0-3][0-9]$'],
            'placeOfBirth' => ['type' => 'string', 'maxLength' => 40, 'minLength' => 0],
            'countryOfBirth' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 999],
            'nationality' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 999],
            'gender' => ['type' => 'integer', 'enum' => [0, 1, 2], 'alias' => 'GenderCode'],
            'address' => ['ref' => Address::class],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
