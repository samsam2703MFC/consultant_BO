<?php
declare(strict_types=1);

/**
 * Cockpit CEO — le manque à gagner d'assortiment.
 *
 * « Usage du catalogue » dit COMBIEN de références un magasin ne vend pas.
 * C'est un compte, et un compte ne se traite pas : 105 références absentes ne
 * disent ni ce qu'elles valent, ni par où commencer. Cet écran les valorise —
 * en euros, par mois, magasin par magasin.
 *
 * Le principe tient en une phrase : pour chaque référence qu'un magasin n'a
 * pas vendue ce mois-là alors que d'AUTRES la vendaient, on estime ce qu'il en
 * aurait fait s'il l'avait proposée, et on le valorise au prix réellement
 * pratiqué.
 *
 * Quatre précautions, qui sont tout le travail :
 *
 *  - Une référence vendue par UN SEUL magasin n'entre pas au calcul. C'est une
 *    spécialité — le pain d'un boulanger qui a sa recette —, pas un manque.
 *  - Le volume retenu est la MÉDIANE des vendeurs, pas leur moyenne. Sur trois
 *    magasins, une opération promotionnelle chez l'un tirerait la moyenne et
 *    donnerait un manque imaginaire aux deux autres.
 *  - Tout est ramené au JOUR D'OUVERTURE, puis à la FRÉQUENTATION du magasin :
 *    sans ce dernier facteur, le plus petit magasin du réseau se verrait
 *    attribuer le potentiel du plus grand, et son « manque » serait surtout sa
 *    taille.
 *  - Le prix est celui qui a été ENCAISSÉ chez les vendeurs (CA ÷ quantité),
 *    pas un prix de catalogue : c'est le seul qui tient compte des remises.
 *
 * Et ce que ça reste : une estimation à assortiment comparable. L'écran le dit
 * en toutes lettres — un franchisé à qui on annonce un manque de 69 000 €
 * doit savoir d'où le chiffre sort.
 */

/** La médiane d'une liste non vide. */
function mqMediane(array $v): float
{
    sort($v);
    $n = count($v);
    if ($n === 0) { return 0.0; }
    $m = intdiv($n, 2);
    return $n % 2 === 1 ? (float) $v[$m] : ((float) $v[$m - 1] + (float) $v[$m]) / 2;
}

/**
 * GET /produits/manque?mois=6[&groupe=]
 *
 * Le manque à gagner par magasin et par mois, le détail par référence de
 * chaque magasin, et le classement réseau des références qui coûtent le plus.
 */
