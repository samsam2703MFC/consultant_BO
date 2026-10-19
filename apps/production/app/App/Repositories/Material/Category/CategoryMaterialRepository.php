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

    public function getExistedCategoryInMaterialList()
    {
        $sql = "SELECT DISTINCT
                    rm.id_category,
                    cm.name
                FROM raw_material rm
                INNER JOIN $this->table cm
                ON rm.id_category = cm.id
                ORDER BY cm.name";
        return $this->query($sql);
    }

    public function getPackCategory()
    {
        $sql = "SELECT id, name FROM $this->table WHERE id_group = 1";
        return $this->query($sql);
    }

}