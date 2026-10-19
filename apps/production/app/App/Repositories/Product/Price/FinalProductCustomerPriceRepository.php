<?php

namespace App\Repositories\Product\Price;

use App\Models\ApiCURL;
use App\Core\Helpers\BaseRepository;
use App\Models\Client\PriceList\PriceListItemModel;
use App\Repositories\Client\ClientRepository;

/**
 * User class
 */
class FinalProductCustomerPriceRepository extends BaseRepository
{

    protected string $table = 'final_product_customer_price';

    protected $allowedColumns = [
        'id_final_product',
        'id_customer',
        'price',
        'available'
    ];

    public function updateFranchiseePrice($data)
    {
        $apiCurl = new ApiCURL();

        $url = APIPREFIXE . "/ShopMaterialPrice/updateSLFoodPrice";

        $res = $apiCurl->insert(json_encode($data), $url);
        return $res['success'];
    }

    public function selectProductPriceArrByCustomer($idClient)
    {
        $sql = "SELECT DISTINCT 
                    fp.id,
                    fp.name,
                    :id_customer as id_customer,
                    IFNULL(fpcp.price, 0) as price,
                    fp.ppc as pieces_per_carton,
                    IF(fpcp.available IS NULL, 0, fpcp.available) as is_available
                FROM final_product fp
                LEFT JOIN (SELECT id_final_product, price, available FROM final_product_customer_price WHERE id_customer = :id_customer) fpcp
                ON fp.id = fpcp.id_final_product
                WHERE fp.active = 1
                GROUP BY fp.id
                ORDER BY fp.name";
        return $this->query($sql, ['id_customer' => $idClient]);
    }

    public function getActiveByClient($clientId)
    {
        $sql = "SELECT DISTINCT 
                    fp.id,
                    fp.name,
                    :id_customer as id_customer,
                    IFNULL(fpcp.price, 0) as price,
                    fp.ppc as pieces_per_carton,
                    IF(fpcp.available IS NULL, 0, fpcp.available) as is_available
                FROM final_product fp
                LEFT JOIN (SELECT id_final_product, price, available FROM final_product_customer_price WHERE id_customer = :id_customer) fpcp
                ON fp.id = fpcp.id_final_product
                WHERE fp.active = 1
                GROUP BY fp.id
                ORDER BY fp.name";
        $resp = $this->query($sql, ['id_customer' => $clientId]);

        if(empty($resp)) return [];

        $casted = [];
        foreach($resp as $item){
            $casted[] = new PriceListItemModel([
                'id_product' => $item['id'],
                'sku' => $item['id'],
                'product_name' => $item['name'],
                'price_net' => $item['price'],
                'vat_rate' => '0',
                'package_size' => $item['pieces_per_carton'],
                'package_unit' => 'pcs',
                'is_active' => 1,
                'valid_from' => date('Y-m-d'),
            ]);
        }

        return $casted;
    }

    public function getByIdCustomer($data)
    {
        $sql = "SELECT 
                fp.id,
                fp.name,
                IF(fpcp.id_final_product IS NULL, 0, fpcp.available) as available,
                IFNULL(fpcp.price, fp.standard_price) as price 
                FROM final_product fp
                LEFT JOIN (SELECT  * FROM final_product_customer_price WHERE id_customer = :id_customer) fpcp
                    ON fp.id = fpcp.id_final_product
                WHERE fp.active = 1
                ORDER BY fp.name";
        return $this->query($sql, $data);
    }

    public function getByActiveIdCustomer($data)
    {
        $sql = "SELECT 
                fp.id,
                fp.name,
                IF(fpcp.id_final_product IS NULL, 0, fpcp.available) as available,
                IFNULL(fpcp.price, fp.standard_price) as price 
                FROM final_product fp
                LEFT JOIN (SELECT  * FROM final_product_customer_price WHERE id_customer = :id_customer) fpcp
                    ON fp.id = fpcp.id_final_product
                WHERE fp.active = 1 AND fpcp.available = 1
                ORDER BY fp.name";
        return $this->query($sql, $data);
    }


    public function increatePriceByMultipier($id_customer, $multiplier)
    {
        $sql = "UPDATE final_product_customer_price fpcp
                SET fpcp.price = ROUND(fpcp.price * :multiplier,2)
                WHERE id_customer = :id_customer";
        return $this->query($sql, [
            'multiplier' => $multiplier,
            'id_customer' => $id_customer]);
    }

    public function getProductPriceByCustomer($id_product)
    {
        $sql = "SELECT MIN(price) as price FROM final_product_customer_price WHERE id_final_product = :id_product AND price > 0";
        $res = $this->query($sql, ['id_product' => $id_product]);
        if (!$res) return -1;

        return $res[0]['price'];
    }

    public function updatePrice($idClient, $idProduct, $price)
    {
        $sql = "UPDATE $this->table SET price = :price WHERE id_customer = :id_customer && id_final_product = :id_final_product";
        return $this->query($sql, [
            'price' => $price,
            'id_customer' => $idClient,
            'id_final_product' => $idProduct
        ]);
    }

    public function updateAvailable($idClient, $idProduct, $available)
    {
        $sql = "UPDATE $this->table SET available = :available WHERE id_customer = :id_customer && id_final_product = :id_final_product";
        return $this->query($sql, [
            'available' => $available,
            'id_customer' => $idClient,
            'id_final_product' => $idProduct
        ]);
    }

    /**
     * Aktualizuje ceny dla wszystkich klientów mających dany produkt w cenniku.
     * Jeśli klient nie ma rekordu – tworzy go (upsert).
     */
    public function updateAllPricesForProduct(int $productId, float $price): int|bool
    {
        // Zaktualizuj wszystkich klientów którzy mają ten produkt
        $sql = "UPDATE final_product_customer_price SET price = :price WHERE id_final_product = :product_id";
        $updated = $this->query($sql, ['price' => $price, 'product_id' => $productId]);

        return $updated;
    }

    public function getByClientAndProduct($idClient, $idProduct)
    {
        $sql = "SELECT
                    fp.id,
                    fp.name,
                    fp.weight,
                    fp.ppc,
                    IFNULL(fpcp.price, fp.standard_price) as single_price
                FROM final_product fp
                LEFT JOIN (SELECT id_final_product, price FROM final_product_customer_price WHERE id_customer = :id_customer) fpcp
                    ON fp.id = fpcp.id_final_product
                WHERE fp.id = :id_product";
        return $this->query($sql, ['id_customer' => $idClient, 'id_product' => $idProduct]);
    }
}