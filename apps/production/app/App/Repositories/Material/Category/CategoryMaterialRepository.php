<?php


namespace App\Repositories\Material\Category;
use App\Core\Helpers\BaseRepository;

/**
 * Categort model class
 */
class CategoryMaterialRepository extends BaseRepository
{


    protected string $table = 'category_material';

    protected $allowedColumns = [
        'id',
        'id_group',
        'name'
    ];

    public function getPackCategory()
    {
        $sql = "SELECT id, name FROM $this->table WHERE id_group = 1";
        return $this->query($sql);
    }

}