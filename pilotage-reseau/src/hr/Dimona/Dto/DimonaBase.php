<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DimonaBase » — docs/dimona/openapi_dimona_v2.yaml, ligne 1193.
 * The base object for all different Dimona types.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DimonaBase extends AbstractDto
{
    public const SCHEMA = 'DimonaBase';

    public ?Employer $employer = null;
    public ?WorkerDeclaration $worker = null;
    public ?DeclarationStatus $declarationStatus = null;

    public static function fields(): array
    {
        return [
            'employer' => ['ref' => Employer::class],
            'worker' => ['ref' => WorkerDeclaration::class],
            'declarationStatus' => ['ref' => DeclarationStatus::class],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
