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

/* --- Mon marketing : les campagnes en cours, décrites (15/11/2026) -------------------------------------------------- */

/**
 * GET /dashboard/marketing/campagnes?shop=4 — Mon marketing (15/11/2026, « c'est seulement les campagnes en cours et la
 * description complète de ce qui s'y passe ») : les campagnes EN COURS du magasin (celles du hub, src/marketing_hub.php,
 * mhCampagnes), chacune avec la note de campagne que le réseau envoie au franchisé (src/mkt_brief.php, mktBriefDonnees) :
 * le levier et ce sur quoi elle se juge, l'objectif du magasin (sa référence, sa cible, les clients et le chiffre
 * attendus, son budget de la période), le mot du responsable quand il est écrit, le calendrier, la communication canal
 * par canal, les formats, les documents joints. Réduite à CE magasin : ni les chiffres ni les noms des autres magasins.
 */
function ep_dashboard_marketing_campagnes(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if (!preg_match('/^\d{1,6}$/', $shop)) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!function_exists('mhCampagnes') || !function_exists('mktBriefDonnees')) { http_response_code(503); return ['error' => 'les campagnes ne sont pas chargées']; }
    $auj = date('Y-m-d');
    $out = [];
    foreach ((array) (mhCampagnes($shop, $auj)['liste'] ?? []) as $c) {
        if (($c['phase'] ?? '') !== 'en_cours') { continue; }
        $d = null; $note = null;
        try { $d = mktBriefDonnees((int) $c['id']); } catch (Throwable $e) { $note = 'la note de la campagne ne se lit pas : ' . $e->getMessage(); }
        $d = is_array($d) ? $d : [];
        $ligne = null;
        foreach ((array) ($d['lignes'] ?? []) as $l) { if ((string) ($l['id'] ?? '') === $shop) { $ligne = $l; } }
        if (is_array($ligne)) { unset($ligne['id'], $ligne['nom']); }
        $du = (string) ($c['debut'] ?? ''); $au = (string) ($c['fin'] ?? '');
        $jours = $du !== '' && $au !== '' ? (int) round((strtotime($au) - strtotime($du)) / 86400) + 1 : null;
        $jour = $du !== '' ? (int) round((strtotime($auj) - strtotime($du)) / 86400) + 1 : null;
        $mot = is_array($d['mot'] ?? null) && trim((string) ($d['mot']['texte'] ?? '')) !== '' ? $d['mot'] : null;
        $out[] = [
            'id' => (int) $c['id'], 'nom' => (string) $c['nom'], 'type' => (string) ($c['type'] ?? ''), 'typeCouleur' => (string) ($c['typeCouleur'] ?? ''),
            'statut' => (string) ($c['statutNom'] ?? ''), 'du' => $du, 'au' => $au, 'jours' => $jours, 'jour' => $jour,
            'image' => $c['image'] ?? null,
            'portee' => (string) ($d['portee'] ?? ($c['reseau'] ? 'Réseau' : 'Magasins désignés')),
            'nMagasins' => count((array) ($d['magasinIds'] ?? [])),
            'levier' => (string) ($d['levier'] ?? ''), 'iconePath' => (string) ($d['iconePath'] ?? ''),
            'kpi' => ['nom' => (string) ($d['kpiNom'] ?? ''), 'unite' => (string) ($d['kpiUnite'] ?? ''), 'decimales' => (int) ($d['kpiDecimales'] ?? 0),
                'mesure' => (bool) ($d['kpiMesure'] ?? false), 'objectifPct' => $d['objectifPct'] ?? null],
            'magasin' => $ligne,
            'mot' => $mot,
            'budget' => $d['budget'] ?? null,
            'etapes' => array_values((array) ($d['etapes'] ?? [])),
            'canaux' => array_values((array) ($d['canaux'] ?? [])),
            'investis' => array_values(array_map(static fn ($x) => array_intersect_key((array) $x, array_flip(['nom', 'couleur', 'montant', 'unite', 'kpi', 'calcul', 'raison'])), (array) ($d['investis'] ?? []))),
            'formats' => array_values((array) ($d['formats'] ?? [])),
            // Les documents qui partent avec la note du franchisé : ceux-là seulement, et s'ils sont encore sur le serveur.
            'annexes' => array_values(array_map(static fn ($a) => ['id' => (int) $a['id'], 'nom' => (string) $a['nom'], 'type' => (string) ($a['type'] ?? ''), 'taille' => (string) ($a['tailleTxt'] ?? '')],
                array_filter((array) ($d['annexes'] ?? []), static fn ($a) => !empty($a['enMail']) && !empty($a['existe'])))),
            'note' => $note,
        ];
    }
    return ['shop' => $shop, 'aujourdhui' => $auj, 'campagnes' => $out, 'genere' => date('c')];
}

