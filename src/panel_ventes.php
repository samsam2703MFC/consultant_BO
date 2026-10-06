<?php

declare(strict_types=1);

/**
 * Les VENTES par les endpoints du panel (atelierby.tfbuddy.com) — le
 * remplaçant de la table locale `transaction`, morte à la mi-juillet.
 *
 * Règle actée : plus de copie locale des données sources, seulement les
 * endpoints. Pour un mois entier :
 *   · CA et tickets PAR VENDEUSE : /shops/{id}/statistics/sales/employees/{date},
 *     un appel par magasin et par jour, agrégé ici par personne ;
 *   · la ventilation par créneau (la difficulté mesurée du coefficient) :
 *     /shops/{id}/statistics/sales/hourly-distribution/{date}.
 *
 * Les LIGNES PAR TICKET par vendeuse n'existent dans aucune route (mesuré :
 * /receipt vide, pas de /products par ticket) — elles restent nulles, et un
 * record ne se joue jamais sur un zéro inventé. La route est à réclamer à
 * tfbuddy ; le jour où elle répond, elle se branche ici.
 *
 * Un mois RÉVOLU se calcule une fois et se grave (ceo_app_setting.pvMois…) ;
 * le mois en cours expire au bout d'une heure. Ce cache est un agrégat du
 * cockpit, pas un miroir de la source.
 */

function panelVentesMois(string $m): ?array
{
    if (!function_exists('setting') || !PanelApi::configured()) { return null; }
    $encours = $m >= date('Y-m');
    $cache = setting('pvMois' . $m);
    if (is_array($cache) && isset($cache['d'])
        && (!$encours || (int) ($cache['quand'] ?? 0) > time() - 3600)) {
        // Les lignes de la moissonneuse rejoignent le mois dès qu'il est
        // entièrement moissonné — même un mois gravé avant la moisson.
        return pvLignesFusion($m, $cache['d']);
    }

    [$du, $au] = venteBornes($m);
    $jours = [];
    $fin = min(substr($au, 0, 10), date('Y-m-d', strtotime('+1 day')));
    for ($j = substr($du, 0, 10); $j < $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { $jours[] = $j; }
    if ($jours === []) { return null; }

    try { $shops = Db::rows('SELECT id FROM shops WHERE active = 1'); }
    catch (PDOException $e) { return null; }
    $idDe = [];
    foreach (venteEmployes() as $id => $e2) { $idDe[$e2['nom']] = (int) $id; }

    $chemins = [];
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        foreach ($jours as $j) {
            $chemins['e' . $sid . '_' . $j] = '/shops/' . $sid . '/statistics/sales/employees/' . $j;
            $chemins['h' . $sid . '_' . $j] = '/shops/' . $sid . '/statistics/sales/hourly-distribution/' . $j;
        }
    }
    $res = PanelApi::getParallele($chemins, 8);

    $ventes = []; $sans = ['tickets' => 0, 'ca' => 0.0];
    $caSeg = ['matSem' => 0.0, 'amSem' => 0.0, 'matWe' => 0.0, 'amWe' => 0.0];
    $joursServis = 0;
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        foreach ($jours as $j) {
            $le = $res['e' . $sid . '_' . $j] ?? null;
            if (is_array($le)) {
                $joursServis++;
                foreach (analyseListe($le) as $r) {
                    $t = (int) ($r['transactions_qty'] ?? 0);
                    $ca = (float) ($r['total_receipt_value'] ?? 0);
                    if ($t === 0 && $ca === 0.0) { continue; }
                    $id = $idDe[(string) ($r['display_name'] ?? '')] ?? null;
                    // Une vendeuse que le référentiel ne connaît pas rejoint le
                    // « sans vendeur » : visible à l'écran, jamais perdue.
                    if ($id === null) { $sans['tickets'] += $t; $sans['ca'] += $ca; continue; }
                    if (!isset($ventes[$id])) { $ventes[$id] = ['tickets' => 0, 'ca' => 0.0, 'lignes' => null]; }
                    $ventes[$id]['tickets'] += $t;
                    $ventes[$id]['ca'] += $ca;
                }
            }
            $lh = $res['h' . $sid . '_' . $j] ?? null;
            if (is_array($lh)) {
                $we = (int) date('N', strtotime($j)) >= 6;
                foreach (analyseListe($lh) as $r) {
                    $h = (int) substr((string) ($r['hour_from'] ?? '00'), 0, 2);
                    $cle = $we ? ($h >= VENTE_CRENEAU_BASCULE ? 'amWe' : 'matWe')
                               : ($h >= VENTE_CRENEAU_BASCULE ? 'amSem' : 'matSem');
                    $caSeg[$cle] += (float) ($r['income'] ?? 0);
                }
            }
        }
    }
    // Un mois troué n'est pas un mois : sous 90 % de jours-magasins servis,
    // mieux vaut « indisponible » qu'un classement bâti sur trois jours.
    $attendus = count($jours) * max(1, count($shops));
    if ($ventes === [] || $joursServis < (int) ceil($attendus * 0.9)) { return null; }

    $d = ['ventes' => $ventes, 'sans' => $sans, 'caSeg' => $caSeg, 'source' => 'endpoints'];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['pvMois' . $m, json_encode(['quand' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE)]);
    return pvLignesFusion($m, $d);
}

