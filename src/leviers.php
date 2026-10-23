<?php
declare(strict_types=1);

/**
 * Les six leviers, magasin par magasin (21/10/2026, demande de Sam : « le
 * tableau des 6 leviers dans le Tableau des magasins » puis « les leviers dans
 * le dashboard mensuel »). Résultat › Mois et le dashboard magasin (vue Mois)
 * lisent la MÊME réponse : un chiffre ne peut pas différer d'un écran à l'autre.
 *
 * GET /leviers/magasins?annee=2026&mois=10 — deux lectures dans une réponse :
 *   mois  : le mois choisi (à date s'il est en cours), face au même mois de
 *           l'année d'avant et au réseau ;
 *   cumul : janvier → le dernier mois clos, face aux mêmes mois de l'année
 *           d'avant.
 *
 * Les sources, aucune nouvelle :
 *   Trafic            les clients (tickets) du P&L mensuel (ep_perf) — par jour au mois ;
 *   Récurrence        le taux de retour des clients identifiés en caisse, hors pro (23/10/2026, demande de Sam) :
 *                     la part venus au moins deux jours ; à part, celui des seuls clients PWA — recurrence_pwa.php ;
 *   Expérience client la note Google de la fiche (mar_avis_fiche, Réputation digitale),
 *                     son relevé mensuel (mac_google_rating_snapshot, tenu par le panel
 *                     consultant depuis octobre 2026) pour un mois passé et le N-1 ;
 *   Food / Labour / Overhead cost  les ratios du P&L mensuel (ep_perf).
 * Les pastilles suivent les seuils de la Table KPI (ceo_kpi_def) : rien n'est fixé ici.
 *
 * Comparer à mois égaux, toujours. Le mois d'ouverture d'un magasin est partiel
 * (l'ouverture saisie dans Scouting › Magasins du réseau, sinon l'année du
 * panel) : il ne se compare pas. Des frais généraux à 0 % ne sont pas saisis :
 * ils ne se comparent pas non plus. Le réseau se compare à périmètre constant.
 */

/** Écart à la médiane du magasin (points) au-delà duquel un mois de food cost est signalé « à vérifier ». */
const LEV_HORS_NORME = 15;

/** Les codes de la Table KPI dont les seuils colorent chaque levier. */
const LEV_KPI = ['trafic' => 'trafic-clients', 'xp' => 'note-google', 'food' => 'food-cost-pct', 'labour' => 'labour-pct', 'overhead' => 'overhead-pct'];

/** Les leviers dans l'ordre du référentiel, chacun avec sa mesure (vide : pas encore de mesure). */
function levDefs(): array
{
    $cles = ['trafic' => 'trafic', 'recurrence' => 'recurrence', 'xp' => 'xp', 'food-cost' => 'food', 'labour-cost' => 'labour', 'overhead-cost' => 'overhead'];
    $mesures = ['trafic' => ['clients par jour', 'clients'], 'recurrence' => ['taux de retour des clients identifiés', 'taux de retour des clients identifiés'], 'xp' => ['note Google', 'note Google'],
        'food' => ['% du CA', '% du CA'], 'labour' => ['% du CA', '% du CA'], 'overhead' => ['% du CA', '% du CA']];
    $out = [];
    foreach (ep_leviers() as $l) {
        $k = $cles[$l['slug']] ?? null;
        if ($k === null) { continue; }
        $out[] = ['cle' => $k, 'nom' => $l['nom'], 'type' => $l['type'], 'couleur' => $l['color'],
            'mesureMois' => $mesures[$k][0], 'mesureCumul' => $mesures[$k][1], 'vide' => false];
    }
    return $out;
}

/** La pastille d'une valeur face aux seuils d'un KPI : v (sous l'alerte), o (entre alerte et critique), r (au-delà), null sans seuil. */
function levNiveau(?array $s, ?float $v): ?string
{
    if ($s === null || $v === null || $s['alerte'] === null) { return null; }
    $al = (float) $s['alerte']; $cr = $s['critique'] !== null ? (float) $s['critique'] : $al;
    if ($s['sens'] === 'haut') { return $v >= $cr ? 'r' : ($v >= $al ? 'o' : 'v'); }
    return $v <= $cr ? 'r' : ($v <= $al ? 'o' : 'v');
}

