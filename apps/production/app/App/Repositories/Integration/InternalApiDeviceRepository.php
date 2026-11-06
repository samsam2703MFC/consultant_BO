<?php
declare(strict_types=1);

namespace App\Repositories\Integration;

use App\Core\Helpers\BaseRepository;

final class InternalApiDeviceRepository extends BaseRepository
{
    protected string $table = 'internal_api_device';

    protected $allowedColumns = [
        'name', 'description', 'device_id', 'api_key_hash', 'is_active', 'created_by_user_id',
    ];

    /** @return array<string, mixed> */
    public function findByDeviceId(string $deviceId): array
    {
        return $this->first(['device_id' => $deviceId]);
    }

    /** @return list<array<string, mixed>> */
    public function getAllForManagement(): array
    {
        return $this->query(
            'SELECT d.*, CONCAT(u.name, " ", u.surname) AS created_by_name
             FROM internal_api_device d
             LEFT JOIN user u ON u.id = d.created_by_user_id
             ORDER BY d.created_at DESC, d.id DESC'
        ) ?: [];
    }

    public function touchLastUsed(int $id): void
    {
        $this->customUpdate('UPDATE internal_api_device SET last_used_at = NOW() WHERE id = :id', ['id' => $id]);
    }
}
