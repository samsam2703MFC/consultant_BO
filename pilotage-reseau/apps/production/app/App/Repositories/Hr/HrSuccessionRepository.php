<?php
declare(strict_types=1);

namespace App\Repositories\Hr;

use App\Repositories\Workshop\WorkshopRepository;

/** [278] HR › Sukcesja : plans (n_hr_succession), étapes, cases à cocher, agenda du passage progressif. */
final class HrSuccessionRepository extends WorkshopRepository
{
    protected string $table = 'n_hr_succession';

    /** Plans (actifs d'abord) avec le nom du responsable et du successeur. */
    public function plans(): array
    {
        return $this->all(
            "SELECT s.*, u.displayed_name AS responsible_name, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS successor_name
             FROM n_hr_succession s
             LEFT JOIN user u ON u.id = s.responsible_user
             LEFT JOIN n_prod_employee e ON e.id = s.successor_employee
             LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
             ORDER BY s.status = 'active' DESC, s.departure_date, s.id"
        );
    }

    public function plan(int $id): ?array
    {
        return $this->all('SELECT * FROM n_hr_succession WHERE id = ?', [$id])[0] ?? null;
    }

    /** Plan existant (quel que soit son statut) d'une personne pour ce motif — un plan auto annulé n'est pas recréé. */
    public function planFor(int $employeeId, string $reason): ?array
    {
        return $this->all('SELECT * FROM n_hr_succession WHERE id_employee = ? AND reason = ? ORDER BY id DESC LIMIT 1', [$employeeId, $reason])[0] ?? null;
    }

    public function steps(array $planIds): array
    {
        if (!$planIds) return [];
        return $this->all('SELECT * FROM n_hr_succession_step WHERE id_plan IN (' . implode(',', array_map('intval', $planIds)) . ') ORDER BY id_plan, step_no');
    }

    public function items(array $stepIds): array
    {
        if (!$stepIds) return [];
        return $this->all('SELECT i.*, u.displayed_name AS done_name FROM n_hr_succession_item i LEFT JOIN user u ON u.id = i.done_by
                           WHERE i.id_step IN (' . implode(',', array_map('intval', $stepIds)) . ') ORDER BY i.id_step, i.sort, i.id');
    }

    public function agenda(array $planIds): array
    {
        if (!$planIds) return [];
        return $this->all('SELECT * FROM n_hr_succession_agenda WHERE id_plan IN (' . implode(',', array_map('intval', $planIds)) . ') ORDER BY id_plan, date_from');
    }

    public function item(int $id): ?array
    {
        return $this->all('SELECT i.*, s.id_plan, s.step_no FROM n_hr_succession_item i JOIN n_hr_succession_step s ON s.id = i.id_step WHERE i.id = ?', [$id])[0] ?? null;
    }

    /**
     * Crée un plan complet (étapes, cases, agenda) dans une transaction.
     * @param list<array{no:int, title:string, start:string, due:string, items:list<array{label:string, code:?string}>}> $steps
     * @param list<array{from:string, to:string, pct:int, note:string}> $agenda
     */
    public function create(array $plan, array $steps, array $agenda): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO n_hr_succession (id_employee, reason, departure_date, responsible_user, auto, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$plan['employee'], $plan['reason'], $plan['departure'], $plan['responsible'], (int) $plan['auto'], $plan['by']]);
            $id = (int) $this->db->lastInsertId();
            $st = $this->db->prepare('INSERT INTO n_hr_succession_step (id_plan, step_no, title, start_date, due_date) VALUES (?, ?, ?, ?, ?)');
            $it = $this->db->prepare('INSERT INTO n_hr_succession_item (id_step, label, code, sort) VALUES (?, ?, ?, ?)');
            foreach ($steps as $s) {
                $st->execute([$id, $s['no'], $s['title'], $s['start'], $s['due']]);
                $sid = (int) $this->db->lastInsertId();
                foreach ($s['items'] as $k => $i) $it->execute([$sid, $i['label'], $i['code'], $k]);
            }
            $this->insertAgenda($id, $agenda);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Nouveau départ : dates des étapes et agenda recalculés (les cases cochées restent). */
    public function reschedule(int $id, string $departure, array $steps, array $agenda): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE n_hr_succession SET departure_date = ? WHERE id = ?')->execute([$departure, $id]);
            $st = $this->db->prepare('UPDATE n_hr_succession_step SET start_date = ?, due_date = ? WHERE id_plan = ? AND step_no = ?');
            foreach ($steps as $s) $st->execute([$s['start'], $s['due'], $id, $s['no']]);
            $this->db->prepare('DELETE FROM n_hr_succession_agenda WHERE id_plan = ?')->execute([$id]);
            $this->insertAgenda($id, $agenda);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertAgenda(int $id, array $agenda): void
    {
        $ag = $this->db->prepare('INSERT INTO n_hr_succession_agenda (id_plan, date_from, date_to, own_pct, note) VALUES (?, ?, ?, ?, ?)');
        foreach ($agenda as $a) $ag->execute([$id, $a['from'], $a['to'], $a['pct'], $a['note']]);
    }

