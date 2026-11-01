<?php

declare(strict_types=1);

namespace Hr\Repository;

use Hr\Auth\Identity;
use Hr\Support\Clock;
use Hr\Support\Database;

/**
 * Le journal du module : qui, quand, quoi. `detail` ne porte jamais une donnée sensible (ni NISS, ni clé,
 * ni jeton) — on y écrit des identifiants internes et des faits.
 */
final class AuditLog
{
    public static function add(?Identity $who, string $action, ?int $employerId = null, ?string $targetType = null, ?string $targetId = null, string $detail = ''): void
    {
        Database::insert('hr_dimona_audit', [
            'at' => Clock::sql(),
            'user_id' => $who?->userId,
            'user_name' => $who !== null ? mb_substr($who->name, 0, 120) : null,
            'role' => $who?->role,
            'action' => mb_substr($action, 0, 48),
            'employer_id' => $employerId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail' => mb_substr($detail, 0, 500),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function forEmployer(int $employerId, int $limit = 100): array
    {
        $rows = Database::rows('SELECT * FROM hr_dimona_audit WHERE employer_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$employerId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'at' => Clock::iso($r['at']), 'user' => $r['user_name'], 'role' => $r['role'],
            'action' => $r['action'], 'target' => $r['target_type'] !== null ? $r['target_type'] . ':' . $r['target_id'] : null,
            'detail' => $r['detail'],
        ], $rows);
    }

    /** Une action de ce type a-t-elle déjà été écrite pour cette cible depuis tel instant ? (alertes du cron) */
    public static function since(string $action, string $targetType, string $targetId, int $since): bool
    {
        return Database::row('SELECT id FROM hr_dimona_audit WHERE action = ? AND target_type = ? AND target_id = ? AND at >= ? LIMIT 1',
            [$action, $targetType, $targetId, Clock::sql($since)]) !== null;
    }
}
