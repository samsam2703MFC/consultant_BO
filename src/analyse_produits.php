<?php

declare(strict_types=1);

/**
 * Analyse produits × magasins — full endpoints.
 *
 * La grille d'entrée (chaque référence, où elle se vend, mini-courbe par
 * magasin) et la fiche de vie d'un produit se nourrissent de la seule route
 * /shops/{id}/statistics/sales/product-category-groups?date_from&date_to,
 * lue par TRANCHES : semaines pour 1-6 mois, mois pour 12 — le « graphique
 * fin » sans noyer l'API. Une tranche close se lit une fois et se grave
 * (ceo_app_setting.apB…) ; la tranche en cours expire à l'heure.
 */

/** Les tranches de la période : [du, au, libellé], du plus ancien au plus récent. */
function apTranches(int $mois): array
{
    $out = [];
    if ($mois <= 6) {
        // Des SEMAINES pleines (lundi → dimanche), la dernière coupée à aujourd'hui.
        $lundi = strtotime('monday this week');
        $n = (int) ceil($mois * 30.5 / 7);
        for ($i = $n - 1; $i >= 0; $i--) {
            $du = date('Y-m-d', strtotime("-$i week", $lundi));
            $au = min(date('Y-m-d', strtotime($du . ' +6 days')), date('Y-m-d'));
            $out[] = [$du, $au, 'S' . date('W', strtotime($du))];
        }
    } else {
        for ($i = 11; $i >= 0; $i--) {
            $t = strtotime(date('Y-m-01') . " -$i month");
            $du = date('Y-m-01', $t);
            $au = min(date('Y-m-t', $t), date('Y-m-d'));
            $out[] = [$du, $au, strftime_fr($t, 'M')];
        }
    }
    return $out;
}

/** Condense une réponse product-category-groups : pid → [nom, cat, qté, CA]. */
function apCondense(array $r): array
{
    $p = [];
    foreach (analyseListe($r) as $l) {
        $pid = (int) ($l['product_id'] ?? 0);
        if ($pid <= 0) { continue; }
        if (!isset($p[$pid])) {
            $p[$pid] = [trim((string) ($l['product_name'] ?? ('Produit ' . $pid))),
                trim((string) ($l['category_name'] ?? '')), 0.0, 0.0];
        }
        $p[$pid][2] += (float) ($l['sold_qty'] ?? 0);
        $p[$pid][3] += (float) ($l['sales_value'] ?? ($l['total_earning'] ?? 0));
    }
    return $p;
}

/**
 * Les catégories DÉSACTIVÉES du catalogue, par nom minuscule.
 *
 * `/product-categories` porte `is_active` : une catégorie retirée du
 * catalogue continue de traîner dans l'historique des ventes (bases, cartons
 * B2B, gammes arrêtées) et fausserait toute comparaison d'assortiment. On
 * garde la liste six heures ; si la route ne répond pas, on ne filtre RIEN —
 * mieux vaut une analyse large qu'une analyse amputée en silence.
 *
 * @return array<string, true>|null  null = liste indisponible, ne pas filtrer
 */
function apCategoriesInactives(): ?array
{
    static $memo = false;
    if ($memo !== false) { return $memo; }
    $cache = setting('apCatInactives');
    if (is_array($cache) && isset($cache['quand'], $cache['noms'])
        && (int) $cache['quand'] > time() - 21600) {
        return $memo = array_fill_keys((array) $cache['noms'], true);
    }
    $r = PanelApi::get('/product-categories');
    if (!is_array($r)) { return $memo = null; }
    $noms = [];
    foreach (analyseListe($r) as $c) {
        $nom = mb_strtolower(trim((string) ($c['name'] ?? '')));
        if ($nom === '') { continue; }
        if (isset($c['is_active']) && !(int) $c['is_active']) { $noms[] = $nom; }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['apCatInactives', json_encode(['quand' => time(), 'noms' => $noms], JSON_UNESCAPED_UNICODE)]);
    return $memo = array_fill_keys($noms, true);
}

/**
 * Les tranches demandées, pour PLUSIEURS couples magasin × période à la fois :
 * cache d'abord, puis un seul voyage parallèle pour les manquantes. Chaque
 * tranche close se grave ; celle en cours expire à l'heure. Une tranche que
 * l'API n'a pas servie vaut null.
 *
 * @param array<int,array{0:int,1:string,2:string}> $couples [sid, du, au]
 * @return array<string,?array>  clé « sid:du » → pid → [nom, cat, qté, CA]
 */
function apTranches2(array $couples): array
{
    $out = []; $chemins = [];
    foreach ($couples as [$sid, $du, $au]) {
        $k = $sid . ':' . $du;
        $cache = setting('apB' . $sid . ':' . $du . ':' . $au);
        $close = $au < date('Y-m-d');
        if (is_array($cache) && isset($cache['p'])
            && ($close || (int) ($cache['quand'] ?? 0) > time() - 3600)) {
            $out[$k] = $cache['p'];
            continue;
        }
        $chemins[$k] = '/shops/' . $sid . '/statistics/sales/product-category-groups?'
            . http_build_query(['date_from' => $du, 'date_to' => $au]);
    }
    if ($chemins !== []) {
        $res = PanelApi::getParallele($chemins, 8);
        foreach ($couples as [$sid, $du, $au]) {
            $k = $sid . ':' . $du;
            if (isset($out[$k]) || !isset($chemins[$k])) { continue; }
            $r = $res[$k] ?? null;
            if (!is_array($r)) { $out[$k] = null; continue; }
            $p = apCondense($r);
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['apB' . $sid . ':' . $du . ':' . $au,
                 json_encode(['quand' => time(), 'p' => $p], JSON_UNESCAPED_UNICODE)]);
            $out[$k] = $p;
        }
    }
    // Le tri des catégories désactivées se fait à la LECTURE, pas à la mise en
    // cache : les tranches gravées avant cette règle en profitent aussi, sans
    // qu'il faille les rejeter.
    $morte = apCategoriesInactives();
    if ($morte !== null && $morte !== []) {
        foreach ($out as $k => $p) {
            if (!is_array($p)) { continue; }
            foreach ($p as $pid => $x) {
                if (isset($morte[mb_strtolower(trim((string) $x[1]))])) { unset($out[$k][$pid]); }
            }
        }
    }
    return $out;
}

/**
 * GET /analyse/produits?mois=3 — la grille entière : chaque référence, sa
 * série par magasin et la moyenne réseau, tranche par tranche.
 * Avec ?pid= : la MÊME période un an plus tôt, réduite à ce produit — la
 * ligne « an dernier » de la fiche.
 */
