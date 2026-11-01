<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DeclarationStatus » — docs/dimona/openapi_dimona_v2.yaml, ligne 1146.
 * Block containing the data relating to the declaration processing. When retrieving a declaration this
 * is filled up by the service.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DeclarationStatus extends AbstractDto
{
    public const SCHEMA = 'DeclarationStatus';

    /** Identification number of the Dimona declaration */
    public ?int $declarationId = null;
    /** Result of the processing of the declaration. (A - accepted, W - accepted with warning, B - refused and S - Waiting because an identification request sent to SIG */
    public ?string $result = null;
    public ?DeclarationStatusPeriod $period = null;
    public ?DeclarationStatusDailyRegistration $dailyRegistration = null;
    /** List of anomalies */
    public ?array $anomalies = null;
    /** List of additional information */
    public ?array $informationsCollection = null;

    public static function fields(): array
    {
        return [
            'declarationId' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
            'result' => ['type' => 'string'],
            'period' => ['ref' => DeclarationStatusPeriod::class],
            'dailyRegistration' => ['ref' => DeclarationStatusDailyRegistration::class],
            'anomalies' => ['type' => 'array', 'items' => ['ref' => Anomaly::class]],
            'informationsCollection' => ['type' => 'array', 'items' => ['ref' => Information::class]],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
