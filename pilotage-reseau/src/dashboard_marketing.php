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
