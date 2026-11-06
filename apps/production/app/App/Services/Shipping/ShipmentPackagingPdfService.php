<?php

namespace App\Services\Shipping;

use App\Services\Document\WDTService;
use App\Services\Utils\DocumentLabels;
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
        $wz = $data['wz'] ?? [];
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->brandPage('Zestawienie opakowań — wysyłka WDT', ['ref' => (string) ($wz['doc_num'] ?? $shipmentId), 'company' => DocumentLabels::companyLine($wz)]);
        $pdf->brandHeader('Zestawienie opakowań', [
            ['WZ', $wz['doc_num'] ?? '-'],
            ['Data wysyłki', $data['shipment']['shipment_date']],
            ['Status', $this->statusLabel($data['record']['status'])],
        ], 'Wysyłka WDT · ewidencja opakowań BDO');
        $pdf->brandParties([
            'label' => 'Wydający',
            'name' => $wz['consignor_name'] ?? '-',
            'lines' => [$wz['consignor_address_1'] ?? '', $wz['consignor_address_2'] ?? '', 'BDO: ' . WDTService::BDO],
        ], [
            'label' => 'Odbiorca',
            'name' => $wz['consignee_name'] ?? '-',
            'lines' => [$wz['consignee_address_1'] ?? '', $wz['consignee_address_2'] ?? ''],
        ]);
        if (($data['record']['evidence_basis'] ?? 'SHIPMENT_SNAPSHOT') === 'CURRENT_DATA_RECONSTRUCTION') {
            $pdf->brandCallout('Uwaga: ewidencję utworzono po wysyłce na podstawie bieżącej receptury i bieżących danych opakowań. Nie odtwarza ona danych historycznych z dnia wysyłki.');
        }

        $rows = [];
        foreach ($data['items'] as $item) {
            $rows[] = [
                $item['product_name_snapshot'],
                $item['material_name_snapshot'] ?: '—',
                $this->categoryLabel($item['bdo_material_category_snapshot']),
                $item['source_unit_snapshot'] ?: '—',
                $item['packaging_units_total'] !== null ? $this->numberWithoutIntegerDecimals($item['packaging_units_total']) : '—',
                $item['total_weight_g'] !== null ? $this->numberWithoutIntegerDecimals($item['total_weight_g']) : '—',
                $this->itemStatusLabel($item['calculation_status']),
            ];
        }
        // brandTable sam łamie strony całymi wierszami i powtarza nagłówek
        $pdf->brandTable([
            ['label' => 'Produkt', 'w' => 42],
            ['label' => 'Element opakowania', 'w' => 35],
            ['label' => 'Kategoria BDO', 'w' => 25],
            ['label' => 'Jedn.', 'w' => 14, 'align' => 'R'],
            ['label' => 'Ilość', 'w' => 16, 'align' => 'R'],
            ['label' => 'Masa [g]', 'w' => 18, 'align' => 'R'],
            ['label' => 'Status', 'w' => 22, 'align' => 'R'],
        ], $rows, ['font' => 7.5]);

        $pdf->brandSection('Podsumowanie kompletnej masy według materiału');
        $summaryRows = [];
        foreach ($data['summary'] as $summary) {
            $grams = (float)$summary['total_weight_g'];
            $summaryRows[] = [$this->categoryLabel($summary['category']) . ' (' . $this->usageLabel($summary['usage_type']) . ')',
                $this->numberWithoutIntegerDecimals($grams / 1000) . ' kg'];
        }
        $pdf->brandTable([
            ['label' => 'Kategoria BDO i rodzaj użycia', 'w' => 126],
            ['label' => 'Masa [kg]', 'w' => 60, 'align' => 'R'],
        ], $summaryRows, ['font' => 8]);

        if ($data['record']['status'] === 'REQUIRES_DATA') {
            $pdf->brandCallout('Uwaga: dokument zawiera pozycje bez kompletnych danych. Podsumowanie obejmuje wyłącznie pozycje kompletne.');
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
