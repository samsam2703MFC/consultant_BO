<?php
namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Services\Utils\TCPDFService;

class OrderShippingDifferenceService implements IDocument
{

    private OrderShippingDifferenceRepository $orderShippingDifferenceRepository;
    private OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository;
    private WDTRepository $wdtRepository;
    private ShippingLogRepository $shippingLogRepository;

    public function __construct(
        OrderShippingDifferenceRepository $orderShippingDifferenceRepository,
        OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository,
        WDTRepository $wdtRepository,
        ShippingLogRepository $shippingLogRepository
    ) {
        $this->orderShippingDifferenceRepository = $orderShippingDifferenceRepository;
        $this->orderShippingDifferenceProductRepository = $orderShippingDifferenceProductRepository;
        $this->wdtRepository = $wdtRepository;
        $this->shippingLogRepository = $shippingLogRepository;
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $pdf->setMargins(15, 5, 15);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();
        $pdf->Image(ROOT."/resources/images/logo.jpg", 180, 5, 19, 26, 'JPG', '', '', false, 300, '', false, false, 0);

        $border = 0;

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->writeHTMLCell(140, 6, 15, 32, "Bonjour " . $data['wdtData']['consignee_name'], $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->writeHTMLCell(180, 12, 15, 45, "Nous avons récemment pris connaissance des écarts entre la commande que nous avons reçue et la facture correspondante. ", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 12, 15, 58, "Nous comprenons que des erreurs peuvent survenir, mais nous tenons à régulariser la situation.", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 6, 15, 71, "Voici un récapitulatif des différences entre la commande et la facture :", $border, 'L', 0, true, 'L', true, true, 15, 'M');

        $pdf->Ln(5);
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
        $summaryDiffCents = $pdf->FillTableToMissingProductsInOrder($header_first, $header_sec, $data['diffProductData']);
        $summaryDiffValue = DifferenceAmountCalculator::formatCents($summaryDiffCents);

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->writeHTMLCell(180, 12, 15, '', "Nous sommes conscients de l'importance de maintenir des transactions transparentes et de la satisfaction de nos clients.", $border, 1, 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 6, 15, '', "Nous vous remercions de votre compréhension et de votre coopération.", $border, 1, 0, true, 'L', true, true, 15, 'M');
        $pdf->Ln(5);

        if($summaryDiffCents < 0){
            $pdf->writeHTMLCell(180, 6, 15, '', "Nou vous remboursons le montant suivant : {$summaryDiffValue} €  pour que le montant payé en acompte soit adéquat avec le montant de la facture {$data['wdtData']['doc_num']}", $border, 1, 0, true, 'L', true, true, 15, 'M');
        } else {
            $pdf->writeHTMLCell(180, 6, 15, '', "Nous vous demandons de régler la différences de {$summaryDiffValue} € avec la mention Facture : <span style='background-color: #0dcaf0'>{$data['wdtData']['doc_num']}</span>", $border, 1, 0, true, 'L', true, true, 15, 'M');
        }

        $pdf->Ln(10);
        $pdf->writeHTMLCell(180, 12, 15, '', "Cordialement,", $border, 1, 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 12, 15, '', "ATELIER WRO01 SP. Z O.O.", $border, 1, 0, true, 'L', true, true, 15, 'M');

        return  $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $differenceData = $this->orderShippingDifferenceRepository->first(['id' => $id]);
        if (!$differenceData) {
            throw new CustomException("DIFFERENCE_DATA_NOT_FOUND", 'errors', 404);
        }

        $diffProductData = $this->orderShippingDifferenceProductRepository->getAllByDifferenceId($id);

        $log = $this->shippingLogRepository->first(['id_diff' => $id]);
        if (!$log) {
            throw new CustomException("SHIPPING_LOG_NOT_FOUND", 'errors', 404);
        }

        $wdtData = $this->wdtRepository->first(['id' => $log['id_wdt']]);
        if (!$wdtData) {
            throw new CustomException("WDT_DATA_NOT_FOUND", 'errors', 404);
        }


        $this->renderDocument($pdf, [
            'differenceData' => $differenceData,
            'diffProductData' => $diffProductData,
            'wdtData' => $wdtData
        ]);

        return ['file_string' => $pdf, "filename" => 'ROZNICE_' . $wdtData['doc_num'] . '.pdf'];
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

    public function getProductsByIdDifference($id)
    {
        return $this->orderShippingDifferenceProductRepository->getAllByDifferenceId($id);
    }

    public function updateProduct($id, $idProduct, $data)
    {
        return $this->orderShippingDifferenceProductRepository->update($id, $idProduct, $data);
    }
}
