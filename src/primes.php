<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les primes, telles que l'app worker les lit (demande du 06/10/2026, maquette B).
 *
 * Deux primes qui s'additionnent, chaque mois, pour chaque personne de vente :
 *
 *  1. LA PRIME INDIVIDUELLE, à la vente — trois briques :
 *     · les VENTES CROISÉES (nouveau) : un ticket à deux lignes ou plus (deux produits différents ;
 *       trois croissants font une ligne). Le nombre se lit tous les jours dans l'app ; la prime se
 *       joue sur le TAUX du mois (ventes croisées ÷ tickets), face à la cible du magasin, par
 *       paliers (cible, + 5 points, + 10 points). Le taux et non le nombre brut : un gros horaire
 *       ne vaut pas une meilleure vente, la même raison qui classe au CA par heure ;
 *     · BATS TON RECORD (existant, src/ventes.php) : lignes par ticket face à son record 12 mois ;
 *     · LA MEILLEURE VENDEUSE (existant) : la première du magasin, la première du réseau.
 *
 *  2. LA PRIME MAGASIN, sur l'objectif du mois (nouveau) : l'objectif est celui du budget, déjà
 *     dans le cockpit ; le palier d'atteinte franchi fin de mois donne des EUROS PAR HEURE PRESTÉE
 *     à chaque personne du magasin (97 % → 0,50 €/h, 100 % → 1 €/h, 105 % → 1,50 €/h,
 *     110 % → 2 €/h, réglables). Chacune comprend sa prime à la minute, et l'enveloppe suit la
 *     taille de l'équipe. Les heures sont celles du planning du panel ; en dessous d'un minimum
 *     dans le mois, pas de part.
 *
 * Le mois en cours se montre en PROJECTION (au rythme des jours clos, semaine et week-end à part) ;
 * seul le mois clos se paie, par le geste « Enregistrer les primes » qui existe déjà. L'app ne lit
 * jamais un autre nom que celui de la personne connectée : les collègues sont des heures.
 *
 * Les lectures passent par les caches du cockpit (pvMois, pvL, pvCa…) et un cache de 15 minutes
 * par personne et par mois : l'app s'ouvre souvent, le calcul est lourd.
 */

const PRIMES_CACHE_MOI = 900;      // 15 min : la fiche d'une personne pour le mois en cours
const PRIMES_CACHE_CLOS = 86400;   // un jour : un mois clos ne bouge que si le CEO l'enregistre
const PRIMES_CACHE_JETON = 600;    // 10 min : un jeton d'employé vérifié chez le panel
const PRIMES_CACHE_JOUR = 600;     // 10 min : le compteur du jour

/* ======================================================================
   Les réglages
   ====================================================================== */

/** La prime magasin : les paliers d'atteinte et leurs euros par heure, le minimum d'heures. */
function primeMagasinReglages(): array
{
    $def = ['paliers' => [
        ['pct' => 97, 'eh' => 0.5, 'lib' => 'Encouragement'],
        ['pct' => 100, 'eh' => 1.0, 'lib' => 'Objectif'],
        ['pct' => 105, 'eh' => 1.5, 'lib' => 'Dépassé'],
        ['pct' => 110, 'eh' => 2.0, 'lib' => 'Record'],
    ], 'heuresMin' => 20];
    $c = setting('ventePrimeMagasin');
    if (!is_array($c)) { return $def; }
    $p = primeMagasinPaliersValides($c['paliers'] ?? null);
    return ['paliers' => $p !== [] ? $p : $def['paliers'],
        'heuresMin' => isset($c['heuresMin']) && is_numeric($c['heuresMin']) ? max(0, (int) $c['heuresMin']) : 20];
}

/** Des paliers propres : atteinte entre 50 et 200 %, euros par heure de 0 à 20, triés, sans doublon. */
function primeMagasinPaliersValides($p): array
{
    if (!is_array($p)) { return []; }
    $out = [];
    foreach ($p as $x) {
        if (!is_array($x) || !isset($x['pct'], $x['eh']) || !is_numeric($x['pct']) || !is_numeric($x['eh'])) { continue; }
        $pct = (int) round((float) $x['pct']); $eh = round((float) $x['eh'], 2);
        if ($pct < 50 || $pct > 200 || $eh < 0 || $eh > 20) { continue; }
        $out[$pct] = ['pct' => $pct, 'eh' => $eh, 'lib' => mb_substr(trim((string) ($x['lib'] ?? '')), 0, 30)];
    }
    ksort($out);
    return array_values($out);
}

/** POST /ventes/prime-magasin {paliers: [{pct, eh, lib}], heuresMin} — les réglages de la prime magasin. */
function wr_prime_magasin(): array
{
    $b = body();
    $cur = primeMagasinReglages();
    if (array_key_exists('paliers', $b)) {
        $p = primeMagasinPaliersValides($b['paliers']);
        if ($p === []) { http_response_code(422); return ['error' => 'au moins un palier valide (atteinte 50 à 200 %, 0 à 20 € par heure)']; }
        $cur['paliers'] = $p;
    }
    if (isset($b['heuresMin']) && is_numeric($b['heuresMin'])) { $cur['heuresMin'] = max(0, min(200, (int) $b['heuresMin'])); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['ventePrimeMagasin', json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', 'Prime magasin', 'Paliers : ' . implode(', ', array_map(
        static fn ($x) => $x['pct'] . ' % → ' . number_format($x['eh'], 2, ',', '') . ' €/h', $cur['paliers'])) . ' · ' . $cur['heuresMin'] . ' h minimum');
    return ['ok' => true, 'reglages' => $cur];
}

