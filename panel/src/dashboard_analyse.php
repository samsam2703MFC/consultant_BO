<?php

declare(strict_types=1);

/*
 * Vendre › Analyse du dashboard magasin (15/11/2026, maquettes A, E et F de docs/maquettes/rail-franchise : « go E et F,
 * A en entrée »). Les pages lisent surtout des routes qui existent déjà :
 *   /analyse/developpement/magasin   les 13 semaines de chaque produit du magasin, son prix et sa marge (la page A)
 *   /analyse/produits/magasin        la fiche d'un produit : ses semaines et son prix face au réseau (E)
 *   /analyse/categories/magasin      les semaines d'une famille (F)
 *   /analyse/categories/journee      une journée d'une famille, heure par heure, face aux 6 mêmes jours d'avant (F)
 *   /exploitation/invendus/detail    les pièces jetées, produit par produit (E et F)
 * Ce module ajoute les lectures qui leur manquaient :
 *   GET /dashboard/analyse/periode?shop=4&du=…&au=…[&prec=…]   le magasin sur la période choisie (15/11/2026)
 *   GET /dashboard/analyse/reseau?shop=4&du=…&au=…&pid=1610006 | &groupe=Viennoiserie
 *   GET /dashboard/analyse/mois?shop=4&pids=1610006,1610004
 *   GET /dashboard/analyse/jours?shop=4&pid=1610006[&au=…]
 * La période : semaine, mois, trimestre, année, 12 semaines closes (27/11/2026), 13 semaines ou du … au ; elle se compare à la
 * période d'avant (prec), au jour.
 *
 * Les autres magasins n'y ont ni nom, ni identifiant, ni volume ni chiffre : seulement des pièces rapportées à leur
 * chiffre (« pour 10 k€ ») et des évolutions. De quoi se comparer à taille égale, pas de quoi lire le chiffre d'un voisin.
 * Lecture seule ; les tranches closes viennent du cache des ventes (apTranches2), les invendus de celui de invLignes.
 */

/**
 * La marge réalisée d'un produit sur une période, à partir des pièces, du chiffre TTC et du prix du catalogue
 * (adPrixCatalogue : [ttc, ht, cout]). Vendu aussi en portions ou en variantes (prix moyen encaissé à plus de 15 % du
 * prix de la pièce entière), on applique le taux de marge de la recette au CA HT au lieu du coût d'une pièce entière.
 * → [caHt, marge, motif, methode]
 */
function daMarge(float $q, float $ca, ?array $c): array
{
    $caHt = null; $marge = null; $motif = null; $methode = null;
    if ($c !== null && $c['ht'] !== null && $c['ttc'] !== null && $c['ttc'] > 0) { $caHt = $ca * $c['ht'] / $c['ttc']; }
    if ($q <= 0) { $marge = 0.0; }
    elseif ($c === null || $c['ht'] === null) { $motif = 'prix du catalogue inconnu'; }
    elseif ($c['cout'] === null) { $motif = 'coût de la recette manquant'; }
    elseif ($c['cout'] >= $c['ht'] || $c['cout'] < 0.05 * $c['ht']) { $motif = 'recette à vérifier'; }
    elseif ($caHt !== null) {
        $prixMoyen = $ca / $q;
        if ($c['ttc'] !== null && abs($prixMoyen / $c['ttc'] - 1) <= 0.15) { $marge = $caHt - $q * $c['cout']; $methode = 'pieces'; }
        else { $marge = $caHt * (1 - $c['cout'] / $c['ht']); $methode = 'taux'; }
    }
    return [$caHt, $marge, $motif, $methode];
}

/** Le groupe (la famille) d'une catégorie, comme l'onglet Produits : le premier segment de svGroupes. */
function daGroupeDe(string $cat, array $grpDe): string
{
    return explode(' · ', (string) ($grpDe[$cat] ?? 'Autres'))[0];
}

/* --- La période (15/11/2026, « on doit pouvoir choisir mois, semaine, trimestre, année et range ») ------------------- */

