<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** TRS : maszyny śledzone na tabletach, stany (praca / postój), produkcja i straty kart na dzień i grupę. */
final class OeeRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_ws_state';

    private const MACHINE = "SELECT w.id, w.code, w.name_pl, w.name_fr, w.type, w.robot_mode, w.operator_share, w.id_group, g.name_pl AS group_name
        FROM tfb_prod_workstation w JOIN tfb_prod_group g ON g.id = w.id_group";

    /** Maszyny z TRS (aktywne), opcjonalnie jednej grupy. */
    public function machines(?int $groupId = null): array
    {
        return $this->all(self::MACHINE . ' WHERE w.active = 1 AND w.oee_tracked = 1' . ($groupId !== null ? ' AND w.id_group = ?' : '')
            . ' ORDER BY g.sort_order, w.name_pl, w.id', $groupId !== null ? [$groupId] : []);
    }

    public function machine(int $id): ?array
    {
        return $this->all(self::MACHINE . ' WHERE w.id = ? AND w.oee_tracked = 1', [$id])[0] ?? null;
    }

    /** Stany maszyn przecinające okres [from, to) (otwarte : ended_at NULL). */
    public function states(array $wsIds, string $from, string $to): array
    {
        if (!$wsIds) return [];
        $in = implode(',', array_map('intval', $wsIds));
        return $this->all("SELECT s.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name
                           FROM tfb_prod_ws_state s
                           LEFT JOIN tfb_prod_employee e ON e.id = s.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE s.id_workstation IN ({$in}) AND s.started_at < ? AND (s.ended_at IS NULL OR s.ended_at > ?)
                           ORDER BY s.id_workstation, s.started_at, s.id", [$to, $from]);
    }

    public function open(int $wsId): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_ws_state WHERE id_workstation = ? AND ended_at IS NULL ORDER BY started_at DESC, id DESC LIMIT 1', [$wsId])[0] ?? null;
    }

    public function byUuid(string $uuid): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_ws_state WHERE client_uuid = ?', [$uuid])[0] ?? null;
    }

    public function close(int $wsId, string $at): void
    {
        $this->db->prepare('UPDATE tfb_prod_ws_state SET ended_at = GREATEST(started_at, ?) WHERE id_workstation = ? AND ended_at IS NULL')->execute([$at, $wsId]);
    }

    public function start(array $s): int
    {
        $this->db->prepare('INSERT INTO tfb_prod_ws_state (id_workstation, state, reason, note, started_at, id_employee, id_device, client_uuid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$s['id_workstation'], $s['state'], $s['reason'], $s['note'], $s['started_at'], $s['id_employee'], $s['id_device'], $s['client_uuid']]);
        return (int) $this->db->lastInsertId();
    }

    /** Partie (bez anulowanych) na kartę w grupach i dniach okresu. @return list<array{id_group:int,id_item:int,q:float}> */
    public function produced(array $groupIds, string $fromDay, string $toDay): array
    {
        if (!$groupIds) return [];
        $in = implode(',', array_map('intval', $groupIds));
        return $this->all("SELECT id_group, id_item, SUM(quantity) AS q FROM tfb_prod_output
                           WHERE cancelled_at IS NULL AND id_group IN ({$in}) AND work_date BETWEEN ? AND ? GROUP BY id_group, id_item", [$fromDay, $toDay]);
    }

    public function waste(array $groupIds, string $fromDay, string $toDay): array
    {
        if (!$groupIds) return [];
        $in = implode(',', array_map('intval', $groupIds));
        return $this->all("SELECT id_group, id_item, SUM(quantity) AS q FROM tfb_prod_waste
                           WHERE cancelled_at IS NULL AND id_group IN ({$in}) AND work_date BETWEEN ? AND ? GROUP BY id_group, id_item", [$fromDay, $toDay]);
    }

    /** @return array<int, string> karta => nazwa */
    public function itemNames(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        return array_column($this->all("SELECT i.id, COALESCE(fp.name, pr.name) AS name FROM tfb_prod_item i
                                         LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
                                         WHERE i.id IN ({$in})"), 'name', 'id');
    }
}
