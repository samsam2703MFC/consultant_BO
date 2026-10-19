<?php

namespace App\Services\Product\Price;

use App\Services\Utils\TCPDFService;

/**
 * Generuje PDF cennika ogólnego produktów.
 * Format: A4 pionowy (portrait)
 * Kolumny: Lp. | Nazwa produktu | Kategoria | Status | Cena standardowa
 */
class GeneralPriceListPdfService
{
    // Paleta kolorów
    private array $colorPrimary    = [13,  110, 253];
    private array $colorHeaderBg   = [33,  37,  41];
    private array $colorHeaderText = [255, 255, 255];
    private array $colorRowEven    = [248, 249, 250];
    private array $colorRowOdd     = [255, 255, 255];
    private array $colorZeroBg     = [255, 243, 205];
    private array $colorZeroText   = [133, 100,   4];
    private array $colorInactive   = [173, 181, 189];
    private array $colorBorder     = [222, 226, 230];
    private array $colorGreen      = [25,  135,  84];
    private array $colorGray       = [108, 117, 125];
    private array $colorText       = [33,  37,  41];

    // Szerokości kolumn – A4 portrait, marginesy 10mm, użyteczne ~190mm
    // Lp. | Nazwa produktu | Kategoria | Status | Cena
    private array $colWidths = [10, 90, 48, 22, 20];

    /**
     * @param array $products  Dane z repozytorium
     * @return array ['filename' => string, 'file_string' => string]
     */
    public function generate(array $products): array
    {
        $pdf = $this->createPdf();
        $pdf->AddPage();

        $this->drawDocumentHeader($pdf, count($products));
        $this->drawTableHeader($pdf);
        $this->drawRows($pdf, $products);
        $this->drawSummary($pdf, $products);

        $filename   = 'cennik_ogolny_' . date('Y-m-d') . '.pdf';
        $fileString = $pdf->Output($filename, 'S');

        return [
            'filename'    => $filename,
            'file_string' => $fileString,
        ];
    }

    // -------------------------------------------------------------------------
    // Inicjalizacja PDF
    // -------------------------------------------------------------------------

