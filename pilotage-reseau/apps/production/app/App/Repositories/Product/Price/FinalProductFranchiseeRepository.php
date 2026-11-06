<?php

namespace App\Repositories\Product\Price;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class FinalProductFranchiseeRepository extends BaseRepository
{

    protected string $table = 'franchisee_final_product';

    protected $allowedColumns = [
        'id_franchisee',
        'id_final_product',
        'price',
        'available'
    ];


    public function getByFranchisee($data)
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    ffp.id_final_product,
                    IF(ffp.id_final_product IS NULL, 0, ffp.available) as available,
                    IFNULL(ffp.price,0) as price 
                FROM final_product fp
                LEFT JOIN (SELECT * FROM franchisee_final_product WHERE id_franchisee = :id_franchisee) ffp
                ON fp.id = ffp.id_final_product
                WHERE fp.active = 1
                ORDER BY fp.name";
        return $this->query($sql, $data);
    }


    public function update($data)
    {
        $exist = $this->first(['id_franchisee' => $data['id_franchisee'], 'id_final_product' => $data['id_final_product']]);
        if (!$exist) {
            return $this->insert($data);
        }

        $sql = "UPDATE $this->table SET price = :price, available = :available WHERE id_franchisee = :id_franchisee && id_final_product = :id_final_product";
        return $this->query($sql, $data);
    }

}