/** Les jours de du à au, bornes comprises. */
function daJours(string $du, string $au): int
{
    return (int) round((strtotime($au . ' 12:00:00') - strtotime($du . ' 12:00:00')) / 86400) + 1;
}

/**
 * La découpe d'une période pour ses courbes : des jours jusqu'à 31 jours, des semaines (lundi → dimanche, coupées aux bornes)
 * jusqu'à 190 jours, des mois au-delà. → [pas, [[du, au], …]]
 */
function daDecoupe(string $du, string $au): array
{
    $n = daJours($du, $au); $out = [];
    if ($n <= 31) {
        for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' 12:00:00 +1 day'))) { $out[] = [$d, $d]; }
        return ['jour', $out];
    }
    if ($n <= 190) {
        $d = $du;
        while ($d <= $au) {
            $dim = date('Y-m-d', strtotime($d . ' 12:00:00 sunday this week'));
            $f = min($dim, $au); $out[] = [$d, $f]; $d = date('Y-m-d', strtotime($f . ' 12:00:00 +1 day'));
        }
        return ['semaine', $out];
    }
    $d = $du;
    while ($d <= $au) { $f = min(date('Y-m-t', strtotime($d . ' 12:00:00')), $au); $out[] = [$d, $f]; $d = date('Y-m-d', strtotime($f . ' 12:00:00 +1 day')); }
    return ['mois', $out];
}

/**
 * La période demandée (du, au ; prec : le début de la période d'avant, sinon la même longueur juste avant), arrêtée à
 * aujourd'hui. La période d'avant compte autant de jours que la période lue (une période entamée se compare à date), sans
 * jamais chevaucher la période. → [du, au, prevDu, prevAu] ou un message d'erreur.
 */
function daPeriode(): array|string
{
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? ''); $prec = (string) ($_GET['prec'] ?? '');
    $auj = date('Y-m-d');
    if ($du === '' && $au === '') { $t = array_slice(apTranches(3), -13); $du = $t[0][0]; $au = $auj; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au < $du) { return 'du et au (AAAA-MM-JJ) attendus, du avant au'; }
    if ($du > $auj) { return 'la période commence après aujourd’hui'; }
    $au = min($au, $auj);
    if (daJours($du, $au) > 400) { return 'une période de 400 jours au plus'; }
    $len = daJours($du, $au);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prec) || $prec >= $du) { $prec = date('Y-m-d', strtotime($du . ' 12:00:00 -' . $len . ' day')); }
    $pAu = min(date('Y-m-d', strtotime($prec . ' 12:00:00 +' . ($len - 1) . ' day')), date('Y-m-d', strtotime($du . ' 12:00:00 -1 day')));
    return [$du, $au, $prec, $pAu];
}

/**
 * L'évolution d'une série sur la période face à la période d'avant, au jour (des mois de 30 et 31 jours se comparent) :
 * nouveau, arrêté, peu vendu, en hausse (≥ + 8 %), en baisse (≤ − 8 %), stable — les seuils de l'onglet Produits.
 */
function daEvoPeriode(float $v, float $q, int $j, float $vp, float $qp, int $jp, array $vt): array
{
    $r = $j > 0 ? $v / $j : 0.0; $rp = $jp > 0 ? $vp / $jp : 0.0;
    $pct = $rp > 0 ? round(100 * ($r / $rp - 1), 1) : null;
    $depuis = null;
    if ($r > 0 && $rp < 0.15 * $r) {
        $verdict = 'nouveau';
        $moy = $v / max(1, count($vt));
        foreach ($vt as $i => $x) { if ($x >= 0.25 * $moy) { $depuis = $i; break; } }
    } elseif ($rp > 0 && $r < 0.15 * $rp) { $verdict = 'arrete'; }
    elseif ($q + $qp < max(4.0, 2.0 * ($j + $jp) / 7)) { $verdict = 'peu'; }
    elseif ($pct !== null && $pct >= 8.0) { $verdict = 'monte'; }
    elseif ($pct !== null && $pct <= -8.0) { $verdict = 'baisse'; }
    else { $verdict = 'stable'; }
    return ['pct' => $pct, 'verdict' => $verdict, 'depuisI' => $depuis];
}

