<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMediaMonthRepository extends BaseRepository
{
    protected string $table = 'cost_media_month';

    protected $allowedColumns = [
        'cost_month_id','media_type_id','amount','note'
    ];

    public function getByMonthId($monthId): array
    {
        $sql = "SELECT cm.*, cmt.name as media_name, cmt.code as media_code, cmt.unit as media_unit
                FROM {$this->table} cm
                INNER JOIN cost_media_type cmt ON cm.media_type_id = cmt.id
                WHERE cm.cost_month_id = :monthId
                ORDER BY cmt.name";
        return $this->query($sql, ['monthId'=>$monthId]) ?: [];
    }

    public function findByMonthIdAndType($monthId, int $mediaTypeId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE cost_month_id = :y AND media_type_id = :mt LIMIT 1";
        $rows = $this->query($sql, ['y'=>$monthId,'mt'=>$mediaTypeId]);
        return $rows[0] ?? [];
    }

    public function upsertByMonthIdAndType(int $monthId, int $mediaTypeId, float $amount, ?string $note = null): int
    {
        $existing = $this->findByMonthIdAndType($monthId, $mediaTypeId);
        if (!empty($existing)) {
            $this->update((int)$existing['id'], [
                'amount' => $amount,
                'note' => $note,
            ]);
            return (int)$existing['id'];
        }

        $id = $this->insert([
            'cost_month_id' => $monthId,
            'media_type_id' => $mediaTypeId,
            'amount' => $amount,
            'note' => $note,
        ]);
        return (int)($id ?: 0);
    }

    public function deleteByMonthIdAndId($monthId, $id): bool
    {
        return $this->query("DELETE FROM {$this->table} WHERE cost_month_id = :cost_month_id AND media_type_id = :media_type_id", ['cost_month_id'=>$monthId,'media_type_id'=>$id]);
    }

    public function deleteByMonthId(int $monthId): bool
    {
        return (bool)$this->executeStatement(
            "DELETE FROM {$this->table} WHERE cost_month_id = :mid",
            ['mid' => $monthId]
        );
    }

    /**
     * Kopiuje koszty mediów z miesiąca źródłowego do docelowego.
     * Kwoty są kopiowane 1:1 (wartości historyczne – użytkownik może je potem edytować).
     */
    public function copyFromMonth(int $fromMonthId, int $toMonthId): bool
    {
        $rows = $this->getByMonthId($fromMonthId);
        if (empty($rows)) {
            return true;
        }

        foreach ($rows as $row) {
            $this->upsertByMonthIdAndType(
                $toMonthId,
                (int)$row['media_type_id'],
                (float)$row['amount'],
                isset($row['note']) ? (string)$row['note'] : null
            );
        }

        return true;
    }
}
