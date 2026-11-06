<?php

namespace App\Services\Shipping;

use App\Services\Utils\TCPDFService;

class ShipmentPpwrReportPdfService
{
    public function generate(array $report): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $period = $report['date_from'] . ' – ' . $report['date_to'];

        $pdf->brandPage('Raport opakowań PPWR', ['ref' => $period]);
        $pdf->brandHeader('Raport opakowań PPWR', [['Okres', $period]], 'Opakowania wysłane z produktami');
        $this->writeCoverage($pdf, $report['coverage']);
        $this->writeSummary($pdf, 'Łączna masa wysłanych opakowań według materiału PPWR', $report['summary']);

        // Klient = osobny dokument (własna numeracja stron)
        foreach ($report['customers'] as $customer) {
            $pdf->brandPage('Raport opakowań PPWR — klient', ['ref' => (string) $customer['customer_name']]);
            $pdf->brandHeader('Raport opakowań PPWR — klient', [['Okres', $period]], (string) $customer['customer_name']);
            $this->writeSummary($pdf, 'Masa opakowań otrzymanych przez klienta według materiału PPWR', $customer['summary']);
        }

        return [
            'filename' => 'RAPORT_PPWR_' . $report['date_from'] . '_' . $report['date_to'] . '.pdf',
            'file_string' => $pdf->Output('', 'S'),
        ];
    }

    private function writeCoverage(TCPDFService $pdf, array $coverage): void
    {
        $pdf->brandSection('Zakres danych');
        $rows = [];
        foreach ([
            'Wysyłki w okresie' => $coverage['shipment_count'],
            'Wysyłki bez ewidencji opakowań' => $coverage['without_evidence_count'],
            'Wysyłki z brakami BDO' => $coverage['requires_data_count'],
            'Pozycje bez snapshotu PPWR' => $coverage['missing_ppwr_snapshot_count'],
            'Raporty PPWR odtworzone z danych bieżących' => $coverage['current_ppwr_reconstruction_count'],
            'Ewidencje odtworzone z danych bieżących' => $coverage['current_data_evidence_count'],
        ] as $label => $value) {
            $rows[] = [$label, (string)$value];
        }
        $pdf->brandTable([
            ['label' => 'Wskaźnik', 'w' => 140],
            ['label' => 'Liczba', 'w' => 46, 'align' => 'R'],
        ], $rows, ['font' => 8.5]);
    }

    private function writeSummary(TCPDFService $pdf, string $title, array $summary): void
    {
        $pdf->brandSection($title);
        $rows = [];
        foreach ($summary as $row) {
            $grams = (float)$row['total_weight_g'];
            $rows[] = [$this->materialLabel($row['material']), $this->formatNumber($grams) . ' g', $this->formatNumber($grams / 1000) . ' kg'];
        }
        if (empty($summary)) {
            $rows[] = ['Brak kompletnych pozycji opakowaniowych w wybranym okresie.', '', ''];
        }
        $pdf->brandTable([
            ['label' => 'Materiał dominujący PPWR', 'w' => 110],
            ['label' => 'Masa [g]', 'w' => 38, 'align' => 'R'],
            ['label' => 'Masa [kg]', 'w' => 38, 'align' => 'R'],
        ], $rows, ['font' => 8.5]);
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