/** Les ventes croisées : la cible (réseau, et par magasin), les paliers au-dessus, le minimum de tickets. */
function croiseesReglages(): array
{
    $def = ['cibleDefaut' => 50.0, 'cibles' => [], 'paliers' => [['plus' => 0, 'm' => 40], ['plus' => 5, 'm' => 70], ['plus' => 10, 'm' => 100]], 'minTickets' => 30];
    $c = setting('venteCroisees');
    if (!is_array($c)) { return $def; }
    $out = $def;
    if (isset($c['cibleDefaut']) && is_numeric($c['cibleDefaut'])) { $out['cibleDefaut'] = max(0.0, min(100.0, round((float) $c['cibleDefaut'], 1))); }
    foreach ((array) ($c['cibles'] ?? []) as $sid => $v) {
        if (is_numeric($v) && (float) $v > 0) { $out['cibles'][(string) $sid] = max(0.0, min(100.0, round((float) $v, 1))); }
    }
    $p = croiseesPaliersValides($c['paliers'] ?? null);
    if ($p !== []) { $out['paliers'] = $p; }
    if (isset($c['minTickets']) && is_numeric($c['minTickets'])) { $out['minTickets'] = max(0, (int) $c['minTickets']); }
    return $out;
}

/** Des paliers propres : des points au-dessus de la cible (0 à 50), un montant (0 à 1 000 €), triés. */
function croiseesPaliersValides($p): array
{
    if (!is_array($p)) { return []; }
    $out = [];
    foreach ($p as $x) {
        if (!is_array($x) || !isset($x['m']) || !is_numeric($x['m'])) { continue; }
        $plus = isset($x['plus']) && is_numeric($x['plus']) ? round((float) $x['plus'], 1) : 0.0;
        $m = (int) round((float) $x['m']);
        if ($plus < 0 || $plus > 50 || $m < 0 || $m > 1000) { continue; }
        $out[(string) $plus] = ['plus' => $plus, 'm' => $m];
    }
    uasort($out, static fn ($a, $b) => $a['plus'] <=> $b['plus']);
    return array_values($out);
}

/** POST /ventes/croisees {cibleDefaut, cibles: {shop: pct}, paliers: [{plus, m}], minTickets}. */
function wr_croisees(): array
{
    $b = body();
    $cur = croiseesReglages();
    if (isset($b['cibleDefaut']) && is_numeric($b['cibleDefaut'])) { $cur['cibleDefaut'] = max(0.0, min(100.0, round((float) $b['cibleDefaut'], 1))); }
    if (array_key_exists('cibles', $b) && is_array($b['cibles'])) {
        // Une cible vide ou nulle retire le magasin : il retombe sur la cible du réseau.
        foreach ($b['cibles'] as $sid => $v) {
            if ($v === null || $v === '' || !is_numeric($v) || (float) $v <= 0) { unset($cur['cibles'][(string) $sid]); }
            else { $cur['cibles'][(string) $sid] = max(0.0, min(100.0, round((float) $v, 1))); }
        }
    }
    if (array_key_exists('paliers', $b)) {
        $p = croiseesPaliersValides($b['paliers']);
        if ($p === []) { http_response_code(422); return ['error' => 'au moins un palier valide (0 à 50 points au-dessus de la cible, 0 à 1 000 €)']; }
        $cur['paliers'] = $p;
    }
    if (isset($b['minTickets']) && is_numeric($b['minTickets'])) { $cur['minTickets'] = max(0, min(1000, (int) $b['minTickets'])); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['venteCroisees', json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', 'Ventes croisées', 'Cible réseau ' . $cur['cibleDefaut'] . ' %, ' . count($cur['cibles']) . ' cible(s) de magasin, paliers '
        . implode(', ', array_map(static fn ($x) => '+' . $x['plus'] . ' pt → ' . $x['m'] . ' €', $cur['paliers'])) . ', ' . $cur['minTickets'] . ' tickets minimum');
    return ['ok' => true, 'reglages' => $cur];
}

/** La cible d'un magasin : la sienne, sinon celle du réseau. */
function croiseesCible(array $cfg, string $shop): float
{
    return (float) ($cfg['cibles'][$shop] ?? $cfg['cibleDefaut']);
}

/**
 * La prime ventes croisées d'une personne : le plus haut palier franchi, et le prochain à viser.
 *
 * @return array{montant:int,palier:?float,prochain:?array{taux:float,montant:int}}
 */
function croiseesPrime(?float $taux, float $cible, array $cfg, int $tickets): array
{
    $out = ['montant' => 0, 'palier' => null, 'prochain' => null];
    $assez = $tickets >= (int) $cfg['minTickets'];
    foreach ($cfg['paliers'] as $p) {
        $seuil = round($cible + $p['plus'], 1);
        if ($assez && $taux !== null && $taux + 1e-9 >= $seuil) {
            if ($p['m'] >= $out['montant']) { $out['montant'] = $p['m']; $out['palier'] = $seuil; }
        } elseif ($out['prochain'] === null) {
            $out['prochain'] = ['taux' => $seuil, 'montant' => $p['m']];
        }
    }
    return $out;
}

/** Le plus haut palier de la prime magasin atteint à cette atteinte (en %), sinon null. */
function primeMagasinPalier(?float $pct, array $paliers): ?array
{
    if ($pct === null) { return null; }
    $out = null;
    foreach ($paliers as $p) { if ($pct + 1e-9 >= $p['pct']) { $out = $p; } }
    return $out;
}

/* ======================================================================
   Le magasin sur le mois : objectif, encaissé, projection, paliers
   ====================================================================== */

/** Les objectifs du mois, magasin par magasin : le budget, sinon le CA théorique (comme le dashboard). */
function primesBudgets(string $m): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE year = ? AND month = ?',
            [(int) substr($m, 0, 4), (int) substr($m, 5, 2)]) as $b) {
            if ($b['revenue_budget'] !== null && (float) $b['revenue_budget'] > 0) { $out[(string) $b['shop_id']] = ['montant' => (float) $b['revenue_budget'], 'source' => 'budget']; }
            elseif ($b['ca_theorique'] !== null && (float) $b['ca_theorique'] > 0) { $out[(string) $b['shop_id']] = ['montant' => (float) $b['ca_theorique'], 'source' => 'theorique']; }
        }
    } catch (PDOException $e) { /* budget non encodé : pas d'objectif */ }
    return $out;
}

