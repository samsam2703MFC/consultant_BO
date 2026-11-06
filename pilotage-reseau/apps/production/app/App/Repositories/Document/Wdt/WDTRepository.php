<?php
namespace App\Repositories\Document\Wdt;

use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class WDTRepository extends BaseRepository
{
    protected string $table = 'document_wdt';

    protected $allowedColumns = [
        'doc_num', 
        'id_customer',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignor_nip', 
        'consignor_bank',
        'consignor_swift',
        'established_date', 
        'consignee_name', 
        'consignee_address_1',
        'consignee_address_2',
        'consignee_nip',
        'invoiced_date',
        'shipping_date',
        'total_weight',
        'palette_nums',
        'total_net',
        'total',
        'currency',
    ];

    public function getDataForFranchiseeInput($id_wdt)
    {
        $res_data = [];

        $sql = "SELECT 
                       1 as id_supplier, 
                       customer.franchisee_id as id_franchisee,
                       document_wdt.doc_num as invoice_num, 
                       document_wdt.established_date as invoice_date
                FROM document_wdt 
                    INNER JOIN customer 
                        ON document_wdt.id_customer = customer.id 
                WHERE document_wdt.id = :id_wdt";
        $res = $this->query($sql, ['id_wdt' => $id_wdt]);

        if(!$res) return false;

        $res_data['other_data'] = $res[0];

        $sql = "SELECT id_product, name as product_name, quantity, price_carton as single_price FROM document_wdt_product WHERE id_document_wdt = :id_wdt";
        $res = $this->query($sql, ['id_wdt' => $id_wdt]);

        if(!$res) return false;

        $res_data['product_data'] = $res;

        return $res_data;

    }

    public function insertSelectForStandardShipping($data)
    {
        $sql = "INSERT INTO `document_wdt`(
                          `doc_num`,
                          `id_customer`, 
                          `consignor_name`,
                          `consignor_address_1`,
                          `consignor_address_2`,
                          `consignor_nip`,
                          `consignor_bank`, 
                          `consignor_swift`, 
                          `established_date`, 
                          `consignee_name`,
                          `consignee_address_1`, 
                          `consignee_address_2`, 
                          `consignee_nip`,
                          `invoiced_date`,
                          `shipping_date`,
                          `total_weight`,
                          `palette_nums`,
                          `total_net`) 
                     SELECT 
                            CONCAT(YEAR(NOW()), RIGHT(CONCAT('000',WEEK(:shipping_date_week)+1),2) ,:id_customer_num),
                        	:id_customer,
                        	:consignor_name, 
                            :consignor_address_1, 
                            :consignor_address_2, 
                            :consignor_nip, 
                            :consignor_bank, 
                            :consignor_swift, 
                            :established_date, 
                            :consignee_name, 
                            :consignee_address_1, 
                            :consignee_address_2, 
                            :consignee_nip,
                            :invoiced_date,
                            :shipping_date,
                            SUM(dwp.weight_total),
                            (SELECT GROUP_CONCAT(' ',id_palet) FROM palets_delivery WHERE id_customer = :id_customer_pd AND DATE(delivery_date) = :shipping_date_pd),
                            (
                                SELECT 
                                    SUM(x.price)
                                FROM (SELECT
                                (COUNT(cs.id_product)*IFNULL(fpcp.price, fp.standard_price)) as price
                                FROM palets_delivery pd
                                INNER JOIN carton_pallet_connection cpc
                                ON pd.id_palet = cpc.id_palet
                                INNER JOIN carton_stock cs
                                ON cpc.id_carton = cs.id_carton
                                INNER JOIN final_product fp
                                ON cs.id_product = fp.id
                                LEFT JOIN (SELECT * FROM final_product_customer_price WHERE id_customer = :id_customer_total_net_price_1) fpcp
                                ON fp.id = fpcp.id_final_product
                                WHERE pd.id_customer = :id_customer_total_net_price_2 AND DATE(pd.delivery_date) = :shipping_date_total_net_price
                                GROUP BY cs.id_product)x)
                            FROM document_wz_product dwp
                            WHERE dwp.id_document_wz = :id_document_wz";
        return $this->customInsert($sql, $data);
    }
}
