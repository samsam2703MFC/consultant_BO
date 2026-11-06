<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

/**
 * User class
 */
class ProductRecipePreparationConnection extends BaseRepository
{

    protected string $table = 'product_recipe_preparation_connection';

    protected $allowedColumns = [
        'id_recipe',
        'id_preparation_type',
        'duration_second'
    ];

    protected $fetchAllowedColumns = [
        'id_recipe',
        'id_preparation_type',
        'duration_second'
    ];

    public function insertForRecipe($idRecipe, $preparationTypes)
    {
        if(!empty($preparationTypes)){
            foreach ($preparationTypes as $item){
                $res = $this->insert([
                    'id_recipe' => $idRecipe,
                    'id_preparation_type' => $item['id_preparation'],
                    'duration_second' => $item['duration_second']
                ]);
                if(!$res) {
                    throw new CustomException("INSERT_PREPARATION_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }
        }
        return true;
    }

}