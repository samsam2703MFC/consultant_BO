<?php
declare(strict_types=1);

/**
 * Client de l'API amont du panel consultant (`/api/v1`).
 *
 * Les checklists — noms des tâches, photos de réalisation, notation — ne sont
 * PAS dans la base partagée : la base ne garde que l'identifiant de tâche, un
 * identifiant de pièce jointe et le journal des avis (`mac_task_review`). Le
 * panel les lit sur cette API ; le cockpit fait exactement pareil, pour montrer
 * les mêmes données.
 *
 * Authentification : POST /consultant/auth/login {phone, password} → JWT, envoyé
 * ensuite en `Authorization: Bearer`. Le jeton est mis en cache dans
 * `ceo_app_setting` (clé `panelApiToken`) et renouvelé automatiquement sur 401.
 *
 * Configuration (config/config.php, jamais dans le dépôt) :
 *   'panelApi' => ['base' => 'https://…/api/v1', 'phone' => '…', 'password' => '…']
 * Repli sur les variables d'environnement PANEL_API_BASE / _PHONE / _PASSWORD.
 * Sans identifiants, `configured()` est faux et les écrans se replient
 * proprement sur ce que contient la base partagée.
 */
final class PanelApi
{
    /** Dernière erreur rencontrée — affichée à l'écran plutôt qu'un vide muet. */
    public static ?string $lastError = null;

    private static ?array $cfg = null;
    private static ?string $token = null;

    public static function config(): array
    {
        if (self::$cfg === null) {
            // Priorité : réglage saisi dans Paramètres (compte consultant), puis
            // config/config.php, puis variables d'environnement. Le réglage
            // permet de brancher le compte sans toucher au serveur.
            $s = setting('panelApi', []);
            if (!is_array($s)) { $s = []; }
            $c = Db::config()['panelApi'] ?? [];
            $env = static fn (string $k): string => (string) (getenv($k) ?: '');
            $val = static function (string $k, array $s, array $c, string $envKey, string $def = '') use ($env): string {
                foreach ([$s[$k] ?? null, $c[$k] ?? null] as $v) {
                    if (is_string($v) && trim($v) !== '') { return trim($v); }
                }
                $e = $env($envKey);
                return $e !== '' ? $e : $def;
            };
            self::$cfg = [
                'base'     => rtrim($val('base', $s, $c, 'PANEL_API_BASE', 'https://atelierby.tfbuddy.com/api/v1'), '/'),
                'phone'    => $val('phone', $s, $c, 'PANEL_API_PHONE'),
                'password' => $val('password', $s, $c, 'PANEL_API_PASSWORD'),
            ];
        }
        return self::$cfg;
    }

    /** État pour l'écran Paramètres — JAMAIS le mot de passe, seulement s'il existe. */
    public static function statut(): array
    {
        $c = self::config();
        return ['base' => $c['base'], 'phone' => $c['phone'],
            'motDePasseDefini' => $c['password'] !== '', 'configure' => self::configured()];
    }

