<?php
declare(strict_types=1);

/**
 * Vérification — les mouvements d'un produit ou d'une matière sur une période (demande du 31/10/2026 : « pouvoir
 * prendre un produit et avoir tous les mouvements pendant une période »).
 *
 * Un PRODUIT (pièces), jour par jour : gardé d'hier, produit (cuissons validées, sinon le plan), vendu (heure par
 * heure, tickets du panel), poubelle déclarée, gardé pour demain, compté, non expliqué — les journées gravées de
 * l'audit des flux (lot 2, `vxJ:`) et le relevé des tickets (`svP`). Avec les matières de sa recette et leurs
 * mouvements de stock.
 * Une MATIÈRE (stock du panel) : chaque mouvement relevé en direct (`ceo_stock_releve`, toutes les cinq minutes) —
 * livraison validée, inventaire, correction à la main, remise à zéro — avec le stock avant et après ; et ce que les
 * ventes et la poubelle des produits qui l'emploient en consomment, jour par jour (recettes aplaties).
 *
 * GET /verification/mouvements/liste?shop=           — les produits et matières du magasin, pour la recherche ;
 * GET /verification/mouvements?shop=&p=ID|m=ID&du=&au= — les mouvements (62 jours au plus, jusqu'à aujourd'hui).
 * Lecture seule.
 */

const VMO_JOURS_MAX = 62;
const VMO_SEC_CALCUL = 15.0;      // secondes pour calculer les journées de l'audit des flux qui manquent

/** La période demandée : [du, au, jours] (par défaut les quatorze derniers jours, hier compris). */
function vmoPeriode(): array
{
    $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['au'] ?? '')) ? (string) $_GET['au'] : date('Y-m-d', strtotime('-1 day'));
    if ($au > date('Y-m-d')) { $au = date('Y-m-d'); }
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['du'] ?? '')) ? (string) $_GET['du'] : date('Y-m-d', strtotime($au . ' -13 day'));
    if ($du > $au) { $du = $au; }
    if ((strtotime($au) - strtotime($du)) / 86400 >= VMO_JOURS_MAX) { $du = date('Y-m-d', strtotime($au . ' -' . (VMO_JOURS_MAX - 1) . ' day')); }
    $jours = [];
    for ($t = strtotime($du . ' 12:00:00'); date('Y-m-d', $t) <= $au; $t += 86400) { $jours[] = date('Y-m-d', $t); }
    return [$du, $au, $jours];
}

/** Les ventes d'un produit, heure par heure, d'un jour (le relevé des tickets) : [h => [q, ca]] ; portions ramenées à la pièce. */
function vmoVentesJour(int $sid, string $j, int $pid): array
{
    try { $c = setting('svP' . $sid . ':' . $j); } catch (Throwable $e) { $c = null; }
    $out = [];
    foreach ((array) (is_array($c) ? ($c['p'] ?? []) : []) as $h => $lst) {
        foreach ((array) $lst as $k => $x) {
            if ((int) explode(':', (string) $k)[0] !== $pid || !is_array($x)) { continue; }
            $f = 1.0;
            if (str_contains((string) $k, ':') && function_exists('svPortionDuNom')) { $pp = svPortionDuNom((string) $x[0]); $f = isset($pp['f']) && (float) $pp['f'] > 0 ? (float) $pp['f'] : 1.0; }
            $out[(int) $h][0] = ($out[(int) $h][0] ?? 0.0) + (float) $x[1] * $f;
            $out[(int) $h][1] = ($out[(int) $h][1] ?? 0.0) + (float) $x[2];
        }
    }
    ksort($out);
    return $out;
}

/** La ligne d'un produit dans la journée gravée de l'audit des flux, ou null. */
function vmoLigneJour(?array $g, int $pid): ?array
{
    foreach ((array) ($g['l'] ?? []) as $x) { if ((int) ($x['p'] ?? 0) === $pid) { return $x; } }
    return null;
}

