<?php

namespace App\Repositories\Material\Allergen;

use App\Core\Helpers\BaseRepository;

class AllergenRepository extends BaseRepository
{
    protected string $table = 'allergen';

    protected $allowedColumns = [
        'code',
        'name',
    ];

    public function getAll(): array
    {
        $sql = "SELECT `id`, `code` FROM `allergen` ORDER BY `code`";

        return $this->query($sql) ?: [];
    }

    public function getForMaterial(int $materialId): array
    {
        $sql = "SELECT a.`id`, a.`code`
                FROM `material_allergen_connection` mac
                INNER JOIN `allergen` a
                    ON a.`id` = mac.`allergen_id`
                WHERE mac.`material_id` = :material_id
                ORDER BY a.`code`";

        return $this->query($sql, ['material_id' => $materialId]) ?: [];
    }

    public function getAllergenIdsForMaterial(int $materialId): array
    {
        $sql = "SELECT mac.`allergen_id`
                FROM `material_allergen_connection` mac
                WHERE mac.`material_id` = :material_id";

        $rows = $this->query($sql, ['material_id' => $materialId]) ?: [];
        return array_values(array_map(static fn(array $r) => (int)$r['allergen_id'], $rows));
    }

    public function materialExists(int $materialId): bool
    {
        $sql = "SELECT `id` FROM `raw_material` WHERE `id` = :id LIMIT 1";
        $rows = $this->query($sql, ['id' => $materialId]) ?: [];

        return !empty($rows);
    }

    /**
     * Pełna synchronizacja połączeń (replace): usuwa wszystkie i wstawia aktualną listę.
     */
    public function replaceMaterialConnections(int $materialId, array $allergenIds): bool
    {
        $allergenIds = array_values(array_unique(array_map('intval', $allergenIds)));

        $delSql = "DELETE FROM `material_allergen_connection` WHERE `material_id` = :material_id";
        $delRes = $this->query($delSql, ['material_id' => $materialId]);
        if ($delRes === false) {
            return false;
        }

        if (count($allergenIds) === 0) {
            return true;
        }

        $insSql = "INSERT INTO `material_allergen_connection` (`material_id`, `allergen_id`) VALUES (:material_id, :allergen_id)";
        foreach ($allergenIds as $aid) {
            $res = $this->query($insSql, ['material_id' => $materialId, 'allergen_id' => $aid]);
            if ($res === false) {
                return false;
            }
        }

        return true;
    }
}

