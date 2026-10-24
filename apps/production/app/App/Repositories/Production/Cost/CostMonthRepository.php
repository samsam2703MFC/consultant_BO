<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMonthRepository extends BaseRepository
{
    protected string $table = 'cost_month';

    protected $allowedColumns = [
        'year','month','status'
    ];

    /**
     * Get the latest cost month (by year-month desc)
     * Useful as default when no specific month is selected
     */
    public function getLatest(): array
    {
        $sql = "SELECT * FROM {$this->table} ORDER BY year DESC, month DESC LIMIT 1";
        $result = $this->query($sql);
        return $result[0] ?? [];
    }

    /**
     * Get cost month by year and month
     */
    public function getByYearMonth(int $year, int $month): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE year = :y AND month = :m LIMIT 1";
        $result = $this->query($sql, ['y' => $year, 'm' => $month]);
        return $result[0] ?? [];
    }

    /**
     * List all cost months for dropdown/selection
     */
    public function listAll(int $limit = 24): array
    {
        $sql = "SELECT id, year, month, status 
                FROM {$this->table} 
                ORDER BY year DESC, month DESC 
                LIMIT :lim";
        return $this->query($sql, ['lim' => $limit]) ?: [];
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";
        $res = $this->query($sql, ['id'=>$id]);
        return !empty($res) ? $res[0] : null;
    }

    public function listLast(int $limit = 24): array
    {
        $limit = max(1, min(120, (int)$limit));
        $sql = "SELECT * FROM {$this->table} ORDER BY year DESC, month DESC LIMIT {$limit}";
        return $this->query($sql) ?: [];
    }

    public function lockMonth(int $id): bool
    {
        return (bool)$this->customUpdate(
            "UPDATE {$this->table} SET status = 'LOCKED' WHERE id = :id",
            ['id'=>$id]
        );
    }
}
