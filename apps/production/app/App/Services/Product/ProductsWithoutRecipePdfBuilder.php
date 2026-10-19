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
    private array $colorGrayDark    = [51,  51,  51];
    private array $colorGrayMedium  = [102, 102, 102];
    private array $colorGrayLight   = [220, 220, 220];
    private array $colorSectionBg   = [44,  62,  80];   // ciemnoniebieski – tło sekcji
    private array $colorSectionText = [255, 255, 255];   // biały – tekst sekcji
    private array $colorCategoryBg  = [236, 240, 245];   // jasnoniebieski – tło kategorii
    private array $colorStripeBg    = [249, 249, 249];   // naprzemienne wiersze

    // -------------------------------------------------------------------------
    // Publiczne API
    // -------------------------------------------------------------------------

    /**
     * @param  array $grouped  Wynik ProductService::getWithoutRecipeGrouped()
     * @return array ['filename' => string, 'file_string' => string]
     */
    public function build(array $grouped): array
    {
        $pdf = $this->createPdf();

        $pdf->AddPage();
        $this->renderHeader($pdf);

        if (empty($grouped)) {
            $pdf->SetFont('dejavusans', 'I', 10);
            $pdf->SetTextColor(...$this->colorGrayMedium);
            $pdf->Cell(0, 8, 'Wszystkie produkty mają przypisaną recepturę.', 0, 1, 'C');
        } else {
            $this->renderContent($pdf, $grouped);
        }

        $pdfString = $pdf->Output('produkty_bez_receptury.pdf', 'S');

        return [
            'filename'    => 'produkty_bez_receptury_' . date('Y-m-d') . '.pdf',
            'file_string' => $pdfString,
        ];
    }

    // -------------------------------------------------------------------------
    // Prywatne metody
    // -------------------------------------------------------------------------

    private function createPdf(): TCPDFService
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SLFood');
        $pdf->SetTitle('Produkty bez przypisanej receptury');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 15);
        return $pdf;
    }

    private function renderHeader(TCPDFService $pdf): void
    {
        // Tytuł
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor(...$this->colorGrayDark);
        $pdf->Cell(0, 10, 'Produkty bez przypisanej receptury', 0, 1, 'C');

        // Podtytuł z datą
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(...$this->colorGrayMedium);
        $pdf->Cell(0, 6, 'Wygenerowano: ' . date('d.m.Y H:i'), 0, 1, 'C');

        $pdf->Ln(4);

        // Linia pozioma
        $pdf->SetDrawColor(...$this->colorGrayLight);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(4);
    }

    private function renderContent(TCPDFService $pdf, array $grouped): void
    {
        $pageW    = $pdf->getPageWidth() - 20; // szerokość robocza (marginesy 10+10)
        $lpColW   = 10;
        $nameColW = $pageW - $lpColW;

        foreach ($grouped as $sectionData) {
            // ── Nagłówek sekcji ──────────────────────────────────────────────
            $this->checkPageBreak($pdf, 14);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetFillColor(...$this->colorSectionBg);
            $pdf->SetTextColor(...$this->colorSectionText);
            $pdf->SetDrawColor(...$this->colorSectionBg);
            $pdf->Cell($pageW, 8, mb_strtoupper($sectionData['section_name']), 0, 1, 'L', true);
            $pdf->Ln(1);

            foreach ($sectionData['categories'] as $categoryData) {
                // ── Nagłówek kategorii ───────────────────────────────────────
                $this->checkPageBreak($pdf, 10);
                $pdf->SetFont('dejavusans', 'B', 9);
                $pdf->SetFillColor(...$this->colorCategoryBg);
                $pdf->SetTextColor(...$this->colorGrayDark);
                $pdf->SetDrawColor(...$this->colorGrayLight);
                $pdf->Cell($pageW, 6, '  ' . $categoryData['category_name'], 'B', 1, 'L', true);

                // ── Nagłówek tabeli ─────────────────────────────────────────
                $this->checkPageBreak($pdf, 8);
                $pdf->SetFont('dejavusans', 'B', 8);
                $pdf->SetFillColor(...$this->colorGrayLight);
                $pdf->SetTextColor(...$this->colorGrayDark);
                $pdf->Cell($lpColW,   6, 'Lp.', 1, 0, 'C', true);
                $pdf->Cell($nameColW, 6, 'Nazwa produktu', 1, 1, 'L', true);

                // ── Wiersze produktów ────────────────────────────────────────
                $pdf->SetFont('dejavusans', '', 8);
                $pdf->SetTextColor(...$this->colorGrayDark);
                $pdf->SetDrawColor(...$this->colorGrayLight);

                $lp   = 1;
                $fill = false;
                foreach ($categoryData['products'] as $product) {
                    $this->checkPageBreak($pdf, 6);
                    $pdf->SetFillColor(...($fill ? $this->colorStripeBg : [255, 255, 255]));
                    $pdf->Cell($lpColW,   6, (string)$lp,       1, 0, 'C', $fill);
                    $pdf->Cell($nameColW, 6, $product['name'],  1, 1, 'L', $fill);
                    $fill = !$fill;
                    $lp++;
                }

                $pdf->Ln(2);
            }

            $pdf->Ln(3);
        }
    }

    /**
     * Sprawdza czy zostaje wystarczająco miejsca na stronie;
     * jeśli nie – dodaje nową stronę.
     */
    private function checkPageBreak(TCPDFService $pdf, float $neededMm): void
    {
        if ($pdf->GetY() + $neededMm > $pdf->getPageHeight() - 15) {
            $pdf->AddPage();
        }
    }
}