/**
 * Les produits de la période cumulés par famille ($cle = 'groupe') ou par catégorie ('cat', avec sa famille), tranche par
 * tranche, et leur évolution face à la période d'avant (daEvoPeriode). Les produits vendus seulement sur la période d'avant
 * y comptent : une référence arrêtée fait baisser sa catégorie. n : les références vendues sur la période. Une ligne sans
 * chiffre sur la période n'est pas rendue ; du plus gros chiffre au plus petit.
 * $X : [pid => ['cat', 'groupe', 'v' => [n], 'q' => [n], 'vp', 'qp']].
 */
function daCumuls(array $X, string $cle, int $n, int $j, int $jp): array
{
    $G = [];
    foreach ($X as $x) {
        $k = (string) $x[$cle];
        if (!isset($G[$k])) {
            $G[$k] = ['nom' => $k] + ($cle === 'cat' ? ['groupe' => (string) $x['groupe']] : [])
                + ['n' => 0, 'v' => array_fill(0, $n, 0.0), 'q' => array_fill(0, $n, 0.0), 'vp' => 0.0, 'qp' => 0.0];
        }
        if (array_sum($x['v']) > 0 || array_sum($x['q']) > 0) { $G[$k]['n']++; }
        for ($i = 0; $i < $n; $i++) { $G[$k]['v'][$i] += $x['v'][$i]; $G[$k]['q'][$i] += $x['q'][$i]; }
        $G[$k]['vp'] += $x['vp']; $G[$k]['qp'] += $x['qp'];
    }
    $out = [];
    foreach ($G as $g) {
        if (array_sum($g['v']) <= 0) { continue; }
        $out[] = array_merge($g, ['v' => array_map(static fn ($a) => round($a, 2), $g['v']), 'q' => array_map(static fn ($a) => round($a, 1), $g['q']),
            'vp' => round($g['vp'], 2), 'qp' => round($g['qp'], 1), 'evo' => daEvoPeriode(array_sum($g['v']), array_sum($g['q']), $j, $g['vp'], $g['qp'], $jp, $g['v'])]);
    }
    usort($out, static fn ($a, $b) => array_sum($b['v']) <=> array_sum($a['v']));
    return $out;
}

/**
 * GET /dashboard/analyse/periode?shop=4&du=2026-10-01&au=2026-10-31[&prec=2026-09-01] — le magasin sur une période :
 * chaque produit vendu (sa catégorie, sa famille), ses pièces et son chiffre tranche par tranche (des jours, des semaines
 * ou des mois, daDecoupe), ses totaux sur la période d'avant, son évolution (daEvoPeriode), son prix et sa recette du
 * catalogue ; les familles (les catégories principales du catalogue), les catégories (27/11/2026, la page d'une famille
 * semaine par semaine) et le magasin entier de même (daCumuls). Aucun autre magasin n'est lu.
 */
