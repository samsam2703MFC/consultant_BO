<?php

namespace App\Services\Shipping;

use App\Services\Utils\TCPDFService;

class ShipmentPpwrPdfService
{
    public function __construct(private ShipmentPackagingService $packagingService)
    {
    }

    public function generateDocumentPDF(int $shipmentId): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $document = $this->buildDocumentFromShipment($pdf, $shipmentId);

        return [
            'filename' => $document['filename'],
            'file_string' => $pdf->Output('', 'S'),
        ];
    }

    public function buildDocumentFromShipment(TCPDFService $pdf, int $shipmentId): array
    {
        $data = $this->packagingService->getSnapshotForShipment($shipmentId);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(0, 8, 'Raport opakowań PPWR — wysyłka', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(0, 6, 'WZ: ' . ($data['wz']['doc_num'] ?? '-') . ' | Data wysyłki: ' . $data['shipment']['shipment_date'], 0, 1);
        $pdf->Cell(0, 6, 'Odbiorca: ' . ($data['wz']['consignee_name'] ?? '-'), 0, 1);
        if (($data['record']['ppwr_evidence_basis'] ?? null) === 'CURRENT_DATA_RECONSTRUCTION') {
            $pdf->SetTextColor(160, 90, 0);
            $pdf->MultiCell(0, 6, 'Uwaga: raport PPWR odtworzono po wysyłce z bieżącej receptury i bieżących profili PPWR. Nie jest to historyczny stan danych z dnia wysyłki.', 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, 'Łączna masa wysłanych opakowań według materiału dominującego PPWR', 0, 1);
        $widths = [110, 38, 38];
        $pdf->SetFont('dejavusans', 'B', 8);
        foreach (['Materiał dominujący PPWR', 'Masa [g]', 'Masa [kg]'] as $index => $header) {
            $pdf->Cell($widths[$index], 7, $header, 1, 0, 'C');
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 8);
        foreach ($data['ppwr_summary'] as $row) {
            $grams = (float)$row['total_weight_g'];
            $pdf->Cell($widths[0], 7, $this->materialLabel($row['material']), 1);
            $pdf->Cell($widths[1], 7, $this->formatNumber($grams) . ' g', 1, 0, 'R');
            $pdf->Cell($widths[2], 7, $this->formatNumber($grams / 1000) . ' kg', 1, 1, 'R');
        }
        if (empty($data['ppwr_summary'])) {
            $pdf->Cell(array_sum($widths), 7, 'Brak kompletnych pozycji opakowaniowych w tej wysyłce.', 1, 1, 'C');
        }

        return [
            'filename' => 'RAPORT_PPWR_WYSYLKA_' . ($data['wz']['doc_num'] ?? $shipmentId) . '.pdf',
            'file_string' => $pdf,
        ];
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
