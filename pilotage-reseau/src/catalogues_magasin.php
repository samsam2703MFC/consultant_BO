<?php
declare(strict_types=1);

/**
 * « Mon catalogue » du dashboard magasin (14/11/2026, Sam : « dans le dashboard, un module où les franchisés peuvent
 * créer leur catalogue, sous contrainte »).
 *
 * Les catalogues vivent dans le portail MARKETING (dépôt marketing, module Catalogues) : le cockpit n'en garde rien,
 * il ne lit aucune table `mar_`. Il RELAIE les gestes du dashboard à l'API d'intégration du Marketing
 * (docs/PORTAILS.md, « Entre portails ») :
 *   {mar_api_url}/v1/integration/marketing/shops/{shopId}/…   en-tête X-Service-Token = erp_jeton_service
 * où `shopId` est l'identifiant de caisse ERP de la boutique — celui des magasins du cockpit (table `shops`, ep_stores).
 *
 * Le jeton de service ne quitte jamais le serveur. La boutique n'est jamais prise dans le corps : elle est celle de la
 * SESSION (src/acces.php). Un magasin ou un franchisé n'a que les siens (un seul : il n'a rien à dire ; plusieurs :
 * `?shop=` choisit parmi eux, accesRefus refuse les autres) ; le siège (ou l'accès « ouvert ») désigne le magasin
 * regardé par `?shop=`, comme pour tous les écrans du dashboard.
 *
 * Toutes les contraintes (produits vendus par la boutique, ses prix, couverture et dernière page de la charte, trois
 * mises en page, source gamme ou choix) sont appliquées par le Marketing : ses réponses — succès ou erreurs
 * `{success:false, error:{code, message, details}}` — repartent telles quelles, avec leur code HTTP.
 *
 * Routes du cockpit (sous /dashboard, donc ouvertes aux sessions des magasins — ACCES_FRANCHISE) :
 *   GET    /dashboard/catalogues/produits                  → …/catalogue-products
 *   GET    /dashboard/catalogues                           → …/catalogues?perPage=100&sort=-updatedAt
 *   POST   /dashboard/catalogues                           → …/catalogues
 *   GET    /dashboard/catalogues/{id}                      → …/catalogues/{id}
 *   PUT    /dashboard/catalogues/{id}                      → …/catalogues/{id}
 *   DELETE /dashboard/catalogues/{id}                      → …/catalogues/{id}
 *   GET    /dashboard/catalogues/{id}/document?lang=fr|nl  → …/catalogues/{id}/document
 *   POST   /dashboard/catalogues/{id}/impression {lang}    → …/catalogues/{id}/print-links
 *
 * Réglages (aucun écrit en dur) :
 *   - l'adresse de l'API du Marketing : `mar_api_url` d'erp_cles (portail commun, comme rec_api_url / pil_api_url ;
 *     ex. « https://<hôte>/marketing/api »), à défaut `marketingApi` de config/config.php ;
 *   - le jeton de service : `erp_jeton_service` d'erp_cles (le même que pour le Recrutement).
 */

const CM_CHAMPS_CREER = ['title', 'source', 'format', 'pageCount', 'langs', 'products', 'pages'];
const CM_CHAMPS_MODIFIER = ['title', 'format', 'pageCount', 'langs', 'products', 'pages', 'status', 'printQty'];

