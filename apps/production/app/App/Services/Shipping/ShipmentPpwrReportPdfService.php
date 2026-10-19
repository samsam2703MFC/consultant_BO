<?php

namespace App\Services\Shipping;

use App\Services\Utils\TCPDFService;

class ShipmentPpwrReportPdfService
{
    public function generate(array $report): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(0, 8, 'Raport opakowań PPWR', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(0, 6, 'Okres: ' . $report['date_from'] . ' – ' . $report['date_to'], 0, 1);
        $pdf->Ln(3);
        $this->writeCoverage($pdf, $report['coverage']);
        $pdf->Ln(5);
        $this->writeSummary($pdf, 'Łączna masa wysłanych opakowań według materiału PPWR', $report['summary']);

        foreach ($report['customers'] as $customer) {
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', 'B', 14);
            $pdf->Cell(0, 8, 'Raport opakowań PPWR — klient', 0, 1, 'C');
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->Cell(0, 6, 'Okres: ' . $report['date_from'] . ' – ' . $report['date_to'], 0, 1);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->MultiCell(0, 7, 'Klient: ' . $customer['customer_name'], 0, 'L');
            $pdf->Ln(3);
            $this->writeSummary($pdf, 'Masa opakowań otrzymanych przez klienta według materiału PPWR', $customer['summary']);
        }

        return [
            'filename' => 'RAPORT_PPWR_' . $report['date_from'] . '_' . $report['date_to'] . '.pdf',
            'file_string' => $pdf->Output('', 'S'),
        ];
    }

    private function writeCoverage(TCPDFService $pdf, array $coverage): void
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, 'Zakres danych', 0, 1);
        $pdf->SetFont('dejavusans', '', 8);
        foreach ([
            'Wysyłki w okresie' => $coverage['shipment_count'],
            'Wysyłki bez ewidencji opakowań' => $coverage['without_evidence_count'],
            'Wysyłki z brakami BDO' => $coverage['requires_data_count'],
            'Pozycje bez snapshotu PPWR' => $coverage['missing_ppwr_snapshot_count'],
            'Raporty PPWR odtworzone z danych bieżących' => $coverage['current_ppwr_reconstruction_count'],
            'Ewidencje odtworzone z danych bieżących' => $coverage['current_data_evidence_count'],
        ] as $label => $value) {
            $pdf->Cell(120, 6, $label, 1);
            $pdf->Cell(30, 6, (string)$value, 1, 1, 'R');
        }
    }

    private function writeSummary(TCPDFService $pdf, string $title, array $summary): void
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, $title, 0, 1);
        $widths = [110, 38, 38];
        $pdf->SetFont('dejavusans', 'B', 8);
        foreach (['Materiał dominujący PPWR', 'Masa [g]', 'Masa [kg]'] as $index => $header) {
            $pdf->Cell($widths[$index], 7, $header, 1, 0, 'C');
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 8);
        foreach ($summary as $row) {
            $grams = (float)$row['total_weight_g'];
            $pdf->Cell($widths[0], 7, $this->materialLabel($row['material']), 1);
            $pdf->Cell($widths[1], 7, $this->formatNumber($grams) . ' g', 1, 0, 'R');
            $pdf->Cell($widths[2], 7, $this->formatNumber($grams / 1000) . ' kg', 1, 1, 'R');
        }
        if (empty($summary)) {
            $pdf->Cell(array_sum($widths), 7, 'Brak kompletnych pozycji opakowaniowych w wybranym okresie.', 1, 1, 'C');
        }
    }

    private function materialLabel(?string $material): string
    {
        return [
            'PLASTIC' => 'Tworzywo sztuczne', 'PAPER_CARDBOARD' => 'Papier i tektura',
            'GLASS' => 'Szkło', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne',
            'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe',
            'UNKNOWN' => 'Brak snapshotu PPWR',
        ][$material ?? 'UNKNOWN'] ?? 'Brak snapshotu PPWR';
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, abs($value - round($value)) < 0.000001 ? 0 : 2, ',', ' ');
    }
}
