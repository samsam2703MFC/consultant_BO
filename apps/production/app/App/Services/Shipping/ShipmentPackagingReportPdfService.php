<?php

namespace App\Services\Shipping;

use App\Services\Utils\TCPDFService;

class ShipmentPackagingReportPdfService
{
    /** @param array{date_from:string,date_to:string,summary:list<array<string,mixed>>,coverage:array<string,mixed>} $report */
    public function generate(array $report): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(0, 8, 'Raport opakowań BDO', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(0, 6, 'Okres: ' . $report['date_from'] . ' – ' . $report['date_to'], 0, 1);
        $pdf->Ln(3);

        $coverage = $report['coverage'];
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, 'Kontrola kompletności ewidencji', 0, 1);
        $pdf->SetFont('dejavusans', '', 8);
        foreach ([
            'Wysyłki w okresie' => $coverage['shipment_count'],
            'Ewidencje kompletne' => $coverage['complete_evidence_count'],
            'Ewidencje z brakami' => $coverage['requires_data_count'],
            'Wysyłki bez ewidencji' => $coverage['without_evidence_count'],
            'Ewidencje utworzone z danych bieżących' => $coverage['current_data_evidence_count'],
        ] as $label => $value) {
            $pdf->Cell(105, 6, $label, 1);
            $pdf->Cell(35, 6, (string)$value, 1, 1, 'R');
        }

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, 'Masa opakowań według kategorii BDO', 0, 1);
        $widths = [106, 40, 40];
        $pdf->SetFont('dejavusans', 'B', 8);
        foreach (['Kategoria BDO i rodzaj użycia', 'Masa [g]', 'Masa [kg]'] as $index => $header) {
            $pdf->Cell($widths[$index], 7, $header, 1, 0, 'C');
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 8);
        foreach ($report['summary'] as $row) {
            $grams = (float)$row['total_weight_g'];
            $pdf->Cell($widths[0], 7, $this->categoryLabel($row['category']) . ' (' . $this->usageLabel($row['usage_type']) . ')', 1);
            $pdf->Cell($widths[1], 7, $this->formatNumber($grams) . ' g', 1, 0, 'R');
            $pdf->Cell($widths[2], 7, $this->formatNumber($grams / 1000) . ' kg', 1, 1, 'R');
        }
        if (empty($report['summary'])) {
            $pdf->Cell(array_sum($widths), 7, 'Brak kompletnych pozycji opakowaniowych w wybranym okresie.', 1, 1, 'C');
        }

        if ((int)$coverage['requires_data_count'] > 0 || (int)$coverage['without_evidence_count'] > 0) {
            $pdf->Ln(4);
            $pdf->SetTextColor(160, 40, 40);
            $pdf->MultiCell(0, 6, 'Uwaga: podsumowanie mas obejmuje wyłącznie kompletne pozycje ewidencji. Uzupełnij wysyłki oznaczone brakami przed wykorzystaniem raportu do rozliczenia.', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }

        return [
            'filename' => 'RAPORT_BDO_' . $report['date_from'] . '_' . $report['date_to'] . '.pdf',
            'file_string' => $pdf->Output('', 'S'),
        ];
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.000001) {
            return number_format($value, 0, ',', ' ');
        }

        return number_format($value, 2, ',', ' ');
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
