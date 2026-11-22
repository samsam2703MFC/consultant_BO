<?php
declare(strict_types=1);

/**
 * Les gains du commercial (portail Commercial, 19/11/2026, demande : « le nombre d'euros qu'il gagne, avec le détail :
 * 20 % des commandes le premier mois, ensuite 5 % — tout en paramètre, par secteur — avec un plancher minimum »).
 *
 * Les règles (réglées dans Paramètres, `smReglages.gains`) :
 *  - un client compte s'il a été apporté par le commercial : un client du portail (sm_client), rattaché à son compte du
 *    panel (clé « c{id} ») ; seules ses commandes à partir du jour où il est entré au portail (`debut`) comptent ;
 *  - sa 1re commande ouvre le compteur : `taux1` % du HT de chaque commande jusqu'au mois `mois1`, `taux2` % jusqu'au
 *    mois `mois2`, `taux3` % jusqu'au mois `duree` (0 = sans fin) — par défaut 20 % le 1er mois, 10 % les mois 2 et 3,
 *    5 % jusqu'au 12e ; au-delà, rien ;
 *  - une commande sous `min` € HT ne rapporte rien ;
 *  - chaque secteur (type d'offre de Prospection) peut avoir sa règle ; sinon la règle par défaut ;
 *  - le plancher : si les gains du mois sont sous `plancher` €, le commercial touche le plancher (le complément est dit) ;
 *  - le HT se déduit du montant encaissé (après la remise personnelle du client) au taux `tva` (6 % par défaut) ;
 *  - l'objectif : `objectif` € HT par mois apportés par ses clients (5 000 € par défaut) ; son coût `cout` € par mois ; le
 *    ROI = (retour − coût total) ÷ coût total, le retour étant le CA HT, ou la marge `marge` % quand elle est réglée.
 *
 * Les commandes sont les tickets pro du panel (TVA valide), lus dans les relevés quotidiens (src/ventes_pro.php).
 */

const SM_GAINS_HISTO = 6;   // les mois montrés dans l'historique

/** La phase d'une commande du jour $j pour un client dont la 1re commande est $premiere : [taux, phase]. */
function smGainsPhase(string $j, string $premiere, array $r): array
{
    $fin = static fn (int $m): string => date('Y-m-d', (int) strtotime($premiere . ' +' . $m . ' months'));
    if ($j < $fin((int) $r['mois1'])) { return [(float) $r['taux1'], 'depart']; }
    if ($j < $fin((int) ($r['mois2'] ?? $r['mois1']))) { return [(float) $r['taux2'], 'palier']; }
    if ((int) $r['duree'] === 0 || $j < $fin((int) $r['duree'])) { return [(float) ($r['taux3'] ?? $r['taux2']), 'suite']; }
    return [0.0, 'termine'];
}

/**
 * La simulation (19/11/2026, « ce que lui rapporte un client, dans son plan de prospection ») : un client qui commande
 * $htMois € HT par mois, sous la règle $r, mois par mois sur `$mois` mois ; ce que paie le franchisé (le taux de départ,
 * si $departFranchise) et la marque.
 */
function smSimulation(float $htMois, array $r, bool $departFranchise, int $mois = 12): array
{
    $l = []; $tot = 0.0; $fr = 0.0;
    $debut = '2026-01-01';
    for ($m = 0; $m < $mois; $m++) {
        $j = date('Y-m-d', (int) strtotime($debut . ' +' . $m . ' months'));
        [$taux, $phase] = smGainsPhase($j, $debut, $r);
        $g = $htMois >= (float) $r['min'] ? round($htMois * $taux / 100, 2) : 0.0;
        $f = $departFranchise && $phase === 'depart' ? $g : 0.0;
        $l[] = ['mois' => $m + 1, 'taux' => $taux, 'phase' => $phase, 'gain' => $g, 'franchise' => $f];
        $tot += $g; $fr += $f;
    }
    return ['htMois' => round($htMois, 2), 'mois' => $l, 'total' => round($tot, 2), 'franchise' => round($fr, 2), 'marque' => round($tot - $fr, 2), 'regle' => $r];
}

/**
 * GET /commercial/simulation?secteur=&personnes=&depense=&commandes=&ht= — ce que rapporte un client du secteur : son CA
 * HT par mois (donné, ou estimé comme Prospection : personnes × dépense × commandes par semaine × 4,33) et les gains de
 * l'année sous la règle du secteur.
 */