/** « 2026-10 » → nombre de mois depuis l'an 0, pour compter des écarts. */
function levNum(string $ym): int { return (int) substr($ym, 0, 4) * 12 + (int) substr($ym, 5, 2); }

/**
 * Le calcul, sans lecture : testable sur des données figées.
 *
 * @param array $cells    les cellules d'ep_perf (storeId, annee, mois, ca, tickets, foodCostPct, labourCostPct, overheadPct)
 * @param array $mags     [id => [nom, ville, franchise, ouverture ('AAAA-MM' | 'AAAA' | null)]], dans l'ordre d'affichage
 * @param array $google   [id => [note, avis]] — la fiche Google d'aujourd'hui
 * @param array $releves  [id => ['AAAA-MM' => [note, avis]]] — le relevé mensuel
 * @param array $seuils   [clé de levier => [alerte, critique, sens]]
 */
function levCalcul(array $cells, array $mags, array $google, array $releves, array $seuils, int $y, int $m, string $aujourdhui): array
{
    $ymSel = sprintf('%04d-%02d', $y, $m);
    $ymAuj = substr($aujourdhui, 0, 7);
    $enCours = $ymSel === $ymAuj;
    $jours = static function (string $ym) use ($ymAuj, $aujourdhui): int {
        return $ym === $ymAuj ? (int) substr($aujourdhui, 8, 2) : (int) date('t', strtotime($ym . '-01'));
    };
    $ym = static fn (int $a, int $mo): string => sprintf('%04d-%02d', $a, $mo);
    $C = [];
    foreach ($cells as $c) {
        if (($c['ca'] ?? null) === null || (float) $c['ca'] <= 0) { continue; }
        $C[(string) $c['storeId']][$ym((int) $c['annee'], (int) $c['mois'])] = $c;
    }
    $oh = static fn (?array $c): ?float => $c !== null && (float) ($c['overheadPct'] ?? 0) > 0 ? (float) $c['overheadPct'] : null;
    $val = [
        'food' => static fn (?array $c): ?float => $c !== null && ($c['foodCostPct'] ?? null) !== null ? (float) $c['foodCostPct'] : null,
        'labour' => static fn (?array $c): ?float => $c !== null && ($c['labourCostPct'] ?? null) !== null ? (float) $c['labourCostPct'] : null,
        'overhead' => $oh,
    ];
    $r1 = static fn (?float $v, int $d = 1): ?float => $v === null ? null : round($v, $d);

    // Le mois d'ouverture de chaque magasin, s'il tombe dans les données : partiel, il ne se compare pas.
    $ouvre = [];
    foreach ($mags as $id => $g) {
        if (empty($C[$id])) { continue; }
        $premier = min(array_keys($C[$id]));
        $o = (string) ($g['ouverture'] ?? '');
        if (preg_match('/^\d{4}-\d{2}/', $o)) { $ouvre[$id] = abs(levNum($premier) - levNum(substr($o, 0, 7))) <= 1 ? $premier : null; }
        elseif (preg_match('/^\d{4}$/', $o)) { $ouvre[$id] = substr($premier, 0, 4) === $o ? $premier : null; }
        else { $ouvre[$id] = null; }
    }
    // Les identifiants de magasin arrivent en entiers dans les clés de tableau (« 2 » → 2).
    $comparable = static fn (int|string $id, string $a, string $b): bool => isset($C[$id][$a], $C[$id][$b]) && ($ouvre[$id] ?? null) !== $a && ($ouvre[$id] ?? null) !== $b;

    // —— le mois choisi
    $ymN1 = $ym($y - 1, $m);
    $mois = []; $trafRes = []; $poolR = ['food' => [0.0, 0.0], 'labour' => [0.0, 0.0], 'overhead' => [0.0, 0.0]];
    // le réseau face au N-1 à périmètre constant : les seuls magasins comparables ce mois-là
    $cmpT = [0.0, 0.0, 0]; $cmpR = ['food' => [0.0, 0.0, 0.0, 0.0], 'labour' => [0.0, 0.0, 0.0, 0.0], 'overhead' => [0.0, 0.0, 0.0, 0.0]];
    foreach ($mags as $id => $g) {
        $c = $C[$id][$ymSel] ?? null; $c1 = $C[$id][$ymN1] ?? null;
        $ouv = ($ouvre[$id] ?? null) === $ymSel;
        $t = $c !== null && $c['tickets'] !== null ? (float) $c['tickets'] / max(1, $jours($ymSel)) : null;
        $t1 = $comparable($id, $ymSel, $ymN1) && $c1['tickets'] !== null ? (float) $c1['tickets'] / $jours($ymN1) : null;
        if ($t !== null && !$ouv) { $trafRes[] = $t; }
        if ($t !== null && $t1) { $cmpT[0] += $t; $cmpT[1] += $t1; $cmpT[2]++; }
        $x = ['ouverture' => $ouv, 'trafic' => ['v' => $r1($t), 'n1' => $r1($t1), 'delta' => $t !== null && $t1 ? $r1(100 * ($t / $t1 - 1)) : null]];
        foreach ($val as $k => $f) {
            $v = $f($c); $v1 = $comparable($id, $ymSel, $ymN1) ? $f($c1) : null;
            if ($v !== null) { $poolR[$k][0] += $v * (float) $c['ca']; $poolR[$k][1] += (float) $c['ca']; }
            if ($v !== null && $v1 !== null) { $cmpR[$k][0] += $v * (float) $c['ca']; $cmpR[$k][1] += (float) $c['ca']; $cmpR[$k][2] += $v1 * (float) $c1['ca']; $cmpR[$k][3] += (float) $c1['ca']; }
            $x[$k] = ['v' => $r1($v), 'n1' => $r1($v1), 'delta' => $v !== null && $v1 !== null ? $r1($v - $v1) : null, 'niveau' => levNiveau($seuils[$k] ?? null, $v)];
            // le mois a un P&L mais pas ce coût (frais généraux à 0 %) : « pas saisi », hors du réseau
            if ($c !== null && $v === null) { $x[$k]['nonSaisi'] = true; }
        }
        $mois[$id] = $x;
    }
    $resTraf = $trafRes !== [] ? array_sum($trafRes) / count($trafRes) : null;
    foreach ($mois as $id => &$x) {
        $e = $x['trafic']['v'] !== null && $resTraf ? 100 * ($x['trafic']['v'] / $resTraf - 1) : null;
        $x['trafic']['ecartReseau'] = $r1($e); $x['trafic']['niveau'] = $x['ouverture'] ? null : levNiveau($seuils['trafic'] ?? null, $e);
    }
    unset($x);
    $reseauMois = ['trafic' => ['v' => $r1($resTraf), 'comp' => $cmpT[2] ? $r1($cmpT[0] / $cmpT[2]) : null, 'n1' => $cmpT[2] ? $r1($cmpT[1] / $cmpT[2]) : null,
        'delta' => $cmpT[1] > 0 ? $r1(100 * ($cmpT[0] / $cmpT[1] - 1)) : null, 'magasins' => $cmpT[2]]];
    foreach ($poolR as $k => [$num, $ca]) {
        $v = $ca > 0 ? $num / $ca : null;
        $vc = $cmpR[$k][1] > 0 ? $cmpR[$k][0] / $cmpR[$k][1] : null; $v1 = $cmpR[$k][3] > 0 ? $cmpR[$k][2] / $cmpR[$k][3] : null;
        $reseauMois[$k] = ['v' => $r1($v), 'comp' => $r1($vc), 'n1' => $r1($v1), 'delta' => $vc !== null && $v1 !== null ? $r1($vc - $v1) : null, 'niveau' => levNiveau($seuils[$k] ?? null, $v)];
    }

    // —— la note Google : aujourd'hui pour le mois en cours, le relevé pour un mois passé ; le N-1 au relevé
    $gR = [0.0, 0]; $gN = [0.0, 0];
    foreach ($mags as $id => $g) {
        $now = $google[$id] ?? null;
        $ce = $enCours ? $now : ($releves[$id][$ymSel] ?? null);
        $n1 = $releves[$id][$ymN1] ?? null;
        $mois[$id]['xp'] = ['v' => $ce !== null ? $r1((float) $ce[0], 2) : null, 'avis' => $ce !== null ? (int) $ce[1] : null,
            'n1' => $n1 !== null ? $r1((float) $n1[0], 2) : null, 'delta' => $ce !== null && $n1 !== null ? $r1((float) $ce[0] - (float) $n1[0], 2) : null,
            'niveau' => levNiveau($seuils['xp'] ?? null, $ce !== null ? (float) $ce[0] : null)];
        // le réseau du mois se lit sur les mêmes notes que ses magasins ; le cumul, sur la fiche d'aujourd'hui
        if ($ce !== null && $ce[0] !== null) { $gR[0] += (float) $ce[0] * (int) $ce[1]; $gR[1] += (int) $ce[1]; }
        if ($now !== null && $now[0] !== null) { $gN[0] += (float) $now[0] * (int) $now[1]; $gN[1] += (int) $now[1]; }
    }
    $gRes = $gR[1] > 0 ? $gR[0] / $gR[1] : null; $gAuj = $gN[1] > 0 ? $gN[0] / $gN[1] : null;
    $reseauMois['xp'] = ['v' => $r1($gRes, 2), 'avis' => $gR[1], 'niveau' => levNiveau($seuils['xp'] ?? null, $gRes)];
    $premierReleve = null;
    foreach ($releves as $rs) { foreach (array_keys($rs) as $k) { if ($premierReleve === null || $k < $premierReleve) { $premierReleve = $k; } } }

    // —— le cumul : janvier → le dernier mois clos (le mois choisi s'il est clos)
    $au = $enCours ? $m - 1 : $m;
    $cumul = null; $reseauCumul = null;
    if ($au >= 1) {
        $cumul = [];
        $poolC = ['tk' => 0.0, 'tkC' => 0.0, 'tkN' => 0.0];
        $poolV = ['food' => [0.0, 0.0], 'labour' => [0.0, 0.0], 'overhead' => [0.0, 0.0]];
        $poolCmp = ['food' => [0.0, 0.0, 0.0, 0.0], 'labour' => [0.0, 0.0, 0.0, 0.0], 'overhead' => [0.0, 0.0, 0.0, 0.0]];
        foreach ($mags as $id => $g) {
            $tk = 0.0; $tkC = 0.0; $tkN = 0.0; $moisC = []; $vu = false;
            $sum = ['food' => [0.0, 0.0], 'labour' => [0.0, 0.0], 'overhead' => [0.0, 0.0]];
            $cmp = ['food' => [0.0, 0.0, 0.0, 0.0, 0], 'labour' => [0.0, 0.0, 0.0, 0.0, 0], 'overhead' => [0.0, 0.0, 0.0, 0.0, 0]];
            for ($mm = 1; $mm <= $au; $mm++) {
                $a = $ym($y, $mm); $b = $ym($y - 1, $mm);
                $c = $C[$id][$a] ?? null;
                if ($c === null) { continue; }
                $vu = true; $tk += (float) $c['tickets'];
                foreach ($val as $k => $f) { $v = $f($c); if ($v !== null) { $sum[$k][0] += $v * (float) $c['ca']; $sum[$k][1] += (float) $c['ca']; } }
                if (!$comparable($id, $a, $b)) { continue; }
                $c1 = $C[$id][$b];
                $moisC[] = $mm; $tkC += (float) $c['tickets']; $tkN += (float) $c1['tickets'];
                foreach ($val as $k => $f) {
                    $v = $f($c); $v1 = $f($c1);
                    if ($v === null || $v1 === null) { continue; }
                    $cmp[$k][0] += $v * (float) $c['ca']; $cmp[$k][1] += (float) $c['ca']; $cmp[$k][2] += $v1 * (float) $c1['ca']; $cmp[$k][3] += (float) $c1['ca']; $cmp[$k][4]++;
                }
            }
            if (!$vu) { continue; }
            $x = ['moisComparables' => $moisC, 'trafic' => ['v' => round($tk), 'comp' => $moisC !== [] ? round($tkC) : null, 'n1' => $moisC !== [] ? round($tkN) : null,
                'delta' => $tkN > 0 ? $r1(100 * ($tkC / $tkN - 1)) : null]];
            $poolC['tk'] += $tk; $poolC['tkC'] += $tkC; $poolC['tkN'] += $tkN;
            foreach ($val as $k => $f) {
                $v = $sum[$k][1] > 0 ? $sum[$k][0] / $sum[$k][1] : null;
                $vc = $cmp[$k][1] > 0 ? $cmp[$k][0] / $cmp[$k][1] : null; $v1 = $cmp[$k][3] > 0 ? $cmp[$k][2] / $cmp[$k][3] : null;
                $x[$k] = ['v' => $r1($v), 'comp' => $r1($vc), 'n1' => $r1($v1), 'delta' => $vc !== null && $v1 !== null ? $r1($vc - $v1) : null,
                    'moisCompares' => $cmp[$k][4], 'niveau' => levNiveau($seuils[$k] ?? null, $v)];
                $poolV[$k][0] += $sum[$k][0]; $poolV[$k][1] += $sum[$k][1];
                for ($i = 0; $i < 4; $i++) { $poolCmp[$k][$i] += $cmp[$k][$i]; }
            }
            $cumul[$id] = $x;
        }
        $reseauCumul = ['trafic' => ['v' => round($poolC['tk']), 'comp' => round($poolC['tkC']), 'n1' => round($poolC['tkN']),
            'delta' => $poolC['tkN'] > 0 ? $r1(100 * ($poolC['tkC'] / $poolC['tkN'] - 1)) : null]];
        foreach ($val as $k => $f) {
            $v = $poolV[$k][1] > 0 ? $poolV[$k][0] / $poolV[$k][1] : null;
            $vc = $poolCmp[$k][1] > 0 ? $poolCmp[$k][0] / $poolCmp[$k][1] : null; $v1 = $poolCmp[$k][3] > 0 ? $poolCmp[$k][2] / $poolCmp[$k][3] : null;
            $reseauCumul[$k] = ['v' => $r1($v), 'comp' => $r1($vc), 'n1' => $r1($v1), 'delta' => $vc !== null && $v1 !== null ? $r1($vc - $v1) : null, 'niveau' => levNiveau($seuils[$k] ?? null, $v)];
        }
        foreach ($mags as $id => $g) {
            if (!isset($cumul[$id])) { continue; }
            $now = $google[$id] ?? null;
            $cumul[$id]['xp'] = ['v' => $now !== null ? $r1((float) $now[0], 2) : null, 'avis' => $now !== null ? (int) $now[1] : null, 'niveau' => levNiveau($seuils['xp'] ?? null, $now !== null ? (float) $now[0] : null)];
        }
        $reseauCumul['xp'] = ['v' => $r1($gAuj, 2), 'avis' => $gN[1], 'niveau' => levNiveau($seuils['xp'] ?? null, $gAuj)];
    }

    // —— les mois de food cost hors norme (à vérifier au P&L du panel), et les douze mois de chaque magasin
    $serieMois = [];
    for ($i = 11; $i >= 0; $i--) { $serieMois[] = date('Y-m', strtotime($ymSel . '-01 -' . $i . ' month')); }
    $magasins = [];
    foreach ($mags as $id => $g) {
        if (!isset($mois[$id]) || (empty($C[$id][$ymSel]) && !isset($cumul[$id]))) { continue; }
        $foods = array_values(array_filter(array_map(static fn ($c) => $c['foodCostPct'] ?? null, $C[$id] ?? []), static fn ($v) => $v !== null));
        sort($foods);
        $med = $foods !== [] ? $foods[intdiv(count($foods), 2)] : null;
        $hors = [];
        foreach ($C[$id] ?? [] as $k => $c) {
            $v = $c['foodCostPct'] ?? null;
            if ($v !== null && $med !== null && abs((float) $v - (float) $med) > LEV_HORS_NORME && substr($k, 0, 4) >= (string) ($y - 1)) { $hors[] = ['mois' => $k, 'v' => (float) $v]; }
        }
        $serie = ['trafic' => [], 'food' => [], 'labour' => [], 'overhead' => []];
        foreach ($serieMois as $k) {
            $c = $C[$id][$k] ?? null; $ouvK = ($ouvre[$id] ?? null) === $k;
            $serie['trafic'][] = $c !== null && $c['tickets'] !== null && !$ouvK ? $r1((float) $c['tickets'] / $jours($k)) : null;
            foreach ($val as $kk => $f) { $serie[$kk][] = $ouvK ? null : $r1($f($c)); }
        }
        $magasins[] = ['id' => (string) $id, 'nom' => $g['nom'], 'ville' => $g['ville'], 'franchise' => $g['franchise'], 'ouverture' => $ouvre[$id] ?? null,
            'mois' => $mois[$id], 'cumul' => $cumul[$id] ?? null, 'horsNorme' => $hors, 'serie' => $serie];
    }
    return [
        'annee' => $y, 'mois' => $m, 'moisEnCours' => $enCours, 'jours' => $jours($ymSel),
        'cumulAu' => $au >= 1 ? $au : null,
        'seuils' => $seuils, 'serieMois' => $serieMois,
        'magasins' => $magasins,
        'reseau' => ['mois' => $reseauMois, 'cumul' => $reseauCumul],
        // le relevé mensuel de la note Google : le premier N-1 tombe douze mois après son premier mois
        'googleN1Des' => $premierReleve !== null ? date('Y-m', strtotime($premierReleve . '-01 +12 month')) : null,
    ];
}

