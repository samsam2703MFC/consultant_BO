<?php

namespace App\Repositories\Client\Order;

use App\Core\Helpers\BaseRepository;

class OrderChangelogRepository extends BaseRepository
{
    protected string $table = 'customer_order_changelog';

    protected $allowedColumns = [
        'id_order',
        'id_user',
        'action_type',
        'source',
        'changed_fields',
    ];

    public function log(array $data): int|bool
    {
        return $this->insert($data);
    }

    public function getByOrderId(int $idOrder): array
    {
        $sql = "SELECT
                    coc.id,
                    coc.id_order,
                    coc.id_user,
                    coc.action_type,
                    coc.source,
                    coc.changed_fields,
                    coc.created_at,
                    u.name          AS user_name,
                    u.surname       AS user_surname,
                    u.displayed_name
                FROM {$this->table} coc
                LEFT JOIN user u ON u.id = coc.id_user
                WHERE coc.id_order = :id_order
                ORDER BY coc.created_at ASC";

        return $this->query($sql, ['id_order' => $idOrder]) ?: [];
    }
}
