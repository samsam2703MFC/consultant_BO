<?php
namespace App\Services\Stock;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Stock\FinalProduct\FinalProductStockRepository;
use App\Services\Utils\TCPDFService;

class FinalProductStockStateService implements IDocument
{
    private FinalProductStockRepository $finalProductStockRepository;

    public function __construct(
        FinalProductStockRepository $finalProductStockRepository
    )
    {
        $this->finalProductStockRepository = $finalProductStockRepository;
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
            // Jedno źródło kosztu : koszt surowców karty produktu (= koszt receptury) ; bez kosztu produkt poza sumą (nigdy wartość zgadywana)
            $card_cost = (float) ($item['card_cost'] ?? 0);
            $item['cost_source'] = $card_cost <= 0 ? 'none' : (is_null($item['id_recipe']) ? 'card' : 'recipe');
            $item['single_material_cost'] = $card_cost > 0 ? $card_cost : null;
            $item['total_material_cost'] = $card_cost > 0 ? round($card_cost * $item['count_in_stock'], 2) : null;
        }
        unset($item);

        return $products_in_stock;
    }
}