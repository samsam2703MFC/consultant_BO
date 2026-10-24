<?php
namespace App\Services\Purchasing;

use App\Exceptions\CustomException;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Optimisation du coût des matières premières : dépense annuelle par matière (consommation
 * réelle × prix courant), évolution du prix, meilleure offre valable et gain possible, impact
 * d'un changement de prix sur le coût des produits.
 */
final class CostOptimizationService
{
    public function __construct(private MaterialCostRepository $costs, private ParamRepository $params)
    {
    }

    /** Prix affichés par kg / l plutôt que par g / ml. */
    public static function displayUnit(?string $unit): array
    {
        return match ($unit) {
            'g' => ['label' => 'kg', 'factor' => 1000],
            'ml' => ['label' => 'l', 'factor' => 1000],
            default => ['label' => (string) ($unit ?: 'szt'), 'factor' => 1],
        };
    }

    public function overview(): array
    {
        $months = $this->months();
        $consumption = $this->costs->consumption($months);
        $history = $this->historyByMaterial();
        $offers = [];
        foreach ($this->costs->validOffers() as $offer) {
            $offers[(int) $offer['id_raw_material']] ??= $offer; // trié par prix : la première est la meilleure
        }
        $nc = $this->costs->supplierNcCounts($months);
        $alertPct = (float) $this->params->get('purchasing.price_alert_pct', '10');

        $rows = [];
        $totals = ['spend' => 0.0, 'saving' => 0.0, 'alerts' => 0, 'with_offer' => 0];
        foreach ($this->costs->materials() as $m) {
            $id = (int) $m['id'];
            $unit = self::displayUnit($m['unit']);
            $price = (float) $m['price_per_unit'];
            $qty = $consumption[$id] ?? 0.0;
            $spend = $qty * $price;
            $start = $this->priceAt($history[$id] ?? [], strtotime("-{$months} months"));
            $change = $start > 0 ? ($price - $start) / $start * 100 : null;
            $best = $offers[$id] ?? null;
            $bestPrice = $best ? (float) $best['price_per_base'] : null;
            $saving = $best && $bestPrice < $price ? ($price - $bestPrice) * $qty : 0.0;

            $totals['spend'] += $spend;
            $totals['saving'] += $saving;
            if ($change !== null && $change >= $alertPct) $totals['alerts']++;
            if ($best) $totals['with_offer']++;

            $rows[] = [
                'id' => $id,
                'name' => $m['name'],
                'name_fr' => $m['name_fr'],
                'category' => $m['category'],
                'is_packaging' => (bool) $m['is_part_of_package'],
                'supplier_id' => $m['id_current_supplier'] ? (int) $m['id_current_supplier'] : null,
                'supplier' => $m['supplier_name'],
                'unit' => $unit['label'],
                'price' => $price * $unit['factor'],
                'change_pct' => $change,
                'alert' => $change !== null && $change >= $alertPct,
                'qty' => $qty / $unit['factor'],
                'spend' => $spend,
                'best_offer' => $best ? ['id' => (int) $best['id'], 'supplier' => $best['supplier_name'], 'price' => $bestPrice * $unit['factor']] : null,
                'saving' => $saving,
                'supplier_nc' => $m['id_current_supplier'] ? ($nc[(int) $m['id_current_supplier']] ?? 0) : 0,
                'last_change' => isset($history[$id]) ? end($history[$id])['timestamp'] : null,
            ];
        }
        usort($rows, fn($a, $b) => $b['spend'] <=> $a['spend'] ?: strcmp($a['name'], $b['name']));
        return ['months' => $months, 'alert_pct' => $alertPct, 'totals' => $totals, 'rows' => $rows];
    }

