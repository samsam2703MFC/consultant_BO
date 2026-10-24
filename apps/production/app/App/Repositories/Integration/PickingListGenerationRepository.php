<?php
declare(strict_types=1);

namespace App\Repositories\Integration;

use App\Core\Helpers\BaseRepository;

final class PickingListGenerationRepository extends BaseRepository
{
    protected string $table = 'picking_list_generation';

    protected $allowedColumns = [
        'source', 'id_device', 'id_user', 'filename', 'pdf_sha256', 'order_count', 'product_count',
        'selected_order_ids_json', 'products_snapshot_json',
    ];

    /** @return list<array<string, mixed>> */
    public function getRecent(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        return $this->query(
            "SELECT g.*, d.name AS device_name, CONCAT(u.name, ' ', u.surname) AS user_name
             FROM picking_list_generation g
             LEFT JOIN internal_api_device d ON d.id = g.id_device
             LEFT JOIN user u ON u.id = g.id_user
             ORDER BY g.generated_at DESC, g.id DESC
             LIMIT {$limit}"
        ) ?: [];
    }

    /** @return array<string, mixed> */
    public function getById(int $id): array
    {
        $rows = $this->query(
            "SELECT g.*, d.name AS device_name, CONCAT(u.name, ' ', u.surname) AS user_name
             FROM picking_list_generation g
             LEFT JOIN internal_api_device d ON d.id = g.id_device
             LEFT JOIN user u ON u.id = g.id_user
             WHERE g.id = :id LIMIT 1",
            ['id' => $id]
        );
        return is_array($rows) && isset($rows[0]) ? $rows[0] : [];
    }
}