/**
 * Fusionne les lignes moissonnées dans un mois endpoints — une seule fois :
 * dès que le mois est entièrement moissonné, chaque vendeuse reçoit son
 * compte de lignes, le mois se regrave, et les records reprennent vie.
 */
function pvLignesFusion(string $m, array $d): array
{
    if (!empty($d['lignesFait']) || !function_exists('pvLignesMois')) { return $d; }
    $lig = pvLignesMois($m);
    if ($lig === null) { return $d; }
    foreach ((array) $d['ventes'] as $id => $v) {
        $d['ventes'][$id]['lignes'] = (int) ($lig[(int) $id] ?? 0);
    }
    $d['lignesFait'] = true;
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['pvMois' . $m, json_encode(['quand' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Vente', 'Moisson', 'Les lignes par ticket de ' . $m . ' sont complètes — records recalculables');
    return $d;
}

/* ==========================================================================
   La MOISSONNEUSE des lignes par ticket.

   La route /transactions/{id}?include=products (fournie par la marque) rend
   les lignes d'UN ticket — la liste journalière ne les porte pas. Un mois
   réseau pèse ~15 000 tickets : on moissonne donc par LOTS au cron horaire,
   jour-magasin par jour-magasin, chaque jour gravé une fois pour toujours
   (agrégat par vendeuse — jamais une copie de la source). Quand un mois est
   ENTIÈREMENT moissonné, ses lignes rejoignent panelVentesMois et les
   records « Bats ton record » reprennent vie sur les mois endpoints.
   ========================================================================== */

/** Premier mois à moissonner : là où la table locale s'arrête. */
function pvLignesDebut(): string
{
    return '2026-08';
}

/**
 * Un jour-magasin : lignes et tickets par vendeuse, gravé. Rend null si
 * l'API n'a pas répondu ; le coût (nb de tickets lus) sort par référence.
 */
function pvLignesJour(int $sid, string $jour, int &$cout, bool $force = false): ?array
{
    $cle = 'pvL' . $sid . ':' . $jour;
    $cache = setting($cle);
    if (!$force && is_array($cache) && isset($cache['e'])) { return $cache['e']; }

    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $jour);
    if (!is_array($liste)) { return null; }
    $tickets = [];
    foreach (analyseListe($liste) as $t) {
        $id = (int) ($t['id'] ?? 0);
        if ($id > 0) { $tickets[$id] = (int) ($t['id_employee'] ?? 0); }
    }
    $emp = [];
    // Le concours (tartes & quiches, 06/10/2026) : les produits de la famille, par identifiant.
    $famille = function_exists('concoursMotif') ? pvFamilleIds(concoursMotif()) : [];
    foreach (array_chunk(array_keys($tickets), 40, true) as $lot) {
        $chemins = [];
        foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
        $res = PanelApi::getParallele($chemins, 8);
        foreach ($lot as $id) {
            $t = $res[$id] ?? null;
            // Un ticket muet invalide le jour entier : un compte de lignes
            // partiel paierait des primes fausses.
            if (!is_array($t)) { return null; }
            $e = (int) ($t['id_employee'] ?? $tickets[$id]);
            if (!isset($emp[$e])) { $emp[$e] = ['l' => 0, 't' => 0, 'c' => 0, 'tq' => 0.0]; }
            $emp[$e]['t']++;
            // La règle maison : le nombre de LIGNES du ticket, pas la somme
            // des quantités — la même mesure que la table locale.
            $lignes = (array) ($t['products'] ?? []);
            $n = count($lignes);
            $emp[$e]['l'] += $n;
            // La VENTE CROISÉE (primes de l'app worker, 06/10/2026) : un ticket à deux lignes ou plus.
            if ($n >= 2) { $emp[$e]['c']++; }
            // Le concours : les pièces de la famille vendues sur le ticket.
            foreach ($lignes as $l) {
                if (isset($famille[(int) ($l['id_product'] ?? 0)])) { $emp[$e]['tq'] += (float) ($l['quantity'] ?? 1); }
            }
        }
    }
    foreach ($emp as $e => $x) { $emp[$e]['tq'] = round($x['tq'], 2); }
    $cout += count($tickets);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode(['quand' => time(), 'v' => PV_LIGNES_VERSION, 'e' => $emp], JSON_UNESCAPED_UNICODE)]);
    return $emp;
}