    private function createPdf(): TCPDFService
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Asima ERP');
        $pdf->SetAuthor('SLFood');
        $pdf->SetTitle('Cennik ogólny produktów');
        $pdf->SetSubject('Cennik standardowy');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        return $pdf;
    }

    // -------------------------------------------------------------------------
    // Nagłówek dokumentu
    // -------------------------------------------------------------------------

    private function drawDocumentHeader(TCPDFService $pdf, int $count): void
    {
        // Pasek tytułowy
        $pdf->SetFillColor(...$this->colorHeaderBg);
        $pdf->SetTextColor(...$this->colorHeaderText);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 12, 'CENNIK OGÓLNY PRODUKTÓW', 0, 1, 'C', true);

        $pdf->Ln(1);

        // Wiersz z meta-informacjami
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(...$this->colorGray);
        $pdf->SetFillColor(255, 255, 255);

        $pdf->Cell(70, 5, 'Data wygenerowania: ' . date('d.m.Y'), 0, 0, 'L');
        $pdf->Cell(50, 5, 'Liczba pozycji: ' . $count, 0, 0, 'C');
        $pdf->Cell(0,  5, 'Asima ERP', 0, 1, 'R');

        $pdf->Ln(3);
    }

    // -------------------------------------------------------------------------
    // Nagłówek tabeli
    // -------------------------------------------------------------------------

    private function drawTableHeader(TCPDFService $pdf): void
    {
        $headers = ['Lp.', 'Nazwa produktu', 'Kategoria', 'Status', 'Cena (€)'];

        $pdf->SetFillColor(...$this->colorPrimary);
        $pdf->SetTextColor(...$this->colorHeaderText);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetDrawColor(...$this->colorPrimary);
        $pdf->SetLineWidth(0.1);

        foreach ($headers as $i => $header) {
            $align = ($i === 4) ? 'R' : (($i === 0) ? 'C' : 'L');
            $pdf->Cell($this->colWidths[$i], 7, $header, 0, 0, $align, true);
        }
        $pdf->Ln();

        // cienka linia oddzielająca
        $pdf->SetDrawColor(...$this->colorBorder);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    }

    // -------------------------------------------------------------------------
    // Wiersze danych (bez grupowania)
    // -------------------------------------------------------------------------

    private function drawRows(TCPDFService $pdf, array $products): void
    {
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(...$this->colorBorder);
        $rowH = 6.5;

        foreach ($products as $idx => $product) {
            if ($pdf->GetY() + $rowH > $pdf->getPageHeight() - 18) {
                $pdf->AddPage();
                $this->drawTableHeader($pdf);
            }

            $isActive = (int)($product['active'] ?? 1) === 1;
            $price    = (float)($product['standard_price'] ?? 0);
            $isZero   = ($price == 0);
            $isEven   = ($idx % 2 === 0);

            // Tło wiersza
            if ($isZero && $isActive) {
                $pdf->SetFillColor(...$this->colorZeroBg);
            } elseif ($isEven) {
                $pdf->SetFillColor(...$this->colorRowEven);
            } else {
                $pdf->SetFillColor(...$this->colorRowOdd);
            }

            // Kolor tekstu
            if (!$isActive) {
                $pdf->SetTextColor(...$this->colorInactive);
            } elseif ($isZero) {
                $pdf->SetTextColor(...$this->colorZeroText);
            } else {
                $pdf->SetTextColor(...$this->colorText);
            }

            // Lp.
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->Cell($this->colWidths[0], $rowH, $idx + 1, 'B', 0, 'C', true);

            // Nazwa
            $pdf->Cell($this->colWidths[1], $rowH, $product['name'] ?? '', 'B', 0, 'L', true);

            // Kategoria
            $pdf->Cell($this->colWidths[2], $rowH, $product['category_name'] ?? '—', 'B', 0, 'L', true);

            // Status
            $pdf->SetFont('dejavusans', 'B', 7);
            $pdf->Cell($this->colWidths[3], $rowH, $isActive ? 'Aktywny' : 'Nieaktywny', 'B', 0, 'C', true);

            // Cena
            $priceText = ($isZero && $isActive)
                ? '0,00 €'
                : number_format($price, 2, ',', ' ') . ' €';
            $pdf->SetFont('dejavusans', $isZero ? 'B' : '', 8);
            $pdf->Cell($this->colWidths[4], $rowH, $priceText, 'B', 1, 'R', true);
        }
    }

    // -------------------------------------------------------------------------
    // Podsumowanie
    // -------------------------------------------------------------------------

    private function drawSummary(TCPDFService $pdf, array $products): void
    {
        $totalCount     = count($products);
        $activeCount    = 0;
        $inactiveCount  = 0;
        $zeroPriceCount = 0;

        foreach ($products as $p) {
            $active = (int)($p['active'] ?? 1) === 1;
            $price  = (float)($p['standard_price'] ?? 0);
            if ($active) $activeCount++; else $inactiveCount++;
            if ($price == 0) $zeroPriceCount++;
        }

        // Wysokość potrzebna: odstęp (3) + linia (1) + odstęp (2) + wiersz statystyk (5) + stopka (7)
        $neededHeight = 3 + 1 + 2 + 5 + 7;

        $pageBottom = $pdf->getPageHeight() - $pdf->getBreakMargin();

        if ($pdf->GetY() + $neededHeight > $pageBottom) {
            $pdf->AddPage();
        }

        $pdf->Ln(3);

        $pdf->SetDrawColor(...$this->colorBorder);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());

        $pdf->Ln(2);
        $pdf->SetFont('dejavusans', '', 8);

        $pdf->SetTextColor(...$this->colorGray);
        $pdf->Cell(50, 5, 'Łącznie: ' . $totalCount, 0, 0, 'L');
        $pdf->SetTextColor(...$this->colorGreen);
        $pdf->Cell(45, 5, 'Aktywnych: ' . $activeCount, 0, 0, 'L');
        $pdf->SetTextColor(...$this->colorInactive);
        $pdf->Cell(45, 5, 'Nieaktywnych: ' . $inactiveCount, 0, 0, 'L');
        $pdf->SetTextColor(...$this->colorZeroText);
        $pdf->Cell(0, 5, 'Cena = 0: ' . $zeroPriceCount, 0, 1, 'L');

        $pdf->Ln(2);

        // Stopka na tej samej stronie, bezpośrednio pod podsumowaniem
        $pdf->SetFont('dejavusans', 'I', 7);
        $pdf->SetTextColor(...$this->colorGray);
        $pdf->Cell(0, 5, 'Wygenerowano: ' . date('d.m.Y H:i') . '  |  Asima ERP', 0, 0, 'C');
    }
}