function ep_sm_simulation(): array
{
    $reg = smReglages();
    $sec = (string) ($_GET['secteur'] ?? '');
    $offre = null;
    foreach (prOffres() as $o) { if ($o['id'] === $sec) { $offre = $o; } }
    $pers = max(1, (int) ($_GET['personnes'] ?? 20));
    $dep = is_numeric($_GET['depense'] ?? null) ? (float) $_GET['depense'] : (float) ($offre['depense'] ?? 5);
    $cmd = is_numeric($_GET['commandes'] ?? null) ? (float) $_GET['commandes'] : (float) ($offre['commandes'] ?? 1);
    $ht = is_numeric($_GET['ht'] ?? null) && (float) $_GET['ht'] > 0 ? (float) $_GET['ht'] : round($pers * $dep * $cmd * 4.33, 2);
    return smSimulation($ht, smRegleSecteur($reg['gains'], $sec), (bool) ($reg['gains']['departFranchise'] ?? true))
        + ['secteur' => $sec, 'personnes' => $pers, 'depense' => $dep, 'commandes' => $cmd, 'estime' => !(is_numeric($_GET['ht'] ?? null) && (float) $_GET['ht'] > 0)];
}

/**
 * Le calcul, sans base.
 * $clients : [{id, entreprise, secteur, magasin, shopId, cle, debut}] ; $tickets : [[shopId, cle, 'AAAA-MM-JJ', 'hh:mm', montant encaissé]] ;
 * $gains : la partie `gains` des réglages ; $mois : « AAAA-MM ». Rend le détail par client, les totaux, le plancher.
 */
function smGainsCalcul(array $clients, array $tickets, array $gains, string $mois): array
{
    $tva = (float) ($gains['tva'] ?? SM_TVA);
    $parCle = [];
    foreach ($tickets as $t) { $parCle[$t[0] . '|' . $t[1]][] = $t; }
    $lignes = []; $tot = ['commandes' => 0, 'ht' => 0.0, 'gain' => 0.0, 'franchise' => 0.0]; $parSecteur = []; $parMagasin = [];
    // les commissions du taux de départ sont à la charge du franchisé du magasin (19/11/2026), si le réglage le dit
    $departFranchise = (bool) ($gains['departFranchise'] ?? true);
    foreach ($clients as $c) {
        $r = smRegleSecteur($gains, (string) $c['secteur']);
        $ts = array_values(array_filter($parCle[$c['shopId'] . '|' . $c['cle']] ?? [], static fn ($t) => $t[2] >= $c['debut']));
        usort($ts, static fn ($a, $b) => strcmp($a[2] . $a[3], $b[2] . $b[3]));
        $premiere = $ts[0][2] ?? null;
        $cmd = []; $ht = 0.0; $gain = 0.0; $gFr = 0.0;
        foreach ($ts as $t) {
            if (substr($t[2], 0, 7) !== $mois) { continue; }
            $h = smHt((float) $t[4], $tva);
            [$taux, $phase] = smGainsPhase($t[2], (string) $premiere, $r);
            $sousMin = $h < (float) $r['min'];
            $g = $sousMin ? 0.0 : round($h * $taux / 100, 2);
            $fr = $departFranchise && $phase === 'depart' ? $g : 0.0;
            $cmd[] = ['j' => $t[2], 'h' => $t[3], 'ttc' => round((float) $t[4], 2), 'ht' => $h, 'taux' => $sousMin ? 0.0 : $taux, 'phase' => $sousMin ? 'min' : $phase, 'gain' => $g,
                'payeur' => $g > 0 ? ($fr > 0 ? 'franchise' : 'marque') : ''];
            $ht += $h; $gain += $g; $gFr += $fr;
        }
        $lignes[] = ['id' => $c['id'], 'entreprise' => $c['entreprise'], 'secteur' => $c['secteur'], 'magasin' => $c['magasin'], 'shopId' => $c['shopId'], 'premiere' => $premiere,
            'regle' => $r, 'commandes' => $cmd, 'n' => count($cmd), 'ht' => round($ht, 2), 'gain' => round($gain, 2), 'gainFranchise' => round($gFr, 2),
            'finDepart' => $premiere ? date('Y-m-d', (int) strtotime($premiere . ' +' . (int) $r['mois1'] . ' months')) : null];
        $tot['commandes'] += count($cmd); $tot['ht'] += $ht; $tot['gain'] += $gain; $tot['franchise'] += $gFr;
        if ($gFr > 0) { $k = (string) $c['shopId']; $parMagasin[$k] = ['shopId' => $k, 'magasin' => $c['magasin'], 'montant' => round(($parMagasin[$k]['montant'] ?? 0) + $gFr, 2)]; }
        $s = (string) $c['secteur'];
        $parSecteur[$s] = ['secteur' => $s, 'clients' => ($parSecteur[$s]['clients'] ?? 0) + ($cmd ? 1 : 0),
            'ht' => round(($parSecteur[$s]['ht'] ?? 0) + $ht, 2), 'gain' => round(($parSecteur[$s]['gain'] ?? 0) + $gain, 2)];
    }
    usort($lignes, static fn ($a, $b) => $b['gain'] <=> $a['gain']);
    $gainT = round($tot['gain'], 2);
    $plancher = (float) ($gains['plancher'] ?? 0);
    $complement = $plancher > 0 && $gainT < $plancher ? round($plancher - $gainT, 2) : 0.0;
    $du = round($gainT + $complement, 2);
    $franchise = round($tot['franchise'], 2);
    return ['mois' => $mois, 'clients' => $lignes, 'commandes' => $tot['commandes'], 'ht' => round($tot['ht'], 2), 'gain' => $gainT,
        'plancher' => $plancher, 'complement' => $complement, 'du' => $du, 'parSecteur' => array_values($parSecteur),
        // ce que paient les franchisés (le taux de départ, magasin par magasin) et ce que paie la marque (le reste, plancher compris)
        'franchises' => array_values($parMagasin), 'aLaChargeFranchises' => $franchise, 'aLaChargeMarque' => round($du - $franchise, 2)]
        + smGainsBilan(round($tot['ht'], 2), round($du - $franchise, 2), $gains, $lignes, $mois);
}

