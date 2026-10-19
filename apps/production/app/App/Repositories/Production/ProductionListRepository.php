<?php
namespace App\Repositories\Production;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ProductionListRepository extends BaseRepository
{

    protected string $table = '';

    protected $allowedColumns = [

    ];

    protected $fetchAllowedColumns = [

    ];

    public function createTemporaryTable()
    {
        $sql = "CREATE TEMPORARY TABLE production_list_tmp (
                id_product INT PRIMARY KEY,
                name VARCHAR(255),
                ppc INT,
                stock_minimum INT,
                id_section INT,
                in_stock INT,
                date_1 INT,
                date_2 INT,
                date_3 INT,
                date_4 INT,
                complaint INT,
                id_recipe INT
                )";
        return $this->executeStatement($sql);
    }

    public function fillTemporaryTableWithoutOrder()
    {
        $sql = "INSERT INTO production_list_tmp (id_product, name, ppc, stock_minimum, id_section, in_stock, date_1, date_2, date_3, date_4, complaint, id_recipe)
                        SELECT 
                        fp.id,
                        fp.name,
                        fp.ppc,
                        fp.stock_minimum,
                        fp.id_section,
                        0,
                        0,
                        0,
                        0,
                        0,
                        IFNULL(cp.carton_qty, 0),
                        IFNULL(fp.id_recipe, 0)
                    FROM final_product fp
                    LEFT JOIN (SELECT id_product, SUM(carton_qty) as carton_qty FROM complaint_product_to_send_log WHERE status = 0 GROUP BY id_product) cp
                    ON fp.id = cp.id_product";
        return $this->customInsert($sql, []);
    }

    public function fetchFromTemporaryByIdSectionWithoutOrder($idSection)
    {
        $sql = "SELECT 
                        id_product,
                        name,
                        ppc,
                        stock_minimum as stock_minimum_carton,
                        (stock_minimum*ppc) as stock_minimum_pcs,
                        (in_stock*ppc) as in_stock_pcs,
                        in_stock as in_stock_carton,
                        date_1 as day_1_carton,
                        (date_1*ppc) as day_1_pcs,
                        date_2 as day_2_carton,
                        (date_2*ppc) as day_2_pcs,
                        date_3 as day_3_carton,
                        (date_3*ppc) as day_3_pcs,
                        date_4 as day_4_carton,
                        (date_4*ppc) as day_4_pcs,
                        complaint as complaint_carton,
                        (complaint*ppc) as complaint_pcs,
                        id_recipe
                    FROM production_list_tmp
                    WHERE (in_stock - stock_minimum) < 0 AND id_section = :id_section ORDER BY name";
        return $this->query($sql, ["id_section" => $idSection]);
    }

    public function fetchFromTemporaryByIdSectionWithOrder($idSection, $condition)
    {
        $sql = "SELECT 
                        id_product,
                        name,
                        ppc,
                        stock_minimum as stock_minimum_carton,
                        (stock_minimum*ppc) as stock_minimum_pcs,
                        (in_stock*ppc) as in_stock_pcs,
                        in_stock as in_stock_carton,
                        date_1 as day_1_carton,
                        (date_1*ppc) as day_1_pcs,
                        date_2 as day_2_carton,
                        (date_2*ppc) as day_2_pcs,
                        date_3 as day_3_carton,
                        (date_3*ppc) as day_3_pcs,
                        date_4 as day_4_carton,
                        (date_4*ppc) as day_4_pcs,
                        complaint as complaint_carton,
                        (complaint*ppc) as complaint_pcs,
                        id_recipe
                    FROM production_list_tmp
                    WHERE $condition > 0 AND id_section = :id_section ORDER BY name";
        return $this->query($sql, ["id_section" => $idSection]);
    }

    public function fillTemporaryTableWithOrder($data, $orderIdStr)
    {
        $sql = "INSERT INTO production_list_tmp (id_product, name, ppc, stock_minimum, id_section, in_stock, date_1, date_2, date_3, date_4, complaint, id_recipe)
                        SELECT 
                        fp.id,
                        fp.name,
                        fp.ppc,
                        fp.stock_minimum,
                        fp.id_section,
                        IFNULL(cs.in_stock_qty,0),
                        IFNULL(day_1.qty_carton,0),
                        IFNULL(day_2.qty_carton,0),
                        IFNULL(day_3.qty_carton,0),
                        IFNULL(day_4.qty_carton,0),
                        IFNULL(cp.carton_qty, 0),
                        IFNULL(fp.id_recipe, 0)
                    FROM final_product fp
                    LEFT JOIN (SELECT id_product, COUNT(id_product) as in_stock_qty FROM carton_stock cs WHERE cs.status = 1 GROUP BY id_product) cs
                        ON fp.id = cs.id_product
                    LEFT JOIN (
                                SELECT cop.id_product, SUM(cop.quantity_carton) as qty_carton 
                                FROM customer_order_product cop 
                                    INNER JOIN customer_order co 
                                        ON cop.id_order = co.id 
                                WHERE DATE(co.expected_shipping_date) = '{$data['formated_shipping_date_1']}' AND cop.id_order IN ($orderIdStr) 
                                GROUP BY cop.id_product) day_1
                        ON fp.id = day_1.id_product
                    LEFT JOIN (
                                SELECT cop.id_product, SUM(cop.quantity_carton) as qty_carton 
                                FROM customer_order_product cop 
                                    INNER JOIN customer_order co 
                                        ON cop.id_order = co.id 
                                WHERE DATE(co.expected_shipping_date) = '{$data['formated_shipping_date_2']}' AND cop.id_order IN ($orderIdStr) 
                                GROUP BY cop.id_product) day_2
                        ON fp.id = day_2.id_product
                    LEFT JOIN (
                                SELECT cop.id_product, SUM(cop.quantity_carton) as qty_carton 
                                FROM customer_order_product cop 
                                    INNER JOIN customer_order co 
                                        ON cop.id_order = co.id 
                                WHERE DATE(co.expected_shipping_date) = '{$data['formated_shipping_date_3']}' AND cop.id_order IN ($orderIdStr) 
                                GROUP BY cop.id_product) day_3
                        ON fp.id = day_3.id_product
                    LEFT JOIN (
                                SELECT cop.id_product, SUM(cop.quantity_carton) as qty_carton 
                                FROM customer_order_product cop 
                                    INNER JOIN customer_order co 
                                        ON cop.id_order = co.id 
                                WHERE DATE(co.expected_shipping_date) = '{$data['formated_shipping_date_4']}' AND cop.id_order IN ($orderIdStr) 
                                GROUP BY cop.id_product) day_4
                        ON fp.id = day_4.id_product
                    LEFT JOIN (SELECT id_product, SUM(carton_qty) as carton_qty FROM complaint_product_to_send_log WHERE status = 0 GROUP BY id_product) cp
                        ON fp.id = cp.id_product";
        return $this->customInsert($sql, []);
    }


}