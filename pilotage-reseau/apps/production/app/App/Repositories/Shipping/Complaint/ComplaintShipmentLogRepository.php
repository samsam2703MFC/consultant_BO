<?php

namespace App\Repositories\Shipping\Complaint;
use App\Core\Helpers\BaseRepository;


/**
 * Order model class
 */
class ComplaintShipmentLogRepository extends BaseRepository
{

    protected string $table = 'complaint_shipping_log';

    protected $allowedColumns = [
        'shipment_date',
        'id_customer',
        'id_wz',
        'id_cmr',
        'id_employee'
    ];

    protected $fetchAllowedColumns = [
        'id',
        'shipment_date',
        'id_customer',
        'id_wz',
        'id_cmr',
        'customer_name'
    ];


    public function getAll()
    {
        $sql = "SELECT 
        csl.id,
        csl.shipment_date,
        csl.id_customer,
        IFNULL(cust.company, '') as customer_name,
        csl.id_wz,
        csl.id_cmr
        FROM complaint_shipping_log csl 
        LEFT JOIN customer cust
            ON csl.id_customer = cust.id
        ORDER BY csl.insert_timestamp DESC";

        return $this->query($sql);
    }



}