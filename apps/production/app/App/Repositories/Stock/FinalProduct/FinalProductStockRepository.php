<?php
namespace App\Repositories\Stock\FinalProduct;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class FinalProductStockRepository extends BaseRepository
{

    protected string $table = 'carton_stock';

    protected $allowedColumns = [

    ];

    protected $fetchAllowedColumns = [

    ];

    public function getCategoryProductInStock()
    {
        $sql = "SELECT DISTINCT
                        cat.id,
                        cat.name
                    FROM final_product pd
                    INNER JOIN category_product cat
                    	ON pd.id_category = cat.id                  
                        INNER JOIN (SELECT DISTINCT id_product FROM slfood_stock.carton_stock WHERE status = 1 OR status = 2) prods
                    ON pd.id = prods.id_product
                    ORDER BY cat.name";
        return $this->query($sql);
    }

    public function getStockState()
    {
        $sql = "SELECT 
                    x.id_prod,
                    x.in_stock,
                    x.palletised,
                    x.name,
                    (x.in_stock + x.palletised) as total_in_stock
                    FROM 
                    (
                    SELECT 
                    cs.id_product as id_prod,
                    (SELECT COUNT(id_product) FROM carton_stock WHERE status = 1 AND id_product = id_prod) as in_stock,
                    (SELECT COUNT(id_product) FROM carton_stock WHERE status = 2 AND id_product = id_prod) as palletised,
                    final_product.name 
                    FROM carton_stock cs 
                    INNER JOIN final_product 
                    ON cs.id_product = final_product.id 
                    GROUP BY cs.id_product 
                    ORDER BY final_product.name)x
                    WHERE x.in_stock > 0 OR x.palletised > 0;";
        return $this->query($sql);
    }

    public function getStockStateByCategory($idCategory)
    {
        $sql = "SELECT 
                    x.id_prod,
                    x.in_stock,
                    x.palletised,
                    x.name,
                    (x.in_stock + x.palletised) as total_in_stock
                    FROM
                    (
                        SELECT 
                    cs.id_product as id_prod,
                    (SELECT COUNT(id_product) FROM slfood_stock.carton_stock WHERE status = 1 AND id_product = id_prod) as in_stock,
                    (SELECT COUNT(id_product) FROM slfood_stock.carton_stock WHERE status = 2 AND id_product = id_prod) as palletised,
                    final_product.name 
                    FROM slfood_stock.carton_stock cs 
                    INNER JOIN final_product 
                    ON cs.id_product = final_product.id 
                    WHERE final_product.id_category = :category
                    GROUP BY cs.id_product 
                    ORDER BY final_product.name)x
                    WHERE x.in_stock > 0 OR x.palletised > 0;";
        return $this->query($sql, ['category' => $idCategory]);
    }

    public function getPendingOrderOptions(): array
    {
        $sql = "SELECT
                    co.id,
                    co.reference,
                    co.expected_shipping_date,
                    co.create_order_date,
                    COALESCE(NULLIF(c.company, ''), CONCAT(c.name, ' ', c.surname)) as customer_name,
                    IFNULL(SUM(cop.quantity_carton), 0) as total_cartons
                FROM customer_order co
                INNER JOIN customer c
                    ON co.id_customer = c.id
                LEFT JOIN customer_order_product cop
                    ON cop.id_order = co.id
                WHERE co.status = 1
                GROUP BY co.id
                ORDER BY co.expected_shipping_date, customer_name, co.id";

        return $this->query($sql) ?: [];
    }

