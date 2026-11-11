<?php
namespace App\Repositories\Staff;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Premie zespołów : progi (n_bonus_tier), zakładka « Premia » grupy (n_prod_group.bonus_visible) i dni obecności
 * osób grupy w miesiącu (odbicia n_prod_clock). Osoby grupy jak na tablecie : grupa główna albo zespół grupy.
 */
final class TeamBonusRepository extends WorkshopRepository
{
    protected string $table = 'n_bonus_tier';

    private const MEMBER = '(e.id_primary_group = ? OR e.id IN (SELECT m.id_employee FROM n_prod_team_member m JOIN n_prod_team t ON t.id = m.id_team WHERE t.id_group = ? AND t.active = 1))';

    /** @return list<array{id:int, from:float, amount:float}> rosnąco */
    public function tiers(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'from' => (float) $r['from_per_person_pln'], 'amount' => (float) $r['amount_pln']],
            $this->all('SELECT id, from_per_person_pln, amount_pln FROM n_bonus_tier ORDER BY from_per_person_pln'));
    }

    /** @param list<array{from:float, amount:float}> $tiers zastępują wszystkie progi (w transakcji wywołującego) */
    public function replaceTiers(array $tiers, ?int $userId): void
    {
        $this->db->exec('DELETE FROM n_bonus_tier');
        $stmt = $this->db->prepare('INSERT INTO n_bonus_tier (from_per_person_pln, amount_pln, updated_by) VALUES (?, ?, ?)');
        foreach ($tiers as $t) $stmt->execute([$t['from'], $t['amount'], $userId]);
    }

    /** Aktywne grupy z działem kosztów (sprzedaż WDT idzie na dział) i widocznością zakładki. */
    public function groups(): array
    {
        return $this->all('SELECT id, name_pl, name_fr, color, id_section, bonus_visible FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    public function group(int $id): ?array
    {
        return $this->all('SELECT id, name_pl, name_fr, color, id_section, bonus_visible FROM n_prod_group WHERE id = ? AND active = 1', [$id])[0] ?? null;
    }

    public function setVisible(int $groupId, bool $visible): void
    {
        $this->db->prepare('UPDATE n_prod_group SET bonus_visible = ? WHERE id = ?')->execute([(int) $visible, $groupId]);
    }

    /**
     * Osoby grupy (aktywne) i ich dni z odbiciem w [from, to] ; group_days = dni, w których ktokolwiek z grupy się odbił.
     * @return array{people:list<array{id:int, name:string, days:int}>, group_days:int}
     */
    public function presence(int $groupId, string $from, string $to): array
    {
        $people = $this->all(
            "SELECT e.id, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name,
                    (SELECT COUNT(DISTINCT DATE(k.clock_in)) FROM n_prod_clock k WHERE k.id_employee = e.id AND k.clock_in >= ? AND k.clock_in < ? + INTERVAL 1 DAY) AS days
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE e.active = 1 AND " . self::MEMBER . ' ORDER BY pe.surname, pe.name',
            [$from, $to, $groupId, $groupId]
        );
        $days = $this->all(
            'SELECT COUNT(DISTINCT DATE(k.clock_in)) AS n FROM n_prod_clock k JOIN n_prod_employee e ON e.id = k.id_employee
             WHERE k.clock_in >= ? AND k.clock_in < ? + INTERVAL 1 DAY AND ' . self::MEMBER,
            [$from, $to, $groupId, $groupId]
        );
        return [
            'people' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'days' => (int) $p['days']], $people),
            'group_days' => (int) ($days[0]['n'] ?? 0),
        ];
    }
}
