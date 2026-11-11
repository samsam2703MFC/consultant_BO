<?php
declare(strict_types=1);

namespace App\Services\Recipe;

use App\Exceptions\CustomException;
use App\Services\Dashboard\ProfitabilityService;
use DateTimeImmutable;

/**
 * Page « Historia receptury » (/recipes/{id}/history) : versions datées avec ce qui a changé, et coût matières mois
 * par mois (version en vigueur à la fin du mois × prix des matières de l'époque — la même règle que Rentowność).
 * L'écart d'un mois sur l'autre est séparé en effet prix (même composition, nouveaux prix) et effet composition.
 */
final class RecipeHistoryViewService
{
    private const MONTHS = 12;

    public function __construct(private RecipeVersionService $versions, private ProfitabilityService $profitability) {}

    public function build(int $recipeId, ?DateTimeImmutable $today = null): array
    {
        $history = $this->versions->history($recipeId);
        if (!$history) throw new CustomException('Nie znaleziono receptury.', 'errors', 404);
        $today ??= new DateTimeImmutable();
        $recipe = $history['recipe'];
        $kind = RecipeCalculationViewService::TYPE_KIND[(int) $recipe['id_type']] ?? 'product';

        $months = [];
        $prev = null;
        $first = $today->modify('first day of this month')->modify('-' . (self::MONTHS - 1) . ' months');
        for ($m = $first, $i = 0; $i < self::MONTHS; $m = $m->modify('+1 month'), $i++) {
            $isCurrent = $m->format('Y-m') === $today->format('Y-m');
            $at = $isCurrent ? $today->format('Y-m-d H:i:s') : $m->format('Y-m-t') . ' 23:59:59';
            $ingredients = $this->profitability->recipeIngredientsAt($recipeId, $at);
            $cost = $this->profitability->ingredientsCostAt($ingredients, $at);
            $row = ['ym' => $m->format('Y-m'), 'label' => ProfitabilityService::monthLabel($m->format('Y-m'), true),
                'current' => $isCurrent, 'cost' => $cost, 'version' => self::versionAt($history['versions'], $at),
                'delta' => null, 'price_effect' => null, 'composition_effect' => null];
            if ($prev) {
                $samePrices = $this->profitability->ingredientsCostAt($prev['ingredients'], $at);
                $row['delta'] = $cost - $prev['cost'];
                $row['price_effect'] = $samePrices - $prev['cost'];
                $row['composition_effect'] = $cost - $samePrices;
            }
            $months[] = $row;
            $prev = ['cost' => $cost, 'ingredients' => $ingredients];
        }
        $now = end($months)['cost'];
        $start = $months[0]['cost'];
        $dated = array_values(array_filter($history['versions'], fn($v) => $v['valid_from'] !== null));
        return [
            'recipe' => ['id' => (int) $recipe['id'], 'name' => (string) $recipe['name'], 'yield' => (float) $recipe['yield_quantity'], 'unit' => (string) $recipe['unit']],
            'product' => $recipe['product_id'] ? ['id' => (int) $recipe['product_id'], 'name' => (string) $recipe['product']] : null,
            'kind' => $kind,
            'kind_info' => RecipeCalculationViewService::KINDS[$kind],
            'versions' => $history['versions'],
            'untracked' => $history['current']['untracked'],
            'months' => $months,
            'cost_now' => $now,
            'cost_start' => $start,
            'cost_change_pct' => $start > 0 ? ($now / $start - 1) * 100 : null,
            'price_effect' => array_sum(array_map(fn($r) => (float) $r['price_effect'], $months)),
            'composition_effect' => array_sum(array_map(fn($r) => (float) $r['composition_effect'], $months)),
            'last_change' => $dated[0]['valid_from'] ?? null,
        ];
    }

    /** Numéro de la version en vigueur à $at (versions de la plus récente à la plus ancienne). */
    private static function versionAt(array $versions, string $at): ?int
    {
        foreach ($versions as $v) {
            if ($v['valid_from'] === null || $v['valid_from'] <= $at) return $v['no'];
        }
        return $versions ? end($versions)['no'] : null;
    }
}
