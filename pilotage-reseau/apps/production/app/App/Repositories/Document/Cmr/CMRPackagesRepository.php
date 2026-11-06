<?php

namespace App\Repositories\Document\Cmr;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class CMRPackagesRepository extends BaseRepository
{


    protected string $table = 'document_cmr_packages';

    protected $allowedColumns = [

    ];

    public function getByIdCMR($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id_document_cmr = :id_document_cmr";
        return $this->query($sql, ['id_document_cmr' => $id]);
    }

    public function insertSelectBasedOnWz($data)
    {
        $sql = "INSERT INTO `document_cmr_packages`(
                                    `id_document_cmr`,
                                    `name`, 
                                    `quantity`, 
                                    `packing_method`,
                                    `product_type`, 
                                    `stats_num`, 
                                    `gross_weight`, 
                                    `volume_m3`) 
                    SELECT
                            :id_document_cmr,
                            'Cartons',
                            SUM(dwp.quantity),
                            'Carton',
                            'Bakery Products',
                            '19059090',         
                            SUM(dwp.weight_total),         
                            0         
                    FROM document_wz_product dwp
                    WHERE dwp.id_document_wz = :id_document_wz";
        return $this->customInsert($sql, $data);
    }
}