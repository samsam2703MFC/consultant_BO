<?php
declare(strict_types=1);

namespace App\Repositories\Forecast;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Prognoza 13 tygodni [220] : kartony zamówień klientów na produkt i dzień wysyłki (historia N-1, ostatnie tygodnie,
 * zamówienia w oknie), produkty ERP (sztuki w kartonie, kartony na paletę, karta produkcji), zatwierdzenia
 * (n_forecast_run), odczyty panelu (n_forecast_panel_read) i połączenia produktów gam panelu z produktem ERP (n_forecast_panel_link).
 */
final class ForecastRepository extends WorkshopRepository
{
    protected string $table = 'n_forecast_run';

    /**
     * Kartony zamówień (bez gratisów i anulowanych) na produkt i dzień wysyłki w okresie.
     * @return list<array{product_id:int, ship_date:string, cartons:float}>
     */
    public function cartonsByDay(string $from, string $to): array
    {
        return array_map(fn($r) => ['product_id' => (int) $r['product_id'], 'ship_date' => (string) $r['ship_date'], 'cartons' => (float) $r['cartons']], $this->all(
            'SELECT cop.id_product AS product_id, DATE(co.expected_shipping_date) AS ship_date, SUM(cop.quantity_carton) AS cartons
             FROM customer_order co JOIN customer_order_product cop ON cop.id_order = co.id
             WHERE co.expected_shipping_date >= ? AND co.expected_shipping_date < DATE_ADD(?, INTERVAL 1 DAY)
               AND co.status IN (1, 2, 3, 5) AND COALESCE(co.is_freebie, 0) = 0 AND cop.quantity_carton > 0
             GROUP BY cop.id_product, DATE(co.expected_shipping_date)',
            [$from, $to]
        ));
    }

    /**
     * Produkty ERP : nazwa, sztuki w kartonie, kartony na paletę, aktywność, dział, koszt surowców kartonu (zł), karta
     * produkcji (n_prod_item) i tryb.
     * @return array<int, array> po final_product.id
     */
    public function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $out = [];
        foreach ($this->all('SELECT fp.id, fp.name, fp.ppc, fp.cpp, fp.cpl, fp.lpp, fp.active, fp.id_section, fp.pre_calculated_raw_price_per_carton AS raw_cost,
                    cat.name AS category, i.id AS item_id, s.mode
                FROM final_product fp
                LEFT JOIN category_product cat ON cat.id = fp.id_category
                LEFT JOIN n_prod_item i ON i.id_final_product = fp.id AND i.item_type = \'product\'
                LEFT JOIN n_prod_product_settings s ON s.id_item = i.id
                WHERE fp.id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id']] ??= $r;
        }
        return $out;
    }

