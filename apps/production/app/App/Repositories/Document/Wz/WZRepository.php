<?php
namespace App\Repositories\Document\Wz;

use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class WZRepository extends BaseRepository
{

    protected string $table = 'document_wz';

    protected $allowedColumns = [
        'doc_num', 
        'id_customer', 
        'consignor_name', 
        'consignor_address_1', 
        'consignor_address_2', 
        'consignee_name', 
        'consignee_address_1', 
        'consignee_address_2', 
        'consignor_nip', 
        'consignor_bank_1', 
        'consignor_bank_2', 
        'consignor_swift', 
        'consignee_nip', 
        'established_date'
    ];

    public function insertSelect($data)
    {
        $sql = "INSERT INTO `document_wz`(
                          `doc_num`, 
                          `id_customer`, 
                          `consignor_name`, 
                          `consignor_address_1`, 
                          `consignor_address_2`, 
                          `consignee_name`, 
                          `consignee_address_1`, 
                          `consignee_address_2`, 
                          `consignor_nip`, 
                          `consignor_bank_1`, 
                          `consignor_bank_2`, 
                          `consignor_swift`, 
                          `consignee_nip`, 
                          `established_date`) 
                     SELECT 
                        	CONCAT('WZ-0', (SELECT COUNT(doc_num)+1 FROM document_wz), '-', DATE_FORMAT(NOW(), '%d/%m/%Y')),  
                        	:id_customer,
                        	:consignor_name, 
                            :consignor_address_1, 
                            :consignor_address_2, 
                            :consignee_name, 
                            :consignee_address_1, 
                            :consignee_address_2, 
                            :consignor_nip, 
                            :consignor_bank_1, 
                            :consignor_bank_2, 
                            :consignor_swift, 
                            :consignee_nip, 
                            :established_date";
        return $this->customInsert($sql, $data);
    }

    public function getPDF($data)
    {
        $wzProductModel = new WzProductModel();

        $wz_data = $this->first(['id' => $data['id_wz']]);
        $wzProductModel->order_column = 'name';
        $wz_product_data = $wzProductModel->where(["id_document_wz" => $data['id_wz']]);

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(5, 5, 5);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        #region left_data
// left top side
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $wz_data->consignor_name, 0, 'L', 0, 0, 10, 25, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(55, 5, $wz_data->consignor_address_1."\n".$wz_data->consignor_address_2, 0, 'L', 0, 0, 10, 30, true);

        $pdf->MultiCell(15, 5, "<b>NIP:</b>", 0, 'L', 0, 0, 10, 40, true, 0, true);
        $pdf->MultiCell(45, 5, $wz_data->consignor_nip, 0, 'L', 0, 0, '', '', true, 0, true);

        $pdf->MultiCell(15, 5, "<b>Bank:</b>", 0, 'L', 0, 0, 10, 45, true, 0, true);
        $pdf->MultiCell(65, 5, $wz_data->consignor_bank_1, 0, 'L', 0, 0, '', '', true);

        $pdf->MultiCell(15, 5, "", 0, 'L', 0, 0, 10, 50, true, 0, true);
        $pdf->MultiCell(65, 5, $wz_data->consignor_bank_2, 0, 'L', 0, 0, '', '', true);

        $pdf->MultiCell(15, 5, "<b>SWIFT:</b>", 0, 'L', 0, 0, 10, 55, true, 0, true);
        $pdf->MultiCell(65, 5, $wz_data->consignor_swift, 0, 'L', 0, 0, '', '', true);
//end left top side
#endregion

// right top side
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(15, 5, "Num:", 'B', 'L', 0, 0, 110, 25, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $wz_data->doc_num, 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->MultiCell(15, 5, "Data:", 'B', 'L', 0, 0, 110, 33, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $wz_data->established_date, 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $wz_data->consignee_name, 0, 'L', 0, 0, 110, 45, true, 0, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(85, 5, $wz_data->consignee_address_1, 0, 'L', 0, 0, 110, 50, true, 0, true);
        $pdf->MultiCell(85, 5, $wz_data->consignee_address_2, 0, 'L', 0, 0, 110, 55, true, 0, true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(85, 5, "NIP/TVA: ".$wz_data->consignee_nip, 0, 'L', 0, 0, 110, 61, true, 0, true);
// end right top side

        $pdf->ln();
        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('helvetica', '', 12);

        $header = [
            'Ref. Article',
            'Description',
            'Code Stat.',
            'Quantite',
            'Poid Net',
            'Poid Brut',
            'Qt/Crt',

        ];
        $secHeader = [
            'Kod towaru',
            'Opis towaru',
            'PKWiU',
            'Ilość',
            'Waga jed.',
            'Waga',
            'Ilość/karton',
        ];


        $pdf->Ln(10);
        $pdf->fillWzData($header, $secHeader, $wz_product_data);
        $pdf->Ln(7);

        $base = $pdf->Output('WZ.pdf', 'S');
        $filename = "WZ_".date("d_m_Y");

        $documentModel = new DocumentModel();
        $documentModel->saveDocument($filename, $base);
    }

}