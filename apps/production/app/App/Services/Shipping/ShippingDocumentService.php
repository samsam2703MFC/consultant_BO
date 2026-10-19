<?php
namespace App\Services\Shipping;

use App\Exceptions\CustomException;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Services\Document\CMRService;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Document\WDTService;
use App\Services\Document\WZService;
use App\Services\Utils\TCPDFService;

class ShippingDocumentService
{

    private CMRService $cmrService;
    private WZService $wzService;
    private WDTService $wdtService;
    private OrderShippingDifferenceService $orderShippingDifferenceService;
    private OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository;
    private ShippingLogRepository $shippingLogRepository;
    private ShipmentPpwrPdfService $shipmentPpwrPdfService;

    public function __construct(
        CMRService $cmrService,
        WZService $wzService,
        WDTService $wdtService,
        ShippingLogRepository $shippingLogRepository,
        OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository,
        OrderShippingDifferenceService $orderShippingDifferenceService,
        ShipmentPpwrPdfService $shipmentPpwrPdfService
    )
    {
        $this->cmrService = $cmrService;
        $this->wzService = $wzService;
        $this->wdtService = $wdtService;
        $this->shippingLogRepository = $shippingLogRepository;
        $this->orderShippingDifferenceProductRepository = $orderShippingDifferenceProductRepository;
        $this->orderShippingDifferenceService = $orderShippingDifferenceService;
        $this->shipmentPpwrPdfService = $shipmentPpwrPdfService;
    }

    public function generateDocumentsInOneFile($idShippingLog)
    {
        $shipmentData = $this->shippingLogRepository->first(['id' => $idShippingLog]);
        $diffProductData = $this->orderShippingDifferenceProductRepository->getAllByDifferenceId($shipmentData['id_diff']);
        $wdtData = $this->wdtService->getById($shipmentData['id_wdt']);

        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        for ($i = 0; $i < WZ_PRINT_COUNT; $i++) {
            $this->wzService->buildDocumentFromId($pdf, $shipmentData['id_wz']);
        }


        for ($i = 0; $i < CMR_PRINT_COUNT; $i++) {
            $this->cmrService->buildDocumentFromId($pdf, $shipmentData['id_cmr']);
        }


        for ($i = 0; $i < WDT_PRINT_COUNT; $i++) {
            $this->wdtService->buildDocumentFromId($pdf, $shipmentData['id_wdt']);
        }

        try {
            $this->shipmentPpwrPdfService->buildDocumentFromShipment($pdf, (int)$idShippingLog);
        } catch (CustomException $exception) {
            if ($exception->getMessage() !== 'SHIPMENT_PACKAGING_NOT_FOUND') {
                throw $exception;
            }
        }

        if ($diffProductData) {
            for ($i = 0; $i < DIFF_PRINT_COUNT; $i++) {
                $this->orderShippingDifferenceService->buildDocumentFromId($pdf, $shipmentData['id_diff']);
            }
        }

        return [
            'filename' => "DOKUMENTY_DOSTAWY_" . $shipmentData['shipment_date'] . "_" . $wdtData['consignee_name'] . ".pdf",
            'file_string' => $pdf->Output("DOKUMENTY_DOSTAWY.pdf", 'S')
        ];
    }
}
