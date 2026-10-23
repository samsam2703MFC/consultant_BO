<?php

namespace App\Services\Shipping;

use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

class ShipmentPpwrPdfService
{
    public function __construct(private ShipmentPackagingService $packagingService)
    {
    }

    public function generateDocumentPDF(int $shipmentId): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);

        $document = $this->buildDocumentFromShipment($pdf, $shipmentId);

        return [
            'filename' => $document['filename'],
            'file_string' => $pdf->Output('', 'S'),
        ];
    }

    public function buildDocumentFromShipment(TCPDFService $pdf, int $shipmentId): array
    {
        $data = $this->packagingService->getSnapshotForShipment($shipmentId);
        $wz = $data['wz'] ?? [];
        // Osobny dokument (własna numeracja stron) także w zbiorczym pliku dokumentów dostawy
        $pdf->brandPage('Raport opakowań PPWR — wysyłka', ['ref' => (string) ($wz['doc_num'] ?? $shipmentId), 'company' => DocumentLabels::companyLine($wz)]);
        $pdf->brandHeader('Raport opakowań PPWR', [
            ['WZ', $wz['doc_num'] ?? '-'],
            ['Data wysyłki', $data['shipment']['shipment_date']],
        ], 'Wysyłka');
        $pdf->brandParties([
            'label' => 'Odbiorca',
            'name' => $wz['consignee_name'] ?? '-',
            'lines' => [$wz['consignee_address_1'] ?? '', $wz['consignee_address_2'] ?? ''],
        ]);
        if (($data['record']['ppwr_evidence_basis'] ?? null) === 'CURRENT_DATA_RECONSTRUCTION') {
            $pdf->brandCallout('Uwaga: raport PPWR odtworzono po wysyłce z bieżącej receptury i bieżących profili PPWR. Nie jest to historyczny stan danych z dnia wysyłki.');
        }

        $pdf->brandSection('Łączna masa wysłanych opakowań według materiału dominującego PPWR');
        $rows = [];
        foreach ($data['ppwr_summary'] as $row) {
            $grams = (float)$row['total_weight_g'];
            $rows[] = [$this->materialLabel($row['material']), $this->formatNumber($grams) . ' g', $this->formatNumber($grams / 1000) . ' kg'];
        }
        if (empty($data['ppwr_summary'])) {
            $rows[] = ['Brak kompletnych pozycji opakowaniowych w tej wysyłce.', '', ''];
        }
        $pdf->brandTable([
            ['label' => 'Materiał dominujący PPWR', 'w' => 110],
            ['label' => 'Masa [g]', 'w' => 38, 'align' => 'R'],
            ['label' => 'Masa [kg]', 'w' => 38, 'align' => 'R'],
        ], $rows, ['font' => 8.5]);

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