    /** Vide le jeton en cache (après changement d'identifiants). */
    public static function oublierJeton(): void
    {
        self::$token = null; self::$cfg = null;
        try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['panelApiToken']); } catch (Throwable $e) { /* rien */ }
    }

    /** Test de connexion : renvoie [ok, message] — pour le bouton « Tester ». */
    public static function tester(): array
    {
        self::oublierJeton();
        self::$lastError = null;
        if (!self::configured()) { return [false, 'Renseignez le téléphone et le mot de passe du compte.']; }
        $tok = self::token(true);
        if ($tok === null) { return [false, self::$lastError ?? 'connexion refusée']; }
        return [true, 'Connexion réussie — le cockpit peut lire les tâches du panel.'];
    }

    /** Vrai si des identifiants sont configurés (sinon : mode base partagée seule). */
    public static function configured(): bool
    {
        $c = self::config();
        return $c['phone'] !== '' && $c['password'] !== '';
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
            CURLOPT_TIMEOUT        => 12,
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
            self::$lastError = 'API injoignable : ' . $err;
            return [0, null];
        }
        return [$code, json_decode((string) $raw, true)];
    }

    /** Jeton en cache, sinon connexion. `$force` ignore le cache (retry sur 401). */
    private static function token(bool $force = false): ?string
    {
        if (!$force) {
            if (self::$token !== null) { return self::$token; }
            $cached = setting('panelApiToken');
            if (is_array($cached) && !empty($cached['token']) && ($cached['exp'] ?? 0) > time()) {
                return self::$token = (string) $cached['token'];
            }
        }
        if (!self::configured()) { self::$lastError = 'identifiants API du panel non configurés'; return null; }
        $c = self::config();
        [$code, $res] = self::http('POST', $c['base'] . '/consultant/auth/login',
            ['phone' => $c['phone'], 'password' => $c['password']]);
        // La forme exacte varie (access_token / token / data.access_token) :
        // on prend la première clé présente plutôt que d'en supposer une.
        $tok = null;
        foreach ([$res['access_token'] ?? null, $res['token'] ?? null,
                  $res['data']['access_token'] ?? null, $res['data']['token'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') { $tok = $cand; break; }
        }
        if ($tok === null) {
            self::$lastError = 'connexion API refusée (HTTP ' . $code . ')'
                . (isset($res['description']) ? ' : ' . $res['description'] : '');
            return null;
        }
        // Cache court (30 min) : le JWT expire, on ne s'y accroche pas.
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['panelApiToken', json_encode(['token' => $tok, 'exp' => time() + 1800])]);
        return self::$token = $tok;
    }

    /**
     * GET authentifié sur l'API du panel. Renvoie le corps décodé, ou null.
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
            self::$lastError = 'GET ' . $path . ' → HTTP ' . $code
                . (isset($res['description']) ? ' : ' . $res['description'] : '');
            return null;
        }
        return $res['data'] ?? $res;
    }

    /** POST authentifié. Renvoie [ok, corps]. */
    public static function post(string $path, array $body): array
    {
        $tok = self::token();
        if ($tok === null) { return [false, null]; }
        $url = self::config()['base'] . $path;
        [$code, $res] = self::http('POST', $url, $body, $tok);
        if ($code === 401) {
            $tok = self::token(true);
            if ($tok === null) { return [false, null]; }
            [$code, $res] = self::http('POST', $url, $body, $tok);
        }
        if ($code < 200 || $code >= 300) {
            self::$lastError = 'POST ' . $path . ' → HTTP ' . $code
                . (isset($res['description']) ? ' : ' . $res['description'] : '');
            return [false, $res];
        }
        return [true, $res];
    }

    /**
     * URL signée d'une pièce jointe (photo de réalisation d'une tâche).
     * L'API ne renvoie qu'un identifiant numérique ; l'image elle-même vit
     * derrière une URL signée à durée limitée, obtenue ici.
     */
    public static function attachmentUrl(int $attachmentId): ?string
    {
        if ($attachmentId <= 0) { return null; }
        $r = self::get('/attachments/' . $attachmentId . '/presigned-url');
        if (is_string($r)) { return $r; }
        foreach (['url', 'presigned_url', 'presignedUrl', 'link'] as $k) {
            if (is_array($r) && isset($r[$k]) && is_string($r[$k])) { return $r[$k]; }
        }
        return null;
    }

    /** Tâches d'une boutique pour une journée (porte le NOM de la tâche). */
    public static function shopTasks(int $shopId, string $date): array
    {
        $r = self::get('/consultant/shops/' . $shopId . '/tasks?date=' . urlencode($date));
        return is_array($r) ? self::liste($r) : [];
    }

    /** Checklists d'une boutique pour une journée. */
    public static function shopChecklists(int $shopId, string $date): array
    {
        $r = self::get('/consultant/shops/' . $shopId . '/checklists?date=' . urlencode($date));
        return is_array($r) ? self::liste($r) : [];
    }

    /**
     * Avancement d'une checklist : c'est CE flux qui porte `completion_id`,
     * `attachment_id` (la photo) et les champs d'avis — pas la liste des tâches.
     */
    public static function checklistProgress(int $shopId, int $checklistId, string $date): array
    {
        $r = self::get('/consultant/shops/' . $shopId . '/checklists/' . $checklistId . '/progress?date=' . urlencode($date));
        return is_array($r) ? self::liste($r) : [];
    }

    /** Dépose un avis (note + conformité + commentaire) — source de vérité. */
    public static function submitReview(int $shopId, array $data): array
    {
        return self::post('/consultant/shops/' . $shopId . '/task-reviews', $data);
    }

    /** Catalogue produits, lu une fois par requête (sert aux photos de référence). */
    private static ?array $catalogue = null;

    /**
     * Photo de la fiche technique d'un produit — à mettre en face de la photo
     * prise en boutique : un contrôle qualité se juge par comparaison.
     *
     * UNIQUEMENT PAR IDENTIFIANT, comme le panel : rapprocher sur l'intitulé
     * ferait refuser un produit correct avec l'air d'une preuve.
     *
     * @return array{nom:string, url:?string}|null
     */
    public static function productPhoto(int $productId): ?array
    {
        if ($productId <= 0) { return null; }
        if (self::$catalogue === null) {
            $r = self::get('/products');
            self::$catalogue = is_array($r) ? self::liste($r) : [];
        }
        foreach (self::$catalogue as $p) {
            $pid = null;
            foreach (['id', 'product_id', 'id_product'] as $k) {
                if (isset($p[$k]) && is_numeric($p[$k])) { $pid = (int) $p[$k]; break; }
            }
            if ($pid !== $productId) { continue; }
            $nom = '';
            foreach (['name', 'product_name', 'label', 'title', 'nom', 'designation'] as $k) {
                if (!empty($p[$k]) && is_string($p[$k])) { $nom = trim($p[$k]); break; }
            }
            // L'image est soit une URL directe, soit une pièce jointe à signer.
            $url = null;
            foreach (['url', 'image_url', 'photo_url', 'picture', 'image', 'thumbnail'] as $k) {
                if (!empty($p[$k]) && is_string($p[$k])) { $url = $p[$k]; break; }
            }
            if ($url === null) {
                foreach (['attachment_id', 'att', 'id_attachment', 'photo_id'] as $k) {
                    if (!empty($p[$k]) && is_numeric($p[$k])) { $url = self::attachmentUrl((int) $p[$k]); break; }
                }
            }
            return ['nom' => $nom !== '' ? $nom : ('Produit #' . $productId), 'url' => $url];
        }
        return null;
    }

    /** Déballe une enveloppe API ({data:…}, {items:…}, liste nue…). */
    private static function liste(array $d): array
    {
        if (array_is_list($d)) { return array_values(array_filter($d, 'is_array')); }
        foreach (['data', 'items', 'tasks', 'results', 'rows', 'content', 'checklists'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) {
                $sous = self::liste($d[$k]);
                if ($sous !== []) { return $sous; }
            }
        }
        return [];
    }
}
