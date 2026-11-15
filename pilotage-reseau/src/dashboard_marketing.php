<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Marketing › Mon marketing (15/11/2026, « faire le total du marketing ») : tout le marketing d'UN
 * magasin sur une page — ce qu'il y a à faire, les campagnes, les posts et leur publication, les avis Google, l'e-mailing.
 *
 *   GET  /dashboard/marketing?shop=4                          le hub du marketing, pour ce magasin
 *   POST /dashboard/marketing/posts {shop, message, …}         un post du magasin (brouillon, ou soumis au contrôle)
 *   POST /dashboard/marketing/media {shop, data, nom}          une photo pour un post (JPEG, PNG, WebP ; 8 Mo)
 *   POST /dashboard/marketing/posts/{id}/soumettre {shop}      un brouillon du magasin envoyé au contrôle
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
 * Les écritures du magasin, par-dessus celles du marketing (src/writes.php, src/social_publication.php), qui ne regardent
 * pas le magasin : ici, le post est TOUJOURS celui du magasin de la requête (vérifié par l'accès), jamais un post de la
 * marque (sans magasin), et un post désigné par son numéro doit être de ce magasin. Le contrôle (règles, Brand Guard), la
 * validation par le bureau et la publication restent ceux du marketing.
 */
function dmRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/marketing' && !str_starts_with($path, '/dashboard/marketing/')) { return null; }
    if ($method === 'GET' && $path === '/dashboard/marketing') { return ep_dashboard_marketing(); }
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if ($method !== 'POST') { http_response_code(405); return ['error' => 'méthode non admise']; }
    if (!preg_match('/^\d{1,6}$/', $shop)) { http_response_code(400); return ['error' => 'shop manquant'];  }
    if ($path === '/dashboard/marketing/posts') {
        // Le corps est relu par wr_fb_post_create : il doit nommer CE magasin, sans numéro de post imposé.
        if ((string) ($b['magasinId'] ?? '') !== $shop) { http_response_code(422); return ['error' => 'le post doit être celui du magasin'];  }
        if (array_key_exists('id', $b)) { http_response_code(422); return ['error' => 'le numéro d’un post est donné par le cockpit']; }
        if (!in_array((string) ($b['statut'] ?? 'a_controler'), ['brouillon', 'a_controler'], true)) { http_response_code(422); return ['error' => 'statut inconnu']; }
        return function_exists('wr_fb_post_create') ? wr_fb_post_create() : dmIndispo();
    }
    if ($path === '/dashboard/marketing/media') {
        return function_exists('wr_social_media') ? wr_social_media() : dmIndispo();
    }
    if (preg_match('#^/dashboard/marketing/posts/([\w-]{1,40})/soumettre$#', $path, $m)) {
        $r = Db::row('SELECT shop_id, status FROM mar_social_post WHERE id = ?', [$m[1]]);
        if ($r === null || (string) $r['shop_id'] !== $shop) { http_response_code(404); return ['error' => 'post introuvable pour ce magasin']; }
        if ((string) $r['status'] !== 'brouillon') { http_response_code(409); return ['error' => 'ce post n’est plus un brouillon']; }
        if (empty($b['soumettre'])) { http_response_code(422); return ['error' => 'soumettre attendu']; }
        return function_exists('wr_fb_controle') ? wr_fb_controle($m[1]) : dmIndispo();
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}

function dmIndispo(): array
{
    http_response_code(503);
    return ['error' => 'le module des réseaux sociaux n’est pas chargé'];
}
