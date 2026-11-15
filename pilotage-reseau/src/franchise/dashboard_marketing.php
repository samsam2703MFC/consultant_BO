<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Marketing › Mon marketing (15/11/2026, « faire le total du marketing ») : tout le marketing d'UN
 * magasin sur une page — ce qu'il y a à faire, les campagnes, les posts et leur publication, les avis Google, l'e-mailing.
 *
 *   GET  /dashboard/marketing?shop=4                          le hub du marketing, pour ce magasin
 * (15/11/2026, « tout doit se faire dans le cockpit franchisé » : plus de lien vers l'espace boutique.)
 *
 * C'est le hub du marketing (src/marketing_hub.php, ep_marketing_hub), lu pour ce magasin seul : la route est sous
 * /dashboard, ouverte aux sessions de magasin (ACCES_FRANCHISE), et le magasin de la requête est vérifié par l'accès.
 * Ce que le hub sait du réseau et qui n'est pas l'affaire d'un magasin est retiré : la liste des magasins, le palmarès de
 * la charte, l'état des jetons Meta. Les objectifs produits des campagnes se lisent à côté
 * (/exploitation/objectifs-produits, déjà ouverte aux magasins).
 */

function ep_dashboard_marketing(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if (!preg_match('/^\d{1,6}$/', $shop)) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!function_exists('ep_marketing_hub')) { http_response_code(503); return ['error' => 'le hub du marketing n’est pas chargé']; }
    $_GET['shop'] = $shop;
    $h = ep_marketing_hub();

    $charte = is_array($h['charte'] ?? null) ? $h['charte'] : [];
    unset($charte['meta']);
    if (isset($charte['stats']) && is_array($charte['stats'])) { unset($charte['stats']['boutiques']); }

    // Les posts : ceux du magasin seulement (le hub filtre déjà ; on le garantit), sans l'avis interne du bureau.
    $social = is_array($h['social'] ?? null) ? $h['social'] : [];
    if (isset($social['liste']) && is_array($social['liste'])) {
        $social['liste'] = array_values(array_filter($social['liste'], static fn ($p) => is_array($p) && (string) ($p['magasinId'] ?? '') === $shop));
    }

    // L'état de la publication vers Facebook et Instagram : réglée par le bureau, dite au magasin en clair.
    $pageReliee = null;
    try {
        $r = Db::row('SELECT page_id, statut_connexion FROM mar_bg_page WHERE shop_id = ? AND actif = 1 ORDER BY page_id IS NULL, id LIMIT 1', [$shop]);
        if ($r !== null) { $pageReliee = ['pageConnue' => ($r['page_id'] ?? '') !== '', 'statut' => (string) ($r['statut_connexion'] ?? '')]; }
    } catch (Throwable $e) { /* table des pages absente : on ne dit rien */ }
    $metaPret = false;
    try { $metaPret = class_exists('MetaGraph') && MetaGraph::configured(); } catch (Throwable $e) { $metaPret = false; }

    return [
        'shop' => $shop, 'magasin' => $h['magasin'] ?? null, 'genere' => $h['genere'] ?? null, 'aujourdhui' => $h['aujourdhui'] ?? date('Y-m-d'),
        'aFaire' => $h['aFaire'] ?? [], 'campagnes' => $h['campagnes'] ?? [], 'social' => $social, 'emailing' => $h['emailing'] ?? [],
        'avis' => $h['avis'] ?? [], 'charte' => $charte,
        'meta' => ['pret' => $metaPret, 'page' => $pageReliee],
    ];
}

/**
 * Les routes du marketing du magasin. Les écritures de posts (POST …/posts, …/media, …/posts/{id}/soumettre, mises en ligne
 * le 15/11/2026) sont retirées le même jour : elles écrivaient l'ancienne table du cockpit (mar_social_post), que le
 * Marketing ne lit pas — un post créé là n'était ni validé ni publié par le réseau. Le « Nouveau post » reviendra par la
 * page « Publier » du Marketing, affichée dans le cockpit.
 */
function dmRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/marketing' && !str_starts_with($path, '/dashboard/marketing/')) { return null; }
    if ($method === 'GET' && $path === '/dashboard/marketing') { return ep_dashboard_marketing(); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}

/* --- Réseaux sociaux : l'espace boutique du Marketing, dans le cockpit (15/11/2026) ---------------------------------- */

/*
 * Décision du 15/11/2026 : « tout doit se faire dans le cockpit franchisé » — le calendrier, la création d'un post (le
 * parcours du Marketing : modèles du réseau, produit et promotion du catalogue, visuel, quota, répétition, validation du
 * réseau), les commentaires, les messages, les avis Google, les opérations et la grille Instagram sont les pages de
 * l'espace boutique du Marketing, affichées dans le dashboard en mode intégré. Le cockpit ne lit ni n'écrit leurs tables :
 * il demande à l'API Social du Marketing (jeton de service de l'ERP) un lien court qui ouvre la session de SA boutique.
 *
 *   GET /dashboard/social/ouvrir?shop=4&page=publier    → {url} : l'adresse que le cadre du dashboard ouvre
 */
const DS_PAGES = ['calendrier', 'publier', 'commentaires', 'messages', 'avis-google', 'operations', 'instagram'];

/** L'adresse de l'API Social du Marketing : celle du Marketing (mar_api_url), « …/api » → « …/social/api/v1/social ». */
function dsAdresseSocial(): string
{
    $m = function_exists('cmAdresseMarketing') ? cmAdresseMarketing() : '';
    return $m === '' ? '' : (string) preg_replace('#/api$#', '', $m) . '/social/api/v1/social';
}

function dsRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/social/ouvrir') { return null; }
    if ($method !== 'GET') { http_response_code(405); return ['error' => 'méthode non admise']; }
    [$shop, $err] = cmBoutique();
    if ($err !== null) { return ['error' => $err['error']['message'] ?? 'magasin']; }
    $page = in_array((string) ($_GET['page'] ?? ''), DS_PAGES, true) ? (string) $_GET['page'] : 'calendrier';
    $base = dsAdresseSocial();
    $jeton = function_exists('erpCle') ? erpCle('erp_jeton_service') : '';
    if ($base === '' || $jeton === '') {
        http_response_code(503);
        return ['error' => 'Le lien avec le Marketing n’est pas réglé : l’adresse du Marketing (mar_api_url) et le jeton de service (erp_jeton_service) d’erp_cles, au siège.'];
    }
    [$code, $j] = cmAppel($base . '/integration/franchise/sessions', 'POST', ['shop' => (string) $shop, 'page' => $page], $jeton);
    if ($code === 0) { http_response_code(502); return ['error' => 'Le Marketing ne répond pas : réessayez dans un instant.']; }
    if ($code === 401) { http_response_code(502); return ['error' => 'Le Marketing refuse le jeton de service : il doit être le même des deux côtés (erp_cles).']; }
    if ($code === 404 && !is_array($j)) { http_response_code(502); return ['error' => 'Le Marketing ne sait pas encore ouvrir l’espace boutique dans le cockpit (mise à jour à déployer).']; }
    if ($code < 200 || $code >= 300 || !is_array($j) || !is_string($j['url'] ?? null) || !str_starts_with($j['url'], '/')) {
        http_response_code($code >= 400 && $code < 500 ? $code : 502);
        return ['error' => is_array($j) && isset($j['error']) && is_string($j['error']) ? $j['error'] : 'Réponse du Marketing illisible.'];
    }
    return ['url' => $j['url'], 'page' => $page, 'expire' => $j['expire'] ?? null];
}
