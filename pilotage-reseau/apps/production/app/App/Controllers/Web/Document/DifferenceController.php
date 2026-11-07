<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Shipping\Utils\MissingProductReasonService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DifferenceController extends BaseController
{

    private OrderShippingDifferenceService $orderShippingDifferenceService;
    private MissingProductReasonService $missingProductReasonService;

    public function __construct(
        OrderShippingDifferenceService $orderShippingDifferenceService,
        MissingProductReasonService $missingProductReasonService
    )
    {
        $this->orderShippingDifferenceService = $orderShippingDifferenceService;
        $this->missingProductReasonService = $missingProductReasonService;
    }

    public function productOverview($id)
    {
        $data['rows'] = $this->orderShippingDifferenceService->getProductsByIdDifference($id);
        $data['missing_reasons'] = $this->missingProductReasonService->getAll();

        $this->view("document/difference/edit_comment", $data);
    }

    public function ajaxUpdateProduct($id, $idProduct)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->orderShippingDifferenceService->updateProduct($id, $idProduct, $data);

        return $this->handleAjaxResponse($result, 'Cena zaktualizowana', 'Nie udało się zaktualizować ceny');
    }

    public function ajaxGenerateDifferenceDocument($id)
    {
        $respDoc = $this->handleAction(function () use ($id) {
            return $this->orderShippingDifferenceService->generateDocumentPDF($id);
        }, default: false);


        if (!$respDoc) {
            return $this->json($respDoc, $respDoc['code']);
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
}