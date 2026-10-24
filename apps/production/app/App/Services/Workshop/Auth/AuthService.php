<?php
declare(strict_types=1);

namespace App\Services\Workshop\Auth;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Jwt;
use App\Core\Api\Workshop\JwtException;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Security\Passwords;
use App\Repositories\Workshop\AuthRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Connexion à l'API du module atelier avec les comptes de l'ERP (table user) :
 * jeton d'accès JWT court + jeton de renouvellement opaque, à usage unique (rotation).
 * Un jeton de renouvellement déjà utilisé qui revient est le signe d'un vol : toute sa
 * famille est révoquée. Les droits sont relus en base à chaque requête (refus par défaut).
 */
final class AuthService
{
    public const AUDIENCE = 'tfb-production';

    public function __construct(
        private AuthRepository $auth,
        private ParamRepository $params,
        private WorkshopConfig $config,
    ) {
    }

    public function login(string $login, string $password, string $ip, ?string $userAgent): array
    {
        $jwt = $this->jwt();
        $login = trim($login);
        $window = max(1, (int) $this->params->get('auth.lockout_window_min', '15'));
        if ($this->auth->recentFailures($login, $ip, $window) >= max(1, (int) $this->params->get('auth.max_failed_logins', '10'))) {
            throw new ApiException('TOO_MANY_ATTEMPTS', 429, [], ['minutes' => $window]);
        }

        $user = $login === '' ? null : $this->auth->userByLogin($login);
        if ($user === null) {
            password_verify($password, '$2y$10$usesomesillystringforsaltDpTqHcLCiYB2OmmLMtpNfO1PO8xu');
            $this->auth->recordAttempt($login, $ip, false);
            throw new ApiException('INVALID_CREDENTIALS', 401);
        }
        if (!self::passwordMatches((string) $user['password'], $password)) {
            $this->auth->recordAttempt($login, $ip, false);
            throw new ApiException('INVALID_CREDENTIALS', 401);
        }
        $this->auth->recordAttempt($login, $ip, true);
        if (Passwords::needsRehash((string) $user['password'])) {
            $this->auth->setPassword((int) $user['id'], Passwords::hash($password));
        }

        if (!$this->auth->rolePermissions((int) $user['id'])) {
            throw new ApiException('NO_PRODUCTION_ROLE', 403);
        }
        return $this->issue($jwt, $user, bin2hex(random_bytes(16)), $ip, $userAgent);
    }

    public function refresh(string $refreshToken, string $ip, ?string $userAgent): array
    {
        $jwt = $this->jwt();
        $row = $refreshToken === '' ? null : $this->auth->refreshTokenByHash(hash('sha256', $refreshToken));
        if ($row === null) {
            throw new ApiException('REFRESH_INVALID', 401);
        }
        if ($row['revoked_at'] !== null) {
            $this->auth->revokeFamily($row['family_id']);
            throw new ApiException('REFRESH_INVALID', 401);
        }
        if (strtotime($row['expires_at']) < time() || !$this->auth->consumeRefreshToken((int) $row['id'])) {
            $this->auth->revokeFamily($row['family_id']);
            throw new ApiException('REFRESH_INVALID', 401);
        }

        $user = $this->auth->userById((int) $row['id_user']);
        if ($user === null || !$this->auth->rolePermissions((int) $user['id'])) {
            $this->auth->revokeFamily($row['family_id']);
            throw new ApiException('REFRESH_INVALID', 401);
        }
        $tokens = $this->issue($jwt, $user, $row['family_id'], $ip, $userAgent);
        $this->auth->linkReplacement((int) $row['id'], $tokens['_refresh_id']);
        return $tokens;
    }

    public function logout(string $refreshToken): void
    {
        $row = $refreshToken === '' ? null : $this->auth->refreshTokenByHash(hash('sha256', $refreshToken));
        if ($row !== null) {
            $this->auth->revokeFamily($row['family_id']);
        }
    }

