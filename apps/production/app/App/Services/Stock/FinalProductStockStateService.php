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
        $pdf->brandPage('Stan magazynowy', ['ref' => date('d.m.Y')]);
        // data stanu wpisywana ręcznie przy liczeniu (wydruk przed inwentaryzacją)
        $pdf->brandHeader('Stan magazynowy', [
            ['Data stanu', '......................'],
            ['Wydruk', date('d.m.Y H:i')],
        ], 'Produkty gotowe w magazynie i wartość surowców');

        $zl = fn($v) => number_format((float) $v, 2, ',', ' ') . ' zł';
        $rows = [];
        $total = 0.0;
        $fromCard = 0;
        $withoutCost = 0;
        foreach ($data['productData'] as $item) {
            // * koszt z karty produktu (brak receptury) ; ** brak kosztu — poza sumą
            $source = $item['cost_source'] ?? 'recipe';
            $mark = $source === 'card' ? ' *' : ($source === 'none' ? ' **' : '');
            if ($source === 'card') $fromCard++;
            if ($source === 'none') $withoutCost++;
            $rows[] = [$item['name'] . $mark, $item['count_in_stock'], $item['total_material_cost'] === null ? 'brak kosztu' : $zl($item['total_material_cost'])];
            $total += (float) ($item['total_material_cost'] ?? 0);
        }
        $rows[] = ['_total' => ['Suma', '', $zl($total)]];

        $pdf->brandTable([
            ['label' => 'Produkt', 'w' => 120],
            ['label' => 'Ilość kartonów', 'w' => 30, 'align' => 'R'],
            ['label' => 'Wartość', 'w' => 36, 'align' => 'R'],
        ], $rows, ['font' => 8.5]);

        if ($fromCard) {
            $pdf->brandNote("* Produkt bez receptury: koszt surowców kartonu z karty produktu ({$fromCard}).");
        }
        if ($withoutCost) {
            $pdf->brandNote("** Brak kosztu surowców (receptura bez składników albo produkt bez receptury): produkt poza sumą ({$withoutCost}).");
        }

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