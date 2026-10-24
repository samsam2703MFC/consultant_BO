<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

/**
 * Coboty w marszrucie (obliczenie czyste, bez bazy).
 *
 * Etap z alternatywą na aktywnym cobocie (robot w trybie współpracującym) liczy się na cobocie : jego kadencja
 * (czas stały, zmienny, pojemność — gdy kadencja nie jest podana, czasy etapu ręcznego) i godziny pracy =
 * czas × część osoby cobota (np. 0,25). Czasy ręczne zostają w kluczu manual : oszczędzone godziny = gamma
 * ręczna − gamma z cobotami.
 */
final class CobotRouting
{
    /**
     * @param list<array> $steps kroki jednej karty (n_prod_routing_step)
     * @param array<int, array> $cobots id etapu => alternatywa na cobocie (fixed_time_s, variable_time_s,
     *        capacity_per_pass, id_workstation, operator_share, name)
     * @return list<array>
     */
    public static function apply(array $steps, array $cobots): array
    {
        return array_map(function (array $s) use ($cobots) {
            $c = $cobots[(int) ($s['id'] ?? 0)] ?? null;
            if ($c === null) return $s;
            $own = (float) $c['fixed_time_s'] > 0 || (float) $c['variable_time_s'] > 0;
            $s['manual'] = array_intersect_key($s, array_flip(['fixed_time_s', 'variable_time_s', 'capacity_per_pass', 'operators_required']));
            if ($own) {
                $s['fixed_time_s'] = (float) $c['fixed_time_s'];
                $s['variable_time_s'] = (float) $c['variable_time_s'];
                $s['capacity_per_pass'] = (float) $c['capacity_per_pass'] > 0 ? (float) $c['capacity_per_pass'] : $s['capacity_per_pass'];
            }
            $s['operators_required'] = (float) $c['operator_share'];
            $s['cobot'] = ['workstation_id' => (int) $c['id_workstation'], 'name' => $c['name'] ?? null, 'operator_share' => (float) $c['operator_share']];
            return $s;
        }, $steps);
    }

    /** Gamma bez cobotów (czasy i osoby etapów ręcznych). */
    public static function manual(array $steps): array
    {
        return array_map(fn(array $s) => isset($s['manual']) ? array_diff_key(array_merge($s, $s['manual']), ['manual' => 1, 'cobot' => 1]) : $s, $steps);
    }

    public static function uses(array $steps): bool
    {
        foreach ($steps as $s) if (isset($s['cobot'])) return true;
        return false;
    }

    /** Roboczogodziny oszczędzone przez coboty dla ilości (≥ 0 ; 0 bez cobotów). */
    public static function savedHours(array $steps, float $quantity): float
    {
        if ($quantity <= 0 || !self::uses($steps)) return 0.0;
        $manual = StandardTime::routing(self::manual($steps), $quantity)['totals']['labour_s'];
        $cobot = StandardTime::routing($steps, $quantity)['totals']['labour_s'];
        return max(0.0, ($manual - $cobot) / 3600);
    }
}
