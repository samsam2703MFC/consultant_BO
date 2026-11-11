<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DimonaOut » — docs/dimona/openapi_dimona_v2.yaml, ligne 1383.
 * Block containing the information needed for a Dimona declaration of end of the employer-worker
 * relationship
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DimonaOut extends AbstractDto
{
    public const SCHEMA = 'DimonaOut';

    public ?References $references = null;
    /** Number that uniquely identifies a Dimona period. This number is automatically assigned to the Dimona IN declaration that generates the period */
    public ?int $periodId = null;
    /** End date of the Dimona period */
    public ?string $endDate = null;

    public static function fields(): array
    {
        return [
            'references' => ['ref' => References::class],
            'periodId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'endDate' => ['type' => 'string', 'format' => 'date'],
        ];
    }

    public static function required(): array
    {
        return ['endDate', 'periodId'];
    }
}
