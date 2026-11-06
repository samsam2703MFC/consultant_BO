<?php

namespace App\Repositories\Complaint;

use App\Domain\Client\Order\OpenOrders;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ComplaintRepository extends BaseRepository
{

    protected string $table = 'complaint';

    protected $allowedColumns = [
        'complaint_uuid',
        'complaint_key',
        'complaint_type',
        'complaint_reason',
        'requested_action',
        'compensation_method',
        'id_customer',
        'id_order',
        'invoice_num',
        'id_product',
        'client_comment',
        'product_quantity',
        'accepted_quantity',
        'single_purchase_price',
        'acceptance_status',
        'production_response',
        'closed_on',
        'notified_at',
        'reported_at'
    ];


//getPendingById
    public function getById($id)
    {
        $sql = "SELECT 
                cpp.id,
                cust.company,
                cpp.id_customer,
                cpp.id_final_product,
                fp.name as product_name,
                cpp.pending_product_qty,
                fp.ppc,
                ROUND(cpp.pending_product_qty/fp.ppc) as expected_carton_qty
                FROM complaint_product_pending cpp
                INNER JOIN final_product fp
                    ON cpp.id_final_product = fp.id
                INNER JOIN customer cust
                    ON cpp.id_customer = cust.id
                WHERE cpp.id = :id
                ORDER BY cust.company, fp.name";
        $res = $this->query($sql, ['id' => $id]);
        if (!$res) return [];

        return $res[0];
    }

    public function validateInsert($data)
    {
        $this->errors = [];

        if ($data['single_purchase_price'] == 0) {
            $this->errors['single_purchase_price'] = "Ustal prawidłową cenę zakupu";
        }

        if ($data['product_quantity'] == 0) {
            $this->errors['product_quantity'] = "Wprowadź prawidłową ilość";
        }


        if (empty($this->errors)) {
            return true;
        }

        return false;
    }

    /** Najbliższy dzień wysyłki otwartego zamówienia każdego klienta (do odpowiedzi „Expédition le …”). */
    public function getNextShippingDates(): array
    {
        $rows = $this->query(
            "SELECT id_customer, MIN(expected_shipping_date) AS day
             FROM customer_order
             WHERE " . OpenOrders::sql('customer_order') . " AND expected_shipping_date >= CURDATE()
             GROUP BY id_customer"
        ) ?: [];
        return array_column($rows, 'day', 'id_customer');
    }

    /**
     * [243] Wykres « Oś czasu » : każda reklamacja z dniem zgłoszenia, decyzją, grupą produkcji produktu (karta produktu,
     * grupa główna), ilością, ilością przyjętą i ceną kartonu z WDT.
     */
    public function chartRows(): array
    {
        return $this->query(
            "SELECT c.id, DATE(COALESCE(c.reported_at, c.acceptance_timestamp)) AS day, COALESCE(c.acceptance_status, 'NEW') AS status,
                    c.complaint_type, c.complaint_reason, c.id_customer, cust.company AS customer, p.name AS product,
                    c.product_quantity, c.accepted_quantity, c.single_purchase_price,
                    CASE WHEN c.acceptance_status IN ('ACCEPTED', 'REJECTED') AND c.reported_at IS NOT NULL AND c.acceptance_timestamp IS NOT NULL
                         THEN TIMESTAMPDIFF(HOUR, c.reported_at, c.acceptance_timestamp) / 24 END AS decision_days,
                    (SELECT g.id FROM n_prod_item i
                       JOIN n_prod_product_settings s ON s.id_item = i.id
                       JOIN n_prod_group g ON g.id = s.id_primary_group
                      WHERE i.id_final_product = c.id_product ORDER BY g.sort_order, g.id LIMIT 1) AS group_id
             FROM complaint c
             LEFT JOIN customer cust ON cust.id = c.id_customer
             LEFT JOIN final_product p ON p.id = c.id_product
             WHERE COALESCE(c.reported_at, c.acceptance_timestamp) IS NOT NULL
             ORDER BY day, c.id"
        ) ?: [];
    }

    /** [243] Grupy produkcji (wiersze osi czasu), w kolejności menu. */
    public function chartGroups(): array
    {
        return $this->query('SELECT id, name_pl FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl') ?: [];
    }

    public function getAll()
    {
        $sql = "SELECT
                    c.id,
                    c.complaint_uuid,
                    c.complaint_reason,
                    c.complaint_type,
                    c.requested_action,
                    c.compensation_method,
                    c.id_customer,
                    c.id_order,
                    c.invoice_num,
                    c.id_product,
                    c.product_quantity,
                    c.accepted_quantity,
                    c.client_comment,
                    c.single_purchase_price,
                    c.acceptance_status,
                    cust.company as customer_name,
                    p.name as product_name,
                    c.acceptance_timestamp,
                    c.reported_at,
                    c.production_response,
                    wdt.established_date as invoice_date,
                    wdt.id as wdt_id
                FROM complaint c
                LEFT JOIN customer cust ON c.id_customer = cust.id
                LEFT JOIN final_product p ON c.id_product = p.id
                LEFT JOIN document_wdt wdt ON wdt.doc_num = c.invoice_num
                ORDER BY c.acceptance_timestamp DESC";
        return $this->query($sql);
    }

}