function ep_analyse_produits(): array
{
    if (!PanelApi::configured()) {
        return ['indispo' => true, 'motif' => 'compte panel non configuré (Mon compte)'];
    }
    $mois = (int) ($_GET['mois'] ?? 3);
    if (!in_array($mois, [1, 3, 6, 12], true)) { $mois = 3; }
    $shops = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $shops[(int) $s['id']] = (string) $s['name'];
    }
    $tranches = apTranches($mois);

    // La fiche : l'an dernier d'UN produit — mêmes tranches, décalées d'un an.
    $pidAd = (int) ($_GET['pid'] ?? 0);
    if ($pidAd > 0) {
        $couples = [];
        foreach ($tranches as [$du, $au, $lib]) {
            foreach (array_keys($shops) as $sid) {
                $couples[] = [$sid, date('Y-m-d', strtotime($du . ' -1 year')),
                    date('Y-m-d', strtotime($au . ' -1 year'))];
            }
        }
        $lu = apTranches2($couples);
        $serie = [];
        foreach ($tranches as [$du, $au, $lib]) {
            $du2 = date('Y-m-d', strtotime($du . ' -1 year'));
            $tot = 0.0; $servis = 0;
            foreach (array_keys($shops) as $sid) {
                $p = $lu[$sid . ':' . $du2] ?? null;
                if ($p === null) { continue; }
                $servis++;
                $tot += (float) ($p[$pidAd][2] ?? 0);
            }
            $serie[] = $servis > 0 ? round($tot / max(1, count($shops)), 1) : null;
        }
        return ['pid' => $pidAd, 'anDernier' => $serie];
    }

    // La grille : toutes les tranches de tous les magasins, en un voyage
    // parallèle pour les manquantes — les closes sont gravées, seul le
    // premier passage paie.
    $couples = [];
    foreach ($shops as $sid => $nom) {
        foreach ($tranches as [$du, $au, $lib]) { $couples[] = [$sid, $du, $au]; }
    }
    $lu = apTranches2($couples);
    $prods = []; $muets = 0; $tailles = [];
    foreach ($shops as $sid => $nom) {
        $tailles[$sid] = 0.0;
        foreach ($tranches as $iT => [$du, $au, $lib]) {
            $p = $lu[$sid . ':' . $du] ?? null;
            if ($p === null) { $muets++; continue; }
            foreach ($p as $pid => $x) {
                $tailles[$sid] += (float) $x[3];
                if (!isset($prods[$pid])) {
                    $prods[$pid] = ['pid' => $pid, 'nom' => $x[0], 'cat' => $x[1],
                        'total' => 0.0, 'ca' => 0.0, 'parShop' => []];
                }
                if (!isset($prods[$pid]['parShop'][$sid])) {
                    $prods[$pid]['parShop'][$sid] = array_fill(0, count($tranches), 0);
                }
                $prods[$pid]['parShop'][$sid][$iT] = round((float) $x[2], 1);
                $prods[$pid]['total'] += (float) $x[2];
                $prods[$pid]['ca'] += (float) $x[3];
            }
        }
    }
    if ($prods === []) {
        return ['indispo' => true,
            'motif' => $muets > 0 ? 'les endpoints du panel n’ont pas répondu' : 'aucune vente sur la période'];
    }
    usort($prods, static fn ($a, $b) => $b['total'] <=> $a['total']);
    $prods = array_slice(array_values($prods), 0, 400);
    foreach ($prods as $i => $p) {
        $prods[$i]['total'] = round($p['total'], 0);
        $prods[$i]['ca'] = round($p['ca'], 0);
    }
    $cats = array_values(array_unique(array_filter(array_map(fn ($p) => $p['cat'], $prods))));
    sort($cats);
    return ['mois' => $mois,
        'tranches' => array_map(fn ($t) => $t[2], $tranches),
        'pas' => $mois <= 6 ? 'semaine' : 'mois',
        'magasins' => array_map(fn ($id, $n) => ['id' => $id, 'nom' => $n], array_keys($shops), $shops),
        'categories' => $cats, 'produits' => $prods,
        // La TAILLE de chaque magasin (son CA total sur la période) : le
        // delta de la grille se pondère avec — un petit magasin ne sort
        // plus mécaniquement en rouge.
        'tailles' => array_map(fn ($v) => round($v, 0), $tailles),
        'muets' => $muets];
}

/**
 * GET /analyse/prix-transfert?source=2&cible=4&m=2026-08
 *
 * « Si j'appliquais les prix de tel magasin à tel autre, qu'est-ce que ça
 * changerait ? » — à volumes INCHANGÉS : chaque référence vendue par la cible
 * est revalorisée au prix encaissé par la source, l'écart se somme.
 *
 * Le prix encaissé (CA ÷ pièces, remises comprises) plutôt que le tarif
 * affiché : c'est ce que le client paie réellement, promotions et gestes
 * commerciaux inclus. Une référence que la source ne vend pas n'a pas de prix
 * chez elle : elle reste au prix de la cible et se compte à part, sans quoi le
 * total mélangerait un écart de prix avec un trou d'assortiment.
 */
function ep_prix_transfert(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'compte panel non configuré']; }
    $src = (int) ($_GET['source'] ?? 0);
    $cib = (int) ($_GET['cible'] ?? 0);
    $m = trim((string) ($_GET['m'] ?? date('Y-m', strtotime('first day of last month'))));
    if (!preg_match('/^\d{4}-\d{2}$/', $m) || $src <= 0 || $cib <= 0 || $src === $cib) {
        http_response_code(422);
        return ['error' => 'source, cible (deux magasins distincts) et m (YYYY-MM) sont requis'];
    }
    $du = $m . '-01';
    $au = min(date('Y-m-t', strtotime($du)), date('Y-m-d', strtotime('-1 day')));
    $lu = apTranches2([[$src, $du, $au], [$cib, $du, $au]]);
    $pS = $lu[$src . ':' . $du] ?? null;
    $pC = $lu[$cib . ':' . $du] ?? null;
    if (!is_array($pS) || !is_array($pC)) {
        return ['indispo' => true, 'motif' => 'les endpoints du panel n’ont pas répondu pour la période'];
    }
    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE id IN (?, ?)', [$src, $cib]) as $s) {
            $noms[(int) $s['id']] = trim((string) array_reverse(explode(' - ', (string) $s['name']))[0]);
        }
    } catch (PDOException $e) { /* noms facultatifs */ }

    $delta = 0.0; $caCible = 0.0; $lignes = []; $sansPrix = 0; $caSansPrix = 0.0;
    foreach ($pC as $pid => $c) {
        $qC = (float) $c[2]; $caC = (float) $c[3];
        if ($qC <= 0) { continue; }
        $caCible += $caC;
        $prixC = $caC / $qC;
        $s = $pS[$pid] ?? null;
        $qS = $s !== null ? (float) $s[2] : 0.0;
        if ($s === null || $qS <= 0) { $sansPrix++; $caSansPrix += $caC; continue; }
        $prixS = (float) $s[3] / $qS;
        $ec = ($prixS - $prixC) * $qC;
        $delta += $ec;
        $lignes[] = ['pid' => (int) $pid, 'nom' => (string) $c[0], 'cat' => (string) $c[1],
            'pieces' => round($qC, 1), 'prixCible' => round($prixC, 2), 'prixSource' => round($prixS, 2),
            'ecartPiece' => round($prixS - $prixC, 2), 'impact' => round($ec, 2)];
    }
    usort($lignes, fn ($a, $b) => abs($b['impact']) <=> abs($a['impact']));
    return [
        'm' => $m, 'du' => $du, 'au' => $au,
        'source' => ['id' => $src, 'nom' => $noms[$src] ?? ('#' . $src)],
        'cible' => ['id' => $cib, 'nom' => $noms[$cib] ?? ('#' . $cib)],
        'caCible' => round($caCible, 2),
        'delta' => round($delta, 2),
        'deltaPct' => $caCible > 0 ? round($delta / $caCible * 100, 2) : null,
        'refs' => count($lignes),
        'refsSansPrixSource' => $sansPrix, 'caRefsSansPrix' => round($caSansPrix, 2),
        // Le filtre catalogue, rendu vérifiable : combien de catégories sont
        // désactivées, et si la liste a bien été lue. « null » dirait que la
        // route n'a pas répondu et qu'AUCUN tri n'a eu lieu.
        'catalogue' => ($ci = apCategoriesInactives()) === null
            ? ['filtre' => false, 'motif' => 'liste des catégories indisponible']
            : ['filtre' => true, 'categoriesInactives' => count($ci)],
        'hausses' => array_values(array_filter($lignes, fn ($l) => $l['impact'] > 0)),
        'baisses' => array_values(array_filter($lignes, fn ($l) => $l['impact'] < 0)),
        'top' => array_slice($lignes, 0, 25),
    ];
}

/* ---------------------------------------------------------------------------
 * La revue franchiseur et l'arbitrage de gamme.
 * ------------------------------------------------------------------------- */

