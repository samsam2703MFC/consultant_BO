<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Product\TechnicalCardDataService;
use App\Services\Product\TechnicalCardPdfService;
use Symfony\Component\HttpFoundation\Response;

class ProductCardController extends BaseController
{
    public function __construct(
        private TechnicalCardDataService $dataService,
        private TechnicalCardPdfService  $pdfService,
    ) {
    }

    /**
     * GET /ajax/products/{idProduct}/technical-card
     * Karta techniczna produktu finalnego.
     */
    public function ajaxGetProductCard(int $idProduct): Response
    {
        $data = $this->dataService->getForProduct($idProduct);
        $pdf  = $this->pdfService->generate($data);

        return $this->buildPdfResponse($pdf);
    }

    /**
     * GET /ajax/compositions/{idRecipe}/technical-card
     * Karta techniczna kompozycji (receptury).
     */
    public function ajaxGetCompositionCard(int $idRecipe): Response
    {
        $data = $this->dataService->getForComposition($idRecipe);
        $pdf  = $this->pdfService->generate($data);

        return $this->buildPdfResponse($pdf);
    }

    // -------------------------------------------------------------------------

    private function buildPdfResponse(array $pdf): Response
    {
        $filename = htmlspecialchars($pdf['filename']);
        return new Response(
            $pdf['file_string'],
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length'      => strlen($pdf['file_string']),
            ]
        );
    }
}