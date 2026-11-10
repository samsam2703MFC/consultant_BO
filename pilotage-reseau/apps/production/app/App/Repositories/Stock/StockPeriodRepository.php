<?php
declare(strict_types=1);

namespace App\Repositories\Stock;

use App\Domain\Client\Order\OpenOrders;
use App\Domain\Stock\StockLevels;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Zapas wg planu logistyki i okresy stanów wyjątkowych [216] : produkty MTS (stany obowiązujące i normalne, kartony na
 * paletę, koszt kartonu), zapas dziś w paletach (zamrażarka), okresy (n_stock_period) i ich stany (n_stock_period_item).
 */
final class StockPeriodRepository extends WorkshopRepository
{
    protected string $table = 'n_stock_period';

    /**
     * Produkty MTS z kartą : stany obowiązujące dziś i normalne (jednostka bazowa), sztuki w kartonie, kartony na paletę,
     * koszt surowców kartonu (€). @return array<int, array> po final_product.id
     */
    public function mtsProducts(): array
    {
        $rate = (float) ($this->all('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')[0]['exchange_rate_euro'] ?? 0) ?: 1.0;
        $out = [];
        foreach ($this->all("SELECT i.id AS item_id, fp.id AS product_id, fp.name, fp.ppc, fp.cpp, fp.pre_calculated_raw_price_per_carton / ? AS cost_eur,
                    " . StockLevels::columns('s') . "
                FROM n_prod_item i
                JOIN n_prod_product_settings s ON s.id_item = i.id
                JOIN final_product fp ON fp.id = i.id_final_product
                " . StockLevels::join('i') . "
                WHERE i.item_type = 'product' AND s.mode = 'MTS' AND fp.active = 1", [$rate]) as $r) {
            $out[(int) $r['product_id']] = $r;
        }
        return $out;
    }

    /** Zapas produktów gotowych dziś w paletach (kartony ÷ kartony na paletę, stan wolny i na paletach). */
    public function stockPallets(): float
    {
        return (float) ($this->all('SELECT SUM(cs.n / fp.cpp) AS p FROM (SELECT id_product, COUNT(*) AS n FROM carton_stock WHERE status IN (' . OpenOrders::STOCK_STATUSES . ')
                GROUP BY id_product) cs JOIN final_product fp ON fp.id = cs.id_product WHERE fp.cpp > 0')[0]['p'] ?? 0);
    }

    /** Kartony produktów gotowych dziś (wolne i na paletach) : final_product.id => kartony. @return array<int, int> */
    public function stockCartons(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_product, COUNT(*) AS n FROM carton_stock WHERE status IN (' . OpenOrders::STOCK_STATUSES . ') GROUP BY id_product') as $r) $out[(int) $r['id_product']] = (int) $r['n'];
        return $out;
    }

    /** @return list<array> okresy (najnowsze najpierw) z liczbą produktów */
    public function periods(): array
    {
        return $this->all("SELECT p.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS approved_by_name,
                    (SELECT COUNT(*) FROM n_stock_period_item pi WHERE pi.id_period = p.id) AS products
                FROM n_stock_period p LEFT JOIN user u ON u.id = p.approved_by ORDER BY p.date_from DESC, p.id DESC");
    }

    public function period(int $id): ?array
    {
        return $this->all("SELECT p.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS approved_by_name
                FROM n_stock_period p LEFT JOIN user u ON u.id = p.approved_by WHERE p.id = ?", [$id])[0] ?? null;
    }

    /** @return list<array{id_period:int, id_item:int, stock_min:string, stock_max:string, normal_min:?string, normal_max:?string, forecast_avg:?string, forecast_peak:?string}> */
    public function items(int $periodId): array
    {
        return $this->all('SELECT * FROM n_stock_period_item WHERE id_period = ?', [$periodId]);
    }

    /** Sumy kartonów ponad stany normalne na okres (lista) : okres => [kartony, palety]. */
    public function extras(): array
    {
        $out = [];
        foreach ($this->all('SELECT pi.id_period, SUM((pi.stock_max - COALESCE(pi.normal_max, 0)) / fp.ppc) AS cartons
                FROM n_stock_period_item pi JOIN n_prod_item i ON i.id = pi.id_item JOIN final_product fp ON fp.id = i.id_final_product
                WHERE fp.ppc > 0 GROUP BY pi.id_period') as $r) $out[(int) $r['id_period']] = (float) $r['cartons'];
        return $out;
    }

    /** Okres zatwierdzony nakładający się na [od, do] (poza $exceptId). */
    public function overlapping(string $from, string $to, ?int $exceptId): ?array
    {
        return $this->all("SELECT id, name, date_from, date_to FROM n_stock_period WHERE status = 'approved' AND date_from <= ? AND date_to >= ?"
            . ($exceptId !== null ? ' AND id <> ?' : '') . ' ORDER BY date_from LIMIT 1', $exceptId !== null ? [$to, $from, $exceptId] : [$to, $from])[0] ?? null;
    }

    /**
     * Zapis okresu i (gdy podane) jego stanów w jednej transakcji ; $items null = stany bez zmian.
     * @param ?list<array{item_id:int, stock_min:float, stock_max:float, normal_min:?float, normal_max:?float, forecast_avg:?float, forecast_peak:?float}> $items
     */
    public function save(?int $id, array $head, ?array $items, ?int $userId): int
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            if ($id === null) {
                $this->db->prepare('INSERT INTO n_stock_period (name, date_from, date_to, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$head['name'], $head['date_from'], $head['date_to'], $userId]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE n_stock_period SET name = ?, date_from = ?, date_to = ? WHERE id = ?')->execute([$head['name'], $head['date_from'], $head['date_to'], $id]);
            }
            if ($items !== null) {
                $this->db->prepare('DELETE FROM n_stock_period_item WHERE id_period = ?')->execute([$id]);
                $ins = $this->db->prepare('INSERT INTO n_stock_period_item (id_period, id_item, stock_min, stock_max, normal_min, normal_max, forecast_avg, forecast_peak) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($items as $it) $ins->execute([$id, $it['item_id'], $it['stock_min'], $it['stock_max'], $it['normal_min'], $it['normal_max'], $it['forecast_avg'], $it['forecast_peak']]);
            }
            if ($own) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }

    public function setStatus(int $id, string $status, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_stock_period SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?')
            ->execute([$status, $status === 'approved' ? $userId : null, $status === 'approved' ? date('Y-m-d H:i:s') : null, $id]);
    }

    /** Okres i jego stany (także bez kaskady klucza obcego, np. kopia struktury w testach). */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM n_stock_period_item WHERE id_period = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM n_stock_period WHERE id = ?')->execute([$id]);
    }
}
