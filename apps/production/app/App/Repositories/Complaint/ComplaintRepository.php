<?php

namespace App\Repositories\Complaint;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ComplaintRepository extends BaseRepository
{

    protected string $table = 'complaint';

    protected $allowedColumns = [
        'complaint_uuid',
        'complaint_key',
        'complaint_type',
        'complaint_reason',
        'requested_action',
        'compensation_method',
        'id_customer',
        'id_order',
        'invoice_num',
        'id_product',
        'client_comment',
        'product_quantity',
        'accepted_quantity',
        'single_purchase_price',
        'acceptance_status',
        'production_response',
        'closed_on',
        'notified_at',
        'reported_at'
    ];


//getPendingById
    public function getById($id)
    {
        $sql = "SELECT 
                cpp.id,
                cust.company,
                cpp.id_customer,
                cpp.id_final_product,
                fp.name as product_name,
                cpp.pending_product_qty,
                fp.ppc,
                ROUND(cpp.pending_product_qty/fp.ppc) as expected_carton_qty
                FROM complaint_product_pending cpp
                INNER JOIN final_product fp
                    ON cpp.id_final_product = fp.id
                INNER JOIN customer cust
                    ON cpp.id_customer = cust.id
                WHERE cpp.id = :id
                ORDER BY cust.company, fp.name";
        $res = $this->query($sql, ['id' => $id]);
        if (!$res) return [];

        return $res[0];
    }

    public function validateInsert($data)
    {
        $this->errors = [];

        if ($data['single_purchase_price'] == 0) {
            $this->errors['single_purchase_price'] = "Ustal prawidłową cenę zakupu";
        }

        if ($data['product_quantity'] == 0) {
            $this->errors['product_quantity'] = "Wprowadź prawidłową ilość";
        }


        if (empty($this->errors)) {
            return true;
        }

        return false;
    }

    public function getAll()
    {
        $sql = "SELECT
                    c.id,
                    c.complaint_uuid,
                    c.complaint_reason,
                    c.complaint_type,
                    c.requested_action,
                    c.compensation_method,
                    c.id_customer,
                    c.id_order,
                    c.invoice_num,
                    c.id_product,
                    c.product_quantity,
                    c.accepted_quantity,
                    c.client_comment,
                    c.single_purchase_price,
                    c.acceptance_status,
                    cust.company as customer_name,
                    p.name as product_name,
                    c.acceptance_timestamp,
                    c.reported_at,
                    c.production_response,
                    wdt.established_date as invoice_date,
                    wdt.id as wdt_id
                FROM complaint c
                LEFT JOIN customer cust ON c.id_customer = cust.id
                LEFT JOIN final_product p ON c.id_product = p.id
                LEFT JOIN document_wdt wdt ON wdt.doc_num = c.invoice_num
                ORDER BY c.acceptance_timestamp DESC";
        return $this->query($sql);
    }

}
