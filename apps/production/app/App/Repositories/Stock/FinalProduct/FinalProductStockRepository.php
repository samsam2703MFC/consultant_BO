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

    public function getProductsInStock()
    {
        $sql = "SELECT 
                    cs.id_product,
                    COUNT(cs.id_product) as count_in_stock,
                    fp.name,
                    fp.id_recipe,
                    fp.pre_calculated_raw_price_per_carton AS card_cost
                FROM carton_stock cs
                INNER JOIN final_product fp
                    ON cs.id_product = fp.id
                WHERE cs.status = 1 OR cs.status = 2
                GROUP BY cs.id_product
                ORDER BY fp.name";
        return $this->query($sql, [],'assoc');
    }
}
