<?php
declare(strict_types=1);

/**
 * Authentification intégrée du cockpit — aucun secret à distribuer.
 *
 *  - Le mot de passe est DÉFINI AU PREMIER LANCEMENT depuis l'écran de
 *    connexion (comme un « setup wizard ») ; son hash (password_hash) vit
 *    dans ceo_app_setting.
 *  - La session est un cookie HttpOnly signé HMAC-SHA256 avec un secret
 *    généré à l'installation (ceo_app_setting.authSecret), valable 30 jours.
 *  - Toutes les routes de l'API sont fermées tant que la session n'est pas
 *    valide, à l'exception des routes /auth/*.
 */

const AUTH_COOKIE = 'cockpit_s';
const AUTH_TTL    = 2592000; // 30 jours

function authSecret(): string { return (string) setting('authSecret', ''); }

function authHash(): ?string { $h = setting('authPasswordHash'); return is_string($h) ? $h : null; }

function authIsSetup(): bool { return authHash() !== null; }

function authOk(): bool
{
    $c = (string) ($_COOKIE[AUTH_COOKIE] ?? '');
    if (!authIsSetup() || $c === '' || !str_contains($c, '.')) { return false; }
    [$exp, $sig] = explode('.', $c, 2);
    if (!ctype_digit($exp) || (int) $exp < time()) { return false; }
    return hash_equals(hash_hmac('sha256', $exp, authSecret()), $sig);
}

function authIssue(): void
{
    $exp = time() + AUTH_TTL;
    $sig = hash_hmac('sha256', (string) $exp, authSecret());
    setcookie(AUTH_COOKIE, $exp . '.' . $sig, [
        'expires' => $exp, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
}

function authClear(): void
{
    setcookie(AUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

/** Routes /auth/* — renvoie null si le chemin n'est pas une route d'auth. */
function authRoute(string $method, string $path): ?array
{
    if ($method === 'GET' && $path === '/auth/status') {
        return ['setup' => authIsSetup(), 'authed' => authOk()];
    }
    if ($method === 'POST' && $path === '/auth/setup') {
        if (authIsSetup()) { http_response_code(409); return ['error' => 'déjà configuré']; }
        $pw = (string) (body()['password'] ?? '');
        if (strlen($pw) < 8) { http_response_code(422); return ['error' => 'Mot de passe : 8 caractères minimum.']; }
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['authPasswordHash', json_encode(password_hash($pw, PASSWORD_DEFAULT))]);
        journalAdd('Système', 'Sécurité', null, 'Mot de passe du cockpit défini (premier lancement)');
        authIssue();
        return ['ok' => true];
    }
    if ($method === 'POST' && $path === '/auth/login') {
        $pw = (string) (body()['password'] ?? '');
        if (!authIsSetup() || !password_verify($pw, (string) authHash())) {
            usleep(500000); // freiner la force brute
            http_response_code(401);
            return ['error' => 'Mot de passe incorrect.'];
        }
        authIssue();
        return ['ok' => true];
    }
    if ($method === 'POST' && $path === '/auth/logout') {
        authClear();
        return ['ok' => true];
    }
    if ($method === 'POST' && $path === '/auth/password') {  // changement de mot de passe (session requise)
        if (!authOk()) { http_response_code(401); return ['error' => 'auth']; }
        $b = body();
        if (!password_verify((string) ($b['ancien'] ?? ''), (string) authHash())) { http_response_code(401); return ['error' => 'Ancien mot de passe incorrect.']; }
        $pw = (string) ($b['password'] ?? '');
        if (strlen($pw) < 8) { http_response_code(422); return ['error' => 'Mot de passe : 8 caractères minimum.']; }
        Db::exec('UPDATE ceo_app_setting SET value = ? WHERE `key` = ?', [json_encode(password_hash($pw, PASSWORD_DEFAULT)), 'authPasswordHash']);
        journalAdd('CEO', 'Sécurité', null, 'Mot de passe du cockpit modifié');
        return ['ok' => true];
    }
    return null;
}
