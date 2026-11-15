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
 * Ce module ajoute les trois lectures qui leur manquaient :
 *   GET /dashboard/analyse/reseau?shop=4&pid=1610006 | &groupe=Viennoiserie
 *   GET /dashboard/analyse/mois?shop=4&pids=1610006,1610004
 *   GET /dashboard/analyse/jours?shop=4&pid=1610006
 *
 * Les autres magasins n'y ont ni nom, ni identifiant, ni volume ni chiffre : seulement des pièces rapportées à leur
 * chiffre (« pour 10 k€ ») et des évolutions. De quoi se comparer à taille égale, pas de quoi lire le chiffre d'un voisin.
 * Lecture seule ; les tranches closes viennent du cache des ventes (apTranches2), les invendus de celui de invLignes.
 */

/** Les semaines comparées pour une évolution : celles de l'onglet Produits (AD_AVANT, AD_APRES), sans la semaine en cours. */
function daEvo(array $q): ?float
{
    $avant = array_sum(array_slice($q, AD_AVANT[0], AD_AVANT[1] - AD_AVANT[0] + 1));
    $apres = array_sum(array_slice($q, AD_APRES[0], AD_APRES[1] - AD_APRES[0] + 1));
    return $avant > 0 ? round(100 * ($apres / $avant - 1), 1) : null;
}

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

/**
 * GET /dashboard/analyse/reseau?shop=4&pid=… | &groupe=… — le magasin face aux autres magasins du réseau, à taille égale,
 * sur les 13 semaines de l'onglet Produits (12 closes et l'en cours).
 *   taille        le chiffre TTC du magasin sur les 13 semaines (le sien)
 *   produits      pour le produit, ou chaque produit de la famille vendu quelque part : ses pièces semaine par semaine chez
 *                 le magasin, ses pièces pour 10 k€ de chiffre, son évolution, et pour chaque autre magasin qui le vend
 *                 ses pièces pour 10 k€ et son évolution (sans nom, du plus vendeur au moins vendeur)
 *   reseau        la sélection (le produit ou la famille) chez les autres magasins, ramenée à la taille du magasin, semaine
 *                 par semaine : (Σ pièces des autres) ÷ (Σ chiffre des autres) × chiffre du magasin — idem en chiffre TTC
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
    @set_time_limit(120);
    $tranches = array_slice(apTranches(3), -13);
    $n = count($tranches);
    $shops = array_map('intval', array_column(Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id'), 'id'));
    if (!in_array($sid, $shops, true)) { $shops[] = $sid; }
    $couples = [];
    foreach ($shops as $s) { foreach ($tranches as $t) { $couples[] = [$s, $t[0], $t[1]]; } }
    $lu = apTranches2($couples);
    $catDe = function_exists('svCategories') ? svCategories() : [];
    $grpDe = function_exists('svGroupes') ? svGroupes() : [];

    // Par magasin : le chiffre des 13 semaines (sa taille), et les pièces / le chiffre de chaque produit retenu.
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
            $ailleurs[] = ['p10k' => $p10k(array_sum($q), $taille[$s]), 'evo' => daEvo($q)];
        }
        usort($ailleurs, static fn ($a, $b) => $b['p10k'] <=> $a['p10k']);
        if (array_sum($qMoi) <= 0) {
            if ($ailleurs !== [] && $pid <= 0) {
                $moy = array_sum(array_column($ailleurs, 'p10k')) / count($ailleurs);
                $absents[] = ['pid' => $id, 'nom' => $nm['nom'], 'cat' => $nm['cat'], 'magasins' => count($ailleurs),
                    'aVotreTaille' => round($moy / 10000 * $moi / $n, 1)];
            }
            continue;
        }
        $produits[] = ['pid' => $id, 'nom' => $nm['nom'], 'cat' => $nm['cat'], 'q' => array_map(static fn ($v) => round($v, 1), $qMoi),
            'p10k' => $p10k(array_sum($qMoi), $moi), 'evo' => daEvo($qMoi), 'autres' => $ailleurs];
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
    return ['shop' => $sid, 'pid' => $pid ?: null, 'groupe' => $pid > 0 ? null : $groupe,
        'semaines' => ['tranches' => array_map(static fn ($t) => (string) $t[2], $tranches), 'bornes' => array_map(static fn ($t) => [$t[0], $t[1]], $tranches)],
        'taille' => round($moi, 2), 'autresMagasins' => count($autres), 'produits' => $produits, 'absents' => $absents,
        'reseau' => $k === null ? null : ['q' => array_map(static fn ($v) => round($v * $k, 1), $rq), 'v' => array_map(static fn ($v) => round($v * $k, 2), $rv)],
        'regle' => ['evolution' => 'semaines ' . (AD_APRES[0] + 1) . ' à ' . (AD_APRES[1] + 1) . ' face aux semaines ' . (AD_AVANT[0] + 1) . ' à ' . (AD_AVANT[1] + 1) . ', sans la semaine en cours',
            'p10k' => 'pièces vendues pour 10 000 € de chiffre TTC du magasin, sur les 13 semaines'],
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
    $dates = [];
    for ($i = 28; $i >= 1; $i--) { $dates[] = date('Y-m-d', strtotime("-$i day")); }
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
    if ($method === 'GET' && $path === '/dashboard/analyse/reseau') { return ep_dashboard_analyse_reseau(); }
    if ($method === 'GET' && $path === '/dashboard/analyse/mois') { return ep_dashboard_analyse_mois(); }
    if ($method === 'GET' && $path === '/dashboard/analyse/jours') { return ep_dashboard_analyse_jours(); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