/** GET /leviers/magasins?annee=&mois= — les lectures, puis levCalcul(). */
function ep_leviers_magasins(): array
{
    $y = (int) ($_GET['annee'] ?? date('Y')); $m = (int) ($_GET['mois'] ?? date('n'));
    if ($y < 2000 || $m < 1 || $m > 12) { http_response_code(400); return ['error' => 'annee et mois attendus']; }
    if (sprintf('%04d-%02d', $y, $m) > date('Y-m')) { $y = (int) date('Y'); $m = (int) date('n'); }
    // Le P&L mensuel de l'année et de la précédente : la lecture du tableau des magasins, sans le panier hors pro.
    $garde = $_GET;
    $_GET = ['annees' => ($y - 1) . ',' . $y];
    try { $cells = ep_perf(); } finally { $_GET = $garde; }

    $saisies = setting('scoutingMagasins');
    if (!is_array($saisies)) { $saisies = []; }
    // Les magasins ouverts, dans l'ordre du panel (ep_stores : la table partagée `shops`, sinon ceo_shop).
    $mags = [];
    foreach (ep_stores() as $r) {
        if (($r['status'] ?? '') !== 'Ouvert') { continue; }
        $id = (string) $r['id'];
        $ouv = $saisies[$id]['ouverture'] ?? null;
        // l'ouverture saisie au mois près ; à défaut, l'année du panel seule (since_year n'a pas de mois)
        $mags[$id] = ['nom' => (string) $r['nom'], 'ville' => (string) ($r['zone'] ?? ''), 'franchise' => (string) ($r['fr'] ?? ''),
            'ouverture' => is_string($ouv) && $ouv !== '' ? $ouv : (!empty($r['opened']) ? substr((string) $r['opened'], 0, 4) : null)];
    }

    $google = [];
    try {
        foreach (Db::rows('SELECT shop_id, rating_avg, rating_count FROM mar_avis_fiche WHERE rating_avg IS NOT NULL') as $r) {
            $google[(string) $r['shop_id']] = [(float) $r['rating_avg'], (int) $r['rating_count']];
        }
    } catch (PDOException $e) { /* réputation absente : pas de note */ }
    // Le relevé mensuel du panel consultant (même base) : table absente = pas encore de relevé.
    $releves = [];
    try {
        foreach (Db::rows('SELECT id_shop, snap_month, rating, reviews FROM mac_google_rating_snapshot') as $r) {
            $releves[(string) $r['id_shop']][(string) $r['snap_month']] = [(float) $r['rating'], (int) $r['reviews']];
        }
    } catch (PDOException $e) { /* pas de relevé */ }

    $seuils = [];
    try {
        $codes = array_values(LEV_KPI);
        $in = implode(',', array_fill(0, count($codes), '?'));
        $parCode = [];
        foreach (Db::rows("SELECT code, seuil_alerte, seuil_critique, sens, actif FROM ceo_kpi_def WHERE code IN ($in)", $codes) as $r) { $parCode[$r['code']] = $r; }
        foreach (LEV_KPI as $k => $code) {
            $r = $parCode[$code] ?? null;
            if ($r === null || (int) $r['actif'] !== 1) { continue; }
            $seuils[$k] = ['code' => $code, 'alerte' => $r['seuil_alerte'] !== null ? (float) $r['seuil_alerte'] : null,
                'critique' => $r['seuil_critique'] !== null ? (float) $r['seuil_critique'] : null, 'sens' => (string) $r['sens']];
        }
    } catch (PDOException $e) { /* Table KPI absente : pas de pastille */ }

    $out = ['leviers' => levDefs()] + levCalcul($cells, $mags, $google, $releves, $seuils, $y, $m, date('Y-m-d'));
    try { levRecurrence($out, array_map('intval', array_keys($mags))); }
    catch (Throwable $e) { $out['recurrence'] = ['erreur' => 'relevé des clients PWA illisible : ' . $e->getMessage()]; }
    return $out;
}

