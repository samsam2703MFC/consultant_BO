<?php
namespace App\Repositories\Document\Wdt;

use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class WDTProductRepository extends BaseRepository
{

    protected string $table = 'document_wdt_product';

    protected $allowedColumns = [
        'id_document_wdt',
        'id_palette',
        'id_product',
        'name',
        'code',
        'quantity',
        'price_carton',
        'total_net',
        'total_tva',
        'price_brut',
        'tva_perc',
        'weight_carton',
        'weight_carton_total',
        'ppc',
        'price_per_piece'
    ];

    public function getProductsByIdWdt($id)
    {
        $sql = "SELECT
                    DISTINCT
                                     wdtp.id_product,
                                     wdtp.name,
                                     wdtp.quantity,
                                     wdtp.price_carton,
                                     IFNULL(fpcp.price, fp.standard_price) as suggested_price
                FROM document_wdt_product wdtp
                INNER JOIN document_wdt wdt
                    ON wdtp.id_document_wdt = wdt.id

                LEFT JOIN final_product_customer_price fpcp
                    ON wdt.id_customer = fpcp.id_customer AND wdtp.id_product = fpcp.id_final_product

                INNER JOIN final_product fp
                    ON wdtp.id_product = fp.id

                WHERE id_document_wdt = :id_document_wdt";
        return $this->query($sql, ['id_document_wdt' => $id]);
    }

    public function getByIdWdt($id)
    {
        $sql = "SELECT * FROM document_wdt_product WHERE id_document_wdt = :id_document_wdt";
        return $this->query($sql, ['id_document_wdt' => $id]);
    }

    public function getGroupedByProducts($idWdt)
    {
        $sql = "SELECT 
                    id_product,
                    name,
                    SUM(quantity) as quantity,
                    price_carton,
                    SUM(total_net) as total_net,
                    SUM(total_tva) as total_tva,
                    price_brut,
                    tva_perc,
                    weight_carton,
                    SUM(weight_carton_total) as weight_carton_total,
                    ppc,
                    price_per_piece
                FROM document_wdt_product
                WHERE id_document_wdt = :id_document_wdt
                GROUP BY id_product, price_carton
                ORDER BY name";
        return $this->query($sql, ['id_document_wdt' => $idWdt]);
    }

    public function updatePrice($data)
    {
        $sql = "UPDATE document_wdt_product SET 
                                price_carton = :price_carton,
                                total_net = quantity * :price_carton,
                                price_per_piece = :price_carton / ppc
                WHERE id_document_wdt = :id_document_wdt AND id_product = :id_product";
        return $this->query($sql, $data);
    }


    public function getSoldDataInLast30Days($id_product)
    {
        $sql = "SELECT 
                    IFNULL(SUM(quantity),0) as sold_quantity,
                    IFNULL(SUM(quantity*price_carton),0) as earned_value
                FROM document_wdt_product wdt_prod
                INNER JOIN (SELECT id FROM document_wdt WHERE DATE(NOW()) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) wdt
                    ON wdt_prod.id_document_wdt = wdt.id
                WHERE wdt_prod.id_product = :id_product";
        $res = $this->query($sql, ['id_product' => $id_product]);
        if(!$res) return [];

        return $res[0];
    }

    public function getSoldDataBySectionInLastMonth($id_section)
    {
        $sql = "SELECT 
                    fp.id,
                    fp.id_category,
                    cp.name as category_name,
                    fp.name,
                    fp.id_recipe,
                    IFNULL(SUM(wdt_prod.quantity),0) as sold_quantity,
                    IFNULL(SUM(wdt_prod.quantity*wdt_prod.price_carton),0) as earned_value_euro
                FROM document_wdt_product wdt_prod
                INNER JOIN (SELECT id FROM document_wdt WHERE document_wdt.established_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND established_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')) wdt
                    ON wdt_prod.id_document_wdt = wdt.id
                INNER JOIN final_product fp
                    ON wdt_prod.id_product = fp.id
                INNER JOIN category_product cp
                    ON fp.id_category = cp.id
                WHERE fp.id_section = :id_section
                GROUP BY fp.id
                ORDER BY fp.name";
        return $this->query($sql, ['id_section' => $id_section]);
    }


    public function getSoldDataByCustomerInPeriod(int $idCustomer, string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT
                    fp.id                                                       AS id_product,
                    fp.name                                                     AS name,
                    IFNULL(SUM(wdt_prod.quantity), 0)                           AS total_cartons
                FROM document_wdt_product wdt_prod
                INNER JOIN document_wdt wdt
                    ON wdt_prod.id_document_wdt = wdt.id
                INNER JOIN final_product fp
                    ON wdt_prod.id_product = fp.id
                WHERE wdt.id_customer      = :id_customer
                  AND wdt.established_date >= :date_from
                  AND wdt.established_date <= :date_to
                GROUP BY fp.id, fp.name
                ORDER BY fp.name";

        return $this->query($sql, [
            'id_customer' => $idCustomer,
            'date_from'   => $dateFrom . ' 00:00:00',
            'date_to'     => $dateTo   . ' 23:59:59',
        ]) ?: [];
    }

    public function getSoldDataByCustomerInLastMonth($id_customer)
    {
        $sql = "SELECT 
                    fp.id,
                    fp.id_category,
                    cp.name as category_name,
                    fp.name,
                    fp.id_recipe,
                    IFNULL(SUM(wdt_prod.quantity),0) as sold_quantity,
                    IFNULL(SUM(wdt_prod.quantity*wdt_prod.price_carton),0) as earned_value_euro
                FROM document_wdt_product wdt_prod
                INNER JOIN (SELECT id FROM document_wdt WHERE id_customer = :id_customer AND document_wdt.established_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND established_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')) wdt
                    ON wdt_prod.id_document_wdt = wdt.id
                INNER JOIN final_product fp
                    ON wdt_prod.id_product = fp.id
                INNER JOIN category_product cp
                    ON fp.id_category = cp.id
                GROUP BY fp.id
                ORDER BY cp.name, fp.name";
        return $this->query($sql, ['id_customer' => $id_customer]);
    }

    public function insertSelectForStandardShipping($data)
    {
        $sql = "INSERT INTO `document_wdt_product`(
                                   `id_document_wdt`, 
                                   `id_palette`, 
                                   `id_product`,
                                   `name`, 
                                   `code`, 
                                   `quantity`,
                                   `price_carton`,
                                   `total_net`, 
                                   `total_tva`,
                                   `price_brut`,
                                   `tva_perc`,
                                   `weight_carton`,
                                   `weight_carton_total`, 
                                   `ppc`,
                                   `price_per_piece`) 
                    SELECT 
                        :id_document_wdt,
                        pd.id_palet,
                        cs.id_product,
                        fp.name,
                        '19059090',
                        COUNT(cs.id_product),
                        IFNULL(fpcp.price, fp.standard_price),
                        ((IFNULL(fpcp.price, fp.standard_price)) * COUNT(cs.id_product)),
                        0,
                        0,
                        0,
                        ((fp.weight * fp.ppc)/1000),
                        (COUNT(cs.id_product)*((fp.weight*fp.ppc)/1000)),
                        fp.ppc,
                        TRUNCATE((IFNULL(fpcp.price, fp.standard_price)/fp.ppc),2)
                    FROM palets_delivery pd
                    INNER JOIN carton_pallet_connection cpc
                    ON pd.id_palet = cpc.id_palet
                    INNER JOIN carton_stock cs
                    ON cpc.id_carton = cs.id_carton
                    INNER JOIN final_product fp
                    ON cs.id_product = fp.id
                    LEFT JOIN (SELECT * FROM final_product_customer_price WHERE id_customer = :id_customer_price) fpcp
                    ON fp.id = fpcp.id_final_product
                    
                    WHERE DATE(pd.delivery_date) = DATE(:shipping_date) AND pd.id_customer = :id_customer
                    
                    GROUP BY pd.id_palet, fp.id
                    ORDER BY pd.id_palet, fp.name;";
        return $this->customInsert($sql, $data);
    }

    public function selectForStandardShipping($data)
    {
        $sql = "SELECT 
                        :id_document_wdt as id_document_wdt,
                        pd.id_palet as id_palette,
                        cs.id_product as id_product,
                        fp.name as name,
                        '19059090' as code,
                        COUNT(cs.id_product) as quantity,
                        IFNULL(fpcp.price, fp.standard_price) as price_carton,
                        ((IFNULL(fpcp.price, fp.standard_price)) * COUNT(cs.id_product)) as total_net,
                        0 as total_tva,
                        0 as price_brut,
                        0 as tva_perc,
                        ((fp.weight * fp.ppc)/1000) as weight_carton,
                        (COUNT(cs.id_product)*((fp.weight*fp.ppc)/1000)) as weight_carton_total,
                        fp.ppc as ppc,
                        TRUNCATE((IFNULL(fpcp.price, fp.standard_price)/fp.ppc),2) as price_per_piece
                    FROM palets_delivery pd
                    INNER JOIN carton_pallet_connection cpc
                    ON pd.id_palet = cpc.id_palet
                    INNER JOIN carton_stock cs
                    ON cpc.id_carton = cs.id_carton
                    INNER JOIN final_product fp
                    ON cs.id_product = fp.id
                    LEFT JOIN (SELECT * FROM final_product_customer_price WHERE id_customer = :id_customer_price) fpcp
                    ON fp.id = fpcp.id_final_product
                    WHERE DATE(pd.delivery_date) = DATE(:shipping_date) AND pd.id_customer = :id_customer
                    GROUP BY pd.id_palet, fp.id
                    ORDER BY pd.id_palet, fp.name;";
        return $this->query($sql, $data);
    }

    public function updateProduct($idWdt, $idProduct, $data)
    {
        $data['id_document_wdt'] = $idWdt;
        $data['id_product'] = $idProduct;

        /**remove unwanted data */
        if(!empty($this->allowedColumns)){
            foreach ($data as $key => $value){
                if(!in_array($key, $this->allowedColumns))
                {
                    unset($data[$key]);
                }
            }
        }

        $keys = array_keys($data);
        $query = "UPDATE {$this->table} SET ";

        foreach ($keys as $key){
            $query .= $key . " = :" . $key . ", ";
        }

        $query = trim($query, ", ");
        $query .= " where id_document_wdt = :id_document_wdt AND id_product = :id_product";

        return $this->query($query, $data);
    }
}