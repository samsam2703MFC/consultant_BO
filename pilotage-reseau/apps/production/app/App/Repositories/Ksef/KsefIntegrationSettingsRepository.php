<?php

namespace App\Repositories\Ksef;

use App\Core\Helpers\BaseRepository;

final class KsefIntegrationSettingsRepository extends BaseRepository
{
    protected string $table = 'ksef_integration_settings';
    protected $allowedColumns = ['id', 'environment', 'token_encrypted', 'last_connection_check_at', 'last_connection_check_message'];

    public function get(): array
    {
        return $this->first(['id' => 1]);
    }

    public function save(array $data): bool
    {
        if ($this->get() === []) {
            return (bool) $this->insert(['id' => 1] + $data);
        }

        return $this->update(1, $data);
    }
}
