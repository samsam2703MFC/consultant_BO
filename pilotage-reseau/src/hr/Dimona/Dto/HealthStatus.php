<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « HealthStatus » — docs/dimona/openapi_dimona_v2.yaml, ligne 2401.
 * Response message for the API health
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class HealthStatus extends AbstractDto
{
    public const SCHEMA = 'HealthStatus';

    /** Level indicating the health status of the service: UP (functioning as expected), DOWN (suffering unexpected failures), DEGRADED (partly unavailable but service  */
    public ?string $status = null;

    public static function fields(): array
    {
        return [
            'status' => ['type' => 'string'],
        ];
    }

    public static function required(): array
    {
        return ['status'];
    }
}
