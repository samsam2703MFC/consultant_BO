<?php
declare(strict_types=1);

namespace App\Services\Integration;

use App\Repositories\Integration\InternalApiDeviceRepository;
use Symfony\Component\Uid\Uuid;

final class InternalApiDeviceService
{
    public function __construct(private InternalApiDeviceRepository $devices)
    {
    }

    /** @return array{device: array<string, mixed>, api_key: string} */
    public function create(array $data, ?int $userId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Nazwa urządzenia jest wymagana.');
        }

        $apiKey = bin2hex(random_bytes(32));
        $id = $this->devices->insert([
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'device_id' => Uuid::v4()->toRfc4122(),
            'api_key_hash' => password_hash($apiKey, PASSWORD_DEFAULT),
            'is_active' => 1,
            'created_by_user_id' => $userId,
        ]);

        return ['device' => $this->devices->first(['id' => (int) $id]), 'api_key' => $apiKey];
    }

    /** @return array{device: array<string, mixed>, api_key: string} */
    public function rotateKey(int $id): array
    {
        $device = $this->devices->first(['id' => $id]);
        if ($device === []) {
            throw new \InvalidArgumentException('Nie znaleziono urządzenia.');
        }
        $apiKey = bin2hex(random_bytes(32));
        $this->devices->update($id, ['api_key_hash' => password_hash($apiKey, PASSWORD_DEFAULT)]);
        return ['device' => $this->devices->first(['id' => $id]), 'api_key' => $apiKey];
    }

    public function toggle(int $id): void
    {
        $device = $this->devices->first(['id' => $id]);
        if ($device === []) {
            throw new \InvalidArgumentException('Nie znaleziono urządzenia.');
        }
        $this->devices->update($id, ['is_active' => !(bool) $device['is_active']]);
    }

    /** @return list<array<string, mixed>> */
    public function getAll(): array
    {
        return $this->devices->getAllForManagement();
    }
}
