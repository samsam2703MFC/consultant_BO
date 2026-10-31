<?php
declare(strict_types=1);

/**
 * Vérification — lot 3 : la base quotidienne et l'évolution (demandes du 31/10/2026 : « uploader tous les jours dans
 * une db, pour voir les améliorations de gestion »). Plan : docs/plan-verification.md § 5 ; maquette : f.png.
 *
 * La table `ceo_verif_jour` garde, pour chaque magasin et chaque jour :
 *  · l'audit des flux de la journée (lot 2), au prix de vente et en pièces : disponible, vendu, poubelle déclarée,
 *    invendu gardé, non expliqué ; la part mesurée (cuissons validées), les cuissons, la clôture, les comptages —
 *    écrit dès qu'une journée est calculée (vxJour), et rattrapé par le cron depuis les journées gravées ;
 *  · la couverture des recettes du jour (lot 1) : CA des trente derniers jours, CA fiable, signalements — la photo
 *    prise par le cron quand toutes les recettes sont lues.
 * On n'y met que des agrégats calculés par le cockpit, jamais une copie des sources. Rien ne s'efface : une journée
 * recalculée (sept derniers jours, validation après coup) remplace sa ligne, les autres restent.
 *
 * GET /verification/evolution[?semaines=12] — les indicateurs semaine par semaine, magasin par magasin et pour le
 * réseau, et « avant / après » (les premières semaines face aux dernières). Lecture seule.
 */

const VJ_SEMAINES = 12;
const VJ_COMPARE = 4;             // semaines comparées au début et à la fin de la période
const VJ_SEUIL_STABLE = 0.3;      // un écart de moins de 0,3 point : stable
/** Les indicateurs : code => [nom, plus haut = mieux ?]. */
const VJ_INDICATEURS = [
    'poubelle' => ['Poubelle déclarée', false],
    'nonExpl'  => ['Non expliqué', false],
    'invendu'  => ['Invendu gardé', false],
    'mesure'   => ['Journées mesurées', true],
    'recettes' => ['Recettes fiables', true],
];

function vjTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_verif_jour ('
        . 'shop_id INT NOT NULL, jour DATE NOT NULL,'
        . 'dispo_eur DECIMAL(12,2) NULL, vendu_eur DECIMAL(12,2) NULL, poubelle_eur DECIMAL(12,2) NULL, invendu_eur DECIMAL(12,2) NULL,'
        . 'nonexpl_eur DECIMAL(12,2) NULL, mesure_eur DECIMAL(12,2) NULL,'
        . 'dispo_pcs DECIMAL(12,1) NULL, vendu_pcs DECIMAL(12,1) NULL, poubelle_pcs DECIMAL(12,1) NULL, invendu_pcs DECIMAL(12,1) NULL, nonexpl_pcs DECIMAL(12,1) NULL,'
        . 'lignes SMALLINT NULL, cuissons_validees SMALLINT NULL, cuissons SMALLINT NULL, cloture TINYINT(1) NULL, comptes SMALLINT NULL,'
        . 'flux_le DATETIME NULL,'
        . 'recettes_ca_total DECIMAL(12,2) NULL, recettes_ca_fiable DECIMAL(12,2) NULL, recettes_signalees SMALLINT NULL, recettes_le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, jour), KEY (jour)'
        . ') DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Écrit (ou réécrit) l'audit des flux d'une journée calculée. Rend vrai si la ligne est écrite. */
function vjEnregistre(int $sid, string $date, array $j, ?int $quand = null): bool
{
    try {
        vjTable();
        $a = vxAgregeJour($j); $e = $a['eur']; $p = $a['pcs'];
        Db::exec('INSERT INTO ceo_verif_jour (shop_id, jour, dispo_eur, vendu_eur, poubelle_eur, invendu_eur, nonexpl_eur, mesure_eur,'
            . ' dispo_pcs, vendu_pcs, poubelle_pcs, invendu_pcs, nonexpl_pcs, lignes, cuissons_validees, cuissons, cloture, comptes, flux_le)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE dispo_eur = VALUES(dispo_eur), vendu_eur = VALUES(vendu_eur), poubelle_eur = VALUES(poubelle_eur),'
            . ' invendu_eur = VALUES(invendu_eur), nonexpl_eur = VALUES(nonexpl_eur), mesure_eur = VALUES(mesure_eur), dispo_pcs = VALUES(dispo_pcs),'
            . ' vendu_pcs = VALUES(vendu_pcs), poubelle_pcs = VALUES(poubelle_pcs), invendu_pcs = VALUES(invendu_pcs), nonexpl_pcs = VALUES(nonexpl_pcs),'
            . ' lignes = VALUES(lignes), cuissons_validees = VALUES(cuissons_validees), cuissons = VALUES(cuissons), cloture = VALUES(cloture),'
            . ' comptes = VALUES(comptes), flux_le = VALUES(flux_le)',
            [$sid, $date, round($e['dispo'], 2), round($e['vendu'], 2), round($e['poubelle'], 2), round($e['invendu'], 2), round($e['nonExpl'], 2), round($a['mesureEur'], 2),
                round($p['dispo'], 1), round($p['vendu'], 1), round($p['poubelle'], 1), round($p['invendu'], 1), round($p['nonExpl'], 1),
                $a['lignes'], $a['cuissonsValidees'], $a['cuissons'], $a['clotures'], $a['comptes'], date('Y-m-d H:i:s', $quand ?? time())]);
        return true;
    } catch (Throwable $ex) { return false; }
}

/** La photo du jour de la couverture des recettes, magasin par magasin ([sid => {caTotal, caFiable, signalements}]). */
function vjPhotoRecettes(array $parShop): int
{
    $n = 0;
    try {
        vjTable();
        foreach ($parShop as $sid => $x) {
            if ((int) $sid <= 0 || !is_array($x)) { continue; }
            Db::exec('INSERT INTO ceo_verif_jour (shop_id, jour, recettes_ca_total, recettes_ca_fiable, recettes_signalees, recettes_le) VALUES (?,?,?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE recettes_ca_total = VALUES(recettes_ca_total), recettes_ca_fiable = VALUES(recettes_ca_fiable),'
                . ' recettes_signalees = VALUES(recettes_signalees), recettes_le = VALUES(recettes_le)',
                [(int) $sid, date('Y-m-d'), (float) ($x['caTotal'] ?? 0), (float) ($x['caFiable'] ?? 0), (int) ($x['signalements'] ?? 0), date('Y-m-d H:i:s')]);
            $n++;
        }
    } catch (Throwable $e) { /* sans base : la photo du jour manque, la suivante la remplace */ }
    return $n;
}

/** Les journées gravées que la table n'a pas encore (ou dans une version plus ancienne) : [sid => [jour => journée]]. */
function vjRattrape(array $C): int
{
    $jours = [];
    foreach ($C as $js) { foreach (array_keys($js) as $j) { $jours[$j] = true; } }
    if ($jours === []) { return 0; }
    $deja = [];
    try {
        vjTable();
        foreach (Db::rows('SELECT shop_id, jour, flux_le FROM ceo_verif_jour WHERE jour >= ?', [min(array_keys($jours))]) as $r) {
            $deja[(int) $r['shop_id'] . ':' . substr((string) $r['jour'], 0, 10)] = $r['flux_le'] === null ? 0 : (int) strtotime((string) $r['flux_le']);
        }
    } catch (Throwable $e) { return 0; }
    $n = 0;
    foreach ($C as $sid => $js) {
        foreach ($js as $j => $g) {
            if (!is_array($g) || !empty($g['perime'])) { continue; }
            $q = (int) ($g['_q'] ?? 0);
            $k = (int) $sid . ':' . $j;
            if (isset($deja[$k]) && $deja[$k] >= $q && $deja[$k] > 0) { continue; }
            if (vjEnregistre((int) $sid, (string) $j, $g, $q > 0 ? $q : null)) { $n++; }
        }
    }
    return $n;
}

/** Les N dernières semaines ISO (lundi → dimanche), la dernière étant celle d'hier : [[cle, num, du, au]]. */
function vjSemaines(int $n, ?string $ref = null): array
{
    $ref ??= date('Y-m-d', strtotime('-1 day'));
    $t = strtotime($ref . ' 12:00:00');
    $lundi = strtotime('-' . ((int) date('N', $t) - 1) . ' day', $t);
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $l = strtotime('-' . (7 * $i) . ' day', $lundi);
        $out[] = ['cle' => date('o-W', $l), 'num' => 'S' . (int) date('W', $l), 'du' => date('Y-m-d', $l), 'au' => date('Y-m-d', strtotime('+6 day', $l))];
    }
    return $out;
}

