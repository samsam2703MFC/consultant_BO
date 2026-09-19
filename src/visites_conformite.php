<?php
/**
 * Conformité du comptoir : le planogramme et l'assortiment obligatoire.
 *
 * Deux questions que le consultant pose à chaque visite, et auxquelles le
 * cockpit sait déjà répondre sans rien lui faire ressaisir :
 *
 *   - LE PLANOGRAMME. Le comptoir est dessiné dans le cockpit — zones, meubles,
 *     niveaux, emplacements — et chaque référence y a, ou n'y a pas, sa place.
 *     On compte les emplacements tenus et on dit quels comptoirs ont été
 *     photographiés montés aujourd'hui.
 *   - L'ASSORTIMENT. Les références marquées « must » doivent être proposées en
 *     permanence. On regarde ce que la caisse DE CE MAGASIN a vendu sur la
 *     fenêtre : une obligatoire qui n'a pas passé un seul ticket en trente
 *     jours n'est pas tenue, et on la nomme.
 *
 * Rien à saisir, aucune table nouvelle : les deux se lisent. Ce qui ne se lit
 * pas se tait plutôt que de se deviner — sans lignes de ticket, l'assortiment
 * rend son motif, pas un zéro qui passerait pour un constat.
 *
 * Deux identifiants cohabitent et ne se confondent pas : le planogramme place
 * des `ceo_prod_product.ref`, la caisse vend des `pwa_id`. Chaque obligatoire
 * porte les deux, et chaque jointure se fait avec le bon.
 */

/**
 * Les références obligatoires du catalogue.
 *
 * @return list<array{ref:string,nom:string,pwa:?string}>
 */
function vcObligatoires(): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT ref, nom, pwa_id FROM ceo_prod_product WHERE must = 1 AND actif = 1 ORDER BY nom') as $r) {
            $pwa = $r['pwa_id'] === null || (string) $r['pwa_id'] === '' ? null : (string) $r['pwa_id'];
            $out[] = ['ref' => (string) $r['ref'], 'nom' => (string) $r['nom'], 'pwa' => $pwa];
        }
    } catch (PDOException $e) { /* pas de catalogue de production : la conformité se taira */ }
    return $out;
}

/**
 * Ce que la caisse du magasin a réellement vendu sur la fenêtre.
 *
 * @return array<string,float>|null  pwa_id → quantité, ou null si la base
 *                                   n'expose pas les lignes de ticket.
 */
