<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Services\Document\WDTService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WDTController extends BaseController
{
    private WDTService $WDTService;

    public function __construct(WDTService $WDTService)
    {
        $this->WDTService = $WDTService;
    }

    public function productOverview($id)
    {
        $data['id_wdt'] = $id;
        $data['rows'] = $this->WDTService->getProductsByIdWdt($id);
        $data['isLocked'] = $this->WDTService->isLockedForEditing((int)$id);

        $this->view("document/wdt/edit_products", $data);
    }

    public function ajaxUpdateProduct($id, $idProduct)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        try {
            $result = $this->WDTService->updateProduct($id, $idProduct, $data);
        } catch (\Throwable $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->handleAjaxResponse($result, 'Cena zaktualizowana', 'Nie udało się zaktualizować ceny');
    }

    public function ajaxGenerateWDTDocument($id)
    {
        $respDoc = $this->handleAction(function () use ($id) {
            return $this->WDTService->generateDocumentPDF($id);
        }, default: false);

        if (!$respDoc || !is_array($respDoc)) {
            return $this->json(['error' => implode('; ', $this->errors)], 500);
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
