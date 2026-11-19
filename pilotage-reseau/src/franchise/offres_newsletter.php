<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Vendre › Offres › Newsletter (15/11/2026, « dans Offres rajouter un onglet newsletter »).
 *
 * Les newsletters du magasin : le module newsletter du cockpit (src/newsletter.php, src/newsletter_envoi.php), dans son
 * rôle MAGASIN, affiché par l'écran du cockpit (public/assets/js/newsletter.js, `CockpitNewsletter`). Le module reste en
 * mode test tant que la marque n'a pas lancé l'envoi : rien ne part, l'écran le dit, et une base sans contact importé
 * est dite « jeu d'essai ».
 *
 * Le rôle n'est jamais celui que la requête annonce : c'est le magasin de la requête (`role`, sinon `shop`), vérifié
 * contre la session par cmBoutique (src/franchise/catalogues_magasin.php) ; « brand » n'existe pas sous /dashboard. Le
 * module applique ensuite ses droits du franchisé : ses segments et ceux de tout le réseau, son adresse d'expéditeur,
 * ses campagnes ; ni segment, ni automatisation, ni réglage, ni import.
 *
 *   GET    /dashboard/marketing/newsletter?role=4                       ce que l'écran affiche
 *   POST   /dashboard/marketing/newsletter/campagnes                   {role, …} programmer ou garder en brouillon
 *   DELETE /dashboard/marketing/newsletter/campagnes/{id}?role=4       retirer un brouillon ou une campagne programmée
 *   POST   /dashboard/marketing/newsletter/campagnes/{id}/envoyer      {role} envoyer maintenant (l'horloge, mode test)
 *   POST   /dashboard/marketing/newsletter/test                        {role, …} le test aux adresses de la marque
 *   PUT    /dashboard/marketing/newsletter/magasins/{id}               {role, senderName, email} l'expéditeur du magasin
 */

const DN_PREFIXE = '/dashboard/marketing/newsletter';
/** Les bases qu'un magasin ne voit pas : les candidats à la franchise (CRM) sont l'affaire du réseau. */
const DN_SOURCES_SIEGE = ['crm'];

function dnRoute(string $method, string $path): ?array
{
    if ($path !== DN_PREFIXE && !str_starts_with($path, DN_PREFIXE . '/')) { return null; }
    if (!function_exists('ep_newsletter')) { http_response_code(503); return ['error' => 'le module newsletter n’est pas chargé']; }

    // Le magasin : celui que la requête nomme, vérifié contre la session. Le rôle du module est posé ICI, dans $_GET,
    // que nlRole() lit avant le corps : un « role: brand » glissé dans le corps n'y change rien.
    $b = in_array($method, ['POST', 'PUT', 'DELETE'], true) ? body() : [];
    $demande = trim((string) ($_GET['role'] ?? ($b['role'] ?? ($_GET['shop'] ?? ($b['shop'] ?? '')))));
    if (!preg_match('/^\d{1,10}$/', $demande)) { http_response_code(422); return ['error' => 'Le magasin est attendu (role).']; }
    $_GET['shop'] = $demande;
    [$shop, $err] = cmBoutique();
    if ($err !== null) { return $err; }
    $_GET['role'] = (string) $shop;

    $sous = substr($path, strlen(DN_PREFIXE));
    if ($method === 'GET' && $sous === '') { return dnLecture(ep_newsletter()); }
    if ($method === 'POST' && $sous === '/campagnes') {
        $seg = Db::row('SELECT source_id FROM mar_nl_segment WHERE id = ?', [mb_substr(trim((string) ($b['segment'] ?? '')), 0, 40)]);
        if ($seg !== null && in_array((string) $seg['source_id'], DN_SOURCES_SIEGE, true)) { http_response_code(403); return ['error' => 'Cette base est celle du réseau.']; }
        return wr_newsletter_campagne_post();
    }
    if ($method === 'DELETE' && preg_match('#^/campagnes/(\d+)$#', $sous, $m)) { return wr_newsletter_campagne_delete((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/campagnes/(\d+)/envoyer$#', $sous, $m)) { return wr_newsletter_campagne_envoyer((int) $m[1]); }
    if ($method === 'POST' && $sous === '/test') { return wr_newsletter_test_post(); }
    if ($method === 'PUT' && preg_match('#^/magasins/(\d{1,10})$#', $sous, $m)) {
        if ($m[1] !== (string) $shop) { http_response_code(403); return ['error' => 'Un magasin ne modifie que son adresse d’expéditeur.']; }
        return wr_newsletter_magasin_put($m[1]);
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}

/**
 * La lecture du module, sans ce qui est l'affaire de la marque : les réglages et les lots (le module ne les rend déjà
 * pas au rôle magasin), et la base des candidats à la franchise, avec ses segments et ses opt-in.
 */
function dnLecture(array $d): array
{
    unset($d['reglages'], $d['lots']);
    $d['sources'] = array_values(array_filter((array) ($d['sources'] ?? []), static fn ($s) => !in_array((string) ($s['id'] ?? ''), DN_SOURCES_SIEGE, true)));
    $d['segments'] = array_values(array_filter((array) ($d['segments'] ?? []), static fn ($s) => !in_array((string) ($s['src'] ?? ''), DN_SOURCES_SIEGE, true)));
    if (isset($d['chiffres']) && is_array($d['chiffres'])) { $d['chiffres']['optin'] = array_sum(array_map(static fn ($s) => (int) ($s['optin'] ?? 0), $d['sources'])); }
    return $d;
}