/** La version de la moisson : 1 = lignes et ventes croisées, 2 = + les pièces du concours. */
const PV_LIGNES_VERSION = 2;

/** La version d'un jour moissonné : celle gravée, sinon 1 s'il porte les ventes croisées, sinon 0. */
function pvLignesVersion(array $entree): int
{
    if (isset($entree['v'])) { return (int) $entree['v']; }
    return pvLignesAvecC((array) ($entree['e'] ?? [])) ? 1 : 0;
}

/**
 * Les produits d'une famille, par identifiant du panel : ceux dont la catégorie ou le nom répond au
 * motif (« tarte|quiche »). Lu au catalogue, gravé un jour.
 */
function pvFamilleIds(string $motif): array
{
    static $memo = [];
    $motif = trim($motif);
    if ($motif === '') { return []; }
    if (isset($memo[$motif])) { return $memo[$motif]; }
    $cle = 'pvFamille:' . substr(md5($motif), 0, 12);
    $c = setting($cle);
    if (is_array($c) && isset($c['ids']) && (int) ($c['quand'] ?? 0) > time() - 86400) { return $memo[$motif] = array_fill_keys(array_map('intval', (array) $c['ids']), true); }
    $ids = [];
    if (function_exists('ep_prod_catalogue')) {
        try {
            foreach (ep_prod_catalogue() as $pr) {
                $pid = $pr['pwaId'] ?? null;
                if ($pid === null) { continue; }
                $texte = (string) ($pr['categorie'] ?? '') . ' ' . (string) ($pr['nom'] ?? '');
                if (@preg_match('/' . $motif . '/iu', $texte) === 1) { $ids[] = (int) $pid; }
            }
        } catch (Throwable $e) { /* catalogue muet : rien ne compte aujourd'hui, demain peut-être */ }
    }
    if ($ids !== []) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['quand' => time(), 'ids' => $ids])]);
    }
    return $memo[$motif] = array_fill_keys($ids, true);
}

/**
 * Une passe de moisson, bornée en tickets. Avance chronologiquement du
 * premier mois endpoints jusqu'à hier, et dit où elle en est.
 */
function pvLignesMoisson(int $budget = 600): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }

    $cout = 0; $faits = 0; $restants = 0; $muets = 0;
    for ($m = pvLignesDebut(); $m <= date('Y-m'); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
        for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            foreach ($shops as $sid) {
                if (is_array(setting('pvL' . $sid . ':' . $j))) { continue; }
                if ($cout >= $budget) { $restants++; continue; }
                $r = pvLignesJour($sid, $j, $cout);
                if ($r === null) { $muets++; } else { $faits++; }
            }
        }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'tickets' => $cout,
        'joursRestants' => $restants, 'muets' => $muets,
        'etat' => $restants === 0 ? 'à jour' : $restants . ' jour(s)-magasin restants'];
}

/** Le battement horaire de la moisson, accroché au cron des rapports. */
function pvLignesCron(): string
{
    $r = pvLignesMoisson(600);
    if (!$r['ok']) { return 'échec : ' . ($r['motif'] ?? '?'); }
    $txt = $r['joursFaits'] . ' jour(s) moissonnés, ' . $r['etat'];
    // La moisson à jour : les jours moissonnés avant le compte des ventes croisées se refont,
    // du plus récent au plus ancien, avec leur propre budget — à la suite de la moisson, ils
    // attendraient des jours (le mois en cours d'abord, puis les mois d'avant).
    if ($r['joursRestants'] === 0) {
        $c = pvCroiseesComplement(450);
        if ($c['joursFaits'] > 0 || $c['joursRestants'] > 0) { $txt .= ' · ventes croisées : ' . $c['joursFaits'] . ' jour(s) recomptés, ' . $c['etat']; }
    }
    // Les ventes par vendeuse jour par jour (les semaines de l'app worker), 48 jours-magasins par passe.
    $v = pvVentesJoursMoisson(48);
    if ($v['joursFaits'] > 0 || $v['joursRestants'] > 0) { $txt .= ' · ventes par jour : ' . $v['joursFaits'] . ' jour(s), ' . ($v['joursRestants'] === 0 ? 'à jour' : $v['joursRestants'] . ' restant(s)'); }
    return $txt;
}

