<?php
declare(strict_types=1);

/**
 * Cockpit CEO — l'analyse d'UN magasin, en quatre étapes.
 *
 * Les écrans réseau (usage, manque à gagner) répondent à « où est le
 * problème ? ». Celui-ci répond à la question du franchisé : « qu'est-ce que
 * JE fais pour développer mon chiffre ? ». Trois leviers, chacun chiffré par
 * mois et par an, puis un plan qui les fusionne :
 *
 *  1. L'ASSORTIMENT — repris du calcul « Manque à gagner » TEL QUEL : même
 *     fonction, mêmes chiffres. Deux écrans qui annoncent deux potentiels
 *     différents ruinent la confiance dans les deux.
 *  2. Les CATÉGORIES — la part de CA du magasin par groupe, face à la part
 *     médiane des autres. Une catégorie en retrait est un rayon qui existe
 *     déjà : la développer ne demande ni référence ni prix nouveaux. Une
 *     catégorie AU-DESSUS du réseau n'est pas une anomalie : c'est l'identité
 *     du magasin, elle est marquée comme force et jamais comptée en négatif.
 *  3. Les PRIX — les références vendues sous le prix encaissé chez les
 *     autres, SANS volume supérieur pour le justifier : un prix bas qui fait
 *     vendre plus n'est pas une erreur, c'est une stratégie, et l'écran ne
 *     pousse pas à la casser. Gain à volume constant.
 *
 * Tout est une estimation à comportement constant. La page le dit.
 */

/** Médiane d'une liste, 0 si vide. */
function anmMediane(array $v): float
{
    return $v === [] ? 0.0 : mqMediane($v);
}

