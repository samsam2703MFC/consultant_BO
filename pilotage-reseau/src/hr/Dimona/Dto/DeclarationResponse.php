<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « DeclarationResponse » — docs/dimona/openapi_dimona_v2.yaml, ligne 978.
 * Details of a Dimona Declaration
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class DeclarationResponse extends AbstractDto
{
    public const SCHEMA = 'DeclarationResponse';

    public ?Employer $employer = null;
    public ?WorkerDeclaration $worker = null;
    public ?DeclarationStatus $declarationStatus = null;
    public ?DimonaIn $dimonaIn = null;
    public ?DimonaOut $dimonaOut = null;
    public ?DimonaUpdate $dimonaUpdate = null;
    public ?DimonaCancel $dimonaCancel = null;
    public ?DailyRegistrationIn $dailyRegistrationIn = null;
    public ?DailyRegistrationUpdate $dailyRegistrationUpdate = null;
    public ?DailyRegistrationCancel $dailyRegistrationCancel = null;

    public static function fields(): array
    {
        return [
            'employer' => ['ref' => Employer::class],
            'worker' => ['ref' => WorkerDeclaration::class],
            'declarationStatus' => ['ref' => DeclarationStatus::class],
            'dimonaIn' => ['ref' => DimonaIn::class],
            'dimonaOut' => ['ref' => DimonaOut::class],
            'dimonaUpdate' => ['ref' => DimonaUpdate::class],
            'dimonaCancel' => ['ref' => DimonaCancel::class],
            'dailyRegistrationIn' => ['ref' => DailyRegistrationIn::class],
            'dailyRegistrationUpdate' => ['ref' => DailyRegistrationUpdate::class],
            'dailyRegistrationCancel' => ['ref' => DailyRegistrationCancel::class],
        ];
    }

    public static function required(): array
    {
        return [];
    }
}
