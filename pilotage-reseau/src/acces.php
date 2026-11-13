<?php
declare(strict_types=1);

/**
 * L'accès à l'API du cockpit (13/11/2026, Sam : « sécurise l'API ») — fermée à qui ne s'est pas connecté.
 *
 * Jusqu'ici l'API répondait à tout le monde (`auth` => false : l'accès passait par un lien depuis l'ERP). Elle
 * demande désormais l'une de ces identités (choix de Sam, 13/11/2026 : la console franchisé n'est pas encore
 * installée sur le serveur, ses comptes s'ajouteront d'eux-mêmes quand elle le sera) :
 *   - le SIÈGE : le mot de passe du cockpit (src/auth.php, cookie signé cockpit_s, 30 jours). Il se crée, ou se
 *     réinitialise, en prouvant qu'on tient le compte ERP du cockpit (« Mon compte » : téléphone et mot de passe
 *     du compte admin ou du compte consultant, comparés à ceux que le serveur garde ; rien n'est affiché) ;
 *   - un MAGASIN : le code de ce magasin, que le siège génère depuis la page de connexion (haché dans
 *     ceo_app_setting.accesCodes ; cookie signé cockpit_m, 90 jours ; régénérer le code ferme les sessions) ;
 *   - un compte de la console franchisé (dépôt WebShop, comptes bo_users, comme la page RH), quand elle répond :
 *     ses cookies de session sont relayés à {console}/bo/franchisee/me puis /bo/franchisor/me, la réponse gardée
 *     60 secondes (dossier temporaire, par empreinte du cookie).
 * Le siège ouvre tout. Un magasin (ou un franchisé) n'ouvre que les écrans des magasins (ACCES_FRANCHISE) et que
 * SES magasins : tout paramètre de magasin d'une requête (shop, shopId, magasin…) doit en faire partie, sinon 403.
 *
 * Restent ouvertes sans session, comme avant, les routes qui ont leur propre clé (index.php) : suivi des newsletters,
 * flux ICS, webhooks Meta, app employé, borne et caisse de pointage, page de suivi du franchisé, tablettes SOP,
 * routes d'intégration des autres portails (X-Service-Token) ; les horloges (…/cron?jeton=) ; la recette de chaque
 * livraison (clé X-Recette tirée par bin/deploy.sh).
 *
 * Réglage `acces` de config.php : « console » (connexion exigée) ou « ouvert » (aucune connexion). En ligne, bin/deploy.sh
 * pose « ouvert » depuis le 13/11/2026 (Sam : « enlève la connexion, laisse ouvert ») ; COCKPIT_ACCES=console referme.
 * Absent : fermé.
 */

const ACCES_COOKIES = ['franchisee' => 'fb_franchisee_session', 'franchisor' => 'fb_franchisor_session'];
const ACCES_TTL = 60;
const ACCES_COOKIE_MAGASIN = 'cockpit_m';
const ACCES_TTL_MAGASIN = 7776000;   // 90 jours

/**
 * Les écrans d'un franchisé : le premier segment des routes que lisent le dashboard du magasin, la caisse, la
 * production, le planogramme et la tablette des vendeuses (public/dashboard, caisse, production, planogramme,
 * resultat-mobile, tablette).
 * Tout le reste (réglages, réseau, marketing, visites, primes du réseau, documents des franchisés…) est au siège.
 */
const ACCES_FRANCHISE = ['/exploitation', '/ventes', '/fidelite', '/analyse', '/fournisseurs', '/push',
    '/production', '/soir', '/assistant/magasin', '/stores', '/benchmarks/magasin', '/benchmarks/releve', '/plan',
    '/leviers', '/equipe', '/caisse', '/planogramme', '/pwa', '/formation/sop/photo', '/uploads', '/dashboard', '/tablette'];

/** Les paramètres qui désignent un magasin, dans l'adresse ou dans le corps JSON. */
const ACCES_CLES_MAGASIN = ['shop', 'shops', 'shopId', 'shop_id', 'magasin', 'magasins', 'magasinId'];

