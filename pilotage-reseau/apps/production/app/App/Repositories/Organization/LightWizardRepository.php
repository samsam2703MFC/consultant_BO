<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Kreatory « lżej » [247] : zakończenie kreatora (n_wizard_done — zadanie zalicza się samo), działania z przeglądów
 * (n_wizard_action) i baner « Od kierownika produkcji » na tabletach grup (n_task_reminder bez zadania).
 */
final class LightWizardRepository extends WorkshopRepository
{
    protected string $table = 'n_wizard_done';

    public function done(string $code, string $from, string $to): ?array
    {
        return $this->all('SELECT d.summary, d.day, d.done_at, u.login AS by_login FROM n_wizard_done d LEFT JOIN user u ON u.id = d.done_by
            WHERE d.code = ? AND d.day >= ? AND d.day < ? ORDER BY d.day DESC LIMIT 1', [$code, $from, $to])[0] ?? null;
    }

    public function markDone(string $code, string $day, string $summary, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE summary = VALUES(summary), done_by = VALUES(done_by), done_at = VALUES(done_at)'
        )->execute([$code, $day, mb_substr($summary, 0, 255), $userId]);
    }

    /** Baner na tablecie grupy (audience group:<id>) — zamyka się sam po zatwierdzeniu dnia grupy. */
    public function tabletMessage(int $groupId, string $groupName, string $title, string $message, string $due, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO n_task_reminder (batch, id_task, id_occurrence, due_date, title, message, urgent, target_kind, id_user, audience, target_label, channels, sent_by, sent_at)
            VALUES (?, NULL, NULL, ?, ?, ?, 0, \'tablet\', NULL, ?, ?, \'tablet\', ?, NOW())')
            ->execute([substr(bin2hex(random_bytes(8)), 0, 16), $due, mb_substr($title, 0, 255), $message, 'group:' . $groupId, mb_substr($groupName, 0, 120), $userId]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array> otwarte działania z przeglądów (najstarszy termin pierwszy) */
    public function openActions(?string $code = null): array
    {
        return $this->all('SELECT a.*, COALESCE(NULLIF(u.displayed_name, \'\'), u.login) AS user_name, g.name_pl AS group_name FROM n_wizard_action a LEFT JOIN user u ON u.id = a.id_user LEFT JOIN n_prod_group g ON g.id = a.id_group
            WHERE a.status = \'open\'' . ($code ? ' AND a.code = ?' : '') . ' ORDER BY a.due_date IS NULL, a.due_date, a.id', $code ? [$code] : []);
    }

    /** @return list<array> działania przeglądu (kod + okres) */
    public function actions(string $code, string $period): array
    {
        return $this->all('SELECT a.*, COALESCE(NULLIF(u.displayed_name, \'\'), u.login) AS user_name, g.name_pl AS group_name FROM n_wizard_action a LEFT JOIN user u ON u.id = a.id_user LEFT JOIN n_prod_group g ON g.id = a.id_group
            WHERE a.code = ? AND a.period = ? ORDER BY a.id', [$code, $period]);
    }

    public function addAction(array $a): int
    {
        $this->db->prepare('INSERT INTO n_wizard_action (code, period, title, goal, id_user, id_group, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$a['code'], $a['period'], $a['title'], $a['goal'] ?? null, $a['id_user'] ?? null, $a['id_group'] ?? null, $a['due_date'] ?? null, $a['created_by'] ?? null]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteActions(string $code, string $period): void
    {
        $this->db->prepare('DELETE FROM n_wizard_action WHERE code = ? AND period = ? AND status = \'open\'')->execute([$code, $period]);
    }

    public function setActionStatus(int $id, bool $done, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_wizard_action SET status = ?, done_at = ?, done_by = ? WHERE id = ?')
            ->execute([$done ? 'done' : 'open', $done ? date('Y-m-d H:i:s') : null, $done ? $userId : null, $id]);
    }

    /** @return list<array{id:int, login:string, name:string}> aktywni użytkownicy ERP (wybór osoby odpowiedzialnej) */
    public function users(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'login' => (string) $r['login'], 'name' => trim((string) ($r['displayed_name'] ?: $r['login']))],
            $this->all('SELECT id, login, displayed_name FROM user ORDER BY COALESCE(NULLIF(displayed_name, \'\'), login)'));
    }

    /** @return list<array{id:int, name:string, code:string}> aktywne grupy produkcji (bez pakowania) */
    public function groups(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name_pl'], 'code' => (string) $r['code']],
            $this->all("SELECT id, code, name_pl FROM n_prod_group WHERE active = 1 AND code NOT IN ('PAKOWANIE', 'FACONNAGE') ORDER BY sort_order, id"));
    }
}