/**
 * GET /dashboard/marketing/annee?shop=4&annee=2026 — le calendrier de l'année de Mon marketing (15/11/2026, « il faut un
 * calendrier annuel avec les campagnes + les produits et saisons des produits ») : les campagnes du magasin qui touchent
 * l'année (celles du hub : réseau ou désignant le magasin, hors brouillons), toutes phases. Les saisons des produits
 * se lisent à côté (/production/saisons, les gammes du panel), leurs produits dans /production/catalogue.
 */
function ep_dashboard_marketing_annee(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if (!preg_match('/^\d{1,6}$/', $shop)) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!function_exists('mhCampagnes')) { http_response_code(503); return ['error' => 'les campagnes ne sont pas chargées']; }
    $an = max(2020, min(2100, (int) ($_GET['annee'] ?? date('Y'))));
    $du = $an . '-01-01'; $au = $an . '-12-31';
    $out = [];
    foreach ((array) (mhCampagnes($shop, date('Y-m-d'))['liste'] ?? []) as $c) {
        $d = (string) ($c['debut'] ?? ''); $f = (string) ($c['fin'] ?? '') ?: $d;
        if ($d === '' || $d > $au || $f < $du) { continue; }
        $out[] = ['id' => (int) $c['id'], 'nom' => (string) $c['nom'], 'type' => (string) ($c['type'] ?? ''), 'typeCouleur' => (string) ($c['typeCouleur'] ?? ''),
            'debut' => $d, 'fin' => $f, 'phase' => (string) ($c['phase'] ?? ''), 'reseau' => (bool) ($c['reseau'] ?? false)];
    }
    usort($out, static fn ($a, $b) => [$a['debut'], $a['fin']] <=> [$b['debut'], $b['fin']]);
    return ['shop' => $shop, 'annee' => $an, 'campagnes' => $out];
}

/**
 * GET /dashboard/marketing/ventes-periode?shop=4&du=2025-11-01&au=2026-01-15&pids=4100011,4100007 — ce que les produits
 * d'une gamme ont fait chez le magasin sur une période (15/11/2026, la liste d'une saison du calendrier : « la catégorie,
 * le produit, la quantité vendue en N-1 sur la période, le CA et la marge effectuée »). Les ventes : les tranches du panel
 * (apTranches2, product-category-groups, gravées une fois closes). La marge réalisée : le CA HT encaissé (le CA TTC au
 * rapport HT/TTC du produit) moins la quantité × le coût de la recette du jour (adPrixCatalogue, products/available) —
 * la règle de « Développement » : une recette qui coûte plus que le prix, ou moins de 5 % de celui-ci, n'est pas crue.
 * Un produit dont le prix moyen encaissé s'écarte de plus de 15 % du prix de la pièce entière (vendu aussi en portions
 * ou en variantes) : CA HT × le taux de marge de la recette (`methode: taux`), au lieu de pièces × coût (`pieces`).
 */