/**
 * Le bilan du mois (19/11/2026, demande : « il doit ramener au moins 5 000 € de nouveaux clients par mois ; mettre son
 * coût au mois pour voir son ROI ») : l'objectif et son atteinte (le CA HT des clients qu'il a apportés), le coût du
 * commercial pour la marque (son coût mensuel + ce que la marque lui paie : les commissions du taux de départ sont à la
 * charge des franchisés), le ROI de la marque. Le retour est le CA HT, ou la marge si `marge` est réglée.
 */
function smGainsBilan(float $ht, float $du, array $gains, array $lignes, string $mois): array
{
    $obj = (float) ($gains['objectif'] ?? SM_OBJECTIF);
    $coutFixe = (float) ($gains['cout'] ?? 0);
    $marge = (float) ($gains['marge'] ?? 0);
    $coutTotal = round($coutFixe + $du, 2);
    $retour = round($marge > 0 ? $ht * $marge / 100 : $ht, 2);
    $nouveaux = array_filter($lignes, static fn ($l) => $l['premiere'] !== null && substr((string) $l['premiere'], 0, 7) === $mois);
    return ['objectif' => $obj, 'atteinte' => $obj > 0 ? round($ht / $obj * 100, 1) : null, 'objectifAtteint' => $obj > 0 ? $ht >= $obj : null, 'manque' => $obj > $ht ? round($obj - $ht, 2) : 0.0,
        'nouveauxDuMois' => count($nouveaux), 'htNouveauxDuMois' => round(array_sum(array_column($nouveaux, 'ht')), 2),
        'cout' => $coutFixe, 'coutTotal' => $coutTotal, 'marge' => $marge, 'retour' => $retour,
        // sans coût mensuel réglé, pas de ROI : rapporté aux seules commissions, il ne dirait rien
        'roi' => $coutFixe > 0 ? round(($retour - $coutTotal) / $coutTotal * 100, 1) : null, 'multiple' => $coutFixe > 0 ? round($retour / $coutTotal, 2) : null];
}

