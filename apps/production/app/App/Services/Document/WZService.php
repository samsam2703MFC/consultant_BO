<?php
namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Wz\WZProductRepository;
use App\Repositories\Document\Wz\WZRepository;
use App\Services\Utils\TCPDFService;

class WZService implements IDocument
{
    private WZRepository $wzRepository;
    private WZProductRepository $wzProductRepository;

    public function __construct(
        WZRepository $wzRepository,
        WZProductRepository $wzProductRepository
    )
    {
        $this->wzRepository = $wzRepository;
        $this->wzProductRepository = $wzProductRepository;
    }


    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        #region wz
        $pdf->setMargins(5, 5, 5);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        #region left_data
// left top side
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $data['wzData']['consignor_name'], 0, 'L', 0, 0, 10, 25, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(55, 5, $data['wzData']['consignor_address_1']."\n".$data['wzData']['consignor_address_2'], 0, 'L', 0, 0, 10, 30, true);

        $pdf->MultiCell(15, 5, "<b>NIP:</b>", 0, 'L', 0, 0, 10, 40, true, 0, true);
        $pdf->MultiCell(45, 5, $data['wzData']['consignor_nip'], 0, 'L', 0, 0, '', '', true, 0, true);

        $pdf->MultiCell(15, 5, "<b>Bank:</b>", 0, 'L', 0, 0, 10, 45, true, 0, true);
        $pdf->MultiCell(65, 5, $data['wzData']['consignor_bank_1'], 0, 'L', 0, 0, '', '', true);

        $pdf->MultiCell(15, 5, "", 0, 'L', 0, 0, 10, 50, true, 0, true);
        $pdf->MultiCell(65, 5, $data['wzData']['consignor_bank_2'], 0, 'L', 0, 0, '', '', true);

        $pdf->MultiCell(15, 5, "<b>SWIFT:</b>", 0, 'L', 0, 0, 10, 55, true, 0, true);
        $pdf->MultiCell(65, 5, $data['wzData']['consignor_swift'], 0, 'L', 0, 0, '', '', true);
//end left top side
#endregion

        #region right_data
// right top side
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(15, 5, "Num:", 'B', 'L', 0, 0, 110, 25, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $data['wzData']['doc_num'], 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->MultiCell(15, 5, "Data:", 'B', 'L', 0, 0, 110, 33, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $data['wzData']['established_date'], 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $data['wzData']['consignee_name'], 0, 'L', 0, 0, 110, 45, true, 0, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(85, 5, $data['wzData']['consignee_address_1'], 0, 'L', 0, 0, 110, 50, true, 0, true);
        $pdf->MultiCell(85, 5, $data['wzData']['consignee_address_2'], 0, 'L', 0, 0, 110, 55, true, 0, true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(85, 5, "NIP/TVA: ".$data['wzData']['consignee_nip'], 0, 'L', 0, 0, 110, 61, true, 0, true);
// end right top side

        #endregion

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
        $pdf->fillWzData($header, $secHeader, $data['wzProductData']);
        $pdf->Ln(7);

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {

        $wzData = $this->wzRepository->first(['id' => $id]);
        if (!$wzData) {
            throw new CustomException("WZ_NOT_FOUND", 'errors', 404);
        }

        $wzProductData = $this->wzProductRepository->getByIdWz($id);
        if (!$wzProductData) {
            throw new CustomException("WZ_PRODUCTS_NOT_FOUND", 'errors', 404);
        }


        $this->renderDocument($pdf, ['wzData' => $wzData, 'wzProductData' => $wzProductData]);

        return ['file_string' => $pdf, "filename" => 'WZ_' . $wzData['doc_num'] . '.pdf'];
    }

    public function generateDocumentPDF(int $id): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromId($pdf, $id);

        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }
}