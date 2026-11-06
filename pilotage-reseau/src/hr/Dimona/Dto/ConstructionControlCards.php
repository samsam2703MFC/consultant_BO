<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « ConstructionControlCards » — docs/dimona/openapi_dimona_v2.yaml, ligne 634.
 * Block for declaring C3.2A-construction control card data
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class ConstructionControlCards extends AbstractDto
{
    public const SCHEMA = 'ConstructionControlCards';

    /** Control card number C3.2A assigned to the worker for the beginning of the Dimona period */
    public ?string $firstMonthC32aNumber = null;
    /** Control card number C3.2A assigned to the worker for the month following the month of the beginning of his Dimona period */
    public ?string $nextMonthC32aNumber = null;

    public static function fields(): array
    {
        return [
            'firstMonthC32aNumber' => ['type' => 'string'],
            'nextMonthC32aNumber' => ['type' => 'string'],
        ];
    }

    public static function required(): array
    {
        return ['firstMonthC32aNumber', 'nextMonthC32aNumber'];
    }
}
