<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Repositories\Workshop\OutputRepository;

/**
 * Tablet grupy, zakładka « Receptury » : karty grupy z recepturą i drzewo receptury (podreceptury, surowce,
 * opakowania, etapy przygotowania, etapy produkcji karty).
 *
 * Ilości drzewa na jednostkę bazową : produkt = 1 karton (receptura produktu jest na karton), półprodukt = 1 kg
 * (wydajność receptury w g / kg) ; tablet mnoży przez wybraną ilość (karton, partia, plan dnia, własna).
 */
final class KioskRecipeService
{
    private const MAX_DEPTH = 6;

    public function __construct(private OutputRepository $repo)
    {
    }

    /** Karty grupy tabletu z recepturą ; plan dnia (Gantt tygodnia) dla podpowiedzi ilości. */
    public function list(ApiContext $context, ?string $date = null): array
    {
        $groupId = $this->groupId($context);
        $day = $date !== null && $date !== '' ? $date : date('Y-m-d');
        $items = array_values(array_filter($this->repo->groupItems($groupId), fn($i) => $i['id_recipe'] !== null));
        $planned = $this->repo->planned($day, array_map(fn($i) => (int) $i['id'], $items));
        $rows = array_map(fn($i) => $this->itemRow($i) + ['planned' => isset($planned[(int) $i['id']]) ? round($planned[(int) $i['id']], 3) : null], $items);
        usort($rows, fn($a, $b) => [$a['planned'] === null, $a['name']] <=> [$b['planned'] === null, $b['name']]);
        return ['date' => $day, 'items' => $rows];
    }

    /** Drzewo receptury karty na jednostkę bazową (karton / kg / receptura). */
    public function recipe(int $itemId, ApiContext $context, ?string $date = null): array
    {
        $this->groupId($context);
        $item = $this->repo->item($itemId) ?? throw ApiException::notFound('item', $itemId);
        if ($item['id_recipe'] === null) throw new ApiException('ITEM_WITHOUT_RECIPE', 409);
        $head = $this->repo->recipeHead((int) $item['id_recipe']) ?? throw new ApiException('ITEM_WITHOUT_RECIPE', 409);
        $fr = $context->translator->lang() === 'fr';
        [$basis, $multiplier, $yield] = $this->basis($item, $head);
        $day = $date !== null && $date !== '' ? $date : date('Y-m-d');
        $planned = $this->repo->planned($day, [$itemId])[$itemId] ?? null;
        return $this->itemRow($item) + [
            'planned' => $planned !== null ? round($planned, 3) : null,
            'basis' => $basis,
            // Wydajność jednej receptury w jednostce bazowej (półprodukt : kg z jednego zarobu).
            'recipe_yield' => $yield,
            'tree' => $this->node($head, $multiplier, $fr, [], 0),
            'routing' => array_map(fn($r) => [
                'step_no' => (int) $r['step_no'], 'name' => $fr && $r['name_fr'] ? $r['name_fr'] : $r['name_pl'],
                'workstation' => $fr && $r['ws_fr'] ? $r['ws_fr'] : $r['ws_pl'],
                'temperature_c' => $r['temperature_c'] !== null ? (float) $r['temperature_c'] : null,
            ], $this->repo->routing($itemId)),
        ];
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Węzeł receptury : linie przemnożone przez mnożnik (ile wydajności receptury na jednostkę bazową).
     * Podreceptura : potrzebna ilość ÷ jej wydajność = mnożnik dziecka.
     */
    private function node(array $recipe, float $multiplier, bool $fr, array $seen, int $depth): array
    {
        $id = (int) $recipe['id'];
        $seen[] = $id;
        $lines = [];
        foreach ($this->repo->recipeLines($id) as $l) {
            $quantity = round((float) $l['quantity'] * $multiplier, 6);
            if ($l['child_recipe_id'] !== null) {
                $childId = (int) $l['child_recipe_id'];
                $yield = (float) $l['yield_quantity'];
                $child = in_array($childId, $seen, true) || $depth >= self::MAX_DEPTH || $yield <= 0
                    ? null : $this->repo->recipeHead($childId);
                $lines[] = [
                    'kind' => 'recipe', 'recipe_id' => $childId, 'name' => $l['recipe_name'], 'unit' => $l['recipe_unit'], 'quantity' => $quantity,
                    'node' => $child === null ? null : $this->node($child, $quantity / $yield, $fr, $seen, $depth + 1),
                ];
            } elseif ($l['child_ingredient_id'] !== null) {
                $lines[] = [
                    'kind' => 'material', 'material_id' => (int) $l['child_ingredient_id'],
                    'name' => $fr && $l['material_name_fr'] ? $l['material_name_fr'] : $l['material_name'],
                    'unit' => $l['material_unit'], 'quantity' => $quantity, 'packaging' => (bool) $l['packaging'],
                ];
            }
        }
        return [
            'recipe_id' => $id, 'name' => $recipe['name'], 'yield' => (float) $recipe['yield_quantity'], 'unit' => $recipe['unit'],
            'batches' => round($multiplier, 6), 'steps' => $this->repo->recipeSteps($id), 'lines' => $lines,
        ];
    }

    /** @return array{0: string, 1: float, 2: ?float} [jednostka bazowa, mnożnik korzenia, wydajność receptury w jednostce bazowej] */
    private function basis(array $item, array $head): array
    {
        $yield = (float) $head['yield_quantity'];
        if ($yield <= 0) return ['recipe', 1.0, null];
        if ($item['item_type'] === 'product' && (int) $item['ppc'] > 0) return ['carton', 1 / $yield, $yield];
        $perKg = ['g' => 1000.0, 'kg' => 1.0, 'ml' => 1000.0, 'l' => 1.0][$head['unit'] ?? ''] ?? null;
        if ($perKg !== null && $item['base_unit'] === 'kg') return ['kg', $perKg / $yield, round($yield / $perKg, 3)];
        return ['recipe', 1.0, null];
    }

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }

    private function itemRow(array $i): array
    {
        return ['item_id' => (int) $i['id'], 'name' => $i['name'], 'type' => $i['item_type'], 'unit' => $i['base_unit'],
            'ppc' => $i['ppc'] !== null && (int) $i['ppc'] > 0 ? (int) $i['ppc'] : null,
            'batch' => isset($i['batch_multiple']) && (float) $i['batch_multiple'] > 0 ? (float) $i['batch_multiple'] : null];
    }
}
