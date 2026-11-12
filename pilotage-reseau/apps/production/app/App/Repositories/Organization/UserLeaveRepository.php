<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [289] Urlop osoby z kontem ERP i jej zastępca (makieta 50) : n_user_leave. Les lectures tolèrent l'absence de la table
 * (avant la migration 180) : pas de congé, pas de transfert.
 */
final class UserLeaveRepository extends WorkshopRepository
{
    protected string $table = 'n_user_leave';

    private const SELECT = "SELECT l.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS name, p.id AS position_id, p.name_pl AS position_name,
            COALESCE(NULLIF(TRIM(s.displayed_name), ''), s.login) AS substitute_name, sp.name_pl AS substitute_position
        FROM n_user_leave l JOIN user u ON u.id = l.id_user LEFT JOIN n_user_position up ON up.id_user = l.id_user LEFT JOIN n_position p ON p.id = up.id_position
        LEFT JOIN user s ON s.id = l.id_substitute LEFT JOIN n_user_position sup ON sup.id_user = l.id_substitute LEFT JOIN n_position sp ON sp.id = sup.id_position";

    public function find(int $id): ?array
    {
        return $this->all(self::SELECT . ' WHERE l.id = ?', [$id])[0] ?? null;
    }

    /** Congés d'une personne qui chevauchent la période (statuts donnés). */
    public function forUser(int $userId, string $from, string $to, array $statuses = ['pending', 'approved']): array
    {
        return $this->safe(fn() => $this->all(self::SELECT . ' WHERE l.id_user = ? AND l.date_from <= ? AND l.date_to >= ? AND l.status IN (' . self::marks($statuses) . ') ORDER BY l.date_from',
            array_merge([$userId, $to, $from], $statuses)));
    }

    /** Tous les congés (pending + approved) qui chevauchent la période — tableau « kto kogo zastępuje ». */
    public function between(string $from, string $to, array $statuses = ['pending', 'approved']): array
    {
        return $this->safe(fn() => $this->all(self::SELECT . ' WHERE l.date_from <= ? AND l.date_to >= ? AND l.status IN (' . self::marks($statuses) . ') ORDER BY l.date_from, l.id',
            array_merge([$to, $from], $statuses)));
    }

    public function pending(): array
    {
        return $this->safe(fn() => $this->all(self::SELECT . " WHERE l.status = 'pending' ORDER BY l.date_from, l.id"));
    }

    /** Congé validé de la personne ce jour-là (null : présente). */
    public function onLeave(int $userId, string $day): ?array
    {
        return $this->safe(fn() => $this->all(self::SELECT . " WHERE l.id_user = ? AND l.status = 'approved' AND l.date_from <= ? AND l.date_to >= ? ORDER BY l.id DESC LIMIT 1",
            [$userId, $day, $day]))[0] ?? null;
    }

    /** Congés validés en cours ce jour-là dont la personne est le remplaçant (avec le poste de l'absent). */
    public function delegations(int $substituteId, string $day): array
    {
        return $this->safe(fn() => $this->all(self::SELECT . " WHERE l.id_substitute = ? AND l.status = 'approved' AND l.date_from <= ? AND l.date_to >= ? AND up.id_position IS NOT NULL ORDER BY l.date_from",
            [$substituteId, $day, $day]));
    }

    /** Jours ouvrés déjà pris ou prévus dans l'année (pending + approved, types urlop / na_zadanie). */
    public function yearLeaves(int $userId, int $year): array
    {
        return $this->safe(fn() => $this->all("SELECT * FROM n_user_leave WHERE id_user = ? AND status IN ('pending','approved') AND type IN ('urlop','na_zadanie')
            AND date_from <= ? AND date_to >= ? ORDER BY date_from", [$userId, $year . '-12-31', $year . '-01-01']));
    }

    /** Comptes ERP proposés comme remplaçants : un poste ERP, ou administrateur / CEO. */
    public function candidates(): array
    {
        return $this->all("SELECT u.id, COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS name, p.name_pl AS position_name, p.code AS position_code
            FROM user u LEFT JOIN n_user_position up ON up.id_user = u.id LEFT JOIN n_position p ON p.id = up.id_position
            WHERE up.id_position IS NOT NULL OR u.id IN (SELECT id_user FROM n_prod_user_role WHERE role_code = 'prod_admin' AND id_group IS NULL) OR u.login = 'admin'
                OR u.id IN (SELECT id_user FROM n_user_ceo)
            ORDER BY COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login)");
    }

    public function insert(array $r): int
    {
        $this->db->prepare('INSERT INTO n_user_leave (id_user, type, date_from, date_to, days, note, id_substitute) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['id_user'], $r['type'], $r['date_from'], $r['date_to'], $r['days'], $r['note'] ?? null, $r['id_substitute'] ?? null]);
        return (int) $this->db->lastInsertId();
    }

    public function setSubstitute(int $id, ?int $substituteId): void
    {
        $this->db->prepare('UPDATE n_user_leave SET id_substitute = ? WHERE id = ?')->execute([$substituteId, $id]);
    }

    /** Décision depuis l'état attendu (pending → approved / rejected ; pending|approved → cancelled). */
    public function decide(int $id, string $status, array $from, ?string $note, ?int $userId): bool
    {
        $st = $this->db->prepare('UPDATE n_user_leave SET status = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status IN (' . self::marks($from) . ')');
        $st->execute(array_merge([$status, $note, $userId, $id], $from));
        return $st->rowCount() > 0;
    }

    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function safe(callable $fn): array
    {
        try {
            return $fn();
        } catch (\PDOException) {
            return [];
        }
    }
}
