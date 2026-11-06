<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Shipping\Utils\MissingProductReasonService;
use App\Services\Stock\FinalProductStockStateService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StockDocumentController extends BaseController
{

    private FinalProductStockStateService $finalProductStockStateService;

    public function __construct(
        FinalProductStockStateService $finalProductStockStateService
    )
    {
        $this->finalProductStockStateService = $finalProductStockStateService;
    }

    public function ajaxGenerateDifferenceDocument()
    {
        $respDoc = $this->handleAction(function () {
            return $this->finalProductStockStateService->generateDocumentPDF();
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