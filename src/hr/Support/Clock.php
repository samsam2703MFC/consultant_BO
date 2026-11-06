<?php

declare(strict_types=1);

namespace Hr\Support;

/** L'heure du module : l'horloge du système, figeable en test. Les dates en base sont en UTC. */
final class Clock
{
    private static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }

    public static function freeze(?int $at): void
    {
        self::$fixed = $at;
    }

    public static function advance(int $seconds): void
    {
        self::$fixed = self::now() + $seconds;
    }

    /** Horodatage SQL en UTC. */
    public static function sql(?int $at = null): string
    {
        return gmdate('Y-m-d H:i:s', $at ?? self::now());
    }

    /** Horodatage SQL (UTC) → ISO 8601, pour le navigateur. */
    public static function iso(?string $sql): ?string
    {
        if ($sql === null || $sql === '') {
            return null;
        }
        $t = strtotime($sql . ' UTC');
        return $t === false ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    public static function ts(?string $sql): ?int
    {
        if ($sql === null || $sql === '') {
            return null;
        }
        $t = strtotime($sql . ' UTC');
        return $t === false ? null : $t;
    }
}
