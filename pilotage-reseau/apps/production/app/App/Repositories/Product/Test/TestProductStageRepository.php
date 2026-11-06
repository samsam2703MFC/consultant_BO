<?php
namespace App\Repositories\Product\Test;


use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class TestProductStageRepository extends BaseRepository
{

    protected string $table = 'test_product_stage';

    protected $allowedColumns = [
        'name'
    ];




    public function updatePriority($data)
    {
        foreach ($data as $item){
            $sql = "UPDATE $this->table SET priority = :prio WHERE id = :id";
            $this->query($sql, ['id' => $item['id'], 'prio' => $item['prio']]);
        }

        return true;
    }

}