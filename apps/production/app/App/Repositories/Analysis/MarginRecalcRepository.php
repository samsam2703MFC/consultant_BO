<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;

/**
 * « Przelicz wszystkie marże » : ostatnia rzeczywista zmiana kosztu surowców kartonu każdego produktu
 * (tfb_margin_recalc) i dziennik przeliczeń (tfb_margin_recalc_run).
 */
class MarginRecalcRepository extends BaseRepository
{
    /** Zmiana kosztu kartonu mniejsza niż to (PLN) = brak zmiany. */
    private const EPS = 0.00005;

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

    /**
     * Zapisuje przeliczenie : produkt, którego koszt się zmienił, dostaje nową parę przed / po ; produkt bez
     * zmiany zachowuje ostatnią zmianę (albo dostaje parę bez zmiany, gdy jeszcze jej nie miał).
     * @return array{products: int, changed: int}
     */
    public function save(array $before, array $after, ?int $userId, int $recipes): array
    {
        $now = date('Y-m-d H:i:s');
        $replace = $this->db->prepare('REPLACE INTO tfb_margin_recalc (id_product, old_cost_carton, new_cost_carton, ppc, recalculated_at, recalculated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $keep = $this->db->prepare('INSERT IGNORE INTO tfb_margin_recalc (id_product, old_cost_carton, new_cost_carton, ppc, recalculated_at, recalculated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $products = 0;
        $changed = 0;
        foreach ($after as $id => $a) {
            if (!isset($before[$id])) continue;
            $products++;
            $row = [$id, round($before[$id]['cost'], 4), round($a['cost'], 4), $a['ppc'], $now, $userId];
            if (abs($a['cost'] - $before[$id]['cost']) >= self::EPS) {
                $replace->execute($row);
                $changed++;
            } else {
                $keep->execute($row);
            }
        }
        $this->db->prepare('INSERT INTO tfb_margin_recalc_run (run_at, run_by, recipes, products, changed) VALUES (?, ?, ?, ?, ?)')
            ->execute([$now, $userId, $recipes, $products, $changed]);
        return ['products' => $products, 'changed' => $changed];
    }

    /** @return array<int, array> produkt => ostatnia zmiana kosztu */
    public function last(): array
    {
        $out = [];
        foreach ($this->query('SELECT r.*, u.displayed_name AS by_name FROM tfb_margin_recalc r LEFT JOIN user u ON u.id = r.recalculated_by') ?: [] as $r) {
            $out[(int) $r['id_product']] = $r;
        }
        return $out;
    }

    /** Ostatnie przeliczenie (kiedy, kto, ile zmian) albo null. */
    public function lastRun(): ?array
    {
        $rows = $this->query('SELECT r.*, u.displayed_name AS by_name FROM tfb_margin_recalc_run r LEFT JOIN user u ON u.id = r.run_by ORDER BY r.id DESC LIMIT 1');
        return $rows ? $rows[0] : null;
    }
}
