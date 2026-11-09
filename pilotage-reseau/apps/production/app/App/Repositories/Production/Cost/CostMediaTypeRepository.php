<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMediaTypeRepository extends BaseRepository
{
    protected string $table = 'cost_media_type';

    protected $allowedColumns = [
        'code','name','unit','is_active'
    ];

    public function getAll(): array
    {
        $sql = "SELECT * FROM {$this->table} ORDER BY name";
        return $this->query($sql) ?: [];
    }

    public function getActive(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY name";
        return $this->query($sql) ?: [];
    }

    public function getById(int $id): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";
        $rows = $this->query($sql, ['id' => $id]);
        return $rows[0] ?? [];
    }
}