function ep_dashboard_marketing_ventes_periode(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    if ($shop <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au < $du) {
        http_response_code(400); return ['error' => 'shop, du et au (AAAA-MM-JJ) attendus'];
    }
    if ((strtotime($au) - strtotime($du)) / 86400 > 400) { http_response_code(422); return ['error' => 'une période de 400 jours au plus'];
    }
    $pids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['pids'] ?? ''))), static fn ($x) => $x > 0)));
    if (!$pids || count($pids) > 200) { http_response_code(422); return ['error' => 'de 1 à 200 produits (pids)']; }
    if (!function_exists('apTranches2') || !function_exists('adPrixCatalogue') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'les ventes du panel ne se lisent pas'];
    }
    $auLu = min($au, date('Y-m-d'));
    $lu = apTranches2([[$shop, $du, $auLu]]);
    $p = $lu[$shop . ':' . $du] ?? null;
    if (!is_array($p)) { http_response_code(502); return ['error' => 'le panel n’a pas rendu les ventes de la période']; }
    $prix = adPrixCatalogue($shop);
    $out = []; $tot = ['q' => 0.0, 'ca' => 0.0, 'caHt' => 0.0, 'marge' => 0.0, 'sansMarge' => 0];
    foreach ($pids as $pid) {
        $x = $p[$pid] ?? null; $c = $prix[$pid] ?? null;
        $q = $x !== null ? (float) $x[2] : 0.0; $ca = $x !== null ? (float) $x[3] : 0.0;
        $caHt = null; $marge = null; $motif = null;
        if ($c !== null && $c['ht'] !== null && $c['ttc'] !== null && $c['ttc'] > 0) { $caHt = $ca * $c['ht'] / $c['ttc']; }
        if ($q <= 0) { $marge = 0.0; }
        elseif ($c === null || $c['ht'] === null) { $motif = 'prix du catalogue inconnu'; }
        elseif ($c['cout'] === null) { $motif = 'coût de la recette manquant'; }
        elseif ($c['cout'] >= $c['ht'] || $c['cout'] < 0.05 * $c['ht']) { $motif = 'recette à vérifier'; }
        elseif ($caHt !== null) {
            // Un produit vendu aussi en portions ou en variantes (prix moyen encaissé loin du prix de la pièce entière) :
            // pièces × coût de la recette entière gonflerait le coût ; on applique alors le taux de marge de la recette.
            $prixMoyen = $ca / $q;
            if ($c['ttc'] !== null && abs($prixMoyen / $c['ttc'] - 1) <= 0.15) { $marge = $caHt - $q * $c['cout']; $methode = 'pieces'; }
            else { $marge = $caHt * (1 - $c['cout'] / $c['ht']); $methode = 'taux'; }
        }
        $out[(string) $pid] = ['q' => round($q, 1), 'ca' => round($ca, 2), 'caHt' => $caHt !== null ? round($caHt, 2) : null,
            'marge' => $marge !== null ? round($marge, 2) : null, 'margePct' => $marge !== null && $caHt ? round(100 * $marge / $caHt, 1) : null, 'motif' => $motif,
            'methode' => $methode ?? null];
        unset($methode);
        $tot['q'] += $q; $tot['ca'] += $ca; $tot['caHt'] += (float) ($caHt ?? 0);
        if ($marge !== null) { $tot['marge'] += $marge; } elseif ($q > 0) { $tot['sansMarge']++; }
    }
    return ['shop' => (string) $shop, 'du' => $du, 'au' => $au, 'auLu' => $auLu, 'produits' => $out,
        'total' => ['q' => round($tot['q'], 1), 'ca' => round($tot['ca'], 2), 'caHt' => round($tot['caHt'], 2), 'marge' => round($tot['marge'], 2),
            'margePct' => $tot['caHt'] > 0 ? round(100 * $tot['marge'] / $tot['caHt'], 1) : null, 'sansMarge' => $tot['sansMarge']],
        'source' => 'ventes du panel (product-category-groups) ; marge : CA HT encaissé − quantité × coût de la recette du jour (products/available)'];
}

/**
 * GET /dashboard/marketing/campagnes/annexe?shop=4&id=12 — un document joint à la note d'une campagne en cours du magasin
 * (le PDF, servi par ep_mkt_annexe_fichier). Seuls les documents qui partent avec la note du franchisé s'ouvrent ici.
 */
function ep_dashboard_marketing_annexe(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $id = (int) ($_GET['id'] ?? 0);
    if (!preg_match('/^\d{1,6}$/', $shop) || $id <= 0) { http_response_code(400); return ['error' => 'shop et id attendus']; }
    if (!function_exists('ep_mkt_annexe_fichier')) { http_response_code(503); return ['error' => 'les annexes ne sont pas chargées']; }
    $a = Db::row('SELECT campaign_id, in_mail FROM mar_campagne_annexe WHERE id = ?', [$id]);
    $enCours = array_column(array_filter((array) (mhCampagnes($shop, date('Y-m-d'))['liste'] ?? []), static fn ($c) => ($c['phase'] ?? '') === 'en_cours'), 'id');
    if ($a === null || !(int) $a['in_mail'] || !in_array((int) $a['campaign_id'], array_map('intval', $enCours), true)) {
        http_response_code(404); return ['error' => 'document inconnu pour ce magasin'];
    }
    return ep_mkt_annexe_fichier($id);
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
    if ($method === 'GET' && $path === '/dashboard/marketing/campagnes') { return ep_dashboard_marketing_campagnes(); }
    if ($method === 'GET' && $path === '/dashboard/marketing/annee') { return ep_dashboard_marketing_annee(); }
    if ($method === 'GET' && $path === '/dashboard/marketing/ventes-periode') { return ep_dashboard_marketing_ventes_periode(); }
    if ($method === 'GET' && $path === '/dashboard/marketing/campagnes/annexe') { return ep_dashboard_marketing_annexe(); }
    // Vendre › Offres › Newsletter : le module newsletter du cockpit, au rôle du magasin (src/franchise/offres_newsletter.php).
    if (($n = dnRoute($method, $path)) !== null) { return $n; }
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
        return ['error' => 'Le lien avec le Marketing n’est pas réglé, au siège : ' . implode(' et ', array_filter([
            $base === '' ? 'l’adresse de l’API du Marketing (mar_api_url d’erp_cles, ou marketingApi de config.php)' : '',
            $jeton === '' ? 'le jeton de service (erp_jeton_service d’erp_cles)' : ''])) . '.'];
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