function accesMode(): string
{
    $v = getenv('COCKPIT_ACCES');
    if (!is_string($v) || $v === '') { $v = (string) (Db::config()['acces'] ?? 'console'); }
    return $v === 'ouvert' ? 'ouvert' : 'console';
}

/** L'adresse interne de l'API de la console (serveur à serveur), celle de la page RH. */
function accesConsoleInterne(): ?string
{
    $v = getenv('HR_CONSOLE_API_INTERNAL');
    if (!is_string($v) || $v === '') { $v = (string) (Db::config()['hr']['consoleApiInternal'] ?? ''); }
    return $v !== '' ? rtrim($v, '/') : null;
}

/** L'adresse de l'API de la console vue du navigateur (la page de connexion y envoie le formulaire). */
function accesConsolePublique(): ?string
{
    $v = getenv('HR_CONSOLE_API_BASE');
    if (!is_string($v) || $v === '') { $v = (string) (Db::config()['hr']['consoleApi'] ?? ''); }
    return $v !== '' ? rtrim($v, '/') : null;
}

/** Les seuls cookies de session de la console, tels que le navigateur les a envoyés (rien d'autre n'est relayé). */
function accesCookies(): string
{
    $out = [];
    foreach (ACCES_COOKIES as $nom) {
        $v = $_COOKIE[$nom] ?? null;
        if (is_string($v) && $v !== '' && strlen($v) < 4096) { $out[] = $nom . '=' . rawurlencode($v); }
    }
    return implode('; ', $out);
}

/** Un GET à la console : [code HTTP, JSON décodé ou null]. Code 0 : la console ne répond pas. */
function accesConsoleGet(string $url, string $cookie): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($cookie !== '') { $h[] = 'Cookie: ' . $cookie; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false]);
    $corps = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($corps)) { return [0, null]; }
    $j = json_decode($corps, true);
    return [$code, is_array($j) ? $j : null];
}

function accesCacheFichier(string $cookie): string
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/cockpit_acces';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir . '/' . hash('sha256', $cookie) . '.json';
}

/**
 * Qui appelle : ['role' => 'siege'|'franchise', 'source' => 'motdepasse'|'code'|'console', 'nom' => …,
 * 'shops' => list<string>|null (null = tout le réseau), 'bo' => …, 'csrf' => …] ou null (personne).
 */
function accesIdentite(): ?array
{
    static $memo = false;
    if ($memo !== false) { return $memo; }
    if (authOk()) { return $memo = ['role' => 'siege', 'source' => 'motdepasse', 'nom' => 'Siège', 'shops' => null, 'bo' => null, 'csrf' => null]; }
    if (($shop = accesMagasinConnecte()) !== null) {
        return $memo = ['role' => 'franchise', 'source' => 'code', 'nom' => accesNomMagasin($shop), 'shops' => [$shop], 'bo' => null, 'csrf' => null];
    }
    return $memo = accesIdentiteConsole();
}