/** Les mouvements de stock relevés d'une matière entre deux instants : [{at, type, avant, apres, delta}]. */
function vmoMouvementsMatiere(int $sid, int $mid, string $du, string $au): array
{
    try {
        if (function_exists('invReleveTables')) { invReleveTables(); }
        $rows = Db::rows("SELECT r.avant, r.qty, r.modif, r.vu, e.type, e.at FROM ceo_stock_releve r LEFT JOIN ceo_stock_evenement e ON e.id = r.evenement_id
            WHERE r.shop_id = ? AND r.material_id = ? AND COALESCE(e.at, r.modif, r.vu) >= ? AND COALESCE(e.at, r.modif, r.vu) < ? ORDER BY COALESCE(e.at, r.modif, r.vu), r.id",
            [$sid, $mid, $du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00']);
    } catch (Throwable $e) { return []; }
    return vmoMouvements($rows);
}

/**
 * Les lignes du relevé d'une matière en mouvements — fonction pure. Un inventaire qui suit une remise à zéro (Halle :
 * le panel efface le solde, puis pose le compté) se lit face au solde effacé : c'est lui que le panel attendait.
 */
function vmoMouvements(array $rows): array
{
    $out = []; $efface = null;
    foreach ($rows as $r) {
        $av = $r['avant'] !== null ? (float) $r['avant'] : null; $q = (float) $r['qty'];
        if (($r['type'] ?? '') === 'effacement') { $efface = $av; }
        elseif (($r['type'] ?? '') === 'inventaire' && $efface !== null) { $av = $efface; $efface = null; }
        $at = substr((string) ($r['at'] ?? $r['modif'] ?? $r['vu']), 0, 16);
        $d = $av === null ? null : $q - $av;
        $type = (string) ($r['type'] ?? 'mouvement');
        // Un mouvement isolé (hors rafale) : une hausse est une entrée à la main, une baisse une correction.
        if ($type === 'mouvement' || $type === '') { $type = $d !== null && $d > 0 ? 'entree' : 'correction'; }
        $out[] = ['at' => $at, 'type' => $type, 'avant' => $av === null ? null : round($av, 3), 'apres' => round($q, 3), 'delta' => $d === null ? null : round($d, 3)];
    }
    return $out;
}

/** Les produits qui emploient une matière (recettes aplaties) : [pid => quantité par pièce]. */
function vmoProduitsDeMatiere(int $mid): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT p.id pid, f.quantity q, r.yield_quantity y FROM flattened_recipe_ingredient f JOIN product p ON p.id_recipe = f.id_recipe
              LEFT JOIN product_recipe r ON r.id = p.id_recipe WHERE f.id_material = ?', [$mid]) as $r) {
            $y = is_numeric($r['y'] ?? null) && (float) $r['y'] > 0 ? (float) $r['y'] : 1.0;
            $q = (float) $r['q'] / $y;
            if ($q > 0) { $out[(int) $r['pid']] = ($out[(int) $r['pid']] ?? 0.0) + $q; }
        }
    } catch (Throwable $e) { /* sans recettes */ }
    return $out;
}

/** Le nom réseau d'un produit. */
function vmoNomProduit(int $pid, ?string $defaut = null): string
{
    if (function_exists('svCatalogue')) { $c = svCatalogue(); if (isset($c[$pid]['nom'])) { return (string) $c[$pid]['nom']; } }
    if (function_exists('invNomsProduits')) { $n = invNomsProduits([$pid]); if (isset($n[$pid])) { return (string) $n[$pid]; } }
    return $defaut ?? ('Produit ' . $pid);
}

/**
 * Les journées d'un produit — fonction pure (bin/tests_verification.php). $G : [jour => journée gravée|null] ;
 * $V : [jour => [h => [q, ca]]]. Rend les jours, les totaux et le journal (la suite des mouvements, dans l'ordre).
 */
