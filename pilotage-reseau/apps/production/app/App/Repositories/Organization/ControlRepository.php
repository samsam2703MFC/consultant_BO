<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [281] Kontrola (profil CEO, makieta 47) : lectures seules pour le tableau de contrôle — comptes ERP et leur poste, personnes
 * de Czas pracy, pointages et absences du jour, activité ERP, demandes de déblocage, sorties oubliées.
 */
final class ControlRepository extends WorkshopRepository
{
    protected string $table = 'n_user_ceo';

    /** Comptes avec un poste ERP actif (+ personne de Czas pracy liée). */
    public function people(): array
    {
        return $this->all(
            "SELECT u.id, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS name, p.id AS position_id, p.code AS position_code,
                    p.name_pl AS position_name, ue.id_employee
             FROM user u
             JOIN n_user_position up ON up.id_user = u.id
             JOIN n_position p ON p.id = up.id_position AND p.active = 1 AND p.kind = 'erp'
             LEFT JOIN n_user_employee ue ON ue.id_user = u.id
             ORDER BY p.sort_order, p.name_pl, name"
        );
    }

    /** Personnes actives de Czas pracy (+ compte ERP lié). */
    public function employees(): array
    {
        return $this->all(
            'SELECT e.id, ue.id_user FROM n_prod_employee e LEFT JOIN n_user_employee ue ON ue.id_employee = e.id WHERE e.active = 1'
        );
    }

    /** Pointages commencés dans [from, to[. */
    public function clocks(string $from, string $to): array
    {
        return $this->all('SELECT id_employee, clock_in, clock_out, source, id_device FROM n_prod_clock WHERE clock_in >= ? AND clock_in < ? ORDER BY clock_in', [$from, $to]);
    }

    /** Absences couvrant le jour (urlop, L4…). @return array<int, string> personne => type */
    public function absences(string $day): array
    {
        $out = [];
        foreach ($this->all('SELECT id_employee, type FROM n_time_absence WHERE date_from <= ? AND date_to >= ?', [$day, $day]) as $r) $out[(int) $r['id_employee']] = $r['type'];
        return $out;
    }

    /** Sorties oubliées : entrées d'avant aujourd'hui (7 derniers jours) sans sortie. */
    public function forgottenExits(string $today): array
    {
        return $this->all(
            "SELECT c.id_employee, c.clock_in FROM n_prod_clock c JOIN n_prod_employee e ON e.id = c.id_employee AND e.active = 1
             WHERE c.clock_out IS NULL AND c.clock_in < ? AND c.clock_in >= ? - INTERVAL 7 DAY ORDER BY c.clock_in",
            [$today, $today]
        );
    }

    /** Temps actif ERP par compte (secondes) dans [from, to[. @return array<int, int> */
    public function activity(string $from, string $to): array
    {
        $out = [];
        try {
            foreach ($this->all('SELECT id_user, SUM(active_s) AS s FROM n_activity_view WHERE started_at >= ? AND started_at < ? GROUP BY id_user', [$from, $to]) as $r) {
                $out[(int) $r['id_user']] = (int) $r['s'];
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    /**
     * Demandes de déblocage du jour (cloche : task_unblock:{compte}:{tâche}:{jour}[:{destinataire}]) — une par compte et tâche.
     * @return list<array{user:int, task:int, reason:string, at:string}>
     */
    public function unblockRequests(string $day): array
    {
        $out = [];
        foreach ($this->all("SELECT dedup_key, body, created_at FROM app_notification WHERE type = 'task_unblock' AND dedup_key LIKE 'task_unblock:%' AND created_at >= ? ORDER BY created_at", [$day]) as $r) {
            $p = explode(':', (string) $r['dedup_key']);
            if (count($p) < 4 || $p[3] !== $day) continue;
            $k = (int) $p[1] . ':' . (int) $p[2];
            if (isset($out[$k])) continue;
            $body = (string) $r['body'];
            $out[$k] = ['user' => (int) $p[1], 'task' => (int) $p[2], 'reason' => trim(str_contains($body, ' : ') ? substr($body, strrpos($body, ' : ') + 3) : $body), 'at' => substr((string) $r['created_at'], 11, 5)];
        }
        return array_values($out);
    }
}