    public function getSupplyMonitorRows(array $orderIds = []): array
    {
        $orderIds = $this->sanitizeIds($orderIds);
        $orderFilter = '';

        if (!empty($orderIds)) {
            $orderFilter = ' AND co.id IN (' . implode(',', $orderIds) . ')';
        } else {
            $orderFilter = ' AND 1 = 0';
        }

        $sql = "SELECT
                    fp.id as id_product,
                    fp.name,
                    COALESCE(sec.name, 'Bez działu') as section_name,
                    COALESCE(cp.name, 'Bez kategorii') as category_name,
                    IFNULL(fp.cpp, 0) as cartons_per_pallet,
                    IFNULL(ord.ordered_quantity, 0) as ordered_quantity,
                    IFNULL(stock.in_stock, 0) as in_stock,
                    IFNULL(stock.palletised, 0) as palletised,
                    IFNULL(stock.in_stock, 0) + IFNULL(stock.palletised, 0) as total_available,
                    GREATEST(IFNULL(ord.ordered_quantity, 0) - IFNULL(stock.in_stock, 0) - IFNULL(stock.palletised, 0), 0) as remaining_to_produce,
                    (IFNULL(stock.in_stock, 0) + IFNULL(stock.palletised, 0) - IFNULL(ord.ordered_quantity, 0)) as available_after_orders,
                    ROUND(IFNULL(wdt4.sold_quantity, 0) / 4, 2) as avg_sold_last_4_weeks,
                    IFNULL(yoy.ordered_quantity_yoy, 0) as ordered_quantity_yoy
                FROM final_product fp
                LEFT JOIN category_product cp
                    ON fp.id_category = cp.id
                LEFT JOIN production_sections sec
                    ON fp.id_section = sec.id
                LEFT JOIN (
                    SELECT
                        cop.id_product,
                        SUM(cop.quantity_carton) as ordered_quantity
                    FROM customer_order_product cop
                    INNER JOIN customer_order co
                        ON cop.id_order = co.id
                    WHERE co.status = 1 {$orderFilter}
                    GROUP BY cop.id_product
                ) ord
                    ON fp.id = ord.id_product
                LEFT JOIN (
                    SELECT
                        cs.id_product,
                        SUM(CASE WHEN cs.status = 1 THEN 1 ELSE 0 END) as in_stock,
                        SUM(CASE WHEN cs.status = 2 THEN 1 ELSE 0 END) as palletised
                    FROM carton_stock cs
                    WHERE cs.status IN (1, 2)
                    GROUP BY cs.id_product
                ) stock
                    ON fp.id = stock.id_product
                LEFT JOIN (
                    SELECT
                        wdtp.id_product,
                        SUM(wdtp.quantity) as sold_quantity
                    FROM document_wdt_product wdtp
                    INNER JOIN document_wdt wdt
                        ON wdtp.id_document_wdt = wdt.id
                    WHERE DATE(wdt.established_date) >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                      AND DATE(wdt.established_date) < CURDATE()
                    GROUP BY wdtp.id_product
                ) wdt4
                    ON fp.id = wdt4.id_product
                LEFT JOIN (
                    SELECT
                        cop_prev.id_product,
                        SUM(cop_prev.quantity_carton) as ordered_quantity_yoy
                    FROM customer_order_product cop_prev
                    INNER JOIN customer_order co_prev
                        ON cop_prev.id_order = co_prev.id
                    INNER JOIN (
                        SELECT
                            DATE_SUB(MIN(DATE(co.expected_shipping_date)), INTERVAL 1 YEAR) as date_from,
                            DATE_SUB(MAX(DATE(co.expected_shipping_date)), INTERVAL 1 YEAR) as date_to
                        FROM customer_order co
                        WHERE co.status = 1 {$orderFilter}
                    ) period
                    WHERE DATE(co_prev.expected_shipping_date) BETWEEN period.date_from AND period.date_to
                    GROUP BY cop_prev.id_product
                ) yoy
                    ON fp.id = yoy.id_product
                WHERE IFNULL(ord.ordered_quantity, 0) > 0
                   OR IFNULL(stock.in_stock, 0) > 0
                   OR IFNULL(stock.palletised, 0) > 0
                ORDER BY sec.name, cp.name, remaining_to_produce DESC, ordered_quantity DESC, fp.name";

        return $this->query($sql) ?: [];
    }

    public function getProductsInStock()
    {
        $sql = "SELECT 
                    cs.id_product,
                    COUNT(cs.id_product) as count_in_stock,
                    fp.name,
                    fp.id_recipe
                FROM carton_stock cs
                INNER JOIN final_product fp
                    ON cs.id_product = fp.id
                WHERE cs.status = 1 OR cs.status = 2
                GROUP BY cs.id_product
                ORDER BY fp.name";
        return $this->query($sql, [],'assoc');
    }

    private function sanitizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        return array_values(array_unique($ids));
    }
}
