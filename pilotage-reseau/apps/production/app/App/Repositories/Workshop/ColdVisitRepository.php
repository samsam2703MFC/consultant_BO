<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [432] Obchód chłodni przed zakupami : miejsca (chłodnie / mroźnie du rejestr n_equipment + miejsca ajoutées), surowce
 * de chaque miejsce, obchody, miejsca zapisane et lignes policzone. Lit raw_material, unit, n_equipment, n_material_lot et
 * les personnes de la production ; écrit seulement les tables n_cold_*. L'inventaire (n_mp_count) est écrit par
 * SupplyRepository::addCount, le mécanisme de Zaopatrzenie.
 */
final class ColdVisitRepository extends WorkshopRepository
{
    protected string $table = 'n_cold_visit';
    protected array $writable = ['started_at', 'completed_at', 'id_employee', 'id_device', 'places', 'materials', 'differences'];
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Migration 255 appliquée (tables du obchód présentes). */
    public function ready(): bool
    {
        return $this->hasTable('n_cold_visit') && $this->hasTable('n_cold_place');
    }

    // --- Miejsca ---------------------------------------------------------------------------------------------------------

    /** Chłodnie / mroźnie ajoutées au rejestr depuis la migration : recopiées comme miejsca (une fois par urządzenie). */
    public function syncEquipment(): void
    {
        if (!$this->hasTable('n_equipment')) return;
        $this->db->exec("INSERT INTO n_cold_place (id_equipment, kind, sort_order)
            SELECT e.id, 'cold', 0 FROM n_equipment e
            WHERE e.kind = 'cold' AND NOT EXISTS (SELECT 1 FROM n_cold_place p WHERE p.id_equipment = e.id)");
    }

    /**
     * Miejsca actives : urządzenie actif (et toujours « chłodnia / mroźnia ») ou miejsce sans urządzenie active ; nombre de
     * surowce rattachés. Urządzenia d'abord (par nom), puis les miejsca ajoutées (ordre, nom).
     */
    public function places(): array
    {
        $eq = $this->hasTable('n_equipment');
        return $this->all('SELECT p.id, p.id_equipment, p.kind, p.sort_order, ' . ($eq ? 'COALESCE(e.name, p.name)' : 'p.name') . ' AS name, '
            . ($eq ? 'e.location' : 'NULL') . ' AS location,
                (SELECT COUNT(*) FROM n_cold_place_material m WHERE m.id_place = p.id) AS materials
            FROM n_cold_place p ' . ($eq ? 'LEFT JOIN n_equipment e ON e.id = p.id_equipment' : '') . '
            WHERE p.active = 1 AND ' . ($eq ? "(p.id_equipment IS NULL OR (e.id IS NOT NULL AND e.active = 1 AND e.kind = 'cold'))" : 'p.id_equipment IS NULL') . '
            ORDER BY p.id_equipment IS NULL, p.sort_order, name, p.id');
    }

    public function place(int $id): ?array
    {
        foreach ($this->places() as $p) if ((int) $p['id'] === $id) return $p;
        return null;
    }

    /** Miejsce sans urządzenie (Magazyn suchy…), après les autres. */
    public function addPlace(string $name, string $kind, ?int $userId): int
    {
        $sort = (int) ($this->all('SELECT COALESCE(MAX(sort_order), 0) AS m FROM n_cold_place WHERE id_equipment IS NULL')[0]['m'] ?? 0) + 1;
        $this->db->prepare('INSERT INTO n_cold_place (id_equipment, name, kind, sort_order, created_by) VALUES (NULL, ?, ?, ?, ?)')->execute([$name, $kind, $sort, $userId]);
        return (int) $this->db->lastInsertId();
    }

    /** Ligne brute (active ou non, avec ou sans urządzenie). */
    public function placeRow(int $id): ?array
    {
        return $this->all('SELECT * FROM n_cold_place WHERE id = ?', [$id])[0] ?? null;
    }

    public function deactivatePlace(int $id): void
    {
        $this->db->prepare('UPDATE n_cold_place SET active = 0 WHERE id = ?')->execute([$id]);
    }

    // --- Surowce des miejsca ------------------------------------------------------------------------------------------

    /** Surowce rattachés à une miejsce : nom, jednostka bazowa. */
    public function placeMaterials(int $placeId): array
    {
        return $this->all('SELECT rm.id, rm.name, rm.name_fr, u.name AS unit FROM n_cold_place_material m
            JOIN raw_material rm ON rm.id = m.id_raw_material LEFT JOIN unit u ON u.id = rm.id_unit
            WHERE m.id_place = ? ORDER BY rm.name, rm.id', [$placeId]);
    }

    /** @return array<int, list<int>> surowiec → miejsca actives où il leży */
    public function materialPlaces(array $materialIds): array
    {
        if (!$materialIds) return [];
        $active = array_map(fn($p) => (int) $p['id'], $this->places());
        if (!$active) return [];
        $out = [];
        foreach ($this->all('SELECT id_place, id_raw_material FROM n_cold_place_material WHERE id_raw_material IN (' . implode(',', array_map('intval', $materialIds)) . ')
            AND id_place IN (' . implode(',', $active) . ') ORDER BY id_place') as $r) {
            $out[(int) $r['id_raw_material']][] = (int) $r['id_place'];
        }
        return $out;
    }

    public function assigned(int $placeId, int $materialId): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_cold_place_material WHERE id_place = ? AND id_raw_material = ?', [$placeId, $materialId]);
    }

    public function assign(int $placeId, int $materialId, ?int $employeeId, ?int $userId): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_cold_place_material (id_place, id_raw_material, id_employee, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$placeId, $materialId, $employeeId, $userId]);
    }

    public function unassign(int $placeId, int $materialId): void
    {
        $this->db->prepare('DELETE FROM n_cold_place_material WHERE id_place = ? AND id_raw_material = ?')->execute([$placeId, $materialId]);
    }

    public function material(int $id): ?array
    {
        return $this->all('SELECT rm.id, rm.name, rm.name_fr, u.name AS unit FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit WHERE rm.id = ?', [$id])[0] ?? null;
    }

    /** Recherche d'un surowiec par nom (polonais ou français), les plus courts d'abord. */
    public function search(string $q, int $limit): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        return $this->all('SELECT rm.id, rm.name, rm.name_fr, u.name AS unit FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit
            WHERE rm.name LIKE ? OR rm.name_fr LIKE ? ORDER BY CHAR_LENGTH(rm.name), rm.name, rm.id LIMIT ' . max(1, min(50, $limit)), [$like, $like]);
    }

    /**
     * Partie bieżąca de chaque surowiec (rejestr des partii, actives) : la plus courte date d'abord (FEFO), puis la plus
     * récente. @return array<int, array{lot_number:?string, best_before:?string}>
     */
    public function currentLots(array $materialIds): array
    {
        if (!$materialIds || !$this->hasTable('n_material_lot')) return [];
        $out = [];
        foreach ($this->all('SELECT id_raw_material, lot_number, best_before FROM n_material_lot
            WHERE active = 1 AND id_raw_material IN (' . implode(',', array_map('intval', $materialIds)) . ')
            ORDER BY id_raw_material, best_before IS NULL, best_before, received_on DESC, id DESC') as $r) {
            $out[(int) $r['id_raw_material']] ??= ['lot_number' => $r['lot_number'], 'best_before' => $r['best_before']];
        }
        return $out;
    }

    // --- Obchody ---------------------------------------------------------------------------------------------------------

    private const VISIT = "SELECT v.*, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS employee_name
        FROM n_cold_visit v LEFT JOIN n_prod_employee e ON e.id = v.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee";

    /** Obchód en cours (pas complet) commencé depuis $since. */
    public function openVisit(string $since): ?array
    {
        return $this->all(self::VISIT . ' WHERE v.completed_at IS NULL AND v.started_at >= ? ORDER BY v.started_at DESC, v.id DESC LIMIT 1', [$since])[0] ?? null;
    }

    /** Dernier obchód complet. */
    public function lastComplete(): ?array
    {
        return $this->all(self::VISIT . ' WHERE v.completed_at IS NOT NULL ORDER BY v.completed_at DESC, v.id DESC LIMIT 1')[0] ?? null;
    }

    public function visit(int $id): ?array
    {
        return $this->all(self::VISIT . ' WHERE v.id = ?', [$id])[0] ?? null;
    }

    /** @return array<int, array> miejsce → zapis dans ce obchód */
    public function visitPlaces(int $visitId): array
    {
        return array_column($this->all("SELECT vp.*, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS employee_name
            FROM n_cold_visit_place vp LEFT JOIN n_prod_employee e ON e.id = vp.id_employee LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
            WHERE vp.id_visit = ? ORDER BY vp.saved_at, vp.id", [$visitId]), null, 'id_place');
    }

    /** Zapis d'une miejsce : nouveau, ou le même remplacé (ses lignes sont supprimées). @return int n_cold_visit_place.id */
    public function savePlace(int $visitId, int $placeId, array $row): int
    {
        $old = $this->all('SELECT id FROM n_cold_visit_place WHERE id_visit = ? AND id_place = ?', [$visitId, $placeId])[0]['id'] ?? null;
        $cols = ['place_name', 'saved_at', 'id_employee', 'id_device', 'clean', 'id_photo', 'note', 'materials', 'differences'];
        $values = array_map(fn($c) => $row[$c] ?? null, $cols);
        if ($old !== null) {
            $this->db->prepare('DELETE FROM n_cold_visit_line WHERE id_visit_place = ?')->execute([(int) $old]);
            $this->db->prepare('UPDATE n_cold_visit_place SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')->execute([...$values, (int) $old]);
            return (int) $old;
        }
        $this->db->prepare('INSERT INTO n_cold_visit_place (id_visit, id_place, ' . implode(', ', $cols) . ') VALUES (?, ?' . str_repeat(', ?', count($cols)) . ')')
            ->execute([$visitId, $placeId, ...$values]);
        return (int) $this->db->lastInsertId();
    }

    public function insertLine(array $row): int
    {
        $cols = ['id_visit_place', 'id_raw_material', 'erp_qty', 'counted_qty', 'date_state', 'loss_kind', 'loss_qty', 'loss_reason', 'lot_number', 'best_before'];
        $this->db->prepare('INSERT INTO n_cold_visit_line (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')
            ->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
        return (int) $this->db->lastInsertId();
    }

    /** Inventaire écrit pour ces lignes (toutes les miejsca du surowiec dans le obchód). */
    public function setLineCount(array $lineIds, float $qty, int $countId): void
    {
        if (!$lineIds) return;
        $this->db->prepare('UPDATE n_cold_visit_line SET count_qty = ?, id_mp_count = ? WHERE id IN (' . implode(',', array_map('intval', $lineIds)) . ')')->execute([$qty, $countId]);
    }

    /** Lignes d'un obchód (toutes miejsca), avec la miejsce et le surowiec. */
    public function visitLines(int $visitId): array
    {
        return $this->all('SELECT l.*, vp.id_place, vp.place_name, rm.name, rm.name_fr, u.name AS unit
            FROM n_cold_visit_line l JOIN n_cold_visit_place vp ON vp.id = l.id_visit_place
            JOIN raw_material rm ON rm.id = l.id_raw_material LEFT JOIN unit u ON u.id = rm.id_unit
            WHERE vp.id_visit = ? ORDER BY vp.saved_at, vp.id, rm.name, l.id', [$visitId]);
    }

    public function updateVisit(int $id, array $data): void
    {
        $this->update($id, $data);
    }
}
