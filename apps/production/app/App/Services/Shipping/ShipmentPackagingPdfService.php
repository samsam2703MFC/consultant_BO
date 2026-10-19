<?php

namespace App\Services\Shipping;

use App\Services\Utils\TCPDFService;

class ShipmentPackagingPdfService
{
    public function __construct(private ShipmentPackagingService $packagingService)
    {
    }

    /** @return array{filename:string,file_string:string} */
    public function generateDocumentPDF(int $shipmentId): array
    {
        $data = $this->packagingService->getSnapshotForShipment($shipmentId);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(0, 8, 'Zestawienie opakowań — wysyłka WDT', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(0, 6, 'WZ: ' . ($data['wz']['doc_num'] ?? '-') . ' | Data wysyłki: ' . $data['shipment']['shipment_date'], 0, 1);
        $pdf->Cell(0, 6, 'Odbiorca: ' . ($data['wz']['consignee_name'] ?? '-') . ' | Status: ' . $this->statusLabel($data['record']['status']), 0, 1);
        if (($data['record']['evidence_basis'] ?? 'SHIPMENT_SNAPSHOT') === 'CURRENT_DATA_RECONSTRUCTION') {
            $pdf->SetTextColor(160, 90, 0);
            $pdf->MultiCell(0, 6, 'Uwaga: ewidencję utworzono po wysyłce na podstawie bieżącej receptury i bieżących danych opakowań. Nie odtwarza ona danych historycznych z dnia wysyłki.', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln(3);

        $widths = [42, 35, 25, 16, 17, 22, 21];
        $headers = ['Produkt', 'Element opakowania', 'Kategoria BDO', 'Jedn.', 'Ilość', 'Masa [g]', 'Status'];
        $this->writeItemsHeader($pdf, $widths, $headers);
        foreach ($data['items'] as $item) {
            $row = [
                $item['product_name_snapshot'],
                $item['material_name_snapshot'] ?: '—',
                $this->categoryLabel($item['bdo_material_category_snapshot']),
                $item['source_unit_snapshot'] ?: '—',
                $item['packaging_units_total'] !== null ? $this->numberWithoutIntegerDecimals($item['packaging_units_total']) : '—',
                $item['total_weight_g'] !== null ? $this->numberWithoutIntegerDecimals($item['total_weight_g']) : '—',
                $this->itemStatusLabel($item['calculation_status']),
            ];
            $rowHeight = $this->getRowHeight($pdf, $widths, $row);

            // Nie pozwalamy TCPDF łamać komórek pojedynczego wiersza osobno,
            // ponieważ powoduje to nakładanie tekstu na kolejnej stronie.
            if ($pdf->GetY() + $rowHeight > 280) {
                $pdf->AddPage();
                $this->writeItemsHeader($pdf, $widths, $headers);
            }

            $this->writeItemsRow($pdf, $widths, $row, $rowHeight);
        }

        // Podsumowanie zawsze rozpoczynamy na osobnej stronie. Dzięki temu
        // automatyczne łamanie strony w tabeli pozycji nie rozjeżdża komórek
        // podsumowania ani tekstu ostrzeżenia.
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, 'Podsumowanie kompletnej masy według materiału', 0, 1);
        $summaryWidths = [126, 60];
        $pdf->SetFont('dejavusans', 'B', 8);
        foreach (['Kategoria BDO i rodzaj użycia', 'Masa [kg]'] as $index => $header) {
            $pdf->Cell($summaryWidths[$index], 7, $header, 1, 0, 'C');
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 8);
        foreach ($data['summary'] as $summary) {
            $grams = (float)$summary['total_weight_g'];
            $pdf->Cell($summaryWidths[0], 7, $this->categoryLabel($summary['category']) . ' (' . $this->usageLabel($summary['usage_type']) . ')', 1);
            $pdf->Cell($summaryWidths[1], 7, $this->numberWithoutIntegerDecimals($grams / 1000) . ' kg', 1, 1, 'R');
        }

        if ($data['record']['status'] === 'REQUIRES_DATA') {
            $pdf->Ln(4);
            $pdf->SetTextColor(160, 40, 40);
            $pdf->MultiCell(0, 7, 'Uwaga: dokument zawiera pozycje bez kompletnych danych. Podsumowanie obejmuje wyłącznie pozycje kompletne.', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }

        return [
            'filename' => 'OPAKOWANIA_WDT_' . ($data['wz']['doc_num'] ?? $shipmentId) . '.pdf',
            'file_string' => $pdf->Output('', 'S'),
        ];
    }

    private function numberWithoutIntegerDecimals(float|string $value): string
    {
        $number = (float)$value;
        if (abs($number - round($number)) < 0.000001) {
            return number_format($number, 0, ',', ' ');
        }

        return number_format($number, 2, ',', ' ');
    }

    /** @param list<int> $widths @param list<string> $headers */
    private function writeItemsHeader(TCPDFService $pdf, array $widths, array $headers): void
    {
        $pdf->SetFont('dejavusans', 'B', 7);
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        foreach ($headers as $index => $header) {
            $pdf->MultiCell($widths[$index], 8, $header, 1, 'C', false, 0, $x, $y, true, 0, false, true, 8, 'M');
            $x += $widths[$index];
        }
        $pdf->SetXY($pdf->getMargins()['left'], $y + 8);
        $pdf->SetFont('dejavusans', '', 7);
    }

    /** @param list<int> $widths @param list<string> $row */
    private function getRowHeight(TCPDFService $pdf, array $widths, array $row): float
    {
        $heights = [7.0];
        foreach ($row as $index => $value) {
            $heights[] = $pdf->getStringHeight($widths[$index], (string)$value);
        }

        return max($heights);
    }

    /** @param list<int> $widths @param list<string> $row */
    private function writeItemsRow(TCPDFService $pdf, array $widths, array $row, float $rowHeight): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        foreach ($row as $index => $value) {
            $pdf->MultiCell(
                $widths[$index],
                $rowHeight,
                (string)$value,
                1,
                $index >= 3 ? 'R' : 'L',
                false,
                0,
                $x,
                $y,
                true,
                0,
                false,
                true,
                $rowHeight,
                'M'
            );
            $x += $widths[$index];
        }
        $pdf->SetXY($pdf->getMargins()['left'], $y + $rowHeight);
    }

    private function statusLabel(string $status): string
    {
        return ['GENERATED' => 'wygenerowana', 'REQUIRES_DATA' => 'wymaga danych', 'CONFIRMED' => 'potwierdzona'][$status] ?? $status;
    }

    private function itemStatusLabel(string $status): string
    {
        return [
            'COMPLETE' => 'kompletna', 'MISSING_RECIPE' => 'brak receptury',
            'MISSING_PACKAGING' => 'brak opakowania', 'MISSING_CATEGORY' => 'brak kategorii',
            'MISSING_WEIGHT' => 'brak masy',
        ][$status] ?? $status;
    }

    private function usageLabel(?string $usage): string
    {
        return $usage === 'REUSABLE' ? 'wielokrotne' : 'jednorazowe';
    }

    private function categoryLabel(?string $category): string
    {
        return [
            'PLASTIC' => 'Tworzywa sztuczne', 'ALUMINIUM' => 'Aluminium',
            'FERROUS_METAL' => 'Metale żelazne', 'PAPER_CARDBOARD' => 'Papier i tektura',
            'GLASS' => 'Szkło', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe',
            'OTHER' => 'Pozostałe',
        ][$category ?? ''] ?? '—';
    }
}