function ep_dashboard_analyse_periode(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop attendu']; }
    if (!function_exists('apTranches2') || !function_exists('adPrixCatalogue') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'les ventes du panel ne se lisent pas']; }
    $P = daPeriode();
    if (is_string($P)) { http_response_code(422); return ['error' => $P]; }
    [$du, $au, $pDu, $pAu] = $P;
    @set_time_limit(120);
    [$pas, $T] = daDecoupe($du, $au);
    $n = count($T);
    $lu = apTranches2(array_merge(array_map(static fn ($t) => [$sid, $t[0], $t[1]], $T), [[$sid, $pDu, $pAu]]));
    $prec = $lu[$sid . ':' . $pDu] ?? null;
    $catDe = function_exists('svCategories') ? svCategories() : [];
    $grpDe = function_exists('svGroupes') ? svGroupes() : [];
    $prix = adPrixCatalogue($sid);
    $j = daJours($du, $au); $jp = daJours($pDu, $pAu);
    $X = []; $muettes = [];
    $ajoute = static function (int $id, array $x) use (&$X, $catDe, $grpDe, $n): void {
        if (isset($X[$id])) { return; }
        $c = (string) ($catDe[$id] ?? ($x[1] ?? ''));
        $X[$id] = ['id' => $id, 'nom' => (string) ($x[0] ?? ('Produit ' . $id)), 'cat' => $c, 'groupe' => daGroupeDe($c, $grpDe),
            'v' => array_fill(0, $n, 0.0), 'q' => array_fill(0, $n, 0.0), 'vp' => 0.0, 'qp' => 0.0];
    };
    foreach ($T as $i => $t) {
        $p = $lu[$sid . ':' . $t[0]] ?? null;
        if (!is_array($p)) { $muettes[] = $i; continue; }
        foreach ($p as $id => $x) { $id = (int) $id; $ajoute($id, $x); $X[$id]['v'][$i] += (float) ($x[3] ?? 0); $X[$id]['q'][$i] += (float) ($x[2] ?? 0); }
    }
    foreach (is_array($prec) ? $prec : [] as $id => $x) { $id = (int) $id; $ajoute($id, $x); $X[$id]['vp'] += (float) ($x[3] ?? 0); $X[$id]['qp'] += (float) ($x[2] ?? 0); }
    $produits = []; $tot = ['v' => array_fill(0, $n, 0.0), 'q' => array_fill(0, $n, 0.0), 'vp' => 0.0, 'qp' => 0.0];
    foreach ($X as $id => $x) {
        $v = array_sum($x['v']); $q = array_sum($x['q']);
        for ($i = 0; $i < $n; $i++) { $tot['v'][$i] += $x['v'][$i]; $tot['q'][$i] += $x['q'][$i]; }
        $tot['vp'] += $x['vp']; $tot['qp'] += $x['qp'];
        if ($v <= 0 && $q <= 0) { continue; }
        $c = $prix[$id] ?? null;
        $produits[] = ['id' => $id, 'nom' => $x['nom'], 'cat' => $x['cat'], 'groupe' => $x['groupe'],
            'v' => array_map(static fn ($a) => round($a, 2), $x['v']), 'q' => array_map(static fn ($a) => round($a, 1), $x['q']),
            'vp' => round($x['vp'], 2), 'qp' => round($x['qp'], 1), 'evo' => daEvoPeriode($v, $q, $j, $x['vp'], $x['qp'], $jp, $x['v']),
            'prix' => $c !== null && $c['ttc'] !== null ? round($c['ttc'], 2) : null, 'prixHt' => $c !== null && $c['ht'] !== null ? round($c['ht'], 2) : null,
            'cout' => $c !== null && $c['cout'] !== null ? round($c['cout'], 3) : null];
    }
    usort($produits, static fn ($a, $b) => array_sum($b['v']) <=> array_sum($a['v']));
    $groupes = daCumuls($X, 'groupe', $n, $j, $jp);
    $categories = daCumuls($X, 'cat', $n, $j, $jp);
    return ['shop' => $sid, 'du' => $du, 'au' => $au, 'jours' => $j, 'pas' => $pas, 'enCours' => $au === date('Y-m-d'),
        'tranches' => array_map(static fn ($t) => [$t[0], $t[1]], $T), 'muettes' => $muettes,
        'precedent' => ['du' => $pDu, 'au' => $pAu, 'jours' => $jp, 'lu' => is_array($prec)],
        'total' => ['v' => array_map(static fn ($a) => round($a, 2), $tot['v']), 'q' => array_map(static fn ($a) => round($a, 1), $tot['q']), 'vp' => round($tot['vp'], 2), 'qp' => round($tot['qp'], 1),
            'evo' => daEvoPeriode(array_sum($tot['v']), array_sum($tot['q']), $j, $tot['vp'], $tot['qp'], $jp, $tot['v'])],
        'groupes' => $groupes, 'categories' => $categories, 'produits' => $produits,
        'regle' => 'évolution : la période face à la période d’avant, au jour ; en hausse à + 8 % et plus, en baisse à − 8 % et moins',
        'source' => 'ventes du panel (product-category-groups) du magasin, tranche par tranche ; prix et recette : products/available'];
}