function vmoProduitJours(int $pid, array $jours, array $G, array $V): array
{
    $J = []; $T = ['garde0' => null, 'prod' => 0.0, 'vendu' => 0.0, 'ca' => 0.0, 'poubelle' => 0.0, 'garde' => null, 'nonExpl' => 0.0, 'mesure' => 0, 'jours' => 0, 'prix' => null];
    $journal = [];
    foreach ($jours as $j) {
        $x = vmoLigneJour($G[$j] ?? null, $pid);
        $h = $V[$j] ?? [];
        $q = 0.0; $ca = 0.0; foreach ($h as [$qh, $cah]) { $q += $qh; $ca += $cah; }
        $gl = $G[$j] ?? null;
        $L = ['date' => $j, 'calcule' => $gl !== null, 'auPlan' => $x !== null, 'heures' => array_map(static fn ($v) => round($v[0], 2), $h), 'ca' => round($ca, 2), 'venduTickets' => round($q, 2)];
        if ($x !== null) {
            $dispo = (float) $x['s0'] + (float) $x['so'];
            $ne = $dispo - (float) $x['vd'] - (float) $x['jd'] - (float) $x['r'];
            $L += ['garde0' => (float) $x['s0'], 'prod' => (float) $x['so'], 'mesure' => (bool) $x['ok'], 'dispo' => round($dispo, 1), 'vendu' => (float) $x['vd'],
                'poubelle' => (float) $x['jd'], 'garde' => (float) $x['r'], 'compte' => $x['co'], 'nonExpl' => round($ne, 1), 'prix' => $x['x']];
            if ($T['garde0'] === null) { $T['garde0'] = (float) $x['s0']; }
            $T['garde'] = (float) $x['r']; $T['prod'] += (float) $x['so']; $T['vendu'] += (float) $x['vd']; $T['poubelle'] += (float) $x['jd']; $T['nonExpl'] += $ne;
            if ($x['ok']) { $T['mesure']++; }
            if ($x['x'] !== null) { $T['prix'] = (float) $x['x']; }
            $T['jours']++;
        } else {
            $L += ['vendu' => round($q, 2)];
            $T['vendu'] += $q;
        }
        $T['ca'] += $ca;
        // Le journal du jour : le matin, la production, les ventes heure par heure, la poubelle, le soir.
        if ($x !== null && (float) $x['s0'] > 0) { $journal[] = ['date' => $j, 'h' => 'matin', 'type' => 'garde0', 'q' => (float) $x['s0'], 'texte' => 'Gardé d’hier : ' . vmoQ((float) $x['s0'])]; }
        if ($x !== null && (float) $x['so'] > 0) { $journal[] = ['date' => $j, 'h' => 'matin', 'type' => 'prod', 'q' => (float) $x['so'], 'texte' => ($x['ok'] ? 'Produit (cuisson validée) : ' : 'Produit au plan (cuisson non validée) : ') . vmoQ((float) $x['so'])]; }
        foreach ($h as $hh => [$qh, $cah]) { if ($qh > 0) { $journal[] = ['date' => $j, 'h' => sprintf('%02dh', $hh), 'type' => 'vente', 'q' => -$qh, 'texte' => 'Vendu ' . vmoQ($qh) . ' (' . number_format($cah, 2, ',', ' ') . ' €)']; } }
        if ($x !== null && (float) $x['jd'] > 0) { $journal[] = ['date' => $j, 'h' => 'soir', 'type' => 'poubelle', 'q' => -(float) $x['jd'], 'texte' => 'Poubelle déclarée : ' . vmoQ((float) $x['jd'])]; }
        if ($x !== null && (float) $x['r'] > 0) { $journal[] = ['date' => $j, 'h' => 'soir', 'type' => 'garde', 'q' => (float) $x['r'], 'texte' => 'Gardé pour demain : ' . vmoQ((float) $x['r']) . ($gl !== null && !empty($gl['enr']) ? ' (clôture enregistrée)' : ' (proposition, clôture non enregistrée)')]; }
        if ($x !== null && abs($ne) >= 0.5) { $journal[] = ['date' => $j, 'h' => 'soir', 'type' => 'nonExpl', 'q' => -$ne, 'texte' => ($ne > 0 ? 'Non expliqué : ' . vmoQ($ne) . ' ni vendus, ni jetés, ni gardés' : 'Vendu ' . vmoQ(-$ne) . ' de plus que disponible') . ($x['ok'] ? '' : ' (produit au plan)')]; }
        $J[] = $L;
    }
    foreach (['prod', 'vendu', 'ca', 'poubelle', 'nonExpl'] as $k) { $T[$k] = round($T[$k], 2); }
    return ['jours' => $J, 'totaux' => $T, 'journal' => $journal];
}

