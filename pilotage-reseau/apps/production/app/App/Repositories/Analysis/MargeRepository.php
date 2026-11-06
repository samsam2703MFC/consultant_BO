<?php


namespace App\Repositories\Analysis;
use App\Core\Helpers\BaseRepository;
use RecipeModel;

/**
 * Marge model class
 */
class MargeRepository extends BaseRepository
{


    function getProductMargeByCategoryAndCustomer($data)
    {
        $sql = "SELECT
                    fp.id_recipe,
                    fp.id as id_product,
                    fp.name as name_product,
                    ps.name as name_section,
                    fp.cpp as carton_per_palette,
                    fp.palette_cost,
                    fp.marge_perc,
                    fp.ppc,
                    IFNULL(fpcp.price, fp.standard_price) as price
                FROM final_product fp
                LEFT JOIN (SELECT id_final_product, price FROM final_product_customer_price WHERE id_customer = :id_customer) fpcp
                	ON fp.id = fpcp.id_final_product
                INNER JOIN production_sections ps
                	ON fp.id_section = ps.id
                WHERE fp.id_category = :id_category AND fp.active = 1
                ORDER BY fp.name";
        $resp = $this->query($sql, $data);

        if (!$resp) return [];

        $recipeModel = new RecipeModel();
        for ($i = 0; $i < count($resp); $i++) {
            if ($resp[$i]->id_recipe == null) {
                $resp[$i]->material_price = 0;
                $resp[$i]->pack_price = 0;
                $resp[$i]->preparation_price = 0;
            } else {
                $recipe_data = $recipeModel->getRecipeSplittedOnTypeOfProduct(['id_recipe' => $resp[$i]->id_recipe]);
                $resp[$i]->material_price = $recipe_data['material'];
                $resp[$i]->pack_price = $recipe_data['pack'];
                $resp[$i]->preparation_price = $recipe_data['preparation_price'];
            }
        }

        return $resp;
    }


}