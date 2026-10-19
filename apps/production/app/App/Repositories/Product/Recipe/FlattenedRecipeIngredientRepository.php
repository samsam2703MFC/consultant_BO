<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

/**
 * User class
 */
class FlattenedRecipeIngredientRepository extends BaseRepository
{

    protected string $table = 'flattened_recipe_ingredient';

    protected $allowedColumns = [
        'recipe_id',
        'material_id',
        'quantity'
    ];

    protected $fetchAllowedColumns = [
        'recipe_id',
        'material_id',
        'quantity'
    ];

    public function getByMaterialId($materialId)
    {
        return $this->query("SELECT * FROM {$this->table} WHERE material_id = :material_id",
            ['material_id' => $materialId]
        );
    }

    public function deleteAll()
    {
        return $this->query("DELETE FROM {$this->table}");
    }

    public function getIngredientsCostSumByRecipeId($recipeId)
    {
        $sql = "SELECT
                    SUM(fri.quantity * mat.price_per_unit) as raw_cost 
                FROM flattened_recipe_ingredient fri 
                INNER JOIN raw_material mat
                    ON fri.material_id = mat.id
                WHERE fri.recipe_id = :recipe_id";
        $res = $this->query($sql, ['recipe_id' => $recipeId]);

        // SUM() zawsze zwraca dokładnie 1 wiersz — gdy brak danych raw_cost = NULL, nie 0 wierszy
        if (empty($res) || $res[0]['raw_cost'] === null) {
            return 0.0;
        }

        return (float) $res[0]['raw_cost'];
    }

    public function getForLabel($recipeId, $langCode)
    {
        $sql = "SELECT
                  GROUP_CONCAT(
                    CONCAT(
                      x.mat_name,
                      IF(x.allergens <> '', CONCAT(' (', x.allergens, ')'), '')
                    )
                    ORDER BY x.quantity DESC
                    SEPARATOR ', '
                  ) AS ingredients,
                    IFNULL(
                      GROUP_CONCAT(
                        DISTINCT x.allergens
                        ORDER BY x.allergens
                        SEPARATOR ', '
                      ), 
                      ''
                    ) as allergens,
                    'energie 1840 kJ 440 kcal - lipides 23 g dont acides gras saturés 6,3 g - glucides 51 g dont sucres 31 g polyols 8,4 g - fibres alimentaires 2,5 g - protéines 6,0 g sel 0,26 g' as nutriscore
                FROM (
                  SELECT
                    fri.recipe_id,
                    fri.quantity,
                    IFNULL(rma.alias, mat.name) AS mat_name,
                    IFNULL(aa.alias, al.code) AS allergens
                  FROM flattened_recipe_ingredient fri
                  INNER JOIN raw_material mat
                    ON fri.material_id = mat.id
                  LEFT JOIN material_allergen_connection mac
                    ON fri.material_id = mac.material_id
                  LEFT JOIN allergen al
                    ON mac.allergen_id = al.id
                  LEFT JOIN raw_material_alias rma 
                    ON mat.id = rma.material_id
                    AND rma.alias_type = 'product_label'
                    AND rma.lang_code = :lang_code
                  LEFT JOIN allergen_alias aa 
                    ON al.id = aa.allergen_id
                    AND aa.alias_type = 'product_label'
                    AND aa.lang_code = :lang_code
                  WHERE fri.recipe_id = :recipe_id
                    AND mat.is_part_of_package = 0
                ) x
                WHERE x.recipe_id = :recipe_id
                GROUP BY x.recipe_id;";
        $res = $this->query($sql, [
            'recipe_id' => $recipeId,
            'lang_code' => $langCode
        ]);

        if(count($res) == 0)
        {
            return [
                'allergens' => "",
                'ingredients' => "",
                'nutriscore' => ""
            ];
        }
        return $res;
    }
}