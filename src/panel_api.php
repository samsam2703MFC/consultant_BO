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

    /**
     * Plusieurs GET menés de front. Renvoie les corps décodés dans l'ordre des
     * chemins fournis (null pour ceux qui ont échoué).
     *
     * Certaines routes ne supportent pas qu'on élargisse leurs bornes :
     * `category-sales` répond en moins d'une seconde sur un mois et expire sur
     * un trimestre. Un trimestre se reconstitue donc en additionnant ses mois —
     * mais en série, douze mois font douze allers-retours et l'écran devient
     * inutilisable. Ces appels étant indépendants, ils partent ensemble : le
     * temps total redevient celui du plus lent, pas leur somme.
     *
     * Le jeton est obtenu AVANT la volée : sans cela douze requêtes se
     * heurteraient au même 401 et relanceraient douze connexions.
     */
    public static function getParallele(array $paths, int $front = 4): array
    {
        if (!$paths) { return []; }
        $tok = self::token();
        if ($tok === null) { return array_fill_keys(array_keys($paths), null); }
        // Quatre connexions de front, pas davantage : à douze, l'API amont a
        // laissé neuf requêtes sur douze sans réponse. Au-delà on n'accélère
        // plus, on fabrique des trous. Les paquets s'enchaînent.
        if (count($paths) > $front) {
            $out = [];
            foreach (array_chunk($paths, $front, true) as $lot) {
                $out += self::getParallele($lot, $front);
            }
            return $out;
        }
        $base = self::config()['base'];
        $multi = curl_multi_init();
        $hs = [];
        foreach ($paths as $k => $p) {
            $ch = curl_init($base . $p);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $tok],
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            curl_multi_add_handle($multi, $ch);
            $hs[$k] = $ch;
        }
        $en = null;
        do {
            $st = curl_multi_exec($multi, $en);
            if ($en) { curl_multi_select($multi, 1.0); }
        } while ($en > 0 && $st === CURLM_OK);

        $out = [];
        foreach ($hs as $k => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $res = is_string($raw) ? json_decode($raw, true) : null;
            if ($code < 200 || $code >= 300) {
                self::$lastError = 'GET ' . $paths[$k] . ' → HTTP ' . $code;
                $out[$k] = null;
                continue;
            }
            $out[$k] = $res['data'] ?? $res;
        }
        curl_multi_close($multi);
        return $out;
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
            // Un 422 n'est pas un mur : c'est l'API qui dit ce qu'elle attend.
            // Son message vaut mieux que dix essais de noms de paramètres, à
            // condition de le remonter — d'où la lecture des clés usuelles.
            $det = '';
            foreach (['description', 'message', 'detail', 'error'] as $k) {
                if (!empty($res[$k]) && is_string($res[$k])) { $det = $res[$k]; break; }
            }
            if ($det === '' && !empty($res['errors'])) {
                $det = json_encode($res['errors'], JSON_UNESCAPED_UNICODE);
            }
            if ($det === '' && is_array($res)) {
                $det = json_encode(array_slice($res, 0, 4), JSON_UNESCAPED_UNICODE);
            }
            self::$lastError = 'GET ' . $path . ' → HTTP ' . $code
                . ($det !== '' ? ' : ' . substr($det, 0, 300) : '');
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

    /**
     * Pertes (casse / invendus jetés) d'une boutique, par produit.
     *   GET /shops/{shop}/products/waste          — toutes les références
     *   GET /shops/{shop}/products/{prod}/waste   — une seule
     * Les bornes de période sont passées telles quelles si fournies ; l'API
     * décide de son propre défaut si elle n'en veut pas.
     */
    public static function shopWaste(int $shopId, ?string $from = null, ?string $to = null): array
    {
        if ($shopId <= 0) { return []; }
        $q = [];
        if ($from !== null) { $q['from'] = $from; $q['date_from'] = $from; }
        if ($to !== null)   { $q['to'] = $to;     $q['date_to'] = $to; }
        $path = '/shops/' . $shopId . '/products/waste' . ($q ? '?' . http_build_query($q) : '');
        $r = self::get($path);
        // Le volet `products` porte le détail par référence ; sans période,
        // l'API répond une enveloppe vide — d'où l'obligation de dater.
        if (is_array($r) && isset($r['products']) && is_array($r['products'])) { return $r['products']; }
        return is_array($r) ? self::liste($r) : [];
    }

    /**
     * Perte d'UNE référence dans UNE boutique — sert au détail réseau.
     * Endpoint dédié (/shops/{s}/products/{p}/waste) ; s'il ne répond pas la
     * forme attendue, on retombe sur la liste complète filtrée, plutôt que de
     * rendre un vide qui passerait pour « aucune perte ».
     */
    public static function shopProductWaste(int $shopId, int $productId, ?string $from = null, ?string $to = null): ?array
    {
        if ($shopId <= 0 || $productId <= 0) { return null; }
        $q = [];
        if ($from !== null) { $q['from'] = $from; $q['date_from'] = $from; }
        if ($to !== null)   { $q['to'] = $to;     $q['date_to'] = $to; }
        $qs = $q ? '?' . http_build_query($q) : '';
        $r = self::get('/shops/' . $shopId . '/products/' . $productId . '/waste' . $qs);
        if (is_array($r)) {
            if (isset($r['products']) && is_array($r['products']) && $r['products']) { return $r['products'][0]; }
            if (isset($r['waste_qty']) || isset($r['sold_qty'])) { return $r; }
            if (isset($r['product']) && is_array($r['product'])) { return $r['product']; }
        }
        foreach (self::shopWaste($shopId, $from, $to) as $w) {
            if ((int) ($w['id_product'] ?? 0) === $productId) { return $w; }
        }
        return null;
    }

    /**
     * Planning d'une boutique : un créneau par ligne (work_date, start_hour,
     * end_hour). UN appel par boutique — l'endpoint par employé existe aussi
     * mais demanderait une requête par personne.
     *
     * On n'en retient que des AGRÉGATS d'heures : le flux porte des données
     * personnelles (nom, téléphone) qui n'ont rien à faire dans le cockpit.
     */
    public static function shopSchedule(int $shopId): array
    {
        if ($shopId <= 0) { return []; }
        $r = self::get('/shops/' . $shopId . '/schedule');
        return is_array($r) ? self::liste($r) : [];
    }

    /** Réponse brute d'un chemin — sonde de diagnostic, pas un usage courant. */
    public static function brut(string $path): mixed
    {
        return self::get($path);
    }

    /** Catégories produit du réseau (/product-categories). */
    /** Chemin qui a effectivement répondu au dernier appel multi-variantes. */
    public static ?string $lastPath = null;

    /**
     * Premier chemin qui rend une liste non vide, parmi plusieurs écritures
     * possibles. Le panel n'expose pas partout la même convention
     * (/product-categories vs /product/categories) et deviner en dur ferait
     * dépendre un écran d'un tiret.
     */
    private static function premierNonVide(array $paths): array
    {
        $err = null;
        foreach ($paths as $p) {
            $r = self::get($p);
            $l = is_array($r) ? self::liste($r) : [];
            if ($l !== []) { self::$lastPath = $p; return $l; }
            $err = $err ?? self::$lastError;
        }
        self::$lastPath = null;
        self::$lastError = $err ?? self::$lastError;
        return [];
    }

    /** Catégories produit du réseau. */
    public static function productCategories(): array
    {
        return self::premierNonVide(['/product/categories', '/product-categories']);
    }

    /** Groupes de catégories du réseau. */
    public static function productCategoryGroups(): array
    {
        return self::premierNonVide(['/product/category-groups', '/product-category-groups']);
    }

    /**
     * Premier chemin qui rend une réponse exploitable — objet OU liste.
     * `premierNonVide()` ne convient pas ici : ces endpoints rendent des
     * objets (un P&L, un jeu d'indicateurs), pas des collections.
     */
    /** Variantes tombées avant celle qui a répondu — diagnostic d'un repli. */
    public static array $lastFallbacks = [];

    private static function premierObjet(array $paths): ?array
    {
        $essais = [];
        self::$lastFallbacks = [];
        foreach ($paths as $p) {
            $r = self::get($p);
            if (is_array($r) && $r !== []) {
                self::$lastPath = $p;
                // Un repli réussi masque l'échec de la route dédiée : sans
                // cette trace, on croit lire la bonne source alors qu'on lit la
                // suivante. C'est exactement ce qui a caché le mauvais mois.
                self::$lastFallbacks = $essais;
                return $r;
            }
            // On garde la trace de CHAQUE variante tentée : quand rien ne
            // répond, la seule information utile est la liste de ce qui a été
            // essayé et ce que chacun a renvoyé.
            $e = (string) self::$lastError;
            $essais[] = (strlen($p) > 120 ? substr($p, 0, 120) . '…' : $p) . ' → '
                . (preg_match('/HTTP (\d+)(.*)$/s', $e, $m) ? $m[1] . substr($m[2], 0, 140) : 'vide');
        }
        self::$lastPath = null;
        self::$lastError = 'aucune variante ne répond — ' . implode(' · ', $essais);
        return null;
    }

    /**
     * Convention de période du panel : `date_from` / `date_to`.
     *
     * L'API l'a dit elle-même — « Invalid date_from/date_to range » — après
     * que j'aie essayé `period`+`date` sur la foi de son code. Deux bornes,
     * rien d'autre : ajouter `from`/`to` en doublon brouillait la lecture et
     * faisait retomber /pnl sur la période courante.
     */
    private static function bornes(string $periode, string $date): array
    {
        $ts = strtotime($date);
        $du = $periode === 'jour' ? $date
            : ($periode === 'semaine' ? date('Y-m-d', strtotime('monday this week', $ts))
                                      : date('Y-m-01', $ts));
        return ['date_from' => $du, 'date_to' => $date];
    }

    private static function q(string $periode, string $date, array $extra = []): string
    {
        return http_build_query($extra + self::bornes($periode, $date));
    }

    /** Indicateurs de vente d'une boutique (CA, tickets, panier, produits/client). */
    public static function salesKpis(int $shopId, string $periode, string $date): ?array
    {
        $q = self::q($periode, $date);
        return self::premierObjet([
            '/consultant/shops/' . $shopId . '/statistics/sales/kpis?' . $q,
            '/shops/' . $shopId . '/statistics/sales/kpis?' . $q,
        ]);
    }

    /**
     * Indicateurs de TOUTES les boutiques en un appel.
     * Sert le positionnement réseau : un appel au lieu d'un par boutique, et
     * surtout des chiffres calculés de la même façon pour tout le monde.
     */
    /**
     * Indicateurs de toutes les boutiques sur des bornes explicites.
     * Sert la comparaison N-1 : on repose la MÊME question sur l'exercice
     * précédent, plutôt que de déduire l'écart d'une source qui ne compterait
     * pas de la même façon.
     */
    public static function shopsSalesKpisEntre(string $du, string $au): ?array
    {
        return self::premierObjet([
            '/consultant/shops/sales-kpis?' . http_build_query(['date_from' => $du, 'date_to' => $au]),
        ]);
    }

    /** Liste des boutiques avec leurs intitulés — /consultant/shops. */
    public static function consultantShops(): ?array
    {
        return self::premierObjet(['/consultant/shops']);
    }

    public static function shopsSalesKpis(string $periode, string $date): ?array
    {
        $q = self::q($periode, $date);
        return self::premierObjet([
            '/consultant/shops/sales-kpis?' . $q,
            '/consultant/shops/sales-kpis',
        ]);
    }

    /**
     * Compte de résultat d'une boutique.
     *
     * Le panel expose une route PAR granularité — /pnl/daily, /pnl/monthly —
     * en plus de la route générique. C'est la route dédiée qui est essayée
     * d'abord : la générique ignorait la date transmise et rendait toujours la
     * période courante, ce qui donnait des chiffres justes sur la mauvaise
     * période.
     */
    public static function pnl(int $shopId, string $periode, string $date): ?array
    {
        $base = '/consultant/shops/' . $shopId . '/pnl';
        $chemins = [];
        if ($periode === 'mois') {
            // « Invalid monthly P&L range (maximum 24 months) » : cette route
            // raisonne en MOIS ENTIERS. Lui passer le 1er → 14 juillet, un mois
            // tronqué, la faisait échouer — et le repli silencieux sur la route
            // générique rendait alors la journée courante.
            // « maximum 24 months » : la borne se compte en MOIS. Des dates
            // complètes, même alignées sur le mois, restent refusées — on
            // essaie donc l'identifiant de mois, puis l'année et le mois.
            $chemins[] = $base . '/monthly?' . http_build_query([
                'date_from' => date('Y-m', strtotime($date)),
                'date_to'   => date('Y-m', strtotime($date)),
            ]);
            $chemins[] = $base . '/monthly?' . http_build_query([
                'year' => (int) date('Y', strtotime($date)), 'month' => (int) date('n', strtotime($date)),
            ]);
            $chemins[] = $base . '/monthly?' . http_build_query([
                'date_from' => date('Y-m-01', strtotime($date)),
                'date_to'   => date('Y-m-t', strtotime($date)),
            ]);
        } elseif ($periode === 'jour') {
            $chemins[] = $base . '/daily?' . http_build_query(['date_from' => $date, 'date_to' => $date]);
        }
        $chemins[] = $base . '?' . self::q($periode, $date);
        return self::premierObjet($chemins);
    }

    /**
     * Ventes par catégorie — la ventilation colorée par niveau de marge.
     * C'est cet endpoint qui doit porter le food cost, absent du /pnl.
     */
    public static function categorySales(int $shopId, string $periode, string $date): ?array
    {
        $q = self::q($periode, $date, ['shop_id' => $shopId]);
        return self::premierObjet([
            '/consultant/shops/category-sales?' . $q,
            '/consultant/shops/' . $shopId . '/category-sales?' . self::q($periode, $date),
        ]);
    }

    /**
     * Ventes mensuelles du réseau — la série qui alimente le graphique
     * budget/réel de l'écran Exploitation, à la source du panel plutôt que
     * recalculée de notre côté.
     */
    public static function monthlySales(string $du, string $au): ?array
    {
        // Les routes « monthly » du panel raisonnent en mois PLEINS : une borne
        // haute au 16 du mois est refusée (« Invalid monthly sales range »).
        // On aligne donc sur le premier et le dernier jour du mois.
        $d1 = date('Y-m-01', strtotime($du));
        $d2 = date('Y-m-t', strtotime($au));
        return self::premierObjet([
            '/consultant/shops/monthly-sales?' . http_build_query(['date_from' => $d1, 'date_to' => $d2]),
            '/consultant/shops/monthly-sales?' . http_build_query(['date_from' => $du, 'date_to' => $au]),
        ]);
    }

    /** Catalogue produit du panel — candidat pour l'analyse par référence. */
    public static function produits(): ?array
    {
        return self::premierObjet(['/products', '/consultant/products']);
    }

    /** Indicateurs trimestriels du réseau. */
    public static function salesKpisQuarterly(string $du, string $au): ?array
    {
        $q = http_build_query(['date_from' => $du, 'date_to' => $au]);
        return self::premierObjet([
            '/consultant/shops/sales-kpis/quarterly?' . $q,
            '/consultant/shops/sales-kpis/quarterly',
        ]);
    }

    /** Ventes par catégorie sur des bornes explicites (réseau). */
    public static function categorySalesEntre(string $du, string $au): ?array
    {
        // Sans `shop_id`, cette route ne répond pas (mesuré : code 0). Avec, elle
        // rend TOUTES les boutiques — le paramètre sert d'autorisation, pas de
        // filtre, et la réponse reste bien celle du réseau.
        $q = http_build_query(['shop_id' => 2, 'date_from' => $du, 'date_to' => $au]);
        return self::premierObjet(['/consultant/shops/category-sales?' . $q]);
    }

    /** Synthèse P&L de toutes les boutiques. */
    public static function pnlSummary(string $periode, string $date): ?array
    {
        return self::premierObjet(['/consultant/shops/pnl-summary?' . self::q($periode, $date)]);
    }

    /** Heatmap de marge d'une boutique. */
    public static function marginHeatmap(int $shopId, string $periode, string $date): ?array
    {
        return self::premierObjet([
            '/consultant/shops/' . $shopId . '/margin-heatmap?' . self::q($periode, $date),
        ]);
    }

    /** Gammes saisonnières du réseau. */
    public static function availabilityPeriods(): array
    {
        return self::premierNonVide([
            '/product-availability-periods',
            '/product/availability-periods',
        ]);
    }

    /**
     * Intitulés traduits des gammes saisonnières.
     * Le réseau est bilingue : la table d'alias de la base est vide, l'API
     * est donc la seule source connue pour le néerlandais.
     */
    public static function periodNameAliases(): array
    {
        return self::premierNonVide([
            '/product-availability-periods/name-aliases',
            '/product/availability-periods/name-aliases',
        ]);
    }

    /** Références rattachées à une gamme saisonnière. */
    public static function periodProducts(int $periodId): array
    {
        if ($periodId <= 0) { return []; }
        return self::premierNonVide([
            '/product-availability-periods/' . $periodId . '/products',
            '/product/availability-periods/' . $periodId . '/products',
        ]);
    }

    /** Références rattachées à une catégorie. */
    public static function categoryProducts(int $categoryId): array
    {
        if ($categoryId <= 0) { return []; }
        return self::premierNonVide([
            '/product-categories/' . $categoryId . '/products',
            '/product/categories/' . $categoryId . '/products',
        ]);
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
    public static function liste(array $d): array
    {
        if (array_is_list($d)) { return array_values(array_filter($d, 'is_array')); }
        foreach (['data', 'items', 'tasks', 'results', 'rows', 'content', 'checklists', 'products', 'grouped_products'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) {
                $sous = self::liste($d[$k]);
                if ($sous !== []) { return $sous; }
            }
        }
        return [];
    }
}
