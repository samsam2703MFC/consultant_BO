<?php

namespace App\Repositories\Complaint\Product;
use App\Core\Helpers\BaseRepository;

/**
 * ComplaintProductPendingModel class
 */
class ComplaintProductToSendLogRepository extends BaseRepository
{

    protected string $table = 'complaint_product_to_send_log';

    protected $allowedColumns = [
        'id',
        'id_customer',
        'id_product',
        'carton_qty',
        'status',
        'shipping_date',
        'insert_timestamp'
    ];


    public function getUnsent()
    {
        $sql = "SELECT 
        cp.id,
        cp.id_customer,
        cp.id_product,
        cp.carton_qty,
        fp.name as product_name,
        cp.shipping_date,
        cust.company as customer_name
        FROM complaint_product_to_send_log cp
        INNER JOIN final_product fp
            ON cp.id_product = fp.id
        INNER JOIN customer cust
            ON cp.id_customer = cust.id
        WHERE cp.status = 0 OR cp.status = 1
        ORDER BY fp.name";
        return $this->query($sql);
    }
}