/**
 * Les jours-magasins moissonnés dans une version plus ancienne (sans les ventes croisées, puis sans
 * les pièces du concours) se relisent, du plus récent au plus ancien, dans la limite d'un budget de
 * tickets : le mois en cours d'abord, puis les mois d'avant, sans jamais bloquer la moisson courante.
 */
function pvCroiseesComplement(int $budget): array
{
    if ($budget <= 0 || !PanelApi::configured()) { return ['joursFaits' => 0, 'joursRestants' => 0, 'etat' => 'sans budget']; }
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['joursFaits' => 0, 'joursRestants' => 0, 'etat' => 'magasins illisibles']; }
    $cout = 0; $faits = 0; $restants = 0;
    $debut = pvLignesDebut() . '-01';
    for ($j = date('Y-m-d', strtotime('-1 day')); $j >= $debut; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        foreach ($shops as $sid) {
            $c = setting('pvL' . $sid . ':' . $j);
            if (!is_array($c) || !isset($c['e'])) { continue; }          // pas encore moissonné : la moisson s'en charge
            if (pvLignesVersion($c) >= PV_LIGNES_VERSION) { continue; }  // déjà à jour
            if ($cout >= $budget) { $restants++; continue; }
            if (pvLignesJour($sid, $j, $cout, true) !== null) { $faits++; } else { $restants++; }
        }
    }
    return ['joursFaits' => $faits, 'joursRestants' => $restants, 'tickets' => $cout,
        'etat' => $restants === 0 ? 'à jour' : $restants . ' jour(s)-magasin à recompter'];
}

/** Un jour moissonné porte-t-il le compte des ventes croisées ? (Un jour sans ticket : oui.) */
function pvLignesAvecC(array $e): bool
{
    foreach ($e as $x) { return is_array($x) && array_key_exists('c', $x); }
    return true;
}

/**
 * Les ventes croisées d'un mois, par personne, depuis les jours moissonnés : tickets, ventes
 * croisées (tickets à deux lignes ou plus), lignes. Le mois en cours s'arrête à hier. Null tant
 * qu'aucun jour n'est moissonné. « complet » : tous les jours du mois sont là, avec leur compte.
 */
function pvCroiseesMois(string $m): ?array
{
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return null; }
    $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
    $out = []; $jours = 0; $manquants = 0; $sansC = 0; $sansTq = 0; $joursVus = []; $joursTqShop = [];
    for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        foreach ($shops as $sid) {
            $c = setting('pvL' . $sid . ':' . $j);
            if (!is_array($c) || !isset($c['e'])) { $manquants++; continue; }
            $e = (array) $c['e'];
            $version = pvLignesVersion($c);
            $avecC = $version >= 1;
            if (!$avecC) { $sansC++; }
            if ($version < 2) { $sansTq++; } else { $joursTqShop[$sid] = ($joursTqShop[$sid] ?? 0) + 1; }
            $joursVus[$j] = true;
            foreach ($e as $id => $x) {
                $id = (int) $id;
                if (!isset($out[$id])) { $out[$id] = ['t' => 0, 'l' => 0, 'c' => 0, 'tc' => 0, 'tq' => 0.0, 'tqT' => 0]; }
                $out[$id]['t'] += (int) ($x['t'] ?? 0);
                $out[$id]['l'] += (int) ($x['l'] ?? 0);
                // Le taux se mesure sur les seuls jours comptés : tc = les tickets de ces jours-là.
                if ($avecC) { $out[$id]['c'] += (int) ($x['c'] ?? 0); $out[$id]['tc'] += (int) ($x['t'] ?? 0); }
                // Les pièces du concours, sur les jours qui les comptent.
                if ($version >= 2) { $out[$id]['tq'] += (float) ($x['tq'] ?? 0); $out[$id]['tqT'] += (int) ($x['t'] ?? 0); }
            }
        }
    }
    $jours = count($joursVus);
    if ($jours === 0) { return null; }
    // Sans aucun jour compté, le « c » (ou le « tq ») d'une personne n'existe pas encore.
    foreach ($out as $id => $x) {
        if ($x['tc'] === 0 && $sansC > 0) { unset($out[$id]['c']); }
        elseif ($x['tc'] > 0 && $x['tc'] < $x['t']) { $out[$id]['t'] = $x['tc']; }   // le taux sur les jours comptés
        if ($x['tqT'] === 0 && $sansTq > 0) { unset($out[$id]['tq']); } else { $out[$id]['tq'] = round($x['tq'], 2); }
        unset($out[$id]['tc']);
    }
    // joursTqShop : par magasin, les jours dont les pièces du concours sont comptées (version 2).
    return ['e' => $out, 'jours' => $jours, 'manquants' => $manquants, 'sansC' => $sansC, 'sansTq' => $sansTq, 'joursTqShop' => $joursTqShop,
        'complet' => $manquants === 0 && $sansC === 0, 'tqComplet' => $manquants === 0 && $sansTq === 0];
}

