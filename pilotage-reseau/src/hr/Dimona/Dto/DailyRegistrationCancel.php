<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DailyRegistrationCancel » — docs/dimona/openapi_dimona_v2.yaml, ligne 718.
 * Block containing the information necessary for a daily registration cancel
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DailyRegistrationCancel extends AbstractDto
{
    public const SCHEMA = 'DailyRegistrationCancel';

    public ?References $references = null;
    /** Number that uniquely identifies a daily registration */
    public ?int $dailyRegistrationId = null;

    public static function fields(): array
    {
        return [
            'references' => ['ref' => References::class],
            'dailyRegistrationId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
        ];
    }

    public static function required(): array
    {
        return ['dailyRegistrationId'];
    }
}
