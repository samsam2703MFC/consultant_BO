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

        $pdf->brandPage('Lista zapotrzebowania materiałowego', ['ref' => date('d.m.Y')]);
        $pdf->brandHeader('Lista zapotrzebowania materiałowego', [
            ['Zamówienia', (string) count($data['order_id'] ?? [])],
            ['Wydruk', date('d.m.Y H:i')],
        ], $stockMinimum ? 'Uwzględniono stany magazynowe' : 'Nie uwzględniono stanów magazynowych');

        if (count($rows) === 0) {
            $pdf->brandText('Brak danych do wyświetlenia.');
            return $pdf;
        }

        $this->renderTable($pdf, $rows);

        return $pdf;
    }

    /**
     * Tabela materiałów : ilości w jednostce wyświetlanej (g/ml → kg/l powyżej 1000), wartość przybliżona w PLN,
     * suma kosztu w ostatnim wierszu.
     */
    private function renderTable(TCPDFService $pdf, array $rows): void
    {
        $columns = [
            ['label' => 'Materiał', 'w' => 78],
            ['label' => 'Wymagane', 'w' => 24, 'align' => 'R'],
            ['label' => 'Na stanie', 'w' => 24, 'align' => 'R'],
            ['label' => 'Do zamówienia', 'w' => 24, 'align' => 'R'],
            ['label' => 'Jm', 'w' => 14, 'align' => 'C'],
            ['label' => 'Wartość (PLN)', 'w' => 30, 'align' => 'R'],
        ];

        $sumApproxValue = 0.0;
        $tableRows = [];

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

            $tableRows[] = [$material, (string)$required, (string)$inStock, (string)$toOrder, $displayUnit, (string)$approxValue];
        }

        $tableRows[] = ['_total' => ['Przybliżony koszt', '', '', '', '', UnitMapper::formatNumber($sumApproxValue, 2)]];
        $pdf->brandTable($columns, $tableRows, ['font' => 8.5, 'min_h' => 6]);
    }

}
