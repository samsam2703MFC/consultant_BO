<?php

namespace App\Services\Shipping;

use App\Services\Utils\TCPDFService;

class ShipmentPackagingReportPdfService
{
    /** @param array{date_from:string,date_to:string,summary:list<array<string,mixed>>,coverage:array<string,mixed>} $report */
    public function generate(array $report): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->brandPage('Raport opakowań BDO', ['ref' => $report['date_from'] . ' – ' . $report['date_to']]);
        $pdf->brandHeader('Raport opakowań BDO', [
            ['Okres', $report['date_from'] . ' – ' . $report['date_to']],
        ], 'Ewidencja opakowań wysłanych z produktami');

        $coverage = $report['coverage'];
        $pdf->brandSection('Kontrola kompletności ewidencji');
        $coverageRows = [];
        foreach ([
            'Wysyłki w okresie' => $coverage['shipment_count'],
            'Ewidencje kompletne' => $coverage['complete_evidence_count'],
            'Ewidencje z brakami' => $coverage['requires_data_count'],
            'Wysyłki bez ewidencji' => $coverage['without_evidence_count'],
            'Ewidencje utworzone z danych bieżących' => $coverage['current_data_evidence_count'],
        ] as $label => $value) {
            $coverageRows[] = [$label, (string)$value];
        }
        $pdf->brandTable([
            ['label' => 'Wskaźnik', 'w' => 140],
            ['label' => 'Liczba', 'w' => 46, 'align' => 'R'],
        ], $coverageRows, ['font' => 8.5]);

        $pdf->brandSection('Masa opakowań według kategorii BDO');
        $rows = [];
        foreach ($report['summary'] as $row) {
            $grams = (float)$row['total_weight_g'];
            $rows[] = [$this->categoryLabel($row['category']) . ' (' . $this->usageLabel($row['usage_type']) . ')',
                $this->formatNumber($grams) . ' g', $this->formatNumber($grams / 1000) . ' kg'];
        }
        if (empty($report['summary'])) {
            $rows[] = ['Brak kompletnych pozycji opakowaniowych w wybranym okresie.', '', ''];
        }
        $pdf->brandTable([
            ['label' => 'Kategoria BDO i rodzaj użycia', 'w' => 106],
            ['label' => 'Masa [g]', 'w' => 40, 'align' => 'R'],
            ['label' => 'Masa [kg]', 'w' => 40, 'align' => 'R'],
        ], $rows, ['font' => 8.5]);

        if ((int)$coverage['requires_data_count'] > 0 || (int)$coverage['without_evidence_count'] > 0) {
            $pdf->brandCallout('Uwaga: podsumowanie mas obejmuje wyłącznie kompletne pozycje ewidencji. Uzupełnij wysyłki oznaczone brakami przed wykorzystaniem raportu do rozliczenia.');
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
