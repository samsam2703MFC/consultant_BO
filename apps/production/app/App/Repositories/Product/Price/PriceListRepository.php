<?php
namespace App\Repositories\Product\Price;

use App\Core\Helpers\BaseRepository;
use Model;
use MYPDFModel;

/**
 * Categort model class
 */
class PriceListRepository extends BaseRepository
{
    protected string $table = '';

    protected $allowedColumns = [

    ];


    public function getExistedCategoryInMaterialList()
    {
        $sql = "SELECT DISTINCT
                    rm.id_category,
                    cm.name
                FROM raw_material rm
                INNER JOIN $this->table cm
                ON rm.id_category = cm.id
                ORDER BY cm.name";
        return $this->query($sql);
    }


    public function getPriceListData($data)
    {
        $sql = "SELECT
                    fpcp.id_final_product,
                    fpcp.price,
                    fp.name as final_product_name,
                    cat.name as category_name,
                    fp.id_category
                FROM final_product_customer_price fpcp
                INNER JOIN final_product fp
                    ON fpcp.id_final_product = fp.id
                INNER JOIN category_product cat
                    ON fp.id_category = cat.id
                WHERE fpcp.id_customer = :id_customer AND available = 1 AND fp.active = 1
                ORDER BY cat.name, fp.name;";
        return $this->query($sql, $data);
    }

    public function checkProductQty($data)
    {
        $this->table = "final_product_customer_price";
        if (!$this->first($data))
            return false;
        else
            return true;
    }

    public function assignStandardPriceList($data)
    {
        $sql = "INSERT INTO final_product_customer_price (id_customer, id_final_product, price, available)
                SELECT :id_customer, id, standard_price, 1 FROM final_product";
        return $this->query($sql, $data);
    }

    public function copyStandardPriceList($data)
    {
        $sql = "INSERT INTO final_product_customer_price (id_customer, id_final_product, price, available)
                SELECT :id_customer, id_final_product, price, 1 FROM final_product_customer_price WHERE id_customer = :id_copy_from_customer";
        return $this->query($sql, $data);
    }
}