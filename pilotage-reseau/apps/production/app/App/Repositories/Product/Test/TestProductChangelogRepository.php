<?php
namespace App\Repositories\Product\Test;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class TestProductChangelogRepository extends BaseRepository
{

    protected string $table = 'test_product_changelog';

    protected $allowedColumns = [
        'id_test_product',
        'id_stage',
        'description',
        'deadline'
    ];

    public function selectChangelogForProduct($idProduct)
    {
        $sql = "SELECT 
                    tpc.id,
                    tpc.id_test_product, 
                    tps.name as title,
                    tpc.description,
                    tpc.deadline,
                    tpc.create_timestamp
                FROM $this->table tpc
                INNER JOIN test_product_stage tps
                ON tpc.id_stage = tps.id
                WHERE tpc.id_test_product = :id_test_product
                ORDER BY tpc.create_timestamp ASC";
        return $this->query($sql, ['id_test_product' => $idProduct]);
    }

    public function getChangelogById($data)
    {
        $sql = "SELECT 
                    id,
                    id_test_product, 
                    title,
                    description,
                    deadline,
                    create_timestamp
                FROM $this->table
                WHERE id = :id
                ORDER BY create_timestamp ASC";
        return $this->query($sql, $data);
    }

    public function findForFranchisee()
    {
        $sql = "SELECT tpc.id, tpc.id_test_product, tps.name as stage_name, tpc.description, tpc.deadline, tpc.create_timestamp
        FROM $this->table tpc 
        INNER JOIN test_product_stage tps
        ON tpc.id_stage = tps.id
        INNER JOIN test_product tp
        ON tpc.id_test_product = tp.id
        WHERE tp.id_type = 1";
        return $this->query($sql);
    }
}