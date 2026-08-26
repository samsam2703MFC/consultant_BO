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

/**
 * Le PDF de l'analyse — deux pages A4, celles qu'on pose sur la table.
 *
 * Le même jeu de données que le wizard, rendu par le même moteur que la note
 * de campagne : l'entretien franchisé se prépare à l'écran et se mène sur
 * papier, et les deux doivent dire exactement les mêmes chiffres.
 */
function anmPdfHtml(array $d): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $pcs = static fn ($v) => number_format((float) $v, 1, ',', ' ') . ' %';
    $F = 'font-family:Helvetica,Arial,sans-serif';
    $ACCENT = '#8D1D2C'; $MUTE = '#6b6259'; $BORD = '#E7E0D6';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);

    $k = $d['kpis']; $l1 = $d['levier1']; $l2 = $d['levier2']; $l3 = $d['levier3'];

    $tuile = static fn (string $lbl, string $val, string $sub, bool $rouge = false) =>
        '<td width="25%" style="padding:3mm 4mm;border:0.5pt solid ' . $BORD . ';border-radius:2mm">'
        . '<div style="font-size:7pt;letter-spacing:.07em;text-transform:uppercase;color:' . $MUTE . '">' . $lbl . '</div>'
        . '<div style="font-size:14pt;font-weight:700;margin-top:1mm' . ($rouge ? ';color:' . $ACCENT : '') . '">' . $val . '</div>'
        . '<div style="font-size:7.5pt;color:' . $MUTE . ';margin-top:0.5mm">' . $sub . '</div></td>';

    $h = '<div style="' . $F . ';color:#221E1A">'
        . '<div style="font-size:17pt;font-weight:700">Analyse magasin — ' . $e($court($d['nom'])) . '</div>'
        . '<div style="font-size:9pt;color:' . $MUTE . ';margin:1.5mm 0 5mm">Du ' . $e(mktBriefJour($d['du']))
        . ' au ' . $e(mktBriefJour($d['au'])) . ' (' . (int) $d['n'] . ' mois) — comparé aux autres magasins du réseau, à fréquentation ramenée.</div>'

        . '<table width="100%" cellpadding="0" cellspacing="4" style="margin-bottom:5mm"><tr>'
        . $tuile('CA — ' . (int) $d['n'] . ' mois', $eur($k['ca']), $eur($k['caMois']) . ' / mois')
        . $tuile('Panier', number_format((float) $k['panier'], 2, ',', ' ') . ' €',
            'réseau : ' . number_format((float) $k['panierReseau'], 2, ',', ' ') . ' €')
        . $tuile('Assortiment vendu', (string) (int) $k['refsVendues'],
            'meilleur magasin : ' . (int) $k['refsMax'] . ' références')
        . $tuile('Potentiel identifié', '+ ' . $eur($k['totalMois']) . ' / mois',
            $eur($k['totalAn']) . ' / an — ' . $pcs($k['partCa']) . ' du CA', true)
        . '</tr></table>';

    // --- Les trois leviers.
    $carte = static fn (string $lbl, int $mois, int $an, string $regle, string $note) =>
        '<td width="33%" valign="top" style="padding:3mm 4mm;border:0.5pt solid ' . $BORD . ';border-radius:2mm">'
        . '<div style="font-size:7pt;letter-spacing:.07em;text-transform:uppercase;color:' . $MUTE . '">' . $lbl . '</div>'
        . '<div style="font-size:13pt;font-weight:700;color:' . $ACCENT . ';margin-top:1mm">+ ' . number_format($mois, 0, ',', ' ') . ' € / mois</div>'
        . '<div style="font-size:7.5pt;color:' . $MUTE . '">' . number_format($an, 0, ',', ' ') . ' € / an — ' . $regle . '</div>'
        . '<div style="font-size:8pt;margin-top:2mm;line-height:1.5">' . $note . '</div></td>';

    $h .= '<div style="font-size:10.5pt;font-weight:700;border-bottom:1pt solid #222;padding-bottom:1.5mm;margin:0 0 3mm">Les trois leviers</div>'
        . '<table width="100%" cellpadding="0" cellspacing="4" style="margin-bottom:5mm"><tr>'
        . $carte('Levier 1 — Assortiment', (int) $l1['retenuMois'], (int) $l1['retenuAn'],
            'la moitié du manque', (int) $l1['refs'] . ' références que les autres vendent et pas lui.')
        . $carte('Levier 2 — Catégories', (int) $l2['potMois'], (int) $l2['potAn'],
            'retour à la part réseau', $l2['enRetrait'] . ' catégorie(s) en retrait — hors ce que le levier 1 compte déjà.')
        . $carte('Levier 3 — Prix', (int) $l3['potMois'], (int) $l3['potAn'],
            'à volume constant', (int) $l3['nb'] . ' référence(s) sous le prix des autres, sans volume supérieur.')
        . '</tr></table>';

    // --- Les catégories.
    $th = 'font-size:7pt;letter-spacing:.06em;text-transform:uppercase;color:' . $MUTE . ';text-align:right;padding:1.5mm 2mm;border-bottom:0.5pt solid #222';
    $td = 'font-size:8.5pt;text-align:right;padding:1.6mm 2mm;border-bottom:0.5pt solid ' . $BORD;
    $h .= '<div style="page-break-inside:avoid"><div style="font-size:10.5pt;font-weight:700;border-bottom:1pt solid #222;padding-bottom:1.5mm;margin:0 0 2mm">Le mix par catégorie, contre le réseau</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="' . $th . ';text-align:left">Catégorie</td><td style="' . $th . '">Sa part</td>'
        . '<td style="' . $th . '">Part réseau</td><td style="' . $th . '">Écart</td>'
        . '<td style="' . $th . '">Potentiel / mois</td><td style="' . $th . '">Par an</td></tr>';
    foreach (array_slice($l2['groupes'], 0, 10) as $g) {
        $retrait = $g['potMois'] > 0;
        $h .= '<tr><td style="' . $td . ';text-align:left;font-weight:600">' . $e($g['nom']) . '</td>'
            . '<td style="' . $td . '">' . $pcs($g['part']) . '</td>'
            . '<td style="' . $td . ';color:' . $MUTE . '">' . $pcs($g['partReseau']) . '</td>'
            . '<td style="' . $td . ';font-weight:600;color:' . ($retrait ? $ACCENT : '#2d7a3e') . '">'
            . ($g['delta'] > 0 ? '+ ' : '− ') . number_format(abs((float) $g['delta']), 1, ',', ' ') . ' pts</td>'
            . '<td style="' . $td . ';font-weight:700' . ($retrait ? ';color:' . $ACCENT : ';color:' . $MUTE . ';font-weight:400') . '">'
            . ($retrait ? $eur($g['potMois']) : ($g['force'] ? 'sa force' : 'au niveau')) . '</td>'
            . '<td style="' . $td . ';color:' . $MUTE . '">' . ($retrait ? $eur($g['potAn']) : '—') . '</td></tr>';
    }
    $h .= '</table></div>';

    // --- Page 2 : les prix, puis le plan.
    $h .= '<div style="page-break-before:always">'
        . '<div style="font-size:10.5pt;font-weight:700;border-bottom:1pt solid #222;padding-bottom:1.5mm;margin:0 0 2mm">Les prix sous le réseau — gain à volume constant</div>';
    if ($l3['refs'] === []) {
        $h .= '<div style="font-size:9pt;color:#2d7a3e;margin-bottom:5mm">Aucun : la grille de ce magasin est au niveau du réseau.</div>';
    } else {
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:5mm"><tr>'
            . '<td style="' . $th . ';text-align:left">Référence</td><td style="' . $th . '">Son prix</td>'
            . '<td style="' . $th . '">Prix réseau</td><td style="' . $th . '">Écart</td>'
            . '<td style="' . $th . '">Volume / mois</td><td style="' . $th . '">Gain / mois</td><td style="' . $th . '">Par an</td></tr>';
        foreach ($l3['refs'] as $r) {
            $h .= '<tr><td style="' . $td . ';text-align:left;font-weight:600">' . $e($r['nom'])
                . ' <span style="color:' . $MUTE . ';font-weight:400">· ' . $e($r['groupe']) . '</span></td>'
                . '<td style="' . $td . '">' . number_format((float) $r['prix'], 2, ',', ' ') . ' €</td>'
                . '<td style="' . $td . ';color:' . $MUTE . '">' . number_format((float) $r['prixReseau'], 2, ',', ' ') . ' €</td>'
                . '<td style="' . $td . ';color:' . $ACCENT . '">− ' . number_format(abs((float) $r['ecartPct']), 1, ',', ' ') . ' %</td>'
                . '<td style="' . $td . '">' . (int) $r['volMois'] . ' u</td>'
                . '<td style="' . $td . ';font-weight:700;color:' . $ACCENT . '">' . $eur($r['gainMois']) . '</td>'
                . '<td style="' . $td . ';color:' . $MUTE . '">' . $eur($r['gainAn']) . '</td></tr>';
        }
        if (($l3['resteN'] ?? 0) > 0) {
            $h .= '<tr><td colspan="7" style="' . $td . ';text-align:left;color:' . $MUTE . '">+ '
                . (int) $l3['resteN'] . ' autres références — ' . $eur($l3['resteMois']) . ' / mois</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '<div style="font-size:10.5pt;font-weight:700;border-bottom:1pt solid #222;padding-bottom:1.5mm;margin:0 0 2mm">Le plan, classé par euros mensuels</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="' . $th . ';text-align:left">#</td><td style="' . $th . ';text-align:left">Action</td>'
        . '<td style="' . $th . ';text-align:left">Levier</td><td style="' . $th . '">/ mois</td>'
        . '<td style="' . $th . '">/ an</td><td style="' . $th . '">Cumul / an</td></tr>';
    foreach ($d['plan'] as $a) {
        $h .= '<tr><td style="' . $td . ';text-align:left;color:' . $MUTE . '">' . (int) $a['rang'] . '</td>'
            . '<td style="' . $td . ';text-align:left;line-height:1.45">' . $e($a['action']) . '</td>'
            . '<td style="' . $td . ';text-align:left;font-size:7.5pt;color:' . $MUTE . '">' . $e($a['levier']) . '</td>'
            . '<td style="' . $td . ';font-weight:700">' . $eur($a['eurMois']) . '</td>'
            . '<td style="' . $td . ';color:' . $MUTE . '">' . $eur($a['eurAn']) . '</td>'
            . '<td style="' . $td . ';font-weight:700;color:' . $ACCENT . '">' . $eur($a['cumulAn']) . '</td></tr>';
    }
    $h .= '</table>'
        . '<div style="font-size:7.5pt;color:' . $MUTE . ';margin-top:4mm;line-height:1.6">'
        . 'Comment lire ces chiffres. L’assortiment est retenu à la moitié de son estimation — un plan qui promet le maximum n’est pas un plan. '
        . 'Le levier catégories déduit ce que l’assortiment compte déjà : jamais deux fois le même euro. '
        . 'Les prix sont ceux réellement encaissés, remises comprises, et une référence dont le volume dépasse celui du réseau n’est pas comptée : son prix bas travaille. '
        . $e($d['source'] ?? '') . '</div>'
        . '</div></div>';
    return $h;
}

/** GET /magasin/analyse.pdf?shop=4[&mois=6] */
function ep_mag_analyse_pdf(): array
{
    $d = ep_mag_analyse();
    if (($d['motif'] ?? null) !== null || !isset($d['kpis'])) {
        http_response_code(422);
        return ['error' => $d['motif'] ?? 'analyse impossible'];
    }
    $pdf = rapPdfRendu(anmPdfHtml($d), [
        'magasin' => $d['nom'],
        'rapport' => 'Analyse magasin — ' . (int) $d['n'] . ' mois',
        'genere' => date('d/m/Y à H:i'),
        'envoye' => '',
    ]);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="analyse-' . mktSlug($d['nom'])
        . '-' . date('Y-m') . '.pdf"');
    echo $pdf;
    exit;
}