/** GET /commercial/gains?mois=AAAA-MM — le détail du mois et l'historique des derniers mois. */
function ep_sm_gains(): array
{
    smTables();
    @set_time_limit(90);
    $mois = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? (string) $_GET['mois'] : date('Y-m');
    if ($mois > date('Y-m')) { $mois = date('Y-m'); }
    $reg = smReglages();
    $mags = smMagasins(); $sec = smSecteurs();
    $rows = Db::rows('SELECT * FROM sm_client ORDER BY entreprise');
    $clients = []; $nonRattaches = [];
    foreach ($rows as $c) {
        $v = ['id' => (int) $c['id'], 'entreprise' => $c['entreprise'], 'secteur' => $c['secteur'], 'secteurNom' => $sec[$c['secteur']]['nom'] ?? '',
            'magasin' => $mags[$c['shop_id']]['nom'] ?? $c['shop_id'], 'shopId' => $c['shop_id'], 'cle' => $c['cle_panel'], 'debut' => $c['debut']];
        if ($c['cle_panel'] === '') { $nonRattaches[] = $v; } else { $clients[] = $v; }
    }
    // les tickets : de la 1re entrée au portail jusqu'à la fin du mois (ou aujourd'hui) ; le mois choisi se complète au panel
    $fin = min(date('Y-m-t', (int) strtotime($mois . '-01')), date('Y-m-d'));
    $debutMois = $mois . '-01';
    $tickets = []; $manquants = 0; $appels = 0;
    $parShop = [];
    foreach ($clients as $c) { $parShop[$c['shopId']][$c['cle']] = true; $parShop[$c['shopId']]['_debut'] = min($parShop[$c['shopId']]['_debut'] ?? $c['debut'], $c['debut']); }
    foreach ($parShop as $sid => $cles) {
        $d = (string) $cles['_debut'];
        for ($j = $d; $j <= $fin; $j = date('Y-m-d', (int) strtotime($j . ' +1 day'))) {
            $dansMois = $j >= $debutMois;
            $t = smTicketsJour((int) $sid, $j, $dansMois, $appels, 25);
            if ($t === null) { if ($dansMois) { $manquants++; } continue; }
            foreach ($t as [$h, $cle, $mt]) { if ($cle !== '' && isset($cles[$cle])) { $tickets[] = [(string) $sid, $cle, $j, $h, $mt]; } }
        }
    }
    $calc = smGainsCalcul($clients, $tickets, $reg['gains'], $mois);
    foreach ($calc['clients'] as &$l) { $l['secteurNom'] = $sec[$l['secteur']]['nom'] ?? ''; }
    unset($l);
    foreach ($calc['parSecteur'] as &$s) { $s['nom'] = $s['secteur'] === '' ? 'Sans secteur' : ($sec[$s['secteur']]['nom'] ?? $s['secteur']); }
    unset($s);
    $histo = [];
    for ($i = SM_GAINS_HISTO - 1; $i >= 0; $i--) {
        $m = date('Y-m', (int) strtotime($debutMois . ' -' . $i . ' months'));
        $x = $m === $mois ? $calc : smGainsCalcul($clients, $tickets, $reg['gains'], $m);
        $histo[] = ['mois' => $m, 'gain' => $x['gain'], 'complement' => $x['complement'], 'du' => $x['du'], 'commandes' => $x['commandes'], 'ht' => $x['ht'],
            'objectif' => $x['objectif'], 'atteinte' => $x['atteinte'], 'coutTotal' => $x['coutTotal'], 'roi' => $x['roi'], 'aLaChargeFranchises' => $x['aLaChargeFranchises']];
    }
    return $calc + ['regles' => $reg['gains'], 'historique' => $histo, 'nonRattaches' => $nonRattaches, 'manquants' => $manquants,
        'source' => 'tickets pro du panel (TVA valide) des clients du portail, rattachés à leur compte ; HT estimé au taux de ' . str_replace('.', ',', (string) $reg['gains']['tva']) . ' %'];
}

/**
 * GET /commercial/commissions?mois=AAAA-MM — la commission commerciale à prélever à chaque franchisé avec ses royalties
 * du mois (19/11/2026 : « les 20 % sont prélevés via une commission spécifique en même temps que les royalties ») :
 * magasin par magasin, le montant HT et le détail par client. Lu par l'écran, l'export et, demain, la facture des
 * royalties de l'ERP.
 */
function ep_sm_commissions(): array
{
    $g = ep_sm_gains();
    $par = [];
    foreach ($g['clients'] as $c) {
        if ((float) ($c['gainFranchise'] ?? 0) <= 0) { continue; }
        $k = (string) $c['shopId'];
        $par[$k] = $par[$k] ?? ['shopId' => $k, 'magasin' => $c['magasin'], 'montantHt' => 0.0, 'clients' => []];
        $par[$k]['montantHt'] = round($par[$k]['montantHt'] + (float) $c['gainFranchise'], 2);
        $par[$k]['clients'][] = ['entreprise' => $c['entreprise'], 'premiere' => $c['premiere'], 'finDepart' => $c['finDepart'],
            'commandes' => count(array_filter($c['commandes'], static fn ($x) => ($x['payeur'] ?? '') === 'franchise')),
            'htCommandes' => round(array_sum(array_map(static fn ($x) => ($x['payeur'] ?? '') === 'franchise' ? (float) $x['ht'] : 0.0, $c['commandes'])), 2),
            'taux' => (float) $c['regle']['taux1'], 'commission' => (float) $c['gainFranchise']];
    }
    return ['mois' => $g['mois'], 'libelle' => 'Commission commerciale B2B', 'magasins' => array_values($par),
        'total' => round(array_sum(array_column($par, 'montantHt')), 2), 'manquants' => $g['manquants'] ?? 0,
        'note' => 'Montants HT, au taux de départ, à prélever avec les royalties du mois ; la TVA s’ajoute à la facture.'];
}

