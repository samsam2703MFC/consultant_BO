<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les primes, telles que l'app worker les lit (demande du 06/10/2026, maquette B ;
 * une récompense par prime depuis le 14/10/2026, src/primes_app.php).
 *
 * Chaque mois, pour chaque personne de vente, des primes qui s'additionnent :
 *
 *  · les PRIMES DE L'APP, une liste réglée dans le cockpit, chacune avec UNE récompense (50 € par
 *    défaut) : les ventes croisées (un ticket à deux lignes ou plus ; le TAUX du mois face à la cible
 *    du magasin, pas le nombre brut), l'objectif du magasin (l'atteinte du budget du mois ; chaque
 *    personne au planning au-dessus du minimum d'heures), les catégories (Queen of Tartes, Queen of
 *    Quiches : la 1re du magasin au nombre de pièces), les promotions du panel (la 1re du magasin
 *    au nombre de bundles ou de pièces de l'offre, sur les dates et dans les magasins de la promo) et
 *    la note Google du magasin (à son seuil ou mieux, 25 € par défaut pour chaque personne au planning) ;
 *  · BATS TON RECORD et LA MEILLEURE VENDEUSE (src/ventes.php), qui gardent leurs montants à eux.
 *
 * Le mois en cours se montre en PROJECTION (au rythme des jours clos, semaine et week-end à part) ;
 * seul le mois clos se paie, par le geste « Enregistrer les primes » qui existe déjà.
 *
 * Les lectures passent par les caches du cockpit (pvMois, pvL, pvCa…) et un cache de 15 minutes
 * par personne et par mois : l'app s'ouvre souvent, le calcul est lourd.
 */

const PRIMES_CACHE_MOI = 900;      // 15 min : la fiche d'une personne pour le mois en cours
// La forme de la fiche : à monter quand elle change, pour qu'un déploiement ne serve pas une fiche d'avant
// gardée en cache (16/10/2026 : 5 les douze semaines avec mag et res, le podium des catégories ; 6 la barre des targets ; 7 la barre des catégories ; 8 « sans_vente » ; 9 cadeau, description, sponsor ; 10 les indications de la fiche).
const PRIMES_FICHE_V = 10;
const PRIMES_CACHE_CLOS = 86400;   // un jour : un mois clos ne bouge que si le CEO l'enregistre
const PRIMES_CACHE_JETON = 600;    // 10 min : un jeton d'employé vérifié chez le panel
const PRIMES_CACHE_JOUR = 600;     // 10 min : le compteur du jour
const PRIMES_MOIS_A_VENIR = 3;     // les mois à venir qu'on peut ouvrir dans l'app (16/10/2026)

/* ======================================================================
   Les réglages
   ====================================================================== */

/**
 * La prime de l'objectif magasin, depuis la liste des primes de l'app : un seul palier (l'atteinte
 * visée, 100 % par défaut) et sa récompense par personne, le minimum d'heures dans le mois.
 */
function primeMagasinReglages(): array
{
    $e = primesAppEntree('objectif');
    $actif = $e === null || $e['actif'];
    return ['paliers' => [['pct' => (int) ($e['pct'] ?? 100), 'montant' => $actif ? (int) ($e['montant'] ?? PRIMES_APP_RECOMPENSE) : 0, 'lib' => 'Objectif']],
        'heuresMin' => (int) ($e['heuresMin'] ?? 20), 'actif' => $actif];
}

/**
 * Les ventes croisées : la cible (réseau, et par magasin), le minimum de tickets ; la récompense
 * vient de la liste des primes de l'app (une seule, à la cible).
 */
function croiseesReglages(): array
{
    $out = ['cibleDefaut' => 50.0, 'cibles' => [], 'minTickets' => 30];
    $c = setting('venteCroisees');
    if (is_array($c)) {
        if (isset($c['cibleDefaut']) && is_numeric($c['cibleDefaut'])) { $out['cibleDefaut'] = max(0.0, min(100.0, round((float) $c['cibleDefaut'], 1))); }
        foreach ((array) ($c['cibles'] ?? []) as $sid => $v) {
            if (is_numeric($v) && (float) $v > 0) { $out['cibles'][(string) $sid] = max(0.0, min(100.0, round((float) $v, 1))); }
        }
        if (isset($c['minTickets']) && is_numeric($c['minTickets'])) { $out['minTickets'] = max(0, (int) $c['minTickets']); }
    }
    $e = primesAppEntree('croisees');
    $out['actif'] = $e === null || $e['actif'];
    $out['categorie'] = (string) ($e['categorie'] ?? '');
    $out['montant'] = $out['actif'] ? (int) ($e['montant'] ?? PRIMES_APP_RECOMPENSE) : 0;
    $out['paliers'] = [['plus' => 0.0, 'm' => $out['montant']]];
    return $out;
}

/** POST /ventes/croisees {cibleDefaut, cibles: {shop: pct}, minTickets}. */
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
    if (isset($b['minTickets']) && is_numeric($b['minTickets'])) { $cur['minTickets'] = max(0, min(1000, (int) $b['minTickets'])); }
    $garde = ['cibleDefaut' => $cur['cibleDefaut'], 'cibles' => $cur['cibles'], 'minTickets' => $cur['minTickets']];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['venteCroisees', json_encode($garde, JSON_UNESCAPED_UNICODE)]);
    primesCachesVider();
    journalAdd('CEO', 'Paramètre', 'Ventes croisées', 'Cible réseau ' . $cur['cibleDefaut'] . ' %, ' . count($cur['cibles']) . ' cible(s) de magasin'
        . ($cur['cibles'] !== [] ? ' (' . implode(', ', array_map(static fn ($s, $v) => $s . ' : ' . $v . ' %', array_keys($cur['cibles']), $cur['cibles'])) . ')' : '')
        . ', ' . $cur['minTickets'] . ' tickets minimum');
    return ['ok' => true, 'reglages' => croiseesReglages()];
}

/**
 * Les concours d'avant (06/10/2026) : le nombre de pièces d'une famille vendues dans le mois. Ils
 * vivent maintenant dans la liste des primes de l'app, comme catégories ; ces deux-là la sèment.
 */
function concoursDefauts(): array
{
    return [
        ['cle' => 'tartes', 'lib' => 'Queen of Tartes', 'motif' => 'tarte', 'magasin' => 50, 'reseau' => 0, 'minPieces' => 10],
        ['cle' => 'quiches', 'lib' => 'Queen of Quiches', 'motif' => 'quiche', 'magasin' => 50, 'reseau' => 0, 'minPieces' => 10],
    ];
}

