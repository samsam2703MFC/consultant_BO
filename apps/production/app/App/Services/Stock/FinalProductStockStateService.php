<?php
namespace App\Services\Stock;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Product\Recipe\RecipePreparationRepository;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Repositories\Stock\FinalProduct\FinalProductStockRepository;
use App\Services\Recipe\RecipeService;
use App\Services\Utils\TCPDFService;

class FinalProductStockStateService implements IDocument
{
    private FinalProductStockRepository $finalProductStockRepository;
    private RecipeService $recipeService;

    public function __construct(
        FinalProductStockRepository $finalProductStockRepository,
        RecipeService $recipeService
    )
    {
        $this->finalProductStockRepository = $finalProductStockRepository;
        $this->recipeService = $recipeService;
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {

//        $headerData = ["header_title" => "Stan magazynowy", "header_subtitle" => date('d-m-Y')];
        $headerData = ["header_title" => "Stan magazynowy", "header_subtitle" => "........................"];
        $header = array(
            "Produkt",
            "Ilość kartonów",
            "Wartość");

        $pdf->AddPage();
        $pdf->customHeader($headerData);
        $pdf->setPrintFooter(false);
//        $pdf->customFooter(["info_1" => ""]);
        $pdf->setMargins(1, 1, 1);

        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('helvetica', '', 12);

        $pdf->Ln(10);
        $pdf->fillStockList($header, $data['productData']);
        $pdf->Ln(7);

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $this->renderDocument($pdf, [
            'productData' => $this->getCurrentStockWithValue()
        ]);

        return ['file_string' => $pdf, "filename" => 'STOCK_LIST_' . date("d_m_Y") . '.pdf'];
    }

    public function generateDocumentPDF($id = -1): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromId($pdf, $id);

        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }

    public function getCurrentStockWithValue()
    {
        $products_in_stock = $this->finalProductStockRepository->getProductsInStock();
        if(empty($products_in_stock)) return [];

        foreach ($products_in_stock as &$item){
            // Bez receptury : koszt surowców kartonu z karty produktu ; bez niego produkt poza sumą (nigdy wartość zgadywana)
            if(is_null($item['id_recipe'])){
                $card_cost = (float) ($item['card_cost'] ?? 0);
                $item['cost_source'] = $card_cost > 0 ? 'card' : 'none';
                $item['single_material_cost'] = $card_cost > 0 ? $card_cost : null;
                $item['total_material_cost'] = $card_cost > 0 ? round($card_cost * $item['count_in_stock'], 2) : null;
                continue;
            }

            $calculation_data = $this->recipeService->getRecipeSummary($item['id_recipe']);
            $item['cost_source'] = 'recipe';
            $item['single_material_cost'] = $calculation_data['total_ingredient_cost'];
            $item['total_material_cost'] = round($calculation_data['total_ingredient_cost'] * $item['count_in_stock'], 2);
        }
        unset($item);

        return $products_in_stock;
    }
}