<?php
declare(strict_types=1);

namespace App\Repositories\Hr;

use App\Repositories\Workshop\WorkshopRepository;

/** [271] HR : fiche (n_hr_employee), documents (n_hr_document), notes (n_hr_note), journal (n_hr_log), bilans du mois. */
final class HrRepository extends WorkshopRepository
{
    protected string $table = 'n_hr_employee';

    /** Visibilité d'une note pour un non-administrateur (3 paramètres : viewer × 3). */
    private const VISIBLE = "(n.visibility = 'public' AND (n.audience IS NULL OR FIND_IN_SET((SELECT p.code FROM n_user_position up JOIN n_position p ON p.id = up.id_position WHERE up.id_user = ? LIMIT 1), n.audience)))
                     OR n.author = ? OR n.followup_user = ?)";

    public const FIELDS = ['phone_enc', 'birth_enc', 'gender', 'hire_date', 'contract_type', 'contract_end', 'work_time', 'clothing_size', 'medical_until', 'emergency_enc',
        // [277] paie et contrats (accès « payroll »)
        'pay_type', 'pay_amount', 'bank_enc', 'address_enc', 'pesel_enc', 'pit2', 'kup_raised', 'young_relief', 'student', 'sick_insurance'];

    /** Employés (actifs d'abord), groupe, poste de l'atelier, fiche HR, nombre de documents. */
    public function people(): array
    {
        return $this->all(
            "SELECT e.id, e.active, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, pe.surname, g.name_pl AS group_name, g.color AS group_color,
                    p.name_pl AS position, h.phone_enc, h.birth_enc, h.gender, h.hire_date, h.contract_type, h.contract_end, h.work_time, h.clothing_size,
                    h.medical_until, h.emergency_enc, h.pay_type, h.pay_amount, h.bank_enc, h.address_enc, h.pesel_enc,
                    h.pit2, h.kup_raised, h.young_relief, h.student, h.sick_insurance, (SELECT COUNT(*) FROM n_hr_document d WHERE d.id_employee = e.id) AS documents
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN n_prod_group g ON g.id = e.id_primary_group
             LEFT JOIN n_position p ON p.id = e.id_position
             LEFT JOIN n_hr_employee h ON h.id_employee = e.id
             ORDER BY e.active DESC, g.sort_order IS NULL, g.sort_order, pe.surname, pe.name"
        );
    }

    public function exists(int $employeeId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_employee WHERE id = ?', [$employeeId]);
    }

    public function save(int $employeeId, array $data, ?int $userId): void
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $cols = array_keys($data);
        $sql = 'INSERT INTO n_hr_employee (id_employee, ' . implode(', ', $cols) . ', updated_by) VALUES (?' . str_repeat(', ?', count($cols)) . ', ?)'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)) . ', updated_by = VALUES(updated_by)';
        $this->db->prepare($sql)->execute(array_merge([$employeeId], array_values($data), [$userId]));
    }

    // --- documents

    public function documents(int $employeeId): array
    {
        return $this->all('SELECT d.id, d.kind, d.title, d.filename, d.mime, d.size, d.doc_date, d.uploaded_by, d.created_at, u.displayed_name AS by_name
                           FROM n_hr_document d LEFT JOIN user u ON u.id = d.uploaded_by WHERE d.id_employee = ? ORDER BY COALESCE(d.doc_date, DATE(d.created_at)) DESC, d.id DESC', [$employeeId]);
    }

    public function document(int $id): ?array
    {
        return $this->all('SELECT * FROM n_hr_document WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertDocument(array $d): int
    {
        $this->db->prepare('INSERT INTO n_hr_document (id_employee, kind, title, filename, mime, size, doc_date, content, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['id_employee'], $d['kind'], $d['title'], $d['filename'], $d['mime'], $d['size'], $d['doc_date'], $d['content'], $d['uploaded_by']]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteDocument(int $id): void
    {
        $this->db->prepare('DELETE FROM n_hr_document WHERE id = ?')->execute([$id]);
    }

    /** Documents par type (onglet Dokumenty). */
    public function allDocuments(): array
    {
        return $this->all("SELECT d.id, d.id_employee, d.kind, d.title, d.filename, d.size, d.doc_date, d.created_at, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
                           FROM n_hr_document d JOIN n_prod_employee e ON e.id = d.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
                           ORDER BY d.created_at DESC LIMIT 500");
    }

    // --- notes

    /**
     * Notes d'une personne ou d'une période : l'administrateur voit tout ; les autres, leurs notes, celles dont ils suivent
     * le follow-up et les publiques — [279] limitées aux postes « dla kogo » quand la note en désigne.
     */
    public function notes(?int $employeeId, ?string $from, ?string $to, bool $all, ?int $viewer): array
    {
        $where = [];
        $params = [];
        if ($employeeId !== null) { $where[] = 'n.id_employee = ?'; $params[] = $employeeId; }
        if ($from !== null) { $where[] = 'n.created_at >= ?'; $params[] = $from; }
        if ($to !== null) { $where[] = 'n.created_at < ?'; $params[] = $to; }
        if (!$all) { $where[] = "(" . self::VISIBLE; array_push($params, (int) $viewer, (int) $viewer, (int) $viewer); }
        return $this->all("SELECT n.id, n.id_employee, n.body_enc, n.visibility, n.to_admin, n.author, n.created_at, u.displayed_name AS by_name,
                                  n.kind, n.topic_enc, n.audience, n.channels, n.followup_due, n.followup_user, n.followup_done_at, n.followup_done_note,
                                  fu.displayed_name AS followup_name, fd.displayed_name AS followup_done_name,
                                  TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, g.name_pl AS group_name
                           FROM n_hr_note n LEFT JOIN user u ON u.id = n.author LEFT JOIN user fu ON fu.id = n.followup_user LEFT JOIN user fd ON fd.id = n.followup_done_by
                           JOIN n_prod_employee e ON e.id = n.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
                           LEFT JOIN n_prod_group g ON g.id = e.id_primary_group"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY n.created_at DESC, n.id DESC', $params);
    }

    public function note(int $id): ?array
    {
        return $this->all('SELECT * FROM n_hr_note WHERE id = ?', [$id])[0] ?? null;
    }

    /** [279] Szybka notatka : rodzaj, temat (chiffré), dla kogo, alertes, follow-up. */
    public function insertQuickNote(array $d): int
    {
        $this->db->prepare('INSERT INTO n_hr_note (id_employee, kind, topic_enc, body_enc, visibility, to_admin, audience, channels, followup_due, followup_user, author)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['id_employee'], $d['kind'], $d['topic_enc'], $d['body_enc'], $d['visibility'], (int) $d['to_admin'], $d['audience'], $d['channels'],
                $d['followup_due'], $d['followup_user'], $d['author']]);
        return (int) $this->db->lastInsertId();
    }

    public function followupDone(int $id, ?int $userId, ?string $note): void
    {
        $this->db->prepare('UPDATE n_hr_note SET followup_done_at = NOW(), followup_done_by = ?, followup_done_note = ? WHERE id = ? AND followup_done_at IS NULL')
            ->execute([$userId, $note, $id]);
    }

    /** Follow-ups ouverts : tous (administrateur) ou ceux qui concernent la personne (responsable ou auteur). */
    public function openFollowups(?int $viewer, bool $all): array
    {
        return $this->all("SELECT n.id, n.id_employee, n.kind, n.topic_enc, n.followup_due, n.followup_user, n.author, fu.displayed_name AS followup_name,
                                  TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
                           FROM n_hr_note n LEFT JOIN user fu ON fu.id = n.followup_user
                           JOIN n_prod_employee e ON e.id = n.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE n.followup_due IS NOT NULL AND n.followup_done_at IS NULL" . ($all ? '' : ' AND (n.followup_user = ? OR n.author = ?)') . '
                           ORDER BY n.followup_due, n.id', $all ? [] : [(int) $viewer, (int) $viewer]);
    }

    /** Follow-ups ouverts d'une personne avec « zadanie » (Mój tydzień). */
    public function taskFollowups(int $userId): array
    {
        return $this->all("SELECT n.id, n.id_employee, n.kind, n.topic_enc, n.followup_due, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
                           FROM n_hr_note n JOIN n_prod_employee e ON e.id = n.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE n.followup_user = ? AND n.followup_done_at IS NULL AND n.followup_due IS NOT NULL AND FIND_IN_SET('task', n.channels)
                           ORDER BY n.followup_due", [$userId]);
    }

    /** Follow-ups ouverts dont le terme est atteint (rappel quotidien). */
    public function dueFollowups(string $date): array
    {
        return $this->all("SELECT n.id, n.id_employee, n.kind, n.topic_enc, n.followup_due, n.followup_user, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
                           FROM n_hr_note n JOIN n_prod_employee e ON e.id = n.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE n.followup_user IS NOT NULL AND n.followup_done_at IS NULL AND n.followup_due <= ?", [$date]);
    }

    /**
     * Destinataires « dla kogo » : utilisateurs des postes cités, administrateurs pour « admin ».
     * @param list<string> $codes @return array<int, array{id:int, name:string, email:?string, phone:?string}>
     */
    public function recipients(array $codes): array
    {
        $out = [];
        $pos = array_values(array_filter($codes, fn($c) => $c !== 'admin'));
        if ($pos) {
            foreach ($this->all('SELECT u.id, u.displayed_name AS name, u.email, u.phone FROM user u JOIN n_user_position up ON up.id_user = u.id JOIN n_position p ON p.id = up.id_position
                                 WHERE p.code IN (' . implode(',', array_fill(0, count($pos), '?')) . ')', $pos) as $r) $out[(int) $r['id']] = $r;
        }
        if (in_array('admin', $codes, true)) {
            foreach ($this->admins() as $a) $out[$a['id']] ??= $this->all('SELECT id, displayed_name AS name, email, phone FROM user WHERE id = ?', [$a['id']])[0] ?? null;
        }
        return array_filter($out);
    }

    /** Personnes à qui confier un follow-up : administrateurs et postes de bureau. */
    public function followupUsers(array $codes): array
    {
        $ids = array_column($this->admins(), 'id');
        $rows = $this->all('SELECT u.id, u.displayed_name AS name, p.name_pl AS position FROM user u LEFT JOIN n_user_position up ON up.id_user = u.id LEFT JOIN n_position p ON p.id = up.id_position
                            WHERE p.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')' . ($ids ? ' OR u.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '') . '
                            ORDER BY u.displayed_name', $codes);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'position' => $r['position'] ?: (in_array((int) $r['id'], $ids, true) ? 'Administrator' : null)], $rows);
    }

    public function insertNote(int $employeeId, string $bodyEnc, string $visibility, bool $toAdmin, ?int $author): int
    {
        $this->db->prepare('INSERT INTO n_hr_note (id_employee, body_enc, visibility, to_admin, author) VALUES (?, ?, ?, ?, ?)')
            ->execute([$employeeId, $bodyEnc, $visibility, (int) $toAdmin, $author]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteNote(int $id): void
    {
        $this->db->prepare('DELETE FROM n_hr_note WHERE id = ?')->execute([$id]);
    }

    /** Nombre de notes du mois visibles par personne. @return array<int, int> */
    public function noteCounts(string $from, string $to, bool $all, ?int $viewer): array
    {
        $sql = 'SELECT n.id_employee, COUNT(*) AS n FROM n_hr_note n WHERE n.created_at >= ? AND n.created_at < ?' . ($all ? '' : ' AND (' . self::VISIBLE) . ' GROUP BY n.id_employee';
        return array_map('intval', array_column($this->all($sql, $all ? [$from, $to] : [$from, $to, (int) $viewer, (int) $viewer, (int) $viewer]), 'n', 'id_employee'));
    }

    // --- [276] modèles de contrats

    public function templates(): array
    {
        return $this->all('SELECT t.code, t.title, t.legal, t.kind, t.body, t.sort, t.updated_at, u.displayed_name AS by_name FROM n_hr_template t LEFT JOIN user u ON u.id = t.updated_by ORDER BY t.sort, t.title');
    }

    public function template(string $code): ?array
    {
        return $this->all('SELECT * FROM n_hr_template WHERE code = ?', [$code])[0] ?? null;
    }

    public function saveTemplate(string $code, string $title, string $body, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_hr_template SET title = ?, body = ?, updated_by = ? WHERE code = ?')->execute([$title, $body, $userId, $code]);
    }

    public function company(): array
    {
        return $this->all('SELECT wz_company_name AS name, wz_company_address_1 AS address_1, wz_company_address_2 AS address_2, wz_company_nip AS nip FROM erp_company_data WHERE id = 1')[0] ?? [];
    }

    // --- journal, bilans, administrateurs

    public function log(?int $userId, ?int $employeeId, string $action): void
    {
        $this->db->prepare('INSERT INTO n_hr_log (id_user, id_employee, action) VALUES (?, ?, ?)')->execute([$userId, $employeeId, $action]);
    }

    public function summary(string $month): ?array
    {
        return $this->all('SELECT * FROM n_hr_summary WHERE month = ?', [$month])[0] ?? null;
    }

    public function saveSummary(string $month, int $notes, int $recipients, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_hr_summary (month, notes, recipients, sent_by, sent_at) VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE notes = VALUES(notes), recipients = VALUES(recipients), sent_by = VALUES(sent_by), sent_at = NOW()')
            ->execute([$month, $notes, $recipients, $userId]);
    }

    /** Administrateurs de la production (rôle prod_admin ; à défaut le compte « admin »). @return list<array{id:int, email:?string}> */
    public function admins(): array
    {
        $rows = $this->all("SELECT DISTINCT u.id, u.email FROM n_prod_user_role r JOIN user u ON u.id = r.id_user WHERE r.role_code = 'prod_admin' AND r.id_group IS NULL");
        if (!$rows) $rows = $this->all("SELECT id, email FROM user WHERE login = 'admin'");
        return array_map(fn($r) => ['id' => (int) $r['id'], 'email' => $r['email'] ?: null], $rows);
    }
}