function ep_prod_manque(): array
{
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 1 || $n > 12) { $n = 6; }
    $groupe = trim((string) ($_GET['groupe'] ?? ''));

    $mois = utilMois($n);
    $du = $mois[0]['du'];
    $au = $mois[count($mois) - 1]['au'];

    $out = ['mois' => $mois, 'magasins' => [], 'references' => [], 'reseau' => null,
        'groupe' => $groupe, 'horsGamme' => 0, 'seuil' => 2, 'motif' => null,
        'source' => null];

    // --- Le catalogue, gammes fermées écartées (même règle que l'usage).
    $refs = [];
    foreach (ep_prod_catalogue() as $p) {
        $g = (string) ($p['groupe'] ?? '');
        if ($groupe !== '' && $g !== $groupe) { continue; }
        $pid = $p['pwaId'] ?? null;
        if ($pid === null) { continue; }
        $refs[(int) $pid] = ['ref' => (string) $p['ref'], 'nom' => (string) $p['nom'],
            'groupe' => $g !== '' ? $g : '— hors groupe',
            'categorie' => ((string) ($p['categorie'] ?? '')) !== '' ? (string) $p['categorie'] : '— sans catégorie',
            'prix' => $p['prix'] !== null ? (float) $p['prix'] : null,
            'mat' => $p['mat'] !== null ? (float) $p['mat'] : null];
    }
    $ecart = utilEcarterHorsGamme($refs, $mois, false);
    $out['horsGamme'] = $ecart['n'];
    $out['gammesEcartees'] = $ecart['gammes'];
    if ($refs === []) { $out['motif'] = 'aucune référence à rapprocher'; return $out; }

    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
    if ($ct === null || $cl === null) {
        $out['motif'] = 'la caisse n’expose pas ses lignes de ticket sur cette base';
        return $out;
    }

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }

    $borneA = $du . ' 00:00:00';
    $borneB = date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00';

    // --- L'activité du magasin : jours ouverts, tickets, CA. C'est elle qui
    // met le potentiel à l'échelle — un manque n'a de sens que rapporté à ce
    // que le magasin fait réellement passer en caisse.
    $act = [];
    try {
        $sql = sprintf(
            'SELECT `%s` AS shop, DATE_FORMAT(`%s`, \'%%Y-%%m\') AS mois,
                    COUNT(DISTINCT DATE(`%s`)) jours,
                    COUNT(*) tickets,
                    SUM(total_gross_amount_after_discount) ca
               FROM `transaction` WHERE `%s` >= ? AND `%s` < ?
              GROUP BY shop, mois',
            $ct['shop'], $ct['date'], $ct['date'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$borneA, $borneB]) as $r) {
            $sid = (string) $r['shop'];
            if (!isset($nomDe[$sid])) { continue; }
            $act[$sid][(string) $r['mois']] = [
                'jours' => max(1, (int) $r['jours']),
                'tickets' => (int) $r['tickets'],
                'ca' => (float) $r['ca'],
            ];
        }
    } catch (PDOException $e) {
        $out['motif'] = 'lecture de l’activité des magasins impossible';
        return $out;
    }
    if ($act === []) { $out['motif'] = 'aucune vente sur la période'; return $out; }

    // --- Qui a vendu quoi, combien, et à quel prix encaissé.
    $vendu = [];   // mois => pid => shop => ['q'=>…, 'ca'=>…]
    try {
        $sql = sprintf(
            'SELECT t.`%s` AS shop, DATE_FORMAT(t.`%s`, \'%%Y-%%m\') AS mois,
                    l.`%s` AS produit, SUM(l.`%s`) AS q,
                    SUM(l.total_gross_value_after_discount) AS ca
               FROM `transaction_product` l
               JOIN `transaction` t ON t.`%s` = l.`%s`
              WHERE t.`%s` >= ? AND t.`%s` < ?
              GROUP BY shop, mois, produit',
            $ct['shop'], $ct['date'], $cl['produit'], $cl['quantite'],
            $ct['id'], $cl['ticket'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$borneA, $borneB]) as $r) {
            $pid = (int) $r['produit'];
            if (!isset($refs[$pid])) { continue; }
            $sid = (string) $r['shop'];
            if (!isset($nomDe[$sid])) { continue; }
            $q = (float) $r['q'];
            if ($q <= 0) { continue; }
            $vendu[(string) $r['mois']][$pid][$sid] = ['q' => $q, 'ca' => (float) $r['ca']];
        }
    } catch (PDOException $e) {
        $out['motif'] = 'lecture des lignes de ticket impossible : ' . $e->getMessage();
        return $out;
    }

    // --- Le calcul.
    $parShopMois = [];   // shop => mois => ['eur','marge','unites','refs']
    $parShopGrp  = [];   // shop => groupe => eur — pour croiser avec le mix
    $parShopRef  = [];   // shop => pid => ['eur','marge','unites','mois']
    $parRef      = [];   // pid => ['eur','marge','unites','shops'=>[]]

    foreach ($mois as $m) {
        $cle = $m['cle'];
        $lignes = $vendu[$cle] ?? [];
        foreach ($refs as $pid => $r) {
            $par = $lignes[$pid] ?? [];
            if (count($par) < 2) { continue; }   // spécialité, pas manque

            // Prix ENCAISSÉ chez les vendeurs — remises comprises.
            $qTot = 0.0; $caTot = 0.0;
            $tauxJour = []; $freqVendeurs = [];
            foreach ($par as $sid => $v) {
                $a = $act[$sid][$cle] ?? null;
                if ($a === null) { continue; }
                $qTot += $v['q']; $caTot += $v['ca'];
                $tauxJour[] = $v['q'] / $a['jours'];
                $freqVendeurs[] = $a['tickets'] / $a['jours'];
            }
            if ($tauxJour === [] || $qTot <= 0) { continue; }
            $prixU = $caTot / $qTot;
            if ($prixU <= 0) { $prixU = $r['prix'] ?? 0.0; }
            $margeU = ($r['mat'] !== null && $prixU > 0) ? max(0.0, $prixU - $r['mat']) : null;
            $mediane = mqMediane($tauxJour);
            $freqRef = mqMediane($freqVendeurs);
            if ($mediane <= 0 || $freqRef <= 0) { continue; }

            foreach ($nomDe as $sid => $nom) {
                if (isset($par[$sid])) { continue; }        // il la vend déjà
                $a = $act[$sid][$cle] ?? null;
                if ($a === null) { continue; }              // fermé ce mois-là
                // Mise à l'échelle : sa fréquentation contre celle des vendeurs.
                $echelle = ($a['tickets'] / $a['jours']) / $freqRef;
                $unites = $mediane * $a['jours'] * $echelle;
                if ($unites < 0.5) { continue; }            // sous l'unité : bruit
                $eur = $unites * $prixU;
                $mrg = $margeU !== null ? $unites * $margeU : 0.0;

                $parShopMois[$sid][$cle]['eur'] = ($parShopMois[$sid][$cle]['eur'] ?? 0) + $eur;
                $parShopMois[$sid][$cle]['marge'] = ($parShopMois[$sid][$cle]['marge'] ?? 0) + $mrg;
                $parShopMois[$sid][$cle]['unites'] = ($parShopMois[$sid][$cle]['unites'] ?? 0) + $unites;
                $parShopMois[$sid][$cle]['refs'] = ($parShopMois[$sid][$cle]['refs'] ?? 0) + 1;

                $parShopGrp[$sid][$r['groupe']] = ($parShopGrp[$sid][$r['groupe']] ?? 0) + $eur;
                $parShopRef[$sid][$pid]['eur'] = ($parShopRef[$sid][$pid]['eur'] ?? 0) + $eur;
                $parShopRef[$sid][$pid]['marge'] = ($parShopRef[$sid][$pid]['marge'] ?? 0) + $mrg;
                $parShopRef[$sid][$pid]['unites'] = ($parShopRef[$sid][$pid]['unites'] ?? 0) + $unites;
                $parShopRef[$sid][$pid]['mois'] = ($parShopRef[$sid][$pid]['mois'] ?? 0) + 1;
                $parShopRef[$sid][$pid]['prix'] = $prixU;
                $parShopRef[$sid][$pid]['vendeurs'] = count($par);

                $parRef[$pid]['eur'] = ($parRef[$pid]['eur'] ?? 0) + $eur;
                $parRef[$pid]['marge'] = ($parRef[$pid]['marge'] ?? 0) + $mrg;
                $parRef[$pid]['unites'] = ($parRef[$pid]['unites'] ?? 0) + $unites;
                $parRef[$pid]['shops'][$nom] = true;
            }
        }
    }

    // --- Le tableau des magasins, et le détail de chacun.
    $lignesShop = [];
    foreach ($nomDe as $sid => $nom) {
        $parMois = []; $totEur = 0.0; $totMrg = 0.0; $totU = 0.0; $ca = 0.0;
        foreach ($mois as $m) {
            $a = $act[$sid][$m['cle']] ?? null;
            $x = $parShopMois[$sid][$m['cle']] ?? null;
            $ca += $a['ca'] ?? 0.0;
            $parMois[] = ['cle' => $m['cle'],
                'charge' => $a !== null,
                'eur' => $x ? round($x['eur']) : ($a !== null ? 0 : null),
                'marge' => $x ? round($x['marge']) : ($a !== null ? 0 : null),
                'unites' => $x ? (int) round($x['unites']) : ($a !== null ? 0 : null),
                'refs' => $x['refs'] ?? 0];
            $totEur += $x['eur'] ?? 0; $totMrg += $x['marge'] ?? 0; $totU += $x['unites'] ?? 0;
        }

        $top = [];
        foreach ($parShopRef[$sid] ?? [] as $pid => $v) {
            $top[] = ['ref' => $refs[$pid]['ref'], 'nom' => $refs[$pid]['nom'],
                'categorie' => $refs[$pid]['categorie'], 'groupe' => $refs[$pid]['groupe'],
                'eur' => round($v['eur']), 'marge' => round($v['marge']),
                'unites' => (int) round($v['unites']),
                'unitesMois' => (int) round($v['unites'] / max(1, $v['mois'])),
                'prix' => round($v['prix'], 2), 'vendeurs' => $v['vendeurs'], 'mois' => $v['mois']];
        }
        usort($top, static fn ($a, $b) => $b['eur'] <=> $a['eur']);
        $reste = array_slice($top, 12);
        $lignesShop[] = [
            'id' => $sid, 'nom' => $nom,
            'mois' => $parMois,
            'eur' => round($totEur), 'marge' => round($totMrg), 'unites' => (int) round($totU),
            'ca' => round($ca), 'part' => $ca > 0 ? round(100 * $totEur / $ca, 1) : null,
            'refs' => count($top),
            // Le manque VENTILÉ par groupe : l'analyse magasin s'en sert pour
            // ne pas compter deux fois ce que le mix et l'assortiment
            // expliquent ensemble.
            'parGroupe' => array_map('intval', array_map('round', $parShopGrp[$sid] ?? [])),
            'top' => array_slice($top, 0, 12),
            'resteN' => count($reste), 'resteEur' => round(array_sum(array_column($reste, 'eur'))),
        ];
    }
    usort($lignesShop, static fn ($a, $b) => $b['eur'] <=> $a['eur']);

    // --- Le classement réseau des références.
    $refLignes = [];
    foreach ($parRef as $pid => $v) {
        $shops = array_keys($v['shops']);
        sort($shops);
        $refLignes[] = ['ref' => $refs[$pid]['ref'], 'nom' => $refs[$pid]['nom'],
            'groupe' => $refs[$pid]['groupe'], 'categorie' => $refs[$pid]['categorie'],
            'eur' => round($v['eur']), 'marge' => round($v['marge']),
            'unites' => (int) round($v['unites']),
            'absente' => $shops, 'nAbsente' => count($shops)];
    }
    usort($refLignes, static fn ($a, $b) => $b['eur'] <=> $a['eur']);

    $caReseau = array_sum(array_column($lignesShop, 'ca'));
    $eurReseau = array_sum(array_column($lignesShop, 'eur'));
    $out['magasins'] = $lignesShop;
    $out['references'] = array_slice($refLignes, 0, 60);
    $out['referencesN'] = count($refLignes);
    $out['reseau'] = [
        'eur' => $eurReseau, 'marge' => array_sum(array_column($lignesShop, 'marge')),
        'unites' => array_sum(array_column($lignesShop, 'unites')),
        'ca' => $caReseau, 'part' => $caReseau > 0 ? round(100 * $eurReseau / $caReseau, 1) : null,
        'parMois' => (int) round($eurReseau / max(1, count($mois))),
        'refs' => count($refLignes), 'magasins' => count($lignesShop),
    ];
    $out['source'] = 'lignes de ticket de la caisse, ' . mktBriefJour($du) . ' → ' . mktBriefJour($au)
        . ' — médiane des vendeurs par jour d’ouverture, mise à l’échelle de la fréquentation, au prix encaissé';
    return $out;
}
