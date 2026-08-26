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
    $manqueGrp = [];
    foreach ($manque['magasins'] ?? [] as $m) {
        if ((string) $m['id'] !== $shop) { continue; }
        $manqueGrp = $m['parGroupe'] ?? [];
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
        // Revenir à la part réseau, le CA du mois posé comme assiette…
        $brut = $delta < 0 ? (-$delta / 100) * $caMois : 0.0;
        // …MOINS ce que le levier 1 compte déjà sur ce groupe (à sa retenue
        // de moitié) : une catégorie en retrait l'est souvent parce que des
        // références y manquent, et additionner les deux leviers promettrait
        // deux fois le même euro.
        $deja = min($brut, ($manqueGrp[$g] ?? 0) / 2 / $n);
        $pot = max(0.0, $brut - $deja);
        if ($pot > 0) { $potGrpMois += $pot; }
        $groupes[] = ['nom' => $g,
            'part' => round($part, 1), 'partReseau' => round($ref, 1),
            'delta' => round($delta, 1),
            'potMois' => (int) round($pot), 'potAn' => (int) round($pot * 12),
            'dejaMois' => (int) round($deja),
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
 * Même identité que la note de campagne : le logo en tête, le filet bordeaux,
 * les cartes crème — et la Georgia pour les titres et les grands chiffres,
 * comme à l'écran. La feuille de style est déclarée UNE fois : un document
 * dont chaque cellule porte son style en ligne finit par en porter trois
 * différents.
 */
function anmPdfHtml(array $d): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $pcs = static fn ($v) => number_format((float) $v, 1, ',', ' ') . ' %';
    $px2 = static fn ($v) => number_format((float) $v, 2, ',', ' ') . ' €';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $logo = rapLogoDataUri();

    $k = $d['kpis']; $l1 = $d['levier1']; $l2 = $d['levier2']; $l3 = $d['levier3'];

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .k{font-size:7.2pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a;font-weight:normal}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.ok{color:#2d7a3e}
      .h1{font-size:20pt;letter-spacing:-.01em;margin:4mm 0 1mm}
      .soustitre{font-size:9pt;color:#7a736a;margin:0 0 5mm}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      .tile .v{font-family:Georgia,"DejaVu Serif",serif;font-size:15pt;margin-top:1mm}
      .tile .s{font-size:7.5pt;color:#7a736a;margin-top:.8mm;line-height:1.45}
      table.grille{width:100%;border-collapse:separate;border-spacing:1.6mm 0;margin:0 -1.6mm 5mm}
      table.t{width:100%;border-collapse:collapse;margin-bottom:5mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;
            text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.6pt;text-align:right;padding:1.4mm 2mm;border-bottom:.5pt solid #EAE3D8}
      .t .l{text-align:left}
      .t td b{font-weight:bold}
      .barre{display:inline-block;position:relative;width:34mm;height:2.6mm;border-radius:2mm;background:#EFE9DF;vertical-align:middle}
      .barre i{position:absolute;left:0;top:0;height:2.6mm;border-radius:2mm}
      .barre s{position:absolute;top:-1mm;width:.6mm;height:4.6mm;background:#221E1A}
      .pastille{font-size:7pt;font-weight:bold;border-radius:3mm;padding:.6mm 2.2mm;white-space:nowrap}
      .p-asso{background:#F6E4E7;color:#8D1D2C}.p-cat{background:#FBEFE0;color:#8a5a1c}.p-prix{background:#E3EFE6;color:#2d7a3e}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;
               font-size:7.6pt;color:#7a736a;line-height:1.6;page-break-inside:avoid}
    </style>';

    // --- L'en-tête : le même bandeau que la note de campagne.
    $h = $css . '<div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">'
            : '<strong style="font-size:12pt">L’Atelier by</strong>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Analyse magasin<br>'
        . $e(mktBriefJour($d['du'])) . ' → ' . $e(mktBriefJour($d['au'])) . ' · ' . (int) $d['n'] . ' mois</td>'
        . '</tr></table>'

        . '<div class="serif h1">' . $e($court($d['nom'])) . '</div>'
        . '<p class="soustitre">Trois leviers pour développer le chiffre, chacun par mois et par an — comparé aux autres magasins du réseau, à fréquentation ramenée.</p>';

    // --- Les repères.
    $tuile = static fn (string $lbl, string $val, string $sub, string $cls = '') =>
        '<td width="25%" valign="top" class="tile"><div class="k">' . $lbl . '</div>'
        . '<div class="v ' . $cls . '">' . $val . '</div><div class="s">' . $sub . '</div></td>';
    $h .= '<table class="grille" cellpadding="0" cellspacing="0"><tr>'
        . $tuile('CA — ' . (int) $d['n'] . ' mois', $eur($k['ca']), $eur($k['caMois']) . ' / mois')
        . $tuile('Panier', $px2($k['panier']), 'réseau : ' . $px2($k['panierReseau']))
        . $tuile('Assortiment vendu', (string) (int) $k['refsVendues'],
            'meilleur magasin : ' . (int) $k['refsMax'] . ' références')
        . $tuile('Potentiel identifié', '+ ' . $eur($k['totalMois']) . ' <span style="font-size:9pt">/ mois</span>',
            '<b>' . $eur($k['totalAn']) . ' / an</b> — ' . $pcs($k['partCa']) . ' du CA actuel', 'acc')
        . '</tr></table>';

    // --- Les trois leviers.
    $carte = static fn (string $lbl, int $mois, int $an, string $regle, string $note) =>
        '<td width="33%" valign="top" class="tile"><div class="k">' . $lbl . '</div>'
        . '<div class="v acc">+ ' . number_format($mois, 0, ',', ' ') . ' € <span style="font-size:9pt">/ mois</span></div>'
        . '<div class="s"><b>' . number_format($an, 0, ',', ' ') . ' € / an</b> — ' . $regle . '</div>'
        . '<div class="s" style="color:#221E1A;margin-top:1.6mm">' . $note . '</div></td>';
    $h .= '<div class="sec">Les trois leviers</div>'
        . '<table class="grille" cellpadding="0" cellspacing="0"><tr>'
        . $carte('1 · Assortiment', (int) $l1['retenuMois'], (int) $l1['retenuAn'],
            'la moitié du manque', (int) $l1['refs'] . ' références que les autres vendent et pas lui.')
        . $carte('2 · Catégories', (int) $l2['potMois'], (int) $l2['potAn'],
            'retour à la part réseau', $l2['enRetrait'] . ' catégorie(s) en retrait — hors ce que le levier 1 compte déjà.')
        . $carte('3 · Prix', (int) $l3['potMois'], (int) $l3['potAn'],
            'à volume constant', (int) $l3['nb'] . ' référence(s) sous le prix des autres, sans volume supérieur.')
        . '</tr></table>';

    // --- Le mix par catégorie, barres à échelle commune.
    $grps = array_slice($l2['groupes'], 0, 10);
    $maxPart = 1.0;
    foreach ($grps as $g) { $maxPart = max($maxPart, (float) $g['part'], (float) $g['partReseau']); }
    $h .= '<div style="page-break-inside:avoid"><div class="sec">Le mix par catégorie, contre le réseau</div>'
        . '<table class="t" cellpadding="0" cellspacing="0"><tr>'
        . '<th class="l">Catégorie</th><th>Sa part</th><th>Part réseau</th><th class="l" style="padding-left:5mm">Position</th>'
        . '<th>Écart</th><th>Potentiel / mois</th><th>Par an</th></tr>';
    foreach ($grps as $g) {
        $retrait = $g['potMois'] > 0;
        $w  = (int) round(100 * (float) $g['part'] / $maxPart);
        $wr = (int) round(100 * (float) $g['partReseau'] / $maxPart);
        $h .= '<tr><td class="l"><b>' . $e($g['nom']) . '</b></td>'
            . '<td>' . $pcs($g['part']) . '</td><td class="mut">' . $pcs($g['partReseau']) . '</td>'
            . '<td class="l" style="padding-left:5mm"><span class="barre">'
            . '<i style="width:' . $w . '%;background:' . ($retrait ? '#8D1D2C' : '#2d7a3e') . '"></i>'
            . '<s style="left:' . min(99, $wr) . '%"></s></span></td>'
            . '<td class="' . ($retrait ? 'acc' : 'ok') . '"><b>' . ($g['delta'] > 0 ? '+ ' : '− ')
            . number_format(abs((float) $g['delta']), 1, ',', ' ') . ' pts</b></td>'
            . ($retrait
                ? '<td class="acc"><b>' . $eur($g['potMois']) . '</b></td><td class="mut">' . $eur($g['potAn']) . '</td>'
                : '<td class="mut" style="font-size:7.5pt">' . ($g['force'] ? 'sa force' : 'au niveau') . '</td><td class="mut">—</td>');
        $h .= '</tr>';
    }
    $h .= '</table></div>';

    // --- Page 2 : les prix, puis le plan.
    $h .= '<div style="page-break-before:always">'
        . '<div class="sec">Les prix sous le réseau — gain à volume constant</div>';
    if ($l3['refs'] === []) {
        $h .= '<p class="ok" style="font-size:9pt;margin:0 0 5mm">Aucun : la grille de ce magasin est au niveau du réseau.</p>';
    } else {
        $h .= '<table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Référence</th><th>Son prix</th><th>Prix réseau</th><th>Écart</th>'
            . '<th>Volume / mois</th><th>Gain / mois</th><th>Par an</th></tr>';
        foreach ($l3['refs'] as $r) {
            $h .= '<tr><td class="l"><b>' . $e($r['nom']) . '</b> <span class="mut">· ' . $e($r['groupe']) . '</span></td>'
                . '<td>' . $px2($r['prix']) . '</td><td class="mut">' . $px2($r['prixReseau']) . '</td>'
                . '<td class="acc">− ' . number_format(abs((float) $r['ecartPct']), 1, ',', ' ') . ' %</td>'
                . '<td>' . (int) $r['volMois'] . ' u</td>'
                . '<td class="acc"><b>' . $eur($r['gainMois']) . '</b></td>'
                . '<td class="mut">' . $eur($r['gainAn']) . '</td></tr>';
        }
        if (($l3['resteN'] ?? 0) > 0) {
            $h .= '<tr><td colspan="7" class="l mut">+ ' . (int) $l3['resteN']
                . ' autres références — ' . $eur($l3['resteMois']) . ' / mois</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '<div class="sec">Le plan, classé par euros mensuels</div>'
        . '<table class="t" cellpadding="0" cellspacing="0"><tr>'
        . '<th class="l" width="14">#</th><th class="l">Action</th><th class="l">Levier</th>'
        . '<th>/ mois</th><th>/ an</th><th>Cumul / an</th></tr>';
    $pastille = ['Assortiment' => 'p-asso', 'Catégorie' => 'p-cat', 'Prix' => 'p-prix'];
    foreach ($d['plan'] as $a) {
        $h .= '<tr><td class="l mut">' . (int) $a['rang'] . '</td>'
            . '<td class="l" style="line-height:1.45">' . $e($a['action']) . '</td>'
            . '<td class="l"><span class="pastille ' . ($pastille[$a['levier']] ?? 'p-cat') . '">' . $e($a['levier']) . '</span></td>'
            . '<td><b>' . $eur($a['eurMois']) . '</b></td>'
            . '<td class="mut">' . $eur($a['eurAn']) . '</td>'
            . '<td class="acc"><b>' . $eur($a['cumulAn']) . '</b></td></tr>';
    }
    $h .= '</table>'
        . '<div class="methode"><b style="color:#221E1A">Comment lire ces chiffres.</b> '
        . 'L’assortiment est retenu à la moitié de son estimation — un plan qui promet le maximum n’est pas un plan. '
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
