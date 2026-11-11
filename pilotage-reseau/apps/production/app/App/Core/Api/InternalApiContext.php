<?php
declare(strict_types=1);

namespace App\Core\Api;

/** Request-local authenticated device context for internal API handlers. */
final class InternalApiContext
{
    /** @var array<string, mixed>|null */
    private static ?array $device = null;

    /** @param array<string, mixed> $device */
    public static function setDevice(array $device): void
    {
        self::$device = $device;
    }

    /** @return array<string, mixed> */
    public static function device(): array
    {
        if (self::$device === null) {
            throw new \RuntimeException('INTERNAL_API_DEVICE_CONTEXT_MISSING');
        }

        return self::$device;
    }
}
