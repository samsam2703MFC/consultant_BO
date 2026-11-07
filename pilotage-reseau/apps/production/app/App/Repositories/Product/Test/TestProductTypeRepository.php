<?php
namespace App\Repositories\Product\Test;


use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class TestProductTypeRepository extends BaseRepository
{

    protected string $table = 'test_product_type';

    protected $allowedColumns = [
        'name',
    ];

    public function getWithChangelog()
    {
        $sql = "SELECT DISTINCT tpt.* 
                FROM `test_product_type` tpt
                INNER JOIN test_product tp
                    ON tpt.id = tp.id_type
                INNER JOIN test_product_changelog tpc
                    ON tp.id = tpc.id_test_product;";
        return $this->query($sql);
    }

}