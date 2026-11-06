<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class CostMediaSectionAllocationRepository extends BaseRepository
{
    protected string $table = 'cost_media_section_allocation';

    protected $allowedColumns = [
        'cost_month_id','media_type_id','section_id','allocation_percent','note'
    ];

    public function getByMonthId(int $costMonthId): array
    {
        $sql = "SELECT csa.*, cmt.name as media_name, cmt.code as media_code, ps.name as section_name
                FROM {$this->table} csa
                INNER JOIN cost_media_type cmt ON csa.media_type_id = cmt.id
                INNER JOIN production_sections ps ON csa.section_id = ps.id
                WHERE csa.cost_month_id = :mid
                ORDER BY cmt.name, ps.name";
        return $this->query($sql, ['mid' => $costMonthId]) ?: [];
    }

    public function findByKey(int $costMonthId, int $mediaTypeId, int $sectionId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE cost_month_id = :mid AND media_type_id = :mt AND section_id = :sid LIMIT 1";
        $rows = $this->query($sql, ['mid' => $costMonthId, 'mt' => $mediaTypeId, 'sid' => $sectionId]);
        return $rows[0] ?? [];
    }

    public function upsert(int $costMonthId, int $mediaTypeId, int $sectionId, float $percent, ?string $note = null): bool
    {
        $sql = "INSERT INTO {$this->table} (cost_month_id, media_type_id, section_id, allocation_percent, note)
                VALUES (:mid, :mt, :sid, :pct, :note)
                ON DUPLICATE KEY UPDATE allocation_percent = VALUES(allocation_percent), note = VALUES(note)";
        return (bool)$this->executeStatement($sql, [
            'mid' => $costMonthId,
            'mt' => $mediaTypeId,
            'sid' => $sectionId,
            'pct' => $percent,
            'note' => $note
        ]);
    }

    public function getSumByMonthAndMediaType(int $costMonthId, int $mediaTypeId, ?int $excludeId = null): float
    {
        $sql = "SELECT COALESCE(SUM(allocation_percent), 0) AS total
                FROM {$this->table}
                WHERE cost_month_id = :mid AND media_type_id = :mt";
        $params = ['mid' => $costMonthId, 'mt' => $mediaTypeId];
        if ($excludeId !== null) {
            $sql .= " AND id <> :eid";
            $params['eid'] = $excludeId;
        }
        $rows = $this->query($sql, $params);
        return isset($rows[0]['total']) ? (float)$rows[0]['total'] : 0.0;
    }

    /**
     * Get allocated media costs for a specific section in a given cost month
     * Returns array with media costs allocated to this section based on percentages
     */
    public function getAllocatedCostsForSection(int $costMonthId, int $sectionId): array
    {
        $sql = "SELECT
                    cmt.code,
                    cmt.name as media_name,
                    cmm.amount as total_cost,
                    csa.allocation_percent,
                    (cmm.amount * (csa.allocation_percent / 100)) as allocated_cost
                FROM {$this->table} csa
                INNER JOIN cost_media_month cmm
                    ON csa.cost_month_id = cmm.cost_month_id
                    AND csa.media_type_id = cmm.media_type_id
                INNER JOIN cost_media_type cmt ON csa.media_type_id = cmt.id
                WHERE csa.cost_month_id = :mid
                    AND csa.section_id = :sid
                    AND cmt.is_active = 1";

        return $this->query($sql, ['mid' => $costMonthId, 'sid' => $sectionId]) ?: [];
    }

    /**
     * Get total allocated media costs for a section in a given cost month
     * Returns single float value - sum of all allocated media costs
     */
    public function getTotalAllocatedCostForSection(int $costMonthId, int $sectionId): float
    {
        $sql = "SELECT
                    COALESCE(SUM(cmm.amount * (csa.allocation_percent / 100)), 0) as total
                FROM {$this->table} csa
                INNER JOIN cost_media_month cmm
                    ON csa.cost_month_id = cmm.cost_month_id
                    AND csa.media_type_id = cmm.media_type_id
                INNER JOIN cost_media_type cmt ON csa.media_type_id = cmt.id
                WHERE csa.cost_month_id = :mid
                    AND csa.section_id = :sid
                    AND cmt.is_active = 1";

        $result = $this->query($sql, ['mid' => $costMonthId, 'sid' => $sectionId]);
        return isset($result[0]['total']) ? (float)$result[0]['total'] : 0.0;
    }

    public function deleteByMonthId(int $monthId): bool
    {
        return (bool)$this->executeStatement(
            "DELETE FROM {$this->table} WHERE cost_month_id = :mid",
            ['mid' => $monthId]
        );
    }

    /**
     * Kopiuje alokacje mediów z miesiąca źródłowego do docelowego.
     */
    public function copyFromMonth(int $fromMonthId, int $toMonthId): bool
    {
        $rows = $this->getByMonthId($fromMonthId);
        if (empty($rows)) {
            return true;
        }

        foreach ($rows as $row) {
            $this->upsert(
                $toMonthId,
                (int)$row['media_type_id'],
                (int)$row['section_id'],
                (float)$row['allocation_percent'],
                isset($row['note']) ? (string)$row['note'] : null
            );
        }

        return true;
    }
}
