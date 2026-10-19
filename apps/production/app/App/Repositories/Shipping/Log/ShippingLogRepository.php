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

    /** @return list<array<string, mixed>> */
    public function getAllByDocumentType(string $documentType): array
    {
        $columns = [
            'wz' => 'id_wz',
            'cmr' => 'id_cmr',
            'wdt' => 'id_wdt',
            'difference' => 'id_diff',
        ];
        if (!isset($columns[$documentType])) {
            throw new \InvalidArgumentException('Nieobsługiwany typ dokumentu wysyłkowego.');
        }

        $column = $columns[$documentType];
        $sql = "SELECT sl.id, sl.shipment_date, sl.id_customer, sl.id_wz, sl.id_cmr, sl.id_wdt, sl.id_diff, cs.company
                FROM shipment_log sl
                INNER JOIN customer cs ON sl.id_customer = cs.id
                WHERE sl.`{$column}` IS NOT NULL AND sl.`{$column}` <> 0
                ORDER BY sl.shipment_date DESC, cs.company ASC";
        return $this->query($sql) ?: [];
    }


    public function getWdtPDF($data)
    {
        $wdtModel = new WdtModel();
        $wdtProductModel = new WdtProductModel();

        $wdt_data = $wdtModel->first(['id' => $data['id_wdt']]);

        $wdtProductModel->order_column = 'id_palette';
        $wdt_product_data = $wdtProductModel->where(["id_document_wdt" => $data['id_wdt']]);

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);

        #region wdt
        $pdf->setMargins(5, 5, 5);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        #region left_data
// left top side
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $wdt_data->consignor_name, 0, 'L', 0, 0, 10, 25, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(55, 5, $wdt_data->consignor_address_1."\n".$wdt_data->consignor_address_2, 0, 'L', 0, 0, 10, 30, true);

        $pdf->MultiCell(15, 5, "<b>NIP:</b>", 0, 'L', 0, 0, 10, 40, true, 0, true);
        $pdf->MultiCell(45, 5, $wdt_data->consignor_nip, 0, 'L', 0, 0, '', '', true, 0, true);

        $pdf->MultiCell(15, 5, "<b>BDO:</b>", 0, 'L', 0, 0, 10, 45, true, 0, true);
        $pdf->MultiCell(45, 5, "000507840", 0, 'L', 0, 0, '', '', true, 0, true);

//end left top side
#endregion

        #region right_data
