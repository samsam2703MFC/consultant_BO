<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;

/** Koszt surowców kartonu przed i po ostatnim « Przelicz wszystkie marże » (tfb_margin_recalc). */
class MarginRecalcRepository extends BaseRepository
{
    protected string $table = 'tfb_margin_recalc';

    /** @return array<int, array{cost: float, ppc: ?int}> produkt aktywny z recepturą => koszt surowców kartonu */
    public function currentCosts(): array
    {
        $out = [];
        foreach ($this->query('SELECT fp.id, fp.ppc, COALESCE(pr.precalculated_material_cost, 0) AS cost
                               FROM final_product fp JOIN product_recipe pr ON pr.id = fp.id_recipe WHERE fp.active = 1') ?: [] as $r) {
            $out[(int) $r['id']] = ['cost' => (float) $r['cost'], 'ppc' => $r['ppc'] !== null ? (int) $r['ppc'] : null];
        }
        return $out;
    }

    /** Zapisuje przeliczenie (poprzednie zastępowane). */
    public function save(array $before, array $after, ?int $userId): int
    {
        $this->db->exec('DELETE FROM tfb_margin_recalc');
        $stmt = $this->db->prepare('INSERT INTO tfb_margin_recalc (id_product, old_cost_carton, new_cost_carton, ppc, recalculated_at, recalculated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        foreach ($after as $id => $a) {
            if (!isset($before[$id])) continue;
            $stmt->execute([$id, round($before[$id]['cost'], 4), round($a['cost'], 4), $a['ppc'], $now, $userId]);
        }
        return count(array_intersect_key($after, $before));
    }

    /** @return array<int, array> produkt => wiersz ostatniego przeliczenia */
    public function last(): array
    {
        $out = [];
        foreach ($this->query('SELECT r.*, u.displayed_name AS by_name FROM tfb_margin_recalc r LEFT JOIN user u ON u.id = r.recalculated_by') ?: [] as $r) {
            $out[(int) $r['id_product']] = $r;
        }
        return $out;
    }
}
