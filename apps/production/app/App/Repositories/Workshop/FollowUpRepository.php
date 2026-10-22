<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Śledzenie produkcji (strona biura, protokół zebrania) : plan tygodnia (Gantt), partie i straty z tabletów,
 * zatwierdzone dni, niezgodności produkcji — produkty (sztuki), na grupę, dzień i produkt.
 */
final class FollowUpRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_output';

    public function groups(): array
    {
        return $this->all('SELECT id, code, name_pl, name_fr FROM tfb_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    /** Plan dnia na grupę główną produktu. @return list<array{work_date:string,id_group:int,id_item:int,q:float}> */
    public function planned(string $from, string $to): array
    {
        return $this->all("SELECT ws.work_date, s.id_primary_group AS id_group, ws.id_item, SUM(ws.quantity) AS q
                           FROM tfb_prod_week_slot ws
                           JOIN tfb_prod_item i ON i.id = ws.id_item AND i.item_type = 'product'
                           JOIN tfb_prod_product_settings s ON s.id_item = ws.id_item
                           WHERE ws.work_date BETWEEN ? AND ? AND s.id_primary_group IS NOT NULL
                           GROUP BY ws.work_date, s.id_primary_group, ws.id_item", [$from, $to]);
    }

    public function produced(string $from, string $to): array
    {
        return $this->all("SELECT o.work_date, o.id_group, o.id_item, SUM(o.quantity) AS q, COUNT(*) AS n
                           FROM tfb_prod_output o JOIN tfb_prod_item i ON i.id = o.id_item AND i.item_type = 'product'
                           WHERE o.work_date BETWEEN ? AND ? AND o.cancelled_at IS NULL
                           GROUP BY o.work_date, o.id_group, o.id_item", [$from, $to]);
    }

    public function waste(string $from, string $to): array
    {
        return $this->all("SELECT w.work_date, w.id_group, w.id_item, w.reason, SUM(w.quantity) AS q
                           FROM tfb_prod_waste w JOIN tfb_prod_item i ON i.id = w.id_item AND i.item_type = 'product'
                           WHERE w.work_date BETWEEN ? AND ? AND w.cancelled_at IS NULL
                           GROUP BY w.work_date, w.id_group, w.id_item, w.reason", [$from, $to]);
    }

    public function closes(string $from, string $to): array
    {
        return $this->all("SELECT c.work_date, c.id_group, c.closed_at, c.note, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name
                           FROM tfb_prod_day_close c
                           LEFT JOIN tfb_prod_employee e ON e.id = c.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE c.work_date BETWEEN ? AND ?", [$from, $to]);
    }

    /** Niezgodności produkcji okresu (zgłoszone z tabletów lub w rejestrze). */
    public function nc(string $from, string $to): array
    {
        return $this->all("SELECT n.id, n.number, n.id_prod_group, n.severity, n.status, n.description, DATE(n.reported_at) AS day
                           FROM tfb_nc n WHERE n.source = 'production' AND n.reported_at BETWEEN ? AND ? ORDER BY n.reported_at", [$from . ' 00:00:00', $to . ' 23:59:59']);
    }

    /** @return array<int, array{name:string, ppc:?int}> */
    public function items(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->all("SELECT i.id, COALESCE(fp.name, pr.name) AS name, fp.ppc FROM tfb_prod_item i
                             LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe WHERE i.id IN ({$in})") as $r) {
            $out[(int) $r['id']] = ['name' => $r['name'], 'ppc' => $r['ppc'] !== null && (int) $r['ppc'] > 0 ? (int) $r['ppc'] : null];
        }
        return $out;
    }
}
