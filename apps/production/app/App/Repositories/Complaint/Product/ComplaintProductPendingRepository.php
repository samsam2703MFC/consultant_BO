<?php

namespace App\Repositories\Complaint\Product;

use App\Core\Helpers\BaseRepository;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Document\DocumentRepository;
use MYPDFModel;

/**
 * ComplaintProductPendingModel class
 */
class ComplaintProductPendingRepository extends BaseRepository
{

    protected string $table = 'complaint_product_pending';

    protected $allowedColumns = [
        'id_customer',
        'id_final_product',
        'pending_product_qty'
    ];

    public function getAll()
    {
        $sql = "SELECT 
                cpp.id,
                cust.company,
                cpp.id_customer,
                cpp.id_final_product,
                fp.name as product_name,
                cpp.pending_product_qty,
                fp.ppc
                FROM complaint_product_pending cpp
                INNER JOIN final_product fp
                    ON cpp.id_final_product = fp.id
                INNER JOIN customer cust
                    ON cpp.id_customer = cust.id
                WHERE pending_product_qty > 0
                ORDER BY cust.company, fp.name";
        return $this->query($sql);
    }

    public function insertProduct($data)
    {
        $sql = "INSERT INTO complaint_product_pending (id_customer, id_final_product, pending_product_qty)
                    VALUES (:id_customer, :id_final_product, :pending_product_qty)
                    ON DUPLICATE KEY UPDATE pending_product_qty = pending_product_qty + :pending_product_qty";
        return $this->query($sql, $data);
    }

    public function insertToSendLog($data)
    {
        $sql = "INSERT INTO complaint_product_to_send_log (
                                           id_customer,
                                           id_product, 
                                           carton_qty, 
                                           shipping_date) 
                SELECT 
                cpp.id_customer,
                cpp.id_final_product,
                :carton_qty,
                :shipping_date
                FROM complaint_product_pending cpp
                WHERE cpp.id = :id";
        return $this->customInsert($sql, $data);
    }

    public function reducePendingProducts($data)
    {
        $sql = "UPDATE complaint_product_pending cpp 
                INNER JOIN final_product fp 
                    ON cpp.id_final_product = fp.id
                SET cpp.pending_product_qty = IF(:carton_qty*fp.ppc > cpp.pending_product_qty, 0, (cpp.pending_product_qty-(:carton_qty*fp.ppc)))
                WHERE cpp.id = :id";
        return $this->customUpdate($sql, $data);
    }

    public function getUniqueShippingDate()
    {
        $sql = "SELECT DISTINCT shipping_date FROM complaint_product_to_send_log WHERE status = 0 ORDER BY shipping_date";
        return $this->query($sql, []);
    }



    public function getUniqueClientsForSelectedShippingDate($shippingDate)
    {
        $sql = "SELECT DISTINCT 
        c.id_customer,
        cust.company
        FROM complaint_product_to_send_log c 
        INNER JOIN customer cust 
            ON c.id_customer = cust.id
        WHERE shipping_date = :shipping_date AND status = 0";
        return $this->query($sql, ['shipping_date' => $shippingDate]);
    }

    public function getProductDataForSelectedShipingDateAndCustomer($data)
    {
        $sql = "SELECT 
        cp.id,
        cp.id_customer,
        cp.id_product,
        fp.name as product_name,
        SUM(cp.carton_qty) as sum,
        cp.status,
        cat.name as category_name
        FROM complaint_product_to_send_log cp
        INNER JOIN customer cust
            ON cp.id_customer = cust.id
        INNER JOIN final_product fp
            ON cp.id_product = fp.id
        INNER JOIN category_product cat
            ON fp.id_category = cat.id
        WHERE cp.shipping_date = :shipping_date AND cp.id_customer = :id_customer
        GROUP BY cp.id_product
        ORDER BY cat.name, fp.name";

        return $this->query($sql, $data);
    }


}