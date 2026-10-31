<?php
declare(strict_types=1);

namespace App\Services\Api;

use App\Core\Api\InternalApiAuthenticationException;
use App\Repositories\Integration\InternalApiDeviceRepository;

final class InternalDeviceAuthenticator
{
    public function __construct(private InternalApiDeviceRepository $devices)
    {
    }

    /** @param array<string, mixed> $server @return array<string, mixed> */
    public function authenticate(array $server): array
    {
        $deviceId = trim((string) ($server['HTTP_X_DEVICE_ID'] ?? ''));
        $apiKey = (string) ($server['HTTP_X_API_KEY'] ?? '');
        if ($deviceId === '' || $apiKey === '') {
            throw new InternalApiAuthenticationException('MISSING_DEVICE_CREDENTIALS', 401);
        }

        $device = $this->devices->findByDeviceId($deviceId);
        if ($device === [] || !password_verify($apiKey, (string) $device['api_key_hash'])) {
            throw new InternalApiAuthenticationException('INVALID_DEVICE_CREDENTIALS', 401);
        }
        if (!(bool) $device['is_active']) {
            throw new InternalApiAuthenticationException('DEVICE_DISABLED', 403);
        }

        $this->devices->touchLastUsed((int) $device['id']);
        return $device;
    }
}
