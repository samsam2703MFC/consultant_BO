<?php
namespace App\Controllers\Web\Shipping;

use App\Core\Web\BaseController;
use App\Services\Carrier\ProductCarrierService;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Document\WDTService;
use App\Services\Shipping\ComplaintDocumentService;
use App\Services\Shipping\Pallet\PalletDeliveryService;
use App\Services\Shipping\ShippingDocumentService;
use App\Services\Shipping\ShipmentPackagingPdfService;
use App\Services\Shipping\ShipmentPackagingService;
use App\Services\Shipping\ShipmentPpwrPdfService;
use App\Services\Shipping\ShippingService;
use App\Exceptions\CustomException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends BaseController
{
    public function __construct(
        private PalletDeliveryService $palletDeliveryService,
        private ProductCarrierService $productCarrierRepository,
        private ShippingService $shippingService,
        private ShippingDocumentService $shippingDocumentService,
        private ShipmentPackagingPdfService $shipmentPackagingPdfService,
        private ShipmentPackagingService $shipmentPackagingService,
        private ShipmentPpwrPdfService $shipmentPpwrPdfService,
        private ComplaintDocumentService $complaintDocumentService,
        private WDTService $WDTService,
        private OrderShippingDifferenceService $orderShippingDifferenceService
    )
    {
    }


    public function showShippingForm()
    {
        $data['dates'] = $this->palletDeliveryService->getDistinctDateWithoutDocument();
        $data['complaint_dates'] = $this->palletDeliveryService->getDistinctDateWithoutDocumentForComplaint();
        $data['carriers'] = $this->productCarrierRepository->getActive();

        $this->view('shipping/document/shipping_form', $data);
    }





    public function ajaxGetDocuments($id)
    {
        $respDoc = $this->safeFetch([$this->shippingDocumentService, 'generateDocumentsInOneFile'], $this->errors, $id);


        if (!$respDoc) {
            // jeżeli coś poszło nie tak, zwracamy JSON z kodem błędu
            return $this->json($this->errors, 500);
        }

        $pdfData  = $respDoc['file_string'];
        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    public function ajaxGetComplaintDocuments($id)
    {
        $respDoc = $this->safeFetch([$this->complaintDocumentService, 'generateDocumentsInOneFile'], $this->errors, $id);


        if (!$respDoc) {
            // jeżeli coś poszło nie tak, zwracamy JSON z kodem błędu
            return $this->json($this->errors, 500);
        }

        $pdfData  = $respDoc['file_string'];
        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    public function ajaxGetPackagingDocument($id)
    {
        $document = $this->safeFetch([$this->shipmentPackagingPdfService, 'generateDocumentPDF'], $this->errors, (int)$id);
        if (!$document) {
            return $this->json($this->errors, 500);
        }

        return new Response($document['file_string'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . htmlspecialchars($document['filename']) . '"',
            'Content-Length' => strlen($document['file_string']),
        ]);
    }

    public function ajaxCreateCurrentPackagingEvidence($id)
    {
        try {
            $result = $this->shipmentPackagingService->createCurrentDataEvidence((int)$id);

            return $this->json([
                'data' => $result,
                'message' => $result['created']
                    ? 'Utworzono ewidencję BDO na podstawie bieżących danych.'
                    : 'Dla tej wysyłki ewidencja BDO już istnieje.',
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string)$exception);
            return $this->json(['message' => 'Nie udało się utworzyć ewidencji BDO.'], 500);
        }
    }

    public function ajaxCreateCurrentPpwrEvidence($id)
    {
        try {
            $result = $this->shipmentPackagingService->createCurrentPpwrEvidence((int)$id);
            return $this->json([
                'data' => $result,
                'message' => $result['reconstructed']
                    ? 'Przygotowano raport PPWR z danych bieżących.'
                    : 'Raport PPWR korzysta z zapisanego snapshotu wysyłki.',
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string)$exception);
            return $this->json(['message' => 'Nie udało się przygotować danych PPWR dla wysyłki.'], 500);
        }
    }

    public function ajaxGetPpwrDocument($id)
    {
        try {
            $document = $this->shipmentPpwrPdfService->generateDocumentPDF((int)$id);
            return new Response($document['file_string'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . htmlspecialchars($document['filename']) . '"',
                'Content-Length' => strlen($document['file_string']),
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        }
    }

    public function ajaxGetMissingPackagingItems($id)
    {
        try {
            return $this->json([
                'data' => $this->shipmentPackagingService->getMissingItemsForShipment((int)$id),
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string)$exception);
            return $this->json(['message' => 'Nie udało się pobrać brakujących danych BDO.'], 500);
        }
    }

    public function ajaxUpdateMissingPackagingItem($shipmentId, $itemId)
    {
        try {
            $payload = $this->getJson(Request::createFromGlobals());
            $result = $this->shipmentPackagingService->updateMissingItemForShipment(
                (int)$shipmentId,
                (int)$itemId,
                $payload
            );

            return $this->json([
                'data' => $result,
                'message' => $result['record_status'] === 'GENERATED'
                    ? 'Dane BDO są kompletne dla tej wysyłki.'
                    : 'Dane pozycji zostały zapisane. W ewidencji pozostają jeszcze braki.',
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string)$exception);
            return $this->json(['message' => 'Nie udało się zapisać danych BDO.'], 500);
        }
    }




    
}
