<?php
declare(strict_types=1);

/**
 * Client de l'API ERP (TFBuddy) — réalm ADMIN.
 *
 * La reprise de l'assistant de campagne (gammes saisonnières, alias de noms,
 * produits par gamme) consomme l'API de l'ERP plutôt que ses tables, dès que ce
 * compte est configuré. Les identifiants et le jeton vivent CÔTÉ SERVEUR :
 * rien ne transite par le navigateur — même règle que PanelApi (compte
 * consultant) et que la clé Anthropic.
 *
 * Authentification : POST /admin/auth/login {phone, password} → JWT court
 * (~30 min), mis en cache dans `ceo_app_setting` (clé `erpAdminToken`) et
 * renouvelé automatiquement sur 401. Le réalm historique
 * POST /admin/authenticate {login, password} sert de repli.
 *
 * Configuration (par priorité) : réglage `erpAdmin` saisi dans Paramètres,
 * puis config/config.php ('erpAdmin' => [base, phone, password]), puis
 * variables d'environnement ERP_ADMIN_BASE / _PHONE / _PASSWORD.
 * Un jeton déposé à la main (POST /admin/erp-token) est utilisé tel quel
 * tant qu'il n'est pas expiré — utile pour un essai sans compte.
 */
final class ErpApi
{
    /** Dernière erreur rencontrée — remontée à l'écran plutôt qu'un vide muet. */
    public static ?string $lastError = null;

    private static ?array $cfg = null;
    private static ?string $token = null;

    public static function config(): array
    {
        if (self::$cfg === null) {
            $s = setting('erpAdmin', []);
            if (!is_array($s)) { $s = []; }
            $c = Db::config()['erpAdmin'] ?? [];
            $env = static fn (string $k): string => (string) (getenv($k) ?: '');
            $val = static function (string $k, array $s, array $c, string $envKey, string $def = '') use ($env): string {
                foreach ([$s[$k] ?? null, $c[$k] ?? null] as $v) {
                    if (is_string($v) && trim($v) !== '') { return trim($v); }
                }
                $e = $env($envKey);
                return $e !== '' ? $e : $def;
            };
            self::$cfg = [
                'base'     => rtrim($val('base', $s, $c, 'ERP_ADMIN_BASE', 'https://atelierby.tfbuddy.com/api/v1'), '/'),
                'phone'    => $val('phone', $s, $c, 'ERP_ADMIN_PHONE'),
                'password' => $val('password', $s, $c, 'ERP_ADMIN_PASSWORD'),
            ];
        }
        return self::$cfg;
    }

    /** État pour l'écran Paramètres — JAMAIS le mot de passe, seulement s'il existe. */
    public static function statut(): array
    {
        $c = self::config();
        $jeton = self::jetonEnCache();
        return ['base' => $c['base'], 'phone' => $c['phone'],
            'motDePasseDefini' => $c['password'] !== '',
            'configure'        => self::configured(),
            'jetonValide'      => $jeton !== null];
    }

