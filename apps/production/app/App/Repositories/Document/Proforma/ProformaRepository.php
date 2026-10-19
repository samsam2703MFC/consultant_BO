<?php
namespace App\Repositories\Document\Proforma;

use App\Models\ApiCURL;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ProformaRepository extends BaseRepository
{

    protected string $table = 'document_proforma';
    protected $allowedColumns = [
        'doc_num',
        'id_customer',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignor_nip',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'consignee_nip',
        'consignor_bank',
        'consignor_swift',
        'established_date',
        'status',
        'uuid',
        'is_sent_to_client',
        'sent_to_client_at',
        'send_method',
        'is_sent_to_franchise',
        'received_by_franchise_at',
        's3_bucket',
        's3_key',
        'checksum_sha256',
        'size_bytes',
        'uploaded_at',
        'notified_at'
    ];
    protected $fetchAllowedColumns = [
        'id',
        'doc_num',
        'id_customer',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignor_nip',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'consignee_nip',
        'consignor_bank',
        'consignor_swift',
        'established_date',
        'create_timestamp',
        'status',
        'uuid',
        'is_sent_to_client',
        'sent_to_client_at',
        'send_method',
        'is_sent_to_franchise',
        'received_by_franchise_at',
        's3_bucket',
        's3_key',
        'checksum_sha256',
        'size_bytes',
        'uploaded_at',
        'notified_at'
    ];

    public $errors = [];

    /** @return array<int, array<string, mixed>> */
    public function getUnpaidForReminder(): array
    {
        return $this->query(
            "SELECT dp.id, dp.doc_num, dp.established_date, dp.id_customer, c.company,
                    COALESCE(SUM(dpp.total_price_net), 0) AS total_price_net
             FROM document_proforma dp
             INNER JOIN customer c ON c.id = dp.id_customer
             LEFT JOIN document_proforma_product dpp ON dpp.id_proforma = dp.id
             WHERE LOWER(COALESCE(dp.status, 'issued')) NOT IN ('paid', 'cancelled')
             GROUP BY dp.id, dp.doc_num, dp.established_date, dp.id_customer, c.company
             ORDER BY dp.established_date DESC, dp.id DESC"
        ) ?: [];
    }


    public function getFromApi($data)
    {
        $options = array(
            'http'=>array(
                'method'=>"GET",
                'header'=>["auth: cHJvZHVjdGlvbjoxMjM=",
                    "User-Type: Production"]
            )
        );
        $context=stream_context_create($options);

        //$api_url = "https://franchiseatelier.com/FAPI/public/Proforma/getProformaPDFString/{$data['id_proforma']}";
        $api_url = APIPREFIXE."Proforma/getProformaPDFString/{$data['id_proforma']}";

        return file_get_contents($api_url,false, $context);
    }

//    public function getProformaNum()
//    {
//        $options = array(
//            'http'=>array(
//                'method'=>"GET",
//                'header'=>["auth: cHJvZHVjdGlvbjoxMjM=",
//                    "User-Type: Production"]
//            )
//        );
//        $context=stream_context_create($options);
//
//        //$api_url = "https://franchiseatelier.com/FAPI/public/Proforma/getProformaPDFString/{$data['id_proforma']}";
//        $api_url = APIPREFIXE."Proforma/getNextProformaNum";
//
//        $json_data = file_get_contents($api_url,false,$context);
//        return json_decode(json_encode(json_decode($json_data)), true);
//    }

    public function getProformaNum()
    {
        $sql = "SELECT 
                        CONCAT('FP-', 
                            (SELECT RIGHT(CONCAT('00000', (SELECT COUNT(doc_num)+1 FROM document_proforma WHERE DATE(NOW()) = DATE(established_date))), 5)), 
                            '/', 
                            DATE_FORMAT(NOW(), '%d'), 
                            '/', DATE_FORMAT(NOW(), '%m'), 
                            '/', YEAR(NOW())) as inv_num";
        return $this->query($sql);
    }

    public function getSavedProformaData($data)
    {
        $res_arr = [];

        $sql = "SELECT * FROM document_proforma WHERE id = :id_saved_proforma";
        $res = $this->query($sql, $data);

        $res_arr['proforma_data'] = (count($res) > 0) ? $res[0] : $res;

        $sql = "SELECT * FROM document_proforma_product WHERE id_proforma = :id_saved_proforma";
        $res_arr['proforma_product_data'] = $this->query($sql, $data);

        return $res_arr;
    }

    public function deleteProforma($data)
    {
        $sql = "DELETE FROM document_proforma WHERE id = :id_saved_proforma";
        $this->query($sql, $data);

        $sql = "DELETE FROM document_proforma_product WHERE id_proforma = :id_saved_proforma";
        $this->query($sql, $data);

        $customerOrderModel = new CustomerOrderModel();
        $customerOrderModel->update($data['id_order'], ["id_proforma" => null]);
    }

    public function insertProformaNum($data)
    {
        $apiCurl = new ApiCURL();

        $url = APIPREFIXE."Proforma/insertProformaDataFromProduction";

        $res = $apiCurl->insert(json_encode($data), $url);

        return $res['success'];
    }

    public function genProformaPDF($data)
    {

    }

    public function getGenerated()
    {
        $sql = "SELECT 
            doc.id AS id_proforma,
            doc.doc_num,
            co.id as id_order,
            cust.company,
            doc.id_customer,
            co.expected_shipping_date,
            co.id_franchisee_collector,
            doc.status AS proforma_status
            FROM document_proforma doc
            INNER JOIN customer cust
                ON doc.id_customer = cust.id
            LEFT JOIN customer_order co
                ON co.id_proforma = doc.id
            ORDER BY doc.create_timestamp DESC";
        return $this->query($sql);
    }

    public function markNotified($uuid)
    {
        return $this->update($uuid, ["notified_at" => date("Y-m-d H:i:s"), "status" => "notified"]);
    }
}
