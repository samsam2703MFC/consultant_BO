<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DimonaCancel » — docs/dimona/openapi_dimona_v2.yaml, ligne 1203.
 * Block containing the information necessary for a declaration Dimona cancellation of the relationship
 * between an employer and a worker
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DimonaCancel extends AbstractDto
{
    public const SCHEMA = 'DimonaCancel';

    public ?References $references = null;
    /** Number that uniquely identifies a Dimona period. This number is automatically assigned to the Dimona IN declaration that generates the period */
    public ?int $periodId = null;

    public static function fields(): array
    {
        return [
            'references' => ['ref' => References::class],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
        ];
    }

    public static function required(): array
    {
        return ['periodId'];
    }
}
