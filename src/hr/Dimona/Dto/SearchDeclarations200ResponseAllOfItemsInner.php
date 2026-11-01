<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « searchDeclarations_200_response_allOf_items_inner » — docs/dimona/openapi_dimona_v2.yaml, ligne 2587.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class SearchDeclarations200ResponseAllOfItemsInner extends AbstractDto
{
    public const SCHEMA = 'searchDeclarations_200_response_allOf_items_inner';

    /** Any absolute URI that is using http or https protocol */
    public ?string $href = null;
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
            'href' => ['type' => 'string', 'format' => 'uri'],
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
