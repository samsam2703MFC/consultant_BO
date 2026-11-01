<?php

declare(strict_types=1);

namespace Hr\Dimona;

/**
 * La commission paritaire telle que la Dimona l'attend.
 *
 * Instructions administratives ONSS, « Dimona : données principales » (lien du YAML, DimonaFeatures
 * .jointCommissionNumber) : seuls 3 chiffres se renseignent, même s'il existe des sous-commissions ; la
 * mention des commissions 124, 140, 144, 145, 149, 302, 320 et 322 est indispensable ; « les autres
 * commissions paritaires peuvent être renseignées sous la valeur xxx ». L'exemple Dimona-In de la doc
 * fonctionnelle v2 (§ 7.1.1, p. 20) envoie "XXX". Une boulangerie en CP 118 ou 119 se déclare donc « XXX ».
 */
final class JointCommission
{
    /** @var list<string> */
    public const EXPLICIT = ['124', '140', '144', '145', '149', '302', '320', '322'];
    public const OTHER = 'XXX';

    public static function forDimona(?string $cp): string
    {
        $digits = preg_replace('/\D/', '', (string) $cp) ?? '';
        $three = substr($digits, 0, 3);
        return in_array($three, self::EXPLICIT, true) ? $three : self::OTHER;
    }

    /** « 118.03 », « 119 », « 302 » : ce qu'un franchisé saisit — jusqu'à 3 chiffres, un point, 2 chiffres. */
    public static function isPlausible(?string $cp): bool
    {
        return (bool) preg_match('/^\d{3}(\.\d{1,2})?$/', trim((string) $cp));
    }
}
