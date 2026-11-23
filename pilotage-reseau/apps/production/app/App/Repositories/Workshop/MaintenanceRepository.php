<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Serwisy i przeglądy maszyn » [405] : lecture
 *  - des machines de l'ERP (n_prod_workstation : nom, type, groupe n_prod_group, active) ;
 *  - des arrêts « awaria » saisis sur la tablette du groupe (n_prod_ws_state : state = 'stop', reason = 'breakdown', 049) ;
 *  - des registres de l'assistant (n_equipment, n_equipment_check, n_equipment_ticket).
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que les tables de l'assistant
 * (n_equipment*, n_maintenance_review) : aucune machine ni aucun arrêt de l'ERP n'est modifié.
 */
final class MaintenanceRepository extends WorkshopRepository
{
    protected string $table = 'n_maintenance_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Machines de l'ERP (actives et non), avec groupe (null : table absente). */
    public function workstations(): ?array
    {
        if (!$this->hasTable('n_prod_workstation')) return null;
        $g = $this->hasTable('n_prod_group');
        return $this->all('SELECT w.id, w.code, w.name_pl, w.type, w.active, ' . ($g ? 'g.name_pl' : 'NULL') . ' AS group_name
            FROM n_prod_workstation w ' . ($g ? 'LEFT JOIN n_prod_group g ON g.id = w.id_group' : '') . ' ORDER BY w.name_pl, w.id');
    }

    /** Arrêts « awaria » commencés depuis $from (null : table absente). */
    public function breakdowns(string $from): ?array
    {
        if (!$this->hasTable('n_prod_ws_state')) return null;
        return $this->all("SELECT s.id, s.id_workstation, s.started_at, s.ended_at, s.note FROM n_prod_ws_state s
            WHERE s.state = 'stop' AND s.reason = 'breakdown' AND s.started_at >= ? ORDER BY s.started_at, s.id", [$from . ' 00:00:00']);
    }

    public function equipment(): array
    {
        return $this->all('SELECT * FROM n_equipment WHERE active = 1 ORDER BY name, id');
    }

    public function checks(): array
    {
        return $this->all('SELECT c.* FROM n_equipment_check c JOIN n_equipment e ON e.id = c.id_equipment AND e.active = 1
            WHERE c.active = 1 ORDER BY c.next_due IS NULL, c.next_due, c.id');
    }

    public function check(int $id): ?array
    {
        return $this->all('SELECT c.* FROM n_equipment_check c JOIN n_equipment e ON e.id = c.id_equipment AND e.active = 1 WHERE c.id = ? AND c.active = 1', [$id])[0] ?? null;
    }

    /** Zgłoszenia : toutes les ouvertes, et les fermées depuis $closedFrom (pour l'écran et les arrêts déjà couverts). */
    public function tickets(string $closedFrom): array
    {
        return $this->all("SELECT * FROM n_equipment_ticket WHERE status IN ('open', 'ordered') OR reported_on >= ? OR closed_on >= ?
            ORDER BY status IN ('open', 'ordered') DESC, due_date IS NULL, due_date, reported_on, id", [$closedFrom, $closedFrom]);
    }

    public function ticket(int $id): ?array
    {
        return $this->all('SELECT * FROM n_equipment_ticket WHERE id = ?', [$id])[0] ?? null;
    }

    /** Zgłoszenia liés à des arrêts TRS (id_ws_state), tous statuts confondus. @return array<int, true> */
    public function linkedStops(): array
    {
        return array_fill_keys(array_map('intval', array_column($this->all('SELECT DISTINCT id_ws_state FROM n_equipment_ticket WHERE id_ws_state IS NOT NULL'), 'id_ws_state')), true);
    }

    public function addEquipment(string $name, string $kind, ?int $wsId, ?string $location, ?string $note, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_equipment (name, kind, id_workstation, location, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $kind, $wsId, $location, $note, $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function addCheck(int $equipmentId, string $kind, ?string $title, ?int $interval, ?string $lastDone, ?string $nextDue, ?string $provider, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_equipment_check (id_equipment, kind, title, interval_months, last_done, next_due, provider, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$equipmentId, $kind, $title, $interval, $lastDone, $nextDue, $provider, $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function checkDone(int $id, string $doneOn, ?string $nextDue, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_equipment_check SET last_done = ?, next_due = ?, updated_by = ? WHERE id = ?')->execute([$doneOn, $nextDue, $userId, $id]);
    }

    public function addTicket(array $t, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_equipment_ticket (id_equipment, id_workstation, id_ws_state, kind, title, provider, reported_on, due_date, status, note, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$t['id_equipment'], $t['id_workstation'], $t['id_ws_state'], $t['kind'], $t['title'], $t['provider'], $t['reported_on'], $t['due_date'], $t['status'], $t['note'], $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function updateTicket(int $id, string $status, ?string $dueDate, ?string $closedOn, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_equipment_ticket SET status = ?, due_date = ?, closed_on = ?, updated_by = ? WHERE id = ?')->execute([$status, $dueDate, $closedOn, $userId, $id]);
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_maintenance_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<string> éléments décidés pendant le mois (restent à l'écran même sans signal). */
    public function decidedIn(string $monthStart): array
    {
        return array_column($this->all('SELECT item_key FROM n_maintenance_review WHERE month_start = ?', [$monthStart]), 'item_key');
    }

    /** @return list<string> arrêts TRS décidés un mois quelconque (ne reviennent plus). */
    public function decidedStops(): array
    {
        return array_column($this->all("SELECT DISTINCT item_key FROM n_maintenance_review WHERE kind = 'stop'"), 'item_key');
    }

    /** Une décision par (mois, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, string $kind, string $decision, ?string $bookedOn, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_maintenance_review (month_start, item_key, kind, decision, booked_on, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), booked_on = VALUES(booked_on), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $kind, $decision, $bookedOn, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