/** Une erreur au format du standard (celui du Marketing), pour ce que le cockpit dit lui-même. */
function cmErreur(int $http, string $code, string $message): array
{
    http_response_code($http);
    return ['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => []], 'meta' => ['source' => 'cockpit']];
}

/** L'adresse de l'API du Marketing (sans barre finale, se terminant par /api), ou '' si elle n'est pas réglée. */
function cmAdresseMarketing(): string
{
    $v = erpCle('mar_api_url');
    if ($v === '') { $v = trim((string) (Db::config()['marketingApi'] ?? '')); }
    $v = rtrim($v, '/');
    if ($v === '' || !preg_match('#^https?://[^/\s]+#i', $v)) { return ''; }
    // On accepte l'adresse du module (« …/marketing ») comme celle de son API (« …/marketing/api »).
    return preg_match('#/api$#', $v) ? $v : $v . '/api';
}

/**
 * La boutique de la session : [identifiant ERP, null] ou [null, réponse d'erreur]. Jamais le corps de la requête.
 */
function cmBoutique(): array
{
    $demande = isset($_GET['shop']) ? trim((string) $_GET['shop']) : '';
    $id = accesMode() === 'ouvert' ? null : accesIdentite();
    $siens = $id === null ? null : ($id['shops'] ?? null);
    if (is_array($siens)) {
        // Un magasin ou un franchisé : ses magasins seulement (accesRefus a déjà refusé un autre ?shop=).
        if ($demande === '') {
            if (count($siens) !== 1) { return [null, cmErreur(422, 'VALIDATION_FAILED', 'Choisissez le magasin.')]; }
            $demande = (string) $siens[0];
        }
        if (!in_array($demande, array_map('strval', $siens), true)) {
            return [null, cmErreur(403, 'PERM_SHOP_FORBIDDEN', 'Ce magasin n’est pas sur votre session.')];
        }
    } elseif ($demande === '') {
        return [null, cmErreur(422, 'VALIDATION_FAILED', 'Magasin attendu (?shop=).')];
    }
    if (!ctype_digit($demande) || (int) $demande <= 0) { return [null, cmErreur(422, 'VALIDATION_FAILED', 'Identifiant de magasin invalide.')]; }
    return [(int) $demande, null];
}

/**
 * Un appel au Marketing : [code HTTP, corps décodé ou null, texte brut]. Code 0 : injoignable. Même serveur : si
 * l'adresse publique ne répond pas (NAT en épingle), un second essai résout le nom en local (comme erpLireRecrutement).
 */
function cmAppel(string $url, string $methode, ?array $corps, string $jeton): array
{
    $essai = static function (array $opts = []) use ($url, $methode, $corps, $jeton): array {
        $ch = curl_init($url);
        $h = ['Accept: application/json', 'X-Service-Token: ' . $jeton];
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_FOLLOWLOCATION => false];
        if ($corps !== null) {
            $h[] = 'Content-Type: application/json';
            $o[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $o[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts + $o);
        $brut = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($brut) ? $brut : ''];
    };
    [$code, $brut] = $essai();
    if ($code === 0) {
        $p = parse_url($url);
        $hote = (string) ($p['host'] ?? '');
        $port = (int) ($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80));
        if ($hote !== '' && !filter_var($hote, FILTER_VALIDATE_IP) && $hote !== 'localhost') {
            [$code, $brut] = $essai([CURLOPT_RESOLVE => ["$hote:$port:127.0.0.1"]]);
        }
    }
    $j = $brut !== '' ? json_decode($brut, true) : null;
    return [$code, is_array($j) ? $j : null, $brut];
}

/** Ne garde du corps que les champs que le Marketing accepte d'une boutique (jamais une boutique, jamais un prix). */
function cmCorps(array $champs): array
{
    $b = body();
    return array_intersect_key($b, array_flip($champs));
}

