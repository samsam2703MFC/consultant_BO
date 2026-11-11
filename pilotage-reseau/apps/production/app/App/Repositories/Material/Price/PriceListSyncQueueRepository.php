<?php
namespace App\Repositories\Material\Price;
use App\Core\Helpers\BaseRepository;
use DateTimeImmutable;

/**
 * User class
 */
class PriceListSyncQueueRepository extends BaseRepository
{
    protected string $table = 'raw_material_price_list_sync_queue';
    protected $allowedColumns = [
        'id_client',
        'due_at',
        'attempts',
        'last_error',
        'updated_at',
    ];

    public function schedulePricelistSync(int $idClient, int $delaySec = 50)
    {
        $dueAt = (new DateTimeImmutable("+{$delaySec} seconds"))->format('Y-m-d H:i:s');
        $sql = "INSERT INTO {$this->table} (id_client, due_at) 
                VALUES (:id_client, :due_at)
                ON DUPLICATE KEY UPDATE due_at = VALUES(due_at), attempts = 0, last_error = NULL";
        return $this->query($sql, [
            'id_client' => $idClient,
            'due_at' => $dueAt,
        ]);
    }

    public function getPending($limit)
    {
        $sql = "SELECT 
                    id_client 
                FROM raw_material_price_list_sync_queue 
                WHERE due_at <= NOW()
                ORDER BY due_at ASC LIMIT $limit";
        return $this->query($sql);
    }

    public function increaseAttempts($idClient)
    {
        $sql = "UPDATE 
                    raw_material_price_list_sync_queue 
                SET attempts = attempts+1, 
                    due_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE) 
                                            WHERE id_client = :id_client";
        return $this->query($sql, ['id_client' => $idClient]);
    }
}