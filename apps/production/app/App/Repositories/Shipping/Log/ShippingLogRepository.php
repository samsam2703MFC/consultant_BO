<?php
namespace App\Repositories\Shipping\Log;

use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ShippingLogRepository extends BaseRepository
{

    protected string $table = 'shipment_log';

    protected $allowedColumns = [
        'shipment_date',
        'id_customer',
        'id_wz',
        'id_cmr',
        'id_wdt',
        'id_diff',
        'id_employee'
    ];

    private $fetchAllowedColumns = [
        'id',
        'shipment_date',
        'id_customer',
        'id_wz',
        'id_cmr',
        'id_wdt',
        'id_diff',
        'id_employee',
        'insert_timestamp'
    ];

    public function getShipmentDocs($idShipment)
    {

    }

    public function newShipping($data)
    {
        $orderShippingDifferenceProductModel = new OrderShippingDifferenceProductModel();

        $res = $this->insertNewShipping($data);

        if(!$res['success']){
            $this->errors['error'] = "Nie udało się wygenerować plików";
        }

        $diff_doc_res = $orderShippingDifferenceProductModel->insertDiffProduct($res['data']);

        if(!$diff_doc_res){
            $this->errors['error'] = "Nie udało się załadować różnic produktowych";
        }

        try{

            if(!$this->sendToFranchisee($res['data']['wdt_id'])){
                $this->errors['error_franchisee_input'] = "Nie udało się przesłać danych do franczyzy";
            }
        }catch (Exception $e){

        }

        return $res;
    }

    public function sendToFranchisee($id_wdt)
    {
        $wdtModel = new WdtModel();
        $franchisee_input_data = $wdtModel->getDataForFranchiseeInput($id_wdt);

        if(!$franchisee_input_data){
            return false;
        }

        $apiCurl = new ApiCURL();
        $url = APIPREFIXE."/RelatedSupplier/newDelivery";

        $res = $apiCurl->insert(json_encode($franchisee_input_data), $url);
        return $res['success'];
    }

    public function getAll()
    {
        $sql = "SELECT 
        sl.id,
        sl.shipment_date,
        sl.id_customer,
        sl.id_wz,
        sl.id_cmr,
        sl.id_wdt,
        sl.id_diff,
        cs.company,
        spr.status AS packaging_status,
        spr.evidence_basis AS packaging_evidence_basis,
        spr.ppwr_evidence_basis,
        spr.ppwr_generated_at,
        ks.status AS ksef_status,
        ks.ksef_number,
        MAX(ks.upo_xml IS NOT NULL) AS ksef_upo_available,
        ks.error_message AS ksef_error_message,
        COUNT(CASE WHEN spi.calculation_status <> 'COMPLETE' THEN 1 END) AS packaging_issue_count
        FROM shipment_log sl
        INNER JOIN customer cs
        ON sl.id_customer = cs.id
        LEFT JOIN shipment_packaging_record spr ON spr.id_shipment_log = sl.id
        LEFT JOIN shipment_packaging_item spi ON spi.id_shipment_packaging_record = spr.id
        LEFT JOIN ksef_submission ks ON ks.id_shipment_log = sl.id
        GROUP BY sl.id, sl.shipment_date, sl.id_customer, sl.id_wz, sl.id_cmr, sl.id_wdt, sl.id_diff, cs.company, spr.status, spr.evidence_basis, spr.ppwr_evidence_basis, spr.ppwr_generated_at, ks.status, ks.ksef_number, ks.error_message
        ORDER BY shipment_date DESC, cs.company ASC";
        return $this->query($sql);
    }

}