    /** @return list<array{id:int, name:string}> aktywne produkty ERP (lista do połączenia SKU) */
    public function activeProducts(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $this->all('SELECT id, name FROM final_product WHERE active = 1 ORDER BY name'));
    }

    public function productExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM final_product WHERE id = ?', [$id]);
    }

    /** Aktywni pracownicy produkcji (zdolność : × godziny etatu). */
    public function activeEmployees(): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM n_prod_employee WHERE active = 1')[0]['n'] ?? 0);
    }

    /** Czas dostawy (dni) z ostatniej oferty bieżącego dostawcy surowca, która go podaje. @return array<int, int> */
    public function leadTimes(): array
    {
        $out = [];
        foreach ($this->all('SELECT o.id_raw_material, o.lead_time_days FROM n_mp_offer o
                JOIN raw_material rm ON rm.id = o.id_raw_material AND rm.id_current_supplier = o.id_supplier
                WHERE o.lead_time_days IS NOT NULL ORDER BY o.id_raw_material, o.created_at, o.id') as $r) {
            $out[(int) $r['id_raw_material']] = (int) $r['lead_time_days'];
        }
        return $out;
    }

    /**
     * Koszty stałe miesięcy kosztowych (Finanse) wiersz po wierszu — prognoza wydziela inwestycje (kredyt, leasing).
     * @return list<array{name:string, category:string, amount:float}>
     */
    public function fixedCosts(array $monthIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $monthIds))));
        if (!$ids) return [];
        return array_map(fn($r) => ['name' => (string) $r['name'], 'category' => (string) $r['category'], 'amount' => (float) $r['amount']],
            $this->all('SELECT name, category, amount FROM cost_month_fixed_cost WHERE month_id IN (' . implode(',', $ids) . ')'));
    }

    // --- Zatwierdzenia -------------------------------------------------------------------------------------------

    public function runForWeek(string $weekStart): ?array
    {
        return $this->all("SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS approved_by_name
                FROM n_forecast_run r LEFT JOIN user u ON u.id = r.approved_by WHERE r.week_start = ?", [$weekStart])[0] ?? null;
    }

    /** @return list<array> ostatnie zatwierdzenia (bez zdjęcia liczb) */
    public function runs(int $limit = 8): array
    {
        return $this->all("SELECT r.id, r.week_start, r.weeks, r.trend, r.cartons, r.season_cartons, r.hours, r.production_cost, r.purchase_value, r.note, r.approved_at,
                    COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS approved_by_name
                FROM n_forecast_run r LEFT JOIN user u ON u.id = r.approved_by ORDER BY r.week_start DESC LIMIT " . max(1, min(52, $limit)));
    }

    /** Zatwierdzenie tygodnia : nowe albo ponowne (te same 13 tygodni po zmianach). */
    public function saveRun(array $row): int
    {
        $cols = ['week_start', 'weeks', 'trend', 'cartons', 'season_cartons', 'hours', 'production_cost', 'purchase_value', 'payload', 'note', 'approved_at', 'approved_by'];
        $row = array_intersect_key($row, array_flip($cols));
        $names = array_keys($row);
        $sql = 'INSERT INTO n_forecast_run (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", array_diff($names, ['week_start'])));
        $this->db->prepare($sql)->execute(array_values($row));
        return (int) ($this->all('SELECT id FROM n_forecast_run WHERE week_start = ?', [$row['week_start']])[0]['id'] ?? 0);
    }

    public function deleteRun(string $weekStart): void
    {
        $this->db->prepare('DELETE FROM n_forecast_run WHERE week_start = ?')->execute([$weekStart]);
    }

    // --- Odczyty panelu ------------------------------------------------------------------------------------------

    /** Ostatni udany odczyt panelu (z danymi) albo null. */
    public function lastPanelRead(): ?array
    {
        return $this->all("SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS read_by_name
                FROM n_forecast_panel_read r LEFT JOIN user u ON u.id = r.read_by WHERE r.ok = 1 ORDER BY r.read_at DESC, r.id DESC LIMIT 1")[0] ?? null;
    }

    /** Ostatnia próba (także nieudana) — błąd pokazany na stronie. */
    public function lastPanelAttempt(): ?array
    {
        return $this->all('SELECT id, read_at, ok, error FROM n_forecast_panel_read ORDER BY read_at DESC, id DESC LIMIT 1')[0] ?? null;
    }

    public function insertPanelRead(string $readAt, ?int $userId, bool $ok, ?string $error, ?string $payload): int
    {
        $this->db->prepare('INSERT INTO n_forecast_panel_read (read_at, read_by, ok, error, payload) VALUES (?, ?, ?, ?, ?)')
            ->execute([$readAt, $userId, $ok ? 1 : 0, $error !== null ? mb_substr($error, 0, 255) : null, $payload]);
        $id = (int) $this->db->lastInsertId();
        // Tylko 20 ostatnich odczytów.
        $this->db->exec('DELETE FROM n_forecast_panel_read WHERE id NOT IN (SELECT id FROM (SELECT id FROM n_forecast_panel_read ORDER BY id DESC LIMIT 20) k)');
        return $id;
    }

    // --- Produkt gamy panelu → produkt ERP -----------------------------------------------------------------------

    /** @return array<int, array{id_final_product:?int, ignored:bool, panel_name:?string}> po id produktu sklepu */
    public function panelLinks(): array
    {
        $out = [];
        foreach ($this->all('SELECT panel_product_id, panel_name, id_final_product, ignored FROM n_forecast_panel_link') as $r) {
            $out[(int) $r['panel_product_id']] = ['id_final_product' => $r['id_final_product'] !== null ? (int) $r['id_final_product'] : null,
                'ignored' => (bool) (int) $r['ignored'], 'panel_name' => $r['panel_name']];
        }
        return $out;
    }

    public function savePanelLink(int $panelProductId, ?string $name, ?int $productId, bool $ignored, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_forecast_panel_link (panel_product_id, panel_name, id_final_product, ignored, created_by) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE panel_name = VALUES(panel_name), id_final_product = VALUES(id_final_product), ignored = VALUES(ignored)')
            ->execute([$panelProductId, $name !== null ? mb_substr($name, 0, 255) : null, $productId, $ignored ? 1 : 0, $userId]);
    }

    public function deletePanelLink(int $panelProductId): void
    {
        $this->db->prepare('DELETE FROM n_forecast_panel_link WHERE panel_product_id = ?')->execute([$panelProductId]);
    }
}
