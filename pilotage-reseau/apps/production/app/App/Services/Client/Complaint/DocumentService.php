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

        // Jeden klient = jeden dokument (własna numeracja stron) ; kategorie jako wiersze grup, kolumna do odhaczenia
        foreach ($productListData as $item) {
            $pdf->brandPage('Reklamacje — lista kompletacyjna', ['ref' => (string) $item['customer_data']]);
            $pdf->brandHeader('Reklamacje', [
                ['Data wysyłki', (string) $item['shipping_date']],
                ['Data wydruku', date("d-m-Y")],
            ], (string) $item['customer_data']);

            $rows = [];
            $lastCategory = null;
            foreach ($item['product_data'] as $row) {
                if ($row['category_name'] !== $lastCategory) {
                    $rows[] = ['_group' => $row['category_name']];
                    $lastCategory = $row['category_name'];
                }
                $rows[] = [$row['product_name'], $row['sum'], ''];
            }
            $pdf->brandTable([
                ['label' => 'Produkt', 'w' => 76],
                ['label' => 'Ilość (karton)', 'w' => 16, 'align' => 'R'],
                ['label' => '✓', 'w' => 8, 'align' => 'C'],
            ], $rows, ['font' => 9, 'head_font' => 8, 'min_h' => 6.5]);
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