/**
 * Le CA d'un magasin jour par jour, par l'endpoint des KPIs : un jour clos se grave une fois,
 * le jour en cours se relit toutes les dix minutes. Les jours manquants partent de front.
 *
 * @return array<string, float|null>  date => CA
 */
function primesCaJours(int $sid, array $jours): array
{
    $out = []; $aLire = [];
    $auj = date('Y-m-d');
    foreach ($jours as $j) {
        $c = setting('pvJ' . $sid . ':' . $j);
        if (is_array($c) && isset($c['ca']) && ($j < $auj || (int) ($c['quand'] ?? 0) > time() - PRIMES_CACHE_JOUR)) { $out[$j] = (float) $c['ca']; }
        else { $out[$j] = null; $aLire[$j] = '/shops/' . $sid . '/statistics/sales/kpis?date_from=' . $j . '&date_to=' . $j; }
    }
    if ($aLire !== [] && PanelApi::configured()) {
        $res = PanelApi::getParallele($aLire, 8);
        foreach ($aLire as $j => $p) {
            $k = $res[$j] ?? null;
            if (!is_array($k) || !isset($k['ca'])) { continue; }
            $out[$j] = (float) $k['ca'];
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['pvJ' . $sid . ':' . $j, json_encode(['quand' => time(), 'ca' => (float) $k['ca'], 'tickets' => (int) ($k['tickets'] ?? 0)])]);
        }
    }
    return $out;
}

/**
 * Le mois d'un magasin face à son objectif : l'encaissé, la projection au rythme des jours clos
 * (semaine et week-end à part), les paliers de la prime magasin et ce qu'il manque à chacun.
 *
 * $heuresVous : les heures de la personne au planning du mois (null : seulement l'équipe).
 */
