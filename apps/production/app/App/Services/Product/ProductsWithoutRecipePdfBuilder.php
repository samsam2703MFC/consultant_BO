<?php

namespace App\Services\Product;

use App\Services\Utils\TCPDFService;

/**
 * Generuje PDF z listą produktów nieposiadających przypisanej receptury.
 * Produkty są pogrupowane per sekcja → kategoria, posortowane rosnąco po nazwie.
 *
 * Wejście: wynik ProductService::getWithoutRecipeGrouped()
 *   [
 *     section_id => [
 *       'section_name' => string,
 *       'categories'   => [
 *         category_id => [
 *           'category_name' => string,
 *           'products'      => [ ['id' => int, 'name' => string], ... ]
 *         ]
 *       ]
 *     ]
 *   ]
 */
class ProductsWithoutRecipePdfBuilder
{
    /**
     * @param  array $grouped  Wynik ProductService::getWithoutRecipeGrouped()
     * @return array ['filename' => string, 'file_string' => string]
     */
    public function build(array $grouped): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SLFood');
        $pdf->SetTitle('Produkty bez przypisanej receptury');

        $count = 0;
        foreach ($grouped as $sectionData) {
            foreach ($sectionData['categories'] as $categoryData) $count += count($categoryData['products']);
        }

        $pdf->brandPage('Produkty bez przypisanej receptury', ['ref' => date('d.m.Y')]);
        $pdf->brandHeader('Produkty bez receptury', [
            ['Produkty', (string) $count],
            ['Wydruk', date('d.m.Y H:i')],
        ], 'Produkty bez przypisanej receptury według działu i kategorii');

        if (empty($grouped)) {
            $pdf->brandText('Wszystkie produkty mają przypisaną recepturę.', ['align' => 'C', 'color' => TCPDFService::MUTED]);
        } else {
            $this->renderContent($pdf, $grouped);
        }

        $pdfString = $pdf->Output('produkty_bez_receptury.pdf', 'S');

        return [
            'filename'    => 'produkty_bez_receptury_' . date('Y-m-d') . '.pdf',
            'file_string' => $pdfString,
        ];
    }

    /** Dział = sekcja z tytułem ; kategorie = wiersze grupy, numeracja Lp. od 1 w każdej kategorii. */
    private function renderContent(TCPDFService $pdf, array $grouped): void
    {
        foreach ($grouped as $sectionData) {
            $products = array_sum(array_map(fn($c) => count($c['products']), $sectionData['categories']));
            $pdf->brandSection($sectionData['section_name'], $products . ' prod.');

            $rows = [];
            foreach ($sectionData['categories'] as $categoryData) {
                $rows[] = ['_group' => $categoryData['category_name']];
                $lp = 1;
                foreach ($categoryData['products'] as $product) {
                    $rows[] = [(string) $lp++, $product['name']];
                }
            }
            $pdf->brandTable([
                ['label' => 'Lp.', 'w' => 10, 'align' => 'C'],
                ['label' => 'Nazwa produktu', 'w' => 176],
            ], $rows, ['font' => 8.5]);
        }
    }
}