function vmoQ(float $q): string { $r = round($q, 1); return (abs($r - round($r)) < 0.05 ? number_format($r, 0, ',', ' ') : number_format($r, 1, ',', ' ')) . ' pce' . (abs($r) >= 2 ? 's' : ''); }

/**
 * Une matière jour par jour — fonction pure : les mouvements relevés et la consommation théorique ($C : [jour => q]).
 * Rend les jours (livré, corrigé, inventaire, théorique, stock du soir) et les totaux.
 */
function vmoMatiereJours(array $jours, array $M, array $C): array
{
    $parJour = [];
    foreach ($M as $m) { $parJour[substr($m['at'], 0, 10)][] = $m; }
    $J = []; $T = ['livre' => 0.0, 'corrige' => 0.0, 'theorique' => 0.0, 'inventaires' => 0, 'ecartInventaires' => 0.0, 'stockDebut' => null, 'stockFin' => null];
    foreach ($M as $m) { if ($m['avant'] !== null) { $T['stockDebut'] = $m['avant']; break; } }
    $dernier = $T['stockDebut'];
    foreach ($jours as $j) {
        $L = ['date' => $j, 'livre' => 0.0, 'corrige' => 0.0, 'inventaire' => null, 'theorique' => round((float) ($C[$j] ?? 0), 3), 'mouvements' => $parJour[$j] ?? []];
        foreach ($L['mouvements'] as $m) {
            $d = (float) ($m['delta'] ?? 0);
            if (in_array($m['type'], ['livraison', 'entree'], true) && $d > 0) { $L['livre'] += $d; }
            elseif ($m['type'] === 'inventaire') { $L['inventaire'] = ['compte' => $m['apres'], 'avant' => $m['avant'], 'ecart' => $m['delta']]; $T['inventaires']++; $T['ecartInventaires'] += (float) ($m['delta'] ?? 0); }
            elseif ($m['type'] !== 'effacement') { $L['corrige'] += $d; }
            $dernier = $m['apres'];
        }
        $L['stockSoir'] = $dernier;
        $T['livre'] += $L['livre']; $T['corrige'] += $L['corrige']; $T['theorique'] += $L['theorique'];
        $J[] = $L;
    }
    $T['stockFin'] = $dernier;
    foreach (['livre', 'corrige', 'theorique', 'ecartInventaires'] as $k) { $T[$k] = round($T[$k], 3); }
    return ['jours' => $J, 'totaux' => $T];
}

