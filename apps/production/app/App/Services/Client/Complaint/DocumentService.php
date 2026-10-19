<?php
namespace App\Services\Client\Complaint;


use App\Exceptions\CustomException;
use App\Repositories\Document\Cmr\CMRPackagesRepository;
use App\Repositories\Document\Cmr\CMRRepository;
use App\Repositories\Shipping\Complaint\ComplaintShipmentLogRepository;
use App\Services\Document\CMRService;
use App\Services\Document\WZService;
use App\Services\Utils\TCPDFService;

class DocumentService
{

    public function __construct(
        private ComplaintService $complaintService,
        private ComplaintShipmentLogRepository      $complaintShipmentLogRepository,
        private CMRService $cmrService,
        private WZService $wzService
    )
    {
    }

    public function generatePickingListPDF($shippingDate)
    {
        $productListData = $this->complaintService->getGroupedProductReadyToPack($shippingDate);

        $pdf = new TCPDFService('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetMargins(5, '5', 5);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->SetFont('helvetica', '', 12);

        foreach ($productListData as $item) {
            $pdf->AddPage();

            $pdf->SetFont('helvetica', '', 10);
            $pdf->writeHTMLCell(75, 5, 5, 5, "Data wydruku:   " . date("d-m-Y"), 0, 1, 0, true, 'L', true);
            $pdf->writeHTMLCell(75, 5, 5, 10, "Data wysylki:   {$item['shipping_date']}", 0, 1, 0, true, 'L', true);

            $pdf->SetFont('helvetica', 'B', 17);
            $pdf->writeHTMLCell(160, 5, 25, 20, "REKLAMACJE", 0, 1, 0, true, 'C', true);

            $pdf->SetFont('helvetica', '', 14);
            $pdf->writeHTMLCell(160, 5, 25, 30, "{$item['customer_data']}", 0, 1, 0, true, 'C', true);

            $pdf->Ln();

            $pdf->SetFont('', 'B', 12);
            $pdf->Cell(85, 7, 'Kategoria', 1, 0, 'C');
            $pdf->Cell(85, 7, 'Produkt', 1, 0, 'C');
            $pdf->Cell(30, 7, 'Ilosc (karton)', 1, 0, 'C');


            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 9);

            $lastCategory = "";
            foreach ($item['product_data'] as $row) {
                $currCategory = $row['category_name'];
                if (strcmp($lastCategory, $currCategory) == 0) $currCategory = "";

                $pdf->Cell(85, 6, $currCategory, 'LR', 0, 'L');
                $pdf->Cell(85, 6, $row['product_name'], 'LR', 0, 'L');
                $pdf->Cell(30, 6, $row['sum'], 'LR', 0, 'C');
                $pdf->Ln();
                $lastCategory = $row['category_name'];
            }

            $pdf->Cell(200, 0, '', 'T');
        }

        $base = $pdf->Output('WYSYLKA_ZWROTY.pdf', 'D');
        $filename = "COMPLAINT_PICKING_LIST_" . date("d_m_Y");

        return [
            'filename' => $filename . '.pdf',
            'file_string' => $base
        ];
    }



    public function generateShippingDocumentsPDF($id)
    {
        $shipmentData = $this->complaintShipmentLogRepository->first(['id' => $id]);
        if( !$shipmentData) {
            throw new CustomException("SHIPPING_LOG_NOT_FOUND", 'errors', 404);
        }

        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        for ($i = 0; $i < WZ_PRINT_COUNT; $i++) {
            $this->wzService->buildDocumentFromId($pdf, $shipmentData['id_wz']);
        }

        for ($i = 0; $i < CMR_PRINT_COUNT; $i++) {
            $this->cmrService->buildDocumentFromId($pdf, $shipmentData['id_cmr']);
        }

        return [
            "file_string" => $pdf->Output('SHIPPING_complaint.pdf', 'S'),
            "filename" => 'SHIPPING_DOCS_' . $shipmentData['shipment_date'] . '.pdf'
        ];
    }
}