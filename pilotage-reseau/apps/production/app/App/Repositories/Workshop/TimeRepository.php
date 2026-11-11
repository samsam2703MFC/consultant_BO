<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * [262] Czas pracy : personnes actives, pointages (n_prod_clock, lus seulement), jours validés, motifs, calendrier des
 * absences, jours de récupération des heures supplémentaires, droit au congé, mois clôturés.
 */
final class TimeRepository extends WorkshopRepository
{
    protected string $table = 'n_time_absence';

    /** Personnes actives (production), groupe principal. */
    public function staff(): array
    {
        return $this->all(
            "SELECT e.id, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, pe.surname, e.id_primary_group AS group_id, g.name_pl AS group_name, g.color AS group_color
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN n_prod_group g ON g.id = e.id_primary_group
             WHERE e.active = 1 ORDER BY g.sort_order IS NULL, g.sort_order, pe.surname, pe.name"
        );
    }

    public function employee(int $id): ?array
    {
        return $this->all(
            "SELECT e.id, e.active, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, g.name_pl AS group_name
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN n_prod_group g ON g.id = e.id_primary_group WHERE e.id = ?",
            [$id]
        )[0] ?? null;
    }

    /** Pointages commencés dans [from, to[ (jour du pointage = jour de l'entrée). */
    public function clocks(string $from, string $to, ?int $employeeId = null): array
    {
        $params = [$from . ' 00:00:00', $to . ' 00:00:00'];
        $where = '';
        if ($employeeId !== null) { $where = ' AND k.id_employee = ?'; $params[] = $employeeId; }
        return $this->all(
            "SELECT k.id, k.id_employee, k.clock_in, k.clock_out, k.source, k.note, k.corrected_at,
                    (SELECT GROUP_CONCAT(COALESCE(a.note, '') SEPARATOR ' · ') FROM n_clock_answer a JOIN n_clock_question q ON q.id = a.id_question
                     WHERE a.id_clock = k.id AND q.kind = 'yes_no' AND a.answer = 1) AS took
             FROM n_prod_clock k WHERE k.clock_in >= ? AND k.clock_in < ?{$where} ORDER BY k.clock_in",
            $params
        );
    }

    /**
     * [269] Towar zabrany przy wyjściu tego dnia : odbicia z odpowiedzią « Tak » (yes_no) i ich pozycje (n_clock_goods).
     * @return list<array{id_clock:int, clock_out:?string, source:?string, note:?string, product:?string, quantity:?float, from_waste:?int, id_final_product:?int}>
     */
    public function goods(int $employeeId, string $day): array
    {
        return $this->all(
            "SELECT k.id AS id_clock, k.clock_out, k.source, a.note, g.product, g.quantity, g.from_waste, g.id_final_product
             FROM n_prod_clock k
             JOIN n_clock_answer a ON a.id_clock = k.id AND a.answer = 1
             JOIN n_clock_question q ON q.id = a.id_question AND q.kind = 'yes_no'
             LEFT JOIN n_clock_goods g ON g.id_clock = k.id AND g.id_question = a.id_question
             WHERE k.id_employee = ? AND k.clock_in >= ? AND k.clock_in < ? + INTERVAL 1 DAY
             ORDER BY k.clock_in, g.id",
            [$employeeId, $day . ' 00:00:00', $day]
        );
    }

    public function clock(int $id): ?array
    {
        return $this->all('SELECT id, id_employee, clock_in, clock_out, source, note FROM n_prod_clock WHERE id = ?', [$id])[0] ?? null;
    }

    // --- Jours validés et mois clôturés ----------------------------------------------------------------

    /** @return array<string, array> jour => validation */
    public function validatedDays(string $from, string $to): array
    {
        $rows = $this->all(
            "SELECT d.day, d.validated_by, d.validated_at, d.summary, u.displayed_name AS by_name
             FROM n_time_day d LEFT JOIN user u ON u.id = d.validated_by WHERE d.day >= ? AND d.day < ? ORDER BY d.day",
            [$from, $to]
        );
        return array_column($rows, null, 'day');
    }

