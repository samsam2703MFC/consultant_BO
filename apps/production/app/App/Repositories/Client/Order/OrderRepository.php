<?php

namespace App\Repositories\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;

use App\Core\Helpers\BaseRepository;
use App\Repositories\Document\DocumentRepository;
use MYPDFModel;

/**
 * Order model class
 */
class OrderRepository extends BaseRepository
{

    protected string $table = 'customer_order';

    protected $allowedColumns = [
        'id_customer',
        'reference',
        'invoice_num',
        'status',
        'order_description',
        'expected_shipping_date',
        'create_order_date',
        'update_status_date',
        'id_franchisee_collector',
        'standard_price_list',
        'id_proforma',
        'id_wdt',
        'is_freebie',
        'order_batch_key',
        'batch_index',
        'order_type',
        'parent_order_id',
        'supplement_push_status',
        'supplement_push_at',
        'supplement_order_key'
    ];

    public function getById($id)
    {
        $sql = "SELECT 
                    co.*,
                    cs.id as id_customer,
                    cs.name as customer_name,
                    cs.surname as customer_surname,
                    cs.company,
                    cs.fac_street,
                    cs.fac_street_num,
                    cs.fac_zip,
                    cs.fac_town,
                    cs.tax_number
                FROM customer_order co
                INNER JOIN customer cs
                    ON co.id_customer = cs.id
                WHERE co.id = :id_order";
        return $this->query($sql, ['id_order' => $id])[0];
    }

    public function getByIdFranchiseeCollector($data)
    {
        $sql = "SELECT 
                    co.*,
                    cs.company
                FROM $this->table co
                INNER JOIN customer cs
                    ON co.id_customer = cs.id
                WHERE co.id_franchisee_collector = :id_franchisee_collector";
        return $this->query($sql, $data)[0];
    }

    public function getLastInsertedIdOfOrderFromCollector()
    {
        $sql = "SELECT IFNULL(MAX(id_franchisee_collector),0) as lastInsertedOrder FROM customer_order WHERE id_franchisee_collector IS NOT NULL";
        return $this->query($sql);
    }


    public function getPending()
    {
        $sql = "SELECT
                co.id as id,
                co.id_customer,
                CONCAT(cust.name, ' ', cust.surname) as fullname,
                cust.company,
                co.status,
                co.order_description,
                DATE(co.create_order_date) as create_date,
                TIME(co.create_order_date) as create_order_time,
                co.id_franchisee_collector,
                DATE(expected_shipping_date) as expected_shipping_date,
                cost.name as type_name,
                co.id_proforma,
                doc.status as proforma_status,
                co.id_wdt,
                co.is_freebie,
                co.order_batch_key,
                co.batch_index,
                (SELECT COUNT(*) FROM customer_order co2
                    WHERE co2.order_batch_key IS NOT NULL
                    AND co2.order_batch_key = co.order_batch_key) as batch_total,
                COALESCE(co.order_type, 'STANDARD') as order_type,
                co.supplement_push_status,
                if(
                    cust.franchisee_uid is not null AND
                    cust.credentials is not null, 
                    1, 0) as integrated_customer,
                ROUND((SELECT
                           SUM((((cop.quantity_carton*fp.ppc) * fp.weight)/1000)) as totalWeightKg
                       FROM customer_order_product cop
                                INNER JOIN final_product fp
                                           ON cop.id_product = fp.id
                       WHERE cop.id_order = co.id),2) as totalSumKg
            
            FROM customer_order co
                     INNER JOIN customer cust
                                ON co.id_customer = cust.id
                    INNER JOIN customer_order_status_type cost
                        ON co.status = cost.id
                    LEFT JOIN document_proforma doc
                        ON co.id_proforma = doc.id
            WHERE co.status = " . CustomerOrderStatus::PENDING . "
            ORDER BY expected_shipping_date;";
        return $this->query($sql);
    }


