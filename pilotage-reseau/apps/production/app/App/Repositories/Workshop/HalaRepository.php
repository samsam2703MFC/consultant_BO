<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Tablet hali « Mój dzień » [409–411] : tables propres (migrations 247–249) — réglages et strefy des groupes, sprzątanie,
 * étapes confirmées, fin de journée, zmiana, schładzanie, wezwania « Pomoc ». Lecture seule ailleurs : groupes
 * (n_prod_group), personnes et postes (n_prod_employee, production_employee, n_position), registre des lots matières
 * (n_material_lot, raw_material), photo du produit (n_product_media, emplacement 0), heure du plan (n_prod_week_slot),
 * validation du jour (n_prod_day_close), parties (n_prod_output). Une table absente (base partielle) rend une liste vide.
 */
final class HalaRepository extends WorkshopRepository
{
    protected string $table = 'n_hala_help';
    protected array $writable = ['work_date', 'id_group', 'id_device', 'id_employee', 'step', 'reason', 'note', 'status', 'created_at',
        'taken_at', 'taken_by', 'closed_at', 'closed_by'];

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    // --- Groupes et réglages ---------------------------------------------------------------------------------------------

    /** Groupes de production actifs, dans l'ordre de l'ERP. */
    public function groups(): array
    {
        return $this->all('SELECT id, code, name_pl, name_fr, color, sort_order FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    public function group(int $id): ?array
    {
        return $this->all('SELECT id, code, name_pl, name_fr, color, active FROM n_prod_group WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return array<int, array> groupe => réglage enregistré */
    public function settings(): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_hala_group') as $r) $out[(int) $r['id_group']] = $r;
        return $out;
    }

    public function saveSettings(int $groupId, array $s, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_hala_group (id_group, guided, step_make, step_bags, step_chill, step_clean, step_pack, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE guided = VALUES(guided), step_make = VALUES(step_make), step_bags = VALUES(step_bags), step_chill = VALUES(step_chill),
                    step_clean = VALUES(step_clean), step_pack = VALUES(step_pack), updated_by = VALUES(updated_by)')
            ->execute([$groupId, (int) $s['guided'], (int) $s['make'], (int) $s['bags'], (int) $s['chill'], (int) $s['clean'], (int) $s['pack'], $userId]);
    }

    // --- Strefy sprzątania -----------------------------------------------------------------------------------------------

    public function zones(?int $groupId = null, bool $activeOnly = true): array
    {
        $where = [];
        $params = [];
        if ($groupId !== null) { $where[] = 'id_group = ?'; $params[] = $groupId; }
        if ($activeOnly) $where[] = 'active = 1';
        return $this->all('SELECT * FROM n_hala_clean_zone' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id_group, sort_order, id', $params);
    }

    public function zone(int $id): ?array
    {
        return $this->all('SELECT * FROM n_hala_clean_zone WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertZone(int $groupId, string $namePl, ?string $nameFr, string $icon, ?int $userId): int
    {
        $sort = (int) ($this->all('SELECT COALESCE(MAX(sort_order), 0) AS m FROM n_hala_clean_zone WHERE id_group = ?', [$groupId])[0]['m'] ?? 0) + 1;
        $this->db->prepare('INSERT INTO n_hala_clean_zone (id_group, name_pl, name_fr, icon, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$groupId, $namePl, $nameFr, $icon, $sort, $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function updateZone(int $id, string $namePl, ?string $nameFr, string $icon, bool $active, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_hala_clean_zone SET name_pl = ?, name_fr = ?, icon = ?, active = ?, updated_by = ? WHERE id = ?')
            ->execute([$namePl, $nameFr, $icon, (int) $active, $userId, $id]);
    }

    /** @return array<int, array> strefa => {done_at, employee} */
    public function cleanRecords(string $day, int $groupId): array
    {
        $out = [];
        foreach ($this->all("SELECT r.id_zone, r.done_at, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee
                FROM n_hala_clean_record r LEFT JOIN n_prod_employee e ON e.id = r.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                WHERE r.work_date = ? AND r.id_group = ?", [$day, $groupId]) as $r) $out[(int) $r['id_zone']] = $r;
        return $out;
    }

    public function markClean(string $day, int $groupId, int $zoneId, ?int $employeeId, ?int $deviceId): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_hala_clean_record (work_date, id_group, id_zone, id_employee, id_device, done_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$day, $groupId, $zoneId, $employeeId, $deviceId]);
    }

    public function unmarkClean(string $day, int $zoneId): void
    {
        $this->db->prepare('DELETE FROM n_hala_clean_record WHERE work_date = ? AND id_zone = ?')->execute([$day, $zoneId]);
    }

    // --- Étapes, fin de journée, zmiana ----------------------------------------------------------------------------------

    /** @return array<string, string> étape => heure */
    public function steps(string $day, int $groupId): array
    {
        return array_column($this->all('SELECT step, done_at FROM n_hala_step WHERE work_date = ? AND id_group = ?', [$day, $groupId]), 'done_at', 'step');
    }

    public function markStep(string $day, int $groupId, string $step, ?int $employeeId, ?int $deviceId): void
    {
        $this->db->prepare('INSERT INTO n_hala_step (work_date, id_group, step, id_employee, id_device, done_at) VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE id_employee = VALUES(id_employee), id_device = VALUES(id_device), done_at = NOW()')
            ->execute([$day, $groupId, $step, $employeeId, $deviceId]);
    }

    public function dayFinished(string $day, int $groupId): ?array
    {
        return $this->all("SELECT d.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee FROM n_hala_day d
                LEFT JOIN n_prod_employee e ON e.id = d.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                WHERE d.work_date = ? AND d.id_group = ?", [$day, $groupId])[0] ?? null;
    }

    public function finishDay(string $day, int $groupId, ?int $employeeId, ?int $deviceId, string $summary): void
    {
        $this->db->prepare('INSERT INTO n_hala_day (work_date, id_group, finished_at, id_employee, id_device, summary) VALUES (?, ?, NOW(), ?, ?, ?)
                ON DUPLICATE KEY UPDATE finished_at = NOW(), id_employee = VALUES(id_employee), id_device = VALUES(id_device), summary = VALUES(summary)')
            ->execute([$day, $groupId, $employeeId, $deviceId, mb_substr($summary, 0, 255)]);
    }

    /** @return array<int, string> groupe => heure de la validation du jour de production (n_prod_day_close) */
    public function dayCloses(string $day): array
    {
        $out = [];
        foreach ($this->all('SELECT id_group, closed_at FROM n_prod_day_close WHERE work_date = ?', [$day]) as $r) $out[(int) $r['id_group']] = (string) $r['closed_at'];
        return $out;
    }

    /** @return array<int, string> groupe => heure de « Zatwierdź dzień » (n_hala_day) */
    public function daysFinished(string $day): array
    {
        return array_map('strval', array_column($this->all('SELECT id_group, finished_at FROM n_hala_day WHERE work_date = ?', [$day]), 'finished_at', 'id_group'));
    }

    public function shift(string $day): ?array
    {
        return $this->all("SELECT s.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee FROM n_hala_shift s
                LEFT JOIN n_prod_employee e ON e.id = s.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                WHERE s.work_date = ?", [$day])[0] ?? null;
    }

    public function validateShift(string $day, ?int $employeeId, ?int $deviceId, string $summary): void
    {
        $this->db->prepare('INSERT INTO n_hala_shift (work_date, validated_at, id_employee, id_device, summary) VALUES (?, NOW(), ?, ?, ?)
                ON DUPLICATE KEY UPDATE validated_at = NOW(), id_employee = VALUES(id_employee), id_device = VALUES(id_device), summary = VALUES(summary)')
            ->execute([$day, $employeeId, $deviceId, mb_substr($summary, 0, 255)]);
    }

    // --- Schładzanie -----------------------------------------------------------------------------------------------------

    public function chills(string $day, ?int $groupId = null): array
    {
        $params = [$day];
        $g = '';
        if ($groupId !== null) { $g = ' AND c.id_group = ?'; $params[] = $groupId; }
        return $this->all("SELECT c.*, COALESCE(fp.name, pr.name) AS name FROM n_hala_chill c
                LEFT JOIN n_prod_item i ON i.id = c.id_item LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
                WHERE c.work_date = ?{$g} ORDER BY c.out_at, c.id", $params);
    }

    public function chill(int $id): ?array
    {
        return $this->all('SELECT * FROM n_hala_chill WHERE id = ?', [$id])[0] ?? null;
    }

    public function chillByUuid(string $uuid): ?array
    {
        return $this->all('SELECT * FROM n_hala_chill WHERE client_uuid = ?', [$uuid])[0] ?? null;
    }

    public function insertChill(array $r): int
    {
        $this->db->prepare('INSERT INTO n_hala_chill (work_date, id_group, id_item, id_output, out_at, target_c, max_minutes, id_employee, id_device, client_uuid)
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)')
            ->execute([$r['work_date'], $r['id_group'], $r['id_item'], $r['id_output'], $r['target_c'], $r['max_minutes'], $r['id_employee'], $r['id_device'], $r['client_uuid']]);
        return (int) $this->db->lastInsertId();
    }

    public function measureChill(int $id, float $temp, ?int $employeeId): void
    {
        $this->db->prepare('UPDATE n_hala_chill SET temp_c = ?, measured_at = NOW(), measured_by = ? WHERE id = ?')->execute([$temp, $employeeId, $id]);
    }

    // --- Wezwania « Pomoc » ----------------------------------------------------------------------------------------------

    /** Wezwania du jour (ouvertes et prises d'abord), avec le groupe et la personne. */
    public function calls(string $day, bool $openOnly = false): array
    {
        return $this->all("SELECT h.*, g.name_pl AS group_name, g.name_fr AS group_name_fr, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee,
                    TRIM(CONCAT(pt.name, ' ', pt.surname)) AS taken_name
                FROM n_hala_help h LEFT JOIN n_prod_group g ON g.id = h.id_group
                LEFT JOIN n_prod_employee e ON e.id = h.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                LEFT JOIN n_prod_employee et ON et.id = h.taken_by LEFT JOIN production_employee pt ON pt.id = et.id_production_employee
                WHERE h.work_date = ?" . ($openOnly ? " AND h.status <> 'closed'" : '') . "
                ORDER BY h.status = 'closed', h.status = 'taken', h.created_at DESC, h.id DESC", [$day]);
    }

    /** Wezwanie encore ouvert (ou pris) du groupe aujourd'hui. */
    public function openCall(string $day, int $groupId): ?array
    {
        return $this->all("SELECT h.*, TRIM(CONCAT(pt.name, ' ', pt.surname)) AS taken_name FROM n_hala_help h
                LEFT JOIN n_prod_employee et ON et.id = h.taken_by LEFT JOIN production_employee pt ON pt.id = et.id_production_employee
                WHERE h.work_date = ? AND h.id_group = ? AND h.status <> 'closed' ORDER BY h.id DESC LIMIT 1", [$day, $groupId])[0] ?? null;
    }

    // --- Personnes, lots, produits ---------------------------------------------------------------------------------------

    /** Personne de la tablette : nom et code du poste. */
    public function employee(int $id): ?array
    {
        return $this->all("SELECT e.id, e.id_primary_group, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, pe.name AS first_name, p.code AS position_code
                FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee LEFT JOIN n_position p ON p.id = e.id_position
                WHERE e.id = ?", [$id])[0] ?? null;
    }

    /** Lots du registre au numéro lu (tous les surowce), avec le nom du surowiec. */
    public function lotsByNumber(string $lot): array
    {
        if (!$this->hasTable('n_material_lot')) return [];
        return $this->all('SELECT l.id, l.id_raw_material, l.lot_number, l.best_before, l.active, rm.name AS material
                FROM n_material_lot l LEFT JOIN raw_material rm ON rm.id = l.id_raw_material
                WHERE l.lot_number = ? ORDER BY l.active DESC, l.id DESC', [$lot]);
    }

    public function materialName(int $id): ?string
    {
        $r = $this->all('SELECT name FROM raw_material WHERE id = ?', [$id])[0] ?? null;
        return $r ? (string) $r['name'] : null;
    }

    /** @return array<int, array{product_id: ?int, has_photo: bool, start: ?string}> article => produit ERP, photo, heure du plan */
    public function itemsMeta(array $itemIds, string $day): array
    {
        if (!$itemIds) return [];
        $in = implode(',', array_map('intval', $itemIds));
        $photo = $this->hasTable('n_product_media')
            ? '(SELECT COUNT(*) FROM n_product_media m WHERE m.id_final_product = i.id_final_product AND m.slot = 0 AND m.image IS NOT NULL)' : '0';
        $start = $this->hasTable('n_prod_week_slot')
            ? '(SELECT MIN(w.start_time) FROM n_prod_week_slot w WHERE w.id_item = i.id AND w.work_date = ?)' : 'NULL';
        $out = [];
        foreach ($this->all("SELECT i.id, i.id_final_product, {$photo} AS photos, {$start} AS start_time FROM n_prod_item i WHERE i.id IN ({$in})",
                $start === 'NULL' ? [] : [$day]) as $r) {
            $out[(int) $r['id']] = ['product_id' => $r['id_final_product'] !== null ? (int) $r['id_final_product'] : null, 'has_photo' => (int) $r['photos'] > 0,
                'start' => $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 5) : null];
        }
        return $out;
    }

    /** Photo du produit (emplacement 0 de la fiche). */
    public function productPhoto(int $itemId): ?array
    {
        if (!$this->hasTable('n_product_media')) return null;
        return $this->all('SELECT m.mime, m.image FROM n_prod_item i JOIN n_product_media m ON m.id_final_product = i.id_final_product AND m.slot = 0
                WHERE i.id = ? AND m.image IS NOT NULL', [$itemId])[0] ?? null;
    }
}
