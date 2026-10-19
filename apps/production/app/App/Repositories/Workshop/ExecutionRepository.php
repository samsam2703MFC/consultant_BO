<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** P7 : tablettes, identifiants des personnes, pointage, temps passés sur les opérations, signatures. */
final class ExecutionRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_device';
    protected array $writable = ['name', 'id_group', 'token_hash', 'pairing_code_hash', 'pairing_expires_at', 'active', 'last_seen_at', 'created_by'];

    // --- Tablettes -------------------------------------------------------------------------------

    public function devices(): array
    {
        return $this->all('SELECT d.id, d.name, d.id_group, g.name_pl AS group_pl, g.name_fr AS group_fr, d.active, d.last_seen_at, d.created_at,
                                  d.token_hash IS NOT NULL AS paired, d.pairing_expires_at
                           FROM tfb_prod_device d LEFT JOIN tfb_prod_group g ON g.id = d.id_group ORDER BY d.active DESC, d.name');
    }

    public function deviceByToken(string $hash): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_device WHERE token_hash = ?', [$hash])[0] ?? null;
    }

    public function deviceByPairing(string $hash): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_device WHERE pairing_code_hash = ? AND pairing_expires_at > NOW()', [$hash])[0] ?? null;
    }

    public function touchDevice(int $id): void
    {
        $this->db->prepare('UPDATE tfb_prod_device SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 1 MINUTE)')->execute([$id]);
    }

    // --- Personnes et identifiants -----------------------------------------------------------------

    public function employee(int $id): ?array
    {
        return $this->all(
            'SELECT e.id, e.id_production_employee, e.id_primary_group, e.active, TRIM(CONCAT(pe.name, " ", pe.surname)) AS name, pe.id_section
             FROM tfb_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee WHERE e.id = ?',
            [$id]
        )[0] ?? null;
    }

    /** Groupes d'une personne : groupe principal et groupes de ses équipes en cours. */
    public function employeeGroups(int $id): array
    {
        $rows = $this->all(
            'SELECT id_primary_group AS g FROM tfb_prod_employee WHERE id = ? AND id_primary_group IS NOT NULL
             UNION SELECT t.id_group FROM tfb_prod_team_member m JOIN tfb_prod_team t ON t.id = m.id_team AND t.active = 1
             WHERE m.id_employee = ? AND (m.valid_from IS NULL OR m.valid_from <= CURDATE()) AND (m.valid_to IS NULL OR m.valid_to >= CURDATE())',
            [$id, $id]
        );
        return array_values(array_unique(array_map(fn($r) => (int) $r['g'], $rows)));
    }

    /** Personnes actives d'un groupe (ou toutes), avec leur état d'identifiant et de pointage. */
    public function people(?int $groupId): array
    {
        $params = [];
        $where = '';
        if ($groupId !== null) {
            $where = ' AND (e.id_primary_group = ? OR e.id IN (SELECT m.id_employee FROM tfb_prod_team_member m JOIN tfb_prod_team t ON t.id = m.id_team WHERE t.id_group = ? AND t.active = 1))';
            $params = [$groupId, $groupId];
        }
        return $this->all(
            "SELECT e.id, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, e.id_primary_group,
                    c.pin_hash IS NOT NULL AS has_pin, c.badge_hash IS NOT NULL AS has_badge, COALESCE(c.biometric_consent, 0) AS biometric_consent,
                    c.locked_until, (SELECT k.clock_in FROM tfb_prod_clock k WHERE k.id_employee = e.id AND k.clock_out IS NULL ORDER BY k.clock_in DESC LIMIT 1) AS clocked_in_at,
                    (SELECT COUNT(*) FROM tfb_prod_phone p WHERE p.id_employee = e.id AND p.revoked_at IS NULL) AS phones
             FROM tfb_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN tfb_prod_employee_credential c ON c.id_employee = e.id
             WHERE e.active = 1{$where} ORDER BY pe.surname, pe.name",
            $params
        );
    }

    public function credential(int $employeeId): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_employee_credential WHERE id_employee = ?', [$employeeId])[0] ?? null;
    }

    public function credentialByBadge(string $hash): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_employee_credential WHERE badge_hash = ?', [$hash])[0] ?? null;
    }

    public function saveCredential(int $employeeId, array $data): void
    {
        $allowed = ['badge_hash', 'pin_hash', 'failed_attempts', 'locked_until', 'biometric_consent', 'biometric_consent_at', 'biometric_consent_by', 'updated_by'];
        $data = array_intersect_key($data, array_flip($allowed));
        if (!$data) return;
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO tfb_prod_employee_credential (id_employee, %s) VALUES (:id, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute(['id' => $employeeId] + $data);
    }

    // --- Téléphones (pointage par QR code) ----------------------------------------------------------

    public function insertPhone(int $employeeId, string $hash, ?string $label): int
    {
        $this->db->prepare('INSERT INTO tfb_prod_phone (id_employee, token_hash, label, last_used_at) VALUES (?, ?, ?, NOW())')->execute([$employeeId, $hash, $label]);
        return (int) $this->db->lastInsertId();
    }

    public function phoneByHash(string $hash): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_phone WHERE token_hash = ?', [$hash])[0] ?? null;
    }

    public function touchPhone(int $id): void
    {
        $this->db->prepare('UPDATE tfb_prod_phone SET last_used_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public function revokePhones(int $employeeId): int
    {
        $stmt = $this->db->prepare('UPDATE tfb_prod_phone SET revoked_at = NOW() WHERE id_employee = ? AND revoked_at IS NULL');
        $stmt->execute([$employeeId]);
        return $stmt->rowCount();
    }

    // --- Pointage ------------------------------------------------------------------------------------

    public function openClock(int $employeeId): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_clock WHERE id_employee = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1', [$employeeId])[0] ?? null;
    }

    public function clock(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_clock WHERE id = ?', [$id])[0] ?? null;
    }

    public function clockByUuid(string $uuid): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_clock WHERE in_client_uuid = ? OR out_client_uuid = ?', [$uuid, $uuid])[0] ?? null;
    }

    public function clockIn(int $employeeId, string $at, ?int $deviceId, ?string $uuid, string $source = 'kiosk', ?string $note = null): int
    {
        $this->db->prepare('INSERT INTO tfb_prod_clock (id_employee, clock_in, id_device, source, in_client_uuid, note) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$employeeId, $at, $deviceId, $source, $uuid, $note]);
        return (int) $this->db->lastInsertId();
    }

    public function clockOut(int $id, string $at, ?string $uuid): void
    {
        $this->db->prepare('UPDATE tfb_prod_clock SET clock_out = ?, out_client_uuid = ? WHERE id = ? AND clock_out IS NULL')->execute([$at, $uuid, $id]);
    }

    public function correctClock(int $id, string $in, ?string $out, string $note, ?int $userId): void
    {
        $this->db->prepare('UPDATE tfb_prod_clock SET clock_in = ?, clock_out = ?, note = ?, corrected_by = ?, corrected_at = NOW() WHERE id = ?')
            ->execute([$in, $out, $note, $userId, $id]);
    }

    /** Pointages chevauchant [from, to[ (ouverts compris), pour une personne ou toutes. */
    public function clocks(string $from, string $to, ?int $employeeId = null): array
    {
        $params = [$to, $from];
        $where = '';
        if ($employeeId !== null) { $where = ' AND k.id_employee = ?'; $params[] = $employeeId; }
        return $this->all(
            "SELECT k.*, e.id_primary_group, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
             FROM tfb_prod_clock k JOIN tfb_prod_employee e ON e.id = k.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE k.clock_in < ? AND (k.clock_out IS NULL OR k.clock_out > ?){$where} ORDER BY k.clock_in",
            $params
        );
    }

    // --- Opérations et temps passés ----------------------------------------------------------------

    public function openLogs(int $operationId): array
    {
        return $this->all('SELECT * FROM tfb_prod_time_log WHERE id_operation = ? AND ended_at IS NULL', [$operationId]);
    }

    public function openLogsOfEmployee(int $employeeId): array
    {
        return $this->all('SELECT * FROM tfb_prod_time_log WHERE id_employee = ? AND ended_at IS NULL', [$employeeId]);
    }

    public function logs(int $operationId): array
    {
        return $this->all('SELECT * FROM tfb_prod_time_log WHERE id_operation = ? ORDER BY started_at', [$operationId]);
    }

    public function startLog(int $operationId, int $employeeId, string $at, ?string $uuid): void
    {
        $this->db->prepare('INSERT INTO tfb_prod_time_log (id_operation, id_employee, started_at, source, client_uuid) VALUES (?, ?, ?, "tablet", ?)')
            ->execute([$operationId, $employeeId, $at, $uuid]);
    }

    public function endLog(int $logId, string $at): void
    {
        $this->db->prepare('UPDATE tfb_prod_time_log SET ended_at = GREATEST(started_at, ?) WHERE id = ? AND ended_at IS NULL')->execute([$at, $logId]);
    }

    public function setOperation(int $id, array $data): void
    {
        $allowed = ['status', 'actual_start', 'actual_end', 'qty_good', 'qty_scrap', 'scrap_reason'];
        $data = array_intersect_key($data, array_flip($allowed));
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $this->db->prepare("UPDATE tfb_prod_operation SET {$set} WHERE id = :id")->execute($data + ['id' => $id]);
    }

    public function routingStep(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_routing_step WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertTimeStudy(array $s): void
    {
        $this->db->prepare('INSERT INTO tfb_prod_time_study (id_routing_step, id_workstation, component, quantity, passes, duration_s, unit_time_s, measured_at, id_employee, note)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$s['id_routing_step'], $s['id_workstation'], $s['component'], $s['quantity'], $s['passes'], $s['duration_s'], $s['unit_time_s'], $s['measured_at'], $s['id_employee'], $s['note']]);
    }

    /** Opérations d'une période avec temps passés (KPIs). */
    public function operationsWithLabour(string $from, string $to): array
    {
        return $this->all(
            "SELECT op.id, op.kind, op.status, op.planned_start, op.planned_end, op.actual_start, op.actual_end, op.qty_good, op.qty_scrap,
                    w.id_group, rs.operators_required, o.id AS order_id, o.origin, o.due_at,
                    (SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, l.started_at, COALESCE(l.ended_at, NOW()))), 0) FROM tfb_prod_time_log l WHERE l.id_operation = op.id) AS labour_s
             FROM tfb_prod_operation op JOIN tfb_prod_order o ON o.id = op.id_order JOIN tfb_prod_workstation w ON w.id = op.id_workstation
             LEFT JOIN tfb_prod_routing_step rs ON rs.id = op.id_routing_step
             WHERE op.status <> 'cancelled' AND op.planned_start < ? AND op.planned_end > ?",
            [$to, $from]
        );
    }

    /** OF MTO dus sur la période, avec fin réelle de leur dernière opération. */
    public function dueOrders(string $from, string $to): array
    {
        return $this->all(
            "SELECT o.id, o.code, o.origin, o.due_at, o.status,
                    SUM(op.status <> 'done') AS open_ops, MAX(op.actual_end) AS finished_at, COUNT(op.id) AS ops
             FROM tfb_prod_order o LEFT JOIN tfb_prod_operation op ON op.id_order = o.id AND op.status <> 'cancelled'
             WHERE o.due_at >= ? AND o.due_at < ? AND o.status <> 'cancelled'
             GROUP BY o.id",
            [$from, $to]
        );
    }

    // --- Signatures ----------------------------------------------------------------------------------

    public function insertSignature(array $s): int
    {
        $this->db->prepare('INSERT INTO tfb_prod_signature (id_employee, purpose, ref, storage, image_png, s3_key, sha256, id_device) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$s['id_employee'], $s['purpose'], $s['ref'], $s['storage'], $s['image_png'], $s['s3_key'], $s['sha256'], $s['id_device']]);
        return (int) $this->db->lastInsertId();
    }

    /** Signatures d'une personne (sans l'image). */
    public function signatures(int $employeeId, ?string $purpose = null): array
    {
        $params = [$employeeId];
        $where = '';
        if ($purpose !== null) { $where = ' AND purpose = ?'; $params[] = $purpose; }
        return $this->all("SELECT id, purpose, ref, sha256, signed_at FROM tfb_prod_signature WHERE id_employee = ?{$where} ORDER BY signed_at", $params);
    }

    public function signatureImage(int $id): ?array
    {
        return $this->all('SELECT id, id_employee, storage, image_png, s3_key, sha256 FROM tfb_prod_signature WHERE id = ?', [$id])[0] ?? null;
    }
}
