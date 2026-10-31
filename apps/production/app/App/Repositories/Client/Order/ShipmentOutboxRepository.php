<?php

namespace App\Repositories\Client\Order;

use App\Core\Helpers\BaseRepository;
use PDOException;

class ShipmentOutboxRepository extends BaseRepository
{
    protected string $table = 'webhook_outbox_shipments';

    protected $allowedColumns = [
        'id_order',
        'shop_uid',
        'url',
        'event_id',
        'payload_json',
        'status',
        'attempts',
        'next_try',
        'last_error',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'id_order',
        'shop_uid',
        'url',
        'event_id',
        'payload_json',
        'status',
        'attempts',
        'next_try',
        'last_error',
        'created_at',
        'updated_at'
    ];

    public function enqueue(array $row): bool
    {
        $sql = "INSERT IGNORE INTO {$this->table}
                (id_order, shop_uid, url, event_id, payload_json, status, attempts, next_try, created_at, updated_at)
                VALUES (:id_order, :shop_uid, :url, :event_id, :payload_json, 'pending', 0, NOW(), NOW(), NOW())";
        return $this->query($sql, $row);
    }

    /** zwróć paczkę „do ponowienia” */
    public function fetchDue(int $limit = 50): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE status='pending' AND next_try <= NOW()
                ORDER BY next_try ASC
                LIMIT $limit";
        return $this->query($sql);
    }

    /** atomowe zclaimowanie rekordu, żeby uniknąć dubli w wielu workerach */
    public function claim(int $id): bool
    {
        $sql = "UPDATE {$this->table}
                SET status='processing', updated_at=NOW()
                WHERE id=:id AND status='pending'";
        return $this->query($sql, ['id' => $id]) === 1;
    }

    public function markSent(int $id): void
    {
        $this->query(
            "UPDATE {$this->table} SET status='sent', updated_at=NOW() WHERE id=:id",
            ['id' => $id]
        );
    }

    public function postpone(int $id, int $attempts, string $error, int $seconds): void
    {
        $this->query(
            "UPDATE {$this->table}
             SET attempts=:a, next_try=DATE_ADD(NOW(), INTERVAL :sec SECOND),
                 last_error=:e, status='pending', updated_at=NOW()
             WHERE id=:id",
            ['a' => $attempts, 'sec' => $seconds, 'e' => mb_strimwidth($error, 0, 480), 'id' => $id]
        );
    }

    public function failPermanently(int $id, string $error): void
    {
        $this->query(
            "UPDATE {$this->table}
             SET status='failed', last_error=:e, updated_at=NOW()
             WHERE id=:id",
            ['e' => mb_strimwidth($error, 0, 480), 'id' => $id]
        );
    }
}
