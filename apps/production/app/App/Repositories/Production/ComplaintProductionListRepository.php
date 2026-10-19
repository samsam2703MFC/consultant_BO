<?php
namespace App\Repositories\Production;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ComplaintProductionListRepository extends BaseRepository
{

    protected string $table = '';

    protected $allowedColumns = [

    ];

    protected $fetchAllowedColumns = [

    ];

    public function createTemporaryTable()
    {
        $sql = "CREATE TEMPORARY TABLE production_list_tmp (
                    id_product INT PRIMARY KEY,
                    name VARCHAR(255),
                    id_section INT,
                    ppc INT,
                    complaint INT
                    )";
        return $this->executeStatement($sql);
    }

    public function fillTemporaryTable()
    {
        $sql = "INSERT INTO production_list_tmp (
                                     id_product,
                                     name, 
                                     id_section,
                                     ppc, 
                                     complaint) 
                    SELECT 
                           cp.id_product,
                           fp.name, 
                           fp.id_section,
                           fp.ppc,
                           SUM(cp.carton_qty) as carton_qty 
                    FROM complaint_product_to_send_log cp
                    INNER JOIN final_product fp
                    ON cp.id_product = fp.id
                    WHERE status = 0 GROUP BY id_product";
        return $this->customInsert($sql, []);
    }

    public function fetchFromTemporaryByIdSectionWithoutOrder($idSection)
    {
        $sql = "SELECT 
                            id_product,
                            name,
                            ppc,
                            complaint as complaint_carton,
                            (complaint*ppc) as complaint_pcs
                        FROM production_list_tmp
                        WHERE id_section = :id_section ORDER BY name";
        return $this->query($sql, ['id_section' => $idSection]);
    }
}