/** Le taux de ventes croisées d'un mois, magasin par magasin (sur les jours comptés). */
function pvCroiseesMoisMagasins(string $m): array
{
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return []; }
    $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
    $out = [];
    foreach ($shops as $sid) {
        $t = 0; $c = 0; $jours = 0;
        for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $x = setting('pvL' . $sid . ':' . $j);
            if (!is_array($x) || !isset($x['e']) || !pvLignesAvecC((array) $x['e'])) { continue; }
            $jours++;
            foreach ((array) $x['e'] as $y) { $t += (int) ($y['t'] ?? 0); $c += (int) ($y['c'] ?? 0); }
        }
        $out[(string) $sid] = ['tickets' => $t, 'croisees' => $c, 'jours' => $jours, 'taux' => $t > 0 ? round(100 * $c / $t, 1) : null];
    }
    return $out;
}

/**
 * Les ventes croisées d'une personne, semaine par semaine (lundi à dimanche), sur les n dernières
 * semaines, depuis les jours moissonnés de son magasin.
 */
function pvCroiseesSemaines(int $emp, int $n, int $sid): array
{
    $out = [];
    $lundi = date('Y-m-d', strtotime('monday this week'));
    for ($i = $n - 1; $i >= 0; $i--) {
        $du = date('Y-m-d', strtotime($lundi . ' -' . (7 * $i) . ' days'));
        $t = 0; $c = 0; $jours = 0;
        for ($k = 0; $k < 7; $k++) {
            $j = date('Y-m-d', strtotime($du . ' +' . $k . ' days'));
            if ($j >= date('Y-m-d')) { break; }
            $x = setting('pvL' . $sid . ':' . $j);
            if (!is_array($x) || !isset($x['e']) || !pvLignesAvecC((array) $x['e'])) { continue; }
            $jours++;
            $y = $x['e'][$emp] ?? ($x['e'][(string) $emp] ?? null);
            if (is_array($y)) { $t += (int) ($y['t'] ?? 0); $c += (int) ($y['c'] ?? 0); }
        }
        $out[] = ['lib' => 'S' . (int) date('W', strtotime($du)), 'du' => $du, 'tickets' => $t, 'croisees' => $c, 'jours' => $jours,
            'taux' => $t > 0 ? round(100 * $c / $t, 1) : null];
    }
    return $out;
}

/**
 * Les n dernières semaines (lundi à dimanche, la semaine en cours comprise), pour TOUT le réseau :
 * par semaine, par personne, les tickets, les ventes croisées et les pièces du concours, depuis
 * les jours moissonnés. C'est la matière des positions semaine par semaine. Gravé une heure.
 */
function pvSemainesReseau(int $n): array
{
    $cle = 'pvSemReseau' . $n;
    $c = setting($cle);
    if (is_array($c) && isset($c['s']) && (int) ($c['quand'] ?? 0) > time() - 3600) { return (array) $c['s']; }
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return []; }
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $du = date('Y-m-d', strtotime($lundi . ' -' . (7 * $i) . ' days'));
        $sem = ['lib' => 'S' . (int) date('W', strtotime($du)), 'du' => $du, 'e' => [], 'jours' => 0, 'joursTq' => 0];
        for ($k = 0; $k < 7; $k++) {
            $j = date('Y-m-d', strtotime($du . ' +' . $k . ' days'));
            if ($j >= date('Y-m-d')) { break; }
            $vu = false;
            foreach ($shops as $sid) {
                $x = setting('pvL' . $sid . ':' . $j);
                if (!is_array($x) || !isset($x['e'])) { continue; }
                $version = pvLignesVersion($x);
                if ($version < 1) { continue; }
                $vu = true;
                if ($version >= 2) { $sem['joursTq']++; }
                foreach ((array) $x['e'] as $id => $y) {
                    $id = (int) $id;
                    if (!isset($sem['e'][$id])) { $sem['e'][$id] = ['t' => 0, 'c' => 0, 'tq' => 0.0, 'shop' => (string) $sid]; }
                    $sem['e'][$id]['t'] += (int) ($y['t'] ?? 0);
                    $sem['e'][$id]['c'] += (int) ($y['c'] ?? 0);
                    if ($version >= 2) { $sem['e'][$id]['tq'] += (float) ($y['tq'] ?? 0); }
                }
            }
            if ($vu) { $sem['jours']++; }
        }
        $out[] = $sem;
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['quand' => time(), 's' => $out], JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/**
 * Les ventes d'un jour, par vendeuse, pour un magasin (/statistics/sales/employees/{jour}) :
 * tickets et CA, par identifiant du référentiel. Un jour clos se grave une fois ; aujourd'hui se
 * relit toutes les dix minutes. Null si le panel ne répond pas.
 */
