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
function pvLignesJour(int $sid, string $jour, int &$cout): ?array
{
    $cle = 'pvL' . $sid . ':' . $jour;
    $cache = setting($cle);
    if (is_array($cache) && isset($cache['e'])) { return $cache['e']; }

    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $jour);
    if (!is_array($liste)) { return null; }
    $tickets = [];
    foreach (analyseListe($liste) as $t) {
        $id = (int) ($t['id'] ?? 0);
        if ($id > 0) { $tickets[$id] = (int) ($t['id_employee'] ?? 0); }
    }
    $emp = [];
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
            if (!isset($emp[$e])) { $emp[$e] = ['l' => 0, 't' => 0]; }
            $emp[$e]['t']++;
            // La règle maison : le nombre de LIGNES du ticket, pas la somme
            // des quantités — la même mesure que la table locale.
            $emp[$e]['l'] += count((array) ($t['products'] ?? []));
        }
    }
    $cout += count($tickets);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode(['quand' => time(), 'e' => $emp], JSON_UNESCAPED_UNICODE)]);
    return $emp;
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
    return $r['ok'] ? ($r['joursFaits'] . ' jour(s) moissonnés, ' . $r['etat']) : ('échec : ' . ($r['motif'] ?? '?'));
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
