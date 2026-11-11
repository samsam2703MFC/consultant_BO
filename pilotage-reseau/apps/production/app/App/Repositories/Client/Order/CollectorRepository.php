<?php
namespace App\Repositories\Client\Order;

use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

/**
 * User class
 */
class CollectorRepository extends BaseRepository
{

    protected string $table = 'ordered_product_in_proforma_by_franchisees';

    protected $allowedColumns = [
        'id_order',
        'id_product',
        'quantity_carton',
        'expected_quantity_carton',
        'reason_of_missing',
        'purchase_price'
    ];

    protected $fetchAllowedColumns = [
        'id_order',
        'id_product',
        'quantity_carton',
        'expected_quantity_carton',
        'reason_of_missing',
        'purchase_price'
    ];

    public function insertFromOrder($data)
    {
        $sql = "INSERT INTO ordered_product_in_proforma_by_franchisees (`id_order`, `id_product`, `quantity_carton`, `expected_quantity_carton`, `purchase_price`)
                            SELECT 
                                   id, 
                                   :id_product, 
                                   :quantity_carton, 
                                   :expected_quantity_carton,
                                   :purchase_price FROM customer_order WHERE id_franchisee_collector = :id_order LIMIT 1";
        return $this->query($sql, [
            "id_order" => $data['id_order'],
            "id_product" => $data['ref_supplier'],
            "quantity_carton" => $data['quantity'],
            "expected_quantity_carton" => $data['quantity'],
            "purchase_price" => $data['purchase_price'],
        ]);
    }

}