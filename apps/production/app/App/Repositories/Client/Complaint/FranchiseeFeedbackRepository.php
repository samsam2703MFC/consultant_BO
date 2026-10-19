<?php

namespace App\Repositories\Client\Complaint;

use App\Models\ApiCURL;
use App\Core\Helpers\BaseRepository;
use MYPDFModel;


/**
 * User class
 */
class FranchiseeFeedbackRepository extends BaseRepository
{

    protected string $table = 'franchisee_order_note_collector';

    protected $allowedColumns = [
        'franchisee_name',
        'subject',
        'id_supplier',
        'supplier_name',
        'incident_date',
        'content',
        'content_pl',
        'id_product',
        'product_name',
        'file_path',
        'file_path_2',
        'update_timestamp',
        'id_franchisee',
        'id_note',
        'id_order_note_status',
        'response'
    ];

    public function getLastUpdateDate()
    {
        $sql = "SELECT IFNULL(MAX(update_timestamp), '0000-00-00') as last_update_record_datetime FROM franchisee_order_note_collector";
        return $this->query($sql)[0];
    }

    public function getNew()
    {
        $last_inserted_date = $this->getLastUpdateDate();

        $options = array(
            'http' => array(
                'method' => "GET",
                'header' => ["auth: cHJvZHVjdGlvbjoxMjM=",
                    "User-Type: Production"]
            )
        );
        $context = stream_context_create($options);

        //$api_url = "https://franchiseatelier.com/FAPI/public/Proforma/getProformaPDFString/{$data['id_proforma']}";

        $api_url = urlencode(APIPREFIXE . "OrderFeedback/getFeedback/{$last_inserted_date->last_update_record_datetime}");
        $fixedEncodedUrl = str_replace(['%2F', '%3A'], ['/', ':'], $api_url);

        $json_data = file_get_contents($fixedEncodedUrl, false, $context);
        $convertedToArrObj = json_decode(json_encode(json_decode($json_data)), true);


        $this->insertOrderNote($convertedToArrObj);
    }

    public function insertOrderNote($data)
    {

        if (empty($data)) {
            return true;
        }

        foreach ($data as $noteItem) {
            $sql = "INSERT INTO `franchisee_order_note_collector`(
                                                    `franchisee_name`,
                                                    `subject`,
                                                    `id_supplier`,
                                                    `supplier_name`,
                                                    `incident_date`,
                                                    `content`,
                                                    `id_product`,
                                                    `product_quantity`,
                                                    `product_name`,
                                                    `file_path`,
                                                    `file_path_2`,
                                                    `update_timestamp`,
                                                    `id_franchisee`,
                                                    `id_note`,
                                                    `id_order_note_status`,
                                                    `invoice_num`
                        ) VALUES (
                                    :franchisee_name,
                                    :subject_name,
                                    :id_supplier,
                                    :supplier_name,
                                    :incident_date,
                                    :content,
                                    :id_product,
                                    :product_quantity,
                                    :product_name,
                                    :file_path,
                                    :file_path_2,
                                    :last_update,
                                    :id_franchisee,
                                    :id_note,
                                    :id_order_note_status,
                                    :invoice_num
                        ) ON DUPLICATE KEY UPDATE
                        subject = :subject_name,
                        id_supplier = :id_supplier,
                        supplier_name = :supplier_name,
                        incident_date = :incident_date,
                        content = :content,
                        id_product = :id_product,
                        product_quantity = :product_quantity,                          
                        product_name = :product_name,
                        file_path = :file_path,
                        file_path_2 = :file_path_2,
                        update_timestamp = :last_update";
            $this->query($sql, $noteItem);
        }