    public function validateDay(string $day, ?int $userId, string $summary): void
    {
        $this->db->prepare('REPLACE INTO n_time_day (day, validated_by, validated_at, summary) VALUES (?, ?, NOW(), ?)')->execute([$day, $userId, $summary]);
    }

    /** Jours à revalider après une modification (pointage, calendrier) : [from, to] inclus. @return int jours rouverts */
    public function reopenDays(string $from, string $to): int
    {
        $stmt = $this->db->prepare('DELETE FROM n_time_day WHERE day >= ? AND day <= ?');
        $stmt->execute([$from, $to]);
        return $stmt->rowCount();
    }

    /** Licznik w menu : dni z odbiciami przed dzisiaj (od $from), jeszcze niezatwierdzone. */
    public function daysToValidate(string $from, string $today): int
    {
        return (int) ($this->all(
            'SELECT COUNT(DISTINCT DATE(k.clock_in)) AS n FROM n_prod_clock k LEFT JOIN n_time_day d ON d.day = DATE(k.clock_in)
             WHERE k.clock_in >= ? AND k.clock_in < ? AND d.day IS NULL',
            [$from . ' 00:00:00', $today . ' 00:00:00']
        )[0]['n'] ?? 0);
    }

    public function closedMonth(string $month): ?array
    {
        return $this->all(
            'SELECT m.month, m.closed_by, m.closed_at, u.displayed_name AS by_name FROM n_time_month m LEFT JOIN user u ON u.id = m.closed_by WHERE m.month = ?',
            [$month]
        )[0] ?? null;
    }