/**
 * La Récurrence, ajoutée à la réponse : le taux de retour des clients PWA (recurrence_pwa.php) du mois, du cumul, du
 * même mois de l'année d'avant (s'il est relevé) et des douze mois des courbes ; ses seuils, ceux du KPI « clients PWA
 * revenus ÷ clients PWA » de la Table KPI s'il existe. Le jour en cours et quelques jours manquants se relisent ici.
 */
function levRecurrence(array &$out, array $sids): void
{
    $y = (int) $out['annee']; $m = (int) $out['mois'];
    $du = sprintf('%04d-%02d-01', $y, $m); $au = date('Y-m-t', strtotime($du));
    rpCompleter($sids, $du, $au);
    $tm = rpTaux($sids, $du, $au);
    $du1 = sprintf('%04d-%02d-01', $y - 1, $m);
    $t1 = rpTaux($sids, $du1, date('Y-m-t', strtotime($du1)));
    $tc = $tc1 = null;
    if (!empty($out['cumulAu'])) {
        $fin = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, (int) $out['cumulAu'])));
        $tc = rpTaux($sids, $y . '-01-01', $fin);
        $tc1 = rpTaux($sids, ($y - 1) . '-01-01', date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y - 1, (int) $out['cumulAu']))));
    }
    // Le taux de la colonne : tous les clients identifiés en caisse (23/10/2026) ; celui des clients PWA, à part.
    $seuil = rpSeuilKpi('clientsRevenus', 'clientsIdentifies');
    $seuilPwa = rpSeuilKpi('pwaRevenus', 'pwaClients');
    if ($seuil !== null) { $out['seuils']['recurrence'] = $seuil; }
    if ($seuilPwa !== null) { $out['seuils']['recurrencePwa'] = $seuilPwa; }
    $serie = rpSerie($sids, $out['serieMois'], 'vIdent');
    $part = static fn (int $tk, int $tous): ?float => $tous > 0 ? round(100 * $tk / $tous, 1) : null;
    $cellule = static function (array $x, ?array $x1) use ($seuil, $seuilPwa, $part): array {
        $n1 = $x1 !== null ? $x1['vIdent'] : null; $n1p = $x1 !== null ? $x1['v'] : null;
        return ['v' => $x['vIdent'], 'n1' => $n1, 'delta' => $x['vIdent'] !== null && $n1 !== null ? round($x['vIdent'] - $n1, 1) : null,
            'niveau' => levNiveau($seuil, $x['vIdent']), 'clients' => $x['identifies'], 'revenus' => $x['revIdent'], 'partTickets' => $part($x['tkIdent'], $x['tickets']),
            'pwa' => ['v' => $x['v'], 'n1' => $n1p, 'delta' => $x['v'] !== null && $n1p !== null ? round($x['v'] - $n1p, 1) : null, 'niveau' => levNiveau($seuilPwa, $x['v']),
                'clients' => $x['pwa'], 'revenus' => $x['revenus'], 'partTickets' => $part($x['ticketsPwa'], $x['tickets'])],
            'inconnus' => $x['inconnus'], 'joursLus' => $x['joursLus'], 'jours' => $x['jours'], 'complet' => $x['complet'], 'repartition' => $x['repartition']];
    };
    foreach ($out['magasins'] as &$g) {
        $sid = (int) $g['id'];
        $g['mois']['recurrence'] = $cellule($tm['magasins'][$sid], $t1['magasins'][$sid] ?? null);
        if ($g['cumul'] !== null && $tc !== null) { $g['cumul']['recurrence'] = $cellule($tc['magasins'][$sid], $tc1['magasins'][$sid] ?? null); }
        $g['serie']['recurrence'] = $serie[$sid] ?? [];
    }
    unset($g);
    $out['reseau']['mois']['recurrence'] = $cellule($tm['reseau'], $t1['reseau']);
    if ($out['reseau']['cumul'] !== null && $tc !== null) { $out['reseau']['cumul']['recurrence'] = $cellule($tc['reseau'], $tc1['reseau']); }
    $out['recurrence'] = ['debut' => RP_DEBUT, 'kpi' => $seuil !== null ? $seuil['code'] : null, 'kpiPwa' => $seuilPwa !== null ? $seuilPwa['code'] : null];
}
