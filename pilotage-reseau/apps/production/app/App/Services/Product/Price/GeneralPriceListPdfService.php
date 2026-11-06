<?php

namespace App\Services\Product\Price;

use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

/**
 * Generuje PDF cennika ogólnego produktów (styl dokumentów L'Atelier).
 * Format: A4 pionowy (portrait)
 * Kolumny: Lp. | Nazwa produktu | Kategoria | Status | Cena standardowa
 */
class GeneralPriceListPdfService
{
    /**
     * @param array $products  Dane z repozytorium
     * @return array ['filename' => string, 'file_string' => string]
     */
    public function generate(array $products): array
    {
        $pdf = $this->createPdf();
        $pdf->brandPage('Cennik ogólny produktów');
        $pdf->brandHeader('Cennik ogólny produktów', [
            ['Data wygenerowania', date('d.m.Y')],
            ['Liczba pozycji', (string) count($products)],
        ], 'Ceny standardowe (€)');

        $this->drawRows($pdf, $products);
        $this->drawSummary($pdf, $products);

        $filename   = 'cennik_ogolny_' . date('Y-m-d') . '.pdf';
        $fileString = $pdf->Output($filename, 'S');

        return [
            'filename'    => $filename,
            'file_string' => $fileString,
        ];
    }

    private function createPdf(): TCPDFService
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Asima ERP');
        $pdf->SetAuthor('SLFood');
        $pdf->SetTitle('Cennik ogólny produktów');
        $pdf->SetSubject('Cennik standardowy');
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        return $pdf;
    }

    /** Wiersze bez grupowania ; produkt aktywny bez ceny oznaczony « * » (przypis pod tabelą). */
    private function drawRows(TCPDFService $pdf, array $products): void
    {
        $rows = [];
        foreach (array_values($products) as $idx => $product) {
            $isActive = (int)($product['active'] ?? 1) === 1;
            $price    = (float)($product['standard_price'] ?? 0);
            $rows[] = [
                $idx + 1,
                $product['name'] ?? '',
                $product['category_name'] ?? '—',
                $isActive ? 'Aktywny' : 'Nieaktywny',
                DocumentLabels::money($price) . ($price == 0 && $isActive ? ' *' : ''),
            ];
        }

        $pdf->brandTable([
            ['label' => 'Lp.', 'w' => 6, 'align' => 'C'],
            ['label' => 'Nazwa produktu', 'w' => 52],
            ['label' => 'Kategoria', 'w' => 26],
            ['label' => 'Status', 'w' => 12, 'align' => 'C'],
            ['label' => 'Cena (€)', 'w' => 12, 'align' => 'R'],
        ], $rows, ['font' => 8]);
    }

    private function drawSummary(TCPDFService $pdf, array $products): void
    {
        $totalCount     = count($products);
        $activeCount    = 0;
        $inactiveCount  = 0;
        $zeroPriceCount = 0;
        $zeroActive     = 0;

        foreach ($products as $p) {
            $active = (int)($p['active'] ?? 1) === 1;
            $price  = (float)($p['standard_price'] ?? 0);
            if ($active) $activeCount++; else $inactiveCount++;
            if ($price == 0) $zeroPriceCount++;
            if ($price == 0 && $active) $zeroActive++;
        }

        $pdf->brandSummary([
            ['Łącznie', (string) $totalCount],
            ['Aktywnych', (string) $activeCount],
            ['Nieaktywnych', (string) $inactiveCount],
            ['Cena = 0', (string) $zeroPriceCount],
        ]);
        if ($zeroActive > 0) {
            $pdf->brandNote('* Produkt aktywny bez ceny standardowej (0,00 €) — do uzupełnienia.');
        }
    }
}