/**
 * GET /dashboard/analyse/reseau?shop=4&du=…&au=…[&prec=…]&pid=… | &groupe=… — le magasin face aux autres magasins du
 * réseau, à taille égale, sur la période (daPeriode ; sans du ni au, les 13 dernières semaines).
 *   taille        le chiffre TTC du magasin sur la période (le sien)
 *   produits      pour le produit, ou chaque produit de la famille vendu quelque part : ses pièces semaine par semaine chez
 *                 le magasin, ses pièces pour 10 k€ de chiffre, son évolution, et pour chaque autre magasin qui le vend
 *                 ses pièces pour 10 k€ et son évolution face à la période d'avant (sans nom, du plus vendeur au moins vendeur)
 *   reseau        la sélection (le produit ou la famille) chez les autres magasins, ramenée à la taille du magasin, tranche
 *                 par tranche : (Σ pièces des autres) ÷ (Σ chiffre des autres) × chiffre du magasin — idem en chiffre TTC
 *   absents       (famille) les produits que d'autres magasins vendent et pas celui-ci : combien de magasins, et ce que
 *                 cela ferait par semaine à sa taille
 */
function ep_dashboard_analyse_reseau(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $pid = (int) ($_GET['pid'] ?? 0);
    $groupe = trim((string) ($_GET['groupe'] ?? ''));
    if ($sid <= 0 || ($pid <= 0 && $groupe === '')) { http_response_code(400); return ['error' => 'shop et pid, ou shop et groupe, attendus']; }
    if (!function_exists('apTranches2') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'les ventes du panel ne se lisent pas']; }
    $per = daPeriode();
    if (is_string($per)) { http_response_code(422); return ['error' => $per]; }
    [$du, $au, $pDu, $pAu] = $per;
    @set_time_limit(120);
    $tranches = daDecoupe($du, $au)[1];
    $n = count($tranches);
    $shops = array_map('intval', array_column(Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id'), 'id'));
    if (!in_array($sid, $shops, true)) { $shops[] = $sid; }
    // Chaque magasin : la période tranche par tranche (la courbe), et la période d'avant d'un seul relevé (l'évolution).
    $couples = [];
    foreach ($shops as $s) { foreach ($tranches as $t) { $couples[] = [$s, $t[0], $t[1]]; } $couples[] = [$s, $pDu, $pAu]; }
    $lu = apTranches2($couples);
    $catDe = function_exists('svCategories') ? svCategories() : [];
    $grpDe = function_exists('svGroupes') ? svGroupes() : [];
    $j = daJours($du, $au); $jp = daJours($pDu, $pAu);
    // La période d'avant : les pièces de chaque produit retenu, par magasin.
    $QP = [];
    foreach ($shops as $s) {
        foreach ((array) ($lu[$s . ':' . $pDu] ?? []) as $id => $x) {
            $id = (int) $id; $cat = (string) ($catDe[$id] ?? ($x[1] ?? ''));
            if ($pid > 0 ? $id !== $pid : daGroupeDe($cat, $grpDe) !== $groupe) { continue; }
            $QP[$s][$id] = ($QP[$s][$id] ?? 0.0) + (float) ($x[2] ?? 0);
        }
    }
    $evoP = static fn (float $q, float $qp): ?float => $qp > 0 && $jp > 0 && $j > 0 ? round(100 * (($q / $j) / ($qp / $jp) - 1), 1) : null;

    // Par magasin : le chiffre de la période (sa taille), et les pièces / le chiffre de chaque produit retenu.
    $taille = []; $Q = []; $V = []; $noms = []; $lus = [];
    foreach ($shops as $s) {
        $taille[$s] = 0.0; $vide = true;
        foreach ($tranches as $i => $t) {
            $p = $lu[$s . ':' . $t[0]] ?? null;
            if (!is_array($p)) { continue; }
            $vide = false;
            foreach ($p as $id => $x) {
                $id = (int) $id; $ca = (float) ($x[3] ?? 0);
                $taille[$s] += $ca;
                $cat = (string) ($catDe[$id] ?? ($x[1] ?? ''));
                if ($pid > 0 ? $id !== $pid : daGroupeDe($cat, $grpDe) !== $groupe) { continue; }
                if (!isset($noms[$id])) { $noms[$id] = ['nom' => (string) ($x[0] ?? ('Produit ' . $id)), 'cat' => $cat]; }
                $Q[$s][$id] = $Q[$s][$id] ?? array_fill(0, $n, 0.0);
                $V[$s][$id] = $V[$s][$id] ?? array_fill(0, $n, 0.0);
                $Q[$s][$id][$i] += (float) ($x[2] ?? 0);
                $V[$s][$id][$i] += $ca;
            }
        }
        if (!$vide) { $lus[] = $s; }
    }
    if (!in_array($sid, $lus, true) || ($taille[$sid] ?? 0) <= 0) { http_response_code(502); return ['error' => 'le panel n’a pas rendu les ventes du magasin']; }
    $autres = array_values(array_filter($lus, static fn ($s) => $s !== $sid && $taille[$s] > 0));
    $moi = $taille[$sid];
    $p10k = static fn (float $q, float $t): ?float => $t > 0 ? round($q / $t * 10000, 1) : null;

    $produits = []; $absents = [];
    foreach ($noms as $id => $nm) {
        $qMoi = $Q[$sid][$id] ?? array_fill(0, $n, 0.0);
        $ailleurs = [];
        foreach ($autres as $s) {
            $q = $Q[$s][$id] ?? null;
            if ($q === null || array_sum($q) <= 0) { continue; }
            $ailleurs[] = ['p10k' => $p10k(array_sum($q), $taille[$s]), 'evo' => $evoP(array_sum($q), (float) ($QP[$s][$id] ?? 0))];
        }
        usort($ailleurs, static fn ($a, $b) => $b['p10k'] <=> $a['p10k']);
        if (array_sum($qMoi) <= 0) {
            if ($ailleurs !== [] && $pid <= 0) {
                $moy = array_sum(array_column($ailleurs, 'p10k')) / count($ailleurs);
                $absents[] = ['pid' => $id, 'nom' => $nm['nom'], 'cat' => $nm['cat'], 'magasins' => count($ailleurs),
                    'aVotreTaille' => round($moy / 10000 * $moi / $j * 7, 1)];
            }
            continue;
        }
        $produits[] = ['pid' => $id, 'nom' => $nm['nom'], 'cat' => $nm['cat'], 'q' => array_map(static fn ($v) => round($v, 1), $qMoi),
            'p10k' => $p10k(array_sum($qMoi), $moi), 'evo' => $evoP(array_sum($qMoi), (float) ($QP[$sid][$id] ?? 0)), 'autres' => $ailleurs];
    }
    usort($produits, static fn ($a, $b) => array_sum($b['q']) <=> array_sum($a['q']));
    usort($absents, static fn ($a, $b) => [$b['magasins'], $b['aVotreTaille']] <=> [$a['magasins'], $a['aVotreTaille']]);

    // La sélection chez les autres, ramenée à la taille du magasin, semaine par semaine.
    $tA = array_sum(array_map(static fn ($s) => $taille[$s], $autres));
    $rq = array_fill(0, $n, 0.0); $rv = array_fill(0, $n, 0.0);
    foreach ($autres as $s) {
        foreach ($Q[$s] ?? [] as $id => $q) { for ($i = 0; $i < $n; $i++) { $rq[$i] += $q[$i]; $rv[$i] += $V[$s][$id][$i]; } }
    }
    $k = $tA > 0 ? $moi / $tA : null;
    // L'évolution de la sélection chez les autres magasins, ensemble : la période face à la période d'avant, au jour.
    $qA = 0.0; $qAp = 0.0;
    foreach ($autres as $s) { $qA += array_sum(array_map('array_sum', $Q[$s] ?? [])); $qAp += array_sum($QP[$s] ?? []); }
    return ['shop' => $sid, 'pid' => $pid ?: null, 'groupe' => $pid > 0 ? null : $groupe, 'du' => $du, 'au' => $au, 'jours' => $j,
        'precedent' => ['du' => $pDu, 'au' => $pAu, 'jours' => $jp], 'tranches' => array_map(static fn ($t) => [$t[0], $t[1]], $tranches),
        'taille' => round($moi, 2), 'autresMagasins' => count($autres), 'produits' => $produits, 'absents' => $absents,
        'reseau' => $k === null ? null : ['q' => array_map(static fn ($v) => round($v * $k, 1), $rq), 'v' => array_map(static fn ($v) => round($v * $k, 2), $rv), 'evo' => $evoP($qA, $qAp)],
        'regle' => ['evolution' => 'la période face à la période d’avant, au jour',
            'p10k' => 'pièces vendues pour 10 000 € de chiffre TTC du magasin, sur la période', 'aVotreTaille' => 'par semaine'],
        'source' => 'ventes du panel (product-category-groups), chaque magasin actif du réseau ; les autres magasins sans nom ni volume'];
}

/**
 * GET /dashboard/analyse/mois?shop=4&pids=1,2 — les produits mois par mois, depuis l'ouverture (12 mois au plus, le mois en
 * cours jusqu'à aujourd'hui) : pièces, chiffre TTC, CA HT et marge réalisée (daMarge), et le chiffre de tout le magasin.
 * Un mois sans aucune vente au magasin avant le premier mois vendu n'est pas rendu : le magasin n'était pas ouvert.
 */
function ep_dashboard_analyse_mois(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $pids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['pids'] ?? ''))), static fn ($x) => $x > 0)));
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop attendu']; }
    if (!$pids || count($pids) > 80) { http_response_code(422); return ['error' => 'de 1 à 80 produits (pids)']; }
    if (!function_exists('apTranches2') || !function_exists('adPrixCatalogue') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'les ventes du panel ne se lisent pas']; }
    @set_time_limit(120);
    $auj = date('Y-m-d');
    $mois = [];
    for ($i = 11; $i >= 0; $i--) {
        $t = strtotime(date('Y-m-01') . " -$i month");
        $mois[] = ['cle' => date('Y-m', $t), 'du' => date('Y-m-01', $t), 'au' => min(date('Y-m-t', $t), $auj), 'enCours' => $i === 0];
    }
    $lu = apTranches2(array_map(static fn ($m) => [$sid, $m['du'], $m['au']], $mois));
    $prix = adPrixCatalogue($sid);
    $out = []; $total = []; $ouvert = false;
    foreach ($mois as $m) {
        $p = $lu[$sid . ':' . $m['du']] ?? null;
        $ca = is_array($p) ? array_sum(array_map(static fn ($x) => (float) ($x[3] ?? 0), $p)) : null;
        if (!$ouvert && ($ca === null || $ca <= 0)) { continue; }
        $ouvert = true;
        $m['jours'] = (int) round((strtotime($m['au'] . ' 12:00:00') - strtotime($m['du'] . ' 12:00:00')) / 86400) + 1;
        $m['lu'] = is_array($p);
        $total[] = $ca !== null ? round($ca, 2) : null;
        $ligne = [];
        foreach ($pids as $id) {
            $x = is_array($p) ? ($p[$id] ?? null) : null;
            $q = $x !== null ? (float) $x[2] : 0.0; $v = $x !== null ? (float) $x[3] : 0.0;
            [$caHt, $marge] = daMarge($q, $v, $prix[$id] ?? null);
            $ligne[(string) $id] = is_array($p) ? [round($q, 1), round($v, 2), $caHt !== null ? round($caHt, 2) : null, $marge !== null ? round($marge, 2) : null] : null;
        }
        $m['produits'] = $ligne;
        $out[] = $m;
    }
    return ['shop' => $sid, 'mois' => array_map(static fn ($m) => array_diff_key($m, ['produits' => 1]), $out),
        'produits' => array_combine(array_map('strval', $pids), array_map(static fn ($id) => array_map(static fn ($m) => $m['produits'][(string) $id], $out), $pids)),
        'totalMagasin' => $total, 'colonnes' => ['pieces', 'caTtc', 'caHt', 'marge'],
        'source' => 'ventes du panel (product-category-groups), un relevé par mois ; marge : CA HT − pièces × coût de la recette du jour, ou le taux de la recette pour un produit vendu en portions'];
}

