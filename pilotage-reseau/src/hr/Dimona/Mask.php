<?php

declare(strict_types=1);

namespace Hr\Dimona;

/** Le NISS (clé `ssin`, partout où le YAML la place) masqué dans une copie lisible d'une requête ou réponse. */
final class Mask
{
    public const SSIN = '••••••.•••.••';

    public static function payload(mixed $v): mixed
    {
        if (is_object($v)) {
            $v = json_decode((string) json_encode($v), true);
        }
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = ($k === 'ssin' && is_string($x) && $x !== '') ? self::SSIN : self::payload($x);
        }
        return $out;
    }

    /** NISS valide : 11 chiffres et le contrôle modulo 97 (le « 2 » devant pour les naissances depuis 2000). */
    public static function ssinValid(string $ssin): bool
    {
        $n = preg_replace('/\D/', '', $ssin) ?? '';
        if (strlen($n) !== 11) {
            return false;
        }
        $base = substr($n, 0, 9);
        $ctrl = (int) substr($n, 9, 2);
        return 97 - ((int) $base % 97) === $ctrl || 97 - ((int) ('2' . $base) % 97) === $ctrl;
    }

    /** « 85.07.30-033.28 » */
    public static function ssinFormat(string $ssin): string
    {
        $n = preg_replace('/\D/', '', $ssin) ?? '';
        return strlen($n) === 11
            ? substr($n, 0, 2) . '.' . substr($n, 2, 2) . '.' . substr($n, 4, 2) . '-' . substr($n, 6, 3) . '.' . substr($n, 9, 2)
            : $ssin;
    }
}
