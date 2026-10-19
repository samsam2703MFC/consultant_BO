<?php

namespace App\Services\Production;

use App\Core\Helpers\UnitMapper;
use App\Services\Utils\TCPDFService;

/**
 * Builder PDF dla listy zapotrzebowania materiałowego.
 *
 * Oczekiwany kształt $data (minimum):
 *  - rows: array<int,array{
 *      material_name?:string,
 *      unit?:string,
 *      required?:string|float|int,
 *      in_stock?:string|float|int,
 *      to_order?:string|float|int,
 *      note?:string
 *    }>
 *  - stock_minimum: bool
 */
class RequirementListPdfBuilder
{
    /**
     * @return TCPDFService
     */
    public function build(TCPDFService $pdf, array $data): TCPDFService
    {
        $stockMinimum = !empty($data['stock_minimum']);
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];

        $pdf->AddPage();

        $headerData = [
            'header_title' => 'Lista zapotrzebowania materiałowego',
            'header_subtitle' => date('d-m-Y'),
        ];

        $pdf->customHeader($headerData, false, 'p');
        $pdf->Footer();
        $pdf->customFooter([
            'info_1' => $stockMinimum ? 'Uwzględniono stany magazynowe' : 'Nie uwzględniono stanów magazynowych',
        ]);

        $pdf->Ln(14);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('dejavusans', '', 10);

        if (count($rows) === 0) {
            $pdf->SetFont('dejavusans', '', 11);
            $pdf->MultiCell(0, 6, 'Brak danych do wyświetlenia.', 0, 'L', false, 1);
            return $pdf;
        }

        $this->renderTable($pdf, $rows);

