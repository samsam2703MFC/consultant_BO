<?php

namespace App\Repositories\Shipping\Difference;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class OrderShippingDifferenceProductRepository extends BaseRepository
{

    protected string $table = 'document_order_shipping_difference_products';

    protected $allowedColumns = [
        'id_document_order_shipping_difference',
        'id_product',
        'product_name',
        'quantity_ordered',
        'price_during_ordering',
        'ordered_total_amount',
        'invoiced_quantity',
        'price_during_invoicing',
        'invoiced_total_amount',
        'difference_reason'
    ];

    public function updateDiffReason($data)
    {
        $sql = "UPDATE document_order_shipping_difference_products SET difference_reason = :difference_reason WHERE id_document_order_shipping_difference = :id_doc AND id_product = :id_product";
        return $this->customUpdate($sql, $data);
    }

    public function getAllByDifferenceId($id)
    {
        $sql = "SELECT * FROM document_order_shipping_difference_products WHERE id_document_order_shipping_difference = :id ORDER BY product_name";
        return $this->query($sql, ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getPaidProformaProductsByWdt(int $wdtId): array
    {
        $sql = "SELECT dpp.id_product, MAX(dpp.product_name) AS product_name,
                    SUM(dpp.quantity) AS quantity_ordered,
                    ROUND(SUM(dpp.total_price_net) / NULLIF(SUM(dpp.quantity), 0), 4) AS price_during_ordering,
                    ROUND(SUM(dpp.total_price_net), 2) AS ordered_total_amount
                FROM customer_order co
                INNER JOIN document_proforma dp ON dp.id = co.id_proforma
                INNER JOIN document_proforma_product dpp ON dpp.id_proforma = dp.id
                WHERE co.id_wdt = :id_wdt
                  AND LOWER(COALESCE(dp.status, '')) = 'paid'
                  AND IFNULL(co.is_freebie, 0) = 0
                GROUP BY dpp.id_product
                ORDER BY product_name";
        return $this->query($sql, ['id_wdt' => $wdtId]) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getShippedProductsByWdt(int $wdtId): array
    {
        $sql = "SELECT id_product, MAX(name) AS product_name,
                    SUM(quantity) AS invoiced_quantity,
                    ROUND(SUM(total_net) / NULLIF(SUM(quantity), 0), 4) AS price_during_invoicing,
                    ROUND(SUM(total_net), 2) AS invoiced_total_amount
                FROM document_wdt_product
                WHERE id_document_wdt = :id_wdt
                GROUP BY id_product
                ORDER BY product_name";
        return $this->query($sql, ['id_wdt' => $wdtId]) ?: [];
    }

    /** @param list<array<string, mixed>> $rows */
    public function insertSettlementProducts(int $differenceId, array $rows): bool
    {
        foreach ($rows as $row) {
            $result = $this->insert([
                'id_document_order_shipping_difference' => $differenceId,
                'id_product' => $row['id_product'],
                'product_name' => $row['product_name'],
                'quantity_ordered' => $row['quantity_ordered'],
                'price_during_ordering' => $row['price_during_ordering'],
                'ordered_total_amount' => $row['ordered_total_amount'],
                'invoiced_quantity' => $row['invoiced_quantity'],
                'price_during_invoicing' => $row['price_during_invoicing'],
                'invoiced_total_amount' => $row['invoiced_total_amount'],
            ]);
            if (!$result) {
                return false;
            }
        }
        return true;
    }


    public function updateInvoicedPrice($data)
    {
        $sql = "UPDATE document_order_shipping_difference_products SET
            price_during_invoicing = :price_carton
        WHERE id_document_order_shipping_difference = :id_diff AND id_product = :id_product";
        return $this->customUpdate($sql, $data);
    }

    public function updateProduct($idDifference, $idProduct, $data)
    {
        $data['id_document_order_shipping_difference'] = $idDifference;
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
        $query .= " where id_document_order_shipping_difference = :id_document_order_shipping_difference AND id_product = :id_product";

        return $this->query($query, $data);
    }
}
