<?php
namespace App\Repositories\Settings;

use App\Core\Helpers\BaseRepository;

class LanguageRepository extends BaseRepository
{
    protected string $table = 'supported_language';

    protected $allowedColumns = [
        'code',
        'name',
        'is_active',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'code',
        'name',
        'is_active',
        'created_at',
    ];

    public function findActive(): array
    {
        return $this->query(
            "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY name"
        );
    }

    public function findByCode(string $code): array
    {
        $result = $this->query(
            "SELECT * FROM {$this->table} WHERE code = :code LIMIT 1",
            ['code' => strtolower($code)]
        );
        return is_array($result) && count($result) ? $result[0] : [];
    }
}