/** Les routes /dashboard/catalogues* : la réponse (tableau), ou null si la route n'est pas la sienne. */
function cmRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/catalogues' && !str_starts_with($path, '/dashboard/catalogues/')) { return null; }
    $suite = substr($path, strlen('/dashboard/catalogues'));

    // La route d'abord (une adresse inconnue reste un 404, réglages ou pas).
    $cible = null; $corps = null; $requete = [];
    if ($suite === '/produits' && $method === 'GET') {
        $cible = '/catalogue-products';
    } elseif ($suite === '' && $method === 'GET') {
        $cible = '/catalogues';
        $requete = ['perPage' => 100, 'sort' => '-updatedAt'];
        $st = (string) ($_GET['statut'] ?? '');
        if (in_array($st, ['brouillon', 'pret', 'imprime'], true)) { $requete['filter[status][eq]'] = $st; }
    } elseif ($suite === '' && $method === 'POST') {
        $cible = '/catalogues'; $corps = cmCorps(CM_CHAMPS_CREER);
    } elseif (preg_match('#^/(\d{1,10})$#', $suite, $m) && in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
        $cible = '/catalogues/' . $m[1];
        if ($method === 'PUT') { $corps = cmCorps(CM_CHAMPS_MODIFIER); }
    } elseif (preg_match('#^/(\d{1,10})/document$#', $suite, $m) && $method === 'GET') {
        $cible = '/catalogues/' . $m[1] . '/document';
        $requete = ['lang' => ($_GET['lang'] ?? 'fr') === 'nl' ? 'nl' : 'fr'];
    } elseif (preg_match('#^/(\d{1,10})/impression$#', $suite, $m) && $method === 'POST') {
        $cible = '/catalogues/' . $m[1] . '/print-links';
        $corps = ['lang' => (body()['lang'] ?? 'fr') === 'nl' ? 'nl' : 'fr'];
    } else {
        return cmErreur(404, 'RESOURCE_NOT_FOUND', 'Route inconnue.');
    }

    [$shop, $refus] = cmBoutique();
    if ($refus !== null) { return $refus; }

    $base = cmAdresseMarketing();
    if ($base === '') {
        return cmErreur(503, 'DEPENDENCY_UNAVAILABLE', 'L’adresse du Marketing n’est pas réglée (clé « mar_api_url » de la table des clés erp_cles). Le siège doit la renseigner.');
    }
    $jeton = erpCle('erp_jeton_service');
    if ($jeton === '') {
        return cmErreur(503, 'DEPENDENCY_UNAVAILABLE', 'Le jeton de service entre portails n’est pas réglé (clé « erp_jeton_service » de erp_cles). Le siège doit le renseigner.');
    }

    $url = $base . '/v1/integration/marketing/shops/' . $shop . $cible . ($requete ? '?' . http_build_query($requete) : '');
    [$code, $j, $brut] = cmAppel($url, $method, $corps, $jeton);
    if ($code === 0) {
        error_log('catalogues magasin : Marketing injoignable (' . $method . ' ' . $cible . ')');
        return cmErreur(502, 'DEPENDENCY_UNAVAILABLE', 'Le Marketing ne répond pas. Réessayez dans un instant ; si cela dure, prévenez le siège.');
    }
    if ($code === 204) { http_response_code(200); return ['success' => true, 'data' => null, 'meta' => []]; }
    if (!is_array($j) || !array_key_exists('success', $j)) {
        error_log('catalogues magasin : réponse illisible du Marketing, HTTP ' . $code . ' (' . $method . ' ' . $cible . ')');
        $msg = $code === 404 ? 'L’adresse du Marketing (mar_api_url) ne mène pas à son API : réponse 404 sans contenu.'
            : 'Réponse illisible du Marketing (HTTP ' . $code . ').';
        return cmErreur(502, 'DEPENDENCY_UNAVAILABLE', $msg);
    }
    // Un 401 du Marketing vise le jeton de service du cockpit, pas la session du magasin : il repart en 502 (le
    // navigateur ne doit pas le prendre pour une déconnexion), son corps intact, le message précisé.
    if ($code === 401) {
        $j['error']['message'] = 'Le Marketing refuse le jeton de service du cockpit (' . (string) ($j['error']['code'] ?? '401')
            . ') : erp_jeton_service doit être le même des deux côtés. Prévenez le siège.';
        $code = 502;
    }
    // La réponse du Marketing repart telle quelle, avec son code.
    http_response_code($code);
    return $j;
}