/**
 * Les séries semaine par semaine — fonction pure (bin/tests_verification.php). $rows : les lignes de ceo_verif_jour ;
 * rend [sid|'reseau' => [indicateur => [valeur|null par semaine]]], et l'état « estimé » de chaque semaine.
 */
function vjSeries(array $rows, array $semaines, array $sids): array
{
    $idx = []; foreach ($semaines as $k => $s) { $idx[$s['cle']] = $k; }
    $vide = static fn () => ['dispo' => 0.0, 'vendu' => 0.0, 'poubelle' => 0.0, 'invendu' => 0.0, 'nonexpl' => 0.0, 'mesure' => 0.0, 'rTot' => 0.0, 'rFi' => 0.0, 'jours' => 0];
    $S = [];
    foreach (array_merge(array_map('strval', $sids), ['reseau']) as $k) { foreach ($semaines as $i => $s) { $S[$k][$i] = $vide(); } }
    foreach ($rows as $r) {
        $sid = (string) (int) $r['shop_id'];
        if (!isset($S[$sid])) { continue; }
        $cle = date('o-W', strtotime(substr((string) $r['jour'], 0, 10) . ' 12:00:00'));
        if (!isset($idx[$cle])) { continue; }
        $i = $idx[$cle];
        foreach ([$sid, 'reseau'] as $k) {
            $x = &$S[$k][$i];
            if ($r['dispo_eur'] !== null) {
                $x['dispo'] += (float) $r['dispo_eur']; $x['vendu'] += (float) $r['vendu_eur']; $x['poubelle'] += (float) $r['poubelle_eur'];
                $x['invendu'] += (float) $r['invendu_eur']; $x['nonexpl'] += (float) $r['nonexpl_eur']; $x['mesure'] += (float) $r['mesure_eur']; $x['jours']++;
            }
            if ($r['recettes_ca_total'] !== null && (float) $r['recettes_ca_total'] > 0) { $x['rTot'] += (float) $r['recettes_ca_total']; $x['rFi'] += (float) $r['recettes_ca_fiable']; }
            unset($x);
        }
    }
    $out = [];
    foreach ($S as $k => $ws) {
        foreach ($ws as $i => $x) {
            $d = $x['dispo'];
            $p = static fn (float $v) => $d > 0 ? round(100 * $v / $d, 1) : null;
            $out[$k]['poubelle'][$i] = $p($x['poubelle']);
            $out[$k]['nonExpl'][$i] = $p($x['nonexpl']);
            $out[$k]['invendu'][$i] = $p($x['invendu']);
            $out[$k]['mesure'][$i] = $p($x['mesure']);
            $out[$k]['recettes'][$i] = $x['rTot'] > 0 ? round(100 * $x['rFi'] / $x['rTot'], 1) : null;
            $out[$k]['estime'][$i] = $d > 0 ? (100 * $x['mesure'] / $d) < (defined('VX_MESURE_MIN') ? VX_MESURE_MIN : 50.0) : null;
            $out[$k]['jours'][$i] = $x['jours'];
        }
    }
    return $out;
}

/**
 * Avant / après : la moyenne des premières semaines renseignées face à celle des dernières, et le sens du changement.
 * $versZero : le non expliqué se juge à sa distance à zéro — −12 % (vendu plus que produit : cuissons non saisies) n'est
 * pas mieux que +3 %.
 */
