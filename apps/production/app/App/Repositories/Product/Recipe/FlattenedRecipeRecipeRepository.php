<?php
namespace App\Repositories\Product\Recipe;


use App\Core\Helpers\BaseRepository;

/**
 *  shop customer class
 */
class FlattenedRecipeRecipeRepository extends BaseRepository
{

    protected string $table = 'flattened_recipe_recipe';
    protected $allowedColumns = [
        'recipe_id',
        'subrecipe_id'
    ];
    protected $fetchAllowedColumns = [
    ];

    public $errors = [];

    public function deleteAll()
    {
        return $this->query("DELETE FROM {$this->table}");
    }

    public function getRecipesBySubrecipeId($id)
    {
        return $this->query("SELECT * FROM {$this->table} WHERE subrecipe_id = :id", ['id' => $id]);
    }

}