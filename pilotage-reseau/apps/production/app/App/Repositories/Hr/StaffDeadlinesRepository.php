<?php
declare(strict_types=1);

namespace App\Repositories\Hr;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Badania i szkolenia (przegląd terminów) » [404] : lecture
 *  - des salariés actifs (n_prod_employee.active = 1 → production_employee : nom ; groupe ; poste) avec, de leur fiche HR
 *    (n_hr_employee), la seule date medical_until — en clair, celle que le tableau HR montre déjà au Kierownik produkcji ;
 *    aucune colonne chiffrée (HrCipher) n'est lue ;
 *  - de la date du dernier document HR « Badania lekarskie » (n_hr_document.kind = 'badania' : doc_date, sinon date d'import ;
 *    jamais le contenu ni le titre) ;
 *  - du registre des szkolenia de l'assistant (n_staff_training).
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »). N'écrit que n_staff_training,
 * n_staff_deadline_review (et, via HrRepository, la trace de lecture n_hr_log, comme le tableau HR).
 */
final class StaffDeadlinesRepository extends WorkshopRepository
{
    protected string $table = 'n_staff_deadline_review';

    /** @var array<string, bool> */
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Salariés actifs : nom, groupe, poste, badania ważne do, dernier document « badania » (null : tables absentes). */
    public function employees(): ?array
    {
        if (!$this->hasTable('n_prod_employee') || !$this->hasTable('production_employee')) return null;
        $hr = $this->hasTable('n_hr_employee');
        $doc = $this->hasTable('n_hr_document');
        $grp = $this->hasTable('n_prod_group');
        $pos = $this->hasTable('n_position');
        return $this->all("SELECT e.id, TRIM(CONCAT(COALESCE(pe.name, ''), ' ', COALESCE(pe.surname, ''))) AS name,
                " . ($grp ? 'g.name_pl' : 'NULL') . " AS group_name, " . ($pos ? 'p.name_pl' : 'NULL') . " AS position,
                " . ($hr ? 'h.medical_until' : 'NULL') . " AS medical_until,
                " . ($doc ? "(SELECT MAX(COALESCE(d.doc_date, DATE(d.created_at))) FROM n_hr_document d WHERE d.id_employee = e.id AND d.kind = 'badania')" : 'NULL') . " AS medical_doc
            FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
            " . ($grp ? 'LEFT JOIN n_prod_group g ON g.id = e.id_primary_group' : '') . "
            " . ($pos ? 'LEFT JOIN n_position p ON p.id = e.id_position' : '') . "
            " . ($hr ? 'LEFT JOIN n_hr_employee h ON h.id_employee = e.id' : '') . "
            WHERE e.active = 1
            ORDER BY pe.surname, pe.name, e.id");
    }

    /** Szkolenia actives du registre (les plus récentes d'abord). */
    public function trainings(): array
    {
        return $this->all('SELECT t.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS by_name FROM n_staff_training t LEFT JOIN user u ON u.id = t.created_by
            WHERE t.active = 1 ORDER BY t.id_employee, t.kind, t.valid_until IS NULL DESC, t.valid_until DESC, t.done_on DESC, t.id DESC');
    }

    public function training(int $id): ?array
    {
        return $this->all('SELECT * FROM n_staff_training WHERE id = ? AND active = 1', [$id])[0] ?? null;
    }

    public function addTraining(int $employeeId, string $kind, ?string $title, ?string $doneOn, ?string $validUntil, ?string $note, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_staff_training (id_employee, kind, title, done_on, valid_until, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$employeeId, $kind, $title, $doneOn, $validUntil, $note, $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    /** Wpis błędny : désactivé (jamais supprimé). */
    public function removeTraining(int $id, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_staff_training SET active = 0, updated_by = ? WHERE id = ?')->execute([$userId, $id]);
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_staff_deadline_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<string> éléments décidés pendant le mois (restent à l'écran même sans signal). */
    public function decidedIn(string $monthStart): array
    {
        return array_column($this->all('SELECT item_key FROM n_staff_deadline_review WHERE month_start = ?', [$monthStart]), 'item_key');
    }

    /** Une décision par (mois, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, string $kind, int $employeeId, string $decision, ?string $bookedOn, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_staff_deadline_review (month_start, item_key, kind, id_employee, decision, booked_on, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), booked_on = VALUES(booked_on), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $kind, $employeeId, $decision, $bookedOn, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