function primesMagasinMois(int $sid, string $m, array $reg, ?float $heuresVous, float $heuresEquipe, ?array $budget = null): array
{
    $bud = $budget ?? (primesBudgets($m)[(string) $sid] ?? null);
    $objectif = $bud !== null ? round((float) $bud['montant'], 2) : null;
    $auj = date('Y-m-d');
    $premier = $m . '-01'; $dernier = date('Y-m-t', strtotime($premier));
    $clos = $dernier < $auj;
    $nbJours = (int) substr($dernier, 8, 2);

    $jours = []; $ca = 0.0; $projection = null; $moySem = null; $moyWe = null; $joursRestants = 0;
    if ($clos) {
        // Un mois clos : le total par l'endpoint du mois, gravé ; pas de jour par jour.
        $k = pvKpisMois($sid, $m);
        $ca = $k !== null ? (float) $k['ca'] : 0.0;
        $projection = $ca;
    } elseif ($m === substr($auj, 0, 7)) {
        $liste = [];
        for ($j = $premier; $j <= $auj; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { $liste[] = $j; }
        $caJ = primesCaJours($sid, $liste);
        $sem = []; $we = [];
        foreach ($liste as $j) {
            $v = $caJ[$j];
            $estWe = (int) date('N', strtotime($j)) >= 6;
            $jours[] = ['date' => $j, 'ca' => $v !== null ? round($v, 2) : null, 'we' => $estWe, 'auj' => $j === $auj];
            if ($v !== null) { $ca += $v; if ($j < $auj) { if ($estWe) { $we[] = $v; } else { $sem[] = $v; } } }
        }
        // La projection : les jours clos tels quels, puis chaque jour restant (aujourd'hui compris,
        // sa journée n'est pas finie) au rythme moyen de son genre de jour.
        $moySem = $sem !== [] ? array_sum($sem) / count($sem) : null;
        $moyWe = $we !== [] ? array_sum($we) / count($we) : null;
        $tous = array_merge($sem, $we);
        $moyTous = $tous !== [] ? array_sum($tous) / count($tous) : null;
        $caClos = array_sum($tous);
        $reste = 0.0; $ok = $tous !== [];
        for ($j = $auj; $j <= $dernier; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $joursRestants++;
            $estWe = (int) date('N', strtotime($j)) >= 6;
            $moy = $estWe ? ($moyWe ?? $moyTous) : ($moySem ?? $moyTous);
            if ($moy === null) { $ok = false; break; }
            $reste += $moy;
        }
        $projection = $ok ? round($caClos + $reste, 2) : null;
    } else {
        // Un mois à venir : rien à projeter.
        $projection = null;
    }

    $atteinte = ($objectif !== null && $objectif > 0) ? round(100 * $ca / $objectif, 1) : null;
    $atteinteProj = ($objectif !== null && $objectif > 0 && $projection !== null) ? round(100 * $projection / $objectif, 1) : null;
    $palier = primeMagasinPalier($atteinteProj, $reg['paliers']);
    $paliers = [];
    foreach ($reg['paliers'] as $p) {
        $cible = $objectif !== null ? $objectif * $p['pct'] / 100 : null;
        $manque = ($cible !== null && $projection !== null) ? max(0.0, $cible - $projection) : null;
        $paliers[] = ['pct' => $p['pct'], 'eh' => $p['eh'], 'lib' => $p['lib'],
            'atteint' => $atteinteProj !== null && $atteinteProj + 1e-9 >= $p['pct'],
            'manque' => $manque !== null ? round($manque, 2) : null,
            'parJour' => ($manque !== null && $manque > 0 && $joursRestants > 0) ? round($manque / $joursRestants, 2) : null,
            'vous' => $heuresVous !== null ? round($p['eh'] * $heuresVous, 2) : null,
            'equipe' => round($p['eh'] * $heuresEquipe, 2)];
    }
    $sousMin = $heuresVous !== null && $heuresVous < $reg['heuresMin'];
    return ['objectif' => $objectif, 'objectifSource' => $bud['source'] ?? null, 'clos' => $clos,
        'ca' => round($ca, 2), 'atteinte' => $atteinte,
        'projection' => $projection, 'atteinteProj' => $atteinteProj,
        'moySem' => $moySem !== null ? round($moySem, 2) : null, 'moyWe' => $moyWe !== null ? round($moyWe, 2) : null,
        'joursRestants' => $joursRestants, 'objectifJour' => $objectif !== null ? round($objectif / $nbJours, 2) : null,
        'jours' => $jours, 'paliers' => $paliers,
        'palier' => $palier !== null ? ['pct' => $palier['pct'], 'eh' => $palier['eh'], 'lib' => $palier['lib']] : null,
        'heures' => $heuresVous !== null ? round($heuresVous, 1) : null, 'heuresMin' => $reg['heuresMin'], 'sousMin' => $sousMin,
        'heuresEquipe' => round($heuresEquipe, 1),
        'part' => ($heuresVous !== null && $heuresEquipe > 0) ? round(100 * $heuresVous / $heuresEquipe, 1) : null,
        'vous' => ($palier !== null && $heuresVous !== null && !$sousMin) ? round($palier['eh'] * $heuresVous, 2) : 0.0,
        'equipe' => $palier !== null ? round($palier['eh'] * $heuresEquipe, 2) : 0.0];
}

/* ======================================================================
   Les heures et les ventes d'un mois, mises en cache
   ====================================================================== */

/**
 * Les heures du planning sur un mois, par personne : toutes (le mois entier, jours à venir
 * compris) et celles déjà prestées (jours passés). Le magasin de chacune vient de sa fiche.
 *
 * @return array<int, array{mois:float,faites:float,shop:string,fin:?string}>
 */
function primesHeuresMois(string $m): array
{
    [$du, $au] = venteBornes($m);
    $duree = '((TIME_TO_SEC(s.end_hour) - TIME_TO_SEC(s.start_hour) + 86400) % 86400)';
    $out = [];
    try {
        foreach (Db::rows('SELECT s.id_employee, e.id_shop,
                                  SUM(' . $duree . ') / 3600 h,
                                  SUM(CASE WHEN s.work_date < ? THEN ' . $duree . ' ELSE 0 END) / 3600 hf,
                                  MAX(s.work_date) fin
                             FROM franchisee_employee_schedule s
                             JOIN franchisee_employee e ON e.id = s.id_employee
                            WHERE s.work_date >= ? AND s.work_date < ?
                            GROUP BY s.id_employee, e.id_shop',
            [date('Y-m-d'), substr($du, 0, 10), substr($au, 0, 10)]) as $r) {
            $out[(int) $r['id_employee']] = ['mois' => round((float) $r['h'], 2), 'faites' => round((float) $r['hf'], 2), 'shop' => (string) $r['id_shop'],
                // Le planning se saisit semaine après semaine : jusqu'où va-t-il ? Les heures du mois sont celles connues à ce jour.
                'fin' => isset($r['fin']) ? substr((string) $r['fin'], 0, 10) : null];
        }
    } catch (PDOException $e) { /* planning illisible : zéro heure partout, l'écran le dit */ }
    return $out;
}

/**
 * venteMois, avec un cache pour les mois clos servis par la table locale (avant la moisson) :
 * ils ne bougent plus, et leur lecture SQL coûtait des secondes à chaque fiche.
 */
function venteMoisCache(string $m, array $nomDe): array
{
    $clos = date('Y-m-t', strtotime($m . '-01')) < date('Y-m-d');
    $local = $m < (function_exists('pvLignesDebut') ? pvLignesDebut() : '2026-08');
    if ($clos && $local) {
        $c = setting('vmMois' . $m);
        if (is_array($c) && isset($c['lignes'])) { return $c; }
        $r = venteMois($m, $nomDe);
        if ($r['motif'] === null) {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['vmMois' . $m, json_encode($r, JSON_UNESCAPED_UNICODE)]);
        }
        return $r;
    }
    return venteMois($m, $nomDe);
}