        return true;
    }

    public function getAll()
    {
        $sql = "SELECT 
                    fo.`id`,
                    fo.`franchisee_name`,
                    fo.`subject`,
                    fo.`id_supplier`,
                    fo.`supplier_name`,
                    fo.`incident_date`,
                    fo.`content`,
                    fo.`id_product`,
                    fo.`product_name`,
                    fo.`file_path`,
                    fo.`file_path_2`,
                    fo.`update_timestamp`,
                    fo.`id_franchisee`,
                    fo.`id_note`,
                    fo.`id_order_note_status`,
                    ons.content as status_name
                 FROM franchisee_order_note_collector fo
                 INNER JOIN order_note_status ons 
                     ON fo.id_order_note_status = ons.id
                 ORDER BY update_timestamp";
        return $this->query($sql, []);
    }

    public function getOwnLog()
    {
        $sql = "SELECT 
                    fo.`id`,
                    fo.`franchisee_name`,
                    fo.`subject`,
                    fo.`id_supplier`,
                    fo.`supplier_name`,
                    fo.`incident_date`,
                    fo.`content`,
                    fo.content_pl,
                    fo.`id_product`,
                    fo.`product_name`,
                    fo.`file_path`,
                    fo.`file_path_2`,
                    fo.`update_timestamp`,
                    fo.`id_franchisee`,
                    fo.`id_note`,
                    fo.`id_order_note_status`,
                    ons.content as status_name
                 FROM franchisee_order_note_collector fo
                 INNER JOIN order_note_status ons 
                     ON fo.id_order_note_status = ons.id
                WHERE fo.id_supplier = 1 AND fo.id_order_note_status < 3
                 ORDER BY update_timestamp";
        return $this->query($sql, []);
    }

    public function getById($data)
    {
        $sql = "SELECT 
                    fo.`id`,
                    fo.`franchisee_name`,
                    cust.company,
                    cust.id as id_customer,
                    fo.`subject`,
                    fo.`id_supplier`,
                    fo.`supplier_name`,
                    fo.`incident_date`,
                    fo.`content`,
                    fo.invoice_num,
                    fo.content_pl,
                    fo.`id_product`,
                    fo.product_quantity,
                    IFNULL(fo.`product_name`, '-') as product_name,
                    fo.`file_path`,
                    fo.`file_path_2`,
                    fo.`update_timestamp`,
                    fo.`id_franchisee`,
                    fo.`id_note`,
                    fo.`id_order_note_status`,
                    fo.response,
                    IFNULL(dwp.price_carton, 0) as single_purchase_price,
                    ons.content as status_name
                 FROM franchisee_order_note_collector fo
                 INNER JOIN order_note_status ons 
                     ON fo.id_order_note_status = ons.id
                INNER JOIN customer cust
                    ON fo.id_franchisee = cust.franchisee_id
                LEFT JOIN document_wdt dw
                    ON fo.invoice_num = dw.doc_num
                LEFT JOIN document_wdt_product dwp    
                    ON dw.id = dwp.id_document_wdt AND fo.id_product = dwp.id_product
                WHERE fo.id = :id
                GROUP BY fo.id_product";

        $res = $this->query($sql, $data);
        if (!$res) return [];

        return $res[0];
    }

    public function getOwnLogByStatus($data)
    {
        $sql = "SELECT 
                    fo.`id`,
                    fo.`franchisee_name`,
                    fo.`subject`,
                    fo.`id_supplier`,
                    fo.`supplier_name`,
                    fo.`incident_date`,
                    fo.`content`,
                    fo.content_pl,
                    fo.`id_product`,
                    fo.`product_name`,
                    fo.product_quantity,
                    fo.`file_path`,
                    fo.`file_path_2`,
                    fo.`update_timestamp`,
                    fo.`id_franchisee`,
                    fo.`id_note`,
                    fo.`id_order_note_status`,
                    fo.response,
                    ons.content as status_name
                 FROM franchisee_order_note_collector fo
                 INNER JOIN order_note_status ons 
                     ON fo.id_order_note_status = ons.id
                WHERE fo.id_supplier = 1 AND fo.id_order_note_status = :id_status
                 ORDER BY update_timestamp DESC";
        return $this->query($sql, $data);
    }


    public function updateFranchisee($data)
    {
        $data_for_update = $this->getIdNoteWithStatusById($data)[0];
        $apiCurl = new ApiCURL();
        $url = APIPREFIXE . "/OrderFeedback/update/{$data_for_update->id}";

        unset($data_for_update->id);


        $res = $apiCurl->update(json_encode($data_for_update), $url);
        return $res['success'];
    }

    public function getIdNoteWithStatusById($data)
    {
        $sql = "SELECT id_order_note_status, response as supplier_response, id_note as id FROM franchisee_order_note_collector WHERE id = :id";
        return $this->query($sql, $data);
    }

    public function getStatusName($data)
    {
        $sql = "SELECT ons.content as status_name FROM order_note_status ons WHERE id = :id_status";
        return $this->query($sql, $data)[0];
    }

    public function getPDF($data)
    {
        $feedbackData = $this->getById($data);

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);

        $pdf->setMargins(5, 5, 5);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->MultiCell(200, 5, "Reklamacja klienta", 0, 'C', 0, 0, 5, 10, true);


        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Klient:", 0, 'L', 0, 0, 10, 35, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(100, 5, $feedbackData->company, 0, 'L', 0, 0, 55, 35, true);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Data zdarzenia:", 0, 'L', 0, 0, 10, 45, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(100, 5, $feedbackData->incident_date, 0, 'L', 0, 0, 55, 45, true);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Produkt:", 0, 'L', 0, 0, 10, 55, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(100, 5, $feedbackData->product_name, 0, 'L', 0, 0, 55, 55, true);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Ilość:", 0, 'L', 0, 0, 10, 65, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(100, 5, $feedbackData->product_quantity, 0, 'L', 0, 0, 55, 65, true);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Opis (BE):", 0, 'L', 0, 0, 10, 75, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(150, 40, $feedbackData->content, 1, 'L', 0, 0, 55, 75, true);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Opis (PL):", 0, 'L', 0, 0, 10, 120, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(150, 40, $feedbackData->content_pl, 1, 'L', 0, 0, 55, 120, true);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(45, 5, "Inne uwagi:", 0, 'L', 0, 0, 10, 165, true);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(150, 25, "", 1, 'L', 0, 0, 55, 165, true);

        //$pdf->Image(ATELIER_RESOURCES_PREFIXE.$feedbackData->file_path, 15, 140, 75, 113, '', '', true, 150, '', false, false, 1, false, false, false);
        $pdf->SetXY(110, 200);
        $pdf->Image(ATELIER_RESOURCES_PREFIXE . $feedbackData->file_path, 10, 200, 90, 70, '', '', 'T', false, 300, '', false, false, 1, false, false, false);
        $pdf->Image(ATELIER_RESOURCES_PREFIXE . $feedbackData->file_path_2, 110, 200, 90, 70, '', '', 'T', false, 300, '', false, false, 1, false, false, false);

        $pdf->Output('REKLAMACJA.pdf', 'D');
        //$pdf->Output('INVOICE.pdf', 'I');
        //$pdf->Output();

    }
}