/** Les revues posées, par identifiant de référence. */
function revuesProduits(): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT id_produit, note, necessaire, auteur, maj FROM ceo_prod_revue') as $r) {
            $out[(string) $r['id_produit']] = ['note' => $r['note'] !== null ? (int) $r['note'] : null,
                'necessaire' => (int) ($r['necessaire'] ?? 0) === 1, 'auteur' => $r['auteur'], 'maj' => $r['maj']];
        }
    } catch (PDOException $e) { /* table absente : aucune revue */ }
    return $out;
}

/** GET /products/scoring — les références scorées, chacune avec sa revue. */
function ep_products_revue(): array
{
    $liste = ep_products();
    $rev = revuesProduits();
    foreach ($liste as $i => $p) {
        $r = $rev[(string) ($p['id'] ?? '')] ?? null;
        $liste[$i]['revue'] = $r['note'] ?? null;
        $liste[$i]['necessaire'] = (bool) ($r['necessaire'] ?? false);
        $liste[$i]['revuePar'] = $r['auteur'] ?? null;
        $liste[$i]['revueLe'] = isset($r['maj']) ? substr((string) $r['maj'], 0, 16) : null;
    }
    return $liste;
}

/**
 * PUT /products/{id}/revue {note} — poser (1 à 5) ou retirer (null) la revue
 * d'une référence. Elle pèse dans le score dès le prochain calcul.
 */
function wr_prod_revue(string $id): array
{
    $b = body();
    $id = mb_substr(trim($id), 0, 24);
    if ($id === '') { http_response_code(422); return ['error' => 'référence manquante']; }
    $note = $b['note'] ?? null;
    if ($note === null || $note === '' || (int) $note === 0) {
        // Retirer la revue sans toucher au drapeau « nécessaire » : la ligne
        // ne disparaît que si plus rien ne la justifie.
        Db::exec('UPDATE ceo_prod_revue SET note = NULL, maj = ? WHERE id_produit = ?', [date('Y-m-d H:i:s'), $id]);
        Db::exec('DELETE FROM ceo_prod_revue WHERE id_produit = ? AND note IS NULL AND necessaire = 0', [$id]);
        return ['ok' => true, 'id' => $id, 'note' => null];
    }
    $note = (int) $note;
    if ($note < 1 || $note > 5) { http_response_code(422); return ['error' => 'la revue va de 1 à 5']; }
    $auteur = mb_substr(trim((string) ($b['auteur'] ?? '')), 0, 80) ?: null;
    Db::exec('INSERT INTO ceo_prod_revue (id_produit, note, auteur, maj) VALUES (?,?,?,?)
              ON DUPLICATE KEY UPDATE note = VALUES(note), auteur = VALUES(auteur), maj = VALUES(maj)',
        [$id, $note, $auteur, date('Y-m-d H:i:s')]);
    return ['ok' => true, 'id' => $id, 'note' => $note];
}

/**
 * PUT /products/{id}/necessaire {necessaire} — cocher ou décocher « nécessaire » :
 * une référence à garder quoi qu'en disent la marge et le score (un produit
 * d'appel, une pièce de gamme, une obligation d'enseigne). La revue posée
 * reste intacte dans les deux sens.
 */
