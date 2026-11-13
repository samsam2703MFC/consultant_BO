<?php
declare(strict_types=1);

/**
 * L'accès à l'API du cockpit (13/11/2026, Sam : « sécurise l'API ») — les comptes de la console franchisé.
 *
 * Jusqu'ici l'API répondait à tout le monde (`auth` => false : l'accès passait par un lien depuis l'ERP). Elle demande
 * désormais une session de la console franchisé (dépôt WebShop, comptes bo_users), la même que la page RH /hr :
 *   - le compte « siège » (franchiseur) ouvre tout le cockpit ;
 *   - un compte « franchisé » n'ouvre que les écrans des magasins (ACCES_FRANCHISE) et que SES magasins : tout
 *     paramètre de magasin d'une requête (shop, shopId, magasin…) doit en faire partie, sinon 403.
 *
 * L'identité est vérifiée par l'API de la console, jamais lue dans ses tables : les deux cookies de session
 * (fb_franchisee_session, fb_franchisor_session, posés sur le même hôte, chemin /, Secure) sont relayés, serveur à
 * serveur, à GET {console}/bo/franchisee/me puis /bo/franchisor/me. La réponse est gardée 60 secondes (dossier
 * temporaire, par empreinte du cookie) : le dashboard lit une vingtaine de routes par écran.
 *
 * Restent ouvertes sans session, comme avant, les routes qui ont leur propre clé (index.php) : suivi des newsletters,
 * flux ICS, webhooks Meta, app employé, borne et caisse de pointage, page de suivi du franchisé, tablettes SOP,
 * routes d'intégration des autres portails (X-Service-Token) — et les horloges (cron), appelées par le serveur
 * lui-même, et la recette de chaque livraison (clé X-Recette tirée par bin/deploy.sh). Le mot de passe intégré du cockpit (src/auth.php), s'il est activé, reste accepté.
 *
 * Réglage `acces` de config.php : « console » (en ligne, posé par bin/deploy.sh) ou « ouvert » (banc, installation
 * autonome). Absent : console — fermé par défaut.
 */

const ACCES_COOKIES = ['franchisee' => 'fb_franchisee_session', 'franchisor' => 'fb_franchisor_session'];
const ACCES_TTL = 60;

/**
 * Les écrans d'un franchisé : le premier segment des routes que lisent le dashboard du magasin, la caisse, la
 * production et le planogramme (public/dashboard, caisse, production, planogramme, resultat-mobile).
 * Tout le reste (réglages, réseau, marketing, visites, primes du réseau, documents des franchisés…) est au siège.
 */
const ACCES_FRANCHISE = ['/acces', '/exploitation', '/ventes', '/fidelite', '/analyse', '/fournisseurs', '/push',
    '/production', '/soir', '/assistant/magasin', '/stores', '/benchmarks/magasin', '/benchmarks/releve', '/plan',
    '/leviers', '/equipe', '/caisse', '/planogramme', '/pwa', '/formation/sop/photo', '/uploads', '/dashboard'];

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
 * Qui appelle : ['role' => 'siege'|'franchise', 'nom' => …, 'shops' => list<string>|null (null = tout le réseau),
 * 'bo' => 'franchisee'|'franchisor', 'csrf' => …] ou null (personne n'est connecté). Lance une RuntimeException si la
 * console ne répond pas ou n'a pas ses comptes activés.
 */
function accesIdentite(): ?array
{
    static $memo = false;
    if ($memo !== false) { return $memo; }
    $cookie = accesCookies();
    if ($cookie === '') { return $memo = null; }
    $f = accesCacheFichier($cookie);
    if (is_file($f) && filemtime($f) > time() - ACCES_TTL) {
        $c = json_decode((string) @file_get_contents($f), true);
        if (is_array($c) && array_key_exists('qui', $c)) { return $memo = (is_array($c['qui']) ? $c['qui'] : null); }
    }
    $base = accesConsoleInterne();
    if ($base === null) { throw new RuntimeException('La connexion par la console franchisé n’est pas configurée sur ce serveur.'); }
    $id = null; $muette = 0;
    foreach (['franchisee' => 'franchise', 'franchisor' => 'siege'] as $bo => $role) {
        if (!isset($_COOKIE[ACCES_COOKIES[$bo]])) { continue; }
        [$code, $j] = accesConsoleGet($base . '/bo/' . $bo . '/me', $cookie);
        if ($code === 0) { $muette++; continue; }
        if ($code === 200 && is_array($j['user'] ?? null)) {
            $u = $j['user'];
            $shops = $role === 'franchise' ? array_values(array_map('strval', (array) ($u['shops'] ?? []))) : null;
            $id = ['id' => 'bo:' . (int) ($u['id'] ?? 0), 'role' => $role, 'bo' => $bo,
                'nom' => trim((string) ($u['display_name'] ?? '')) ?: 'Compte ' . (int) ($u['id'] ?? 0),
                'shops' => $shops, 'csrf' => is_string($j['csrf'] ?? null) ? $j['csrf'] : null];
            break;
        }
    }
    if ($id === null && $muette > 0) { throw new RuntimeException('La console franchisé ne répond pas.'); }
    @file_put_contents($f, json_encode(['qui' => $id], JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0600);
    return $memo = $id;
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
    if (authEnabled() && authOk()) { return null; }   // le mot de passe intégré, s'il est activé
    if (accesMode() === 'ouvert') {
        return authEnabled() ? [401, ['error' => 'auth', 'setup' => authIsSetup()]] : null;
    }
    try {
        $id = accesIdentite();
    } catch (RuntimeException $e) {
        return [503, ['error' => 'acces', 'message' => $e->getMessage()]];
    }
    if ($id === null) { return [401, ['error' => 'auth', 'connexion' => 'console', 'message' => 'Connectez-vous avec votre compte de la console.']]; }
    if ($id['role'] === 'siege') { return null; }
    if (!accesRouteFranchise($path)) {
        return [403, ['error' => 'acces', 'message' => 'Cet écran est réservé au siège.']];
    }
    foreach (accesMagasinsDemandes() as $s) {
        if (!in_array((string) $s, $id['shops'] ?? [], true)) {
            return [403, ['error' => 'acces', 'message' => 'Ce magasin n’est pas sur votre compte.', 'shops' => $id['shops']]];
        }
    }
    return null;
}

/** GET /acces/moi — qui est connecté (la page de connexion et la garde des pages) ; GET /acces/config — la console. */
function accesRoute(string $method, string $path): ?array
{
    if ($method === 'GET' && $path === '/acces/config') {
        return ['mode' => accesMode(), 'console' => accesConsolePublique()];
    }
    if ($method === 'GET' && $path === '/acces/moi') {
        if (accesMode() === 'ouvert') { return ['mode' => 'ouvert', 'connecte' => true, 'role' => 'siege', 'shops' => null]; }
        try { $id = accesIdentite(); } catch (RuntimeException $e) { http_response_code(503); return ['error' => 'acces', 'message' => $e->getMessage()]; }
        if ($id === null) { return ['mode' => 'console', 'connecte' => false, 'console' => accesConsolePublique()]; }
        return ['mode' => 'console', 'connecte' => true, 'role' => $id['role'], 'nom' => $id['nom'], 'shops' => $id['shops'],
            'bo' => $id['bo'], 'csrf' => $id['csrf'], 'console' => accesConsolePublique()];
    }
    if ($method === 'POST' && $path === '/acces/oublier') { accesOublier(); return ['ok' => true]; }
    return null;
}
