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
 * Toutes les contraintes (produits vendus par la boutique, ses prix, couverture et dernière page de la charte, 8 à 16
 * pages par 2, six mises en page dont les présentations de gamme et les grandes vignettes 2 × 2, gabarits A · B · C,
 * en-tête titre · bandeau · grande illustration, une page par catégorie ajoutée, place libre — photo de la
 * médiathèque du réseau ou de la boutique, ou illustration —, source gamme ou choix) sont appliquées par le Marketing : ses réponses — succès ou erreurs
 * `{success:false, error:{code, message, details}}` — repartent telles quelles, avec leur code HTTP.
 *
 * Routes du cockpit (sous /dashboard, donc ouvertes aux sessions des magasins — ACCES_FRANCHISE) :
 *   GET    /dashboard/catalogues/produits                  → …/catalogue-products
 *   GET    /dashboard/catalogues/medias?page=&q=            → …/catalogue-media?perPage=24&page=&filter[name][like]=
 *          (les images de la médiathèque que la boutique peut mettre à la place libre d'une page : réseau et siennes)
 *   POST   /dashboard/catalogues/medias {name, contentBase64} → …/catalogue-media
 *          (une photo que la boutique importe, pour une page ou la vignette d'une promotion — 15/11/2026)
 *   GET    /dashboard/catalogues                           → …/catalogues?perPage=100&sort=-updatedAt
 *   POST   /dashboard/catalogues                           → …/catalogues
 *   GET    /dashboard/catalogues/{id}                      → …/catalogues/{id}
 *   PUT    /dashboard/catalogues/{id}                      → …/catalogues/{id}
 *   DELETE /dashboard/catalogues/{id}                      → …/catalogues/{id}
 *   GET    /dashboard/catalogues/{id}/document?lang=fr|nl  → …/catalogues/{id}/document
 *   POST   /dashboard/catalogues/{id}/impression {lang}    → …/catalogues/{id}/print-links
 *
 * L'assistant du franchisé (15/11/2026, client : « choix du catalogue, ensuite les gammes, la présentation, validation et
 * exportation PDF ») — pour un de MES catalogues ({base} = /dashboard/catalogues) ou un catalogue de la MARQUE préparé
 * pour ma boutique ({base} = /dashboard/catalogues/marque) :
 *   GET    {base}/{id}/verifications?lang=           → …/{catalogues|brand-catalogues}/{id}/checks (les points à voir)
 *   PUT    {base}/{id}/verifications/{code} {seen}   → …/{id}/checks/{code}
 *   POST   {base}/{id}/imprime {quantity}            → …/{id}/prints (« Marquer imprimé · N ex. », charte figée)
 *   GET    {base}/{id}/lien                          → …/{id}/public-links (le lien permanent du catalogue en ligne)
 *   POST   {base}/{id}/lien                          → …/{id}/public-links (« Nouveau lien » : l'ancien est révoqué)
 * Les catalogues de la marque reçus :
 *   GET    /dashboard/catalogues/marque              → …/brand-catalogues?perPage=50
 *   GET    /dashboard/catalogues/marque/{id}[/document?lang=]
 *   PUT    /dashboard/catalogues/marque/{id}/reception {status: pris}   (« Le prendre tel quel »)
 *   POST   /dashboard/catalogues/marque/{id}/adaptation                 (« L'adapter à ma boutique » : une copie à moi)
 *   POST   /dashboard/catalogues/marque/{id}/impression {lang}          (lien d'impression de MA version)
 *
 * Réglages (aucun écrit en dur) :
 *   - l'adresse de l'API du Marketing : `mar_api_url` d'erp_cles (portail commun, comme rec_api_url / pil_api_url ;
 *     ex. « https://<hôte>/marketing/api »), à défaut `marketingApi` de config/config.php ;
 *   - le jeton de service : `erp_jeton_service` d'erp_cles (le même que pour le Recrutement).
 */

/** `offers` ({type, id, on} : les promotions de TFBuddy cochées) et `showAvailability` (booléen) : 14/11/2026. */
const CM_CHAMPS_CREER = ['title', 'source', 'format', 'pageCount', 'langs', 'products', 'pages', 'offers', 'showAvailability'];
const CM_CHAMPS_MODIFIER = ['title', 'format', 'pageCount', 'langs', 'products', 'pages', 'status', 'printQty', 'offers', 'showAvailability'];

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
        // Les dessins du document (en-tête, repli des vignettes) sont des adresses absolues que le Marketing compose avec
        // l'hôte qu'il voit : l'appel interne lui montrerait 127.0.0.1, que le navigateur ne joint pas. On lui passe celui
        // de la page du dashboard (MediaService::publicUrl lit X-Forwarded-Host).
        $hote = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $hote) === 1) { $h[] = 'X-Forwarded-Host: ' . $hote; }
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

/**
 * Ne garde du corps que les champs que le Marketing accepte d'une boutique (jamais une boutique, jamais un prix). Les
 * pages intérieures (`pages`) partent telles quelles — {layout, category, product, template, gamme, text, textNl, filler, header,
 * script, fillerMediaId} : le Marketing les valide champ par champ (422 nommé), y compris la photo d'une autre boutique
 * (`product` : le produit d'une pleine page, `layout: pleine`, 17/11/2026). Les
 * promotions cochées (`offers` : [{type, id, on, mediaId}] — `mediaId` : l'image de la médiathèque de la vignette) et `showAvailability` aussi : une promotion que TFBuddy ne rend pas
 * pour la boutique (celle d'une autre, finie, inconnue) est refusée par le Marketing (422 `offers[i].id`).
 */
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
    } elseif ($suite === '/medias' && $method === 'GET') {
        // Les images de la place libre : le Marketing ne rend que celles du réseau et de la boutique du chemin.
        $cible = '/catalogue-media';
        $page = (string) ($_GET['page'] ?? '1');
        $requete = ['page' => ctype_digit($page) && (int) $page > 0 ? (int) $page : 1, 'perPage' => 24];
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        if ($q !== '') { $requete['filter'] = ['name' => ['like' => $q]]; }
    } elseif ($suite === '/medias' && $method === 'POST') {
        // Une photo importée par la boutique (15/11/2026) : dans la médiathèque du Marketing, rangée à SA boutique.
        $cible = '/catalogue-media';
        $b = body();
        $corps = ['name' => mb_substr(trim((string) ($b['name'] ?? '')), 0, 120), 'contentBase64' => (string) ($b['contentBase64'] ?? '')];
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
    } elseif ($suite === '/marque' && $method === 'GET') {
        $cible = '/brand-catalogues';
        $requete = ['perPage' => 50];
    } elseif (preg_match('#^/marque/(\d{1,10})$#', $suite, $m) && $method === 'GET') {
        $cible = '/brand-catalogues/' . $m[1];
    } elseif (preg_match('#^/marque/(\d{1,10})/document$#', $suite, $m) && $method === 'GET') {
        $cible = '/brand-catalogues/' . $m[1] . '/document';
        $requete = ['lang' => ($_GET['lang'] ?? 'fr') === 'nl' ? 'nl' : 'fr'];
    } elseif (preg_match('#^/marque/(\d{1,10})/reception$#', $suite, $m) && $method === 'PUT') {
        $cible = '/brand-catalogues/' . $m[1] . '/reception';
        $corps = ['status' => (string) (body()['status'] ?? '')];
    } elseif (preg_match('#^/marque/(\d{1,10})/adaptation$#', $suite, $m) && $method === 'POST') {
        $cible = '/brand-catalogues/' . $m[1] . '/adaptations';
        $corps = [];
    } elseif (preg_match('#^/marque/(\d{1,10})/impression$#', $suite, $m) && $method === 'POST') {
        $cible = '/brand-catalogues/' . $m[1] . '/print-links';
        $corps = ['lang' => (body()['lang'] ?? 'fr') === 'nl' ? 'nl' : 'fr'];
    } elseif (preg_match('#^(/marque)?/(\d{1,10})/verifications$#', $suite, $m) && $method === 'GET') {
        $cible = ($m[1] !== '' ? '/brand-catalogues/' : '/catalogues/') . $m[2] . '/checks';
        $requete = ['lang' => ($_GET['lang'] ?? 'fr') === 'nl' ? 'nl' : 'fr'];
    } elseif (preg_match('#^(/marque)?/(\d{1,10})/verifications/([a-z0-9-]{1,60})$#', $suite, $m) && $method === 'PUT') {
        $cible = ($m[1] !== '' ? '/brand-catalogues/' : '/catalogues/') . $m[2] . '/checks/' . $m[3];
        $corps = ['seen' => (body()['seen'] ?? null) === true];
    } elseif (preg_match('#^(/marque)?/(\d{1,10})/imprime$#', $suite, $m) && $method === 'POST') {
        $cible = ($m[1] !== '' ? '/brand-catalogues/' : '/catalogues/') . $m[2] . '/prints';
        $q = body()['quantity'] ?? null;
        $corps = ['quantity' => is_int($q) ? $q : (is_string($q) && ctype_digit($q) ? (int) $q : 0)];
    } elseif (preg_match('#^(/marque)?/(\d{1,10})/lien$#', $suite, $m) && in_array($method, ['GET', 'POST'], true)) {
        $cible = ($m[1] !== '' ? '/brand-catalogues/' : '/catalogues/') . $m[2] . '/public-links';
        if ($method === 'POST') { $corps = []; }
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
