<?php

namespace App\Repositories\Shipping;
use App\Core\Helpers\BaseRepository;
use Model;

/**
 * User class
 */
class PaletDeliveryRepository extends BaseRepository
{

    protected string $table = 'palets_delivery';

    protected $allowedColumns = [

    ];

    public function getDistinctDateWithoutDocument()
    {
        $sql = "SELECT DISTINCT delivery_date FROM palets_delivery WHERE status = 2 ORDER BY delivery_date DESC";
        return $this->query($sql);
    }

    public function getDistinctDateWithoutDocumentForComplaint()
    {
        $sql = "SELECT DISTINCT shipping_date 
                FROM carton_stock_complaint
                WHERE status = 1 
                ORDER BY shipping_date DESC";
        return $this->query($sql);
    }

    public function getCustomerByShippingDate($shippingDate)
    {
        $sql = "SELECT DISTINCT 
                    pd.id_customer,
                    cust.company
                FROM palets_delivery pd
                INNER JOIN customer cust
                    ON pd.id_customer = cust.id
                WHERE pd.delivery_date = :shipping_date AND pd.status = 2";
        return $this->query($sql, ['shipping_date' => $shippingDate]);
    }

    /**
     * Porównuje produkty zamówione przez klienta na daną datę wysyłki
     * z produktami faktycznie zeskanowanymi na paletach.
     * Zwraca listę produktów z ilościami: zamówioną i zeskanowaną.
     */
    public function getPendingProductsPreview($shippingDate, $customerId)
    {
        $sql = "SELECT
                    fp.id                                        AS id_product,
                    fp.name                                      AS product_name,
                    COALESCE(ordered.quantity_ordered, 0)        AS quantity_ordered,
                    COALESCE(scanned.quantity_scanned, 0)        AS quantity_scanned,
                    (COALESCE(ordered.quantity_ordered, 0) - COALESCE(scanned.quantity_scanned, 0)) AS quantity_diff
                FROM final_product fp

                LEFT JOIN (
                    SELECT cop.id_product, SUM(cop.quantity_carton) AS quantity_ordered
                    FROM customer_order_product cop
                    INNER JOIN customer_order co ON cop.id_order = co.id
                    WHERE co.id_customer     = :id_customer_order
                      AND DATE(co.expected_shipping_date) = :shipping_date_order
                    GROUP BY cop.id_product
                ) ordered ON fp.id = ordered.id_product

                LEFT JOIN (
                    SELECT cs.id_product, COUNT(cs.id_carton) AS quantity_scanned
                    FROM carton_stock cs
                    INNER JOIN carton_pallet_connection cpc ON cs.id_carton = cpc.id_carton
                    INNER JOIN palets_delivery pd           ON cpc.id_palet  = pd.id_palet
                    WHERE pd.id_customer     = :id_customer_scan
                      AND DATE(pd.delivery_date) = :shipping_date_scan
                      AND pd.status > 1
                    GROUP BY cs.id_product
                ) scanned ON fp.id = scanned.id_product

                WHERE ordered.id_product IS NOT NULL OR scanned.id_product IS NOT NULL
                ORDER BY fp.name";

        return $this->query($sql, [
            'id_customer_order'   => $customerId,
            'shipping_date_order' => $shippingDate,
            'id_customer_scan'    => $customerId,
            'shipping_date_scan'  => $shippingDate,
        ]);
    }

    /**
     * Zwraca inne aktywne daty zamówień klienta (status 1 lub 2, bez WDT),
     * różne od aktualnie wybranej daty wysyłki.
     * Pozwala ostrzec użytkownika, że klient ma zamówienia na inne dni.
     */
    public function getOtherActiveOrderDatesByCustomer($shippingDate, $customerId)
    {
        $sql = "SELECT 
                    DATE(co.expected_shipping_date) AS shipping_date,
                    COUNT(co.id)                    AS order_count
                FROM customer_order co
                WHERE co.id_customer = :id_customer
                  AND (co.status = 1 OR co.status = 2)
                  AND co.id_wdt IS NULL
                  AND DATE(co.expected_shipping_date) != :shipping_date
                GROUP BY DATE(co.expected_shipping_date)
                ORDER BY co.expected_shipping_date ASC";

        return $this->query($sql, [
            'id_customer'   => $customerId,
            'shipping_date' => $shippingDate,
        ]);
    }

    public function updateStatusByDateAndCustomer($deliveryDate, $customerId, $status)
    {
        $sql = "UPDATE palets_delivery 
                SET status = :status 
                WHERE delivery_date = :delivery_date AND id_customer = :id_customer";
        return $this->customUpdate($sql, [
            'status' => $status,
            'delivery_date' => $deliveryDate,
            'id_customer' => $customerId
        ]);
    }

}