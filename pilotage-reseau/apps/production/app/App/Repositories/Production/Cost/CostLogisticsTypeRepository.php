<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostLogisticsTypeRepository extends BaseRepository
{
    protected string $table = 'cost_logistics_type';

    protected $allowedColumns = ['code', 'name', 'is_active', 'sort_order'];

    public function getActive(): array
    {
        $sql = "SELECT id, code, name, sort_order
                FROM {$this->table}
                WHERE is_active = 1
                ORDER BY sort_order ASC, name ASC";
        return $this->query($sql) ?: [];
    }

    public function getAll(): array
    {
        $sql = "SELECT id, code, name, is_active, sort_order
                FROM {$this->table}
                ORDER BY sort_order ASC, name ASC";
        return $this->query($sql) ?: [];
    }
}


