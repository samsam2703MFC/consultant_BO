<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « Anomaly » — docs/dimona/openapi_dimona_v2.yaml, ligne 610.
 * Block containing the details of an anomaly
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Anomaly extends AbstractDto
{
    public const SCHEMA = 'Anomaly';

    /** Identifier of the anomaly - See Annex 30 of the Glossary (https://www.socialsecurity.be/lambda/portail/glossaires/dimona.nsf/web/glossary_home_fr) */
    public ?string $errorId = null;
    public ?LocalizedString $label = null;
    /** Type of additional information regarding the anomaly */
    public ?string $type = null;
    /** The value of the type parameter */
    public ?string $value = null;

    public static function fields(): array
    {
        return [
            'errorId' => ['type' => 'string'],
            'label' => ['ref' => LocalizedString::class],
            'type' => ['type' => 'string'],
            'value' => ['type' => 'string'],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
