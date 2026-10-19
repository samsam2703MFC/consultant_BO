<?php


namespace App\Controllers\Web\Client\Complaint;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Client\Complaint\ComplaintCollectorService;
use App\Services\Client\Complaint\ComplaintService;
use App\Services\Client\Complaint\DocumentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ComplaintController extends BaseController
{


    public function __construct(
        private ComplaintService $complaintService,
        private DocumentService $documentService,
        private ComplaintCollectorService $complaintCollectorService,
    )
    {

    }

    #[Route('GET', '/franchise-complaints')]
    public function overview()
    {
        $data['complaints'] = $this->complaintService->getComplaints();

        $this->view('/client/complaint/overview', $data);
    }

    public function pendingOverview()
    {
        $data['pending_products'] = $this->complaintService->getPendingProducts();
        $this->view('/client/complaint/pending_products', $data);
    }

    public function pendingShipmentOverview()
    {
        $data['pending_complaints'] = $this->complaintService->getPendingShipment();

        $this->view('/client/complaint/pending_shipment', $data);
    }

    public function realisation($id)
    {
        $data['pending_product_info'] = $this->complaintService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $success = $this->handleAction(function () use ($id) {
                $this->complaintService->realise($id, $_POST);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/client-complaints/pending-products');
                exit;
            }
        }

        $this->view('/client/complaint/realisation', $data);
    }

    public function shippingList()
    {
        $data['unique_shipping_date'] = $this->complaintService->getUniqueShippingDate();

        $this->view('/client/complaint/document/picking_list_overview', $data);
    }

    public function shippingDocuments()
    {
        $data['generated_docs'] = $this->complaintService->getAll();

        $this->view('/client/complaint/document/shipping_documents_overview', $data);
    }


    #[Route('PATCH', '/ajax/franchise-complaints/{id:\d+}')]
    public function ajaxUpdateFranchiseComplaint($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $resp = $this->complaintService->update($id, $data);
        if(!$resp){
            return $this->json($resp);
        }

        $respNotify = $this->complaintCollectorService->sendComplaintUpdate($id);
        return $this->json($respNotify);
    }

    public function ajaxUpdatePendingShipment($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $success = $this->handleAction(function () use ($id, $data) {
            $this->complaintService->updatePendingShipment($id, $data);
            return true;
        }, default: false);

        return $this->handleAjaxResponse($success, 'Aktualizacja powiodła się', 'Nie udało się zaktualizować');
    }

    public function ajaxGetPickingList()
    {

        if (isset($_GET['shipping_date']) && !empty($_GET['shipping_date'])) {
            $shipping_date = $_GET['shipping_date'];
        } else {
            return $this->json(['message' => 'Brak daty wysyłki'], 400);
        }

        $resp = $this->handleAction(function () use ($shipping_date) {
            return $this->documentService->generatePickingListPDF($shipping_date);
        }, default: false);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy pakowania'], 500);
        }

        $pdfData = $resp['file_string'];
        $filename = htmlspecialchars($resp['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length' => strlen($pdfData),
            ]
        );
    }

    public function ajaxGetShippingDocument($id)
    {
        $resp = $this->handleAction(function () use ($id) {
            return $this->documentService->generateShippingDocumentsPDF($id);
        }, default: false);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy pakowania'], 500);
        }

        $pdfData = $resp['file_string'];
        $filename = htmlspecialchars($resp['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length' => strlen($pdfData),
            ]
        );
    }

    public function ajaxGetPresignedUrl($id)
    {
        $link = $this->complaintCollectorService->getAttachmentPresigned($id);
        return $this->json($link);
    }

    public function ajaxGetComplaintsFromFranchise()
    {
        $result = $this->complaintCollectorService->runManualCollector();
        return $this->handleAjaxResponse($result, 'Reklamacje zaktualizowane', 'Nie udało się zaktualizować reklamacji');
    }
}