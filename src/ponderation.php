<?php
declare(strict_types=1);

/**
 * POIDS DES JOURS DE LA SEMAINE — la clé qui transforme un budget mensuel en
 * objectif quotidien.
 *
 * L'objectif d'un magasin est posé au MOIS (le budget validé). Pour dire à un
 * gérant « aujourd'hui il vous manque 71 clients », il faut savoir ce que le
 * jour d'aujourd'hui pèse dans la semaine : un jeudi ne vaut pas un samedi.
 * Répartir le budget en parts égales ferait mentir l'écran cinq jours sur
 * sept — le samedi paraîtrait toujours excellent, le lundi toujours mauvais.
 *
 * Ces poids ne s'inventent donc pas : ils se CALCULENT sur l'historique réel
 * du réseau, puis se proposent. Le calcul retenu est la MOYENNE PAR
 * OCCURRENCE, pas la somme : sur douze mois un mardi peut apparaître 53 fois
 * et un lundi 52, et sommer donnerait 2 % d'écart qui ne doit rien au métier.
 * On fait donc la moyenne de chaque jour de semaine, puis on ramène les sept
 * moyennes à 100.
 *
 * Les poids sont les MÊMES pour tout le réseau (décision produit) : un
 * magasin en dessous de la courbe réseau le samedi doit le voir, pas se voir
 * mesurer contre sa propre faiblesse.
 */

const PJ_CLE      = 'ponderationJours';
const PJ_NOMS     = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
                     5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
