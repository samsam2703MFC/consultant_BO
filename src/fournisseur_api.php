<?php
declare(strict_types=1);

/**
 * Client de l'API ERP (TFBuddy) — réalm FOURNISSEUR.
 *
 * Mesuré : les commandes matière ne se lisent NI en réalm consultant NI en
 * réalm admin — /shops/{id}/orders/materials et /material-suppliers/{id}/orders
 * répondent 404 ORDER_NOT_FOUND aux deux, alors que le portail fournisseur les
 * voit sur la même API et le même environnement. Elles vivent donc derrière le
 * compte externe d'un fournisseur : c'est ce compte-ci.
 *
 * Un jeton ne voit QUE son fournisseur (claim supplier_id) — le suivi affiche
 * donc les commandes du ou des fournisseurs dont le compte est renseigné, et
 * dit ce qu'il ne peut pas montrer plutôt que de laisser un écran vide muet.
 *
 * Configuration (par priorité) : réglage `fournisseurApi` saisi dans
 * Paramètres, puis config/config.php ('fournisseurApi' => [base, login,
 * password]), puis SUPPLIER_API_BASE / _LOGIN / _PASSWORD.
 */
final class FournisseurApi
{
    public static ?string $lastError = null;

    private static ?array $cfg = null;
    private static ?string $token = null;

    public static function config(): array
    {
        if (self::$cfg === null) {
            $s = setting('fournisseurApi', []);
            if (!is_array($s)) { $s = []; }
            $c = Db::config()['fournisseurApi'] ?? [];
            $env = static fn (string $k): string => (string) (getenv($k) ?: '');
            $val = static function (string $k, string $envKey, string $def = '') use ($s, $c, $env): string {
                foreach ([$s[$k] ?? null, $c[$k] ?? null] as $v) {
                    if (is_string($v) && trim($v) !== '') { return trim($v); }
                }
                $e = $env($envKey);
                return $e !== '' ? $e : $def;
            };
            self::$cfg = [
                'base'     => rtrim($val('base', 'SUPPLIER_API_BASE', 'https://atelierby.tfbuddy.com/api/v1'), '/'),
                'login'    => $val('login', 'SUPPLIER_API_LOGIN'),
                'password' => $val('password', 'SUPPLIER_API_PASSWORD'),
            ];
        }
        return self::$cfg;
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['login'] !== '' && $c['password'] !== '';
    }

    /** État pour l'écran — JAMAIS le mot de passe. */
    public static function statut(): array
    {
        $c = self::config();
        return ['base' => $c['base'], 'login' => $c['login'],
            'motDePasseDefini' => $c['password'] !== '',
            'configure' => self::configured(),
            'fournisseurId' => self::fournisseurId(),
            'jetonValide' => self::jetonEnCache() !== null];
    }

    public static function oublierJeton(): void
    {
        self::$token = null; self::$cfg = null;
        try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['fournisseurToken']); } catch (Throwable $e) { /* rien */ }
    }

    /** Test de connexion — pour le bouton « Tester ». */
    public static function tester(): array
    {
        self::oublierJeton();
        self::$lastError = null;
        if (!self::configured()) { return [false, 'Renseignez l’identifiant et le mot de passe du compte fournisseur.']; }
        $fid = self::fournisseurId();
        if ($fid === null) { return [false, self::$lastError ?? 'connexion refusée']; }
        $cmds = self::get('/material-suppliers/' . $fid . '/orders');
        $n = is_array($cmds) ? count(analyseListe($cmds)) : 0;
        return [true, 'Connexion réussie — fournisseur #' . $fid . ', ' . $n . ' commande(s) lisible(s).'];
    }

    /** L'identifiant du fournisseur porté par le jeton (claim supplier_id). */
    public static function fournisseurId(): ?int
    {
        $tok = self::token();
        if ($tok === null) { return null; }
        $parts = explode('.', $tok);
        if (count($parts) < 2) { return null; }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $id = is_array($claims) ? (int) ($claims['supplier_id'] ?? 0) : 0;
        return $id > 0 ? $id : null;
    }

    private static function jetonEnCache(): ?string
    {
        $c = setting('fournisseurToken');
        if (!is_array($c) || empty($c['token'])) { return null; }
        return (int) ($c['exp'] ?? 0) > time() + 60 ? (string) $c['token'] : null;
    }

    private static function http(string $method, string $url, ?array $body = null, ?string $token = null): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($body !== null)  { $headers[] = 'Content-Type: application/json'; }
        if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { self::$lastError = 'API fournisseur injoignable : ' . $err; return [0, null]; }
        return [$code, json_decode((string) $raw, true)];
    }

    private static function token(bool $force = false): ?string
    {
        if (!$force) {
            if (self::$token !== null) { return self::$token; }
            $cached = self::jetonEnCache();
            if ($cached !== null) { return self::$token = $cached; }
        }
        if (!self::configured()) { self::$lastError = 'compte fournisseur non configuré'; return null; }
        $c = self::config();
        [$code, $res] = self::http('POST', $c['base'] . '/material-suppliers/auth/login',
            ['login' => $c['login'], 'password' => $c['password']]);
        $tok = is_array($res) && !empty($res['access_token']) ? (string) $res['access_token'] : null;
        if ($tok === null) {
            self::$lastError = 'connexion fournisseur refusée (HTTP ' . $code . ')'
                . (isset($res['description']) && is_string($res['description']) ? ' : ' . $res['description'] : '');
            return null;
        }
        $ttl = isset($res['expires_in']) && is_numeric($res['expires_in']) ? (int) $res['expires_in'] : 1800;
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['fournisseurToken', json_encode(['token' => $tok, 'exp' => time() + $ttl])]);
        return self::$token = $tok;
    }

    /** GET authentifié. Un 401 déclenche UNE reconnexion avant d'abandonner. */
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
            self::$lastError = 'GET ' . $path . ' → HTTP ' . $code . ($det !== '' ? ' : ' . $det : '');
            return null;
        }
        return $res['data'] ?? $res;
    }
}
