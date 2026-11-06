<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\RecipeProducerRepository;

/**
 * Partia produktu : kompozycje robione przez inną grupę (makieta 1) — jedna linia z numerem partii tej grupy zamiast
 * surowców kompozycji. Drzewo receptury (product_recipe_connection) od receptury karty : kompozycja z grupą robiącą
 * inną niż grupa partii zatrzymuje zejście ; jej surowce (flattened_recipe_ingredient × użyta ilość ÷ wydajność)
 * odejmowane od surowców receptury. Kompozycja zagnieżdżona w odciętej idzie razem z nią.
 */
final class CompositionCut
{
    private const MAX_DEPTH = 6;

    public function __construct(
        private OutputRepository $repo,
        private RecipeProducerRepository $producers,
    ) {
    }

    /**
     * @return array{cuts: list<array{recipe_id:int, name:string, unit:string, quantity:float, producer:array}>, minus: array<int, float>}
     *         ilości na jedną recepturę karty (ta sama podstawa co flattened_recipe_ingredient)
     */
    public function of(int $recipeId, int $groupId): array
    {
        // Bez pamięci podręcznej : grupa robiąca może się zmienić między dwoma wywołaniami (ten sam proces).
        $used = [];
        $this->walk($recipeId, 1.0, $groupId, $used, [], 0);
        $producers = $this->producers->forRecipes(array_keys($used));
        $cuts = [];
        $minus = [];
        foreach ($used as $id => $u) {
            $cuts[] = ['recipe_id' => $id, 'name' => $u['name'], 'unit' => $u['unit'], 'quantity' => round($u['quantity'], 6), 'producer' => $producers[$id]];
            if ($u['yield'] <= 0) continue;
            foreach ($this->repo->recipe($id) as $m) {
                $mid = (int) $m['material_id'];
                $minus[$mid] = ($minus[$mid] ?? 0.0) + (float) $m['quantity'] * $u['quantity'] / $u['yield'];
            }
        }
        usort($cuts, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['cuts' => $cuts, 'minus' => $minus];
    }

    /**
     * Surowce receptury po odcięciu kompozycji innych grup (linie ≤ 0,005 znikają).
     * @param list<array{material_id:int|string, quantity:float|string}> $rows flattened_recipe_ingredient
     */
    public static function apply(array $rows, array $minus): array
    {
        if (!$minus) return $rows;
        $out = [];
        foreach ($rows as $r) {
            $q = (float) $r['quantity'] - ($minus[(int) $r['material_id']] ?? 0.0);
            if ($q <= 0.005) continue;
            $out[] = ['quantity' => round($q, 3)] + $r;
        }
        return $out;
    }

    /** Zejście w drzewo : kompozycja innej grupy = odcięta (ilość w jej jednostce), inaczej zejście z mnożnikiem. */
    private function walk(int $recipeId, float $multiplier, int $groupId, array &$used, array $seen, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH || in_array($recipeId, $seen, true)) return;
        $seen[] = $recipeId;
        $lines = array_values(array_filter($this->repo->recipeLines($recipeId), fn($l) => $l['child_recipe_id'] !== null));
        if (!$lines) return;
        $producers = $this->producers->forRecipes(array_map(fn($l) => (int) $l['child_recipe_id'], $lines));
        foreach ($lines as $l) {
            $child = (int) $l['child_recipe_id'];
            $quantity = (float) $l['quantity'] * $multiplier;
            if (isset($producers[$child]) && $producers[$child]['id'] !== $groupId) {
                $used[$child] ??= ['name' => (string) $l['recipe_name'], 'unit' => (string) $l['recipe_unit'], 'yield' => (float) $l['yield_quantity'], 'quantity' => 0.0];
                $used[$child]['quantity'] += $quantity;
                continue;
            }
            $yield = (float) $l['yield_quantity'];
            if ($yield > 0) $this->walk($child, $quantity / $yield, $groupId, $used, $seen, $depth + 1);
        }
    }
}