    public function getClientsWithOrders(): array
    {
        $sql = "SELECT DISTINCT cust.id, cust.company
                FROM customer_order co
                INNER JOIN customer cust ON co.id_customer = cust.id
                ORDER BY cust.company ASC";

        return $this->query($sql);
    }

    /** @param list<int> $orderIds */
    public function hasAllPendingIds(array $orderIds): bool
    {
        $orderIds = array_values(array_unique(array_filter($orderIds, static fn(int $id): bool => $id > 0)));
        if ($orderIds === []) {
            return false;
        }

        $placeholders = [];
        $params = [];
        foreach ($orderIds as $index => $id) {
            $placeholder = ':id_' . $index;
            $placeholders[] = $placeholder;
            $params['id_' . $index] = $id;
        }
        $params['pending_status'] = CustomerOrderStatus::PENDING;
        $rows = $this->query(
            'SELECT COUNT(*) AS count_pending FROM customer_order
             WHERE status = :pending_status AND id IN (' . implode(',', $placeholders) . ')',
            $params
        );
        return is_array($rows) && (int) ($rows[0]['count_pending'] ?? 0) === count($orderIds);
    }

    /** Zwraca zrealizowane i wysłane zamówienia wraz z kluczami dokumentów. */
    public function getArchived(array $filters = []): array
    {
        $where = ['co.status IN (' . implode(', ', CustomerOrderStatus::archivalValues()) . ')'];
        $params = [];
        if (!empty($filters['client_id']) && ctype_digit((string) $filters['client_id'])) {
            $where[] = 'co.id_customer = :client_id';
            $params['client_id'] = (int) $filters['client_id'];
        }
        foreach (['date_from', 'date_to'] as $filter) {
            if (!empty($filters[$filter]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$filter])) {
                $where[] = 'DATE(co.expected_shipping_date) ' . ($filter === 'date_from' ? '>=' : '<=') . " :{$filter}";
                $params[$filter] = $filters[$filter];
            }
        }
        if (($filters['document'] ?? '') === 'proforma') $where[] = 'co.id_proforma IS NOT NULL';
        elseif (($filters['document'] ?? '') === 'wdt') $where[] = 'co.id_wdt IS NOT NULL';
        elseif (($filters['document'] ?? '') === 'none') $where[] = 'co.id_proforma IS NULL AND co.id_wdt IS NULL';

