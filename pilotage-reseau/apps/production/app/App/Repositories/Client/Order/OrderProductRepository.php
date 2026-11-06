<?php

namespace App\Repositories\Client\Order;
use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

/**
 * User class
 */
class OrderProductRepository extends BaseRepository
{

    protected string $table = 'customer_order_product';

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

    public function insertForOrderWithStandardPrice($idOrder, $products)
    {
        if(!empty($products)){
            foreach($products as $product){
                $sql = "INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) 
                        SELECT :insertedId, id, :quantity, :exp_quantity, standard_price
                        FROM final_product WHERE id = :id_product";

                $res = $this->customInsert($sql, [
                    "insertedId" => $idOrder,
                    "quantity" => $product['quantity'],
                    "exp_quantity" => $product['quantity'],
                    "id_product" => $product['id_product']
                ]);
                if(!$res){
                    return false;
                }
            }
        }

        return true;
    }

    public function insertForOrderWithClientPrice($idOrder, $idClient, $products)
    {
        if(!empty($products)){
            foreach($products as $product){
                $sql = "INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) 
                        SELECT :insertedId, fp.id, :quantity, :exp_quantity, IFNULL(fpcp.price, fp.standard_price)
                        FROM final_product_customer_price fpcp
                        RIGHT JOIN final_product fp
                            ON fpcp.id_final_product = fp.id
                        WHERE fp.id = :id_product AND fpcp.id_customer = :id_customer";

                $res = $this->customInsert($sql, [
                    "insertedId" => $idOrder,
                    "quantity" => $product['quantity'],
                    "exp_quantity" => $product['quantity'],
                    "id_product" => $product['id_product'],
                    "id_customer" => $idClient
                ]);
                if(!$res){
                    return false;
                }
            }
        }

        return true;
    }


    public function getById($id)
    {
        $sql = "SELECT 
                    cop.id_product,
                    fp.name,
                    cop.quantity_carton,
                    cop.expected_quantity_carton,
                    cop.purchase_price
                FROM customer_order_product cop
                INNER JOIN final_product fp
                ON cop.id_product = fp.id
                WHERE cop.id_order = :id_order
                ORDER BY fp.name";
        return $this->query($sql, ['id_order' => $id]);
    }

    public function getOrderDataForPDF($idOrder)
    {
        $sql = "SELECT
                    cat.name as category_name,
                    fp.name as product_name,
                    cop.quantity_carton as sum
                FROM customer_order_product cop
                INNER JOIN final_product fp
                	ON cop.id_product = fp.id
                INNER JOIN category_product cat
                	ON fp.id_category = cat.id
                WHERE cop.id_order = :id_order
                GROUP BY cop.id_product
                ORDER BY cat.name, fp.name";
        return $this->query($sql, ['id_order' => $idOrder]);
    }

    public function getDataToProforma($data)
    {
        $sql = "SELECT
                    fp.id as id_product,
                    fp.name as product_name,
                    cop.quantity_carton,
                    cop.purchase_price,
                    TRUNCATE((cop.quantity_carton * cop.purchase_price),2) as total_carton_price,
                    TRUNCATE(fp.weight/1000, 2) as single_weight,
                    fp.ppc,
                    TRUNCATE(((fp.ppc*fp.weight)*cop.quantity_carton)/1000, 2) as sum_weight,
                    DATE(NOW()) as proforma_date
                FROM customer_order_product cop 
                INNER JOIN final_product fp
                    ON cop.id_product = fp.id
                WHERE cop.id_order = :id_order
                ORDER BY fp.name";
        return $this->query($sql, $data);
    }

    public function insertFromCollector($data)
    {
        $sql = "INSERT INTO customer_order_product (`id_order`, `id_product`, `quantity_carton`, `expected_quantity_carton`, `purchase_price`)
                            SELECT 
                                   id, 
                                   :id_product, 
                                   :quantity_carton, 
                                   :expected_quantity_carton,
                                   :purchase_price FROM customer_order WHERE id_franchisee_collector = :id_order LIMIT 1";
        return $this->customInsert($sql, [
            "id_order" => $data['id_order'],
            "id_product" => $data['ref_supplier'],
            "quantity_carton" => $data['quantity'],
            "expected_quantity_carton" => $data['quantity'],
            "purchase_price" => $data['purchase_price'],
        ]);
    }

}