function vjAvantApres(array $v, bool $hautMieux, bool $versZero = false): ?array
{
    $vals = array_values(array_filter($v, static fn ($x) => $x !== null));
    $n = count($vals);
    if ($n < 2) { return null; }
    $k = min(VJ_COMPARE, intdiv($n, 2));
    $moy = static fn (array $a) => array_sum($a) / count($a);
    $av = $moy(array_slice($vals, 0, $k)); $ap = $moy(array_slice($vals, -$k));
    $d = $ap - $av;
    $dj = $versZero ? abs($ap) - abs($av) : $d;
    $sens = abs($dj) < VJ_SEUIL_STABLE ? 'stable' : ((($dj > 0) === $hautMieux) ? 'mieux' : 'moins bien');
    return ['avant' => round($av, 1), 'apres' => round($ap, 1), 'ecart' => round($d, 1), 'sens' => $sens, 'semaines' => $k];
}

/** GET /verification/evolution[?semaines=12] — les indicateurs semaine par semaine, et avant / après. */
function ep_verification_evolution(): array
{
    $n = max(4, min(26, (int) ($_GET['semaines'] ?? VJ_SEMAINES)));
    $sem = vjSemaines($n);
    $mags = vfMagasins();
    $rows = []; $base = ['lignes' => 0, 'premier' => null, 'dernier' => null, 'fluxLe' => null, 'recettesLe' => null, 'magasins' => 0];
    try {
        vjTable();
        $rows = Db::rows('SELECT * FROM ceo_verif_jour WHERE jour >= ? ORDER BY jour', [$sem[0]['du']]);
        $b = Db::row('SELECT COUNT(*) n, MIN(jour) premier, MAX(jour) dernier, MAX(flux_le) flux, MAX(recettes_le) rec, COUNT(DISTINCT shop_id) mags FROM ceo_verif_jour');
        if ($b !== null) { $base = ['lignes' => (int) $b['n'], 'premier' => $b['premier'], 'dernier' => $b['dernier'], 'fluxLe' => $b['flux'], 'recettesLe' => $b['rec'], 'magasins' => (int) $b['mags']]; }
    } catch (Throwable $e) { $base['erreur'] = 'la base quotidienne n’a pas pu être lue'; }
    $S = vjSeries($rows, $sem, array_keys($mags));
    $lecture = static function (array $s): array {
        $o = ['series' => $s, 'comparaison' => []];
        foreach (VJ_INDICATEURS as $code => [$nom, $haut]) {
            $vals = (array) ($s[$code] ?? []);
            // Une semaine sans aucune poubelle déclarée n'est pas une semaine parfaite : la poubelle n'y est pas déclarée.
            if ($code === 'poubelle') { $vals = array_map(static fn ($x) => $x !== null && (float) $x <= 0.0 ? null : $x, $vals); }
            $o['comparaison'][$code] = vjAvantApres($vals, $haut, $code === 'nonExpl');
        }
        return $o;
    };
    $magasins = [];
    foreach ($mags as $sid => $nom) { $magasins[] = ['id' => (string) $sid, 'nom' => $nom] + $lecture($S[(string) $sid] ?? []); }
    return ['semaines' => $sem, 'magasins' => $magasins, 'reseau' => $lecture($S['reseau'] ?? []),
        'indicateurs' => array_map(static fn ($c, $x) => ['code' => $c, 'nom' => $x[0], 'hautMieux' => $x[1]], array_keys(VJ_INDICATEURS), array_values(VJ_INDICATEURS)),
        'base' => $base, 'seuils' => ['nonExpl' => [3, 6], 'poubelle' => [6, 9], 'mesureMin' => defined('VX_MESURE_MIN') ? VX_MESURE_MIN : 50.0],
        'lu' => date('c'),
        'source' => 'la base quotidienne ceo_verif_jour : l’audit des flux de chaque journée (lot 2) et la photo du jour de la couverture des recettes (lot 1), sommés par semaine · lecture seule'];
}