function vcVendues(string $shop, string $du, string $au): ?array
{
    if (!function_exists('utilColonnes')) { return null; }
    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
    if ($ct === null || $cl === null) { return null; }
    $sql = sprintf(
        'SELECT l.`%s` AS produit, SUM(l.`%s`) q
           FROM `transaction_product` l JOIN `transaction` t ON t.`%s` = l.`%s`
          WHERE t.`%s` = ? AND t.`%s` >= ? AND t.`%s` < ?
          GROUP BY produit',
        $cl['produit'], $cl['quantite'], $ct['id'], $cl['ticket'],
        $ct['shop'], $ct['date'], $ct['date']);
    $bornes = [$shop, $du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00'];
    try {
        $out = [];
        foreach (Db::rows($sql, $bornes) as $r) {
            $q = (float) $r['q'];
            if ($q > 0) { $out[(string) $r['produit']] = $q; }
        }
        return $out;
    } catch (PDOException $e) { return null; }
}

/**
 * Le dernier jour où la caisse de ce magasin a remonté une ligne de ticket.
 *
 * La fenêtre ne part pas d'aujourd'hui mais de là : une base arrêtée fin
 * juillet ferait dire à trente jours de silence que toutes les obligatoires
 * ont disparu du rayon, ce qui est un défaut de synchronisation, pas un
 * défaut d'assortiment.
 */
function vcDerniereVente(string $shop): ?string
{
    if (!function_exists('utilColonnes')) { return null; }
    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    if ($ct === null) { return null; }
    try {
        $r = Db::row(sprintf('SELECT MAX(`%s`) AS d FROM `transaction` WHERE `%s` = ?', $ct['date'], $ct['shop']), [$shop]);
    } catch (PDOException $e) { return null; }
    $d = $r === null ? null : (string) ($r['d'] ?? '');
    return $d !== null && $d !== '' ? substr($d, 0, 10) : null;
}

/**
 * Le comptoir tel qu'il est dessiné : emplacements, emplacements tenus, et
 * les références qui y ont une place.
 */
function vcComptoir(): array
{
    $out = ['zones' => 0, 'meubles' => 0, 'emplacements' => 0, 'tenus' => 0,
        'pct' => null, 'refs' => [], 'motif' => null];
    try {
        $out['zones'] = (int) (Db::row('SELECT COUNT(*) AS n FROM pla_zone')['n'] ?? 0);
        $out['meubles'] = (int) (Db::row('SELECT COUNT(*) AS n FROM pla_meuble')['n'] ?? 0);
        $out['emplacements'] = (int) (Db::row('SELECT COUNT(*) AS n FROM pla_slot')['n'] ?? 0);
        foreach (Db::rows('SELECT ref, slot_id FROM pla_placement WHERE slot_id IS NOT NULL') as $r) {
            $out['refs'][(string) $r['ref']] = (int) $r['slot_id'];
        }
    } catch (PDOException $e) {
        $out['motif'] = 'le comptoir n’est pas dessiné sur cette base';
        return $out;
    }
    // Un emplacement peut porter plusieurs références : ce sont les
    // emplacements distincts qui disent la tenue du meuble, pas les placements.
    $out['tenus'] = count(array_unique(array_values($out['refs'])));
    if ($out['emplacements'] > 0) {
        $out['pct'] = (int) round(100 * min($out['tenus'], $out['emplacements']) / $out['emplacements']);
    } elseif ($out['motif'] === null) {
        $out['motif'] = 'aucun emplacement n’est dessiné dans le planogramme';
    }
    return $out;
}

/** Les comptoirs photographiés montés aujourd'hui, zone par zone. */
function vcMontage(int $shop, string $jour): array
{
    try {
        if (function_exists('plaMontageTable')) { plaMontageTable(); }
        $noms = [];
        foreach (Db::rows('SELECT id, nom FROM pla_zone') as $z) { $noms[(int) $z['id']] = (string) $z['nom']; }
        $out = [];
        foreach (Db::rows('SELECT zone_id, auteur, quand FROM pla_montage WHERE shop_id = ? AND jour = ?', [$shop, $jour]) as $r) {
            $zid = (int) $r['zone_id'];
            $out[] = ['zone' => $zid, 'nom' => $noms[$zid] ?? ('zone ' . $zid),
                'auteur' => (string) $r['auteur'], 'quand' => (string) $r['quand']];
        }
        return $out;
    } catch (PDOException $e) { return []; }
}

/**
 * GET /visites/conformite?shop=4&jours=30 — le planogramme et l'assortiment.
 *
 * Lu pendant la visite : le consultant y trouve ce que le cockpit sait déjà du
 * comptoir, et n'a plus qu'à constater sur place ce que le cockpit ne peut pas
 * voir.
 */
function ep_visites_conformite(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $jours = max(7, min(180, (int) ($_GET['jours'] ?? 30)));
    $auj = date('Y-m-d');
    // La fenêtre se cale sur la dernière vente connue du magasin, et non sur
    // aujourd'hui : une base en retard rendrait sinon un rayon entièrement vide.
    $derniere = vcDerniereVente((string) $sid);
    $au = $derniere !== null && $derniere < $auj ? $derniere : $auj;
    $du = date('Y-m-d', strtotime($au . ' -' . ($jours - 1) . ' days'));
    $retard = $derniere === null ? null : (int) round((strtotime($auj) - strtotime($derniere)) / 86400);

    $comptoir = vcComptoir();
    $obl = vcObligatoires();
    $vendues = $derniere === null ? null : vcVendues((string) $sid, $du, $au);

    // --- L'assortiment. Présente = vue au moins une fois sur la fenêtre.
    $assort = ['obligatoires' => count($obl), 'presentes' => 0, 'manquantes' => 0,
        'pct' => null, 'liste' => [], 'du' => $du, 'au' => $au, 'jours' => $jours,
        'derniereVente' => $derniere, 'retard' => $retard, 'motif' => null];
    if ($obl === []) {
        $assort['motif'] = 'aucune référence n’est déclarée obligatoire pour le réseau';
    } elseif ($derniere === null) {
        $assort['motif'] = 'la caisse de ce magasin n’a remonté aucune ligne de ticket';
    } elseif ($vendues === null) {
        $assort['motif'] = 'la caisse n’expose pas ses lignes de ticket sur cette base';
    } elseif ($vendues === []) {
        $assort['motif'] = 'aucune vente sur la fenêtre : rien à conclure de l’assortiment';
    } else {
        $manquantes = []; $sansId = 0;
        foreach ($obl as $p) {
            // Sans identifiant de caisse, une obligatoire est illisible : on la
            // compte à part plutôt que de la déclarer absente à tort.
            if ($p['pwa'] === null) { $sansId++; continue; }
            if (isset($vendues[$p['pwa']])) { $assort['presentes']++; continue; }
            $manquantes[] = ['ref' => $p['ref'], 'nom' => $p['nom'],
                // Au comptoir sans passer en caisse : le plan lui donne une
                // place, le magasin ne la vend pas.
                'auComptoir' => isset($comptoir['refs'][$p['ref']])];
        }
        usort($manquantes, static fn ($a, $b) => ($b['auComptoir'] <=> $a['auComptoir']) ?: strcmp($a['nom'], $b['nom']));
        $assort['manquantes'] = count($manquantes);
        $assort['liste'] = array_slice($manquantes, 0, 40);
        $assort['sansIdentifiant'] = $sansId;
        $lisibles = count($obl) - $sansId;
        $assort['lisibles'] = $lisibles;
        if ($lisibles > 0) { $assort['pct'] = (int) round(100 * $assort['presentes'] / $lisibles); }
        else { $assort['motif'] = 'aucune obligatoire ne porte d’identifiant de caisse'; }
    }

    // --- Les obligatoires sans place au comptoir : le plan est en défaut avant
    // même qu'on regarde ce qui se vend.
    $sansPlace = [];
    foreach ($obl as $p) {
        if (!isset($comptoir['refs'][$p['ref']])) { $sansPlace[] = ['ref' => $p['ref'], 'nom' => $p['nom']]; }
    }
    $plano = ['zones' => $comptoir['zones'], 'meubles' => $comptoir['meubles'],
        'emplacements' => $comptoir['emplacements'], 'tenus' => $comptoir['tenus'],
        'pct' => $comptoir['pct'], 'motif' => $comptoir['motif'],
        'obligatoiresSansPlace' => $comptoir['motif'] === null ? count($sansPlace) : 0,
        'sansPlace' => $comptoir['motif'] === null ? array_slice($sansPlace, 0, 40) : [],
        'montage' => vcMontage($sid, $au)];
    $plano['comptoirsMontes'] = count($plano['montage']);

    return ['shop' => (string) $sid, 'jour' => $auj, 'lu' => date('Y-m-d H:i'),
        'planogramme' => $plano, 'assortiment' => $assort,
        'source' => ['planogramme' => 'cockpit — comptoir dessiné, placements et photos de montage',
            'assortiment' => 'catalogue (références obligatoires) × lignes de ticket du magasin']];
}
