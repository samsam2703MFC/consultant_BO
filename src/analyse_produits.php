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
    return ['dernierTicket' => $d['dernier'] ?? null, 'premierTicket' => $d['premier'] ?? null,
        'periodeServie' => is_string($ref) ? $ref : null,
        'mois' => array_map(fn ($r) => ['mois' => $r['m'], 'tickets' => (int) $r['n'], 'jours' => (int) $r['jours']], $mois)];
}
