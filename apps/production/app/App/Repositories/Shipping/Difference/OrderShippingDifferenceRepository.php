<?php

namespace App\Repositories\Shipping\Difference;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class OrderShippingDifferenceRepository extends BaseRepository
{

    protected string $table = 'document_order_shipping_difference';

    protected $allowedColumns = [
        'id_customer',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignor_nip',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'consignee_nip',
        'established_date'
    ];

    protected $fetchAllowedColumns = [
        'id',
        'id_customer',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignor_nip',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'consignee_nip',
        'established_date',
        'create_timestamp'
    ];

    public function getForPaymentReminderList(int $limit = 50): array
    {
        $sql = "SELECT
                    diff.id,
                    diff.established_date AS difference_date,
                    customer.company AS client_name,
                    wdt.doc_num AS invoice_number,
                    COALESCE(wdt.invoiced_date, wdt.established_date) AS invoice_date,
                    wdt.currency,
                    SUM(
                        COALESCE(products.invoiced_total_amount,
                            COALESCE(products.invoiced_quantity, 0) * COALESCE(products.price_during_invoicing, 0))
                        - COALESCE(products.ordered_total_amount,
                            COALESCE(products.quantity_ordered, 0) * COALESCE(products.price_during_ordering, 0))
                    ) AS difference_amount
                FROM document_order_shipping_difference diff
                INNER JOIN shipment_log log ON log.id_diff = diff.id
                INNER JOIN document_wdt wdt ON wdt.id = log.id_wdt
                INNER JOIN customer ON customer.id = diff.id_customer
                LEFT JOIN document_order_shipping_difference_products products
                    ON products.id_document_order_shipping_difference = diff.id
                GROUP BY diff.id, diff.established_date, customer.company, wdt.doc_num, wdt.invoiced_date, wdt.established_date, wdt.currency
                ORDER BY diff.established_date DESC, diff.id DESC
                LIMIT " . max(1, min(200, $limit));

        return $this->query($sql);
    }

}
