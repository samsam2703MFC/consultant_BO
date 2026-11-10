<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

/**
 * Temps standards d'une gamme (calcul pur, sans base).
 *
 * Étape : durée de travail = temps fixe × passages + temps variable × quantité,
 * passages = ⌈quantité ÷ capacité par passage⌉. Le temps fixe ne dépend donc que du nombre
 * de passages : 10 ou 60 pièces dans un seul passage coûtent le même temps fixe.
 * Le nettoyage s'ajoute une fois par lancement ; le délai après l'étape (pousse, repos,
 * refroidissement) allonge le temps écoulé sans occuper l'équipe.
 */
final class StandardTime
{
    public static function passes(float $quantity, float $capacityPerPass): int
    {
        if ($quantity <= 0) return 0;
        if ($capacityPerPass <= 0) throw new \InvalidArgumentException('capacity_per_pass must be > 0');
        return (int) ceil(round($quantity / $capacityPerPass, 6));
    }

    /**
     * @param array{fixed_time_s:int|float|string, variable_time_s:int|float|string, capacity_per_pass:int|float|string,
     *              operators_required?:int|float|string, cleaning_time_s?:int|float|string, delay_after_s?:int|float|string} $step
     * @return array{passes:int, fixed_s:float, variable_s:float, work_s:float, cleaning_s:float, delay_s:float, elapsed_s:float, labour_s:float}
     */
    public static function step(array $step, float $quantity): array
    {
        $passes = self::passes($quantity, (float) $step['capacity_per_pass']);
        $fixed = (float) $step['fixed_time_s'] * $passes;
        $variable = (float) $step['variable_time_s'] * max(0.0, $quantity);
        $work = $fixed + $variable;
        $cleaning = $passes > 0 ? (float) ($step['cleaning_time_s'] ?? 0) : 0.0;
        $delay = $passes > 0 ? (float) ($step['delay_after_s'] ?? 0) : 0.0;
        return [
            'passes' => $passes,
            'fixed_s' => $fixed,
            'variable_s' => $variable,
            'work_s' => $work,
            'cleaning_s' => $cleaning,
            'delay_s' => $delay,
            'elapsed_s' => $work + $cleaning + $delay,
            'labour_s' => ($work + $cleaning) * (float) ($step['operators_required'] ?? 1),
        ];
    }

    /** Gamme complète pour une quantité : détail par étape et totaux. */
    public static function routing(array $steps, float $quantity): array
    {
        $rows = [];
        $totals = ['work_s' => 0.0, 'cleaning_s' => 0.0, 'delay_s' => 0.0, 'elapsed_s' => 0.0, 'labour_s' => 0.0];
        foreach ($steps as $step) {
            $calc = self::step($step, $quantity);
            $rows[] = $calc + ['step_no' => (int) ($step['step_no'] ?? count($rows) + 1)];
            foreach ($totals as $key => $_) $totals[$key] += $calc[$key];
        }
        return ['quantity' => $quantity, 'steps' => $rows, 'totals' => $totals,
            'labour_s_per_unit' => $quantity > 0 ? $totals['labour_s'] / $quantity : null];
    }

    /**
     * Temps unitaire déduit d'une mesure : par pièce (variable) en retirant la part fixe connue,
     * ou par passage (fixed) en retirant la part variable connue.
     */
    public static function unitTime(string $component, array $step, float $quantity, int $passes, float $durationS): float
    {
        if ($component === 'variable') {
            return $quantity > 0 ? max(0.0, $durationS - (float) $step['fixed_time_s'] * $passes) / $quantity : 0.0;
        }
        return $passes > 0 ? max(0.0, $durationS - (float) $step['variable_time_s'] * $quantity) / $passes : 0.0;
    }

    /** Moyenne des n dernières valeurs (liste déjà triée de la plus récente à la plus ancienne). */
    public static function movingAverage(array $latestFirst, int $n): ?float
    {
        $values = array_slice(array_values($latestFirst), 0, max(1, $n));
        return $values ? array_sum($values) / count($values) : null;
    }

    /** Composante qu'une mesure corrige par défaut : le temps par pièce s'il existe, sinon le temps par passage. */
    public static function defaultComponent(array $step): string
    {
        return (float) $step['variable_time_s'] > 0 ? 'variable' : 'fixed';
    }
}
