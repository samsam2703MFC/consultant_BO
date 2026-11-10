<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DeclarationSearchRequest » — docs/dimona/openapi_dimona_v2.yaml, ligne 1016.
 * Search parameters available when searching for a Dimona declaration
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DeclarationSearchRequest extends AbstractDto
{
    public const SCHEMA = 'DeclarationSearchRequest';

    public ?EmployerSearch $employer = null;
    public ?WorkerSearch $worker = null;
    /** Identification number of the Dimona period */
    public ?int $periodId = null;
    /** Number that uniquely identifies a daily registration */
    public ?int $dailyRegistrationId = null;
    public ?References $references = null;
    /** Start date used to filter the search */
    public ?string $fromCreationDate = null;
    /** End date used to filter the search */
    public ?string $toCreationDate = null;
    /** The channel used to declare a dimona */
    public ?array $channel = null;
    /** Type of Dimona declaration */
    public ?array $dimonaDeclarationType = null;
    /** The status of a Dimona declaration */
    public ?array $dimonaDeclarationStatus = null;

    public static function fields(): array
    {
        return [
            'employer' => ['ref' => EmployerSearch::class],
            'worker' => ['ref' => WorkerSearch::class],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'dailyRegistrationId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'references' => ['ref' => References::class],
            'fromCreationDate' => ['type' => 'string', 'format' => 'date'],
            'toCreationDate' => ['type' => 'string', 'format' => 'date'],
            'channel' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['DEF', 'FTP', 'MQL', 'ISA', 'WNS', 'WSE', 'WSM', 'VOC', 'SMS', 'PRN', 'MAI', 'FTP-C', 'ISA-C', 'MQL-C', 'DBI', 'WSXT', 'SFTP', 'MTOM', 'BATCH', 'MOB', 'WNSM', 'WRST']]],
            'dimonaDeclarationType' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['dimonaIn', 'dimonaOut', 'dimonaUpdate', 'dimonaCancel', 'dimonaDelete', 'dailyRegistrationIn', 'dailyRegistrationUpdate', 'dailyRegistrationCancel']]],
            'dimonaDeclarationStatus' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['accepted', 'blocking', 'warning', 'sigeDisPending', 'classic']]],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
