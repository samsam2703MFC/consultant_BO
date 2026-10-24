<?php

namespace App\Repositories\Stock\Complaint;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class CartonStockComplaintRepository extends BaseRepository
{


    protected string $table = 'carton_stock_complaint';

    protected $allowedColumns = [
        'id_carton',
        'shipping_date',
        'id_customer',
        'status'
    ];

    public function getCustomerByShippingDate($date)
    {
        $sql = "SELECT DISTINCT 
                    csc.id_customer,
                    cust.company
                FROM carton_stock_complaint csc 
                INNER JOIN customer cust 
                    ON csc.id_customer = cust.id
                WHERE status = 1 AND shipping_date = :shipping_date
                ORDER BY cust.company";
        return $this->query($sql, ['shipping_date' => $date]);
    }

    public function updateStatusByDateAndCustomer($shippingDate, $customerId, $status)
    {
        $sql = "UPDATE carton_stock_complaint 
                SET status = :status 
                WHERE shipping_date = :shipping_date AND id_customer = :id_customer";
        return $this->customUpdate($sql, [
            'status' => $status,
            'shipping_date' => $shippingDate,
            'id_customer' => $customerId
        ]);
    }
}