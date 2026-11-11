<?php

namespace App\Repositories\Client\PriceList;

use App\Core\Helpers\BaseRepository;

class FranchiseProductSnapshotItemRepository extends BaseRepository
{
    protected string $table = 'franchise_product_sync_snapshot_item';

    protected $allowedColumns = [
        'snapshot_id',
        'id_customer',
        'sku',
        'id_product_external',
        'product_name',
        'price_net',
        'vat_rate',
        'package_size',
        'package_unit',
        'is_active',
        'valid_from',
        'diagnostic_status',
        'raw_payload',
    ];

    public function bySnapshot(int $snapshotId): array
    {
        return $this->query(
            "SELECT *
             FROM {$this->table}
             WHERE snapshot_id = :snapshot_id
             ORDER BY diagnostic_status <> 'ok' DESC, product_name ASC, sku ASC",
            ['snapshot_id' => $snapshotId]
        ) ?: [];
    }
}
