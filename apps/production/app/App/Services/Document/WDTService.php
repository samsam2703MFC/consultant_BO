<?php

namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Document\Wz\WZRepository;
use App\Repositories\Ksef\KsefSubmissionRepository;
use App\Services\Ksef\KsefQrCodeService;
use App\Services\Utils\TCPDFService;

class WDTService implements IDocument
{
    private WDTRepository $wdtRepository;
    private WDTProductRepository $wdtProductRepository;
    private KsefSubmissionRepository $ksefSubmissions;
    private KsefQrCodeService $ksefQrCodes;

    public function __construct(
        WDTRepository        $wdtRepository,
        WDTProductRepository $wdtProductRepository,
        KsefSubmissionRepository $ksefSubmissions,
        KsefQrCodeService $ksefQrCodes
    )
    {
        $this->wdtRepository = $wdtRepository;
        $this->wdtProductRepository = $wdtProductRepository;
        $this->ksefSubmissions = $ksefSubmissions;
        $this->ksefQrCodes = $ksefQrCodes;
    }

    public function getById($id)
    {
        return $this->wdtRepository->first(['id' => $id]);
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {

        $pdf->setMargins(5, 5, 5);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        #region left_data
// left top side
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $data['wdtData']['consignor_name'], 0, 'L', 0, 0, 10, 25, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(55, 5, $data['wdtData']['consignor_address_1'] . "\n" . $data['wdtData']['consignor_address_2'], 0, 'L', 0, 0, 10, 30, true);

        $pdf->MultiCell(15, 5, "<b>NIP:</b>", 0, 'L', 0, 0, 10, 40, true, 0, true);
        $pdf->MultiCell(45, 5, $data['wdtData']['consignor_nip'], 0, 'L', 0, 0, '', '', true, 0, true);

        $pdf->MultiCell(15, 5, "<b>BDO:</b>", 0, 'L', 0, 0, 10, 45, true, 0, true);
        $pdf->MultiCell(45, 5, "000507840", 0, 'L', 0, 0, '', '', true, 0, true);
//end left top side
#endregion

        #region right_data
// right top side
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(15, 5, "Num:", 'B', 'L', 0, 0, 110, 25, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $data['wdtData']['doc_num'], 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->MultiCell(15, 5, "Data:", 'B', 'L', 0, 0, 110, 33, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $data['wdtData']['established_date'], 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $data['wdtData']['consignee_name'], 0, 'L', 0, 0, 110, 45, true, 0, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(85, 5, $data['wdtData']['consignee_address_1'], 0, 'L', 0, 0, 110, 50, true, 0, true);
        $pdf->MultiCell(85, 5, $data['wdtData']['consignee_address_2'], 0, 'L', 0, 0, 110, 55, true, 0, true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(85, 5, "NIP/TVA: " . $data['wdtData']['consignee_nip'], 0, 'L', 0, 0, 110, 61, true, 0, true);
// end right top side

        #endregion

        $pdf->ln(10);
        $pdf->SetTextColor(0, 0, 0);
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

        $pdf->fillWdtData($headerPL, $headerBE, $data['wdtProductData']);

        $pdf->Ln(10);
//pomocnicza komórka bo grupuje wszystkie poniższe dane w jednej linii
        $pdf->MultiCell(60, 5, "", 0, 'L', 0, 1, '', '', true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(60, 5, "Data fakturowania:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(70, 5, $data['wdtData']['invoiced_date'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->MultiCell(60, 5, "Data wysyłki:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(70, 5, $data['wdtData']['shipping_date'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->MultiCell(60, 5, "Waga łącznie:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(70, 5, $data['wdtData']['total_weight'] . " kg", 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->MultiCell(60, 5, "Numer palety:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(70, 5, $data['wdtData']['palette_nums'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();


        $pdf->MultiCell(60, 5, "Paiement Pro Forma", 0, 'L', 0, 1, '', '', true);
        $pdf->MultiCell(60, 5, "Compte en Banque:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(100, 5, $data['wdtData']['consignor_bank'], 0, 'L', 0, 1, '', '', true);
        $pdf->MultiCell(60, 5, "Code BIC Swift:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(80, 5, $data['wdtData']['consignor_swift'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->SetFont('helvetica', 'I', 6);

        $total_net = 0;
        foreach ($data['wdtProductData'] as $wdt_item) {
            $total_net += $wdt_item['total_net'];
        }
        $total_net = number_format($total_net, 2);

        $yPos = $pdf->getY();
        if ($yPos + 42 > 297) {
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
        $pdf->MultiCell(15, 3, $total_net, 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 1, '', '', true);

        $this->renderKsefQr($pdf, $data['wdtData'], $data['ksefSubmission'] ?? []);
        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $wdtData = $this->wdtRepository->first(['id' => $id]);
        if (!$wdtData) {
            throw new CustomException("WDT_NOT_FOUND", 'errors', 404);
        }

        $wdtProductData = $this->wdtProductRepository->getByIdWdt($wdtData['id']);
        if (!$wdtProductData) {
            throw new CustomException("WDT_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocument($pdf, [
            'wdtData' => $wdtData,
            'wdtProductData' => $wdtProductData,
            'ksefSubmission' => $this->ksefSubmissions->findByWdtId((int)$wdtData['id'])]);

        return ['file_string' => $pdf, "filename" => 'WDT_' . $wdtData['doc_num'] . '.pdf'];
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

    public function updateProduct($idWDT, $idProduct, $data)
    {
        $submission = $this->ksefSubmissions->findByWdtId((int)$idWDT);
        if (in_array($submission['status'] ?? null, ['submitted', 'processing', 'accepted', 'rejected'], true)) {
            throw new \RuntimeException('Nie można zmieniać pozycji WDT po przekazaniu dokumentu do KSeF. W razie potrzeby wystaw fakturę korygującą.');
        }
        return $this->wdtProductRepository->updateProduct($idWDT, $idProduct, $data);
    }

    public function isLockedForEditing(int $wdtId): bool
    {
        $submission = $this->ksefSubmissions->findByWdtId($wdtId);
        return in_array($submission['status'] ?? null, ['submitted', 'processing', 'accepted', 'rejected'], true);
    }

    public function getProductsByIdWdt($id)
    {
        return $this->wdtProductRepository->getProductsByIdWdt($id);
    }

    public function getSoldDataInLast30Days($idProduct)
    {
        return $this->wdtProductRepository->getSoldDataInLast30Days($idProduct);
    }

    /** @param array<string,mixed> $wdt @param array<string,mixed> $submission */
    private function renderKsefQr(TCPDFService $pdf, array $wdt, array $submission): void
    {
        if (($submission['status'] ?? null) !== 'accepted' || empty($submission['ksef_number']) || empty($submission['invoice_xml'])) return;
        $url = $this->ksefQrCodes->invoiceVerificationUrl((string)($submission['environment'] ?? 'test'), (string)$wdt['consignor_nip'], (string)$wdt['invoiced_date'], (string)$submission['invoice_xml']);
        if ($pdf->GetY() > 245) $pdf->AddPage();
        $y = $pdf->GetY() + 5;
        $style = ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false];
        $pdf->write2DBarcode($url, 'QRCODE,M', 155, $y, 32, 32, $style, 'N');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(42, 8, 'KSeF: ' . $submission['ksef_number'], 0, 'C', 0, 0, 150, $y + 33, true);
    }
}