/**
 * GET /dashboard/analyse/jours?shop=4&pid=… — un produit sur les 28 derniers jours clos (4 de chaque jour de la semaine) :
 * les pièces vendues chaque jour, et les pièces jetées chaque jour (au jour de leur saisie en caisse ; null pour un jour
 * sans aucune saisie d'invendus au magasin, qui n'est pas un jour sans perte).
 */
function ep_dashboard_analyse_jours(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $pid = (int) ($_GET['pid'] ?? 0);
    if ($sid <= 0 || $pid <= 0) { http_response_code(400); return ['error' => 'shop et pid attendus']; }
    if (!function_exists('apTranches2') || !function_exists('invLignes') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'les ventes du panel ne se lisent pas']; }
    @set_time_limit(120);
    // Les 28 jours qui finissent à la fin de la période choisie (au), au plus tard hier : 4 de chaque jour de la semaine.
    $hier = date('Y-m-d', strtotime('-1 day'));
    $fin = (string) ($_GET['au'] ?? ''); $fin = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) ? min($fin, $hier) : $hier;
    $dates = [];
    for ($i = 27; $i >= 0; $i--) { $dates[] = date('Y-m-d', strtotime($fin . " 12:00:00 -$i day")); }
    $lu = apTranches2(array_map(static fn ($d) => [$sid, $d, $d], $dates));
    $jetes = daInvendusJours($sid, $dates);
    $ventes = []; $jet = [];
    foreach ($dates as $d) {
        $p = $lu[$sid . ':' . $d] ?? null;
        $ventes[] = is_array($p) ? round((float) ($p[$pid][2] ?? 0), 1) : null;
        $l = $jetes[$d] ?? null;
        if ($l === null || $l === []) { $jet[] = null; continue; }
        $q = 0.0; foreach ($l as $x) { if ((int) $x['pid'] === $pid) { $q += (float) $x['pieces']; } }
        $jet[] = round($q, 1);
    }
    return ['shop' => $sid, 'pid' => $pid, 'dates' => $dates, 'ventes' => $ventes, 'jetes' => $jet,
        'source' => 'ventes : product-category-groups du panel, jour par jour · jetés : /shops/{id}/products/waste, jour par jour (au jour de la saisie)'];
}

