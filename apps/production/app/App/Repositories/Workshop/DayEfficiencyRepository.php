<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Wydajność dnia (Tablety › Zatwierdzenia produkcji) : odbicia osób grup (n_prod_clock), partie zrobione
 * (n_prod_output) i sprzątanie dnia (n_prod_day_cleaning). Osoby grupy jak na tablecie : grupa główna albo zespół grupy.
 */
final class DayEfficiencyRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_day_cleaning';

    /** @return array<int, list<int>> osoba → grupy (grupa główna i zespoły aktywne) */
    public function memberships(): array
    {
        $out = [];
        foreach ($this->all('SELECT id AS id_employee, id_primary_group AS id_group FROM n_prod_employee WHERE id_primary_group IS NOT NULL
                             UNION SELECT m.id_employee, t.id_group FROM n_prod_team_member m JOIN n_prod_team t ON t.id = m.id_team WHERE t.active = 1') as $r) {
            $out[(int) $r['id_employee']][] = (int) $r['id_group'];
        }
        return array_map(fn($g) => array_values(array_unique($g)), $out);
    }

    /** Odbicia rozpoczęte w dniach od–do (zmiana po północy liczy się w dniu wejścia), z osobą i stanowiskiem. */
    public function clocks(string $from, string $to): array
    {
        return $this->all(
            "SELECT k.id_employee, k.clock_in, k.clock_out, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name,
                    e.id_position, p.name_pl AS position_pl, p.name_fr AS position_fr, p.sort_order AS position_sort
             FROM n_prod_clock k
             JOIN n_prod_employee e ON e.id = k.id_employee
             JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN n_position p ON p.id = e.id_position
             WHERE k.clock_in >= ? AND k.clock_in < ? + INTERVAL 1 DAY
             ORDER BY k.clock_in",
            [$from, $to]
        );
    }

    /** Zrobione (partie nieanulowane) na dzień, grupę i produkt. */
    public function outputs(string $from, string $to, ?int $groupId = null): array
    {
        $params = [$from, $to];
        $where = '';
        if ($groupId !== null) { $where = ' AND id_group = ?'; $params[] = $groupId; }
        return $this->all(
            "SELECT work_date, id_group, id_item, SUM(quantity) AS quantity FROM n_prod_output
             WHERE cancelled_at IS NULL AND work_date BETWEEN ? AND ?{$where} GROUP BY work_date, id_group, id_item",
            $params
        );
    }

    /** @return array<string, int> « data|grupa » → minuty sprzątania na osobę */
    public function cleaning(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all('SELECT work_date, id_group, minutes_per_person FROM n_prod_day_cleaning WHERE work_date BETWEEN ? AND ?', [$from, $to]) as $r) {
            $out[$r['work_date'] . '|' . $r['id_group']] = (int) $r['minutes_per_person'];
        }
        return $out;
    }

    public function setCleaning(string $date, int $groupId, int $minutes, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_prod_day_cleaning (work_date, id_group, minutes_per_person, updated_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE minutes_per_person = VALUES(minutes_per_person), updated_by = VALUES(updated_by)')
            ->execute([$date, $groupId, $minutes, $userId]);
    }
}