function pvVentesJour(int $sid, string $jour): ?array
{
    $cle = 'pvE' . $sid . ':' . $jour;
    $c = setting($cle);
    if (is_array($c) && isset($c['e']) && ($jour < date('Y-m-d') || (int) ($c['quand'] ?? 0) > time() - 600)) { return (array) $c['e']; }
    if (!PanelApi::configured()) { return null; }
    $l = PanelApi::get('/shops/' . $sid . '/statistics/sales/employees/' . $jour);
    if (!is_array($l)) { return null; }
    $idDe = [];
    foreach (venteEmployes() as $id => $e2) { $idDe[$e2['nom']] = (int) $id; }
    $out = [];
    foreach (analyseListe($l) as $r) {
        $id = $idDe[(string) ($r['display_name'] ?? '')] ?? null;
        if ($id === null) { continue; }
        $out[$id] = ['t' => (int) ($r['transactions_qty'] ?? 0), 'ca' => round((float) ($r['total_receipt_value'] ?? 0), 2)];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['quand' => time(), 'e' => $out], JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/** Les jours d'un magasin entre deux dates qui n'ont pas encore leurs ventes par vendeuse. */
function pvVentesJoursManquants(int $sid, string $du, string $au): array
{
    $out = [];
    for ($j = $du; $j <= $au && $j < date('Y-m-d'); $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        $c = setting('pvE' . $sid . ':' . $j);
        if (!is_array($c) || !isset($c['e'])) { $out[] = $j; }
    }
    return $out;
}

/** Une passe du cron : les ventes par vendeuse des 90 derniers jours, tous magasins, bornée en jours-magasins. */
function pvVentesJoursMoisson(int $budget): array
{
    if ($budget <= 0 || !PanelApi::configured()) { return ['joursFaits' => 0, 'joursRestants' => 0]; }
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['joursFaits' => 0, 'joursRestants' => 0]; }
    $faits = 0; $restants = 0;
    $du = date('Y-m-d', strtotime('-90 days'));
    foreach ($shops as $sid) {
        foreach (array_reverse(pvVentesJoursManquants($sid, $du, date('Y-m-d', strtotime('-1 day')))) as $j) {
            if ($faits >= $budget) { $restants++; continue; }
            if (pvVentesJour($sid, $j) !== null) { $faits++; } else { $restants++; }
        }
    }
    return ['joursFaits' => $faits, 'joursRestants' => $restants];
}

/**
 * Le compteur du jour d'une personne : ses tickets d'aujourd'hui dans son magasin, et combien
 * portent deux lignes ou plus — seulement SES tickets (la liste du jour dit le vendeur de chacun),
 * relus toutes les dix minutes. Null si le panel ne répond pas.
 */
function pvLignesJourPersonne(int $sid, int $emp): ?array
{
    $cle = 'pvLJ' . $sid . ':' . $emp;
    $c = setting($cle);
    if (is_array($c) && isset($c['t']) && (string) ($c['jour'] ?? '') === date('Y-m-d') && (int) ($c['quand'] ?? 0) > time() - 600) { return $c; }
    if (!PanelApi::configured()) { return null; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . date('Y-m-d'));
    if (!is_array($liste)) { return null; }
    $ids = [];
    foreach (analyseListe($liste) as $t) {
        if ((int) ($t['id'] ?? 0) > 0 && (int) ($t['id_employee'] ?? 0) === $emp) { $ids[] = (int) $t['id']; }
    }
    $l = 0; $cc = 0;
    foreach (array_chunk($ids, 40) as $lot) {
        $chemins = [];
        foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
        $res = PanelApi::getParallele($chemins, 8);
        foreach ($lot as $id) {
            $t = $res[$id] ?? null;
            if (!is_array($t)) { return null; }
            $n = count((array) ($t['products'] ?? []));
            $l += $n;
            if ($n >= 2) { $cc++; }
        }
    }
    $out = ['t' => count($ids), 'c' => $cc, 'l' => $l, 'jour' => date('Y-m-d'), 'quand' => time()];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out)]);
    return $out;
}

