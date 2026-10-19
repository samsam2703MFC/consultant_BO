<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Utilisateurs de l'ERP (table user), leurs rôles du module, jetons et tentatives de connexion. */
final class AuthRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_refresh_token';

    public function userByLogin(string $login): ?array
    {
        $rows = $this->all('SELECT id, login, password, displayed_name FROM user WHERE login = ? LIMIT 2', [$login]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    public function userById(int $id): ?array
    {
        $rows = $this->all('SELECT id, login, displayed_name FROM user WHERE id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /** @return list<array{code:string,group_id:?int,permission:?string}> */
    public function rolePermissions(int $userId): array
    {
        return $this->all(
            'SELECT ur.role_code AS code, ur.id_group AS group_id, rp.permission
             FROM tfb_prod_user_role ur
             LEFT JOIN tfb_prod_role_permission rp ON rp.role_code = ur.role_code
             WHERE ur.id_user = ?
             ORDER BY ur.role_code, ur.id_group',
            [$userId]
        );
    }

    public function recentFailures(string $login, string $ip, int $windowMinutes): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM tfb_prod_login_attempt
             WHERE success = 0 AND (login = ? OR ip = ?) AND created_at > NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([$login, $ip, $windowMinutes]);
        return (int) $stmt->fetchColumn();
    }

    public function recordAttempt(string $login, string $ip, bool $success): void
    {
        $this->db->prepare('INSERT INTO tfb_prod_login_attempt (login, ip, success) VALUES (?, ?, ?)')
            ->execute([mb_substr($login, 0, 255), $ip, (int) $success]);
    }

    public function storeRefreshToken(int $userId, string $hash, string $familyId, string $expiresAt, ?string $userAgent, ?string $ip): int
    {
        $this->db->prepare(
            'INSERT INTO tfb_prod_refresh_token (id_user, token_hash, family_id, expires_at, user_agent, ip) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $hash, $familyId, $expiresAt, $userAgent === null ? null : mb_substr($userAgent, 0, 255), $ip]);
        return (int) $this->db->lastInsertId();
    }

    public function refreshTokenByHash(string $hash): ?array
    {
        $rows = $this->all('SELECT * FROM tfb_prod_refresh_token WHERE token_hash = ?', [$hash]);
        return $rows[0] ?? null;
    }

    /** Marque le jeton consommé ; false si un autre appel l'a consommé entre-temps. */
    public function consumeRefreshToken(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE tfb_prod_refresh_token SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    public function linkReplacement(int $id, int $replacedBy): void
    {
        $this->db->prepare('UPDATE tfb_prod_refresh_token SET replaced_by = ? WHERE id = ?')->execute([$replacedBy, $id]);
    }

    public function revokeFamily(string $familyId): void
    {
        $this->db->prepare('UPDATE tfb_prod_refresh_token SET revoked_at = COALESCE(revoked_at, NOW()) WHERE family_id = ?')->execute([$familyId]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->db->prepare('UPDATE tfb_prod_refresh_token SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id_user = ?')->execute([$userId]);
    }
}
