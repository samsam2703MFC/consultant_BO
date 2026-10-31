<?php

namespace App\Repositories\Client\PriceList;

use App\Core\Helpers\BaseRepository;

class FranchiseProductCatalogRepository extends BaseRepository
{
    protected string $table = 'franchise_product_sync_snapshot_item';

    public function currentItemsForAllFranchisees(): array
    {
        // MAX(id) identifies the last persisted successful snapshot per customer.
        $sql = "SELECT
                    item.id,
                    item.sku,
                    item.product_name,
                    item.price_net,
                    item.package_size,
                    item.package_unit,
                    item.is_active,
                    supplier_product.active AS supplier_is_active,
                    item.valid_from,
                    item.diagnostic_status,
                    item.id_customer,
                    snapshot.created_at AS snapshot_created_at,
                    customer.company AS customer_company
                FROM franchise_product_sync_snapshot_item item
                INNER JOIN franchise_product_sync_snapshot snapshot ON snapshot.id = item.snapshot_id
                INNER JOIN (
                    SELECT id_customer, MAX(id) AS id
                    FROM franchise_product_sync_snapshot
                    WHERE status = 'success'
                    GROUP BY id_customer
                ) latest_snapshot ON latest_snapshot.id = snapshot.id
                INNER JOIN customer ON customer.id = item.id_customer
                LEFT JOIN final_product supplier_product
                    ON item.sku REGEXP '^[0-9]+$'
                    AND supplier_product.id = CAST(item.sku AS UNSIGNED)
                ORDER BY item.sku ASC, customer.company ASC";

        return $this->query($sql) ?: [];
    }
}