/** Une entrée de concours nettoyée, ou null si elle ne tient pas debout. */
function concoursEntree(array $c, int $i): ?array
{
    $lib = mb_substr(trim((string) ($c['lib'] ?? '')), 0, 40);
    if ($lib === '') { return null; }
    $motif = mb_substr(trim((string) ($c['motif'] ?? '')), 0, 120);
    if ($motif === '' || @preg_match('/' . $motif . '/iu', '') === false) { return null; }
    $cle = strtolower(trim((string) ($c['cle'] ?? '')));
    $cle = preg_replace('/[^a-z0-9]+/', '', $cle) ?? '';
    if ($cle === '') { $cle = preg_replace('/[^a-z0-9]+/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $lib) ?: 'c' . $i)) ?: ('c' . $i); }
    $out = ['cle' => mb_substr($cle, 0, 20), 'lib' => $lib, 'motif' => $motif, 'magasin' => 50, 'reseau' => 0, 'minPieces' => 10];
    foreach (['magasin', 'minPieces'] as $k) { if (isset($c[$k]) && is_numeric($c[$k])) { $out[$k] = max(0, min(5000, (int) $c[$k])); } }
    return $out;
}

/**
 * Les catégories actives de la liste, sous la forme des concours : {actif, liste: [{cle, lib, motif,
 * magasin (la récompense de la 1re du magasin), reseau (0 : plus de couronne du réseau payée), minPieces}]}.
 */
function concoursReglages(): array
{
    // Un cadeau (16/10/2026) : pas d'euros à verser, le titre porte le cadeau.
    $liste = array_map(static fn ($e) => ['cle' => $e['cle'], 'lib' => $e['lib'], 'motif' => $e['motif'], 'magasin' => !empty($e['cadeau']) ? 0 : (int) $e['montant'], 'reseau' => 0,
        'minPieces' => (int) $e['minPieces'], 'classement' => (bool) $e['classement'], 'cadeau' => $e['cadeau'] ?? null], primesAppDuType('categorie'));
    return ['actif' => $liste !== [], 'liste' => $liste];
}

/** Les familles à compter dans la moisson : [cle => motif]. Les catégories éteintes se comptent quand même, le titre attend. */
function concoursFamilles(): array
{
    $out = [];
    foreach (primesAppDuType('categorie', false) as $e) { $out[$e['cle']] = $e['motif']; }
    return $out;
}

/** Le motif de toutes les familles réunies (l'ancien compte « tq »). */
function concoursMotif(): string
{
    return implode('|', array_map(static fn ($m) => '(?:' . $m . ')', array_values(concoursFamilles())));
}

/**
 * Le classement au nombre de pièces d'une liste de familles sur un mois : par magasin et pour le réseau
 * (à égalité, moins de tickets gagne : la proposition compte plus que le passage). $champ : « q » pour
 * les catégories (le mois entier), « qp » pour les promos (leurs seules dates). $magasinsDe : par clé,
 * les magasins admis (null : tous). Rend, par clé puis par personne, son rang dans le magasin et dans
 * le réseau, et les pièces de la première. Une personne sans jour compté n'y est pas.
 *
 * @return array<string, array<int, array{pieces:float,tickets:int,rangMag:?int,surMag:int,rangRes:?int,surRes:int,premierMag:float,premierRes:float}>>
 */
function piecesClassement(?array $cr, array $emps, array $liste, string $champ = 'q', array $magasinsDe = []): array
{
    $out = [];
    $cmp = static fn ($a, $b) => [$b['pieces'], $a['tickets']] <=> [$a['pieces'], $b['tickets']];
    foreach ($liste as $k) {
        $cle = $k['cle'];
        $admis = $magasinsDe[$cle] ?? null;
        $tous = [];
        foreach ((array) ($cr['e'] ?? []) as $id => $x) {
            $id = (int) $id;
            if (!isset($emps[$id]) || !isset($x['q']) || !is_array($x['q'])) { continue; }
            $shop = (string) $emps[$id]['shop'];
            if ($admis !== null && !isset($admis[$shop])) { continue; }
            $tous[$id] = ['pieces' => (float) ($x[$champ][$cle] ?? 0.0), 'tickets' => (int) ($x['t'] ?? 0), 'shop' => $shop];
        }
        $parShop = [];
        foreach ($tous as $id => $x) { $parShop[$x['shop']][$id] = $x; }
        $reseau = array_filter($tous, static fn ($x) => $x['pieces'] >= $k['minPieces'] && $x['pieces'] > 0);
        uasort($reseau, $cmp);
        $rangRes = []; $i = 0;
        foreach ($reseau as $id => $x) { $rangRes[$id] = ++$i; }
        $premierRes = $reseau !== [] ? (float) reset($reseau)['pieces'] : 0.0;
        $out[$cle] = [];
        foreach ($parShop as $shop => $l) {
            $cl = array_filter($l, static fn ($x) => $x['pieces'] >= $k['minPieces'] && $x['pieces'] > 0);
            uasort($cl, $cmp);
            $rangMag = []; $j = 0;
            foreach ($cl as $id => $x) { $rangMag[$id] = ++$j; }
            $premierMag = $cl !== [] ? (float) reset($cl)['pieces'] : 0.0;
            // Le total du magasin (16/10/2026) : l'objectif d'une promo « magasin » se juge sur lui.
            $totalMag = round(array_sum(array_column($l, 'pieces')), 3);
            foreach ($l as $id => $x) {
                $out[$cle][$id] = ['pieces' => $x['pieces'], 'tickets' => $x['tickets'],
                    'rangMag' => $rangMag[$id] ?? null, 'surMag' => count($cl), 'rangRes' => $rangRes[$id] ?? null, 'surRes' => count($reseau),
                    'premierMag' => $premierMag, 'premierRes' => $premierRes, 'shop' => (string) $shop, 'totalMag' => $totalMag];
            }
        }
    }
    return $out;
}

/** Le classement des catégories (les concours) sur un mois. */
function concoursClassement(?array $cr, array $emps, array $cfg): array
{
    return piecesClassement($cr, $emps, $cfg['liste'], 'q');
}

/** Les promos d'un mois, sous la forme des concours : {cle, lib, magasin (la récompense), minPieces, du, au, classement, shops}. */
function promosConfig(string $m, ?string $shop = null): array
{
    return array_map(static fn ($e) => ['cle' => $e['cle'], 'lib' => $e['lib'], 'magasin' => !empty($e['cadeau']) ? 0 : (int) $e['montant'], 'reseau' => 0, 'minPieces' => (int) $e['minPieces'], 'cadeau' => $e['cadeau'] ?? null,
        'du' => $e['promo']['du'], 'au' => $e['promo']['au'], 'classement' => (bool) $e['classement'], 'shops' => $e['promo']['shops'],
        'sorte' => $e['promo']['sorte'], 'regle' => $e['regle'] ?? 'premiere', 'objectifs' => $e['objectifs'] ?? [], 'objVend' => $e['objVend'] ?? []], promosDuMois($m, $shop));
}

/** Le classement des promos d'un mois : leurs pièces sur leurs dates (« qp »), dans leurs magasins. */
function promosClassement(?array $cr, array $emps, array $promos): array
{
    $admis = [];
    foreach ($promos as $p) { if ($p['shops'] !== []) { $admis[$p['cle']] = array_fill_keys($p['shops'], true); } }
    return piecesClassement($cr, $emps, $promos, 'qp', $admis);
}

/**
 * Les titres d'une personne sur une liste de familles (catégories ou promos) : [{cle, lib, niveau: magasin|seuil|objectif|
 * equipe, montant}]. La 1re du magasin gagne la récompense ; la couronne du réseau n'est plus payée (une récompense par
 * prime). Une promo à la règle « seuil » (16/10/2026) : chaque vendeuse qui atteint le nombre de pièces gagne. Sur
 * objectif (16/10/2026, l'assistant : l'an dernier + x %) : « vendeuse », chacune qui atteint SON objectif (celui du
 * magasin partagé entre ses vendeuses) ; « magasin », une prime POUR LE MAGASIN — il atteint son objectif, la récompense
 * est une seule somme, partagée à parts égales entre ses vendeuses du mois (au centime ; « partage » dit entre combien).
 */
function primesObjectifDe(array $k, string $shop): ?float
{
    $regle = $k['regle'] ?? 'premiere';
    $v = $regle === 'vendeuse' ? ($k['objVend'][$shop] ?? null) : ($regle === 'magasin' ? ($k['objectifs'][$shop] ?? null) : null);
    return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
}

function concoursTitres(array $classements, int $id, array $cfg): array
{
    if (!$cfg['actif']) { return []; }
    $out = [];
    foreach ($cfg['liste'] as $k) {
        $cl = $classements[$k['cle']][$id] ?? null;
        if ($cl === null) { continue; }
        if ((int) $k['magasin'] <= 0 && empty($k['cadeau'])) { continue; }
        $regle = $k['regle'] ?? 'premiere';
        if ($regle === 'vendeuse' || $regle === 'magasin') {
            $obj = primesObjectifDe($k, (string) ($cl['shop'] ?? ''));
            $fait = $regle === 'vendeuse' ? (float) ($cl['pieces'] ?? 0) : (float) ($cl['totalMag'] ?? 0);
            if ($obj !== null && $fait >= $obj) {
                if ($regle === 'vendeuse') { $out[] = ['cle' => $k['cle'], 'lib' => $k['lib'], 'niveau' => 'objectif', 'montant' => (int) $k['magasin']]; continue; }
                $n = count(array_filter($classements[$k['cle']], static fn ($x) => ($x['shop'] ?? null) === ($cl['shop'] ?? '')));
                $out[] = ['cle' => $k['cle'], 'lib' => $k['lib'], 'niveau' => 'equipe', 'montant' => round((int) $k['magasin'] / max(1, $n), 2),
                    'montantMag' => (int) $k['magasin'], 'partage' => max(1, $n)];
            }
        } elseif ($regle === 'seuil') {
            if ((float) ($cl['pieces'] ?? 0) > 0 && (float) ($cl['pieces'] ?? 0) >= max(1, (int) $k['minPieces'])) { $out[] = ['cle' => $k['cle'], 'lib' => $k['lib'], 'niveau' => 'seuil', 'montant' => (int) $k['magasin']]; }
        } elseif (($cl['rangMag'] ?? null) === 1) { $out[] = ['cle' => $k['cle'], 'lib' => $k['lib'], 'niveau' => 'magasin', 'montant' => (int) $k['magasin']]; }
    }
    // Un cadeau : le titre le nomme, sans euros (16/10/2026).
    foreach ($out as $i => $t) { foreach ($cfg['liste'] as $k) { if ($k['cle'] === $t['cle'] && !empty($k['cadeau'])) { $out[$i]['cadeau'] = $k['cadeau']['nom']; } } }
    return $out;
}

/** La prime d'une liste de familles pour une personne : la somme de ses titres (au centime : la part d'une prime du magasin). */
function concoursPrime(array $classements, int $id, array $cfg): int|float
{
    $s = round((float) array_sum(array_map(static fn ($t) => $t['montant'], concoursTitres($classements, $id, $cfg))), 2);
    return $s == floor($s) ? (int) $s : $s;
}

/**
 * La note Google (demande du 14/10/2026, à la place du coefficient du 06/10) : une prime de la liste de
 * l'app (src/primes_app.php, clé « google »), comme les autres. Quand la note de la fiche Google du
 * magasin tient son seuil (4,5 par défaut) ou mieux, chaque personne du magasin au planning du mois,
 * au-dessus du minimum d'heures, gagne la récompense (25 € par défaut). Sous le seuil, rien. La note
 * est celle que la Réputation tient (mar_avis_fiche).
 */
function googleReglages(): array
{
    $e = function_exists('primesAppEntree') ? primesAppEntree('google') : null;
    return ['actif' => (bool) ($e['actif'] ?? true), 'seuil' => (float) ($e['seuil'] ?? PRIMES_GOOGLE_SEUIL),
        'montant' => (int) ($e['montant'] ?? PRIMES_GOOGLE_MONTANT), 'heuresMin' => (int) ($e['heuresMin'] ?? 20)];
}

/** La note Google d'un magasin, telle que la Réputation la tient. Null sans fiche. */
function googleNote(string $shop): ?array
{
    static $memo = null;
    if ($memo === null) {
        $memo = [];
        try {
            foreach (Db::rows('SELECT shop_id, rating_avg, rating_count FROM mar_avis_fiche') as $r) {
                if ($r['rating_avg'] !== null) { $memo[(string) $r['shop_id']] = ['note' => round((float) $r['rating_avg'], 2), 'avis' => (int) ($r['rating_count'] ?? 0)]; }
            }
        } catch (PDOException $e) { /* pas de réputation : pas de note, pas de prime */ }
    }
    return $memo[$shop] ?? null;
}

/** La note d'un magasin face au seuil, prête à montrer : {actif, note, avis, seuil, montant, heuresMin, atteint}. */
function googleDuMagasin(string $shop): array
{
    $reg = googleReglages();
    $n = googleNote($shop);
    $note = $n['note'] ?? null;
    return ['actif' => $reg['actif'], 'note' => $note, 'avis' => $n['avis'] ?? 0, 'seuil' => $reg['seuil'], 'montant' => $reg['montant'],
        'heuresMin' => $reg['heuresMin'], 'atteint' => $reg['actif'] && $note !== null && $note + 1e-9 >= $reg['seuil']];
}

/**
 * La prime Google d'une personne : la récompense si la note de son magasin tient le seuil et qu'elle a ses heures.
 * Sans condition d'heures (heuresMin à 0, 17/10/2026), le planning n'est pas regardé.
 */
function googlePrime(array $g, ?float $heures): float
{
    $heuresOk = $g['heuresMin'] <= 0 || ($heures !== null && $heures + 1e-9 >= $g['heuresMin']);
    return ($g['atteint'] && $heuresOk) ? (float) $g['montant'] : 0.0;
}

/**
 * La brique Google d'une personne sur un mois : la note de son magasin face au seuil, ses heures au
 * planning, la récompense, et ce qu'elle gagne (« montant », comme les autres briques).
 */
function googleBrique(string $shop, ?float $heures): array
{
    $g = googleDuMagasin($shop);
    return ['actif' => $g['actif'], 'note' => $g['note'], 'avis' => $g['avis'], 'seuil' => $g['seuil'], 'recompense' => $g['montant'],
        'heuresMin' => $g['heuresMin'], 'atteint' => $g['atteint'], 'sousMin' => $heures !== null && $heures + 1e-9 < $g['heuresMin'],
        'montant' => googlePrime($g, $heures)];
}

/** La cible d'un magasin : la sienne, sinon celle du réseau. */
function croiseesCible(array $cfg, string $shop): float
{
    return (float) ($cfg['cibles'][$shop] ?? $cfg['cibleDefaut']);
}

/**
 * La prime ventes croisées d'une personne : la récompense si son taux du mois atteint la cible du
 * magasin (assez de tickets), sinon la cible à viser.
 *
 * @return array{montant:int,palier:?float,prochain:?array{taux:float,montant:int}}
 */
function croiseesPrime(?float $taux, float $cible, array $cfg, int $tickets): array
{
    $m = (int) ($cfg['montant'] ?? 0);
    $out = ['montant' => 0, 'palier' => null, 'prochain' => null];
    if ($m <= 0) { return $out; }
    $seuil = round($cible, 1);
    if ($tickets >= (int) $cfg['minTickets'] && $taux !== null && $taux + 1e-9 >= $seuil) { return ['montant' => $m, 'palier' => $seuil, 'prochain' => null]; }
    $out['prochain'] = ['taux' => $seuil, 'montant' => $m];
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
 * (semaine et week-end à part), le palier de la prime de l'objectif et ce qu'il manque.
 *
 * $heuresVous : les heures de la personne au planning du mois (null : seulement l'équipe).
 * $personnes : les personnes du magasin au-dessus du minimum d'heures (l'enveloppe de l'équipe).
 */
function primesMagasinMois(int $sid, string $m, array $reg, ?float $heuresVous, float $heuresEquipe, ?array $budget = null, int $personnes = 0): array
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
    // Sans condition d'heures (heuresMin à 0, 17/10/2026) : chacune du magasin touche sa part, planning ou pas.
    $sansHeures = (int) $reg['heuresMin'] <= 0;
    foreach ($reg['paliers'] as $p) {
        $cible = $objectif !== null ? $objectif * $p['pct'] / 100 : null;
        $manque = ($cible !== null && $projection !== null) ? max(0.0, $cible - $projection) : null;
        $paliers[] = ['pct' => $p['pct'], 'montant' => (int) $p['montant'], 'lib' => $p['lib'],
            'atteint' => $atteinteProj !== null && $atteinteProj + 1e-9 >= $p['pct'],
            'manque' => $manque !== null ? round($manque, 2) : null,
            'parJour' => ($manque !== null && $manque > 0 && $joursRestants > 0) ? round($manque / $joursRestants, 2) : null,
            'vous' => ($heuresVous !== null || $sansHeures) ? (float) $p['montant'] : null,
            'equipe' => (float) ($p['montant'] * $personnes)];
    }
    $sousMin = !$sansHeures && $heuresVous !== null && $heuresVous < $reg['heuresMin'];
    return ['objectif' => $objectif, 'objectifSource' => $bud['source'] ?? null, 'clos' => $clos,
        'ca' => round($ca, 2), 'atteinte' => $atteinte,
        'projection' => $projection, 'atteinteProj' => $atteinteProj,
        'moySem' => $moySem !== null ? round($moySem, 2) : null, 'moyWe' => $moyWe !== null ? round($moyWe, 2) : null,
        'joursRestants' => $joursRestants, 'objectifJour' => $objectif !== null ? round($objectif / $nbJours, 2) : null,
        'jours' => $jours, 'paliers' => $paliers,
        'palier' => $palier !== null ? ['pct' => $palier['pct'], 'montant' => (int) $palier['montant'], 'lib' => $palier['lib']] : null,
        'heures' => $heuresVous !== null ? round($heuresVous, 1) : null, 'heuresMin' => $reg['heuresMin'], 'sousMin' => $sousMin,
        'heuresEquipe' => round($heuresEquipe, 1), 'personnes' => $personnes,
        'part' => ($heuresVous !== null && $heuresEquipe > 0) ? round(100 * $heuresVous / $heuresEquipe, 1) : null,
        'vous' => ($palier !== null && ($sansHeures || ($heuresVous !== null && !$sousMin))) ? (float) $palier['montant'] : 0.0,
        'equipe' => $palier !== null ? (float) ($palier['montant'] * $personnes) : 0.0];
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
    // Le magasin de chacune vient du référentiel (table locale et panel) : une embauche récente a
    // déjà des heures au planning avant d'être dans la table locale, une jointure la perdrait.
    $emps = venteEmployes();
    $out = [];
    try {
        foreach (Db::rows('SELECT s.id_employee,
                                  SUM(' . $duree . ') / 3600 h,
                                  SUM(CASE WHEN s.work_date < ? THEN ' . $duree . ' ELSE 0 END) / 3600 hf,
                                  MAX(s.work_date) fin
                             FROM franchisee_employee_schedule s
                            WHERE s.work_date >= ? AND s.work_date < ?
                            GROUP BY s.id_employee',
            [date('Y-m-d'), substr($du, 0, 10), substr($au, 0, 10)]) as $r) {
            $id = (int) $r['id_employee'];
            $out[$id] = ['mois' => round((float) $r['h'], 2), 'faites' => round((float) $r['hf'], 2), 'shop' => (string) ($emps[$id]['shop'] ?? ''),
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
    $record = ['lt' => $lt, 'record' => $rec, 'recordMois' => $recMois !== null ? primesLibMois($recMois) : null, 'recordM' => $recMois,
        'ecart' => ($lt !== null && $rec !== null) ? round($lt - $rec, 2) : null,
        'tranches' => $prR['tranches'], 'montant' => $prR['prime'],
        'eurDixieme' => $regR['eurDixieme'], 'maxDixiemes' => $regR['maxDixiemes'], 'minTickets' => VENTE_CROSS_MIN_TICKETS,
        // Ses tickets du mois (23/10/2026) : l'app montre ce qu'il en manque pour poser, ou pour faire compter, le record.
        'tickets' => max($tickets, $tC), 'assez' => $assezR];

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
        'premierNom' => isset($duMag[0]) ? (string) ($duMag[0]['nom'] ?? '') : null,
        'secondNom' => isset($duMag[1]) ? (string) ($duMag[1]['nom'] ?? '') : null, 'scoreSecond' => $duMag[1]['score'] ?? null,
        'rangRes' => $rangRes, 'surRes' => count($classables),
        'montantMag' => $mv['magasin'], 'montantRes' => $mv['reseau'],
        'montant' => $rangRes === 1 ? $mv['reseau'] : ($rangMag === 1 ? $mv['magasin'] : 0),
        'motif' => $moi['motifHorsClassement'] ?? null];

    // Les catégories (les concours) : ses pièces de la famille, sa place dans le magasin et dans le réseau.
    $emps = venteEmployes();
    $cfgK = concoursReglages();
    $concours = primesBriquePieces($emp, $cfgK, concoursClassement($cr, $emps, $cfgK), $tC);
    $concours += ['jours' => (int) ($cr['joursTqShop'][(int) $shop] ?? 0), 'complet' => (bool) ($cr['tqComplet'] ?? false)];
    $concours['motif'] = $cr === null ? 'pas encore moissonné' : ($concours['pieces'] === null ? 'pièces pas encore comptées sur ces jours' : null);

    // Les promos de son magasin ce mois-là : leurs pièces sur leurs dates.
    $cfgP = ['actif' => true, 'liste' => promosConfig($m, $shop)];
    $promos = primesBriquePieces($emp, $cfgP, promosClassement($cr, $emps, $cfgP['liste']), $tC);
    $promos['actif'] = $cfgP['liste'] !== [];

    return ['croisees' => $croisees, 'record' => $record, 'meilleure' => $meilleure, 'concours' => $concours, 'promos' => $promos];
}

/**
 * Une brique « au nombre de pièces » (les catégories, les promos) pour une personne : chaque famille
 * avec ses pièces, sa place, la 1re, la récompense, et le titre si elle est 1re du magasin.
 */
function primesBriquePieces(int $emp, array $cfg, array $cls, int $tickets): array
{
    $titres = concoursTitres($cls, $emp, $cfg);
    $liste = [];
    foreach ($cfg['liste'] as $kc) {
        $k = $cls[$kc['cle']][$emp] ?? null;
        $titre = null; $gainT = 0; $partage = null;
        foreach ($titres as $t) { if ($t['cle'] === $kc['cle']) { $titre = $t['niveau']; $gainT = $t['montant']; $partage = $t['partage'] ?? null; } }
        $liste[] = ['cle' => $kc['cle'], 'lib' => $kc['lib'], 'motif' => $kc['motif'] ?? null,
            'pieces' => $k !== null ? $k['pieces'] : null, 'tickets' => $k['tickets'] ?? $tickets,
            'par100' => ($k !== null && ($k['tickets'] ?? 0) > 0) ? round(100 * $k['pieces'] / $k['tickets'], 1) : null,
            'rangMag' => $k['rangMag'] ?? null, 'surMag' => $k['surMag'] ?? 0, 'rangRes' => $k['rangRes'] ?? null, 'surRes' => $k['surRes'] ?? 0,
            'premierMag' => $k['premierMag'] ?? null, 'premierRes' => $k['premierRes'] ?? null,
            'montantMag' => (int) $kc['magasin'], 'montantRes' => 0, 'minPieces' => (int) $kc['minPieces'],
            'du' => $kc['du'] ?? null, 'au' => $kc['au'] ?? null, 'regle' => $kc['regle'] ?? 'premiere',
            'objectif' => $k !== null ? primesObjectifDe($kc, (string) ($k['shop'] ?? '')) : null, 'totalMag' => $k['totalMag'] ?? null,
            'titre' => $titre, 'montant' => $titre !== null ? $gainT : 0, 'partage' => $partage];
    }
    $piecesTot = 0.0; $unCompte = false;
    foreach ($liste as $x) { if ($x['pieces'] !== null) { $unCompte = true; $piecesTot += $x['pieces']; } }
    return ['actif' => (bool) $cfg['actif'], 'liste' => $liste, 'titres' => $titres,
        'montant' => concoursPrime($cls, $emp, $cfg), 'pieces' => $unCompte ? $piecesTot : null];
}

/** Les personnes d'un magasin au planning du mois, au-dessus du minimum d'heures : l'enveloppe de la prime de l'objectif. */
function primesPersonnesEligibles(array $h, string $shop, int $heuresMin): int
{
    $n = 0;
    foreach ($h as $x) { if ($x['shop'] === $shop && $x['mois'] >= $heuresMin) { $n++; } }
    return $n;
}

/**
 * Les classements du mois, à la Strava, pour le menu déroulant de l'app : une liste par prime qui en
 * a un (les catégories et les promos au nombre de pièces, les ventes croisées au taux), pour le
 * magasin et pour le réseau. Les dix premières et la personne elle-même si elle est plus loin. Les
 * noms sont ceux du panel, tels que les collègues se voient dans l'app. Une promo ne classe que les
 * magasins où elle tourne.
 *
 * @return list<array{cle: string, lib: string, type: string, unite: string, magasin: list<array>, reseau: list<array>}>
 */
function primesClassements(int $emp, string $shop, ?array $cr, array $emps, array $cfgK, array $cfgC, array $nomDe = [], array $promos = []): array
{
    $out = [];
    // La localité du magasin : ce qui suit le dernier « - » du nom (« Atelier by Berlo - Corbais » → « Corbais »).
    $lieu = static function (string $sid) use ($nomDe): string {
        $n = (string) ($nomDe[$sid] ?? '');
        if ($n === '') { return 'Magasin ' . $sid; }
        $p = strrpos($n, ' - ');
        return trim($p !== false ? substr($n, $p + 3) : $n);
    };
    $dix = static function (array $liste, int $emp): array {
        $top = array_slice($liste, 0, 10);
        $dedans = false;
        foreach ($top as $x) { if ($x['id'] === $emp) { $dedans = true; } }
        if (!$dedans) { foreach ($liste as $x) { if ($x['id'] === $emp) { $top[] = $x; } } }
        return $top;
    };
    // L'icône déposée par l'admin pour une prime (src/primes_app.php), sinon "" : l'app met l'emoji.
    $icone = static fn (string $cle): string => function_exists('primesIconeDe') ? primesIconeDe($cle) : '';
    $parPieces = static function (array $cls, array $k, string $type) use ($emps, $emp, $shop, $lieu, $dix, $icone): array {
        $tous = [];
        foreach ($cls[$k['cle']] ?? [] as $id => $x) {
            if ($x['pieces'] <= 0) { continue; }
            $tous[] = ['id' => (int) $id, 'nom' => $emps[$id]['nom'] ?? ('#' . $id), 'shop' => (string) $emps[$id]['shop'], 'lieu' => $lieu((string) $emps[$id]['shop']), 'pieces' => $x['pieces'], 'tickets' => $x['tickets'], 'moi' => (int) $id === $emp];
        }
        usort($tous, static fn ($a, $b) => [$b['pieces'], $a['tickets']] <=> [$a['pieces'], $b['tickets']]);
        $mag = array_values(array_filter($tous, static fn ($x) => $x['shop'] === $shop));
        foreach ($tous as $i => $x) { $tous[$i]['rang'] = $i + 1; }
        foreach ($mag as $i => $x) { $mag[$i]['rang'] = $i + 1; }
        return ['cle' => $k['cle'], 'lib' => $k['lib'], 'type' => $type, 'emo' => $type === 'promo' ? '🏷️' : '👑', 'icone' => $icone($k['cle']), 'unite' => 'pieces', 'minPieces' => (int) $k['minPieces'],
            'magasin' => $dix($mag, $emp), 'reseau' => $dix($tous, $emp), 'nMag' => count($mag), 'nRes' => count($tous)];
    };
    if ($cfgK['actif']) {
        $cls = concoursClassement($cr, $emps, $cfgK);
        foreach ($cfgK['liste'] as $k) { if ($k['classement'] ?? true) { $out[] = $parPieces($cls, $k, 'categorie'); } }
    }
    if ($promos !== []) {
        $cls = promosClassement($cr, $emps, $promos);
        foreach ($promos as $k) { if ($k['classement'] ?? true) { $out[] = $parPieces($cls, $k, 'promo'); } }
    }
    // Les ventes croisées : le taux, le minimum de tickets atteint.
    $eC = function_exists('primesAppEntree') ? primesAppEntree('croisees') : null;
    if ($eC === null || ($eC['actif'] && $eC['classement'])) {
        $tous = [];
        foreach ((array) ($cr['e'] ?? []) as $id => $x) {
            $id = (int) $id;
            if (!isset($emps[$id]) || !isset($x['c']) || (int) ($x['t'] ?? 0) < (int) $cfgC['minTickets'] || (int) $x['t'] === 0) { continue; }
            $tous[] = ['id' => $id, 'nom' => $emps[$id]['nom'], 'shop' => (string) $emps[$id]['shop'], 'lieu' => $lieu((string) $emps[$id]['shop']), 'taux' => round(100 * (int) $x['c'] / (int) $x['t'], 1), 'croisees' => (int) $x['c'], 'tickets' => (int) $x['t'], 'moi' => $id === $emp];
        }
        usort($tous, static fn ($a, $b) => [$b['taux'], $b['tickets']] <=> [$a['taux'], $a['tickets']]);
        $mag = array_values(array_filter($tous, static fn ($x) => $x['shop'] === $shop));
        foreach ($tous as $i => $x) { $tous[$i]['rang'] = $i + 1; }
        foreach ($mag as $i => $x) { $mag[$i]['rang'] = $i + 1; }
        $out[] = ['cle' => 'croisees', 'lib' => $eC['lib'] ?? 'Ventes croisées', 'categorie' => (string) ($cfgC['categorie'] ?? ''), 'type' => 'target', 'emo' => '🛍️', 'icone' => $icone('croisees'), 'unite' => 'taux', 'minTickets' => (int) $cfgC['minTickets'],
            'magasin' => $dix($mag, $emp), 'reseau' => $dix($tous, $emp), 'nMag' => count($mag), 'nRes' => count($tous)];
    }
    return $out;
}

/** Les montants d'une personne sur un mois, prime par prime, depuis les briques (la note Google comprise) et la prime de l'objectif. */
function primesMontants(array $b, array $pm): array
{
    return ['croisees' => (float) $b['croisees']['montant'], 'record' => (float) $b['record']['montant'], 'meilleure' => (float) $b['meilleure']['montant'],
        'concours' => (float) $b['concours']['montant'], 'promo' => (float) ($b['promos']['montant'] ?? 0), 'magasin' => (float) $pm['vous'],
        'google' => (float) ($b['google']['montant'] ?? 0)];
}

/**
 * Les titres d'un mois, depuis les briques : ce qu'elle collectionne. [{cle, niveau, lib, montant}] —
 * cle : croisees (niveau = le palier atteint, en %), record (niveau = les dixièmes), meilleure (magasin |
 * reseau), un concours par clé (magasin | reseau), magasin (niveau = le palier du magasin, en %).
 */
function primesTitres(array $b, ?array $pm = null): array
{
    $out = [];
    if (($b['croisees']['montant'] ?? 0) > 0) { $out[] = ['cle' => 'croisees', 'niveau' => (string) ($b['croisees']['palier'] ?? ''), 'lib' => 'Ventes croisées', 'montant' => (int) $b['croisees']['montant']]; }
    if (($b['record']['montant'] ?? 0) > 0) { $out[] = ['cle' => 'record', 'niveau' => (string) ($b['record']['tranches'] ?? 1), 'lib' => 'Record battu', 'montant' => (int) $b['record']['montant']]; }
    if (($b['meilleure']['rangRes'] ?? null) === 1) { $out[] = ['cle' => 'meilleure', 'niveau' => 'reseau', 'lib' => 'Meilleure vendeuse', 'montant' => (int) $b['meilleure']['montant']]; }
    elseif (($b['meilleure']['rangMag'] ?? null) === 1 && ($b['meilleure']['montant'] ?? 0) > 0) { $out[] = ['cle' => 'meilleure', 'niveau' => 'magasin', 'lib' => 'Meilleure vendeuse', 'montant' => (int) $b['meilleure']['montant']]; }
    foreach ((array) ($b['concours']['titres'] ?? []) as $t) { $out[] = ['cle' => 'concours:' . $t['cle'], 'niveau' => $t['niveau'], 'lib' => $t['lib'], 'montant' => (int) $t['montant']]; }
    foreach ((array) ($b['promos']['titres'] ?? []) as $t) { $out[] = ['cle' => 'promo:' . $t['cle'], 'niveau' => $t['niveau'], 'lib' => $t['lib'], 'montant' => (int) $t['montant']]; }
    if ($pm !== null && ($pm['vous'] ?? 0) > 0 && !empty($pm['palier'])) { $out[] = ['cle' => 'magasin', 'niveau' => (string) $pm['palier']['pct'], 'lib' => 'Prime magasin', 'montant' => (int) round($pm['vous'])]; }
    if (($b['google']['montant'] ?? 0) > 0) { $out[] = ['cle' => 'google', 'niveau' => (string) $b['google']['note'], 'lib' => 'Note Google', 'montant' => (int) $b['google']['montant']]; }
    return $out;
}

/**
 * La collection d'une personne : ses titres des six derniers mois clos, comptés par titre, et ceux
 * que le mois en cours lui donnerait. Gravée un jour (« primesColl{emp} »).
 *
 * @return array{depuis: string, mois: list<string>, titres: list<array{cle: string, niveau: string, lib: string, n: int, mois: list<string>, montant: int}>, enCours: list<array>}
 */
function primesCollection(int $emp, string $shop, array $nomDe, array $briquesEnCours, ?array $pmEnCours): array
{
    $cle = 'primesColl' . $emp;
    $c = setting($cle);
    $enCours = primesTitres($briquesEnCours, $pmEnCours);
    if (is_array($c) && isset($c['titres']) && (int) ($c['quand'] ?? 0) > time() - 86400) { $c['enCours'] = $enCours; unset($c['quand']); return $c; }
    $mois = [];
    for ($i = 1; $i <= 6; $i++) {
        $mF = date('Y-m', strtotime(date('Y-m') . '-01 -' . $i . ' month'));
        if ($mF < '2026-01') { break; }
        $mois[] = $mF;
    }
    $parTitre = [];
    foreach (array_reverse($mois) as $mF) {
        $clos = primesMoisClos($emp, $mF, $shop, $nomDe);
        foreach ((array) ($clos['titres'] ?? []) as $t) {
            $k = $t['cle'] . '|' . ($t['cle'] === 'meilleure' || str_starts_with($t['cle'], 'concours:') ? $t['niveau'] : '');
            if (!isset($parTitre[$k])) { $parTitre[$k] = ['cle' => $t['cle'], 'niveau' => $t['niveau'], 'lib' => $t['lib'], 'n' => 0, 'mois' => [], 'montant' => 0]; }
            $parTitre[$k]['n']++; $parTitre[$k]['mois'][] = $mF; $parTitre[$k]['montant'] += (int) $t['montant'];
            $parTitre[$k]['niveau'] = $t['niveau'];
        }
    }
    $out = ['depuis' => $mois !== [] ? end($mois) : date('Y-m'), 'mois' => array_reverse($mois), 'titres' => array_values($parTitre), 'quand' => time()];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out, JSON_UNESCAPED_UNICODE)]);
    unset($out['quand']);
    $out['enCours'] = $enCours;
    return $out;
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
    if (is_array($c) && isset($c['total'], $c['titres']) && array_key_exists('google', $c) && (int) ($c['quand'] ?? 0) > time() - PRIMES_CACHE_CLOS && (bool) ($c['paye'] ?? false) === $paye) { return $c; }

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
    $regM = primeMagasinReglages();
    $pm = primesMagasinMois((int) $shop, $m, $regM, $hVous, $hEq, null, primesPersonnesEligibles($h, $shop, (int) $regM['heuresMin']));
    $b['google'] = googleBrique($shop, $hVous);

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
    foreach (['concours', 'promos'] as $bq) {
        foreach ((array) ($b[$bq]['liste'] ?? []) as $kc) {
            if ($kc['pieces'] === null) { continue; }
            $detail[] = $kc['lib'] . ' : ' . str_replace('.', ',', (string) round($kc['pieces'])) . ' pièces'
                . ($kc['montant'] > 0 ? ' (1re du magasin) : ' . $kc['montant'] . ' €' : ($kc['rangMag'] !== null ? ', ' . $kc['rangMag'] . 'e du magasin' : ''));
        }
    }
    if ($pm['atteinte'] !== null) {
        $detail[] = 'magasin à ' . str_replace('.', ',', (string) $pm['atteinte']) . ' %'
            . ($pm['vous'] > 0 ? ' : ' . number_format($pm['vous'], 0, ',', ' ') . ' €' : '');
    }
    $g = $b['google'];
    if ($g['actif'] && $g['note'] !== null) {
        $detail[] = 'note Google ' . str_replace('.', ',', (string) $g['note']) . ' (seuil ' . str_replace('.', ',', (string) $g['seuil']) . ')'
            . ($g['montant'] > 0 ? ' : ' . number_format($g['montant'], 0, ',', ' ') . ' €' : '');
    }
    $total = round(array_sum(primesMontants($b, $pm)), 2);
    $out = ['m' => $m, 'lib' => primesLibMois($m), 'total' => $total, 'paye' => $paye, 'detail' => $detail,
        'croisees' => $b['croisees']['montant'], 'record' => $b['record']['montant'], 'meilleure' => $b['meilleure']['montant'], 'concours' => $b['concours']['montant'],
        'promo' => $b['promos']['montant'], 'magasin' => $pm['vous'], 'google' => $g['montant'], 'note' => $g['note'],
        'titres' => primesTitres($b, $pm), 'quand' => time()];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/**
 * La fiche « Mes primes » d'une personne pour un mois : une carte par prime (src/primes_app.php), ce
 * qui est acquis et ce qui est en jeu, les classements du menu déroulant ; et pour l'Accueil, le
 * compteur du jour, les briques, la collection, les douze dernières semaines. Les noms des autres sont
 * ceux des classements, rien de plus.
 */
function primesMoi(int $emp, string $m, bool $frais = false): array
{
    // Un mois à venir (16/10/2026) : rien à compter encore — les primes prévues, leur récompense, leur objectif.
    if ($m > date('Y-m') && function_exists('primesAVenir')) { return primesAVenir($emp, $m); }
    // Les primes de ce mois-là : une catégorie ou un target hors de sa période n'y est pas (16/10/2026).
    if (function_exists('primesAppMois')) { primesAppMois($m, true); }
    $cle = 'primesMoi' . $emp . ':' . $m;
    $enCours = $m === date('Y-m');
    if (!$frais) {
        $c = setting($cle);
        if (is_array($c) && isset($c['emp'], $c['cartes']) && ($c['v'] ?? 0) === PRIMES_FICHE_V && (int) ($c['quand'] ?? 0) > time() - ($enCours ? PRIMES_CACHE_MOI : PRIMES_CACHE_CLOS)) { return $c; }
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
    $regM = primeMagasinReglages();
    $pm = primesMagasinMois((int) $shop, $m, $regM, $hVous, $hEq, null, primesPersonnesEligibles($h, $shop, (int) $regM['heuresMin']));
    $pm['planningJusquau'] = $finPlan;
    $b['google'] = googleBrique($shop, $hVous);

    // Aujourd'hui : ses tickets et ses ventes croisées, lus au panel toutes les dix minutes (l'Accueil).
    $auj = null;
    if ($enCours && function_exists('pvLignesJourPersonne')) {
        $j = pvLignesJourPersonne((int) $shop, $emp);
        if ($j !== null) {
            $auj = ['tickets' => $j['t'], 'croisees' => $j['c'], 'lignes' => $j['l'],
                'taux' => $j['t'] > 0 ? round(100 * $j['c'] / $j['t'], 1) : null, 'quand' => date('H:i', $j['quand'])];
        }
    }

    // Les douze dernières semaines, avec sa place dans le réseau chaque semaine (l'Accueil, « Ma semaine »).
    $semaines12 = $enCours ? primesSemaines12($emp, (int) $shop) : [];

    $classements = primesClassements($emp, $shop, $cr, $emps, concoursReglages(), croiseesReglages(), $nomDe, promosConfig($m, $shop));
    $cartes = primesCartes($b, $pm, $classements, $enCours);

    // Ce qui est acquis au rythme actuel ; ce qui est en jeu : la récompense de chaque carte.
    $montants = primesMontants($b, $pm);
    $acquis = round(array_sum($montants), 2);
    $max = 0.0;
    foreach ($cartes as $c) { $max += max((float) $c['montant'], (float) $c['gain']); }
    $enJeu = $enCours ? max($acquis, round($max, 2)) : $acquis;

    $collection = primesCollection($emp, $shop, $nomDe, $b, $pm);

    $out = ['v' => PRIMES_FICHE_V, 'emp' => $emp, 'm' => $m, 'lib' => primesLibMois($m), 'enCours' => $enCours, 'quand' => time(),
        'magasin' => ['id' => $shop, 'nom' => $nomDe[$shop] ?? ('Magasin ' . $shop)], 'cartes' => $cartes, 'classements' => $classements, 'collection' => $collection,
        'heures' => ['mois' => round($hVous, 1), 'faites' => round($hFaites, 1), 'equipe' => round($hEq, 1), 'personnes' => $nEq, 'planningJusquau' => $finPlan],
        'aujourdhui' => $auj, 'acquis' => $acquis, 'enJeu' => $enJeu, 'aPortee' => $enJeu, 'google' => $b['google'], 'montants' => $montants,
        'briques' => $b, 'prime' => $pm, 'semaines12' => $semaines12, 'motif' => $r['motif']];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/** Les heures du planning d'une personne, semaine par semaine (lundi), entre deux dates. */
function primesHeuresSemaines(int $emp, string $du, string $au): array
{
    $duree = '((TIME_TO_SEC(s.end_hour) - TIME_TO_SEC(s.start_hour) + 86400) % 86400)';
    $out = [];
    try {
        foreach (Db::rows('SELECT YEARWEEK(s.work_date, 3) sem, SUM(' . $duree . ') / 3600 h
                             FROM franchisee_employee_schedule s
                            WHERE s.id_employee = ? AND s.work_date >= ? AND s.work_date <= ?
                            GROUP BY YEARWEEK(s.work_date, 3)', [$emp, $du, $au]) as $r) {
            $yw = (string) $r['sem'];
            $lundi = date('Y-m-d', strtotime(substr($yw, 0, 4) . 'W' . substr($yw, 4, 2)));
            $out[$lundi] = round((float) $r['h'], 2);
        }
    } catch (PDOException $e) { /* planning illisible : pas d'heures par semaine */ }
    return $out;
}

/**
 * Les moyennes des douze dernières semaines (16/10/2026, les lignes « mon magasin » et « réseau » du
 * graphique de Statistiques) : semaine par semaine, pour chaque magasin et pour le réseau, la vendeuse
 * moyenne — ventes croisées (Σ croisées ÷ Σ tickets comptés), lignes par ticket (Σ lignes ÷ Σ tickets),
 * tickets par vendeuse (celles qui ont vendu), ticket moyen (Σ CA ÷ Σ tickets) et CA par heure (Σ CA ÷ Σ
 * heures de celles qui ont vendu). Des moyennes, jamais le chiffre d'une collègue. Gravé une heure.
 *
 * @return array<string, array{mag: array<string, array>, res: array}> par lundi de semaine
 */
function primesSemainesMoyennes(): array
{
    $cle = 'primesS12moy';
    $c = setting($cle);
    if (is_array($c) && isset($c['s']) && (int) ($c['quand'] ?? 0) > time() - 3600) { return (array) $c['s']; }
    $reseau = function_exists('pvSemainesReseau') ? pvSemainesReseau(12) : [];
    if ($reseau === []) { return []; }
    $du = $reseau[0]['du']; $au = date('Y-m-d', strtotime('-1 day'));
    $emps = venteEmployes();
    // Les heures de chacune, semaine par semaine, en une lecture du planning.
    $heures = [];
    $duree = '((TIME_TO_SEC(s.end_hour) - TIME_TO_SEC(s.start_hour) + 86400) % 86400)';
    try {
        foreach (Db::rows('SELECT s.id_employee emp, YEARWEEK(s.work_date, 3) sem, SUM(' . $duree . ') / 3600 h
                             FROM franchisee_employee_schedule s
                            WHERE s.work_date >= ? AND s.work_date <= ?
                            GROUP BY s.id_employee, YEARWEEK(s.work_date, 3)', [$du, $au]) as $r) {
            $yw = (string) $r['sem'];
            $heures[date('Y-m-d', strtotime(substr($yw, 0, 4) . 'W' . substr($yw, 4, 2)))][(int) $r['emp']] = (float) $r['h'];
        }
    } catch (PDOException $e) { /* planning illisible : pas de CA par heure moyen */ }
    try { $shops = array_map(fn ($s) => (string) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { $shops = []; }
    $vide = static fn () => ['t' => 0, 'l' => 0, 'c' => 0, 'tc' => 0, 'vend' => 0, 'ca' => 0.0, 'tE' => 0, 'caH' => 0.0, 'h' => 0.0];
    $moyenne = static fn (array $a) => [
        'taux' => $a['tc'] > 0 ? round(100 * $a['c'] / $a['tc'], 1) : null,
        'lignesTicket' => ($a['t'] > 0 && $a['l'] > 0) ? round($a['l'] / $a['t'], 2) : null,
        'tickets' => $a['vend'] > 0 ? (int) round($a['t'] / $a['vend']) : null,
        'panier' => ($a['tE'] > 0 && $a['ca'] > 0) ? round($a['ca'] / $a['tE'], 2) : null,
        'caHeure' => ($a['h'] > 0 && $a['caH'] > 0) ? (int) round($a['caH'] / $a['h']) : null];
    $out = [];
    foreach ($reseau as $sem) {
        $mag = []; $res = $vide();
        // Les tickets, les lignes, les ventes croisées : la moisson de la semaine.
        foreach ((array) ($sem['e'] ?? []) as $id => $x) {
            $sid = (string) ($x['shop'] ?? ''); $mag[$sid] ??= $vide();
            foreach (['t', 'l', 'c', 'tc'] as $k) { $mag[$sid][$k] += (int) ($x[$k] ?? 0); $res[$k] += (int) ($x[$k] ?? 0); }
            if ((int) ($x['t'] ?? 0) > 0) { $mag[$sid]['vend']++; $res['vend']++; }
        }
        // Le CA et ses tickets : les ventes par vendeuse de chaque jour (pvE, moissonnées par le cron).
        $caEmp = [];
        foreach ($shops as $sid) {
            for ($k = 0; $k < 7; $k++) {
                $j = date('Y-m-d', strtotime($sem['du'] . ' +' . $k . ' days'));
                if ($j >= date('Y-m-d')) { break; }
                $v = setting('pvE' . $sid . ':' . $j);
                if (!is_array($v) || !isset($v['e'])) { continue; }
                foreach ((array) $v['e'] as $id => $y) {
                    if ((float) ($y['ca'] ?? 0) <= 0) { continue; }
                    $caEmp[$sid][(int) $id]['ca'] = ($caEmp[$sid][(int) $id]['ca'] ?? 0.0) + (float) $y['ca'];
                    $caEmp[$sid][(int) $id]['t'] = ($caEmp[$sid][(int) $id]['t'] ?? 0) + (int) ($y['t'] ?? 0);
                }
            }
        }
        foreach ($caEmp as $sid => $parEmp) {
            $mag[$sid] ??= $vide();
            foreach ($parEmp as $id => $y) {
                $h = (float) ($heures[$sem['du']][$id] ?? 0);
                $mag[$sid]['ca'] += $y['ca']; $mag[$sid]['tE'] += $y['t']; $res['ca'] += $y['ca']; $res['tE'] += $y['t'];
                // Le CA par heure ne compte que les heures de celles qui ont vendu, et leur CA à elles seules.
                if ($h > 0) { $mag[$sid]['h'] += $h; $res['h'] += $h; $mag[$sid]['caH'] += $y['ca']; $res['caH'] += $y['ca']; }
            }
        }
        $out[$sem['du']] = ['mag' => array_map($moyenne, $mag), 'res' => $moyenne($res)];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['quand' => time(), 's' => $out], JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/**
 * Les douze dernières semaines d'une personne : ses ventes croisées (taux) et sa place au taux
 * parmi les vendeuses du réseau (dix tickets au moins la semaine), ses pièces du concours et sa
 * place, son CA, ses heures, son CA par heure. Mis en cache une heure.
 */
function primesSemaines12(int $emp, int $sid): array
{
    // v4 (16/10/2026) : chaque semaine porte son ticket moyen, ses lignes par ticket, et les moyennes de son
    // magasin (« mag ») et du réseau (« res ») pour les mêmes indicateurs.
    $cle = 'primesS12v4:' . $emp;
    $c = setting($cle);
    if (is_array($c) && isset($c['s']) && (int) ($c['quand'] ?? 0) > time() - 3600) { return (array) $c['s']; }
    $reseau = function_exists('pvSemainesReseau') ? pvSemainesReseau(12) : [];
    if ($reseau === []) { return []; }
    $du = $reseau[0]['du']; $au = date('Y-m-d', strtotime('-1 day'));
    $heures = primesHeuresSemaines($emp, $du, $au);
    // Les ventes par jour de son magasin : les jours manquants se lisent maintenant, trente au plus,
    // le cron complète le reste.
    if (function_exists('pvVentesJour')) {
        $manquants = pvVentesJoursManquants($sid, $du, $au);
        foreach (array_slice(array_reverse($manquants), 0, 30) as $j) { pvVentesJour($sid, $j); }
    }
    $cfgK = concoursReglages();
    $moy = primesSemainesMoyennes();
    $out = [];
    foreach ($reseau as $sem) {
        $e = (array) ($sem['e'] ?? []);
        $moi = $e[$emp] ?? ($e[(string) $emp] ?? null);
        // La place au taux de ventes croisées, dix tickets au moins.
        $taux = []; $tq = [];
        foreach ($e as $id => $x) {
            if ((int) ($x['tc'] ?? $x['t'] ?? 0) >= 10) { $taux[(int) $id] = 100 * (int) $x['c'] / (int) ($x['tc'] ?? $x['t']); }
            if ((float) ($x['tq'] ?? 0) > 0) { $tq[(int) $id] = (float) $x['tq']; }
        }
        $tcMoi = $moi !== null ? (int) ($moi['tc'] ?? $moi['t']) : 0;
        $monTaux = ($moi !== null && $tcMoi >= 10) ? round(100 * (int) $moi['c'] / $tcMoi, 1) : null;
        $rang = $monTaux !== null ? 1 + count(array_filter($taux, static fn ($v) => $v > $monTaux + 1e-9)) : null;
        $mesTq = $moi !== null ? round((float) ($moi['tq'] ?? 0), 1) : null;
        $rangTq = ($mesTq !== null && $mesTq > 0) ? 1 + count(array_filter($tq, static fn ($v) => $v > $mesTq + 1e-9)) : null;
        // Le CA de la semaine : ses jours dans son magasin.
        $ca = 0.0; $tickets = 0; $joursCa = 0;
        for ($k = 0; $k < 7; $k++) {
            $j = date('Y-m-d', strtotime($sem['du'] . ' +' . $k . ' days'));
            if ($j >= date('Y-m-d')) { break; }
            $v = setting('pvE' . $sid . ':' . $j);
            if (!is_array($v) || !isset($v['e'])) { continue; }
            $joursCa++;
            $y = $v['e'][$emp] ?? ($v['e'][(string) $emp] ?? null);
            if (is_array($y)) { $ca += (float) ($y['ca'] ?? 0); $tickets += (int) ($y['t'] ?? 0); }
        }
        $h = (float) ($heures[$sem['du']] ?? 0.0);
        $out[] = ['lib' => $sem['lib'], 'du' => $sem['du'], 'jours' => (int) $sem['jours'],
            'tickets' => $moi !== null ? (int) $moi['t'] : 0, 'croisees' => $moi !== null ? (int) $moi['c'] : 0, 'taux' => $monTaux,
            'rang' => $rang, 'sur' => count($taux),
            'pieces' => $cfgK['actif'] ? $mesTq : null, 'rangPieces' => $cfgK['actif'] ? $rangTq : null, 'surPieces' => count($tq),
            'ca' => $joursCa > 0 ? round($ca, 2) : null, 'heures' => round($h, 1),
            'caHeure' => ($joursCa > 0 && $h > 0 && $ca > 0) ? (int) round($ca / $h) : null,
            'panier' => ($tickets > 0 && $ca > 0) ? round($ca / $tickets, 2) : null,
            'lignesTicket' => ($moi !== null && (int) $moi['t'] > 0 && (int) ($moi['l'] ?? 0) > 0) ? round((int) $moi['l'] / (int) $moi['t'], 2) : null,
            'mag' => $moy[$sem['du']]['mag'][(string) $sid] ?? null, 'res' => $moy[$sem['du']]['res'] ?? null];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['quand' => time(), 's' => $out], JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/* ======================================================================
   Tout le monde sur un mois : ce que l'app montre à chacune, et ce que le CEO enregistre
   ====================================================================== */

/**
 * Les primes d'un mois pour toutes les personnes de vente, magasin par magasin : les quatre
 * règles, les totaux. C'est la lecture du CEO (Équipe & ventes › Résultats) et ce qu'enregistre
 * « Enregistrer les primes ». Mise en cache une heure (le mois en cours) ou un jour (mois clos).
 */
function primesMoisTous(string $m, bool $frais = false): array
{
    if (function_exists('primesAppMois')) { primesAppMois($m, true); }
    $cle = 'primesTous' . $m;
    $enCours = $m === date('Y-m');
    if (!$frais) {
        $c = setting($cle);
        if (is_array($c) && isset($c['personnes'], $c['totaux']['google']) && (int) ($c['quand'] ?? 0) > time() - ($enCours ? 3600 : PRIMES_CACHE_CLOS)) { return $c; }
    }
    $nomDe = primesMagasins();
    $emps = venteEmployes();
    $r = venteMoisCache($m, $nomDe);
    $parMois = [];
    foreach (venteFenetreRecord($m) as $mF) { $rF = venteMoisCache($mF, $nomDe); $parMois[$mF] = $rF['motif'] === null ? $rF['lignes'] : null; }
    $cr = function_exists('pvCroiseesMois') ? pvCroiseesMois($m) : null;
    $h = primesHeuresMois($m);
    $regM = primeMagasinReglages(); $cfgC = croiseesReglages(); $regR = venteRecordReglages(); $mv = ventePrimesConfig();
    $cfgK = concoursReglages();
    $classK = concoursClassement($cr, $emps, $cfgK);
    // Les promos du mois : chacune ne classe que ses magasins.
    $cfgP = ['actif' => true, 'liste' => promosConfig($m)];
    $classP = promosClassement($cr, $emps, $cfgP['liste']);
    $g = venteGagnantes($r['lignes']);
    $reseauId = $g['reseau']['id'] ?? null;
    $magId = [];
    foreach ($g['magasins'] as $x) { if ($x['id'] !== $reseauId) { $magId[(string) $x['shopId']] = $x['id']; } }
    $budgets = primesBudgets($m);
    $regG = googleReglages();

    // Les magasins : heures de l'équipe, objectif, palier.
    $magasins = []; $hEq = []; $nEq = [];
    foreach ($h as $id => $x) { $hEq[$x['shop']] = ($hEq[$x['shop']] ?? 0.0) + $x['mois']; $nEq[$x['shop']] = ($nEq[$x['shop']] ?? 0) + 1; }
    foreach ($nomDe as $sid => $nom) {
        $nElig = primesPersonnesEligibles($h, (string) $sid, (int) $regM['heuresMin']);
        $pm = primesMagasinMois((int) $sid, $m, $regM, null, (float) ($hEq[$sid] ?? 0.0), $budgets[$sid] ?? null, $nElig);
        // La note Google : la récompense pour chacune au planning au-dessus de son minimum d'heures, si la note tient le seuil.
        $gm = googleDuMagasin((string) $sid);
        $gm['eligibles'] = primesPersonnesEligibles($h, (string) $sid, (int) $regG['heuresMin']);
        $gm['equipe'] = $gm['atteint'] ? (float) ($gm['montant'] * $gm['eligibles']) : 0.0;
        $magasins[$sid] = ['id' => (string) $sid, 'nom' => $nom, 'parPayeur' => [], 'objectif' => $pm['objectif'], 'ca' => $pm['ca'],
            'atteinte' => $pm['atteinte'], 'atteinteProj' => $pm['atteinteProj'], 'palier' => $pm['palier'],
            'heuresEquipe' => $pm['heuresEquipe'], 'personnes' => (int) ($nEq[$sid] ?? 0), 'eligibles' => $nElig, 'equipe' => $pm['equipe'], 'cible' => croiseesCible($cfgC, (string) $sid),
            'google' => $gm];
    }

    // Les personnes : toutes celles qui ont une ligne, des heures ou des tickets ce mois-là.
    $ligneDe = [];
    foreach ($r['lignes'] as $l) { $ligneDe[(int) $l['id']] = $l; }
    $ids = array_unique(array_merge(array_keys($ligneDe), array_keys($h), array_map('intval', array_keys($cr['e'] ?? []))));
    $personnes = [];
    foreach ($ids as $id) {
        if (!isset($emps[$id])) { continue; }
        $shop = (string) $emps[$id]['shop'];
        $l = $ligneDe[$id] ?? null;
        $x = $cr['e'][$id] ?? null;
        $tC = $x !== null ? (int) $x['t'] : 0;
        $cC = ($x !== null && isset($x['c'])) ? (int) $x['c'] : null;
        $taux = ($cC !== null && $tC > 0) ? round(100 * $cC / $tC, 1) : null;
        $prC = croiseesPrime($taux, croiseesCible($cfgC, $shop), $cfgC, $tC);
        $lt = $l['lignesTicket'] ?? null;
        if ($lt === null && $x !== null && $tC > 0 && isset($x['l'])) { $lt = round((int) $x['l'] / $tC, 2); }
        $tickets = max((int) ($l['tickets'] ?? 0), $tC);
        $rec = venteRecordVendeuse($parMois, (string) $id, $m);
        $prR = ($lt !== null && $tickets >= VENTE_CROSS_MIN_TICKETS) ? venteRecordPrime((float) $lt, $rec, $regR['eurDixieme'], $regR['maxDixiemes']) : ['tranches' => 0, 'prime' => 0];
        $meilleure = $id === $reseauId ? $mv['reseau'] : ((($magId[$shop] ?? null) === $id) ? $mv['magasin'] : 0);
        $heures = (float) ($h[$id]['mois'] ?? ($l['heures'] ?? 0.0));
        $pal = $magasins[$shop]['palier'] ?? null;
        $montantMag = ($pal !== null && ($h[$id]['mois'] ?? 0.0) >= $regM['heuresMin']) ? (float) $pal['montant'] : 0.0;
        $titresK = concoursTitres($classK, $id, $cfgK);
        $montantK = concoursPrime($classK, $id, $cfgK);
        $piecesK = [];
        foreach ($cfgK['liste'] as $kc) { if (isset($classK[$kc['cle']][$id])) { $piecesK[$kc['cle']] = $classK[$kc['cle']][$id]['pieces']; } }
        $titresP = concoursTitres($classP, $id, $cfgP);
        $montantP = concoursPrime($classP, $id, $cfgP);
        $piecesP = [];
        foreach ($cfgP['liste'] as $kc) { if (isset($classP[$kc['cle']][$id])) { $piecesP[$kc['cle']] = $classP[$kc['cle']][$id]['pieces']; } }
        $gm = $magasins[$shop]['google'] ?? googleDuMagasin($shop);
        $montantG = googlePrime($gm, (float) ($h[$id]['mois'] ?? 0.0));
        $total = round($prC['montant'] + $prR['prime'] + $meilleure + $montantK + $montantP + $montantMag + $montantG, 2);
        // Qui paie quoi (14/10/2026) : la marque, le magasin ou un sponsor, prime par prime.
        $pp = []; $ppApp = [];
        $ajoute = static function (string $payeur, float $v, bool $app) use (&$pp, &$ppApp) {
            if ($v <= 0) { return; }
            $pp[$payeur] = round(($pp[$payeur] ?? 0) + $v, 2);
            if ($app) { $ppApp[$payeur] = round(($ppApp[$payeur] ?? 0) + $v, 2); }
        };
        $ajoute(primesPayeurDe('croisees'), (float) $prC['montant'], true);
        $ajoute(primesPayeurDe('objectif'), $montantMag, true);
        $ajoute(primesPayeurDe('google'), $montantG, true);
        foreach ($titresK as $t) { $ajoute(primesPayeurDe($t['cle']), (float) $t['montant'], true); }
        foreach ($titresP as $t) { $ajoute(primesPayeurDe($t['cle']), (float) $t['montant'], true); }
        $ajoute(primesPayeurDe('record'), (float) $prR['prime'], false);
        $ajoute(primesPayeurDe('meilleure'), (float) $meilleure, false);
        foreach ($pp as $k => $v) { $magasins[$shop]['parPayeur'][$k] = round(($magasins[$shop]['parPayeur'][$k] ?? 0) + $v, 2); }
        $personnes[] = ['id' => $id, 'nom' => $emps[$id]['nom'], 'shopId' => $shop, 'magasinNom' => $nomDe[$shop] ?? ('Magasin ' . $shop),
            'heures' => round($heures, 1), 'tickets' => $tickets, 'ca' => (int) ($l['ca'] ?? 0), 'score' => $l['score'] ?? null, 'rang' => $l['rang'] ?? null,
            'croisees' => ['croisees' => $cC, 'tickets' => $tC, 'taux' => $taux, 'montant' => $prC['montant']],
            'record' => ['lt' => $lt, 'record' => $rec, 'tranches' => $prR['tranches'], 'montant' => $prR['prime']],
            'meilleure' => $meilleure,
            'concours' => ['pieces' => $piecesK !== [] ? $piecesK : null, 'titres' => $titresK, 'montant' => $montantK],
            'promo' => ['pieces' => $piecesP !== [] ? $piecesP : null, 'titres' => $titresP, 'montant' => $montantP],
            'magasin' => $montantMag, 'google' => $montantG, 'total' => $total,
            'parPayeur' => $pp, 'parPayeurApp' => $ppApp];
    }
    usort($personnes, static fn ($a, $b) => [$a['magasinNom'], -$a['total'], $a['nom']] <=> [$b['magasinNom'], -$b['total'], $b['nom']]);
    $totaux = ['croisees' => 0.0, 'record' => 0.0, 'meilleure' => 0.0, 'concours' => 0.0, 'promo' => 0.0, 'magasin' => 0.0, 'google' => 0.0, 'total' => 0.0, 'personnes' => 0, 'parPayeur' => []];
    foreach ($personnes as $p) {
        foreach ($p['parPayeur'] as $k => $v) { $totaux['parPayeur'][$k] = round(($totaux['parPayeur'][$k] ?? 0) + $v, 2); }
        $totaux['croisees'] += $p['croisees']['montant']; $totaux['record'] += $p['record']['montant'];
        $totaux['meilleure'] += $p['meilleure']; $totaux['concours'] += $p['concours']['montant']; $totaux['promo'] += $p['promo']['montant'];
        $totaux['magasin'] += $p['magasin']; $totaux['google'] += $p['google']; $totaux['total'] += $p['total'];
        if ($p['total'] > 0) { $totaux['personnes']++; }
    }
    $out = ['m' => $m, 'lib' => primesLibMois($m), 'enCours' => $enCours, 'quand' => time(), 'motif' => $r['motif'],
        'croiseesComplet' => (bool) ($cr['complet'] ?? false), 'croiseesJours' => (int) ($cr['jours'] ?? 0),
        'concours' => ['actif' => (bool) $cfgK['actif'], 'liste' => $cfgK['liste'], 'complet' => (bool) ($cr['tqComplet'] ?? false)],
        'promos' => ['actif' => $cfgP['liste'] !== [], 'liste' => $cfgP['liste']],
        'google' => googleReglages(),
        'magasins' => array_values($magasins), 'personnes' => $personnes, 'totaux' => $totaux];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/** GET /ventes/primes-mois?m= — tout le monde sur le mois, et ce qui est déjà enregistré. */
function ep_primes_mois(): array
{
    $m = trim((string) ($_GET['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m) || $m > date('Y-m')) { $m = date('Y-m', strtotime('first day of last month')); }
    $out = primesMoisTous($m, isset($_GET['frais']));
    $hist = setting('ventePrimesHist');
    $out['enregistre'] = is_array($hist) && isset($hist[$m]) ? ['quand' => $hist[$m]['quand'] ?? null, 'appWorker' => $hist[$m]['appWorker'] ?? null] : null;
    return $out;
}

/**
 * Ce que « Enregistrer les primes » grave pour l'app worker, en plus des gagnantes : les primes de
 * l'app de chacune — ventes croisées, catégories, promos, objectif magasin, note Google — (identifiants et
 * montants, jamais de nom), et une ligne au journal par personne primée. Le record et la meilleure
 * vendeuse ont leurs enregistrements à eux.
 */
function primesEnregistrer(string $m): array
{
    $t = primesMoisTous($m, true);
    $enr = ['quand' => date('Y-m-d H:i'), 'magasins' => [], 'personnes' => [], 'total' => 0.0, 'parPayeur' => []];
    foreach ($t['magasins'] as $mg) {
        $enr['magasins'][] = ['id' => $mg['id'], 'atteinte' => $mg['atteinte'], 'palier' => $mg['palier'] !== null ? $mg['palier']['pct'] : null,
            'montant' => $mg['palier'] !== null ? $mg['palier']['montant'] : null, 'eligibles' => $mg['eligibles'], 'heuresEquipe' => $mg['heuresEquipe'], 'equipe' => $mg['equipe'],
            'google' => $mg['google']['note'] ?? null, 'googleAtteint' => (bool) ($mg['google']['atteint'] ?? false), 'googleEligibles' => (int) ($mg['google']['eligibles'] ?? 0)];
    }
    foreach ($t['personnes'] as $p) {
        $a = ['croisees' => (float) $p['croisees']['montant'], 'concours' => (float) $p['concours']['montant'], 'promo' => (float) $p['promo']['montant'],
            'magasin' => (float) $p['magasin'], 'google' => (float) $p['google']];
        $app = round(array_sum($a), 2);
        if ($app <= 0) { continue; }
        $enr['personnes'][] = ['id' => $p['id'], 'shopId' => $p['shopId'], 'heures' => $p['heures'],
            'croisees' => $a['croisees'], 'tauxCroisees' => $p['croisees']['taux'],
            'concours' => $a['concours'], 'pieces' => $p['concours']['pieces'], 'titres' => array_map(static fn ($t) => $t['cle'] . ':' . $t['niveau'], (array) ($p['concours']['titres'] ?? [])),
            'promo' => $a['promo'], 'piecesPromo' => $p['promo']['pieces'], 'titresPromo' => array_map(static fn ($t) => $t['cle'], (array) ($p['promo']['titres'] ?? [])),
            'magasin' => $a['magasin'], 'google' => $a['google'], 'total' => $app, 'parPayeur' => $p['parPayeurApp']];
        $enr['total'] += $app;
        foreach ($p['parPayeurApp'] as $k => $v) { $enr['parPayeur'][$k] = round(($enr['parPayeur'][$k] ?? 0) + $v, 2); }
        $parts = [];
        if ($a['croisees'] > 0) { $parts[] = 'ventes croisées ' . number_format($a['croisees'], 2, ',', ' ') . ' € (' . str_replace('.', ',', (string) $p['croisees']['taux']) . ' %)'; }
        if ($a['concours'] > 0) { $parts[] = 'catégories ' . number_format($a['concours'], 2, ',', ' ') . ' € (' . implode(', ', array_map(static fn ($t) => $t['lib'], (array) ($p['concours']['titres'] ?? []))) . ', 1re du magasin)'; }
        if ($a['promo'] > 0) { $parts[] = 'promos ' . number_format($a['promo'], 2, ',', ' ') . ' € (' . implode(', ', array_map(static fn ($t) => $t['lib'], (array) ($p['promo']['titres'] ?? []))) . ', 1re du magasin)'; }
        if ($a['magasin'] > 0) { $parts[] = 'objectif magasin ' . number_format($a['magasin'], 2, ',', ' ') . ' €'; }
        if ($a['google'] > 0) { $parts[] = 'note Google ' . number_format($a['google'], 2, ',', ' ') . ' €'; }
        $payes = implode(', ', array_map(static fn ($k, $v) => number_format($v, 2, ',', ' ') . ' € ' . (str_starts_with($k, 'sponsor:') ? 'sponsor ' . substr($k, 8) : $k), array_keys($p['parPayeurApp']), $p['parPayeurApp']));
        journalAdd('CEO', 'Vente', $p['nom'], 'Primes app worker ' . $m . ' — ' . implode(' · ', $parts) . ' — payé par : ' . $payes . ' — ' . $p['magasinNom']);
    }
    $enr['total'] = round($enr['total'], 2);
    // La réussite est acquise : le SMS part, s'il est activé (une fois par mois et par personne).
    if (function_exists('primesSmsReussite')) {
        try { $enr['sms'] = primesSmsReussite($m, $t['personnes']); } catch (Throwable $e) { $enr['sms'] = ['erreur' => $e->getMessage()]; }
    }
    return $enr;
}

/* ======================================================================
   Les routes
   ====================================================================== */

/** Le mois demandé, AAAA-MM, le mois en cours par défaut ; jamais au-delà. */
function primesMoisDemande(): string
{
    $m = trim((string) ($_GET['m'] ?? ''));
    // Les mois à venir (16/10/2026) : trois au plus, pour voir les primes prévues.
    if (!preg_match('/^\d{4}-\d{2}$/', $m) || $m > date('Y-m', strtotime(date('Y-m-01') . ' +' . PRIMES_MOIS_A_VENIR . ' months'))) { $m = date('Y-m'); }
    return $m;
}

/**
 * La personne, dans le référentiel du cockpit : par son identifiant du panel, sinon par son nom
 * d'affichage (le même que celui des tickets). Null : inconnue.
 */
function primesResoudreEmploye(int $id, string $nom): ?int
{
    $emps = venteEmployes();
    if ($id > 0 && isset($emps[$id])) { return $id; }
    $n = mb_strtolower(trim($nom));
    if ($n === '') { return null; }
    foreach ($emps as $eid => $e) { if (mb_strtolower(trim((string) $e['nom'])) === $n) { return (int) $eid; } }
    // Le prénom seul (« yousra » pour « Yousra B. ») : seulement s'il ne désigne qu'une personne.
    $prenom = explode(' ', $n)[0];
    $trouves = [];
    foreach ($emps as $eid => $e) { if (explode(' ', mb_strtolower(trim((string) $e['nom'])))[0] === $prenom) { $trouves[] = (int) $eid; } }
    return count($trouves) === 1 ? $trouves[0] : null;
}

/** La requête se présente-t-elle comme l'app worker (identité signée ou jeton) ? Alors la voie publique répond, 401 compris. */
function primesVoieApp(): bool
{
    return trim((string) ($_SERVER['HTTP_X_WORKER_SIGN'] ?? '')) !== '' || primesJetonRecu() !== null;
}

/** GET /ventes/moi?emp=&m= (ou ?nom=) — depuis le cockpit (session), pour voir ce que l'app montre. */
function ep_ventes_moi(): array
{
    $emp = (int) ($_GET['emp'] ?? 0);
    $nom = trim((string) ($_GET['nom'] ?? ''));
    if ($emp <= 0 && $nom === '') { http_response_code(400); return ['error' => 'emp ou nom attendu']; }
    $emp = primesResoudreEmploye($emp, $nom);
    if ($emp === null) { http_response_code(404); return ['error' => 'personne inconnue']; }
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

/**
 * Le secret partagé entre l'app worker et le cockpit : ils lisent le même config/config.php, le
 * secret s'en déduit et ne circule jamais. L'app signe l'identité de la personne connectée
 * (son identifiant et son nom, tels que son jeton les porte) avec le jour : le cockpit vérifie
 * la signature, sans aller-retour chez le panel.
 */
function primesSecret(): string
{
    $db = method_exists('Db', 'config') ? (array) (Db::config()['db'] ?? []) : [];
    return hash('sha256', 'primes|' . ($db['host'] ?? '') . '|' . ($db['name'] ?? '') . '|' . ($db['user'] ?? '') . '|' . ($db['password'] ?? ''));
}

function primesSignature(int $emp, string $nom, string $jour): string
{
    return hash_hmac('sha256', $emp . '|' . $nom . '|' . $jour, primesSecret());
}

/** L'identité signée portée par la requête (X-Worker-Emp, X-Worker-Nom en base64, X-Worker-Jour, X-Worker-Sign). */
function primesIdentiteSignee(): ?array
{
    $sign = trim((string) ($_SERVER['HTTP_X_WORKER_SIGN'] ?? ''));
    if ($sign === '') { return null; }
    $emp = (int) ($_SERVER['HTTP_X_WORKER_EMP'] ?? 0);
    $nom = trim((string) base64_decode((string) ($_SERVER['HTTP_X_WORKER_NOM'] ?? ''), true));
    $jour = trim((string) ($_SERVER['HTTP_X_WORKER_JOUR'] ?? ''));
    if (!in_array($jour, [date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))], true)) { return null; }
    if (!hash_equals(primesSignature($emp, $nom, $jour), $sign)) { return null; }
    return ['emp' => $emp, 'nom' => $nom];
}

/** GET /ventes/moi avec l'identité signée ou le jeton de l'app worker : la fiche de la personne connectée, personne d'autre. */
function ep_ventes_moi_jeton(): array
{
    $ident = primesIdentiteSignee();
    if ($ident === null) {
        $jeton = primesJetonRecu();
        $e = $jeton !== null ? primesEmployeDuJeton($jeton) : null;
        if ($e !== null) { $ident = ['emp' => $e, 'nom' => '']; }
    }
    if ($ident === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $emp = primesResoudreEmploye((int) $ident['emp'], (string) $ident['nom']);
    if ($emp === null) {
        http_response_code(404);
        return ['error' => 'personne inconnue du cockpit' . ($ident['nom'] !== '' ? ' : ' . $ident['nom'] : '') . ' (id ' . (int) $ident['emp'] . ')'];
    }
    $r = primesMoi($emp, primesMoisDemande(), isset($_GET['frais']));
    if (isset($r['error'])) { http_response_code((int) ($r['code'] ?? 500)); return ['error' => $r['error']]; }
    return $r;
}

/**
 * GET /ventes/primes-reglages — les réglages des primes : la liste des primes de l'app et sa
 * récompense par défaut, les cibles de ventes croisées, la note Google de chaque magasin, et les montants de Bats ton
 * record et de la meilleure vendeuse (réglés dans l'onglet Targets & primes), les magasins.
 * Les moyennes par magasin et les promotions du panel ont leurs routes (primes-moyennes, primes-promos).
 */
function ep_primes_reglages(): array
{
    $nomDe = primesMagasins();
    return ['app' => primesAppReglages(), 'smsConfigure' => function_exists('smsConfigure') && smsConfigure(), 'categories' => primesCategories(),
        'magasin' => primeMagasinReglages(), 'croisees' => croiseesReglages(), 'concours' => concoursReglages(),
        'google' => googleReglages() + ['notes' => array_map(static fn ($id) => googleNote((string) $id)['note'] ?? null, array_combine(array_map('strval', array_keys($nomDe)), array_keys($nomDe)))],
        'record' => venteRecordReglages(), 'meilleure' => ventePrimesConfig(),
        'magasins' => array_map(static fn ($id, $n) => ['id' => (string) $id, 'nom' => $n], array_keys($nomDe), $nomDe),
        'm' => date('Y-m'), 'lib' => primesLibMois(date('Y-m'))];
}