        $sorts = [
            'client' => 'cust.company',
            'description' => 'co.order_description',
            'order_date' => 'co.create_order_date',
            'shipping_date' => 'co.expected_shipping_date',
            'weight' => 'total_sum_kg',
            'status' => 'co.status',
        ];
        $sort = $sorts[$filters['sort'] ?? 'shipping_date'] ?? $sorts['shipping_date'];
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $sql = "SELECT co.id, co.reference, co.status, co.order_description,
                    DATE(co.create_order_date) AS create_date, TIME(co.create_order_date) AS create_order_time,
                    DATE(co.expected_shipping_date) AS expected_shipping_date, co.id_proforma, co.id_wdt,
                    cust.company, COALESCE(cost.name, CASE WHEN co.status = " . CustomerOrderStatus::ARCHIVED . " THEN 'Zarchiwizowane' ELSE CONCAT('Status ', co.status) END) AS status_name,
                    ROUND((SELECT SUM((cop.quantity_carton * fp.ppc * fp.weight) / 1000)
                           FROM customer_order_product cop INNER JOIN final_product fp ON cop.id_product = fp.id
                           WHERE cop.id_order = co.id), 2) AS total_sum_kg
                FROM customer_order co
                INNER JOIN customer cust ON co.id_customer = cust.id
                LEFT JOIN customer_order_status_type cost ON co.status = cost.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY " . $sort . " " . $direction . ", co.id DESC";
        return $this->query($sql, $params);
    }
    public function insertOrderProductDataWithStandardPrice($data, $connObj)
    {
        $sql = "INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) 
                        SELECT :id_order, id, :new_quantity, :exp_quantity, standard_price
                        FROM final_product WHERE id = :id_product";
        return $this->queryForTransaction($sql, $connObj, $data);
    }

    public function insertOrderProductData($data, $connObj)
    {

        $sql = "INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) 
                        SELECT :id_order, fp.id, :new_quantity, :exp_quantity, IFNULL(fpcp.price, fp.standard_price)
                        FROM final_product_customer_price fpcp
                        RIGHT JOIN final_product fp
                            ON fpcp.id_final_product = fp.id
                        WHERE fp.id = :id_product AND fpcp.id_customer = :id_customer";
        return $this->queryForTransaction($sql, $connObj, $data);
    }


    private function getQueryToInsertProductFromManualOrder($data)
    {
        $queryWithParamArr = [];
        if ($data['standard_price_list'] == 1) {
            foreach ($data['product'] as $productItem) {
                $sql = "INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) 
                        SELECT :insertedId, id, :quantity, :exp_quantity, standard_price
                        FROM final_product WHERE id = :id_product";
                $productId = key($productItem);
                array_push($queryWithParamArr, [
                    "query" => $sql,
                    "data" => [
                        "insertedId" => $data['id_order'],
                        "quantity" => $productItem[$productId],
                        "exp_quantity" => $productItem[$productId],
                        "id_product" => $productId
                    ]]);
            }
        } else {
            foreach ($data['product'] as $productItem) {
                $sql = "INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) 
                        SELECT :insertedId, fp.id, :quantity, :exp_quantity, IFNULL(fpcp.price, fp.standard_price)
                        FROM final_product_customer_price fpcp
                        RIGHT JOIN final_product fp
                            ON fpcp.id_final_product = fp.id
                        WHERE fp.id = :id_product AND fpcp.id_customer = :id_customer";
                $productId = key($productItem);
                array_push($queryWithParamArr, [
                    "query" => $sql,
                    "data" => [
                        "insertedId" => $data['id_order'],
                        "quantity" => $productItem[$productId],
                        "exp_quantity" => $productItem[$productId],
                        "id_product" => $productId,
                        "id_customer" => $data['id_customer']
                    ]]);
            }
        }
        return $queryWithParamArr;
    }

    public function getDatesOfSelectedOrders($data)
    {
        if (!isset($data['order_id']) || empty($data['order_id'])) {
            $orderIdStr = '0';
        } else {
            $orderIdStr = implode(',', $data['order_id']);
        }

        $sql = "SELECT DISTINCT DATE(co.expected_shipping_date) as expected_shipping_date FROM customer_order co WHERE co.id IN ({$orderIdStr}) ORDER BY co.expected_shipping_date LIMIT 4";
        $res = $this->query($sql);

        if ($res && count($res) <= 4) {
            for ($i = count($res); $i < 4; $i++) {
                $new = ['expected_shipping_date' => '-'];
                array_push($res, $new);
            }
        } else {
            $res = [];
            for ($i = count($res); $i < 4; $i++) {
                $new = ['expected_shipping_date' => '-'];
                array_push($res, $new);
            }
        }

        return $res;
    }


    public function getAssignedProforma($idOrder)
    {
        $sql = "SELECT id_proforma FROM customer_order WHERE id = :id_order";
        $res = $this->query($sql, ['id_order' => $idOrder]);
        return !empty($res) ? $res[0]['id_proforma'] : null;
    }

    public function updateWdtIdByShippingDateAndCustomer($wdtId, $shippingDate, $customerId)
    {
        $sql = "UPDATE customer_order SET id_wdt = :wdt_id WHERE expected_shipping_date = :shipping_date AND id_customer = :id_customer";
        return $this->customUpdate($sql, [
            'wdt_id' => $wdtId,
            'shipping_date' => $shippingDate,
            'id_customer' => $customerId
        ]);
    }
}
