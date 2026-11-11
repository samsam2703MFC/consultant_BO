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