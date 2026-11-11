<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Wydajność i straty kwartału [247] (makieta 23) : straty z tabletów (sztuki, wartość surowców = szt. ÷ szt./karton ×
 * koszt surowców kartonu), zrobione sztuki i godziny pracy grup (odbicia) w okresie [from, to] (daty włącznie).
 */
final class YieldReviewRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_waste';

    /** @return list<array{id_group:int, reason:string, q:float, value:float}> */
    public function waste(string $from, string $to): array
    {
        return $this->all("SELECT w.id_group, w.reason, SUM(w.quantity) AS q,
                                  SUM(w.quantity / NULLIF(fp.ppc, 0) * COALESCE(fp.pre_calculated_raw_price_per_carton, 0)) AS value
                           FROM n_prod_waste w JOIN n_prod_item i ON i.id = w.id_item AND i.item_type = 'product' LEFT JOIN final_product fp ON fp.id = i.id_final_product
                           WHERE w.work_date BETWEEN ? AND ? AND w.cancelled_at IS NULL GROUP BY w.id_group, w.reason", [$from, $to]);
    }

    /** @return list<array{id_item:int, name:string, id_group:int, q:float, value:float}> produkty z największą wartością strat */
    public function topWaste(string $from, string $to, int $limit = 5): array
    {
        return $this->all("SELECT w.id_item, fp.name, w.id_group, SUM(w.quantity) AS q,
                                  SUM(w.quantity / NULLIF(fp.ppc, 0) * COALESCE(fp.pre_calculated_raw_price_per_carton, 0)) AS value
                           FROM n_prod_waste w JOIN n_prod_item i ON i.id = w.id_item AND i.item_type = 'product' LEFT JOIN final_product fp ON fp.id = i.id_final_product
                           WHERE w.work_date BETWEEN ? AND ? AND w.cancelled_at IS NULL GROUP BY w.id_item, fp.name, w.id_group ORDER BY value DESC, q DESC LIMIT " . max(1, $limit), [$from, $to]);
    }

    /** @return array<int, float> grupa => zrobione sztuki (produkty) */
    public function produced(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all("SELECT o.id_group, SUM(o.quantity) AS q FROM n_prod_output o JOIN n_prod_item i ON i.id = o.id_item AND i.item_type = 'product'
                             WHERE o.work_date BETWEEN ? AND ? AND o.cancelled_at IS NULL GROUP BY o.id_group", [$from, $to]) as $r) $out[(int) $r['id_group']] = (float) $r['q'];
        return $out;
    }

    /** @return array<int, float> grupa => godziny pracy (odbicia wejście / wyjście w okresie) */
    public function hours(string $from, string $to): array
    {
        $out = [];
        $end = date('Y-m-d', strtotime($to . ' +1 day'));
        foreach ($this->all("SELECT e.id_primary_group AS g,
                                    SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, GREATEST(k.clock_in, ?), LEAST(COALESCE(k.clock_out, k.clock_in), ?)))) / 60 AS h
                             FROM n_prod_clock k JOIN n_prod_employee e ON e.id = k.id_employee
                             WHERE k.clock_in < ? AND COALESCE(k.clock_out, k.clock_in) > ? AND e.id_primary_group IS NOT NULL GROUP BY e.id_primary_group",
            [$from, $end, $end, $from]) as $r) $out[(int) $r['g']] = (float) $r['h'];
        return $out;
    }
}