    public function closeMonth(string $month, ?int $userId): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_time_month (month, closed_by) VALUES (?, ?)')->execute([$month, $userId]);
    }

    public function reopenMonth(string $month): void
    {
        $this->db->prepare('DELETE FROM n_time_month WHERE month = ?')->execute([$month]);
    }

    // --- Motifs -----------------------------------------------------------------------------------------

    public function notes(string $from, string $to): array
    {
        return $this->all('SELECT id_employee, day, kind, reason, note FROM n_time_note WHERE day >= ? AND day < ?', [$from, $to]);
    }

    public function setNote(int $employeeId, string $day, string $kind, string $reason, ?string $note, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_time_note (id_employee, day, kind, reason, note, set_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), note = VALUES(note), set_by = VALUES(set_by)'
        )->execute([$employeeId, $day, $kind, $reason, $note, $userId]);
    }

    public function deleteNote(int $employeeId, string $day, string $kind): void
    {
        $this->db->prepare('DELETE FROM n_time_note WHERE id_employee = ? AND day = ? AND kind = ?')->execute([$employeeId, $day, $kind]);
    }

    // --- Calendrier des absences ------------------------------------------------------------------------

    /** Absences chevauchant [from, to] (bornes incluses). */
    public function absences(string $from, string $to, ?int $employeeId = null): array
    {
        $params = [$to, $from];
        $where = '';
        if ($employeeId !== null) { $where = ' AND a.id_employee = ?'; $params[] = $employeeId; }
        return $this->all(
            "SELECT a.id, a.id_employee, a.date_from, a.date_to, a.type, a.minutes, a.id_comp, a.note, a.created_at
             FROM n_time_absence a WHERE a.date_from <= ? AND a.date_to >= ?{$where} ORDER BY a.date_from, a.id",
            $params
        );
    }

    public function absence(int $id): ?array
    {
        return $this->all('SELECT * FROM n_time_absence WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertAbsence(array $a): int
    {
        $this->db->prepare('INSERT INTO n_time_absence (id_employee, date_from, date_to, type, minutes, id_comp, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$a['id_employee'], $a['date_from'], $a['date_to'], $a['type'], $a['minutes'] ?? null, $a['id_comp'] ?? null, $a['note'] ?? null, $a['created_by'] ?? null]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteAbsence(int $id): void
    {
        $this->db->prepare('DELETE FROM n_time_absence WHERE id = ?')->execute([$id]);
    }

    public function deleteAbsenceOfComp(int $compId): void
    {
        $this->db->prepare('DELETE FROM n_time_absence WHERE id_comp = ?')->execute([$compId]);
    }

    // --- Droit au congé ---------------------------------------------------------------------------------

    /** @return array<int, int> personne => jours par an */
    public function leaveRights(): array
    {
        $rows = $this->all('SELECT id_employee, days_per_year FROM n_time_leave');
        return array_map('intval', array_column($rows, 'days_per_year', 'id_employee'));
    }

    public function setLeaveRight(int $employeeId, int $days, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_time_leave (id_employee, days_per_year, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE days_per_year = VALUES(days_per_year), updated_by = VALUES(updated_by)'
        )->execute([$employeeId, $days, $userId]);
    }

    // --- [266] Adresses e-mail (chiffrées) et karty envoyées -----------------------------------------------

    /** @return array<int, string> salarié => adresse chiffrée */
    public function contacts(): array
    {
        return array_column($this->all('SELECT id_employee, email_enc FROM n_time_contact'), 'email_enc', 'id_employee');
    }

    public function setContact(int $employeeId, string $emailEnc, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_time_contact (id_employee, email_enc, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE email_enc = VALUES(email_enc), updated_by = VALUES(updated_by)'
        )->execute([$employeeId, $emailEnc, $userId]);
    }

    public function deleteContact(int $employeeId): void
    {
        $this->db->prepare('DELETE FROM n_time_contact WHERE id_employee = ?')->execute([$employeeId]);
    }

    public function insertSheetMail(int $employeeId, string $month, int $messageId, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_time_sheet_mail (id_employee, month, id_message, sent_by) VALUES (?, ?, ?, ?)')->execute([$employeeId, $month, $messageId, $userId]);
    }

    /** Dernier envoi de la karta du mois par salarié, avec le statut du message dans la file. @return array<int, array> */
    public function sheetMails(string $month): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT m.id_employee, m.created_at, cm.status, cm.sent_at FROM n_time_sheet_mail m
             LEFT JOIN communication_message cm ON cm.id = m.id_message WHERE m.month = ? ORDER BY m.id',
            [$month]
        ) as $r) $out[(int) $r['id_employee']] = $r;
        return $out;
    }

    // --- Jours de récupération --------------------------------------------------------------------------

    /** Jours de récupération non annulés (une personne ou toutes). */
    public function comps(?int $employeeId = null, ?string $status = null): array
    {
        $params = [];
        $where = "c.status <> 'cancelled'";
        if ($employeeId !== null) { $where .= ' AND c.id_employee = ?'; $params[] = $employeeId; }
        if ($status !== null) { $where .= ' AND c.status = ?'; $params[] = $status; }
        return $this->all(
            "SELECT c.id, c.id_employee, c.day, c.minutes, c.status, c.id_signature, c.proposed_at, c.signed_at, u.displayed_name AS proposed_by_name
             FROM n_time_comp c LEFT JOIN user u ON u.id = c.proposed_by WHERE {$where} ORDER BY c.day, c.id",
            $params
        );
    }

    public function comp(int $id): ?array
    {
        return $this->all('SELECT * FROM n_time_comp WHERE id = ?', [$id])[0] ?? null;
    }

    public function insertComp(int $employeeId, string $day, int $minutes, ?int $userId): int
    {
        $this->db->prepare("INSERT INTO n_time_comp (id_employee, day, minutes, status, proposed_by) VALUES (?, ?, ?, 'proposed', ?)")
            ->execute([$employeeId, $day, $minutes, $userId]);
        return (int) $this->db->lastInsertId();
    }

    /** Signature du jour : seulement depuis « proposé » (une seule fois). */
    public function markCompSigned(int $id, int $signatureId): bool
    {
        $stmt = $this->db->prepare("UPDATE n_time_comp SET status = 'signed', id_signature = ?, signed_at = NOW() WHERE id = ? AND status = 'proposed'");
        $stmt->execute([$signatureId, $id]);
        return $stmt->rowCount() === 1;
    }

    public function cancelComp(int $id): void
    {
        $this->db->prepare("UPDATE n_time_comp SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    }
}
