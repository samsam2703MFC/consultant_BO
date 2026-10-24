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

    /**
     * Plusieurs GET d'un coup (21/10/2026 : les fiches des promotions, relues à l'ouverture de l'onglet Promotions du
     * dashboard) : [clé => corps (`data`, sinon le corps entier) | null], dans l'ordre des chemins. Une file de quatre
     * requêtes de front, comme PanelApi::getParallele. Un 401 refait le jeton et relance les seules requêtes refusées.
     */
    public static function getParallele(array $paths, int $front = 4): array
    {
        $out = array_fill_keys(array_keys($paths), null);
        if ($paths === []) { return $out; }
        $tok = self::token();
        if ($tok === null) { return $out; }
        $refus = self::file($paths, $tok, $front, $out);
        if ($refus !== []) {
            $tok = self::token(true);
            if ($tok !== null) { self::file(array_intersect_key($paths, array_flip($refus)), $tok, $front, $out); }
        }
        return $out;
    }

    /** La file de GET : remplit $out ; rend les clés refusées en 401. */
    private static function file(array $paths, string $tok, int $front, array &$out): array
    {
        $base = self::config()['base'];
        $multi = curl_multi_init();
        $file = array_keys($paths); $cle = []; $actifs = 0; $refus = [];
        $lancer = static function () use (&$file, &$cle, &$actifs, $multi, $paths, $base, $tok): void {
            $k = array_shift($file);
            $ch = curl_init($base . $paths[$k]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $tok],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            curl_multi_add_handle($multi, $ch);
            $cle[spl_object_id($ch)] = $k;
            $actifs++;
        };
        while ($actifs < max(1, $front) && $file) { $lancer(); }
        do {
            $st = curl_multi_exec($multi, $en);
            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $k = $cle[spl_object_id($ch)];
                $raw = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                $actifs--;
                $res = is_string($raw) ? json_decode($raw, true) : null;
                if ($code === 401) { $refus[] = $k; }
                elseif ($code < 200 || $code >= 300) { self::$lastError = 'GET ' . $paths[$k] . ' → HTTP ' . $code; $out[$k] = null; }
                else { $out[$k] = is_array($res) ? ($res['data'] ?? $res) : null; }
                if ($file) { $lancer(); }
            }
            if ($actifs > 0 && curl_multi_select($multi, 1.0) === -1) { usleep(2000); }
        } while ($actifs > 0 && $st === CURLM_OK);
        curl_multi_close($multi);
        return $refus;
    }

    /**
     * Une écriture (POST, PUT, PATCH, DELETE) : {code, corps, erreur}. Les promotions des magasins (17/10/2026)
     * s'écrivent au panel par là ; l'erreur reprend le message du panel et le détail de ses champs refusés.
     */
    public static function ecrire(string $method, string $path, ?array $body = null): array
    {
        $tok = self::token();
        if ($tok === null) { return ['code' => 0, 'corps' => null, 'erreur' => self::$lastError ?? 'jeton admin indisponible']; }
        $url = self::config()['base'] . $path;
        [$code, $res] = self::http($method, $url, $body, $tok);
        if ($code === 401) {
            $tok = self::token(true);
            if ($tok !== null) { [$code, $res] = self::http($method, $url, $body, $tok); }
        }
        if ($code >= 200 && $code < 300) { return ['code' => $code, 'corps' => $res, 'erreur' => null]; }
        $det = '';
        foreach (['description', 'message', 'detail', 'error'] as $k) {
            if (!empty($res[$k]) && is_string($res[$k])) { $det = $res[$k]; break; }
        }
        // Les champs refusés : {errors: {champ: [messages]}} ou une liste.
        $champs = [];
        foreach ((array) ($res['errors'] ?? $res['data']['errors'] ?? []) as $k => $v) {
            $champs[] = (is_string($k) ? $k . ' : ' : '') . (is_array($v) ? implode(', ', array_map('strval', array_filter($v, 'is_scalar'))) : (string) $v);
        }
        return ['code' => $code, 'corps' => $res, 'erreur' => 'HTTP ' . $code . ($det !== '' ? ' : ' . substr($det, 0, 300) : '')
            . ($champs !== [] ? ' — ' . substr(implode(' ; ', $champs), 0, 400) : '')];
    }

    /**
     * GET pour qui veut le code autant que le corps (les promotions du panel, 16/10/2026) :
     * {code, corps, erreur} — un 403 se dit tel quel au lieu de se perdre dans un null.
     */
    public static function sondeGet(string $path): array
    {
        $tok = self::token();
        if ($tok === null) { return ['code' => 0, 'corps' => null, 'erreur' => self::$lastError ?? 'jeton admin indisponible']; }
        $url = self::config()['base'] . $path;
        [$code, $res] = self::http('GET', $url, null, $tok);
        if ($code === 401) {
            $tok = self::token(true);
            if ($tok !== null) { [$code, $res] = self::http('GET', $url, null, $tok); }
        }
        return ['code' => $code, 'corps' => $res, 'erreur' => $code >= 200 && $code < 300 ? null : ('HTTP ' . $code . (isset($res['message']) && is_string($res['message']) ? ' : ' . $res['message'] : ''))];
    }
}
