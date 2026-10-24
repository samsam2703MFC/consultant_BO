<?php
namespace App\Services\Recipe;

use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Repositories\Workshop\RecipeProducerRepository;

/**
 * Strona « Kalkulacja » receptury (produkt gotowy, półprodukt, kompozycja) : koszt surowców całej receptury
 * i na jednostkę (karton / sztuka, kg / l), drzewo składników z udziałem w koszcie, surowce zsumowane po całym
 * drzewie, opakowania vs surowce spożywcze, brakujące ceny, cena standardowa produktu i udział surowców w cenie.
 * Koszt = to samo wyliczenie co dotąd (RecipeRepository::getRecipeCalculation).
 */
final class RecipeCalculationViewService
{
    public const KINDS = [
        'product' => ['label' => 'Produkt gotowy', 'base' => '/recipes', 'list' => 'Produkty gotowe', 'tab' => 'product'],
        'semi' => ['label' => 'Półprodukt', 'base' => '/semi-products', 'list' => 'Półprodukty', 'tab' => 'semi'],
        'composition' => ['label' => 'Kompozycja', 'base' => '/compositions', 'list' => 'Kompozycje', 'tab' => 'composition'],
    ];
    /** product_recipe.id_type → strona receptury */
    public const TYPE_KIND = [1 => 'composition', 2 => 'semi', 3 => 'product'];
    /** Różnica (zł) między kosztem zapisanym (karta produktu, marże) a bieżącym wyliczeniem, od której ostrzegamy. */
    public const STORED_TOLERANCE = 0.05;

    public function __construct(private RecipeRepository $recipes, private RecipeProducerRepository $producers) {}

    public function build(int $id, string $kind): array
    {
        $calc = $this->recipes->getRecipeCalculation($id);
        $context = $this->recipes->calculationContext($id);
        if (!$calc || !$context) throw new CustomException('Nie znaleziono receptury.', 'errors', 404);
        $kind = isset(self::KINDS[$kind]) ? $kind : 'product';

        $elements = $calc['elements'] ?? [];
        $total = self::total($elements);
        $rows = self::rows($elements, $total);
        // grupa przygotowująca : ta receptura (półprodukt / kompozycja) i jej podreceptury
        $producers = $this->producers->forRecipes(array_merge([$id], array_map(fn($r) => $r['id'], array_filter($rows, fn($r) => $r['sub']))));
        foreach ($rows as &$row) $row['producer'] = $row['sub'] ? ($producers[$row['id']] ?? null) : null;
        unset($row);
        $materials = self::materials($elements, $total);
        $packaging = array_sum(array_map(fn($m) => $m['packaging'] ? $m['cost'] : 0.0, $materials));
        $yield = (float) $calc['yield_quantity'];
        $product = $context['product'];
        $ppc = (int) ($product['ppc'] ?? $calc['ppc'] ?? 0);

        $unitCosts = $kind === 'product'
            ? array_filter([
                ['label' => 'karton', 'value' => $yield > 0 ? $total / $yield : $total],
                $ppc > 0 ? ['label' => 'sztuka (1/' . $ppc . ')', 'value' => ($yield > 0 ? $total / $yield : $total) / $ppc] : null,
            ])
            : [self::perUnit($total, $yield, (string) $calc['unit_name'])];

        $sale = null;
        if ($kind === 'product' && $product && (float) $product['standard_price'] > 0) {
            $rate = $context['exchange_rate'];
            $pricePln = $rate > 0 ? (float) $product['standard_price'] * $rate : null;
            $carton = $yield > 0 ? $total / $yield : $total;
            $sale = [
                'price_eur' => (float) $product['standard_price'],
                'price_pln' => $pricePln,
                'rate' => $rate,
                'food_share' => $pricePln ? $carton / $pricePln * 100 : null,
            ];
        }

        $stored = $context['recipe']['precalculated_material_cost'];
        return [
            'kind' => $kind,
            'kind_info' => self::KINDS[$kind],
            'recipe' => [
                'id' => (int) $calc['id'], 'name' => (string) $calc['name'], 'yield' => $yield, 'unit' => (string) $calc['unit_name'], 'ppc' => $ppc,
            ],
            'product' => $product ? ['id' => (int) $product['id'], 'name' => (string) $product['name'], 'category' => $product['category']] : null,
            'total' => $total,
            'unit_costs' => array_values($unitCosts),
            'packaging' => $packaging,
            'food' => $total - $packaging,
            'sale' => $sale,
            'stored' => $stored === null ? null : (float) $stored,
            'stored_differs' => $stored !== null && abs((float) $stored - $total) > self::STORED_TOLERANCE,
            'rows' => $rows,
            'materials' => $materials,
            'missing' => array_values(array_filter($materials, fn($m) => $m['missing'])),
            'cycles' => array_values(array_filter($rows, fn($r) => $r['cycle'])),
            'producer' => $kind !== 'product' ? ($producers[$id] ?? null) : null,
            'groups' => $kind !== 'product' ? $this->producers->groups() : [],
            'used_in' => array_map(fn($u) => ['id' => (int) $u['id'], 'name' => (string) $u['name'],
                'url' => (self::KINDS[self::TYPE_KIND[(int) $u['id_type']] ?? 'product']['base']) . '/' . (int) $u['id'] . '/calculation'], $context['used_in']),
        ];
    }