/** Les magasins actifs, id → nom. */
function primesMagasins(): array
{
    $out = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) { $out[(string) $s['id']] = (string) $s['name']; }
    return $out;
}

/* ======================================================================
   La fiche d'une personne
   ====================================================================== */

/** Le libellé d'un mois, « octobre 2026 ». */
function primesLibMois(string $m): string
{
    $noms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $noms[(int) substr($m, 5, 2) - 1] . ' ' . substr($m, 0, 4);
}

/**
 * Les trois briques de la prime individuelle d'une personne sur un mois, et ce qui les nourrit.
 *
 * $moi : sa ligne de venteMois (null : ni vente ni heure ce mois-là) ; $cr : pvCroiseesMois($m).
 */
function primesBriques(int $emp, string $m, ?array $moi, array $lignes, ?array $cr, array $parMois, string $shop): array
{
    $cfgC = croiseesReglages(); $regR = venteRecordReglages(); $mv = ventePrimesConfig();
    $tickets = (int) ($moi['tickets'] ?? 0);

    // Les ventes croisées : la moisson, ticket par ticket.
    $x = $cr['e'][$emp] ?? null;
    $tC = $x !== null ? (int) $x['t'] : 0;
    $cC = ($x !== null && isset($x['c'])) ? (int) $x['c'] : null;
    $taux = ($cC !== null && $tC > 0) ? round(100 * $cC / $tC, 1) : null;
    $cible = croiseesCible($cfgC, $shop);
    $prC = croiseesPrime($taux, $cible, $cfgC, $tC);
    $prochain = $prC['prochain'];
    if ($prochain !== null && $tC > 0) {
        $prochain['manque'] = max(0, (int) ceil($prochain['taux'] / 100 * $tC - ($cC ?? 0)));
    }
    $croisees = ['croisees' => $cC, 'tickets' => $tC, 'taux' => $taux, 'cible' => $cible,
        'montant' => $prC['montant'], 'palier' => $prC['palier'], 'prochain' => $prochain,
        'minTickets' => $cfgC['minTickets'], 'paliers' => $cfgC['paliers'],
        'jours' => (int) ($cr['jours'] ?? 0), 'complet' => (bool) ($cr['complet'] ?? false),
        'motif' => $cr === null ? 'pas encore moissonné' : ($cC === null ? 'ventes croisées pas encore comptées sur ces jours' : null)];

    // Bats ton record : lignes par ticket face au record 12 mois. Le mois en cours n'a pas encore
    // ses lignes dans venteMois (elles arrivent quand le mois est entièrement moissonné) : on les
    // prend à la moisson, jour par jour.
    $lt = $moi['lignesTicket'] ?? null;
    if ($lt === null && $x !== null && $tC > 0 && isset($x['l'])) { $lt = round((int) $x['l'] / $tC, 2); }
    $rec = venteRecordVendeuse($parMois, (string) $emp, $m);
    $recMois = null;
    if ($rec !== null) {
        foreach (venteFenetreRecord($m) as $mF) {
            foreach (($parMois[$mF] ?? []) ?: [] as $l) {
                if ((string) $l['id'] === (string) $emp && $l['lignesTicket'] !== null && abs((float) $l['lignesTicket'] - $rec) < 1e-9 && ($l['tickets'] ?? 0) >= VENTE_CROSS_MIN_TICKETS) { $recMois = $mF; }
            }
        }
    }
    $assezR = max($tickets, $tC) >= VENTE_CROSS_MIN_TICKETS;
    $prR = ($lt !== null && $assezR) ? venteRecordPrime((float) $lt, $rec, $regR['eurDixieme'], $regR['maxDixiemes']) : ['tranches' => 0, 'prime' => 0];
    $record = ['lt' => $lt, 'record' => $rec, 'recordMois' => $recMois !== null ? primesLibMois($recMois) : null,
        'ecart' => ($lt !== null && $rec !== null) ? round($lt - $rec, 2) : null,
        'tranches' => $prR['tranches'], 'montant' => $prR['prime'],
        'eurDixieme' => $regR['eurDixieme'], 'maxDixiemes' => $regR['maxDixiemes'], 'minTickets' => VENTE_CROSS_MIN_TICKETS,
        'assez' => $assezR];

    // La meilleure vendeuse : le score, le rang dans le magasin et dans le réseau.
    $classables = array_values(array_filter($lignes, static fn ($l) => !empty($l['classable'])));
    $duMag = array_values(array_filter($classables, static fn ($l) => (string) $l['shopId'] === $shop));
    usort($duMag, static fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $rangMag = null;
    foreach ($duMag as $i => $l) { if ((int) $l['id'] === $emp) { $rangMag = $i + 1; } }
    $premier = $duMag[0]['score'] ?? null;
    $rangRes = $moi['rang'] ?? null;
    $meilleure = ['score' => $moi['score'] ?? null, 'caHeure' => $moi['caHeure'] ?? null, 'heures' => $moi['heures'] ?? 0.0,
        'ca' => $moi['ca'] ?? 0, 'tickets' => $tickets, 'panier' => $moi['panier'] ?? null,
        'rangMag' => $rangMag, 'surMag' => count($duMag), 'scorePremier' => $premier,
        'rangRes' => $rangRes, 'surRes' => count($classables),
        'montantMag' => $mv['magasin'], 'montantRes' => $mv['reseau'],
        'montant' => $rangRes === 1 ? $mv['reseau'] : ($rangMag === 1 ? $mv['magasin'] : 0),
        'motif' => $moi['motifHorsClassement'] ?? null];

    return ['croisees' => $croisees, 'record' => $record, 'meilleure' => $meilleure];
}

/**
 * Un mois clos d'une personne : ce que chaque règle a donné, et si le CEO l'a enregistré.
 * Mis en cache un jour — l'enregistrement du CEO l'invalide.
 */
function primesMoisClos(int $emp, string $m, string $shop, array $nomDe): array
{
    $cle = 'primesClos' . $emp . ':' . $m;
    $c = setting($cle);
    $hist = setting('ventePrimesHist');
    $paye = is_array($hist) && isset($hist[$m]);
    if (is_array($c) && isset($c['total']) && (int) ($c['quand'] ?? 0) > time() - PRIMES_CACHE_CLOS && (bool) ($c['paye'] ?? false) === $paye) { return $c; }

    $r = venteMoisCache($m, $nomDe);
    $moi = null;
    foreach ($r['lignes'] as $l) { if ((int) $l['id'] === $emp) { $moi = $l; } }
    $parMois = [];
    foreach (venteFenetreRecord($m) as $mF) { $rF = venteMoisCache($mF, $nomDe); $parMois[$mF] = $rF['motif'] === null ? $rF['lignes'] : null; }
    $cr = function_exists('pvCroiseesMois') ? pvCroiseesMois($m) : null;
    $b = primesBriques($emp, $m, $moi, $r['lignes'], $cr, $parMois, $shop);
    $h = primesHeuresMois($m);
    $hVous = (float) ($h[$emp]['mois'] ?? ($moi['heures'] ?? 0.0));
    $hEq = 0.0;
    foreach ($h as $id => $x) { if ($x['shop'] === $shop) { $hEq += $x['mois']; } }
    $pm = primesMagasinMois((int) $shop, $m, primeMagasinReglages(), $hVous, $hEq);

    $detail = [];
    $detail[] = $b['croisees']['taux'] !== null
        ? ('ventes croisées ' . str_replace('.', ',', (string) $b['croisees']['taux']) . ' % (cible ' . str_replace('.', ',', (string) $b['croisees']['cible']) . ' %)' . ($b['croisees']['montant'] > 0 ? ' : ' . $b['croisees']['montant'] . ' €' : ''))
        : 'ventes croisées pas comptées';
    if ($b['record']['lt'] !== null) {
        $detail[] = str_replace('.', ',', (string) $b['record']['lt']) . ' lignes/ticket' . ($b['record']['record'] !== null ? ', record ' . str_replace('.', ',', (string) $b['record']['record']) : '')
            . ($b['record']['montant'] > 0 ? ' : ' . $b['record']['montant'] . ' €' : '');
    }
    if ($b['meilleure']['montant'] > 0) { $detail[] = ($b['meilleure']['rangRes'] === 1 ? 'meilleure du réseau' : 'meilleure du magasin') . ' : ' . $b['meilleure']['montant'] . ' €'; }
    elseif ($b['meilleure']['rangMag'] !== null) { $detail[] = $b['meilleure']['rangMag'] . 'e du magasin'; }
    if ($pm['atteinte'] !== null) {
        $detail[] = 'magasin à ' . str_replace('.', ',', (string) $pm['atteinte']) . ' %'
            . ($pm['vous'] > 0 ? ' : ' . str_replace('.', ',', (string) $pm['heures']) . ' h × ' . number_format($pm['palier']['eh'], 2, ',', '') . ' € = ' . number_format($pm['vous'], 2, ',', ' ') . ' €' : '');
    }
    $total = round($b['croisees']['montant'] + $b['record']['montant'] + $b['meilleure']['montant'] + $pm['vous'], 2);
    $out = ['m' => $m, 'lib' => primesLibMois($m), 'total' => $total, 'paye' => $paye, 'detail' => $detail,
        'croisees' => $b['croisees']['montant'], 'record' => $b['record']['montant'], 'meilleure' => $b['meilleure']['montant'], 'magasin' => $pm['vous'],
        'quand' => time()];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/**
 * La fiche « Mes primes » d'une personne pour un mois : le compteur du jour, les trois briques,
 * la prime magasin, les semaines, les mois passés. Rien d'un autre nom que le sien.
 */
function primesMoi(int $emp, string $m, bool $frais = false): array
{
    $cle = 'primesMoi' . $emp . ':' . $m;
    $enCours = $m === date('Y-m');
    if (!$frais) {
        $c = setting($cle);
        if (is_array($c) && isset($c['emp']) && (int) ($c['quand'] ?? 0) > time() - ($enCours ? PRIMES_CACHE_MOI : PRIMES_CACHE_CLOS)) { return $c; }
    }
    $emps = venteEmployes();
    if (!isset($emps[$emp])) { return ['error' => 'personne inconnue', 'code' => 404]; }
    $shop = (string) $emps[$emp]['shop'];
    $nomDe = primesMagasins();

    $r = venteMoisCache($m, $nomDe);
    $moi = null;
    foreach ($r['lignes'] as $l) { if ((int) $l['id'] === $emp) { $moi = $l; } }
    $parMois = [];
    foreach (venteFenetreRecord($m) as $mF) { $rF = venteMoisCache($mF, $nomDe); $parMois[$mF] = $rF['motif'] === null ? $rF['lignes'] : null; }
    $cr = function_exists('pvCroiseesMois') ? pvCroiseesMois($m) : null;
    $b = primesBriques($emp, $m, $moi, $r['lignes'], $cr, $parMois, $shop);

    $h = primesHeuresMois($m);
    $hVous = (float) ($h[$emp]['mois'] ?? 0.0); $hFaites = (float) ($h[$emp]['faites'] ?? 0.0);
    $hEq = 0.0; $nEq = 0; $finPlan = null;
    foreach ($h as $id => $x) { if ($x['shop'] === $shop) { $hEq += $x['mois']; $nEq++; if ($x['fin'] !== null && ($finPlan === null || $x['fin'] > $finPlan)) { $finPlan = $x['fin']; } } }
    $pm = primesMagasinMois((int) $shop, $m, primeMagasinReglages(), $hVous, $hEq);
    $pm['planningJusquau'] = $finPlan;
    // Le mois en cours : le CA par heure se lit sur les heures déjà prestées, pas sur le planning
    // du mois entier (le score et le rang, eux, restent ceux du classement du cockpit).
    if ($enCours && $hFaites > 0 && ($b['meilleure']['ca'] ?? 0) > 0) {
        $b['meilleure']['caHeure'] = (int) round($b['meilleure']['ca'] / $hFaites);
        $b['meilleure']['heures'] = round($hFaites, 1);
    }

    // Aujourd'hui : ses tickets et ses ventes croisées, lus au panel toutes les dix minutes.
    $auj = null;
    if ($enCours && function_exists('pvLignesJourPersonne')) {
        $j = pvLignesJourPersonne((int) $shop, $emp);
        if ($j !== null) {
            $auj = ['tickets' => $j['t'], 'croisees' => $j['c'], 'lignes' => $j['l'],
                'taux' => $j['t'] > 0 ? round(100 * $j['c'] / $j['t'], 1) : null, 'quand' => date('H:i', $j['quand'])];
        }
    }

    // Les semaines : ses ventes croisées semaine par semaine, sur six semaines.
    $semaines = function_exists('pvCroiseesSemaines') ? pvCroiseesSemaines($emp, 6, (int) $shop) : [];

    // Ce qui est acquis au rythme actuel, et ce qui est à portée en un cran de plus.
    $acquis = round($b['croisees']['montant'] + $b['record']['montant'] + $b['meilleure']['montant'] + $pm['vous'], 2);
    $cfgC = croiseesReglages(); $regR = venteRecordReglages();
    $maxC = max(array_map(static fn ($p) => $p['m'], $cfgC['paliers']) ?: [0]);
    $cranC = $b['croisees']['prochain'] !== null ? max($b['croisees']['montant'], $b['croisees']['prochain']['montant']) : max($b['croisees']['montant'], $maxC);
    $cranR = min($regR['maxDixiemes'] > 0 ? $regR['maxDixiemes'] : 99, $b['record']['tranches'] + 1) * $regR['eurDixieme'];
    $cranM = $b['meilleure']['montant'] > 0 ? $b['meilleure']['montant'] : $b['meilleure']['montantMag'];
    $cranMag = $pm['vous'];
    foreach ($pm['paliers'] as $p) { if (!$p['atteint'] && $p['vous'] !== null && !$pm['sousMin']) { $cranMag = $p['vous']; break; } }
    $aPortee = round($cranC + $cranR + $cranM + $cranMag, 2);

    // Les mois passés : les trois derniers mois clos.
    $mois = [];
    for ($i = 1; $i <= 3; $i++) {
        $mF = date('Y-m', strtotime($m . '-01 -' . $i . ' month'));
        if ($mF >= date('Y-m')) { continue; }
        $mois[] = primesMoisClos($emp, $mF, $shop, $nomDe);
    }

    $out = ['emp' => $emp, 'm' => $m, 'lib' => primesLibMois($m), 'enCours' => $enCours, 'quand' => time(),
        'magasin' => ['id' => $shop, 'nom' => $nomDe[$shop] ?? ('Magasin ' . $shop)],
        'heures' => ['mois' => round($hVous, 1), 'faites' => round($hFaites, 1), 'equipe' => round($hEq, 1), 'personnes' => $nEq, 'planningJusquau' => $finPlan],
        'aujourdhui' => $auj, 'acquis' => $acquis, 'aPortee' => $aPortee,
        'briques' => $b, 'prime' => $pm, 'semaines' => $semaines, 'mois' => $mois,
        'motif' => $r['motif']];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/* ======================================================================
   Les routes
   ====================================================================== */

/** Le mois demandé, AAAA-MM, le mois en cours par défaut ; jamais au-delà. */
function primesMoisDemande(): string
{
    $m = trim((string) ($_GET['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m) || $m > date('Y-m')) { $m = date('Y-m'); }
    return $m;
}

/** GET /ventes/moi?emp=&m= — depuis le cockpit (session), pour voir ce que l'app montre. */
function ep_ventes_moi(): array
{
    $emp = (int) ($_GET['emp'] ?? 0);
    if ($emp <= 0) { http_response_code(400); return ['error' => 'emp attendu']; }
    $r = primesMoi($emp, primesMoisDemande(), isset($_GET['frais']));
    if (isset($r['error'])) { http_response_code((int) ($r['code'] ?? 500)); return ['error' => $r['error']]; }
    return $r;
}

/** Le jeton porté par la requête (Authorization: Bearer …), sinon null. */
function primesJetonRecu(): ?string
{
    // Apache ne transmet pas toujours Authorization à PHP : l'app envoie aussi X-Employee-Token.
    $x = trim((string) ($_SERVER['HTTP_X_EMPLOYEE_TOKEN'] ?? ''));
    if ($x !== '' && substr_count($x, '.') === 2) { return $x; }
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $k => $v) { if (strtolower((string) $k) === 'authorization') { $h = (string) $v; } }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : null;
}

/**
 * L'employé derrière un jeton de l'app worker : son identifiant est dans le jeton (sub), et le
 * panel le confirme — GET /employees/{id} avec ce jeton répond 200 pour lui seul. Le cockpit ne
 * connaît pas le secret du jeton : c'est le panel qui le vérifie. Vérifié une fois par dix
 * minutes, par empreinte du jeton.
 */
function primesEmployeDuJeton(string $jeton): ?int
{
    $parts = explode('.', $jeton);
    if (count($parts) !== 3) { return null; }
    $p = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true), true);
    $id = is_array($p) ? (int) ($p['sub'] ?? 0) : 0;
    if ($id <= 0) { return null; }
    if (isset($p['exp']) && is_numeric($p['exp']) && (int) $p['exp'] < time()) { return null; }
    $cle = 'primesJeton' . substr(hash('sha256', $jeton), 0, 40);
    $c = setting($cle);
    if (is_array($c) && (int) ($c['emp'] ?? 0) === $id && (int) ($c['quand'] ?? 0) > time() - PRIMES_CACHE_JETON) { return $id; }
    if (!PanelApi::configured()) { return null; }
    $ch = curl_init(rtrim(PanelApi::config()['base'], '/') . '/employees/' . $id);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $jeton]]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) { return null; }
    $d = json_decode((string) $raw, true);
    $d = is_array($d) ? ($d['data'] ?? $d) : null;
    if (!is_array($d) || (int) ($d['id'] ?? 0) !== $id) { return null; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['emp' => $id, 'quand' => time()])]);
    return $id;
}

