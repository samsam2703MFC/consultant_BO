<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Workshop\InvoicePriceImpactRepository;

/**
 * [349] Asystent « Ceny z faktur zakupu » (makieta 65) : co się zmieni, jeśli cena z faktury zostanie zatwierdzona.
 *
 * Dla surowca i nowej ceny (zł na jednostkę bazową, jak w kartotece) :
 *  - porównanie : cena w bazie, ostatnia inna faktura, najniższa cena innego dostawcy z 90 dni ;
 *  - produkty z tym surowcem w recepturze : zmiana kosztu na sztukę i na karton (ilość kupiona = receptura ÷ (1 − odpad),
 *    ta sama reguła co zapotrzebowanie), marża na surowcach przed / po (cena EUR × kurs z ustawień firmy),
 *    efekt miesięczny = zmiana na karton × kartony zamówione (średnia z 3 miesięcy) ;
 *  - razem : efekt / miesiąc i zużycie / miesiąc.
 * Tylko odczyt — zapis ceny robi istniejące « Zatwierdź » kreatora faktur.
 */
final class InvoicePriceImpactService
{
    private const MONTHS = 3;
    private const TOP = 5;

    public function __construct(private InvoicePriceImpactRepository $repo, private MaterialCostRepository $costs)
    {
    }

    /**
     * @param float $newPrice cena z faktury (zł / jednostka bazowa) ; $oldPrice cena w bazie (null : z kartoteki)
     */
    public function impact(int $materialId, float $newPrice, ?float $oldPrice, int $invoiceId = 0, ?int $supplierId = null): array
    {
        $old = $oldPrice ?? $this->catalogPrice($materialId);
        $delta = $old !== null ? $newPrice - $old : 0.0;
        $rate = $this->repo->eurRate();
        $rows = [];
        $total = 0.0;
        $use = 0.0;
        foreach ($this->repo->products($materialId, self::MONTHS) as $p) {
            $qty = (float) $p['qty_per_carton'];
            $ppc = max(1, (int) $p['ppc']);
            $perMonth = (float) $p['cartons'] / self::MONTHS;
            $dCarton = $qty * $delta;
            $raw = $p['raw_cost'] !== null ? (float) $p['raw_cost'] : null;
            $pricePln = $rate > 0 && (float) $p['standard_price'] > 0 ? (float) $p['standard_price'] * $rate : null;
            $effect = $dCarton * $perMonth;
            $total += $effect;
            $use += $qty * $perMonth;
            $rows[] = [
                'id' => (int) $p['id'], 'name' => (string) $p['name'], 'ppc' => $ppc, 'cartons_month' => round($perMonth, 1),
                'cost_piece' => $raw !== null ? round($raw / $ppc, 4) : null,
                'delta_piece' => round($dCarton / $ppc, 4), 'delta_carton' => round($dCarton, 4),
                'margin_before' => $pricePln && $raw !== null ? round((1 - $raw / $pricePln) * 100, 1) : null,
                'margin_after' => $pricePln && $raw !== null ? round((1 - ($raw + $dCarton) / $pricePln) * 100, 1) : null,
                'effect_month' => round($effect, 2),
            ];
        }
        // Najpierw produkty, które kosztują najwięcej w miesiącu, potem największa zmiana na sztukę.
        usort($rows, fn($a, $b) => [abs($b['effect_month']), abs($b['delta_piece'])] <=> [abs($a['effect_month']), abs($a['delta_piece'])]);
        $top = array_slice($rows, 0, self::TOP);
        $rest = array_slice($rows, self::TOP);
        $last = $this->repo->lastInvoicePrice($materialId, $invoiceId);
        $other = $this->repo->otherSupplierBest($materialId, $supplierId);
        return [
            'material_id' => $materialId, 'new_price' => $newPrice, 'old_price' => $old,
            'change_pct' => $old ? round($delta / $old * 100, 2) : null,
            'last_invoice' => $last && $last['price'] !== null ? ['price' => (float) $last['price'], 'date' => $last['issue_date'], 'number' => $last['number'],
                'supplier' => $last['supplier_name']] : null,
            'other_best' => $other ? ['price' => (float) $other['price'], 'date' => $other['day'], 'supplier' => $other['supplier_name']] : null,
            'products' => $top,
            'rest' => $rest ? ['count' => count($rest), 'effect_month' => round(array_sum(array_column($rest, 'effect_month')), 2)] : null,
            'products_count' => count($rows),
            'effect_month' => round($total, 2), 'use_month' => round($use, 3),
            'months' => self::MONTHS, 'eur_rate' => $rate ?: null,
        ];
    }

    /**
     * Efekt miesięczny zmian cen (krok 3 i podsumowanie) : zużycie / miesiąc (średnia z 3 mies.) × (nowa − stara cena).
     * @param array<int, array{new: float, old: float}> $changes surowiec → ceny (zł / jednostka bazowa)
     * @return array<int, float>
     */
    public function monthlyEffects(array $changes): array
    {
        if (!$changes) return [];
        $use = $this->costs->consumption(self::MONTHS);
        $out = [];
        foreach ($changes as $mid => $c) $out[$mid] = round(($use[$mid] ?? 0.0) / self::MONTHS * ($c['new'] - $c['old']), 2);
        return $out;
    }

    /** Ostatnie zatwierdzenie faktury z cenami w kartotece (przycisk zielony). */
    public function lastDone(): ?string
    {
        return $this->repo->lastPricesImport();
    }

    private function catalogPrice(int $materialId): ?float
    {
        $h = $this->costs->priceHistory($materialId);
        $last = $h ? end($h) : null;
        return $last && $last['price_per_unit'] !== null ? (float) $last['price_per_unit'] : null;
    }
}
