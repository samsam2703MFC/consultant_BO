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
    $prods = []; $muets = 0;
    foreach ($shops as $sid => $nom) {
        foreach ($tranches as $iT => [$du, $au, $lib]) {
            $p = $lu[$sid . ':' . $du] ?? null;
            if ($p === null) { $muets++; continue; }
            foreach ($p as $pid => $x) {
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
        'muets' => $muets];
}