    public function setPlan(int $id, array $fields): void
    {
        $allowed = ['successor_employee', 'successor_note', 'responsible_user', 'status', 'closed_at'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if (!$fields) return;
        $this->db->prepare('UPDATE n_hr_succession SET ' . implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($fields))) . ' WHERE id = ?')
            ->execute([...array_values($fields), $id]);
    }

    public function setItem(int $id, bool $done, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_hr_succession_item SET done_at = ' . ($done ? 'NOW()' : 'NULL') . ', done_by = ? WHERE id = ?')->execute([$done ? $userId : null, $id]);
    }

    /** Case « successeur choisi » (étape 2) cochée ou décochée avec le choix du successeur. */
    public function setCodeItem(int $planId, string $code, bool $done, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_hr_succession_item i JOIN n_hr_succession_step s ON s.id = i.id_step SET i.done_at = ' . ($done ? 'COALESCE(i.done_at, NOW())' : 'NULL') . ', i.done_by = ?
                            WHERE s.id_plan = ? AND i.code = ?')->execute([$done ? $userId : null, $planId, $code]);
    }

    /** Étape terminée quand toutes ses cases sont cochées. */
    public function refreshSteps(int $planId): void
    {
        $this->db->prepare('UPDATE n_hr_succession_step s SET s.done_at = IF((SELECT COUNT(*) FROM n_hr_succession_item i WHERE i.id_step = s.id AND i.done_at IS NULL) = 0
                                AND (SELECT COUNT(*) FROM n_hr_succession_item i WHERE i.id_step = s.id) > 0, COALESCE(s.done_at, NOW()), NULL) WHERE s.id_plan = ?')->execute([$planId]);
    }

    /** Étapes ouvertes des plans actifs d'un responsable qui commencent au plus tard le $until (Mój tydzień). */
    public function openSteps(int $userId, string $until): array
    {
        return $this->all(
            "SELECT st.id, st.id_plan, st.step_no, st.title, st.start_date, st.due_date, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
             FROM n_hr_succession_step st JOIN n_hr_succession s ON s.id = st.id_plan
             JOIN n_prod_employee e ON e.id = s.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE s.status = 'active' AND s.responsible_user = ? AND st.done_at IS NULL AND st.start_date <= ?
             ORDER BY st.due_date, st.step_no",
            [$userId, $until]
        );
    }

    /** Étapes en retard des plans actifs (rappel dans la cloche du responsable). */
    public function lateSteps(string $date): array
    {
        return $this->all(
            "SELECT st.id, st.id_plan, st.step_no, st.title, st.due_date, s.responsible_user, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name
             FROM n_hr_succession_step st JOIN n_hr_succession s ON s.id = st.id_plan
             JOIN n_prod_employee e ON e.id = s.id_employee JOIN production_employee pe ON pe.id = e.id_production_employee
             WHERE s.status = 'active' AND s.responsible_user IS NOT NULL AND st.done_at IS NULL AND st.due_date < ?",
            [$date]
        );
    }
}