/** GET /ventes/moi avec le jeton de l'app worker : la fiche de la personne connectée, personne d'autre. */
function ep_ventes_moi_jeton(): array
{
    $jeton = primesJetonRecu();
    $emp = $jeton !== null ? primesEmployeDuJeton($jeton) : null;
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $r = primesMoi($emp, primesMoisDemande(), isset($_GET['frais']));
    if (isset($r['error'])) { http_response_code((int) ($r['code'] ?? 500)); return ['error' => $r['error']]; }
    return $r;
}

/**
 * GET /ventes/primes-reglages?shop= — tous les réglages des primes, les magasins, et l'aperçu du
 * mois en cours pour un magasin (l'objectif, la projection, ce qu'il manque, l'enveloppe).
 */
function ep_primes_reglages(): array
{
    $nomDe = primesMagasins();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if ($shop === '' || !isset($nomDe[$shop])) { $shop = (string) (array_key_first($nomDe) ?? ''); }
    $m = date('Y-m');
    $out = ['magasin' => primeMagasinReglages(), 'croisees' => croiseesReglages(),
        'record' => venteRecordReglages(), 'meilleure' => ventePrimesConfig(),
        'magasins' => array_map(static fn ($id, $n) => ['id' => (string) $id, 'nom' => $n], array_keys($nomDe), $nomDe),
        'shop' => $shop, 'm' => $m, 'lib' => primesLibMois($m), 'apercu' => null, 'mesures' => []];
    if ($shop !== '') {
        $h = primesHeuresMois($m);
        $hEq = 0.0; $n = 0; $finPlan = null;
        foreach ($h as $x) { if ($x['shop'] === $shop) { $hEq += $x['mois']; $n++; if ($x['fin'] !== null && ($finPlan === null || $x['fin'] > $finPlan)) { $finPlan = $x['fin']; } } }
        $out['apercu'] = primesMagasinMois((int) $shop, $m, $out['magasin'], null, $hEq) + ['personnes' => $n, 'planningJusquau' => $finPlan];
    }
    // Le taux de ventes croisées mesuré par magasin sur le dernier mois clos, pour poser les cibles.
    $mClos = date('Y-m', strtotime('first day of last month'));
    if (function_exists('pvCroiseesMoisMagasins')) { $out['mesures'] = pvCroiseesMoisMagasins($mClos); $out['mesuresMois'] = primesLibMois($mClos); }
    return $out;
}