/** L'identité de la console franchisé, si ses cookies sont là et qu'elle répond (sinon null, sans erreur). */
function accesIdentiteConsole(): ?array
{
    $cookie = accesCookies();
    $base = accesConsoleInterne();
    if ($cookie === '' || $base === null) { return null; }
    $f = accesCacheFichier($cookie);
    if (is_file($f) && filemtime($f) > time() - ACCES_TTL) {
        $c = json_decode((string) @file_get_contents($f), true);
        if (is_array($c) && array_key_exists('qui', $c)) { return is_array($c['qui']) ? $c['qui'] : null; }
    }
    $id = null; $muette = false;
    foreach (['franchisee' => 'franchise', 'franchisor' => 'siege'] as $bo => $role) {
        if (!isset($_COOKIE[ACCES_COOKIES[$bo]])) { continue; }
        [$code, $j] = accesConsoleGet($base . '/bo/' . $bo . '/me', $cookie);
        if ($code === 0) { $muette = true; continue; }
        if ($code === 200 && is_array($j['user'] ?? null)) {
            $u = $j['user'];
            $shops = $role === 'franchise' ? array_values(array_map('strval', (array) ($u['shops'] ?? []))) : null;
            $id = ['role' => $role, 'source' => 'console', 'bo' => $bo,
                'nom' => trim((string) ($u['display_name'] ?? '')) ?: 'Compte ' . (int) ($u['id'] ?? 0),
                'shops' => $shops, 'csrf' => is_string($j['csrf'] ?? null) ? $j['csrf'] : null];
            break;
        }
    }
    if ($id === null && $muette) { return null; }   // la console se tait : rien de gardé, on redemandera
    @file_put_contents($f, json_encode(['qui' => $id], JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0600);
    return $id;
}

/** La console franchisé a-t-elle ses comptes (GET /bo/franchisee/me sans cookie → 401) ? Gardé 5 minutes. */
function accesConsoleDispo(): bool
{
    $base = accesConsoleInterne();
    if ($base === null) { return false; }
    $f = accesCacheFichier('dispo|' . $base);
    if (is_file($f) && filemtime($f) > time() - 300) { return trim((string) @file_get_contents($f)) === '1'; }
    [$code] = accesConsoleGet($base . '/bo/franchisee/me', '');
    $ok = $code === 401;
    @file_put_contents($f, $ok ? '1' : '0', LOCK_EX);
    return $ok;
}

/* --- Les magasins : un code par magasin ------------------------------------------------------------------------- */

/** Les magasins ouverts du réseau : [id => nom]. */
function accesMagasins(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = [];
    try {
        foreach (ep_stores() as $s) {
            $statut = (string) ($s['status'] ?? $s['statut'] ?? '');
            if ($statut !== '' && !preg_match('/ouvert/i', $statut)) { continue; }
            $m[(string) $s['id']] = (string) ($s['nom'] ?? $s['name'] ?? ('Magasin ' . $s['id']));
        }
    } catch (Throwable $e) { /* sans liste, aucun magasin ne se connecte */ }
    return $m;
}

function accesNomMagasin(string $shop): string { return accesMagasins()[$shop] ?? ('Magasin ' . $shop); }

/** Les codes : [shop => ['hash' => …, 'gen' => n, 'le' => 'Y-m-d H:i']]. */
function accesCodes(): array
{
    $c = setting('accesCodes', []);
    return is_array($c) ? $c : [];
}

function accesSigne(string $quoi): string { return hash_hmac('sha256', $quoi, authSecret()); }

/** Le magasin de la session (cookie cockpit_m signé, code toujours valide), ou null. */
function accesMagasinConnecte(): ?string
{
    $c = (string) ($_COOKIE[ACCES_COOKIE_MAGASIN] ?? '');
    if (!preg_match('/^(\d{1,6})\.(\d{1,4})\.(\d{10})\.([a-f0-9]{64})$/', $c, $m) || authSecret() === '') { return null; }
    [, $shop, $gen, $exp, $sig] = $m;
    if ((int) $exp < time() || !hash_equals(accesSigne("m|$shop|$gen|$exp"), $sig)) { return null; }
    $code = accesCodes()[$shop] ?? null;
    return is_array($code) && !empty($code['hash']) && (int) ($code['gen'] ?? -1) === (int) $gen ? $shop : null;
}

function accesCookie(string $nom, string $valeur, int $exp): void
{
    setcookie($nom, $valeur, ['expires' => $exp, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')]);
}

/** Un code lisible au comptoir : 8 caractères sans 0/O ni 1/I/L, en deux groupes (« K7PM-4QXT »). */
function accesNouveauCode(): string
{
    $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 8; $i++) { $c .= $a[random_int(0, strlen($a) - 1)]; }
    return substr($c, 0, 4) . '-' . substr($c, 4);
}

function accesNormaliserCode(string $c): string { return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $c)); }

/* --- Le frein : 8 essais ratés en 15 minutes par clé, puis refus ------------------------------------------------ */

function accesFreine(string $cle): bool
{
    $e = setting('accesEchecs', []);
    $l = is_array($e[$cle] ?? null) ? array_filter($e[$cle], static fn ($t) => (int) $t > time() - 900) : [];
    return count($l) >= 8;
}