/** POST /ventes/lignes-moisson — forcer une passe plus large, voir l'état. */
function wr_pv_lignes_moisson(): array
{
    return pvLignesMoisson((int) (body()['budget'] ?? 2000));
}

/**
 * Les lignes d'un mois ENTIÈREMENT moissonné, par vendeuse — null tant
 * qu'un seul jour-magasin manque : un record ne se joue pas sur un mois
 * troué.
 */
function pvLignesMois(string $m): ?array
{
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return null; }
    $fin = date('Y-m-t', strtotime($m . '-01'));
    if ($fin >= date('Y-m-d')) { return null; }   // mois pas fini : pas de lignes
    $out = [];
    for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        foreach ($shops as $sid) {
            $c = setting('pvL' . $sid . ':' . $j);
            if (!is_array($c) || !isset($c['e'])) { return null; }
            foreach ((array) $c['e'] as $e => $x) {
                $out[(int) $e] = ($out[(int) $e] ?? 0) + (int) ($x['l'] ?? 0);
            }
        }
    }
    return $out;
}

/**
 * Les lignes et tickets d'un mois moissonné, PAR MAGASIN — null tant que le
 * mois n'est pas clos et entièrement moissonné.
 */
function pvLignesMoisShops(string $m): ?array
{
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return null; }
    $fin = date('Y-m-t', strtotime($m . '-01'));
    if ($fin >= date('Y-m-d')) { return null; }
    $out = [];
    for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        foreach ($shops as $sid) {
            $c = setting('pvL' . $sid . ':' . $j);
            if (!is_array($c) || !isset($c['e'])) { return null; }
            if (!isset($out[$sid])) { $out[$sid] = ['l' => 0, 't' => 0]; }
            foreach ((array) $c['e'] as $x) {
                $out[$sid]['l'] += (int) ($x['l'] ?? 0);
                $out[$sid]['t'] += (int) ($x['t'] ?? 0);
            }
        }
    }
    return $out;
}

/**
 * Les KPIs d'un magasin sur un mois (CA, tickets, panier) par l'endpoint —
 * gravés une fois le mois clos, rafraîchis à l'heure pour le mois en cours.
 */
function pvKpisMois(int $sid, string $m): ?array
{
    $cle = 'pvCa' . $sid . ':' . $m;
    $cache = setting($cle);
    $clos = date('Y-m-t', strtotime($m . '-01')) < date('Y-m-d');
    if (is_array($cache) && isset($cache['ca'], $cache['tickets'])
        && ($clos || (int) ($cache['quand'] ?? 0) > time() - 3600)) {
        return ['ca' => (float) $cache['ca'], 'tickets' => (int) $cache['tickets'],
            'panier' => isset($cache['panier']) ? (float) $cache['panier'] : null];
    }
    $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d'));
    $k = PanelApi::get('/shops/' . $sid . '/statistics/sales/kpis?' . http_build_query(
        ['date_from' => $m . '-01', 'date_to' => $fin]));
    if (!is_array($k) || !isset($k['ca'])) { return null; }
    $d = ['ca' => (float) $k['ca'], 'tickets' => (int) ($k['tickets'] ?? 0),
        'panier' => isset($k['avg_basket']) ? (float) $k['avg_basket'] : null];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode(['quand' => time()] + $d)]);
    return $d;
}

/** Le CA seul — l'habillage historique de pvKpisMois. */
function pvCaMois(int $sid, string $m): ?float
{
    $k = pvKpisMois($sid, $m);
    return $k !== null ? $k['ca'] : null;
}

/**
 * La moisson des CROISEMENTS d'un mois : l'attache Flip & Flap → boisson par
 * vendeuse, les paires de produits, le prix moyen d'une boisson encaissée —
 * tout depuis /transactions/{id}?include=products, par lots bornés, jour-
 * magasin par jour-magasin, accumulé dans ceo_app_setting.pvCrois{mois}.
 * Un jour au ticket muet reste à refaire : pas de croisement à moitié.
 */
