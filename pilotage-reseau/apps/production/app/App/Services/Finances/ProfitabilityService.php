<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\WdtSalesRepository;

/**
 * Rentowność klienta w miesiącu : sprzedaż z jednej definicji WDT (bez dokumentów zastąpionych) i koszt surowców
 * z karty produktu (= koszt receptury) — te same liczby co Analiza sprzedaży i Rentowność.
 */
class ProfitabilityService
{
    public function __construct(private WdtSalesRepository $wdtSales)
    {
    }

    /** @return list<array{id:int, id_category:?int, category_name:string, name:string, sold_quantity:int, earned_value_pln:float, single_raw_cost:float, total_raw_cost:float}> */
    public function getSoldProductListByCustomerInMonth(int $idClient, string $ym): array
    {
        $rate = $this->wdtSales->eurRate();
        [$from, $to] = WdtSalesRepository::monthBounds($ym, $ym);
        $rows = $this->wdtSales->sales($from, $to, ['customer_id' => $idClient], ['product']);
        $cards = $this->wdtSales->productCards(array_map(fn($r) => (int) $r['product_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $card = $cards[(int) $r['product_id']] ?? null;
            $raw = (float) ($card['raw_cost'] ?? 0);
            $out[] = [
                'id' => (int) $r['product_id'],
                'id_category' => $card['id_category'] ?? null,
                'category_name' => $card['category_name'] ?? 'Bez kategorii',
                'name' => $r['product_name'],
                'sold_quantity' => (int) $r['cartons'],
                'earned_value_pln' => round((float) $r['value_eur'] * $rate, 2),
                'single_raw_cost' => $raw,
                'total_raw_cost' => round($raw * (int) $r['cartons'], 2),
            ];
        }
        usort($out, fn($a, $b) => [$a['category_name'], $a['name']] <=> [$b['category_name'], $b['name']]);
        return $out;
    }
}
