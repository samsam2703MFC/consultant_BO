<?php
namespace App\Services\Shipping;

use App\Repositories\Shipping\Complaint\ComplaintShipmentLogRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Services\Document\CMRService;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Document\WDTService;
use App\Services\Document\WZService;
use App\Services\Utils\TCPDFService;

class ComplaintDocumentService
{
    public function __construct(
        private CMRService $cmrService,
        private WZService $wzService,
        private WDTService $wdtService,
        private ComplaintShipmentLogRepository $complaintShipmentLogRepository,
        private OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository,
        private OrderShippingDifferenceService $orderShippingDifferenceService
    )
    {
    }

    public function generateDocumentsInOneFile($idShippingLog)
    {
        $shipmentData = $this->complaintShipmentLogRepository->first(['id' => $idShippingLog]);
        $wdtData = $this->wdtService->getById($shipmentData['id_wdt']);

        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        for ($i = 0; $i < WZ_PRINT_COUNT; $i++) {
            $this->wzService->buildDocumentFromId($pdf, $shipmentData['id_wz']);
        }

        for ($i = 0; $i < CMR_PRINT_COUNT; $i++) {
            $this->cmrService->buildDocumentFromId($pdf, $shipmentData['id_cmr']);
        }


        return [
            'filename' => "DOKUMENTY_DOSTAWY_ZWROT_" . $shipmentData['shipment_date'] . "_" . $wdtData['consignee_name'] . ".pdf",
            'file_string' => $pdf->Output("DOKUMENTY_DOSTAWY.pdf", 'S')
        ];
    }
}