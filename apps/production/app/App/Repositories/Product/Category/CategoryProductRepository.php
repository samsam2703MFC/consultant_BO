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

    /** Wszystkie kategorie produktów z liczbą produktów aktywnych i nieaktywnych (także kategorie bez produktów). */
    public function allWithCounts(): array
    {
        $sql = "SELECT cp.id, cp.name, cp.target_margin_percentage,
                       COALESCE(SUM(fp.active = 1), 0) AS qty_active,
                       COALESCE(SUM(fp.active = 0), 0) AS qty_inactive
                FROM $this->table cp
                LEFT JOIN final_product fp ON fp.id_category = cp.id
                GROUP BY cp.id, cp.name, cp.target_margin_percentage
                ORDER BY cp.name";
        return $this->query($sql) ?: [];
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