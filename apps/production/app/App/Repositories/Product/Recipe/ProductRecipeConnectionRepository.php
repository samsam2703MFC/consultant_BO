<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

/**
 * User class
 */
class ProductRecipeConnectionRepository extends BaseRepository
{

    protected string $table = 'product_recipe_connection';

    protected $allowedColumns = [
        'parent_recipe_id',
        'child_recipe_id',
        'child_ingredient_id',
        'quantity'
    ];

    protected $fetchAllowedColumns = [
        'parent_recipe_id',
        'child_recipe_id',
        'child_ingredient_id',
        'quantity'
    ];

    public function insertMaterialForRecipe($idRecipe, $materialData)
    {
        if(!empty($materialData)){
            foreach ($materialData as $item){
                $res = $this->insert([
                    'parent_recipe_id' => $idRecipe,
                    'child_recipe_id' => null,
                    'child_ingredient_id' => $item['id'],
                    'quantity' => $item['quantity']
                ]);
                if(!$res) {
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }
        }
        return true;
    }

    public function insertCompositionForRecipe($idRecipe, $compositionData)
    {
        if(!empty($compositionData)){
            foreach ($compositionData as $item){
                $res = $this->insert([
                    'parent_recipe_id' => $idRecipe,
                    'child_recipe_id' => $item['id'],
                    'child_ingredient_id' => null,
                    'quantity' => $item['quantity']
                ]);
                if(!$res) {
                    throw new CustomException("INSERT_COMPOSITION_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }
        }
        return true;
    }

    public function insertSemiProductForRecipe($idRecipe, $sfpData)
    {
        if(!empty($sfpData)){
            foreach ($sfpData as $item){
                $res = $this->insert([
                    'parent_recipe_id' => $idRecipe,
                    'child_recipe_id' => $item['id'],
                    'child_ingredient_id' => null,
                    'quantity' => $item['quantity']
                ]);
                if(!$res) {
                    throw new CustomException("INSERT_SEMI_PRODUCT_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }
        }
        return true;
    }

}