<?php

declare(strict_types=1);

namespace Hr\Dimona;

/**
 * Le statut d'une déclaration dans le module. Le code ONSS vient de declarationStatus.result — YAML,
 * schéma DeclarationStatus (ligne 1155) : « A - accepted, W - accepted with warning, B - refused and
 * S - Waiting because an identification request sent to SIGeDIS ».
 */
final class StatusMapper
{
    public const PENDING = 'PENDING';
    public const ACCEPTED = 'ACCEPTED';
    public const WARNING = 'WARNING';
    public const REFUSED = 'REFUSED';
    public const WAITING_SIGEDIS = 'WAITING_SIGEDIS';
    public const ERROR = 'ERROR';

    public const RESULTS = ['A' => self::ACCEPTED, 'W' => self::WARNING, 'B' => self::REFUSED, 'S' => self::WAITING_SIGEDIS];

    /** Le statut du module pour un code ONSS ; null si le code est absent ou inconnu. */
    public static function fromResult(?string $code): ?string
    {
        return self::RESULTS[strtoupper(trim((string) $code))] ?? null;
    }

    /** Une déclaration finie ne se relit plus d'elle-même (S reste suivie : SIGeDIS peut encore répondre). */
    public static function isFinal(string $status): bool
    {
        return in_array($status, [self::ACCEPTED, self::WARNING, self::REFUSED, self::ERROR], true);
    }

    /** Une période Dimona existe à l'ONSS après ce statut. */
    public static function createsPeriod(string $status): bool
    {
        return $status === self::ACCEPTED || $status === self::WARNING;
    }
}
