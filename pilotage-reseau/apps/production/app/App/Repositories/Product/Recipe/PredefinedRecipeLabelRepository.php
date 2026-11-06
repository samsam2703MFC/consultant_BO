<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

/**
 * User class
 */
class PredefinedRecipeLabelRepository extends BaseRepository
{

    protected string $table = 'predefined_recipe_label';

    protected $allowedColumns = [
        'recipe_id',
        'lang_code',
        'allergens',
        'ingredients',
        'nutriscore',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'recipe_id',
        'lang_code',
        'allergens',
        'ingredients',
        'nutriscore',
        'last_update'
    ];

    public function deleteByRecipeAndLang(int $recipeId, string $langCode): bool
    {
        return $this->customDelete(
            "DELETE FROM {$this->table} WHERE recipe_id = :recipe_id AND lang_code = :lang_code",
            ['recipe_id' => $recipeId, 'lang_code' => $langCode]
        );
    }

}