    /** Koszt receptury = suma pozycji pierwszego poziomu (jak getRecipeSummary). */
    public static function total(array $elements): float
    {
        return round(array_sum(array_map(fn($e) => (float) ($e['price_for_required_quantity'] ?? 0), $elements)), 2);
    }

    /**
     * Drzewo składników jako wiersze tabeli : poziom, klucz rodzica (zwijanie), ilość, cena jednostkowa, koszt, udział.
     * Pozycje danego poziomu od najdroższej.
     */
    public static function rows(array $elements, float $total, int $depth = 0, string $parent = ''): array
    {
        usort($elements, fn($a, $b) => ((float) ($b['price_for_required_quantity'] ?? 0)) <=> ((float) ($a['price_for_required_quantity'] ?? 0)));
        $rows = [];
        foreach ($elements as $i => $e) {
            $key = ($parent === '' ? '' : $parent . '.') . $i;
            $isSub = ($e['type'] ?? '') === 'sub-recipe';
            $qty = (float) ($e['required_quantity'] ?? 0);
            $cost = (float) ($e['price_for_required_quantity'] ?? 0);
            $unitPrice = $isSub ? ($qty > 0 ? $cost / $qty : null) : (float) ($e['suggested_base_unit_price'] ?? 0);
            $rows[] = [
                'key' => $key, 'parent' => $parent, 'depth' => $depth, 'sub' => $isSub,
                'id' => (int) ($e['id'] ?? 0), 'name' => (string) ($e['name'] ?? ''), 'unit' => (string) ($e['unit_name'] ?? ''),
                'qty' => $qty, 'unit_price' => $unitPrice, 'price_unit' => self::priceUnit((string) ($e['unit_name'] ?? '')),
                'price_factor' => self::priceFactor((string) ($e['unit_name'] ?? '')),
                'cost' => $cost, 'share' => $total > 0 ? $cost / $total * 100 : 0.0,
                'packaging' => !$isSub && !empty($e['is_packaging']),
                'missing' => !$isSub && $qty > 0 && (float) ($e['suggested_base_unit_price'] ?? 0) <= 0,
                'cycle' => !empty($e['cycle_detected']),
                'kind' => $isSub ? (self::TYPE_KIND[(int) ($e['id_type'] ?? 0)] ?? null) : null,
                'children' => $isSub ? count($e['elements'] ?? []) : 0,
            ];
            if ($isSub && !empty($e['elements'])) $rows = array_merge($rows, self::rows($e['elements'], $total, $depth + 1, $key));
        }
        return $rows;
    }

    /** Surowce zsumowane po całym drzewie (ten sam surowiec w kilku półproduktach = jedna pozycja), od najdroższego. */
    public static function materials(array $elements, float $total): array
    {
        $out = [];
        $walk = function (array $items) use (&$walk, &$out): void {
            foreach ($items as $e) {
                if (($e['type'] ?? '') === 'sub-recipe') { $walk($e['elements'] ?? []); continue; }
                $id = (int) ($e['id'] ?? 0);
                $out[$id] ??= ['id' => $id, 'name' => (string) $e['name'], 'unit' => (string) ($e['unit_name'] ?? ''), 'qty' => 0.0, 'cost' => 0.0,
                    'unit_price' => (float) ($e['suggested_base_unit_price'] ?? 0), 'packaging' => !empty($e['is_packaging']), 'uses' => 0];
                $out[$id]['qty'] += (float) ($e['required_quantity'] ?? 0);
                $out[$id]['cost'] += (float) ($e['price_for_required_quantity'] ?? 0);
                $out[$id]['uses']++;
            }
        };
        $walk($elements);
        foreach ($out as &$m) {
            $m['share'] = $total > 0 ? $m['cost'] / $total * 100 : 0.0;
            $m['missing'] = $m['qty'] > 0 && $m['unit_price'] <= 0;
            $m['price_unit'] = self::priceUnit($m['unit']);
            $m['price_factor'] = self::priceFactor($m['unit']);
        }
        unset($m);
        usort($out, fn($a, $b) => [$b['cost'], $a['name']] <=> [$a['cost'], $b['name']]);
        return array_values($out);
    }

    /** Koszt na jednostkę wydajności : g → kg, ml → l, inaczej jednostka receptury. */
    public static function perUnit(float $total, float $yield, string $unit): array
    {
        $factor = self::priceFactor($unit);
        $per = $yield > 0 ? $total / $yield * $factor : $total;
        return ['label' => self::priceUnit($unit), 'value' => $per];
    }

    /** Cena surowca pokazywana na kg / l zamiast na g / ml. */
    public static function priceUnit(string $unit): string
    {
        return match (mb_strtolower(trim($unit))) { 'g' => 'kg', 'ml' => 'l', '' => 'j.', default => $unit };
    }

    public static function priceFactor(string $unit): float
    {
        return in_array(mb_strtolower(trim($unit)), ['g', 'ml'], true) ? 1000.0 : 1.0;
    }
}