    public function material(int $id): array
    {
        $m = $this->costs->material($id) ?? throw new CustomException('Nie znaleziono surowca.', 'errors', 404);
        $months = $this->months();
        $unit = self::displayUnit($m['unit']);
        $qty = $this->costs->consumption($months)[$id] ?? 0.0;
        $history = array_map(fn($h) => [
            'date' => $h['timestamp'],
            'supplier' => $h['supplier_name'],
            'pack_price' => (float) $h['pack_price'],
            'pack_size' => (float) $h['package_size_in_base_unit'] / $unit['factor'],
            'price' => (float) $h['price_per_unit'] * $unit['factor'],
        ], $this->costs->priceHistory($id));
        $offers = array_map(fn($o) => [
            'id' => (int) $o['id'],
            'supplier' => $o['supplier_name'],
            'pack_price' => (float) $o['pack_price'],
            'pack_size' => (float) $o['pack_size_base'] / $unit['factor'],
            'price' => (float) $o['price_per_base'] * $unit['factor'],
            'valid_to' => $o['valid_to'],
            'lead_time_days' => $o['lead_time_days'],
            'min_order_qty' => $o['min_order_qty'],
            'saving' => max(0, ((float) $m['price_per_unit'] - (float) $o['price_per_base']) * $qty),
        ], $this->costs->validOffers($id));

        return [
            'id' => $id,
            'name' => $m['name'],
            'name_fr' => $m['name_fr'],
            'unit' => $unit['label'],
            'unit_factor' => $unit['factor'],
            'base_unit' => $m['unit'],
            'price' => (float) $m['price_per_unit'] * $unit['factor'],
            'supplier_id' => $m['id_current_supplier'] ? (int) $m['id_current_supplier'] : null,
            'supplier' => $m['supplier_name'],
            'pack_size' => (float) $m['package_size_in_base_unit'] / $unit['factor'],
            'qty' => $qty / $unit['factor'],
            'spend' => $qty * (float) $m['price_per_unit'],
            'months' => $months,
            'history' => $history,
            'offers' => $offers,
            'products' => $this->simulate($id, null)['products'],
        ];
    }

    /**
     * Impact d'un nouveau prix (par kg / l / szt affiché) sur les produits qui utilisent la matière.
     * Sans prix : situation actuelle (part de la matière dans le coût de chaque produit).
     */
    public function simulate(int $id, ?float $newDisplayPrice): array
    {
        $m = $this->costs->material($id) ?? throw new CustomException('Nie znaleziono surowca.', 'errors', 404);
        $unit = self::displayUnit($m['unit']);
        $current = (float) $m['price_per_unit'];
        $new = $newDisplayPrice === null ? $current : $newDisplayPrice / $unit['factor'];
        $products = [];
        $annual = 0.0;
        foreach ($this->costs->productsUsing($id, $this->months()) as $p) {
            $qtyPerCarton = (float) $p['qty_per_carton'];
            $materialCost = $qtyPerCarton * $current;
            $delta = $qtyPerCarton * ($new - $current);
            $recipeCost = (float) $p['precalculated_material_cost'];
            $annual += $delta * (float) $p['cartons'];
            $products[] = [
                'id' => (int) $p['id'],
                'name' => $p['name'],
                'cartons' => (float) $p['cartons'],
                'material_cost_per_carton' => $materialCost,
                'share_pct' => $recipeCost > 0 ? $materialCost / $recipeCost * 100 : null,
                'delta_per_carton' => $delta,
                'delta_pct' => $recipeCost > 0 ? $delta / $recipeCost * 100 : null,
                'delta_per_piece' => (int) $p['ppc'] > 0 ? $delta / (int) $p['ppc'] : null,
            ];
        }
        return ['new_price' => $new * $unit['factor'], 'annual_delta' => $annual, 'products' => $products];
    }

    private function months(): int
    {
        return max(1, min(36, (int) $this->params->get('purchasing.consumption_months', '12')));
    }

    /** @return array<int,list<array>> */
    private function historyByMaterial(): array
    {
        $by = [];
        foreach ($this->costs->priceHistory() as $row) {
            $by[(int) $row['id_raw_material']][] = $row;
        }
        return $by;
    }

    /** Prix par unité de base en vigueur à une date (dernier changement avant elle, sinon le premier connu). */
    private function priceAt(array $history, int $timestamp): float
    {
        $price = 0.0;
        foreach ($history as $row) {
            if (strtotime($row['timestamp']) > $timestamp) {
                return $price > 0 ? $price : (float) $row['price_per_unit'];
            }
            $price = (float) $row['price_per_unit'];
        }
        return $price;
    }
}
