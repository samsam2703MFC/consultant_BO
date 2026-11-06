<?php
namespace App\Services\Shipping;

use App\Exceptions\CustomException;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Services\Communication\DocumentDistributionService;
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
    private DocumentDistributionService $distribution;

    public function __construct(
        CMRService $cmrService,
        WZService $wzService,
        WDTService $wdtService,
        ShippingLogRepository $shippingLogRepository,
        OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository,
        OrderShippingDifferenceService $orderShippingDifferenceService,
        ShipmentPpwrPdfService $shipmentPpwrPdfService,
        DocumentDistributionService $distribution
    )
    {
        $this->cmrService = $cmrService;
        $this->wzService = $wzService;
        $this->wdtService = $wdtService;
        $this->shippingLogRepository = $shippingLogRepository;
        $this->orderShippingDifferenceProductRepository = $orderShippingDifferenceProductRepository;
        $this->orderShippingDifferenceService = $orderShippingDifferenceService;
        $this->shipmentPpwrPdfService = $shipmentPpwrPdfService;
        $this->distribution = $distribution;
    }

    /**
     * Plik dostawy (« DOKUMENTY_DOSTAWY ») : tylko papier, który jedzie z towarem, w liczbie egzemplarzy z Dystrybucji
     * dokumentów klienta ; resztę wysyłają e-maile automatyczne. $paperOnly = false : komplet jak dawniej (WZ ×3, CMR ×3…).
     */
    public function generateDocumentsInOneFile($idShippingLog, bool $paperOnly = true)
    {
        $shipmentData = $this->shippingLogRepository->first(['id' => $idShippingLog]);
        if (!$shipmentData) throw new CustomException('SHIPMENT_NOT_FOUND', 'errors', 404);
        $wdtData = $this->wdtService->getById($shipmentData['id_wdt']);

        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);
        $this->buildPaper($pdf, $shipmentData, $paperOnly);
        if ($pdf->getNumPages() === 0) $this->emptyNote($pdf, (string) ($wdtData['consignee_name'] ?? ''));

        return [
            'filename' => "DOKUMENTY_DOSTAWY_" . $shipmentData['shipment_date'] . "_" . $wdtData['consignee_name'] . ".pdf",
            'file_string' => $pdf->Output("DOKUMENTY_DOSTAWY.pdf", 'S')
        ];
    }

    /** Wszystkie wysyłki dnia w jednym pliku dla kierowców (tylko papier klientów). */
    public function generateDayFile(string $date): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);
        foreach ($this->shippingLogRepository->query('SELECT * FROM shipment_log WHERE shipment_date = :d ORDER BY id', ['d' => $date]) as $shipment) {
            $this->buildPaper($pdf, $shipment, true);
        }
        if ($pdf->getNumPages() === 0) $this->emptyNote($pdf, $date);
        return ['filename' => 'DOKUMENTY_DLA_KIEROWCOW_' . $date . '.pdf', 'file_string' => $pdf->Output('', 'S')];
    }

    /** @return array<string, int> wydrukowane egzemplarze (wz, cmr, wdt, ppwr, diff) */
    public function buildPaper(TCPDFService $pdf, array $shipmentData, bool $paperOnly = true): array
    {
        $copies = $paperOnly
            ? $this->distribution->paperCopies((int) $shipmentData['id_customer'])
            : ['wz' => WZ_PRINT_COUNT, 'cmr' => CMR_PRINT_COUNT, 'wdt' => WDT_PRINT_COUNT, 'ppwr' => 1, 'diff' => DIFF_PRINT_COUNT];
        $printed = ['wz' => 0, 'cmr' => 0, 'wdt' => 0, 'ppwr' => 0, 'diff' => 0];

        for ($i = 0; $i < ($copies['wz'] ?? 0); $i++, $printed['wz']++) {
            $this->wzService->buildDocumentFromId($pdf, $shipmentData['id_wz']);
        }
        for ($i = 0; $shipmentData['id_cmr'] && $i < ($copies['cmr'] ?? 0); $i++, $printed['cmr']++) {
            $this->cmrService->buildDocumentFromId($pdf, $shipmentData['id_cmr']);
        }
        for ($i = 0; $i < ($copies['wdt'] ?? 0); $i++, $printed['wdt']++) {
            $this->wdtService->buildDocumentFromId($pdf, $shipmentData['id_wdt']);
        }
        try {
            for ($i = 0; $i < ($copies['ppwr'] ?? 0); $i++, $printed['ppwr']++) {
                $this->shipmentPpwrPdfService->buildDocumentFromShipment($pdf, (int) $shipmentData['id']);
            }
        } catch (CustomException $exception) {
            if ($exception->getMessage() !== 'SHIPMENT_PACKAGING_NOT_FOUND') {
                throw $exception;
            }
        }
        if (($copies['diff'] ?? 0) > 0 && $this->orderShippingDifferenceProductRepository->getAllByDifferenceId($shipmentData['id_diff'])) {
            for ($i = 0; $i < $copies['diff']; $i++, $printed['diff']++) {
                $this->orderShippingDifferenceService->buildDocumentFromId($pdf, $shipmentData['id_diff']);
            }
        }
        return $printed;
    }

    private function emptyNote(TCPDFService $pdf, string $ref): void
    {
        $pdf->brandPage('Dokumenty dostawy', ['ref' => $ref]);
        $pdf->brandHeader('Dokumenty dostawy', [], $ref);
        $pdf->brandNote('Brak dokumentów papierowych : według « Dystrybucji dokumentów » (Komunikacja) ten klient dostaje wszystkie dokumenty e-mailem.');
    }
}
