<?php
namespace App\Repositories\Document\Wz;

use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class WZProductRepository extends BaseRepository
{

    protected string $table = 'document_wz_product';

    protected $allowedColumns = [
        'id_document_wz',
        'id_product',
        'name',
        'code',
        'quantity',
        'weight_unit',
        'weight_total',
        'ppc'
    ];

    
    public function getByIdWz($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id_document_wz = :id_document_wz";
        return $this->query($sql, ['id_document_wz' => $id]);
    }

    public function insertSelectForStandardShipping($data)
    {
        $sql = "INSERT INTO `document_wz_product`(
                                  `id_document_wz`, 
                                  `id_product`, 
                                  `name`, 
                                  `code`, 
                                  `quantity`, 
                                  `weight_unit`, 
                                  `weight_total`, 
                                  `ppc`) 
                    SELECT 
                           :id_wz, 
                           cs.id_product, 
                           fp.name, 
                           '19059090', 
                           COUNT(cs.id_product), 
                           TRUNCATE((fp.weight/1000),2), 
                           TRUNCATE(COUNT(cs.id_product) * ((fp.weight)/1000) * fp.ppc, 2), 
                           fp.ppc
                    FROM carton_stock cs
                        INNER JOIN final_product fp
                            ON cs.id_product = fp.id
                        INNER JOIN carton_pallet_connection cpc
                            ON cs.id_carton = cpc.id_carton
                        INNER JOIN palets_delivery pd
                            ON cpc.id_palet = pd.id_palet
                            
                            WHERE pd.id_customer = :id_customer AND DATE(pd.delivery_date) = :shipping_date AND pd.status > 1
                            GROUP BY cs.id_product
                            ORDER BY fp.name";
        return $this->customInsert($sql, $data);
    }

    public function insertSelectForComplaintShipping($data)
    {
        $sql = "INSERT INTO `document_wz_product`(
                                  `id_document_wz`, 
                                  `id_product`, 
                                  `name`, 
                                  `code`, 
                                  `quantity`, 
                                  `weight_unit`, 
                                  `weight_total`, 
                                  `ppc`) 
                    SELECT 
                           :id_wz, 
                           cs.id_product, 
                           fp.name, 
                           '19059090', 
                           COUNT(cs.id_product), 
                           TRUNCATE((fp.weight/1000),2), 
                           TRUNCATE(COUNT(cs.id_product) * ((fp.weight)/1000) * fp.ppc, 2), 
                           fp.ppc
                    FROM carton_stock_complaint csc
                        INNER JOIN carton_stock cs
                            ON csc.id_carton = cs.id_carton
                        INNER JOIN final_product fp
                            ON cs.id_product = fp.id
                            WHERE csc.id_customer = :id_customer 
                              AND DATE(csc.shipping_date) = :shipping_date 
                              AND csc.status = 1
                            GROUP BY cs.id_product
                            ORDER BY fp.name";
        return $this->customInsert($sql, $data);
    }
}