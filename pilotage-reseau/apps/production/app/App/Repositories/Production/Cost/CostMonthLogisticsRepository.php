<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMonthLogisticsRepository extends BaseRepository
{
    protected string $table = 'cost_month_logistics';

    protected $allowedColumns = ['month_id', 'logistics_type_id', 'amount', 'note'];

    /**
     * Pobiera wszystkie koszty logistyczne dla danego miesiąca,
     * wzbogacone o dane z cost_logistics_type.
     */
    public function getByMonthId(int $monthId): array
    {
        $sql = "SELECT
                    cml.id,
                    cml.month_id,
                    cml.logistics_type_id,
                    clt.code          AS type_code,
                    clt.name          AS type_name,
                    cml.amount,
                    cml.note,
                    cml.created_at
                FROM {$this->table} cml
                INNER JOIN cost_logistics_type clt ON clt.id = cml.logistics_type_id
                WHERE cml.month_id = :month_id
                ORDER BY clt.sort_order ASC, cml.id ASC";
        return $this->query($sql, ['month_id' => $monthId]) ?: [];
    }

    /**
     * Suma kosztów logistycznych dla miesiąca.
     */
    public function getTotalByMonthId(int $monthId): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total
                FROM {$this->table}
                WHERE month_id = :month_id";
        $rows = $this->query($sql, ['month_id' => $monthId]);
        return (float)($rows[0]['total'] ?? 0.0);
    }

    /**
     * Kopiuje koszty logistyczne z jednego miesiąca do drugiego.
     */
    public function copyFromMonth(int $sourceMonthId, int $targetMonthId): bool
    {
        $rows = $this->getByMonthId($sourceMonthId);
        foreach ($rows as $row) {
            $this->insert([
                'month_id'           => $targetMonthId,
                'logistics_type_id'  => (int)$row['logistics_type_id'],
                'amount'             => (float)$row['amount'],
                'note'               => $row['note'],
            ]);
        }
        return true;
    }

    public function deleteByMonthId(int $monthId): bool
    {
        return (bool)$this->customDelete(
            "DELETE FROM {$this->table} WHERE month_id = :mid",
            ['mid' => $monthId]
        );
    }
}


