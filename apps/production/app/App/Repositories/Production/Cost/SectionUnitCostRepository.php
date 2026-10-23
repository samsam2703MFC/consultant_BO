<?php
declare(strict_types=1);

namespace App\Repositories\Production\Cost;

use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Workshop\WorkshopRepository;
use PDO;

/** Dane miesięcy kosztowych (cost_month_*) czytane przez SectionUnitCostService. */
final class SectionUnitCostRepository extends WorkshopRepository
{
    protected string $table = 'cost_month';

    public function __construct(PDO $db, private WdtSalesRepository $wdt)
    {
        parent::__construct($db);
    }

    /** @return array<int, array{id:int, year:int, month:int, status:string}> */
    public function months(array $monthIds): array
    {
        if (!$monthIds) return [];
        $rows = $this->all('SELECT id, year, month, status FROM cost_month WHERE id IN (' . $this->ids($monthIds) . ')');
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = ['id' => (int) $r['id'], 'year' => (int) $r['year'], 'month' => (int) $r['month'], 'status' => $r['status']];
        return $out;
    }

    /** Działy : nazwa, amortyzacja i media stałe (miesięcznie), dział wspólny. */
    public function sections(): array
    {
        return $this->all(
            'SELECT ps.id, ps.name, COALESCE(ps.depreciation, 0) AS depreciation,
                    COALESCE(ps.water_cost, 0) + COALESCE(ps.power_supply_cost, 0) + COALESCE(ps.fuel_cost, 0) + COALESCE(ps.other_cost, 0) AS static_media,
                    COALESCE(t.is_shared, 0) AS is_shared
             FROM production_sections ps LEFT JOIN tfb_section_target t ON t.id_section = ps.id ORDER BY ps.id'
        );
    }

    /** @return array<int, array<int, float>> miesiąc => dział => wynagrodzenia (uwzględnione) */
    public function labor(array $monthIds): array
    {
        return $this->byMonthSection('SELECT month_id, section_id, SUM(monthly_cost) AS v FROM cost_month_employee
                                      WHERE is_included = 1 AND month_id IN (' . $this->ids($monthIds) . ') GROUP BY month_id, section_id', $monthIds);
    }

    /** @return list<array{month_id:int, media_type_id:int, amount:float}> */
    public function mediaAmounts(array $monthIds): array
    {
        if (!$monthIds) return [];
        return $this->all('SELECT cost_month_id AS month_id, media_type_id, SUM(amount) AS amount FROM cost_media_month
                           WHERE cost_month_id IN (' . $this->ids($monthIds) . ') GROUP BY cost_month_id, media_type_id');
    }

    /** @return list<array{month_id:int, media_type_id:int, section_id:int, pct:float}> */
    public function mediaAllocations(array $monthIds): array
    {
        if (!$monthIds) return [];
        return $this->all('SELECT cost_month_id AS month_id, media_type_id, section_id, SUM(allocation_percent) AS pct FROM cost_media_section_allocation
                           WHERE cost_month_id IN (' . $this->ids($monthIds) . ') GROUP BY cost_month_id, media_type_id, section_id');
    }

    /** @return array<int, float> miesiąc => koszty stałe firmy */
    public function fixed(array $monthIds): array
    {
        return $this->byMonth('SELECT month_id, SUM(amount) AS v FROM cost_month_fixed_cost WHERE month_id IN (' . $this->ids($monthIds) . ') GROUP BY month_id', $monthIds);
    }

    /** @return array<int, float> miesiąc => koszty logistyki */
    public function logistics(array $monthIds): array
    {
        return $this->byMonth('SELECT month_id, SUM(amount) AS v FROM cost_month_logistics WHERE month_id IN (' . $this->ids($monthIds) . ') GROUP BY month_id', $monthIds);
    }

    /** @return array<int, array<int, int>> miesiąc => dział => kartony wpisane w kalkulatorze (cost_month_section) */
    public function enteredCartons(array $monthIds): array
    {
        return $this->byMonthSection('SELECT month_id, section_id, SUM(cartons_qty) AS v FROM cost_month_section
                                      WHERE month_id IN (' . $this->ids($monthIds) . ') GROUP BY month_id, section_id', $monthIds);
    }

    /** @return array<int, array<int, int>> miesiąc => dział => kartony sprzedane (WDT, jedna definicja sprzedaży) */
    public function soldCartons(array $months): array
    {
        $out = [];
        foreach ($months as $id => $m) {
            $ym = sprintf('%04d-%02d', $m['year'], $m['month']);
            [$from, $to] = WdtSalesRepository::monthBounds($ym, $ym);
            foreach ($this->wdt->sales($from, $to, [], ['section']) as $r) {
                if ($r['section_id'] !== null) $out[$id][(int) $r['section_id']] = (int) $r['cartons'];
            }
        }
        return $out;
    }

    private function byMonth(string $sql, array $monthIds): array
    {
        if (!$monthIds) return [];
        $out = [];
        foreach ($this->all($sql) as $r) $out[(int) $r['month_id']] = (float) $r['v'];
        return $out;
    }

    private function byMonthSection(string $sql, array $monthIds): array
    {
        if (!$monthIds) return [];
        $out = [];
        foreach ($this->all($sql) as $r) $out[(int) $r['month_id']][(int) $r['section_id']] = (float) $r['v'];
        return $out;
    }

    private function ids(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids ? implode(',', $ids) : '0';
    }
}