function pvCroisMoisson(string $m, int $budget = 450): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    $ff = []; $boi = [];
    foreach (ep_prod_catalogue() as $pr) {
        $pid = $pr['pwaId'] ?? null;
        if ($pid === null) { continue; }
        if (stripos((string) ($pr['categorie'] ?? ''), 'flip') !== false) { $ff[(int) $pid] = true; }
        if ((string) ($pr['groupe'] ?? '') === 'Boissons') { $boi[(int) $pid] = true; }
    }
    if ($ff === [] || $boi === []) { return ['ok' => false, 'motif' => 'catalogue sans Flip & Flap ou groupe Boissons']; }

    $etat = setting('pvCrois' . $m);
    $etat = is_array($etat) ? $etat : [];
    // emp/paires/caBoi/qBoi se rangent PAR MAGASIN — un croisement ne se lit
    // jamais mélangé entre magasins. Un ancien état à plat (versions
    // précédentes, emp[eid]=['f','fb'] directement) est irrécupérable :
    // on repart de zéro plutôt que de le lire de travers.
    if (isset($etat['emp']) && is_array($etat['emp']) && $etat['emp'] !== []
        && is_array($u = reset($etat['emp'])) && isset($u['f'])) {
        $etat = [];
    }
    $etat += ['jours' => [], 'emp' => [], 'paires' => [], 'caBoi' => [], 'qBoi' => []];
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }

    $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
    $cout = 0; $faits = 0; $restants = 0;
    for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        foreach ($shops as $sid) {
            $k = $sid . ':' . $j;
            if (!empty($etat['jours'][$k])) { continue; }
            if ($cout >= $budget) { $restants++; continue; }
            $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
            if (!is_array($liste)) { $restants++; continue; }
            $ids = [];
            foreach (analyseListe($liste) as $t) { if ((int) ($t['id'] ?? 0) > 0) { $ids[] = (int) $t['id']; } }
            $jourOk = true;
            foreach (array_chunk($ids, 40) as $lot) {
                $chemins = [];
                foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
                $res = PanelApi::getParallele($chemins, 8);
                foreach ($lot as $id) {
                    $t = $res[$id] ?? null;
                    if (!is_array($t)) { $jourOk = false; break 2; }
                    $emp = (int) ($t['id_employee'] ?? 0);
                    $noms = []; $aFF = false; $aBoi = false;
                    foreach ((array) ($t['products'] ?? []) as $l) {
                        $pid = (int) ($l['id_product'] ?? 0);
                        if (isset($ff[$pid])) { $aFF = true; }
                        if (isset($boi[$pid])) {
                            $aBoi = true;
                            $etat['caBoi'][$sid] = ($etat['caBoi'][$sid] ?? 0.0) + (float) ($l['total_gross_value_after_discount'] ?? 0);
                            $etat['qBoi'][$sid] = ($etat['qBoi'][$sid] ?? 0.0) + (float) ($l['quantity'] ?? 0);
                        }
                        $n2 = trim((string) ($l['product_display_name'] ?? ($l['product_name'] ?? '')));
                        if ($n2 !== '') { $noms[$n2] = true; }
                    }
                    if ($aFF) {
                        if (!isset($etat['emp'][$sid][$emp])) { $etat['emp'][$sid][$emp] = ['f' => 0, 'fb' => 0]; }
                        $etat['emp'][$sid][$emp]['f']++;
                        if ($aBoi) { $etat['emp'][$sid][$emp]['fb']++; }
                    }
                    $noms = array_keys($noms);
                    sort($noms);
                    $nn = count($noms);
                    if ($nn >= 2 && $nn <= 8) {
                        for ($a2 = 0; $a2 < $nn; $a2++) {
                            for ($b2 = $a2 + 1; $b2 < $nn; $b2++) {
                                $p2 = $noms[$a2] . '|' . $noms[$b2];
                                $etat['paires'][$sid][$p2] = ($etat['paires'][$sid][$p2] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
            if ($jourOk) { $etat['jours'][$k] = 1; $faits++; $cout += count($ids); }
            else { $restants++; }
            // Les paires se taillent au passage : garder les 400 plus jouées, par magasin.
            if (isset($etat['paires'][$sid]) && count($etat['paires'][$sid]) > 900) {
                arsort($etat['paires'][$sid]);
                $etat['paires'][$sid] = array_slice($etat['paires'][$sid], 0, 400, true);
            }
        }
    }
    $etat['prixBoisson'] = [];
    foreach ($shops as $sid2) {
        $q2 = $etat['qBoi'][$sid2] ?? 0.0;
        $etat['prixBoisson'][$sid2] = $q2 > 0 ? round(($etat['caBoi'][$sid2] ?? 0.0) / $q2, 2) : 0.0;
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['pvCrois' . $m, json_encode($etat, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'joursFaits' => $faits, 'joursRestants' => $restants, 'tickets' => $cout,
        'etat' => $restants === 0 ? 'à jour' : $restants . ' jour(s)-magasin restants'];
}

/** POST /ventes/crois-moisson {m, budget} — une passe de moisson des croisements. */
function wr_pv_crois_moisson(): array
{
    $b = body();
    $m = (string) ($b['m'] ?? date('Y-m', strtotime('first day of last month')));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m', strtotime('first day of last month')); }
    return pvCroisMoisson($m, (int) ($b['budget'] ?? 450));
}