    /** Vérifie le jeton d'accès et charge les droits de l'utilisateur. */
    public function authenticate(string $accessToken, string $requestId, Translator $translator): ApiContext
    {
        try {
            $claims = $this->jwt()->decode($accessToken, self::AUDIENCE);
        } catch (JwtException $e) {
            throw new ApiException($e->getMessage() === 'expired' ? 'TOKEN_EXPIRED' : 'TOKEN_INVALID', 401);
        }
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0 || $this->auth->userById($userId) === null) {
            throw new ApiException('TOKEN_INVALID', 401);
        }

        $roles = [];
        $global = [];
        $byGroup = [];
        foreach ($this->auth->rolePermissions($userId) as $row) {
            $groupId = $row['group_id'] === null ? null : (int) $row['group_id'];
            $roles[$row['code'] . '|' . $groupId] = ['code' => $row['code'], 'group_id' => $groupId];
            if ($row['permission'] === null) continue;
            if ($groupId === null) {
                $global[$row['permission']] = true;
            } else {
                $byGroup[$row['permission']][$groupId] = true;
            }
        }
        return new ApiContext($requestId, $translator, $userId, array_values($roles), $global, $byGroup);
    }

    /**
     * Jeton d'accès pour l'utilisateur déjà connecté à l'ERP (pages web du module) : pas de mot
     * de passe ni de jeton de renouvellement ; la page en redemande un quand il expire.
     * @return array{access_token:string, token_type:string, expires_in:int}
     */
    public function sessionToken(int $userId): array
    {
        $jwt = $this->jwt();
        $user = $this->auth->userById($userId);
        if ($user === null || !$this->auth->rolePermissions($userId)) {
            throw new ApiException('NO_PRODUCTION_ROLE', 403);
        }
        $now = time();
        $ttl = max(60, (int) $this->params->get('auth.access_ttl_s', '900'));
        $roles = array_values(array_unique(array_column($this->auth->rolePermissions($userId), 'code')));
        return [
            'access_token' => $jwt->encode([
                'iss' => self::AUDIENCE, 'aud' => self::AUDIENCE, 'sub' => (string) $user['id'], 'login' => $user['login'],
                'roles' => $roles, 'iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(12)),
            ]),
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
        ];
    }

    public function me(ApiContext $context): array
    {
        $user = $this->auth->userById((int) $context->userId);
        return [
            'id' => (int) $user['id'],
            'login' => $user['login'],
            'display_name' => $user['displayed_name'],
            'roles' => $context->roles,
            'permissions' => $context->permissions(),
        ];
    }

    private function issue(Jwt $jwt, array $user, string $familyId, string $ip, ?string $userAgent): array
    {
        $now = time();
        $accessTtl = max(60, (int) $this->params->get('auth.access_ttl_s', '900'));
        $refreshTtl = max(1, (int) $this->params->get('auth.refresh_ttl_days', '30')) * 86400;
        $roles = array_values(array_unique(array_column($this->auth->rolePermissions((int) $user['id']), 'code')));

        $access = $jwt->encode([
            'iss' => self::AUDIENCE,
            'aud' => self::AUDIENCE,
            'sub' => (string) $user['id'],
            'login' => $user['login'],
            'roles' => $roles,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $accessTtl,
            'jti' => bin2hex(random_bytes(12)),
        ]);
        $refresh = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $refreshId = $this->auth->storeRefreshToken((int) $user['id'], hash('sha256', $refresh), $familyId,
            date('Y-m-d H:i:s', $now + $refreshTtl), $userAgent, $ip);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $refresh,
            'refresh_expires_in' => $refreshTtl,
            '_refresh_id' => $refreshId,
        ];
    }

    private function jwt(): Jwt
    {
        try {
            return new Jwt($this->config->jwtSecret());
        } catch (\InvalidArgumentException) {
            throw new ApiException('AUTH_NOT_CONFIGURED', 503);
        }
    }

    /** Mot de passe haché (bcrypt/argon) ou, pour les comptes historiques de l'ERP, en clair. */
    public static function passwordMatches(string $stored, string $given): bool
    {
        return Passwords::matches($stored, $given);
    }
}
