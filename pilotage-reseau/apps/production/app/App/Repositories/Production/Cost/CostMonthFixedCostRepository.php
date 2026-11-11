<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMonthFixedCostRepository extends BaseRepository
{
    protected string $table = 'cost_month_fixed_cost';

    protected $allowedColumns = [
        'month_id','name','amount','category','service_month','posting_month'
    ];

    public function getByMonthId(int $monthId): array
    {
        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE month_id = :mid
            ORDER BY id DESC
        ";
        return $this->query($sql, ['mid'=>$monthId]) ?: [];
    }

    public function deleteByMonthId(int $monthId): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE month_id = :mid";
        return (bool)$this->executeStatement($sql, ['mid'=>$monthId]);
    }

    public function copyFromMonth(int $fromMonthId, int $toMonthId): bool
    {
        $sql = "
            INSERT INTO {$this->table} (month_id, name, amount, category, service_month, posting_month)
            SELECT :toMid, name, amount, category, service_month, posting_month
            FROM {$this->table}
            WHERE month_id = :fromMid
        ";
        return (bool)$this->executeStatement($sql, ['toMid'=>$toMonthId,'fromMid'=>$fromMonthId]);
    }
}