const PJ_COURTS   = [1 => 'L', 2 => 'Ma', 3 => 'Me', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
/** En dessous, une moyenne de jour de semaine ne veut rien dire. */
const PJ_MIN_OCC  = 8;

/**
 * Les poids ADOPTÉS, ou null s'il n'y en a pas encore.
 *
 * Aucun repli codé en dur : un écran qui afficherait des objectifs bâtis sur
 * des poids inventés serait faux sans le dire. Tant que rien n'est adopté,
 * l'appelant doit se taire et renvoyer vers le calcul.
 */
function pjPoids(): ?array
{
    $v = setting(PJ_CLE, null);
    if (!is_array($v) || !isset($v['poids']) || !is_array($v['poids'])) { return null; }
    $p = [];
    foreach (range(1, 7) as $j) {
        if (!isset($v['poids'][$j]) && !isset($v['poids'][(string) $j])) { return null; }
        $p[$j] = (float) ($v['poids'][$j] ?? $v['poids'][(string) $j]);
    }
    return ['poids' => $p, 'adopteLe' => (string) ($v['adopteLe'] ?? ''),
            'base' => (string) ($v['base'] ?? '')];
}

/**
 * La part d'UN jour dans sa semaine, en fraction (0,135 pour 13,5 %).
 * Rend null si aucun poids n'est adopté — l'appelant le dit à l'écran.
 */
function pjPart(string $date): ?float
{
    $p = pjPoids();
    if ($p === null) { return null; }
    $j = (int) date('N', strtotime($date));
    $t = array_sum($p['poids']);
    return $t > 0 ? $p['poids'][$j] / $t : null;
}

/** Les magasins actifs, pour le calcul comme pour la répartition. */
function pjShops(): array
{
    try { $r = Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id'); }
    catch (PDOException $e) { return []; }
    return array_map(static fn ($s) => (int) $s['id'], $r);
}

/**
 * CALCUL sur l'historique. Rend la proposition, jamais l'adoption : c'est un
 * humain qui décide, après avoir vu sur quoi le calcul repose.
 *
 * $mois = nombre de mois COMPLETS à remonter. Le mois en cours est exclu : il
 * est tronqué, et sur un mois commencé un jeudi les jeudis pèseraient double.
 */
function pjCalculer(int $mois = 12): array
{
    $shops = pjShops();
    if ($shops === []) { return ['ok' => false, 'motif' => 'Aucun magasin actif.']; }

    // `margin-heatmap` ne rend rien au-delà d'un mois : le détail quotidien
    // coûte un appel par magasin et par mois, et mesSeriesJour refuse au-delà
    // de soixante. On borne donc l'étendue plutôt que de rendre un tableau
    // silencieusement vide.
    $maxMois = max(1, (int) floor(60 / max(1, count($shops))));
    $mois    = max(1, min($mois, $maxMois));

    $au = date('Y-m-t', strtotime('first day of last month'));
    $du = date('Y-m-01', strtotime('first day of last month -' . ($mois - 1) . ' month'));

    $motifs = [];
    $series = mesSeriesJour($shops, $du, $au, $motifs);

    // Somme et compte par jour de semaine. Une paire (magasin, date) sans
    // donnée n'entre pas : un magasin fermé le dimanche ne doit pas tirer le
    // dimanche vers le bas pour les autres.
    $somme = array_fill(1, 7, 0.0);
    $occ   = array_fill(1, 7, 0);
    $jours = [];
    foreach ($series as $parJour) {
        if (!is_array($parJour)) { continue; }
        foreach ($parJour as $date => $d) {
            $ca = (float) ($d['ca'] ?? 0);
            if ($ca <= 0) { continue; }
            $j = (int) date('N', strtotime((string) $date));
            $somme[$j] += $ca; $occ[$j]++;
            $jours[(string) $date] = true;
        }
    }

    $vides = [];
    foreach (range(1, 7) as $j) { if ($occ[$j] < PJ_MIN_OCC) { $vides[] = PJ_NOMS[$j]; } }
    if ($vides !== []) {
        return ['ok' => false, 'du' => $du, 'au' => $au, 'motifs' => $motifs,
                'motif' => 'Pas assez d’historique pour : ' . implode(', ', $vides)
                    . '. Il faut au moins ' . PJ_MIN_OCC . ' occurrences de chaque jour.'];
    }

    // Moyenne par occurrence, puis ramenée à 100 : c'est la part de la semaine
    // que porte chaque jour, débarrassée du nombre de fois qu'il tombe.
    $moy = [];
    foreach (range(1, 7) as $j) { $moy[$j] = $somme[$j] / $occ[$j]; }
    $tot = array_sum($moy);
    if ($tot <= 0) { return ['ok' => false, 'motif' => 'Aucun chiffre d’affaires sur la période.']; }

    $lignes = [];
    foreach (range(1, 7) as $j) {
        $lignes[] = [
            'jour'    => $j,
            'nom'     => PJ_NOMS[$j],
            'court'   => PJ_COURTS[$j],
            'poids'   => round(100 * $moy[$j] / $tot, 2),
            'moyenne' => round($moy[$j], 2),
            'occ'     => $occ[$j],
            'ca'      => round($somme[$j], 2),
        ];
    }
    // L'arrondi à deux décimales peut faire 99,99 ou 100,01 : on reverse
    // l'écart sur le jour le plus lourd, invisible à l'affichage et exact
    // à la somme — un total qui ne fait pas 100 fait douter de tout le reste.
    $ecart = round(100 - array_sum(array_column($lignes, 'poids')), 2);
    if (abs($ecart) >= 0.01) {
        $iMax = 0;
        foreach ($lignes as $i => $l) { if ($l['poids'] > $lignes[$iMax]['poids']) { $iMax = $i; } }
        $lignes[$iMax]['poids'] = round($lignes[$iMax]['poids'] + $ecart, 2);
    }

    $attendu = count($shops) * (int) ((strtotime($au) - strtotime($du)) / 86400 + 1);
    $servi   = array_sum($occ);
    return [
        'ok'        => true,
        'du'        => $du,
        'au'        => $au,
        'mois'      => $mois,
        'moisMax'   => $maxMois,
        'magasins'  => count($shops),
        'jours'     => count($jours),
        'servi'     => $servi,
        'attendu'   => $attendu,
        'couverture' => $attendu > 0 ? round(100 * $servi / $attendu, 1) : null,
        'lignes'    => $lignes,
        'motifs'    => $motifs,
    ];
}

/**
 * GET /exploitation/ponderation-jours[?mois=12]
 * Le calcul PROPOSÉ, et ce qui est adopté aujourd'hui — pour comparer avant
 * de remplacer.
 */
function ep_ponderation_jours(): array
{
    $mois = isset($_GET['mois']) ? (int) $_GET['mois'] : 12;
    $adopte = pjPoids();
    $adLignes = null;
    if ($adopte !== null) {
        $adLignes = [];
        foreach (range(1, 7) as $j) {
            $adLignes[] = ['jour' => $j, 'nom' => PJ_NOMS[$j], 'court' => PJ_COURTS[$j],
                           'poids' => round($adopte['poids'][$j], 2)];
        }
    }
    return [
        'calcul'   => pjCalculer($mois),
        'adopte'   => $adLignes,
        'adopteLe' => $adopte['adopteLe'] ?? null,
        'base'     => $adopte['base'] ?? null,
    ];
}

/**
 * POST /exploitation/ponderation-jours  { poids: {1..7}, base?: "…" }
 * Adopte les poids. Ils sont normalisés à 100 ici : l'écran peut proposer des
 * chiffres retouchés à la main sans avoir à refaire la règle de trois.
 */
function wr_ponderation_jours(): array
{
    $b = body();
    $src = $b['poids'] ?? null;
    if (!is_array($src)) { return ['ok' => false, 'error' => 'poids attendus (1 à 7)']; }
    $p = [];
    foreach (range(1, 7) as $j) {
        $v = $src[$j] ?? $src[(string) $j] ?? null;
        if ($v === null || !is_numeric($v) || (float) $v < 0) {
            return ['ok' => false, 'error' => 'Poids manquant ou négatif pour ' . PJ_NOMS[$j]];
        }
        $p[$j] = (float) $v;
    }
    $tot = array_sum($p);
    if ($tot <= 0) { return ['ok' => false, 'error' => 'La somme des poids doit être positive.']; }
    foreach ($p as $j => $v) { $p[$j] = round(100 * $v / $tot, 4); }

    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [PJ_CLE, json_encode(['poids' => $p, 'adopteLe' => date('Y-m-d H:i:s'),
                              'base' => (string) ($b['base'] ?? '')], JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Réglage', null,
        'Pondération des jours adoptée — ' . implode(' · ',
            array_map(static fn ($j) => PJ_COURTS[$j] . ' ' . round($p[$j], 1) . ' %', range(1, 7))));
    return ['ok' => true, 'poids' => $p];
}
