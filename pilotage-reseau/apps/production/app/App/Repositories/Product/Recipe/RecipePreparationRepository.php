<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class RecipePreparationRepository extends BaseRepository
{

    protected string $table = 'product_preparation_type';

    protected $allowedColumns = [

    ];

    protected $fetchAllowedColumns = [

    ];


    public function getPreparationForRecipe($data)
    {
        $sql = "SELECT
                prpc.id_preparation_type,
                prpc.duration_second,
                (prpc.duration_second/60)*ppt.price as preparation_price,
                ppt.name
            FROM product_recipe_preparation_connection prpc
                INNER JOIN product_preparation_type ppt
                    ON prpc.id_preparation_type = ppt.id
            WHERE prpc.id_recipe = :id_recipe";
        return $this->query($sql, $data);
    }

    public function getPreparationTypes()
    {
        $sql = "SELECT 
                    id, 
                    name
                FROM product_preparation_type
                ORDER BY name";
        return $this->query($sql);
    }


    public function validate($data)
    {
        $this->errors = [];

        if(empty($data['name']))
        {
            $this->errors['name'] = "Wprowadź nazwę";
        }

        if(empty($data['weight']))
        {
            $this->errors['weight'] = "Wprowadź wagę";
        }

        if(empty($data['ppc']))
        {
            $this->errors['ppc'] = "Podaj ilość sztuk w kartonie";
        }
        if(empty($data['ppb']))
        {
            $this->errors['ppb'] = "Podaj ilość sztuk w opakowaniu";
        }
        if(empty($data['bpc']))
        {
            $this->errors['bpc'] = "Podaj ilość opakowań w kartonie";
        }
        if(empty($data['cpl']))
        {
            $this->errors['cpl'] = "Podaj ilość kartonów w jednej warstwie";
        }
        if(empty($data['lpp']))
        {
            $this->errors['lpp'] = "Podaj ilość warstw na palecie";
        }
        if(empty($data['cpp']))
        {
            $this->errors['cpp'] = "Podaj ilość kartonów na palecie";
        }

        if(empty($data['id_category']) || $data['id_category'] === "-1")
        {
            $this->errors['id_category'] = "Wybierz kategorię";
        }

        if(empty($data['id_section']) || $data['id_section'] === "-1")
        {
            $this->errors['id_category'] = "Wybierz dział produkcyjny";
        }

        if(empty($this->errors))
        {
            return true;
        }

        return false;
    }

    public function getRecipeActivityPrice($data)
    {
        $sql = "WITH RECURSIVE cte (parent_recipe_id_, child_recipe_id_, child_ingredient_id_, quantity_, iterator_, looper) AS
                    (
                      SELECT parent_recipe_id, child_recipe_id, child_ingredient_id, quantity, 1 as iterator_, 0 as looper FROM product_recipe_connection WHERE parent_recipe_id = :id_recipe
                        
                      UNION ALL
                      SELECT 
                        c.child_recipe_id_, t.child_recipe_id, t.child_ingredient_id, t.quantity, iterator_+1 as iterator_, looper+1 as looper
                      FROM
                        cte c
                      JOIN product_recipe_connection t
                      ON c.child_recipe_id_ = t.parent_recipe_id
                      WHERE looper != 100
                    )
                    
                    SELECT 
                        parent_recipe_id_, 
                        parent_recipe_id_ as parent_rec,
                        child_recipe_id_, 
                        child_ingredient_id_, 
                        quantity_,             
                        IF(child_recipe_id_ IS NOT NULL, prpc.id_preparation_type,0) as preparation_type,
                        IF(child_recipe_id_ IS NOT NULL, prpc.duration_second,0) as preparation_time,
                        IF(child_recipe_id_ IS NOT NULL, prpc.duration_second/60*ppt.price, 0) as activity_price,
                        
                        pr.outcome_unit,
                        iterator_ 
                    FROM cte 
                    
                    LEFT JOIN product_recipe pr
                    ON cte.parent_recipe_id_ = pr.id
                    
                    LEFT JOIN product_recipe_preparation_connection prpc
                    ON cte.parent_recipe_id_ = prpc.id_recipe
                    
                    LEFT JOIN product_preparation_type ppt
                    ON prpc.id_preparation_type = ppt.id
            
                    WHERE child_recipe_id_ is not null
                    GROUP BY parent_recipe_id_, child_recipe_id_, child_ingredient_id_, quantity_ ORDER BY iterator_ DESC, parent_recipe_id_ DESC";
        $resp = $this->query($sql, $data);

        if(!$resp) return 0;

        $resp = json_decode(json_encode($resp), true);

        $resp = array_reverse($resp);
        $tmpArr = [];
        foreach ($resp as $item)
        {
            $tmpArr[$item['parent_recipe_id_']] = $item;
        }

        $price = 0;
        foreach ($tmpArr as $tmpItem)
        {
            if($tmpItem['parent_recipe_id_'] == $data['id_recipe']){
                $price += $tmpItem['activity_price'];
            }

            foreach ($tmpArr as $childRecipeItem){
                if($childRecipeItem['parent_recipe_id_'] == $tmpItem['child_recipe_id_']){
                    $price += $tmpItem['quantity_']*$childRecipeItem['activity_price'];
                }
            }
        }

        return $price;
    }


}