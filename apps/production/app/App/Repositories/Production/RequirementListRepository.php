<?php
namespace App\Repositories\Production;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class RequirementListRepository extends BaseRepository
{

    protected string $table = '';

    protected $allowedColumns = [

    ];

    protected $fetchAllowedColumns = [

    ];

    public function getRequirementPositionsByOrderIds($orderIds)
    {
        $orderIds = implode(',', $orderIds);
        $sql = "SELECT 
                  mat.id,
                  mat.name as material_name,
                  SUM(cop.quantity_carton * fri.quantity) AS required,
                  0 as in_stock,
                  mat.price_per_unit,
                  mat.id_unit,
                  SUM(cop.quantity_carton * fri.quantity) * mat.price_per_unit as approx_value
                FROM customer_order_product cop
                JOIN final_product fp
                  ON cop.id_product = fp.id
                JOIN flattened_recipe_ingredient fri
                  ON fp.id_recipe = fri.recipe_id
                JOIN raw_material mat
                  ON fri.material_id = mat.id
                WHERE cop.id_order IN ($orderIds)
                GROUP BY mat.id, mat.name;";
        return $this->query($sql);
    }

    public function getRequirementPositionsByOrderIdsWithStockMinimum($orderIds)
    {
        $orderIds = implode(',', $orderIds);
        $sql = "SELECT 
                  mat.id,
                  mat.name as material_name,
                  SUM(cop.quantity_carton * fri.quantity) + SUM(fp.stock_minimum * fri.quantity)  AS required,
                  0 as in_stock,
                  mat.price_per_unit,
                  mat.id_unit,
                  (SUM(cop.quantity_carton * fri.quantity) + SUM(fp.stock_minimum * fri.quantity)) * mat.price_per_unit as approx_value
                FROM customer_order_product cop
                JOIN final_product fp
                  ON cop.id_product = fp.id
                JOIN flattened_recipe_ingredient fri
                  ON fp.id_recipe = fri.recipe_id
                JOIN raw_material mat
                  ON fri.material_id = mat.id
                WHERE cop.id_order IN ($orderIds)
                GROUP BY mat.id, mat.name;";
        return $this->query($sql);
    }




}