function accesEchec(string $cle): void
{
    $e = setting('accesEchecs', []);
    $e = is_array($e) ? $e : [];
    foreach ($e as $k => $l) { $e[$k] = array_values(array_filter((array) $l, static fn ($t) => (int) $t > time() - 900)); if (!$e[$k]) { unset($e[$k]); } }
    $e[$cle][] = time();
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['accesEchecs', json_encode($e)]);
    usleep(600000);
}

/**
 * La preuve du siège pour créer ou réinitialiser le mot de passe : le téléphone et le mot de passe du compte ERP du
 * cockpit (compte admin, src/erp_api.php, ou compte consultant, src/panel_api.php), tels que le serveur les garde.
 */
function accesPreuveErp(string $tel, string $mdp): bool
{
    $chiffres = static fn (string $t): string => (string) preg_replace('/\D/', '', $t);
    $t = $chiffres($tel);
    if ($t === '' || $mdp === '') { return false; }
    $ok = false;
    foreach ([class_exists('ErpApi') ? ErpApi::config() : [], class_exists('PanelApi') ? PanelApi::config() : []] as $c) {
        $ct = $chiffres((string) ($c['phone'] ?? ''));
        $cm = (string) ($c['password'] ?? '');
        if ($ct !== '' && $cm !== '' && hash_equals($ct, $t) & hash_equals($cm, $mdp)) { $ok = true; }
    }
    return $ok;
}

/** Oublie l'identité gardée pour ces cookies (déconnexion). */
function accesOublier(): void
{
    $cookie = accesCookies();
    if ($cookie !== '') { @unlink(accesCacheFichier($cookie)); }
}

/** Les magasins nommés par la requête (adresse et corps JSON), en chaînes. */
function accesMagasinsDemandes(): array
{
    $vals = [];
    $prendre = static function ($v) use (&$vals, &$prendre): void {
        if (is_array($v)) { foreach ($v as $x) { $prendre(is_array($x) ? ($x['id'] ?? $x['shop'] ?? null) : $x); } return; }
        if (is_int($v) || (is_string($v) && $v !== '')) {
            foreach (preg_split('/[\s,;]+/', (string) $v) ?: [] as $p) { if ($p !== '') { $vals[] = $p; } }
        }
    };
    $corps = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? body() : [];
    foreach (ACCES_CLES_MAGASIN as $k) {
        if (isset($_GET[$k])) { $prendre($_GET[$k]); }
        if (isset($corps[$k])) { $prendre($corps[$k]); }
    }
    return array_values(array_unique($vals));
}

/** La route est-elle un écran de magasin (ACCES_FRANCHISE) ? */
function accesRouteFranchise(string $path): bool
{
    foreach (ACCES_FRANCHISE as $p) { if ($path === $p || str_starts_with($path, $p . '/')) { return true; } }
    return false;
}

/**
 * Les horloges : chaque route …/cron vérifie son propre jeton (?jeton=, comparé à temps constant) ; le relevé des
 * inventaires n'en a pas et n'est appelé que par le serveur lui-même (bin/stock_cron.sh, 127.0.0.1).
 */
function accesHorloge(string $path): bool
{
    if (str_ends_with($path, '/cron') && isset($_GET['jeton'])) { return true; }
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && empty($_SERVER['HTTP_X_FORWARDED_FOR']);
    return $local && $path === '/stock/inventaires/releve';
}

/** La recette d'une livraison (bin/deploy.sh) : la clé tirée pour elle, dans l'en-tête X-Recette. */
function accesRecette(): bool
{
    $cle = (string) (Db::config()['accesRecette'] ?? '');
    $recu = (string) ($_SERVER['HTTP_X_RECETTE'] ?? '');
    return strlen($cle) >= 32 && $recu !== '' && hash_equals($cle, $recu);
}

/**
 * La garde de l'API : null (passe) ou [code HTTP, réponse]. `$service` : route d'intégration avec le bon jeton de
 * service. Le refus dit au navigateur où se connecter.
 */