/** Les invendus d'un magasin jour par jour : le cache de invLignes, les jours manquants lus en parallèle puis gravés. */
function daInvendusJours(int $sid, array $dates): array
{
    $out = []; $paths = [];
    foreach ($dates as $d) {
        $c = setting(invCle($sid, $d, $d));
        $ok = is_array($c) && isset($c['l']) && is_array($c['l']);
        if ($ok && (int) ($c['ts'] ?? 0) > time() - invTtl($d)) { $out[$d] = $c['l']; continue; }
        $out[$d] = $ok ? $c['l'] : null;
        $paths[$d] = invChemin($sid, $d, $d);
    }
    if ($paths) {
        foreach (PanelApi::getParallele($paths, 4, 25) as $d => $r) {
            $l = invLignesDe($r);
            if ($l === null) { continue; }
            svGrave(invCle($sid, (string) $d, (string) $d), ['ts' => time(), 'l' => $l]);
            $out[(string) $d] = $l;
        }
    }
    return $out;
}

/** Le routeur du module : null si la route n'est pas la sienne. */
function daRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/analyse' && !str_starts_with($path, '/dashboard/analyse/')) { return null; }
    if ($method === 'GET' && $path === '/dashboard/analyse/periode') { return ep_dashboard_analyse_periode(); }
    if ($method === 'GET' && $path === '/dashboard/analyse/reseau') { return ep_dashboard_analyse_reseau(); }
    if ($method === 'GET' && $path === '/dashboard/analyse/mois') { return ep_dashboard_analyse_mois(); }
    if ($method === 'GET' && $path === '/dashboard/analyse/jours') { return ep_dashboard_analyse_jours(); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