// right top side
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(15, 5, "Num:", 'B', 'L', 0, 0, 110, 25, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $wdt_data->doc_num, 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->MultiCell(15, 5, "Data:", 'B', 'L', 0, 0, 110, 33, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $wdt_data->established_date, 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $wdt_data->consignee_name, 0, 'L', 0, 0, 110, 45, true, 0, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(85, 5, $wdt_data->consignee_address_1, 0, 'L', 0, 0, 110, 50, true, 0, true);
        $pdf->MultiCell(85, 5, $wdt_data->consignee_address_2, 0, 'L', 0, 0, 110, 55, true, 0, true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(85, 5, "NIP/TVA: ".$wdt_data->consignee_nip, 0, 'L', 0, 0, 110, 61, true, 0, true);
// end right top side

        #endregion

        $pdf->ln(10);
        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('helvetica', '', 12);




        $headerPL = array(
            "Palet",
            "Kod Towaru",
            "Opis towaru",
            "PKWiU",
            "Ilość",
            "Cena/kart netto",
            "Wartość netto",
            "VAT",
            "Wart./brutto",
            "Stawka VAT",
            "Waga op.",
            "Waga",
            "Il/Krt",
            "Cena/szt"
        );
        $headerBE = array(
            "Palet",
            "Ref. Article",
            "Description",
            "Code Stat.",
            "Quant.",
            "Prix carton net",
            "Total Net",
            "TVA",
            "Prix brut",
            "Taux TVA",
            "Poid cart",
            "Poid Brut",
            "Qt/Crt",
            "Prix/unit"
        );



        $pdf->fillWdtData($headerPL,$headerBE, $wdt_product_data);



        $pdf->Ln(6);
//pomocnicza komórka bo grupuje wszystkie poniższe dane w jednej linii
        $pdf->MultiCell(60, 5, "", 0, 'L', 0, 1, '','',true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(60, 5, "Data fakturowania:", 0, 'L', 0, 0, '','',true);
        $pdf->MultiCell(70, 5, $wdt_data->invoiced_date, 0, 'L', 0, 1, '','',true);
        $pdf->Ln();

        $pdf->MultiCell(60, 5, "Data wysyłki:", 0, 'L', 0, 0, '','',true);
        $pdf->MultiCell(70, 5, $wdt_data->shipping_date, 0, 'L', 0, 1, '','',true);
        $pdf->Ln();

        $pdf->MultiCell(60, 5, "Waga łącznie:", 0, 'L', 0, 0, '','',true);
        $pdf->MultiCell(70, 5, $wdt_data->total_weight." kg", 0, 'L', 0, 1, '','',true);
        $pdf->Ln();

        $pdf->MultiCell(60, 5, "Numer palety:", 0, 'L', 0, 0, '','',true);
        $pdf->MultiCell(70, 5, $wdt_data->palette_nums, 0, 'L', 0, 1, '','',true);
        $pdf->Ln();



        $pdf->MultiCell(60, 5, "Paiement Pro Forma", 0, 'L', 0, 1, '','',true);
        $pdf->MultiCell(60, 5, "Compte en Banque:", 0, 'L', 0, 0, '','',true);
        $pdf->MultiCell(100, 5, $wdt_data->consignor_bank, 0, 'L', 0, 1, '','',true);
        $pdf->MultiCell(60, 5, "Code BIC Swift:", 0, 'L', 0, 0, '','',true);
        $pdf->MultiCell(80, 5, $wdt_data->consignor_swift, 0, 'L', 0, 1, '','',true);
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'I', 6);

        $total_net = 0;
        foreach ($wdt_product_data as $wdt_item){
            $total_net += $wdt_item->total_net;
        }
        $total_net = number_format($total_net, 2);

        $yPos = $pdf->getY();
        if($yPos + 42 > 297){
            $pdf->AddPage();
        }



        $pdf->MultiCell(24, 3, "Wartosc netto", '', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3, $total_net, 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->MultiCell(24, 3, "Wartosc vat", '', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3, " -", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->MultiCell(24, 3, "Ogolem", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3,  $total_net, 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 1, '', '', true);

#endregion

        $file_name = "FAK_" . $wdt_data->doc_num . "_" . $wdt_data->consignee_name . ".pdf";

        $pdf->Output($file_name, 'D');
        //$pdf->Output('SHIPPING.pdf', 'I');
        $pdf->Output();

    }

    public function getDiffPDF($data)
    {
        $wdtModel = new WdtModel();
        $shipment_data = $this->first(['id_diff' => $data['id_diff']]);
        $orderShippingDifferenceProductModel = new OrderShippingDifferenceProductModel();

        $wdt_data = $wdtModel->first(['id' => $shipment_data->id_wdt]);
        $orderShippingDifferenceProductModel->order_column = 'product_name';
        $diff_product_data = $orderShippingDifferenceProductModel->where(['id_document_order_shipping_difference' => $data['id_diff']]);

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);

        $pdf->setMargins(15, 5, 15);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();
        $pdf->Image(ROOT."/resources/images/logo.jpg", 180, 5, 19, 26, 'JPG', '', '', false, 300, '', false, false, 0);

        $border = 0;

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->writeHTMLCell(140, 6, 15, 32, "Bonjour " . $wdt_data->consignee_name, $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->writeHTMLCell(180, 12, 15, 45, "Nous avons récemment pris connaissance des écarts entre la commande que nous avons reçue et la facture correspondante. ", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 12, 15, 58, "Nous comprenons que des erreurs peuvent survenir, mais nous tenons à régulariser la situation.", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 6, 15, 71, "Voici un récapitulatif des différences entre la commande et la facture :", $border, 'L', 0, true, 'L', true, true, 15, 'M');

        $pdf->Ln(8);
        $header_first = array(
            "",
            "",
            "Commandée",
            "Facturée",
            ""
        );

        $header_sec = array(
            "Num.",
            "Article",
            "Quantité",
            "Prix",
            "Quantité",
            "Prix",
            "Différence"
        );

        //$pdf->FillTableToMissingProductsInOrder($header, $diff_product_data);
        $summary_diff_value = $pdf->FillTableToMissingProductsInOrder($header_first, $header_sec, $diff_product_data);

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->writeHTMLCell(180, 12, 15, '', "Nous sommes conscients de l'importance de maintenir des transactions transparentes et de la satisfaction de nos clients.", $border, 1, 0, true, 'L', true, true, 15, 'M');
        $pdf->Ln();
        $pdf->writeHTMLCell(180, 6, 15, '', "Nous vous remercions de votre compréhension et de votre coopération.", $border, 1, 0, true, 'L', true, true, 15, 'M');
        $pdf->Ln(6);

        if($summary_diff_value < 0){
            $summary_diff_value = -$summary_diff_value;
            $pdf->writeHTMLCell(180, 6, 15, '', "Nou vous remboursons le montant suivant : {$summary_diff_value} €  pour que le montant payé en acompte soit adéquat avec le montant de la facture {$wdt_data->doc_num}", $border, 1, 0, true, 'L', true, true, 15, 'M');
        } else {
            $pdf->writeHTMLCell(180, 6, 15, '', "Nous vous demandons de régler la différences de {$summary_diff_value} € avec la mention Facture : <span style='background-color: #0dcaf0'>{$wdt_data->doc_num}</span>", $border, 1, 0, true, 'L', true, true, 15, 'M');
        }

        $pdf->Ln(15);
        $pdf->writeHTMLCell(180, 12, 15, '', "Cordialement,", $border, 1, 0, true, 'L', true, true, 15, 'M');
        $pdf->Ln(8);
        $pdf->writeHTMLCell(180, 12, 15, '', "ATELIER WRO01 SP. Z O.O.", $border, 1, 0, true, 'L', true, true, 15, 'M');

        $filename = "ROZNICE_" . $wdt_data->doc_num . "_" . $wdt_data->consignee_name . ".pdf";
        $pdf->Output($filename, 'D');
        //$pdf->Output('SHIPPING.pdf', 'I');
        $pdf->Output();
    }

}
