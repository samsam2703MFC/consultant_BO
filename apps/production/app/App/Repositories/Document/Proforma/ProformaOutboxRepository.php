<?php

namespace App\Repositories\Document\Proforma;

use App\Models\ApiCURL;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ProformaOutboxRepository extends BaseRepository
{
    protected string $table = 'proforma_outbox';
    protected $allowedColumns = [
        'shop_uid',
        'order_key',
        'proforma_uuid',
        'file_path',
        'status',
        'retry_count',
        'last_error',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'shop_uid',
        'order_key',
        'proforma_uuid',
        'file_path',
        'status',
        'retry_count',
        'last_error',
        'created_at',
        'updated_at'
    ];

    public $errors = [];

    public function insertOnDuplicate($data)
    {
        $sql = "INSERT INTO `proforma_outbox` (
                    `shop_uid`, 
                    `order_key`, 
                    `proforma_uuid`, 
                    `status`, 
                    `retry_count`, 
                    `last_error`
                ) VALUES (
                    :shop_uid, 
                    :order_key, 
                    :proforma_uuid, 
                    :status, 
                    0, 
                    NULL
                )
                ON DUPLICATE KEY UPDATE
                    `status`      = 'PENDING',
                    `retry_count` = 0,
                    `last_error`  = NULL,
                    `updated_at`  = NOW();";
        return $this->query($sql, $data);
    }

    public function getPendingForShop(string $shopUid, int $limit=50): array {
        $limit = (int)max(1, min($limit, 200));
        $sql = "SELECT * FROM {$this->table} WHERE shop_uid=:u AND status='pending' ORDER BY id LIMIT $limit";
        return $this->query($sql, ['u'=>$shopUid]);
    }

    public function markDone(array $ids): int {
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("UPDATE {$this->table} SET status='done' WHERE id IN ($in)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    public function markFailed(array $ids, string $error): int {
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE {$this->table}
           SET status='failed', retry_count=retry_count+1, last_error=?, updated_at=NOW()
         WHERE id IN ($in)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$error], $ids));
        return $stmt->rowCount();
    }
}