        return $pdf;
    }

    /**
     * Renderuje tabelę w oparciu o Cell/MultiCell (bez HTML).
     */
    private function renderTable(TCPDFService $pdf, array $rows): void
    {
        // Szerokości kolumn pod A4 portrait z marginesami (~194mm przy margin 8/8)
        $w = [
            'material' => 78,
            'required' => 24,
            'in_stock' => 24,
            'to_order' => 24,
            'unit' => 14,
            'value' => 30,
        ];

        $header = [
            'material' => 'Materiał',
            'required' => 'Wymagane',
            'in_stock' => 'Na stanie',
            'to_order' => 'Do zamówienia',
            'unit' => 'Jm',
            'value' => 'Wartość (PLN)',
        ];

        // Header (mniejsza czcionka + MultiCell, żeby nagłówki się mieściły)
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetFillColor(239, 239, 239);

        $headerH = 8;
        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();

        $pdf->MultiCell($w['material'], $headerH, $header['material'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
        $x0 += $w['material'];
        $pdf->MultiCell($w['required'], $headerH, $header['required'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
        $x0 += $w['required'];
        $pdf->MultiCell($w['in_stock'], $headerH, $header['in_stock'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
        $x0 += $w['in_stock'];
        $pdf->MultiCell($w['to_order'], $headerH, $header['to_order'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
        $x0 += $w['to_order'];
        $pdf->MultiCell($w['unit'], $headerH, $header['unit'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
        $x0 += $w['unit'];
        $pdf->MultiCell($w['value'], $headerH, $header['value'], 1, 'C', true, 1, $x0, $y0, true, 0, false, true, $headerH, 'M');

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetFillColor(245, 245, 245);

        $rowH = 6;
        $fill = false;

        $sumToOrder = 0.0;
        $sumRequired = 0.0;
        $sumApproxValue = 0.0;

        foreach ($rows as $r) {
            $material = (string)($r['material_name'] ?? '');
            $unitId = isset($r['id_unit']) ? (int)$r['id_unit'] : null;

            $requiredRaw = $r['required'] ?? '';
            $inStockRaw = $r['in_stock'] ?? '';

            // Jednostka wyświetlana zależna od requiredRaw (> 1000 dla g/ml)
            $displayUnit = UnitMapper::conditionalDisplayCodeFromId($unitId, $requiredRaw)
                ?? (UnitMapper::codeFromId($unitId) ?? '');

            // to_order: preferuj wartość z danych, ale jeśli brak to licz required - in_stock
            if (array_key_exists('to_order', $r)) {
                $toOrderRaw = $r['to_order'];
            } else {
                $toOrderRaw = (is_numeric($requiredRaw) && is_numeric($inStockRaw)) ? ((float)$requiredRaw - (float)$inStockRaw) : '';
            }

            // Format ilości:
            //  - g/ml -> /1000 tylko gdy requiredRaw > 1000
            //  - Wymagane:
            //      - jeżeli zostaje g/ml (raw <= 1000) => zaokrąglamy do pełnych w górę
            //      - w pozostałych przypadkach => ceil do 3 miejsc
            $required = UnitMapper::formatRequiredQuantity($unitId, $requiredRaw);

            // In-stock / to-order: nie wymuszamy pełnych dla g/ml, tylko skracamy zapis
            $inStock = UnitMapper::formatRequiredQuantity($unitId, $inStockRaw);
            $toOrder = UnitMapper::formatRequiredQuantity($unitId, $toOrderRaw);

            // Koszt: approx_value (suma dla danego zamówienia/wyboru)
            $approxValueRaw = $r['approx_value'] ?? '';
            $approxValue = '';
            if (is_numeric($approxValueRaw)) {
                $approxValue = UnitMapper::formatNumber((float)$approxValueRaw, 2);
                $sumApproxValue += (float)$approxValueRaw;
            }

            // sumy ilości (w tej samej logice warunkowej co formatowanie)
            $reqF = UnitMapper::conditionalToDisplayQuantity($unitId, $requiredRaw);
            $toOrderF = UnitMapper::conditionalToDisplayQuantity($unitId, $toOrderRaw);
            if ($reqF !== null) $sumRequired += $reqF;
            if ($toOrderF !== null) $sumToOrder += $toOrderF;

            // wysokość wiersza wg MultiCell (materiał)
            $lines = $this->estimateLines($pdf, $material, $w['material']);
            $h = max($rowH, $lines * 5);

            // łamanie strony
            if ($pdf->GetY() + $h > ($pdf->getPageHeight() - 15)) {
                $pdf->AddPage();

                // powtórka headera tabeli
                $pdf->SetFont('dejavusans', 'B', 8);
                $pdf->SetFillColor(239, 239, 239);

                $headerH = 8;
                $x0 = $pdf->GetX();
                $y0 = $pdf->GetY();

                $pdf->MultiCell($w['material'], $headerH, $header['material'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
                $x0 += $w['material'];
                $pdf->MultiCell($w['required'], $headerH, $header['required'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
                $x0 += $w['required'];
                $pdf->MultiCell($w['in_stock'], $headerH, $header['in_stock'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
                $x0 += $w['in_stock'];
                $pdf->MultiCell($w['to_order'], $headerH, $header['to_order'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
                $x0 += $w['to_order'];
                $pdf->MultiCell($w['unit'], $headerH, $header['unit'], 1, 'C', true, 0, $x0, $y0, true, 0, false, true, $headerH, 'M');
                $x0 += $w['unit'];
                $pdf->MultiCell($w['value'], $headerH, $header['value'], 1, 'C', true, 1, $x0, $y0, true, 0, false, true, $headerH, 'M');

                $pdf->SetFont('dejavusans', '', 8);
                $pdf->SetFillColor(245, 245, 245);
            }

            $x = $pdf->GetX();
            $y = $pdf->GetY();

            $pdf->MultiCell($w['material'], $h, $material, 1, 'L', $fill, 0, $x, $y, true, 0, false, true, $h, 'M');

            $x += $w['material'];
            $pdf->MultiCell($w['required'], $h, (string)$required, 1, 'R', $fill, 0, $x, $y, true, 0, false, true, $h, 'M');

            $x += $w['required'];
            $pdf->MultiCell($w['in_stock'], $h, (string)$inStock, 1, 'R', $fill, 0, $x, $y, true, 0, false, true, $h, 'M');

            $x += $w['in_stock'];
            $pdf->MultiCell($w['to_order'], $h, (string)$toOrder, 1, 'R', $fill, 0, $x, $y, true, 0, false, true, $h, 'M');

            $x += $w['to_order'];
            $pdf->MultiCell($w['unit'], $h, $displayUnit, 1, 'C', $fill, 0, $x, $y, true, 0, false, true, $h, 'M');

            $x += $w['unit'];
            $pdf->MultiCell($w['value'], $h, (string)$approxValue, 1, 'R', $fill, 1, $x, $y, true, 0, false, true, $h, 'M');

            $fill = !$fill;
        }

        // Podsumowanie
        $pdf->Ln(2);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetFillColor(239, 239, 239);

        $totalW = $w['material'] + $w['required'] + $w['in_stock'] + $w['to_order'] + $w['unit'];
        $pdf->Cell($totalW, 7, 'Przybliżony koszt:', 1, 0, 'R', true);
        $pdf->Cell($w['value'], 7, UnitMapper::formatNumber($sumApproxValue, 2), 1, 1, 'R', true);

        $pdf->SetFont('dejavusans', '', 8);
    }

    private function estimateLines(TCPDFService $pdf, string $text, float $width): int
    {
        $text = trim($text);
        if ($text === '') return 1;

        // getNumLines bazuje na aktualnym foncie
        $lines = (int)$pdf->getNumLines($text, $width);
        return max(1, $lines);
    }

    private function formatNumber(float $n): string
    {
        // bez narzucania jednostki/formatu biznesowego
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