/** GET /magasin/analyse?shop=4[&mois=6] */
function ep_mag_analyse(): array
{
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 1 || $n > 12) { $n = 6; }
    $shop = trim((string) ($_GET['shop'] ?? ''));

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    if ($nomDe === []) { return ['motif' => 'aucun magasin actif', 'shops' => []]; }
    if (!isset($nomDe[$shop])) { $shop = (string) array_key_first($nomDe); }

    $mois = utilMois($n);
    $du = $mois[0]['du'];
    $au = $mois[count($mois) - 1]['au'];
    $borneA = $du . ' 00:00:00';
    $borneB = date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00';

    $out = ['shops' => array_map(static fn ($id, $nom) => ['id' => $id, 'nom' => $nom],
                array_keys($nomDe), $nomDe),
        'shop' => $shop, 'nom' => $nomDe[$shop], 'n' => $n,
        'du' => $du, 'au' => $au, 'motif' => null];

    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
    if ($ct === null || $cl === null) {
        $out['motif'] = 'la caisse n’expose pas ses lignes de ticket sur cette base';
        return $out;
    }

    // --- Le catalogue : pid → groupe, nom. Les gammes ne s'écartent pas ici :
    // le mix et les prix se lisent sur ce qui s'est VENDU.
    $refs = [];
    foreach (ep_prod_catalogue() as $p) {
        $pid = $p['pwaId'] ?? null;
        if ($pid === null) { continue; }
        $g = (string) ($p['groupe'] ?? '');
        $refs[(int) $pid] = ['nom' => (string) $p['nom'],
            'groupe' => $g !== '' ? $g : '— hors groupe'];
    }

    // --- L'activité : jours, tickets, CA par magasin (période entière).
    $act = [];
    try {
        $sql = sprintf(
            'SELECT `%s` AS shop, COUNT(DISTINCT DATE(`%s`)) jours, COUNT(*) tickets,
                    SUM(total_gross_amount_after_discount) ca
               FROM `transaction` WHERE `%s` >= ? AND `%s` < ? GROUP BY shop',
            $ct['shop'], $ct['date'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$borneA, $borneB]) as $r) {
            $sid = (string) $r['shop'];
            if (!isset($nomDe[$sid])) { continue; }
            $act[$sid] = ['jours' => max(1, (int) $r['jours']),
                'tickets' => (int) $r['tickets'], 'ca' => (float) $r['ca']];
        }
    } catch (PDOException $e) { $out['motif'] = 'lecture de l’activité impossible'; return $out; }
    if (!isset($act[$shop])) { $out['motif'] = 'aucune vente pour ce magasin sur la période'; return $out; }

    // --- Les ventes : magasin × référence (période entière).
    $vente = [];   // sid => pid => ['q','ca']
    try {
        $sql = sprintf(
            'SELECT t.`%s` AS shop, l.`%s` AS produit, SUM(l.`%s`) q,
                    SUM(l.total_gross_value_after_discount) ca
               FROM `transaction_product` l JOIN `transaction` t ON t.`%s` = l.`%s`
              WHERE t.`%s` >= ? AND t.`%s` < ? GROUP BY shop, produit',
            $ct['shop'], $cl['produit'], $cl['quantite'],
            $ct['id'], $cl['ticket'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$borneA, $borneB]) as $r) {
            $sid = (string) $r['shop'];
            if (!isset($nomDe[$sid])) { continue; }
            $q = (float) $r['q'];
            if ($q <= 0) { continue; }
            $vente[$sid][(int) $r['produit']] = ['q' => $q, 'ca' => (float) $r['ca']];
        }
    } catch (PDOException $e) { $out['motif'] = 'lecture des lignes de ticket impossible'; return $out; }

    $autres = array_values(array_diff(array_keys($nomDe), [$shop]));

    // --- Les repères de tête.
    $caP = $act[$shop]['ca'];
    $caMois = $caP / $n;
    $panier = $act[$shop]['tickets'] > 0 ? $caP / $act[$shop]['tickets'] : null;
    $panierReseau = anmMediane(array_values(array_filter(array_map(
        static fn ($sid) => ($act[$sid]['tickets'] ?? 0) > 0 ? $act[$sid]['ca'] / $act[$sid]['tickets'] : null,
        $autres), static fn ($v) => $v !== null)));
    $refsVendues = count($vente[$shop] ?? []);
    $refsMax = 0;
    foreach ($autres as $sid) { $refsMax = max($refsMax, count($vente[$sid] ?? [])); }

    // --- Levier 1 : l'assortiment, par le calcul « Manque à gagner ».
    $memGet = $_GET;
    $_GET = ['mois' => (string) $n];
    $manque = ep_prod_manque();
    $_GET = $memGet;
    $lev1 = ['manque' => 0, 'retenuMois' => 0, 'refs' => 0, 'top' => []];
    foreach ($manque['magasins'] ?? [] as $m) {
        if ((string) $m['id'] !== $shop) { continue; }
        $lev1 = ['manque' => (int) $m['eur'],
            // La MOITIÉ du manque : un plan qui promet le maximum n'est pas
            // un plan — même règle que la ligne verte de l'écran réseau.
            'retenuMois' => (int) round($m['eur'] / 2 / $n),
            'refs' => (int) $m['refs'],
            'top' => array_slice($m['top'] ?? [], 0, 8)];
    }
    $lev1['retenuAn'] = $lev1['retenuMois'] * 12;

    // --- Levier 2 : le mix par groupe.
    $caGrp = [];   // sid => groupe => ca (sur les références du catalogue)
    $caTot = [];   // sid => ca mappé
    foreach ($vente as $sid => $prods) {
        foreach ($prods as $pid => $v) {
            $g = $refs[$pid]['groupe'] ?? null;
            if ($g === null || $g === '— hors groupe') { continue; }
            $caGrp[$sid][$g] = ($caGrp[$sid][$g] ?? 0) + $v['ca'];
            $caTot[$sid] = ($caTot[$sid] ?? 0) + $v['ca'];
        }
    }
    $groupes = [];
    $potGrpMois = 0.0;
    $tousGroupes = [];
    foreach ($caGrp as $sid => $par) { foreach ($par as $g => $ca) { $tousGroupes[$g] = true; } }
    foreach (array_keys($tousGroupes) as $g) {
        $part = ($caTot[$shop] ?? 0) > 0 ? 100 * ($caGrp[$shop][$g] ?? 0) / $caTot[$shop] : 0.0;
        $partsAutres = [];
        foreach ($autres as $sid) {
            if (($caTot[$sid] ?? 0) > 0) { $partsAutres[] = 100 * ($caGrp[$sid][$g] ?? 0) / $caTot[$sid]; }
        }
        if ($partsAutres === []) { continue; }
        $ref = anmMediane($partsAutres);
        if ($ref <= 0 && $part <= 0) { continue; }
        $delta = $part - $ref;
        // Revenir à la part réseau, le CA du mois posé comme assiette.
        $pot = $delta < 0 ? (-$delta / 100) * $caMois : 0.0;
        if ($pot > 0) { $potGrpMois += $pot; }
        $groupes[] = ['nom' => $g,
            'part' => round($part, 1), 'partReseau' => round($ref, 1),
            'delta' => round($delta, 1),
            'potMois' => (int) round($pot), 'potAn' => (int) round($pot * 12),
            'force' => $delta >= 1.5];
    }
    usort($groupes, static fn ($a, $b) => $b['potMois'] <=> $a['potMois'] ?: $b['delta'] <=> $a['delta']);
    $lev2 = ['potMois' => (int) round($potGrpMois), 'potAn' => (int) round($potGrpMois * 12),
        'groupes' => $groupes,
        'enRetrait' => count(array_filter($groupes, static fn ($g) => $g['potMois'] > 0))];

    // --- Levier 3 : les prix sous le réseau, à volume constant.
    $prix = [];
    $potPrixMois = 0.0;
    $joursShop = $act[$shop]['jours'];
    foreach ($vente[$shop] ?? [] as $pid => $v) {
        $r = $refs[$pid] ?? null;
        if ($r === null || $r['groupe'] === '— hors groupe') { continue; }
        $sien = $v['ca'] / $v['q'];
        if ($sien <= 0.2) { continue; }   // extra à 1 € symbolique, lignes de correction
        $desAutres = []; $tauxAutres = [];
        foreach ($autres as $sid) {
            $x = $vente[$sid][$pid] ?? null;
            if ($x === null || $x['q'] < 5) { continue; }   // un prix sur 2 unités n'est pas un prix
            $desAutres[] = $x['ca'] / $x['q'];
            $tauxAutres[] = $x['q'] / max(1, $act[$sid]['jours']);
        }
        if (count($desAutres) < 2) { continue; }
        $refPrix = anmMediane($desAutres);
        $ecart = $refPrix > 0 ? ($sien - $refPrix) / $refPrix : 0;
        // Sous le réseau d'au moins 2 % et 5 centimes — en deçà, c'est de
        // l'arrondi de caisse, pas une politique de prix.
        if ($ecart > -0.02 || $refPrix - $sien < 0.05) { continue; }
        // Volume supérieur aux autres : son prix bas TRAVAILLE, on ne le
        // compte pas.
        if ($v['q'] / $joursShop > 1.15 * anmMediane($tauxAutres)) { continue; }
        $gain = ($refPrix - $sien) * $v['q'];
        $potPrixMois += $gain / $n;
        $prix[] = ['nom' => $r['nom'], 'groupe' => $r['groupe'],
            'prix' => round($sien, 2), 'prixReseau' => round($refPrix, 2),
            'ecartPct' => round(100 * $ecart, 1),
            'volMois' => (int) round($v['q'] / $n),
            'gainMois' => (int) round($gain / $n), 'gainAn' => (int) round($gain / $n * 12)];
    }
    usort($prix, static fn ($a, $b) => $b['gainMois'] <=> $a['gainMois']);
    $resteP = array_slice($prix, 10);
    $lev3 = ['potMois' => (int) round($potPrixMois), 'potAn' => (int) round($potPrixMois * 12),
        'refs' => array_slice($prix, 0, 10), 'nb' => count($prix),
        'resteN' => count($resteP), 'resteMois' => (int) round(array_sum(array_column($resteP, 'gainMois')))];

    // --- Étape 4 : le plan — les trois leviers fusionnés, classés.
    $actions = [];
    foreach (array_slice($lev1['top'], 0, 6) as $t) {
        $actions[] = ['levier' => 'Assortiment',
            'action' => 'Mettre « ' . $t['nom'] . ' » en production — vendue par ' . $t['vendeurs']
                . ' magasin(s), ' . $t['unitesMois'] . ' u/mois estimées',
            // La même retenue que le levier : la moitié de l'estimation.
            'eurMois' => (int) round($t['eur'] / 2 / $n)];
    }
    foreach (array_slice(array_filter($groupes, static fn ($g) => $g['potMois'] > 0), 0, 4) as $g) {
        $actions[] = ['levier' => 'Catégorie',
            'action' => 'Remonter « ' . $g['nom'] . ' » à la part réseau — '
                . number_format($g['part'], 1, ',', ' ') . ' % contre '
                . number_format($g['partReseau'], 1, ',', ' ') . ' % ailleurs',
            'eurMois' => $g['potMois']];
    }
    foreach (array_slice($prix, 0, 4) as $p) {
        $actions[] = ['levier' => 'Prix',
            'action' => 'Aligner « ' . $p['nom'] . ' » sur le prix réseau — '
                . number_format($p['prix'], 2, ',', ' ') . ' € → '
                . number_format($p['prixReseau'], 2, ',', ' ') . ' €',
            'eurMois' => $p['gainMois']];
    }
    usort($actions, static fn ($a, $b) => $b['eurMois'] <=> $a['eurMois']);
    $actions = array_slice($actions, 0, 10);
    $cumul = 0;
    foreach ($actions as $i => $a) {
        $cumul += $a['eurMois'] * 12;
        $actions[$i]['eurAn'] = $a['eurMois'] * 12;
        $actions[$i]['cumulAn'] = $cumul;
        $actions[$i]['rang'] = $i + 1;
    }

    $totalMois = $lev1['retenuMois'] + $lev2['potMois'] + $lev3['potMois'];
    $out += [
        'kpis' => ['ca' => (int) round($caP), 'caMois' => (int) round($caMois),
            'panier' => $panier !== null ? round($panier, 2) : null,
            'panierReseau' => $panierReseau > 0 ? round($panierReseau, 2) : null,
            'refsVendues' => $refsVendues, 'refsMax' => $refsMax,
            'totalMois' => $totalMois, 'totalAn' => $totalMois * 12,
            'partCa' => $caMois > 0 ? round(100 * $totalMois / $caMois, 1) : null],
        'levier1' => $lev1, 'levier2' => $lev2, 'levier3' => $lev3,
        'plan' => $actions,
        'source' => 'lignes de ticket de la caisse, ' . mktBriefJour($du) . ' → ' . mktBriefJour($au)
            . ' — comparé aux ' . count($autres) . ' autres magasins, à fréquentation ramenée. '
            . 'Estimations à comportement constant : ni promesse, ni objectif contractuel.',
    ];
    return $out;
}
