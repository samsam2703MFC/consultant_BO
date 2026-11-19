<?php
declare(strict_types=1);

/**
 * Les gains du commercial (portail Commercial, 19/11/2026, demande : « le nombre d'euros qu'il gagne, avec le détail :
 * 20 % des commandes le premier mois, ensuite 5 % — tout en paramètre, par secteur — avec un plancher minimum »).
 *
 * Les règles (réglées dans Paramètres, `smReglages.gains`) :
 *  - un client compte s'il a été apporté par le commercial : un client du portail (sm_client), rattaché à son compte du
 *    panel (clé « c{id} ») ; seules ses commandes à partir du jour où il est entré au portail (`debut`) comptent ;
 *  - sa 1re commande ouvre le compteur : pendant `mois1` mois, `taux1` % du HT de chaque commande ; ensuite `taux2` %,
 *    pendant `duree` mois depuis la 1re commande (0 = sans fin) ; au-delà, rien ;
 *  - une commande sous `min` € HT ne rapporte rien ;
 *  - chaque secteur (type d'offre de Prospection) peut avoir sa règle ; sinon la règle par défaut ;
 *  - le plancher : si les gains du mois sont sous `plancher` €, le commercial touche le plancher (le complément est dit) ;
 *  - le HT se déduit du montant encaissé (après la remise personnelle du client) au taux `tva` (6 % par défaut).
 *
 * Les commandes sont les tickets pro du panel (TVA valide), lus dans les relevés quotidiens (src/ventes_pro.php).
 */

const SM_GAINS_HISTO = 6;   // les mois montrés dans l'historique

/** La phase d'une commande du jour $j pour un client dont la 1re commande est $premiere : [taux, phase]. */
function smGainsPhase(string $j, string $premiere, array $r): array
{
    $finDepart = date('Y-m-d', (int) strtotime($premiere . ' +' . (int) $r['mois1'] . ' months'));
    if ($j < $finDepart) { return [(float) $r['taux1'], 'depart']; }
    if ((int) $r['duree'] === 0 || $j < date('Y-m-d', (int) strtotime($premiere . ' +' . (int) $r['duree'] . ' months'))) { return [(float) $r['taux2'], 'suite']; }
    return [0.0, 'termine'];
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
    $lignes = []; $tot = ['commandes' => 0, 'ht' => 0.0, 'gain' => 0.0]; $parSecteur = [];
    foreach ($clients as $c) {
        $r = smRegleSecteur($gains, (string) $c['secteur']);
        $ts = array_values(array_filter($parCle[$c['shopId'] . '|' . $c['cle']] ?? [], static fn ($t) => $t[2] >= $c['debut']));
        usort($ts, static fn ($a, $b) => strcmp($a[2] . $a[3], $b[2] . $b[3]));
        $premiere = $ts[0][2] ?? null;
        $cmd = []; $ht = 0.0; $gain = 0.0;
        foreach ($ts as $t) {
            if (substr($t[2], 0, 7) !== $mois) { continue; }
            $h = smHt((float) $t[4], $tva);
            [$taux, $phase] = smGainsPhase($t[2], (string) $premiere, $r);
            $sousMin = $h < (float) $r['min'];
            $g = $sousMin ? 0.0 : round($h * $taux / 100, 2);
            $cmd[] = ['j' => $t[2], 'h' => $t[3], 'ttc' => round((float) $t[4], 2), 'ht' => $h, 'taux' => $sousMin ? 0.0 : $taux, 'phase' => $sousMin ? 'min' : $phase, 'gain' => $g];
            $ht += $h; $gain += $g;
        }
        $lignes[] = ['id' => $c['id'], 'entreprise' => $c['entreprise'], 'secteur' => $c['secteur'], 'magasin' => $c['magasin'], 'premiere' => $premiere,
            'regle' => $r, 'commandes' => $cmd, 'n' => count($cmd), 'ht' => round($ht, 2), 'gain' => round($gain, 2),
            'finDepart' => $premiere ? date('Y-m-d', (int) strtotime($premiere . ' +' . (int) $r['mois1'] . ' months')) : null];
        $tot['commandes'] += count($cmd); $tot['ht'] += $ht; $tot['gain'] += $gain;
        $s = (string) $c['secteur'];
        $parSecteur[$s] = ['secteur' => $s, 'clients' => ($parSecteur[$s]['clients'] ?? 0) + ($cmd ? 1 : 0),
            'ht' => round(($parSecteur[$s]['ht'] ?? 0) + $ht, 2), 'gain' => round(($parSecteur[$s]['gain'] ?? 0) + $gain, 2)];
    }
    usort($lignes, static fn ($a, $b) => $b['gain'] <=> $a['gain']);
    $gainT = round($tot['gain'], 2);
    $plancher = (float) ($gains['plancher'] ?? 0);
    $complement = $plancher > 0 && $gainT < $plancher ? round($plancher - $gainT, 2) : 0.0;
    return ['mois' => $mois, 'clients' => $lignes, 'commandes' => $tot['commandes'], 'ht' => round($tot['ht'], 2), 'gain' => $gainT,
        'plancher' => $plancher, 'complement' => $complement, 'du' => round($gainT + $complement, 2), 'parSecteur' => array_values($parSecteur)];
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
        $histo[] = ['mois' => $m, 'gain' => $x['gain'], 'complement' => $x['complement'], 'du' => $x['du'], 'commandes' => $x['commandes'], 'ht' => $x['ht']];
    }
    return $calc + ['regles' => $reg['gains'], 'historique' => $histo, 'nonRattaches' => $nonRattaches, 'manquants' => $manquants,
        'source' => 'tickets pro du panel (TVA valide) des clients du portail, rattachés à leur compte ; HT estimé au taux de ' . str_replace('.', ',', (string) $reg['gains']['tva']) . ' %'];
}
