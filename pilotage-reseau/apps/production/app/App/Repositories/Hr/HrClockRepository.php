<?php
declare(strict_types=1);

namespace App\Repositories\Hr;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [348] Grafik tygodnia (n_hr_schedule), SMS « brak wejścia » de la fiche (n_hr_employee.clock_sms / clock_sms_text)
 * et rappels envoyés (n_hr_clock_reminder, un par personne et par jour). Pointages (n_prod_clock) et absences
 * (n_time_absence) : lus seulement.
 */
final class HrClockRepository extends WorkshopRepository
{
    protected string $table = 'n_hr_schedule';

    /** @return array<int, array{start:string, end:string}> jour 1..7 => HH:MM */
    public function schedule(int $employeeId): array
    {
        $out = [];
        foreach ($this->all('SELECT weekday, start_time, end_time FROM n_hr_schedule WHERE id_employee = ? ORDER BY weekday', [$employeeId]) as $r) {
            $out[(int) $r['weekday']] = ['start' => substr((string) $r['start_time'], 0, 5), 'end' => substr((string) $r['end_time'], 0, 5)];
        }
        return $out;
    }

    /** Remplace tout le grafik de la personne (jours absents = wolne). @param array<int, array{start:string, end:string}> $days */
    public function saveSchedule(int $employeeId, array $days, ?int $userId): void
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM n_hr_schedule WHERE id_employee = ?')->execute([$employeeId]);
            $ins = $this->db->prepare('INSERT INTO n_hr_schedule (id_employee, weekday, start_time, end_time, updated_by) VALUES (?, ?, ?, ?, ?)');
            foreach ($days as $wd => $d) $ins->execute([$employeeId, (int) $wd, $d['start'] . ':00', $d['end'] . ':00', $userId]);
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{clock_sms:bool, clock_sms_text:?string, phone_enc:?string} */
    public function settings(int $employeeId): array
    {
        $r = $this->all('SELECT clock_sms, clock_sms_text, phone_enc FROM n_hr_employee WHERE id_employee = ?', [$employeeId])[0] ?? null;
        return ['clock_sms' => $r === null || (bool) $r['clock_sms'], 'clock_sms_text' => $r['clock_sms_text'] ?? null, 'phone_enc' => $r['phone_enc'] ?? null];
    }

    /** Fiche HR créée au besoin (INSERT … ON DUPLICATE KEY UPDATE, comme HrRepository::save). */
    public function saveSettings(int $employeeId, bool $sms, ?string $text, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_hr_employee (id_employee, clock_sms, clock_sms_text, updated_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE clock_sms = VALUES(clock_sms), clock_sms_text = VALUES(clock_sms_text), updated_by = VALUES(updated_by)')
            ->execute([$employeeId, (int) $sms, $text, $userId]);
    }

    public function lastReminder(int $employeeId): ?array
    {
        return $this->all('SELECT day, scheduled_start, sent_at, status, error FROM n_hr_clock_reminder WHERE id_employee = ? ORDER BY day DESC LIMIT 1', [$employeeId])[0] ?? null;
    }

    // --- envoi (cron)

    /**
     * Personnes actives avec un grafik ce jour de la semaine et sans rappel ce jour (fiche HR facultative : sans fiche,
     * pas de téléphone, donc pas de SMS).
     */
    public function due(int $weekday, string $day): array
    {
        return $this->all('SELECT s.id_employee, s.start_time, s.end_time, COALESCE(h.clock_sms, 1) AS clock_sms, h.clock_sms_text, h.phone_enc
                           FROM n_hr_schedule s JOIN n_prod_employee e ON e.id = s.id_employee AND e.active = 1
                           LEFT JOIN n_hr_employee h ON h.id_employee = s.id_employee
                           WHERE s.weekday = ? AND NOT EXISTS (SELECT 1 FROM n_hr_clock_reminder r WHERE r.id_employee = s.id_employee AND r.day = ?)
                           ORDER BY s.start_time, s.id_employee', [$weekday, $day]);
    }

    /**
     * Entrée pointée ce jour (depuis $dayStart), ou pointage encore ouvert commencé la veille (nuit) : la personne est là.
     */
    public function clockedIn(int $employeeId, string $dayStart): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_clock WHERE id_employee = ? AND (clock_in >= ? OR (clock_out IS NULL AND clock_in >= ? - INTERVAL 1 DAY)) LIMIT 1',
            [$employeeId, $dayStart, $dayStart]);
    }

    /** Absence du calendrier (urlop, L4, wolne za nadgodziny…) couvrant le jour. */
    public function absent(int $employeeId, string $day): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_time_absence WHERE id_employee = ? AND date_from <= ? AND date_to >= ? LIMIT 1', [$employeeId, $day, $day]);
    }

    /** Verrou : la ligne du jour d'abord (INSERT IGNORE sur l'unique) — false = déjà prise (autre passage du cron). */
    public function claim(int $employeeId, string $day, string $start, string $at): bool
    {
        $st = $this->db->prepare("INSERT IGNORE INTO n_hr_clock_reminder (id_employee, day, scheduled_start, sent_at, status) VALUES (?, ?, ?, ?, 'pending')");
        $st->execute([$employeeId, $day, $start, $at]);
        return $st->rowCount() === 1;
    }

    public function finish(int $employeeId, string $day, string $status, ?string $error): void
    {
        $this->db->prepare('UPDATE n_hr_clock_reminder SET status = ?, error = ? WHERE id_employee = ? AND day = ?')
            ->execute([$status, $error === null ? null : mb_substr($error, 0, 255), $employeeId, $day]);
    }

    /** [348] Czas pracy › Dzień : rappels du jour (sauf échec). @return array<int, array{at:string, status:string}> */
    public function remindersOn(string $day): array
    {
        $out = [];
        foreach ($this->all("SELECT id_employee, sent_at, status FROM n_hr_clock_reminder WHERE day = ? AND status IN ('sent', 'logged')", [$day]) as $r) {
            $out[(int) $r['id_employee']] = ['at' => substr((string) $r['sent_at'], 11, 5), 'status' => $r['status']];
        }
        return $out;
    }
}