function wr_prod_necessaire(string $id): array
{
    $b = body();
    $id = mb_substr(trim($id), 0, 24);
    if ($id === '') { http_response_code(422); return ['error' => 'référence manquante']; }
    $on = filter_var($b['necessaire'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    Db::exec('INSERT INTO ceo_prod_revue (id_produit, note, necessaire, auteur, maj) VALUES (?,NULL,?,?,?)
              ON DUPLICATE KEY UPDATE necessaire = VALUES(necessaire), maj = VALUES(maj)',
        [$id, $on, mb_substr(trim((string) ($b['auteur'] ?? '')), 0, 80) ?: null, date('Y-m-d H:i:s')]);
    Db::exec('DELETE FROM ceo_prod_revue WHERE id_produit = ? AND note IS NULL AND necessaire = 0', [$id]);
    return ['ok' => true, 'id' => $id, 'necessaire' => $on === 1];
}

/**
 * POST /products/arbitrage.pdf — le PDF « garder / modifier / effacer ».
 *
 * L'écran envoie SES lignes, score compris : le score est calculé une seule
 * fois, côté écran, et le papier ne peut pas dire autre chose que lui. Le
 * serveur ne fait que ranger en trois listes selon les seuils reçus et
 * mettre en page.
 */
function wr_prod_arbitrage_pdf(): array
{
    $b = body();
    $garder = (float) ($b['seuils']['garder'] ?? 70);
    $modifier = (float) ($b['seuils']['modifier'] ?? 50);
    $lignes = is_array($b['lignes'] ?? null) ? $b['lignes'] : [];
    $lib = mb_substr(trim((string) ($b['periode'] ?? '')), 0, 60) ?: strftime_fr(time(), 'M Y');
    $pond = mb_substr(trim((string) ($b['ponderation'] ?? '')), 0, 160);
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $listes = ['garder' => [], 'modifier' => [], 'effacer' => []];
    $nNec = 0; $nSans = 0;
    foreach ($lignes as $l) {
        if (!is_array($l)) { continue; }
        $s = (float) ($l['score'] ?? 0);
        // « Nécessaire » prime sur le score : la référence part dans « garder »
        // quoi qu'en dise sa marge, et la ligne le dit.
        $nec = filter_var($l['necessaire'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($nec) { $nNec++; }
        if (filter_var($l['sansVente'] ?? false, FILTER_VALIDATE_BOOLEAN)) { $nSans++; }
        $cle = $nec ? 'garder' : ($s > $garder ? 'garder' : ($s >= $modifier ? 'modifier' : 'effacer'));
        $listes[$cle][] = $l;
    }
    foreach ($listes as $k => $l) { usort($listes[$k], fn ($a, $b2) => (float) ($b2['score'] ?? 0) <=> (float) ($a['score'] ?? 0)); }
    $etoiles = function ($n) {
        $n = (int) $n;
        if ($n <= 0) { return '<span style="color:#b8b2a8;letter-spacing:1px">&#9734;&#9734;&#9734;&#9734;&#9734;</span>'; }
        return '<span style="color:#C9A227;letter-spacing:1px">' . str_repeat('&#9733;', min(5, $n)) . '</span>'
            . '<span style="color:#d9d2c6;letter-spacing:1px">' . str_repeat('&#9734;', max(0, 5 - $n)) . '</span>';
    };
    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:10.5pt}
      table.t{border-collapse:collapse;width:100%;font-size:8.5pt}
      table.t th{font-size:7pt;text-transform:uppercase;letter-spacing:0.05em;color:#8b8177;text-align:right;padding:1.2mm 1.6mm;border-bottom:0.5pt solid #E5E0D8}
      table.t td{padding:1.3mm 1.6mm;border-bottom:0.4pt solid #F0EDE7;text-align:right}
      table.t th.l,table.t td.l{text-align:left}
      table.t tr{page-break-inside:avoid}
      .bande{margin-top:5mm;padding:1.8mm 3mm;border-left:4px solid;page-break-after:avoid}
      .bloc{page-break-inside:avoid}
    </style>';
    $logo = rapLogoDataUri();
    $h = $css . '<div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2.5px solid #8D1D2C;padding-bottom:2mm;margin-bottom:4mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:34px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:8.5pt;color:#7a736a;line-height:1.5"><b style="color:#221E1A;font-size:10.5pt">Arbitrage de gamme : réseau</b><br>' . $e($lib) . ' · scoring produits</td></tr></table>'
        . '<div style="font-family:Georgia,serif;font-size:19pt;margin:1mm 0 0.5mm">La gamme en trois listes</div>'
        . '<div style="font-size:8.5pt;color:#5d564e;margin-bottom:3mm">' . ($pond !== '' ? 'Score : ' . $e($pond) . '. ' : '')
        . 'Revue : la note franchiseur de 1 à 5 étoiles, posée sur l’écran Scoring, qui pèse dans le score.'
        . ($nSans > 0 ? ' <b>Sans vente</b> (' . $nSans . ') : au catalogue, mais rien de vendu sur la période.' : '')
        . ($nNec > 0 ? ' <b>Nécessaire</b> (' . $nNec . ') : gardée quoi qu’en disent la marge et le score.' : '')
        . ' La case « Validé » se coche en réunion.</div>';
    $tuile = fn (string $cap, int $n, string $coul, string $crit) => '<td width="33%" style="border:1.2px solid #E8C9A0;background:#FFF9EC;border-radius:3mm;padding:2.6mm 2mm;text-align:center">'
        . '<div style="font-size:7.5pt;font-weight:bold;letter-spacing:0.09em;color:#8b8177">' . $cap . '</div>'
        . '<div style="font-family:Georgia,serif;font-size:14pt;color:' . $coul . '">' . $n . '</div>'
        . '<div style="font-size:7pt;color:#5d564e">' . $e($crit) . '</div></td>';
    $h .= '<table width="100%" cellpadding="0" cellspacing="4"><tr>'
        . $tuile('GARDER', count($listes['garder']), '#2d7a3e', 'score > ' . $garder)
        . $tuile('MODIFIER', count($listes['modifier']), '#b8671a', 'score de ' . $modifier . ' à ' . $garder)
        . $tuile('EFFACER', count($listes['effacer']), '#C0182B', 'score < ' . $modifier) . '</tr></table>';
    $sections = [
        ['garder', 'Garder', '#2d7a3e', '#e6f2e8', 'score > ' . $garder],
        ['modifier', 'Modifier — prix, recette ou mise en avant', '#b8671a', '#fdf2e5', 'score de ' . $modifier . ' à ' . $garder],
        ['effacer', 'Effacer', '#C0182B', '#fbebed', 'score < ' . $modifier],
    ];
    foreach ($sections as [$cle, $titre, $coul, $fond, $crit]) {
        $l = $listes[$cle];
        $h .= '<div class="bande" style="background:' . $fond . ';border-color:' . $coul . '"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-family:Georgia,serif;font-size:14pt;color:' . $coul . '">' . $e($titre) . '</td>'
            . '<td align="right" style="font-size:9pt;color:#5d564e">' . $e($crit) . ' · <b>' . count($l) . ' référence(s)</b></td></tr></table></div>';
        if ($l === []) { $h .= '<div style="font-size:9pt;color:#8b8177;margin:1mm 0 0 1mm">Aucune référence.</div>'; continue; }
        $h .= '<table class="t"><tr><th class="l">Référence</th><th class="l">Catégorie</th><th class="l">Fournisseur</th><th>Volume</th><th>Marge</th><th>Taux</th><th>Perte</th><th>Score</th><th style="text-align:center">Revue</th><th style="text-align:center">Validé</th></tr>';
        foreach ($l as $r) {
            $necL = filter_var($r['necessaire'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $sansV = filter_var($r['sansVente'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $h .= '<tr><td class="l" style="font-weight:bold">' . $e($r['nom'] ?? '')
                . ($necL ? ' <span style="font-size:6.5pt;font-weight:normal;color:#2d7a3e;border:0.6pt solid #bfdcc5;border-radius:2mm;padding:0 1.4mm;vertical-align:0.3mm">nécessaire</span>' : '')
                . ($sansV ? ' <span style="font-size:6.5pt;font-weight:normal;color:#8b8177;border:0.6pt solid #d9d2c6;border-radius:2mm;padding:0 1.4mm;vertical-align:0.3mm">sans vente</span>' : '')
                . '</td><td class="l" style="color:#8b8177">' . $e($r['cat'] ?? '') . '</td>'
                . '<td class="l" style="color:#8b8177">' . $e($r['fourn'] ?? '') . '</td>'
                . '<td>' . $e($r['vol'] ?? '') . '</td><td>' . $e($r['marge'] ?? '') . '</td><td>' . $e($r['taux'] ?? '') . '</td><td>' . $e($r['perte'] ?? '') . '</td>'
                . '<td style="font-weight:bold;color:' . $coul . '">' . (int) round((float) ($r['score'] ?? 0)) . '</td>'
                . '<td style="text-align:center;font-size:9pt">' . $etoiles($r['revue'] ?? 0) . '</td>'
                . '<td style="text-align:center"><span style="display:inline-block;width:3.6mm;height:3.6mm;border:0.6pt solid #8b8177;border-radius:0.8mm"></span></td></tr>';
        }
        $h .= '</table>';
    }
    $h .= '</div>';
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Arbitrage de gamme</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => 'Réseau', 'rapport' => 'Arbitrage de gamme ' . $lib,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="arbitrage-gamme-' . date('Y-m-d') . '.pdf"');
    echo $pdf;
    exit;
}

/* ---------------------------------------------------------------------------
 * Le fournisseur de chaque référence.
 *
 * Le panel ne porte aucun fournisseur sur le produit. La chaîne existe
 * pourtant : produit → recette (id_recipe) → matières (/recipes/{id}) →
 * fournisseur (/material-suppliers/{id}/materials). On la remonte une fois,
 * par passes bornées dans le temps (l'écran rappelle tant que ce n'est pas
 * fini), et on grave le résultat sept jours. Une référence peut tenir de
 * plusieurs fournisseurs (le pain d'un sandwich et sa garniture) : on garde
 * la liste, dans l'ordre de la recette.
 * ------------------------------------------------------------------------- */

/** La carte gravée : pid → [fournisseurs]. Vide tant que rien n'est construit. */
function fournisseursCarte(): array
{
    $c = setting('apFournMap');
    if (!is_array($c) || !isset($c['produits']) || !is_array($c['produits'])) { return []; }
    $out = [];
    foreach ($c['produits'] as $pid => $l) { $out[(int) $pid] = array_values((array) $l); }
    return $out;
}

/**
 * GET /products/fournisseurs — construit (ou poursuit) la carte, dans un
 * budget de temps, et la rend. `pret` dit si tout le catalogue est couvert ;
 * sinon l'écran rappelle. `?refaire=1` repart de zéro.
 */
function ep_products_fournisseurs(): array
{
    $budget = max(3, min(40, (int) ($_GET['budget'] ?? 18)));
    $debut = microtime(true);
    $c = setting('apFournMap');
    $frais = is_array($c) && isset($c['quand'], $c['produits'], $c['restants'], $c['matieres'])
        && (int) $c['quand'] > time() - 7 * 86400 && empty($_GET['refaire']);
    $grave = function (array $c): void {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['apFournMap', json_encode($c, JSON_UNESCAPED_UNICODE)]);
    };

    if (!$frais) {
        if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
        // 1. Les fournisseurs, puis les matières de chacun : matière → noms.
        $fours = [];
        foreach (analyseListe(PanelApi::get('/material-suppliers') ?? []) as $f) {
            $id = (int) ($f['id'] ?? 0); $nom = trim((string) ($f['name'] ?? ''));
            if ($id > 0 && $nom !== '') { $fours[$id] = $nom; }
        }
        if ($fours === []) { http_response_code(502); return ['error' => 'liste des fournisseurs indisponible', 'detail' => PanelApi::$lastError]; }
        $req = [];
        foreach ($fours as $id => $nom) { $req[$id] = '/material-suppliers/' . $id . '/materials'; }
        $matieres = [];
        foreach (PanelApi::getParallele($req) as $id => $r) {
            foreach (analyseListe($r ?? []) as $m) {
                $mid = (int) ($m['id'] ?? 0);
                if ($mid <= 0) { continue; }
                $matieres[$mid][] = $fours[$id];
            }
        }
        // 2. Les références et leur recette : le catalogue local d'abord
        //    (product.id_recipe), le panel sinon.
        $recettes = [];
        try {
            foreach (Db::rows('SELECT id, id_recipe FROM product WHERE is_active = 1') as $p) {
                $recettes[(int) $p['id']] = $p['id_recipe'] !== null ? (int) $p['id_recipe'] : 0;
            }
        } catch (PDOException $e) { /* catalogue local absent */ }
        if ($recettes === []) {
            foreach (analyseListe(PanelApi::get('/products') ?? []) as $p) {
                if ((int) ($p['is_active'] ?? 1) !== 1) { continue; }
                $recettes[(int) ($p['id'] ?? 0)] = (int) ($p['id_recipe'] ?? 0);
            }
            unset($recettes[0]);
        }
        $produits = [];
        $restants = [];
        foreach ($recettes as $pid => $rid) {
            if ($rid > 0) { $restants[] = $pid; } else { $produits[$pid] = []; }
        }
        $c = ['quand' => time(), 'fournisseurs' => count($fours), 'matieres' => $matieres,
            'recettes' => $recettes, 'produits' => $produits, 'restants' => $restants, 'total' => count($recettes)];
        $grave($c);
    }

    // 3. Les recettes restantes, par paquets, tant que le budget le permet.
    $matieres = $c['matieres'];
    while ($c['restants'] !== [] && microtime(true) - $debut < $budget) {
        $lot = array_splice($c['restants'], 0, 24);
        $req = [];
        foreach ($lot as $pid) { $req[$pid] = '/recipes/' . (int) $c['recettes'][$pid]; }
        foreach (PanelApi::getParallele($req) as $pid => $r) {
            if (!is_array($r)) {
                // Réponse manquante : la référence repasse en fin de file, une
                // seule fois — au second échec elle est notée sans fournisseur.
                if (empty($c['echecs'][$pid])) { $c['echecs'][$pid] = 1; $c['restants'][] = $pid; }
                else { $c['produits'][$pid] = []; }
                continue;
            }
            $noms = [];
            foreach ((array) ($r['materials'] ?? []) as $m) {
                foreach ($matieres[(int) ($m['id'] ?? 0)] ?? [] as $n) {
                    if (!in_array($n, $noms, true)) { $noms[] = $n; }
                }
            }
            $c['produits'][$pid] = $noms;
        }
        $grave($c);
    }

    $parProduit = [];
    foreach ($c['produits'] as $pid => $l) { $parProduit[(string) $pid] = array_values((array) $l); }
    return ['pret' => $c['restants'] === [], 'restant' => count($c['restants']), 'total' => (int) ($c['total'] ?? 0),
        'fournisseurs' => (int) ($c['fournisseurs'] ?? 0), 'quand' => date('Y-m-d H:i', (int) $c['quand']),
        'parProduit' => $parProduit];
}

/**
 * GET /products/couverture — jusqu'où va la caisse locale. Le scoring se
 * calcule sur le dernier mois qu'elle porte : il faut pouvoir le lire.
 */
function ep_products_couverture(): array
{
    try {
        $d = Db::row('SELECT /*+ MAX_EXECUTION_TIME(4000) */ MAX(insert_timestamp) AS dernier, MIN(insert_timestamp) AS premier FROM transaction');
        $mois = Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */ DATE_FORMAT(insert_timestamp, '%Y-%m') m, COUNT(*) n, COUNT(DISTINCT DATE(insert_timestamp)) jours
                            FROM transaction WHERE insert_timestamp >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                        GROUP BY DATE_FORMAT(insert_timestamp, '%Y-%m') ORDER BY m");
    } catch (PDOException $e) { return ['error' => 'caisse locale indisponible']; }
    $ref = setting('periodeProduits');
    $fen = setting('periodeProduitsFenetre');
    return ['dernierTicket' => $d['dernier'] ?? null, 'premierTicket' => $d['premier'] ?? null,
        'periodeServie' => is_string($ref) ? $ref : null,
        'fenetre' => is_array($fen) ? $fen : null,
        'mois' => array_map(fn ($r) => ['mois' => $r['m'], 'tickets' => (int) $r['n'], 'jours' => (int) $r['jours']], $mois)];
}

/**
 * POST /products/categorie.pdf — UNE catégorie, complète, en paysage :
 * chaque référence avec tout ce que l'écran sait d'elle (fournisseur,
 * volume et sa ventilation par magasin, prix, achat, marge, taux, perte,
 * positions, score, revue, nécessaire, décision). L'écran envoie ses lignes,
 * le serveur met en page : le papier ne peut pas dire autre chose que lui.
 */
function wr_prod_categorie_pdf(): array
{
    $b = body();
    $cat = mb_substr(trim((string) ($b['categorie'] ?? '')), 0, 80);
    if ($cat === '') { http_response_code(422); return ['error' => 'catégorie manquante']; }
    $lignes = is_array($b['lignes'] ?? null) ? array_values(array_filter($b['lignes'], 'is_array')) : [];
    $mags = array_values(array_map(fn ($m) => mb_substr(trim((string) $m), 0, 40), (array) ($b['magasins'] ?? [])));
    $lib = mb_substr(trim((string) ($b['periode'] ?? '')), 0, 80);
    $pond = mb_substr(trim((string) ($b['ponderation'] ?? '')), 0, 160);
    $garder = (float) ($b['seuils']['garder'] ?? 70); $modifier = (float) ($b['seuils']['modifier'] ?? 50);
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $num = fn ($v, int $d = 0) => $v === null || $v === '' ? '' : number_format((float) $v, $d, ',', ' ');
    $eur = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 2, ',', ' ') . ' €';
    $pct = fn ($v, int $d = 0) => $v === null || $v === '' ? '' : number_format((float) $v * 100, $d, ',', ' ') . ' %';
    usort($lignes, fn ($u, $v) => (float) ($v['score'] ?? 0) <=> (float) ($u['score'] ?? 0));

    // Les totaux de la catégorie, et la ventilation par magasin.
    $volTot = 0.0; $caTot = 0.0; $mgTot = 0.0; $mgOk = false; $volMag = array_fill(0, count($mags), 0.0);
    $dec = ['Garder' => 0, 'Modifier' => 0, 'Effacer' => 0]; $nSans = 0; $nNec = 0;
    foreach ($lignes as $l) {
        $volTot += (float) ($l['vol'] ?? 0); $caTot += (float) ($l['ca'] ?? 0);
        if (isset($l['mg']) && $l['mg'] !== null && $l['mg'] !== '') { $mgTot += (float) $l['mg']; $mgOk = true; }
        foreach ((array) ($l['parMagasin'] ?? []) as $i => $v) { if (isset($volMag[$i])) { $volMag[$i] += (float) $v; } }
        $d = (string) ($l['decision'] ?? ''); if (isset($dec[$d])) { $dec[$d]++; }
        if (!empty($l['sansVente'])) { $nSans++; }
        if (!empty($l['necessaire'])) { $nNec++; }
    }
    $coul = ['Garder' => '#2d7a3e', 'Modifier' => '#b8671a', 'Effacer' => '#C0182B'];
    $fond = ['Garder' => '#e6f2e8', 'Modifier' => '#fdf2e5', 'Effacer' => '#fbebed'];
    $etoiles = function ($n) {
        $n = (int) $n;
        if ($n <= 0) { return '<span style="color:#c9c3b8">&#9734;&#9734;&#9734;&#9734;&#9734;</span>'; }
        return '<span style="color:#C9A227">' . str_repeat('&#9733;', min(5, $n)) . '</span><span style="color:#d9d2c6">' . str_repeat('&#9734;', max(0, 5 - $n)) . '</span>';
    };
    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9.5pt}
      table.t{border-collapse:collapse;width:100%;font-size:7.6pt}
      table.t th{font-size:6.3pt;text-transform:uppercase;letter-spacing:0.04em;color:#8b8177;text-align:right;padding:1.1mm 1.2mm;border-bottom:0.5pt solid #E5E0D8;vertical-align:bottom}
      table.t td{padding:1.1mm 1.2mm;border-bottom:0.4pt solid #F0EDE7;text-align:right;vertical-align:middle}
      table.t th.l,table.t td.l{text-align:left}
      table.t th.c,table.t td.c{text-align:center}
      table.t th.mag{color:#8D1D2C;background:#FBF6F1}
      table.t td.mag{background:#FBF6F1}
      table.t tr{page-break-inside:avoid}
      table.t tfoot td{font-weight:bold;border-top:1pt solid #221E1A;background:#F7F4EF}
      .badge{display:inline-block;border-radius:2mm;padding:0.3mm 1.6mm;font-size:6.6pt;font-weight:bold}
      .etq{font-size:6pt;font-weight:normal;color:#8b8177;border:0.5pt solid #d9d2c6;border-radius:2mm;padding:0 1.2mm;vertical-align:0.3mm}
    </style>';
    $logo = rapLogoDataUri();
    $h = $css . '<div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2.5px solid #8D1D2C;padding-bottom:2mm;margin-bottom:3mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:30px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:8pt;color:#7a736a;line-height:1.5"><b style="color:#221E1A;font-size:10pt">Fiche de catégorie : réseau</b><br>' . $e($lib) . ' · scoring produits</td></tr></table>'
        . '<div style="font-family:Georgia,serif;font-size:18pt;margin:0 0 0.5mm">' . $e($cat) . '</div>'
        . '<div style="font-size:8pt;color:#5d564e;margin-bottom:2.5mm">' . count($lignes) . ' référence(s), toutes les informations de l’écran Scoring.'
        . ($pond !== '' ? ' Score : ' . $e($pond) . '.' : '')
        . ' Décision : garder au-dessus de ' . $num($garder) . ', modifier à partir de ' . $num($modifier) . ', effacer en dessous ; « nécessaire » prime.'
        . ($nSans > 0 ? ' <b>Sans vente</b> (' . $nSans . ') : au catalogue, rien de vendu sur la période.' : '') . '</div>';
    // Les tuiles : ce que pèse la catégorie.
    $tuile = fn (string $cap, string $val, string $sous) => '<td style="border:1.2px solid #E8C9A0;background:#FFF9EC;border-radius:3mm;padding:2mm 2mm;text-align:center">'
        . '<div style="font-size:6.8pt;font-weight:bold;letter-spacing:0.09em;color:#8b8177">' . $cap . '</div>'
        . '<div style="font-family:Georgia,serif;font-size:13pt;color:#221E1A">' . $val . '</div>'
        . '<div style="font-size:6.5pt;color:#5d564e">' . $e($sous) . '</div></td>';
    $h .= '<table width="100%" cellpadding="0" cellspacing="3"><tr>'
        . $tuile('RÉFÉRENCES', (string) count($lignes), ($nNec ? $nNec . ' nécessaire(s) · ' : '') . $nSans . ' sans vente')
        . $tuile('VOLUME', $num($volTot), 'pièces vendues sur la période')
        . $tuile('CHIFFRE D’AFFAIRES', $eur($caTot), $volTot > 0 ? 'prix moyen ' . $eur($caTot / $volTot) : '')
        . $tuile('MARGE BRUTE', $mgOk ? $eur($mgTot) : 'n.d.', $mgOk && $caTot > 0 ? $pct($mgTot / $caTot, 1) . ' du CA' : 'coût matière absent')
        . $tuile('DÉCISIONS', '<span style="color:#2d7a3e">' . $dec['Garder'] . '</span> · <span style="color:#b8671a">' . $dec['Modifier'] . '</span> · <span style="color:#C0182B">' . $dec['Effacer'] . '</span>', 'garder · modifier · effacer')
        . '</tr></table>';
    // La ventilation de la catégorie par magasin.
    if ($mags !== [] && $volTot > 0) {
        $h .= '<table width="100%" cellpadding="0" cellspacing="3" style="margin-top:1mm"><tr>';
        foreach ($mags as $i => $m) {
            $h .= '<td style="border:0.6pt solid #E5E0D8;border-radius:2mm;padding:1.4mm 2mm"><div style="font-size:6.5pt;color:#8b8177;text-transform:uppercase;letter-spacing:0.05em">' . $e($m) . '</div>'
                . '<div style="font-size:10pt"><b>' . $num($volMag[$i]) . '</b> <span style="font-size:7pt;color:#5d564e">pièces · ' . $pct($volMag[$i] / $volTot) . '</span></div></td>';
        }
        $h .= '</tr></table>';
    }
    // Le tableau complet.
    $h .= '<table class="t" style="margin-top:3mm"><thead><tr><th class="l">Référence</th><th class="l">Fournisseur</th><th>Volume</th>';
    foreach ($mags as $m) { $h .= '<th class="mag">' . $e(preg_replace('/^Atelier by\s*-?\s*/u', '', $m)) . '</th>'; }
    $h .= '<th>CA</th><th>PV</th><th>Achat</th><th>Marge</th><th>Taux</th><th>Perte</th><th>Pos. gén.</th><th>Pos. cat.</th><th>Score</th><th class="c">Revue</th><th class="c">Décision</th></tr></thead><tbody>';
    foreach ($lignes as $l) {
        $d = (string) ($l['decision'] ?? '');
        $h .= '<tr><td class="l" style="font-weight:bold">' . $e($l['nom'] ?? '')
            . (!empty($l['necessaire']) ? ' <span class="etq" style="color:#2d7a3e;border-color:#bfdcc5">nécessaire</span>' : '')
            . (!empty($l['sansVente']) ? ' <span class="etq">sans vente</span>' : '')
            . '<div style="font-size:6pt;color:#8b8177;font-weight:normal">' . $e($l['id'] ?? '') . ((string) ($l['motifPerte'] ?? '') !== '' ? ' · rebut : ' . $e($l['motifPerte']) : '') . '</div></td>'
            . '<td class="l" style="color:#8b8177">' . $e($l['fourn'] ?? '') . '</td>'
            . '<td style="font-weight:bold">' . $num($l['vol'] ?? 0) . '</td>';
        foreach ($mags as $i => $m) { $v = (array) ($l['parMagasin'] ?? []); $h .= '<td class="mag">' . (isset($v[$i]) ? $num($v[$i]) : '') . '</td>'; }
        $h .= '<td>' . $eur($l['ca'] ?? null) . '</td><td>' . $eur($l['pv'] ?? null) . '</td><td>' . $eur($l['achat'] ?? null) . '</td><td>' . $eur($l['marge'] ?? null) . '</td>'
            . '<td>' . $pct($l['taux'] ?? null) . '</td><td>' . $pct($l['perte'] ?? null, 1) . ((isset($l['jete']) && $l['jete'] !== null && $l['jete'] !== '') ? ' <span style="color:#8b8177">(' . $num($l['jete']) . ')</span>' : '') . '</td>'
            . '<td style="color:#8b8177">' . $e($l['posG'] ?? '') . '</td><td style="color:#8b8177">' . $e($l['posC'] ?? '') . '</td>'
            . '<td style="font-weight:bold;color:' . ($coul[$d] ?? '#221E1A') . '">' . (int) round((float) ($l['score'] ?? 0)) . '</td>'
            . '<td class="c" style="font-size:8pt">' . $etoiles($l['revue'] ?? 0) . '</td>'
            . '<td class="c"><span class="badge" style="color:' . ($coul[$d] ?? '#221E1A') . ';background:' . ($fond[$d] ?? '#eee') . '">' . $e($d) . (!empty($l['necessaire']) ? ' ✓' : '') . '</span></td></tr>';
    }
    $h .= '</tbody><tfoot><tr><td class="l">Total catégorie</td><td></td><td>' . $num($volTot) . '</td>';
    foreach ($mags as $i => $m) { $h .= '<td class="mag">' . $num($volMag[$i]) . '</td>'; }
    $h .= '<td>' . $eur($caTot) . '</td><td></td><td></td><td>' . ($mgOk ? $eur($mgTot) : '') . '</td><td>' . ($mgOk && $caTot > 0 ? $pct($mgTot / $caTot) : '') . '</td><td></td><td></td><td></td><td></td><td></td><td></td></tr></tfoot></table>'
        . '<div style="font-size:6.8pt;color:#8b8177;margin-top:2mm">Volume, CA et ventilation par magasin : ventes du panel sur la période. Achat = coût matière (recettes du réseau, panel, ou saisie du cockpit). Perte = jeté / (vendu + jeté), le nombre jeté entre parenthèses. Positions par CA, sur la gamme entière et dans la catégorie.</div>';
    $h .= apCategorieDouzeMois($cat, $lignes, $e, $num, $eur);
    $h .= '</div>';
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . $e($cat) . '</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => 'Réseau', 'rapport' => 'Catégorie ' . $cat . ' · ' . $lib,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '', 'paysage' => true]);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="categorie-' . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($cat)) . '-' . date('Y-m-d') . '.pdf"');
    echo $pdf;
    exit;
}

/**
 * La deuxième page de la fiche de catégorie : les douze derniers mois clos,
 * une colonne par mois. D'abord le graphique (barres empilées par magasin,
 * total au-dessus), puis le tableau : chaque référence, mois par mois, le
 * total de la catégorie, son CA, et la ventilation par magasin. Source :
 * les mêmes tranches mensuelles du panel que le scoring, gravées.
 */
function apCategorieDouzeMois(string $cat, array $lignes, callable $e, callable $num, callable $eur): string
{
    if (!PanelApi::configured()) { return ''; }
    try {
        $shops = [];
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $sh) { $shops[(int) $sh['id']] = (string) $sh['name']; }
    } catch (PDOException $eS) { return ''; }
    if ($shops === []) { return ''; }
    $finTs = strtotime(date('Y-m-01') . ' -1 day');
    $mois = [];
    for ($i = 11; $i >= 0; $i--) { $mois[] = strtotime(date('Y-m-01', $finTs) . " -$i month"); }
    $couples = [];
    foreach ($mois as $t) { foreach (array_keys($shops) as $sid) { $couples[] = [$sid, date('Y-m-01', $t), date('Y-m-t', $t)]; } }
    $lu = apTranches2($couples);
    $ids = [];
    foreach ($lignes as $l) { $pid = (int) ($l['id'] ?? 0); if ($pid > 0) { $ids[$pid] = (string) ($l['nom'] ?? $pid); } }
    if ($ids === []) { return ''; }
    // volRef[pid][m], volMois[m], caMois[m], volMag[sid][m]
    $volRef = []; $volMois = array_fill(0, 12, 0.0); $caMois = array_fill(0, 12, 0.0); $volMag = [];
    foreach ($shops as $sid => $nom) { $volMag[$sid] = array_fill(0, 12, 0.0); }
    $servis = 0;
    foreach ($mois as $m => $t) {
        foreach (array_keys($shops) as $sid) {
            $pr = $lu[$sid . ':' . date('Y-m-01', $t)] ?? null;
            if (!is_array($pr)) { continue; }
            $servis++;
            foreach ($ids as $pid => $nomP) {
                $q = (float) ($pr[$pid][2] ?? 0); $c = (float) ($pr[$pid][3] ?? 0);
                if ($q == 0.0 && $c == 0.0) { continue; }
                $volRef[$pid][$m] = ($volRef[$pid][$m] ?? 0.0) + $q;
                $volMois[$m] += $q; $caMois[$m] += $c; $volMag[$sid][$m] += $q;
            }
        }
    }
    if ($servis === 0) { return ''; }
    $MOIS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
    $libM = fn ($t) => $MOIS[(int) date('n', $t) - 1];
    $court = fn (string $n) => trim(preg_replace('/^Atelier by\s*-?\s*/u', '', $n));

    // --- les graphiques : une page entière, quatre vues -------------------
    $coulMag = ['#8D1D2C', '#C17A2A', '#2d7a3e', '#6b7fa8', '#C9A227', '#5d564e'];
    $sids = array_keys($shops);
    $colDe = fn (int $sid) => $coulMag[array_search($sid, $sids, true) % count($coulMag)];
    $legende = '';
    foreach ($shops as $sid => $nom) {
        $legende .= '<span style="display:inline-block;width:2.4mm;height:2.4mm;background:' . $colDe($sid) . ';border-radius:0.5mm;vertical-align:-0.3mm;margin:0 1mm 0 3mm"></span>' . $e($court($nom));
    }
    // Un graphique en barres (empilées si plusieurs séries), axe et grille.
    $barres = function (array $series, array $coul, string $fmt, bool $etiquette = true) use ($mois, $libM, $num, $e): string {
        $W = 520; $H = 260; $mL = 44; $mB = 24; $mT = 18; $gW = $W - $mL - 6; $gH = $H - $mT - $mB;
        $tot = array_fill(0, 12, 0.0);
        foreach ($series as $sv) { foreach ($sv as $m => $v) { $tot[$m] += (float) $v; } }
        $max = max(1.0, max($tot));
        $pas = $gW / 12; $bw = $pas * 0.64;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '" style="font-family:Helvetica,Arial,sans-serif">';
        for ($g = 0; $g <= 4; $g++) {
            $y = $mT + $gH - $gH * $g / 4;
            $svg .= '<line x1="' . $mL . '" y1="' . round($y, 1) . '" x2="' . ($mL + $gW) . '" y2="' . round($y, 1) . '" stroke="#E5E0D8" stroke-width="0.6"/>'
                . '<text x="' . ($mL - 3) . '" y="' . round($y + 2.5, 1) . '" font-size="7.5" fill="#8b8177" text-anchor="end">' . ($fmt === 'eur' ? $num($max * $g / 4 / 1000, 1) . ' k€' : $num($max * $g / 4)) . '</text>';
        }
        foreach ($mois as $m => $t) {
            $x = $mL + $pas * $m + ($pas - $bw) / 2; $yCur = $mT + $gH;
            foreach ($series as $i => $sv) {
                $hh = $gH * (float) ($sv[$m] ?? 0) / $max;
                if ($hh > 0) { $yCur -= $hh; $svg .= '<rect x="' . round($x, 1) . '" y="' . round($yCur, 1) . '" width="' . round($bw, 1) . '" height="' . round($hh, 1) . '" fill="' . $coul[$i] . '"/>'; }
            }
            if ($etiquette && $tot[$m] > 0) {
                $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($yCur - 2.5, 1) . '" font-size="7.5" font-weight="bold" fill="#221E1A" text-anchor="middle">' . ($fmt === 'eur' ? $num($tot[$m]) : $num($tot[$m])) . '</text>';
            }
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 7) . '" font-size="7.5" fill="#5d564e" text-anchor="middle">' . $e($libM($t)) . ' ' . date('y', $t) . '</text>';
        }
        return $svg . '</svg>';
    };
    // Un graphique en courbes : une ligne par magasin, points marqués.
    $courbes = function (array $series, array $coul) use ($mois, $libM, $num, $e): string {
        $W = 520; $H = 260; $mL = 44; $mB = 24; $mT = 16; $gW = $W - $mL - 8; $gH = $H - $mT - $mB;
        $max = 1.0;
        foreach ($series as $sv) { $max = max($max, max($sv)); }
        $pas = $gW / 12;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '" style="font-family:Helvetica,Arial,sans-serif">';
        for ($g = 0; $g <= 4; $g++) {
            $y = $mT + $gH - $gH * $g / 4;
            $svg .= '<line x1="' . $mL . '" y1="' . round($y, 1) . '" x2="' . ($mL + $gW) . '" y2="' . round($y, 1) . '" stroke="#E5E0D8" stroke-width="0.6"/>'
                . '<text x="' . ($mL - 3) . '" y="' . round($y + 2.5, 1) . '" font-size="7.5" fill="#8b8177" text-anchor="end">' . $num($max * $g / 4) . '</text>';
        }
        foreach ($mois as $m => $t) {
            $svg .= '<text x="' . round($mL + $pas * $m + $pas / 2, 1) . '" y="' . ($H - 7) . '" font-size="7.5" fill="#5d564e" text-anchor="middle">' . $e($libM($t)) . ' ' . date('y', $t) . '</text>';
        }
        foreach ($series as $i => $sv) {
            $pts = [];
            foreach ($mois as $m => $t) {
                $pts[] = round($mL + $pas * $m + $pas / 2, 1) . ',' . round($mT + $gH - $gH * (float) ($sv[$m] ?? 0) / $max, 1);
            }
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $coul[$i] . '" stroke-width="2.2" stroke-linejoin="round"/>';
            foreach ($pts as $m => $pt) {
                [$px, $py] = explode(',', $pt);
                if ((float) ($sv[$m] ?? 0) > 0) { $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="2.8" fill="' . $coul[$i] . '"/>'; }
            }
        }
        return $svg . '</svg>';
    };
    // Les parts : barres horizontales par magasin sur les douze mois.
    $parts = function () use ($shops, $volMag, $colDe, $court, $num, $e): string {
        $tot = 0.0; $parMag = [];
        foreach ($shops as $sid => $nom) { $parMag[$sid] = array_sum($volMag[$sid]); $tot += $parMag[$sid]; }
        arsort($parMag);
        $W = 520; $rowH = 40; $H = 20 + $rowH * count($parMag); $mL = 150; $gW = $W - $mL - 90;
        $max = max(1.0, max($parMag));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '" style="font-family:Helvetica,Arial,sans-serif">';
        $i = 0;
        foreach ($parMag as $sid => $v) {
            $y = 10 + $rowH * $i; $w = $gW * $v / $max;
            $svg .= '<text x="' . ($mL - 6) . '" y="' . ($y + 17) . '" font-size="9" fill="#221E1A" text-anchor="end">' . $e($court($shops[$sid])) . '</text>'
                . '<rect x="' . $mL . '" y="' . $y . '" width="' . $gW . '" height="26" fill="#F0EDE7" rx="3"/>'
                . '<rect x="' . $mL . '" y="' . $y . '" width="' . round($w, 1) . '" height="18" fill="' . $colDe($sid) . '" rx="2"/>'
                . '<text x="' . round($mL + $gW + 6, 1) . '" y="' . ($y + 17) . '" font-size="9" font-weight="bold" fill="#221E1A">' . $num($v) . ' <tspan font-weight="normal" fill="#5d564e">· ' . ($tot > 0 ? $num($v / $tot * 100) : '0') . ' %</tspan></text>';
            $i++;
        }
        return $svg . '</svg>';
    };
    $cadre = fn (string $titre, string $sous, string $svg) => '<td width="50%" style="vertical-align:top;padding:1mm"><div style="border:0.6pt solid #E5E0D8;border-radius:2mm;padding:2mm 2mm 1mm">'
        . '<div style="font-size:9pt;font-weight:bold">' . $titre . '</div><div style="font-size:6.8pt;color:#8b8177;margin-bottom:1mm">' . $sous . '</div>' . $svg . '</div></td>';
    $serieMag = []; $coulSer = [];
    foreach ($shops as $sid => $nom) { $serieMag[] = $volMag[$sid]; $coulSer[] = $colDe($sid); }
    $pageGraph = '<div style="page-break-before:always"></div>'
        . '<div style="font-family:Georgia,serif;font-size:15pt;margin:0 0 0.5mm">' . $e($cat) . ' : les graphiques</div>'
        . '<div style="font-size:8pt;color:#5d564e;margin-bottom:1.5mm">Douze derniers mois, ' . $e($libM($mois[0])) . ' ' . date('Y', $mois[0]) . ' à ' . $e($libM($mois[11])) . ' ' . date('Y', $mois[11]) . '. ' . $legende . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . $cadre('Volume réseau par mois', 'pièces vendues, empilées par magasin, total au-dessus', $barres($serieMag, $coulSer, 'n'))
        . $cadre('Chiffre d’affaires réseau par mois', 'ventes du panel, en euros', $barres([$caMois], ['#221E1A'], 'eur'))
        . '</tr><tr>'
        . $cadre('Volume par magasin, mois par mois', 'une courbe par magasin', $courbes($serieMag, $coulSer))
        . $cadre('Part de chaque magasin sur douze mois', 'pièces vendues et part du réseau', $parts())
        . '</tr></table>';

    // --- le tableau : une colonne par mois --------------------------------
    $th = '<th class="l">Référence</th>';
    foreach ($mois as $t) { $th .= '<th>' . $e($libM($t)) . '<br><span style="font-weight:normal">' . date('Y', $t) . '</span></th>'; }
    $th .= '<th style="color:#221E1A">Total</th>';
    $tb = '';
    $ordre = array_keys($ids);
    usort($ordre, fn ($a, $b2) => array_sum($volRef[$b2] ?? []) <=> array_sum($volRef[$a] ?? []));
    foreach ($ordre as $pid) {
        $tot = array_sum($volRef[$pid] ?? []);
        $tb .= '<tr><td class="l" style="font-weight:bold">' . $e($ids[$pid]) . '</td>';
        foreach ($mois as $m => $t) {
            $v = $volRef[$pid][$m] ?? 0.0;
            $tb .= '<td' . ($v == 0.0 ? ' style="color:#c9c3b8"' : '') . '>' . ($v == 0.0 ? '·' : $num($v)) . '</td>';
        }
        $tb .= '<td style="font-weight:bold">' . $num($tot) . '</td></tr>';
    }
    $tf = '<tr><td class="l">Total catégorie</td>';
    foreach ($volMois as $v) { $tf .= '<td>' . $num($v) . '</td>'; }
    $tf .= '<td>' . $num(array_sum($volMois)) . '</td></tr>';
    $tf .= '<tr><td class="l" style="font-weight:normal;color:#5d564e">Chiffre d’affaires</td>';
    foreach ($caMois as $v) { $tf .= '<td style="font-weight:normal;color:#5d564e">' . ($v == 0.0 ? '' : $eur($v)) . '</td>'; }
    $tf .= '<td style="font-weight:normal;color:#5d564e">' . $eur(array_sum($caMois)) . '</td></tr>';
    foreach ($shops as $sid => $nom) {
        $col = $colDe($sid);
        $tf .= '<tr><td class="l" style="font-weight:normal"><span style="display:inline-block;width:2mm;height:2mm;background:' . $col . ';border-radius:0.4mm;margin-right:1.2mm"></span>' . $e($court($nom)) . '</td>';
        foreach ($volMag[$sid] as $v) { $tf .= '<td style="font-weight:normal">' . ($v == 0.0 ? '<span style="color:#c9c3b8">·</span>' : $num($v)) . '</td>'; }
        $tf .= '<td style="font-weight:normal">' . $num(array_sum($volMag[$sid])) . '</td></tr>';
    }

    return '<div style="page-break-before:always"></div>'
        . '<div style="font-family:Georgia,serif;font-size:15pt;margin:0 0 0.5mm">' . $e($cat) . ' : les douze derniers mois</div>'
        . '<div style="font-size:8pt;color:#5d564e;margin-bottom:2mm">Pièces vendues par mois, ' . $e($libM($mois[0])) . ' ' . date('Y', $mois[0]) . ' à ' . $e($libM($mois[11])) . ' ' . date('Y', $mois[11]) . ', tous magasins, puis le total de la catégorie, son chiffre d’affaires et la ventilation par magasin.</div>'
        . '<table class="t"><thead><tr>' . $th . '</tr></thead><tbody>' . $tb . '</tbody><tfoot>' . $tf . '</tfoot></table>'
        . '<div style="font-size:6.8pt;color:#8b8177;margin-top:2mm">Un point marque un mois sans vente. Le CA est celui du panel, TVA comprise selon sa règle. Les graphiques sont en page suivante.</div>'
        . $pageGraph;
}