function accesRefus(string $method, string $path, bool $service = false): ?array
{
    if ($service || accesHorloge($path) || accesRecette()) { return null; }
    if (accesMode() === 'ouvert') {
        return authEnabled() && !authOk() ? [401, ['error' => 'auth', 'setup' => authIsSetup()]] : null;
    }
    $id = accesIdentite();
    if ($id === null) { return [401, ['error' => 'auth', 'connexion' => 'acces', 'message' => 'Connectez-vous : mot de passe du siège ou code du magasin.']]; }
    if ($id['role'] === 'siege') { return null; }
    if (!accesRouteFranchise($path)) {
        return [403, ['error' => 'acces', 'message' => 'Cet écran est réservé au siège.']];
    }
    foreach (accesMagasinsDemandes() as $s) {
        if (!in_array((string) $s, $id['shops'] ?? [], true)) {
            return [403, ['error' => 'acces', 'message' => 'Ce magasin n’est pas sur votre session.', 'shops' => $id['shops']]];
        }
    }
    return null;
}

/**
 * Les routes /acces/* (ouvertes : la page de connexion les appelle avant toute session) :
 *   GET  /acces/config     ce que la page propose (mot de passe du siège créé ?, magasins, console disponible ?)
 *   GET  /acces/moi        qui est connecté (la garde des pages, assets/js/garde.js)
 *   POST /acces/siege      {motDePasse} — la connexion du siège
 *   POST /acces/siege/creer {telephone, motDePasseErp, motDePasse} — créer ou réinitialiser le mot de passe du siège
 *   POST /acces/magasin    {shop, code} — la connexion d'un magasin
 *   POST /acces/sortir     — fermer la session (siège, magasin, et l'identité de console gardée)
 *   GET  /acces/codes      (siège) les magasins et leur code : défini ou non, depuis quand — jamais le code
 *   POST /acces/codes      (siège) {shop} : un nouveau code, rendu une seule fois ; {shop, retirer: true} : le retirer
 */
function accesRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/acces/')) { return null; }
    $ouvert = accesMode() === 'ouvert';
    if ($method === 'GET' && $path === '/acces/config') {
        $mags = [];
        foreach (accesMagasins() as $id => $nom) { $mags[] = ['id' => (string) $id, 'nom' => $nom, 'code' => !empty(accesCodes()[$id]['hash'])]; }
        return ['mode' => accesMode(), 'motDePasse' => authIsSetup(), 'magasins' => $mags,
            'console' => accesConsoleDispo() ? accesConsolePublique() : null];
    }
    if ($method === 'GET' && $path === '/acces/moi') {
        if ($ouvert) { return ['mode' => 'ouvert', 'connecte' => true, 'role' => 'siege', 'nom' => 'Banc', 'shops' => null, 'source' => 'ouvert']; }
        $id = accesIdentite();
        if ($id === null) { return ['mode' => 'console', 'connecte' => false]; }
        return ['mode' => 'console', 'connecte' => true] + array_intersect_key($id, array_flip(['role', 'source', 'nom', 'shops', 'bo', 'csrf']))
            + ['console' => $id['source'] === 'console' ? accesConsolePublique() : null];
    }
    $b = $method === 'POST' ? body() : [];
    if ($method === 'POST' && $path === '/acces/siege') {
        if (!authIsSetup()) { http_response_code(409); return ['error' => 'Le mot de passe du siège n’est pas encore créé.']; }
        if (accesFreine('siege')) { http_response_code(429); return ['error' => 'Trop d’essais. Réessayez dans un quart d’heure.']; }
        if (!password_verify((string) ($b['motDePasse'] ?? ''), (string) authHash())) {
            accesEchec('siege');
            http_response_code(401);
            return ['error' => 'Mot de passe incorrect.'];
        }
        authIssue();
        return ['ok' => true];
    }
    if ($method === 'POST' && $path === '/acces/siege/creer') {
        if (accesFreine('preuve')) { http_response_code(429); return ['error' => 'Trop d’essais. Réessayez dans un quart d’heure.']; }
        if (!accesPreuveErp((string) ($b['telephone'] ?? ''), (string) ($b['motDePasseErp'] ?? ''))) {
            accesEchec('preuve');
            http_response_code(403);
            return ['error' => 'Ce n’est pas le compte ERP du cockpit (« Mon compte ») : téléphone ou mot de passe différent.'];
        }
        $pw = (string) ($b['motDePasse'] ?? '');
        if (mb_strlen($pw) < 10) { http_response_code(422); return ['error' => 'Le mot de passe du siège : 10 caractères au moins.']; }
        $existait = authIsSetup();
        // Un nouveau secret de session : réinitialiser ferme toutes les sessions ouvertes (siège et magasins).
        if ($existait) { Db::exec('UPDATE ceo_app_setting SET value = ? WHERE `key` = ?', [json_encode(bin2hex(random_bytes(32))), 'authSecret']); }
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['authPasswordHash', json_encode(password_hash($pw, PASSWORD_DEFAULT))]);
        journalAdd('Système', 'Sécurité', null, $existait ? 'Mot de passe du siège réinitialisé (preuve : compte ERP du cockpit)' : 'Mot de passe du siège créé (preuve : compte ERP du cockpit)');
        authIssue();
        return ['ok' => true];
    }
    if ($method === 'POST' && $path === '/acces/magasin') {
        $shop = (string) ($b['shop'] ?? '');
        if (!isset(accesMagasins()[$shop])) { http_response_code(422); return ['error' => 'Choisissez le magasin.']; }
        if (accesFreine('m' . $shop)) { http_response_code(429); return ['error' => 'Trop d’essais pour ce magasin. Réessayez dans un quart d’heure.']; }
        $code = accesCodes()[$shop] ?? null;
        if (empty($code['hash'])) { http_response_code(401); return ['error' => 'Ce magasin n’a pas encore de code : demandez-le au siège.']; }
        if (!password_verify(accesNormaliserCode((string) ($b['code'] ?? '')), (string) $code['hash'])) {
            accesEchec('m' . $shop);
            http_response_code(401);
            return ['error' => 'Code incorrect.'];
        }
        $exp = time() + ACCES_TTL_MAGASIN; $gen = (int) $code['gen'];
        accesCookie(ACCES_COOKIE_MAGASIN, "$shop.$gen.$exp." . accesSigne("m|$shop|$gen|$exp"), $exp);
        return ['ok' => true, 'shop' => $shop];
    }
    if ($method === 'POST' && $path === '/acces/sortir') {
        accesOublier();
        authClear();
        accesCookie(ACCES_COOKIE_MAGASIN, '', time() - 3600);
        return ['ok' => true];
    }
    if ($path === '/acces/codes') {
        $id = $ouvert ? ['role' => 'siege'] : accesIdentite();
        if (($id['role'] ?? '') !== 'siege') { http_response_code($id === null ? 401 : 403); return ['error' => 'Réservé au siège.']; }
        $codes = accesCodes();
        if ($method === 'GET') {
            $out = [];
            foreach (accesMagasins() as $sid => $nom) { $out[] = ['id' => (string) $sid, 'nom' => $nom, 'defini' => !empty($codes[$sid]['hash']), 'le' => $codes[$sid]['le'] ?? null]; }
            return ['magasins' => $out];
        }
        if ($method === 'POST') {
            $shop = (string) ($b['shop'] ?? '');
            if (!isset(accesMagasins()[$shop])) { http_response_code(422); return ['error' => 'Magasin inconnu.']; }
            $gen = (int) ($codes[$shop]['gen'] ?? 0) + 1;
            if (!empty($b['retirer'])) {
                // La génération reste comptée : un ancien cookie ne redevient jamais valable.
                $codes[$shop] = ['hash' => null, 'gen' => $gen, 'le' => date('Y-m-d H:i')];
                $nouveau = null;
            } else {
                $nouveau = accesNouveauCode();
                $codes[$shop] = ['hash' => password_hash(accesNormaliserCode($nouveau), PASSWORD_DEFAULT), 'gen' => $gen, 'le' => date('Y-m-d H:i')];
            }
            Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['accesCodes', json_encode($codes)]);
            journalAdd('CEO', 'Sécurité', null, ($nouveau ? 'Nouveau code d’accès' : 'Code d’accès retiré') . ' : ' . accesNomMagasin($shop));
            return ['ok' => true, 'shop' => $shop, 'code' => $nouveau];
        }
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
