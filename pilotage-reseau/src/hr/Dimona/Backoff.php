<?php

declare(strict_types=1);

namespace Hr\Dimona;

/**
 * Le rythme de relecture d'une déclaration envoyée (le POST ne rend pas le verdict, le GET rend 404 tant
 * qu'elle n'est pas traitée) : 5 s, 15 s, 30 s, 1 min, 5 min après chaque essai, puis toutes les 15 min, et
 * cela pendant 24 h après l'envoi. Au-delà : plus de relecture, la déclaration passe en ERROR.
 */
final class Backoff
{
    /** @var list<int> */
    public const STEPS = [5, 15, 30, 60, 300];
    public const THEN = 900;
    public const WINDOW = 86400;

    /** Le délai avant la relecture suivante, `attempts` relectures ayant déjà eu lieu. */
    public static function delay(int $attempts): int
    {
        return self::STEPS[max(0, $attempts)] ?? self::THEN;
    }

    /** L'instant de la prochaine relecture, ou null si la fenêtre de 24 h est dépassée. */
    public static function next(int $attempts, int $submittedAt, int $now): ?int
    {
        $at = $now + self::delay($attempts);
        return $at <= $submittedAt + self::WINDOW ? $at : null;
    }
}
