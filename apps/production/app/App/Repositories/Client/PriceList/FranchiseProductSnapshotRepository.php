<?php

namespace App\Repositories\Client\PriceList;

use App\Core\Helpers\BaseRepository;

class FranchiseProductSnapshotRepository extends BaseRepository
{
    protected string $table = 'franchise_product_sync_snapshot';

    protected $allowedColumns = [
        'id_customer',
        'franchisee_uid',
        'requested_date',
        'status',
        'http_code',
        'error_code',
        'error_message',
        'duration_ms',
        'items_count',
        'active_items_count',
        'inactive_items_count',
        'content_hash',
    ];

    public function latestSuccessfulForClient(int $clientId): array
    {
        $rows = $this->query(
            "SELECT *
             FROM {$this->table}
             WHERE id_customer = :id_customer AND status = 'success'
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            ['id_customer' => $clientId]
        );

        return $rows[0] ?? [];
    }

    public function latestAttemptForClient(int $clientId): array
    {
        $rows = $this->query(
            "SELECT *
             FROM {$this->table}
             WHERE id_customer = :id_customer
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            ['id_customer' => $clientId]
        );

        return $rows[0] ?? [];
    }
}