/** GET /verification/mouvements/liste?shop= — les produits (vendus ou au plan sur trente jours) et les matières relevées. */
function ep_verification_mouvements_liste(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { $sid = (int) (array_key_first(vfMagasins()) ?? 0); }
    if ($sid <= 0) { http_response_code(400); return ['error' => 'aucun magasin']; }
    try { $c = setting('vmoListe:' . $sid); } catch (Throwable $e) { $c = null; }
    if (is_array($c) && isset($c['le'], $c['v']) && time() - (int) $c['le'] < 3600 && empty($_GET['rafraichir'])) { return $c['v']; }
    $p = [];
    for ($i = 1; $i <= 30; $i++) {
        $j = date('Y-m-d', strtotime('-' . $i . ' day'));
        try { $g = setting('svP' . $sid . ':' . $j); } catch (Throwable $e) { $g = null; }
        foreach ((array) (is_array($g) ? ($g['p'] ?? []) : []) as $lst) {
            foreach ((array) $lst as $k => $x) {
                $pid = (int) explode(':', (string) $k)[0];
                if ($pid <= 0 || !is_array($x)) { continue; }
                if (!isset($p[$pid])) { $p[$pid] = ['nom' => preg_replace('/ — .*$/u', '', (string) $x[0]), 'q' => 0.0]; }
                $p[$pid]['q'] += (float) $x[1];
            }
        }
        $gv = function_exists('vxJour') ? vxJour($sid, $j, false) : null;
        foreach ((array) ($gv['l'] ?? []) as $x) { $pid = (int) $x['p']; if (!isset($p[$pid])) { $p[$pid] = ['nom' => (string) $x['n'], 'q' => 0.0, 'cat' => (string) $x['c']]; } else { $p[$pid]['cat'] = (string) $x['c']; } }
    }
    $mids = [];
    try {
        if (function_exists('invReleveTables')) { invReleveTables(); }
        foreach (Db::rows('SELECT material_id mid, COUNT(*) n FROM ceo_stock_releve WHERE shop_id = ? GROUP BY material_id', [$sid]) as $r) { $mids[(int) $r['mid']] = (int) $r['n']; }
    } catch (Throwable $e) { /* sans relevé */ }
    $infos = function_exists('invMatieres') ? invMatieres(array_keys($mids)) : [];
    $out = ['shop' => (string) $sid, 'magasins' => array_map(static fn ($id, $nom) => ['id' => (string) $id, 'nom' => $nom], array_keys(vfMagasins()), array_values(vfMagasins())), 'produits' => [], 'matieres' => []];
    foreach ($p as $pid => $x) { $out['produits'][] = ['id' => $pid, 'nom' => (string) $x['nom'], 'cat' => (string) ($x['cat'] ?? ''), 'q' => round($x['q'], 1)]; }
    usort($out['produits'], static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    foreach ($mids as $mid => $n) { $i = $infos[$mid] ?? ['nom' => 'Matière ' . $mid, 'categorie' => '', 'unite' => 'pce']; $out['matieres'][] = ['id' => $mid, 'nom' => $i['nom'], 'cat' => $i['categorie'], 'unite' => $i['unite'], 'n' => $n]; }
    usort($out['matieres'], static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    try { svGrave('vmoListe:' . $sid, ['le' => time(), 'v' => $out]); } catch (Throwable $e) { /* sans cache */ }
    return $out;
}

/** GET /verification/mouvements?shop=&p=|m=&du=&au= */
function ep_verification_mouvements(): array
{
    @set_time_limit(90);
    $sid = (int) ($_GET['shop'] ?? 0);
    $pid = (int) ($_GET['p'] ?? 0); $mid = (int) ($_GET['m'] ?? 0);
    if ($sid <= 0 || ($pid <= 0 && $mid <= 0)) { http_response_code(400); return ['error' => 'shop et p (produit) ou m (matière) requis']; }
    [$du, $au, $jours] = vmoPeriode();
    $base = ['shop' => (string) $sid, 'du' => $du, 'au' => $au, 'lu' => date('c')];
    if ($pid > 0) {
        // Les journées de l'audit des flux : gravées, calculées dans le budget pour celles qui manquent.
        $G = []; $t0 = microtime(true); $manquent = 0;
        foreach ($jours as $j) {
            $g = function_exists('vxJour') ? vxJour($sid, $j, false) : null;
            if ($g === null && function_exists('vxJour') && microtime(true) - $t0 < VMO_SEC_CALCUL) { $g = vxJour($sid, $j, true); }
            if ($g === null) { $manquent++; }
            $G[$j] = $g;
        }
        $V = [];
        foreach ($jours as $j) { $V[$j] = vmoVentesJour($sid, $j, $pid); }
        $P = vmoProduitJours($pid, $jours, $G, $V);
        $nomJ = null; $cat = '';
        foreach ($G as $g) { $x = vmoLigneJour($g, $pid); if ($x !== null) { $nomJ = (string) $x['n']; $cat = (string) $x['c']; break; } }
        // Les matières de sa recette, et leurs mouvements de stock sur la période.
        $rec = function_exists('invRecettes') ? (invRecettes([$pid])[$pid] ?? []) : [];
        $infos = function_exists('invMatieres') ? invMatieres(array_keys($rec)) : [];
        $mats = [];
        foreach ($rec as $m => $qp) {
            $mv = vmoMouvementsMatiere($sid, (int) $m, $du, $au);
            $i = $infos[$m] ?? ['nom' => 'Matière ' . $m, 'categorie' => '', 'unite' => 'pce'];
            $mats[] = ['id' => (int) $m, 'nom' => $i['nom'], 'cat' => $i['categorie'], 'unite' => $i['unite'], 'parPiece' => round($qp, 4),
                'theorique' => round(($P['totaux']['vendu'] + $P['totaux']['poubelle']) * $qp, 3), 'mouvements' => $mv];
            foreach ($mv as $x) {
                $P['journal'][] = ['date' => substr($x['at'], 0, 10), 'h' => substr($x['at'], 11, 5), 'type' => 'stock:' . $x['type'], 'q' => $x['delta'],
                    'texte' => $i['nom'] . ' — ' . vmoTexteStock($x, $i['unite'])];
            }
        }
        usort($mats, static fn ($a, $b) => count($b['mouvements']) <=> count($a['mouvements']));
        $ordre = ['matin' => '00', 'soir' => '23:58'];
        usort($P['journal'], static fn ($a, $b) => strcmp($a['date'] . ($ordre[$a['h']] ?? $a['h']), $b['date'] . ($ordre[$b['h']] ?? $b['h'])));
        return $base + ['type' => 'produit', 'produit' => ['id' => $pid, 'nom' => vmoNomProduit($pid, $nomJ), 'cat' => $cat, 'prix' => $P['totaux']['prix']],
            'jours' => $P['jours'], 'totaux' => $P['totaux'], 'journal' => $P['journal'], 'matieres' => $mats, 'aCalculer' => $manquent,
            'source' => 'les journées de l’audit des flux (cuissons, clôtures, poubelle déclarée), les tickets du panel heure par heure, le stock du panel relevé toutes les cinq minutes'];
    }
    // Une matière
    $info = (function_exists('invMatieres') ? invMatieres([$mid]) : [])[$mid] ?? ['nom' => 'Matière ' . $mid, 'categorie' => '', 'unite' => 'pce'];
    if (function_exists('invUnitesLive')) { $u = invUnitesLive($sid)[$mid] ?? null; if ($u !== null) { $info['unite'] = in_array($u, ['g', 'kg'], true) ? 'g' : (in_array($u, ['ml', 'cl', 'l'], true) ? 'ml' : 'pce'); } }
    $M = vmoMouvementsMatiere($sid, $mid, $du, $au);
    // La consommation théorique : ventes (tickets) et poubelle (journées gravées) des produits qui l'emploient × recette.
    $prods = vmoProduitsDeMatiere($mid);
    $C = []; $parProduit = [];
    foreach ($jours as $j) {
        try { $c = setting('svP' . $sid . ':' . $j); } catch (Throwable $e) { $c = null; }
        foreach ((array) (is_array($c) ? ($c['p'] ?? []) : []) as $lst) {
            foreach ((array) $lst as $k => $x) {
                $p = (int) explode(':', (string) $k)[0];
                if (!isset($prods[$p]) || !is_array($x)) { continue; }
                $f = 1.0;
                if (str_contains((string) $k, ':') && function_exists('svPortionDuNom')) { $pp = svPortionDuNom((string) $x[0]); $f = isset($pp['f']) && (float) $pp['f'] > 0 ? (float) $pp['f'] : 1.0; }
                $q = (float) $x[1] * $f;
                $C[$j] = ($C[$j] ?? 0.0) + $q * $prods[$p];
                $parProduit[$p]['v'] = ($parProduit[$p]['v'] ?? 0.0) + $q;
            }
        }
        $g = function_exists('vxJour') ? vxJour($sid, $j, false) : null;
        foreach ((array) ($g['l'] ?? []) as $x) {
            $p = (int) $x['p'];
            if (isset($prods[$p]) && (float) $x['jd'] > 0) { $C[$j] = ($C[$j] ?? 0.0) + (float) $x['jd'] * $prods[$p]; $parProduit[$p]['w'] = ($parProduit[$p]['w'] ?? 0.0) + (float) $x['jd']; }
        }
    }
    $R = vmoMatiereJours($jours, $M, $C);
    $journal = array_map(static fn ($x) => ['date' => substr($x['at'], 0, 10), 'h' => substr($x['at'], 11, 5), 'type' => 'stock:' . $x['type'], 'q' => $x['delta'], 'texte' => vmoTexteStock($x, $info['unite'])], $M);
    $pl = [];
    foreach ($parProduit as $p => $x) {
        $pl[] = ['id' => $p, 'nom' => vmoNomProduit($p), 'parPiece' => round($prods[$p], 4), 'vendu' => round($x['v'] ?? 0, 1), 'poubelle' => round($x['w'] ?? 0, 1),
            'consomme' => round((($x['v'] ?? 0) + ($x['w'] ?? 0)) * $prods[$p], 3)];
    }
    usort($pl, static fn ($a, $b) => $b['consomme'] <=> $a['consomme']);
    return $base + ['type' => 'matiere', 'matiere' => ['id' => $mid, 'nom' => $info['nom'], 'cat' => $info['categorie'], 'unite' => $info['unite']],
        'jours' => $R['jours'], 'totaux' => $R['totaux'], 'journal' => $journal, 'produits' => $pl, 'nProduitsRecette' => count($prods),
        'source' => 'le stock du panel relevé toutes les cinq minutes (livraisons validées, inventaires, corrections), les tickets et la poubelle déclarée × recettes aplaties'];
}

/** Un mouvement de stock en clair. */
function vmoTexteStock(array $x, string $u): string
{
    $q = static fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',') . ' ' . $u;
    $d = $x['delta'];
    return match ($x['type']) {
        'livraison' => 'Livraison validée : +' . $q(abs((float) $d)) . ' (stock ' . $q($x['avant']) . ' → ' . $q($x['apres']) . ')',
        'entree' => 'Entrée à la main : +' . $q(abs((float) $d)) . ' (stock ' . $q($x['avant']) . ' → ' . $q($x['apres']) . ')',
        'inventaire' => 'Inventaire : compté ' . $q($x['apres']) . ($x['avant'] !== null ? ' (le panel attendait ' . $q($x['avant']) . ', écart ' . ($d >= 0 ? '+' : '−') . $q(abs((float) $d)) . ')' : ''),
        'effacement' => 'Remise à zéro avant inventaire (le panel attendait ' . $q($x['avant']) . ')',
        default => 'Stock corrigé à la main : ' . ($d === null ? '' : ($d >= 0 ? '+' : '−') . $q(abs((float) $d))) . ' (stock ' . $q($x['avant']) . ' → ' . $q($x['apres']) . ')',
    };
}
