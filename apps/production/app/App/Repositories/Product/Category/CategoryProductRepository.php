<?php


namespace App\Repositories\Product\Category;
use App\Core\Helpers\BaseRepository;

/**
 * Categort model class
 */
class CategoryProductRepository extends BaseRepository
{


    protected string $table = 'category_product';

    protected $allowedColumns = [
        'id',
        'name',
        'target_margin_percentage'
    ];

    public function getExistedCategoryInProductList()
    {
        $sql = "SELECT DISTINCT
                    fp.id_category as id_category,
                    fp.id_category as id_category_subquery,
                    cp.name,
                    cp.target_margin_percentage,
                    (SELECT COUNT(id) FROM final_product ifp WHERE ifp.id_category = id_category_subquery AND ifp.active = 1) as qty_active,
                    (SELECT COUNT(id) FROM final_product ifp WHERE ifp.id_category = id_category_subquery AND ifp.active = 0) as qty_no_active
                FROM final_product fp
                INNER JOIN $this->table cp
                ON fp.id_category = cp.id
                ORDER BY cp.name";
        return $this->query($sql);
    }

    public function updateCategory($id, array $data)
    {
        return $this->update($id, $data);
    }

    public function createCategory(array $data)
    {
        return $this->insert($data);
    }

}