    /** Vide le jeton en cache (après changement d'identifiants). */
    public static function oublierJeton(): void
    {
        self::$token = null; self::$cfg = null;
        try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['erpAdminToken']); } catch (Throwable $e) { /* rien */ }
    }

    /** Test de connexion : renvoie [ok, message] — pour le bouton « Tester ». */
    public static function tester(): array
    {
        self::oublierJeton();
        self::$lastError = null;
        if (!self::configured()) { return [false, 'Renseignez le téléphone et le mot de passe du compte admin ERP.']; }
        $gammes = self::get('/product-availability-periods');
        if ($gammes === null) { return [false, self::$lastError ?? 'connexion refusée']; }
        $n = is_array($gammes) ? count($gammes) : 0;
        return [true, 'Connexion réussie — ' . $n . ' gamme(s) lisible(s) sur l’API ERP.'];
    }

    /** Vrai si des identifiants sont configurés (sinon : jeton déposé ou repli tables). */
    public static function configured(): bool
    {
        $c = self::config();
        return $c['phone'] !== '' && $c['password'] !== '';
    }

    /** Vrai si un appel authentifié est possible (identifiants OU jeton encore frais). */
    public static function disponible(): bool
    {
        return self::configured() || self::jetonEnCache() !== null;
    }

    /**
     * Jeton du cache s'il reste utilisable. Deux formes acceptées :
     * la chaîne brute (dépôt manuel) — l'échéance se lit dans le JWT — et
     * {token, exp} (connexion automatique).
     */
    private static function jetonEnCache(): ?string
    {
        $cached = setting('erpAdminToken');
        $tok = null; $exp = 0;
        if (is_string($cached) && $cached !== '') {
            $tok = $cached;
            $claims = json_decode((string) base64_decode(strtr(explode('.', $cached . '..')[1], '-_', '+/')), true);
            $exp = is_array($claims) ? (int) ($claims['exp'] ?? 0) : 0;
        } elseif (is_array($cached) && !empty($cached['token'])) {
            $tok = (string) $cached['token'];
            $exp = (int) ($cached['exp'] ?? 0);
        }
        // 60 s de marge : un jeton qui meurt pendant la reprise vaut un 401.
        return $tok !== null && $exp > time() + 60 ? $tok : null;
    }

    /** Requête HTTP brute. Renvoie [code, corps décodé]. */
    private static function http(string $method, string $url, ?array $body = null, ?string $token = null): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($body !== null)  { $headers[] = 'Content-Type: application/json'; }
        if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            self::$lastError = 'API ERP injoignable : ' . $err;
            return [0, null];
        }
        return [$code, json_decode((string) $raw, true)];
    }

    /** Jeton en cache, sinon connexion. `$force` ignore le cache (retry sur 401). */
    private static function token(bool $force = false): ?string
    {
        if (!$force) {
            if (self::$token !== null) { return self::$token; }
            $cached = self::jetonEnCache();
            if ($cached !== null) { return self::$token = $cached; }
        }
        if (!self::configured()) {
            self::$lastError = 'compte admin ERP non configuré (et aucun jeton déposé encore valable)';
            return null;
        }
        $c = self::config();
        [$code, $res] = self::http('POST', $c['base'] . '/admin/auth/login',
            ['phone' => $c['phone'], 'password' => $c['password']]);
        // Réalm historique en repli : mêmes identifiants, autre route.
        if ($code === 404 || $code === 405) {
            [$code, $res] = self::http('POST', $c['base'] . '/admin/authenticate',
                ['login' => $c['phone'], 'password' => $c['password']]);
        }
        $tok = null;
        foreach ([$res['access_token'] ?? null, $res['token'] ?? null,
                  $res['data']['access_token'] ?? null, $res['data']['token'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') { $tok = $cand; break; }
        }
        if ($tok === null) {
            self::$lastError = 'connexion admin ERP refusée (HTTP ' . $code . ')'
                . (isset($res['description']) && is_string($res['description']) ? ' : ' . $res['description'] : '');
            return null;
        }
        $ttl = isset($res['expires_in']) && is_numeric($res['expires_in']) ? (int) $res['expires_in'] : 1800;
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['erpAdminToken', json_encode(['token' => $tok, 'exp' => time() + $ttl])]);
        return self::$token = $tok;
    }

    /**
     * GET authentifié sur l'API ERP. Renvoie le corps décodé, ou null.
     * Un 401 déclenche UNE reconnexion (jeton périmé) avant d'abandonner.
     */
    public static function get(string $path): mixed
    {
        $tok = self::token();
        if ($tok === null) { return null; }
        $url = self::config()['base'] . $path;
        [$code, $res] = self::http('GET', $url, null, $tok);
        if ($code === 401) {
            $tok = self::token(true);
            if ($tok === null) { return null; }
            [$code, $res] = self::http('GET', $url, null, $tok);
        }
        if ($code < 200 || $code >= 300) {
            $det = '';
            foreach (['description', 'message', 'detail', 'error'] as $k) {
                if (!empty($res[$k]) && is_string($res[$k])) { $det = $res[$k]; break; }
            }
            self::$lastError = 'GET ' . $path . ' → HTTP ' . $code
                . ($det !== '' ? ' : ' . substr($det, 0, 300) : '');
            return null;
        }
        return $res['data'] ?? $res;
    }
}
