<?php
declare(strict_types=1);

/**
 * Le Coach Vente et ses outils (17/11/2026, Sam : « le Coach Vente est ciblé sur les ventes, l'adaptation des prix par
 * rapport à la concurrence, la création de bundles par rapport à vos points forts et points faibles »). Chargé par
 * src/franchise/assistant_ia.php. Trois outils en lecture seule, ouverts à tous les agents de l'assistant :
 *
 *   prix_concurrence     les prix relevés chez les concurrents du magasin (module Benchmark de TF Buddy, comme l'onglet
 *                        Concurrence), produit par produit, face au prix TTC et à la marge du magasin
 *   forces_faiblesses    les points forts et faibles du magasin face au reste du réseau : les catégories et les produits
 *                        dont la part des ventes est au-dessus ou en dessous, avec leur marge ; les produits qui rapportent
 *                        le plus par euro vendu
 *   promotions_en_cours  les promotions et bundles du magasin au panel : la règle en mots, le prix, le contenu, la période
 *
 * et deux visuels : `prix_concurrence` (barres : le prix du magasin, puis chaque concurrent relevé pour ce produit) et
 * `marges_categories` (radar, 17/11/2026, Sam : « un spider sur les marges et les catégories ») : la marge brute de chaque
 * catégorie du magasin face à celle du reste du réseau, chaque magasin compté avec SES prix et SES coûts de recette.
 *
 * La marge d'un produit : prix hors TVA − coût de recette (du magasin, sinon du réseau), comme Vendre › Offres
 * (pmProduits). Les concurrents sont des commerces hors réseau, relevés par le réseau : ils sont nommés. Les autres
 * magasins du réseau, eux, restent anonymes : forces_faiblesses ne rend que « le réseau ».
 */

require_once __DIR__ . '/bundles.php';   // les bundles du Coach Vente (17/11/2026) : onglet Promotions

const AIV_PERIODE_JOURS = 56;          // forces_faiblesses : huit semaines par défaut
const AIV_PART_MIN = 1.0;              // un produit compte à partir de 1 % des ventes (du magasin ou du réseau)
const AIV_BENCH_JOURS = 7;             // un relevé de la concurrence gardé par le dashboard sert une semaine
/** Les questions toutes prêtes du Coach Vente : les prix, les bundles, les ventes. */
const AIV_QUESTIONS = [
    'tuiles' => [
        ['Mes prix sont-ils bien placés face à la concurrence ?', 'prix relevés, écarts, marge'],
        ['Quel bundle créer cette semaine ?', 'sur vos points forts et faibles'],
        ['Quels sont mes points forts et mes points faibles ?', 'face au réseau, avec la marge'],
        ['Comment faire monter mon panier ?', 'ventes additionnelles, heures, produits'],
    ],
    'groupes' => [
        ['Les prix', ['Où suis-je plus cher que mes concurrents ?', 'Quel prix adapter sur la viennoiserie ?', 'Mon croissant face aux boulangeries proches ?']],
        ['Les bundles', ['Quel bundle pour le petit-déjeuner ?', 'Quel bundle pour faire découvrir mes points faibles ?', 'Mes bundles en cours marchent-ils ?']],
        ['Les ventes', ['Qu’est-ce que je vends le plus le matin ?', 'Quels produits portent mon panier ?', 'Comment ont évolué mes samedis sur un an ?']],
    ],
];

/** Les produits du magasin : prix, coût de recette, marge — gardés une heure (aia_cache). [id => fiche]. */
function aivProduits(int $sid): array
{
    $L = aiaCache('produits:' . $sid, 3600, static function () use ($sid) { return function_exists('pmProduits') ? pmProduits($sid) : null; });
    $out = [];
    foreach (is_array($L) ? $L : [] as $p) {
        $ht = is_numeric($p['prixHt'] ?? null) ? (float) $p['prixHt'] : null;
        $c = is_numeric($p['cout'] ?? null) ? (float) $p['cout'] : null;
        $out[(int) $p['id']] = ['nom' => (string) $p['nom'], 'categorie' => (string) ($p['cat'] ?? ''), 'prixTtc' => is_numeric($p['prixTtc'] ?? null) ? round((float) $p['prixTtc'], 2) : null,
            'prixHt' => $ht !== null ? round($ht, 2) : null, 'cout' => $c !== null ? round($c, 2) : null,
            'margePct' => $ht && $c !== null ? round(100 * ($ht - $c) / $ht, 1) : null, 'coutDe' => $p['source'] ?? null];
    }
    return $out;
}

/** Les relevés de la concurrence du magasin : la lecture du dashboard (onglet Concurrence) si elle a moins d'une semaine,
 *  sinon une lecture gardée un jour (elle est lente : un appel par observation). Null si rien ne se lit. */
function aivBenchmark(int $sid): ?array
{
    if (function_exists('setting')) {
        $c = setting('rcG:' . md5('/benchmarks/magasin?shop=' . $sid));
        if (is_array($c) && is_array($c['r'] ?? null) && time() - (int) ($c['le'] ?? 0) < AIV_BENCH_JOURS * 86400) { return $c['r']; }
    }
    $r = aiaCache('bench:' . $sid, 86400, static function () use ($sid) {
        if (!function_exists('ep_benchmark_magasin')) { return null; }
        $g = $_GET; $code = http_response_code();
        $_GET = ['shop' => (string) $sid, 'web' => '0'];
        try { $x = ep_benchmark_magasin(); } catch (Throwable $e) { $x = null; }
        finally { $_GET = $g; if (is_int($code)) { http_response_code($code); } }
        return is_array($x) && !isset($x['error']) ? $x : null;
    });
    return is_array($r) ? $r : null;
}

/** Le prix au kilo d'un relevé, quand sa taille est un poids ; null sinon (une pièce, une portion). */
function aivPrixKg(float $prix, string $unite, mixed $taille): ?float
{
    $t = is_numeric($taille) ? (float) $taille : 0.0;
    if ($prix <= 0 || $t <= 0) { return null; }
    return match (strtolower($unite)) { 'kg' => round($prix / $t, 2), 'g' => round($prix / ($t / 1000), 2), default => null };
}

/** La taille d'un relevé, dite : « 280 g », « 500 g », « la pièce ». */
function aivTaille(string $unite, mixed $taille): string
{
    $t = is_numeric($taille) ? (float) $taille : 0.0;
    return match (strtolower($unite)) {
        'kg' => $t > 0 ? ($t < 1 ? round($t * 1000) . ' g' : str_replace('.', ',', (string) round($t, 2)) . ' kg') : 'au kg',
        'g' => $t > 0 ? round($t) . ' g' : 'au poids',
        'cm' => $t > 0 ? 'Ø ' . round($t) . ' cm' : '',
        'portion' => 'la portion',
        default => 'la pièce',
    };
}

/**
 * Les relevés rangés par produit du magasin (fonction pure, testée) : pour chaque produit, son prix et sa marge, puis les
 * concurrents du plus proche au plus loin. $B : la lecture du benchmark (releves, referentiel, kpi) ; $prod : aivProduits.
 */
function aivGrouperReleves(array $B, array $prod, string $filtre = '', int $limite = 12): array
{
    $f = aiaNorm($filtre);
    $G = [];
    foreach (array_merge((array) ($B['releves'] ?? []), (array) ($B['referentiel'] ?? [])) as $r) {
        if (!is_array($r) || (float) ($r['prix'] ?? 0) <= 0) { continue; }
        $pid = isset($r['monProduit']) && (int) $r['monProduit'] > 0 ? (int) $r['monProduit'] : null;
        $mon = $pid !== null ? ($prod[$pid]['nom'] ?? (string) ($r['monProduitNom'] ?? '')) : '';
        $cle = $pid !== null ? 'p' . $pid : 'n' . aiaNorm((string) $r['produit']);
        if ($f !== '' && !str_contains(aiaNorm($mon . ' ' . $r['produit']), $f)) { continue; }
        if (!isset($G[$cle])) {
            $p = $pid !== null ? ($prod[$pid] ?? null) : null;
            $G[$cle] = ['monProduit' => $mon !== '' ? trim($mon) : null, 'monPrixTtc' => $p['prixTtc'] ?? null, 'maMargePct' => $p['margePct'] ?? null, 'monCoutRecette' => $p['cout'] ?? null, 'concurrents' => []];
        }
        $prix = round((float) $r['prix'], 2);
        $G[$cle]['concurrents'][] = array_filter([
            'commerce' => (string) $r['concurrent'], 'type' => (string) ($r['type'] ?? ''), 'distanceKm' => isset($r['distance']) && $r['distance'] !== null ? (float) $r['distance'] : null,
            'produitVu' => (string) $r['produit'], 'prix' => $prix, 'promo' => isset($r['promo']) && $r['promo'] ? round((float) $r['promo'], 2) : null,
            'taille' => aivTaille((string) ($r['unite'] ?? ''), $r['taille'] ?? null), 'prixAuKg' => aivPrixKg($prix, (string) ($r['unite'] ?? ''), $r['taille'] ?? null),
            'vuLe' => (string) ($r['vuLe'] ?? ''), 'qualiteNotee' => trim(mb_substr((string) preg_replace('/\s+/u', ' ', (string) ($r['notes'] ?? '')), 0, 180)) ?: null,
            'source' => ($r['source'] ?? '') === 'referentiel' ? 'relevé web' : 'relevé en magasin',
        ], static fn ($v) => $v !== null && $v !== '');
    }
    foreach ($G as &$g) {
        usort($g['concurrents'], static fn ($a, $b) => ($a['distanceKm'] ?? 999) <=> ($b['distanceKm'] ?? 999));
        $g['concurrents'] = array_slice($g['concurrents'], 0, 8);
        $pr = array_column($g['concurrents'], 'prix');
        $g['prixConcurrents'] = ['min' => min($pr), 'max' => max($pr), 'releves' => count($pr)];
    }
    unset($g);
    // Les produits du magasin d'abord, les plus relevés en tête.
    uasort($G, static fn ($a, $b) => [$b['monProduit'] !== null, $b['prixConcurrents']['releves']] <=> [$a['monProduit'] !== null, $a['prixConcurrents']['releves']]);
    return array_values(array_slice($G, 0, max(1, min(30, $limite))));
}

/** L'outil prix_concurrence. */
function aivOutilPrix(int $sid, array $in): array
{
    $B = aivBenchmark($sid);
    if ($B === null) { return ['erreur' => 'les relevés de la concurrence ne se lisent pas pour le moment']; }
    $G = aivGrouperReleves($B, aivProduits($sid), (string) ($in['produit'] ?? ''), (int) ($in['limite'] ?? 12) ?: 12);
    $k = is_array($B['kpi'] ?? null) ? $B['kpi'] : [];
    return ['produits' => $G, 'releves' => [
            'concurrentsSuivis' => $k['covered_competitors'] ?? null, 'dernierReleve' => $k['last_observation_date'] ?? null, 'fraicheurJours' => $k['freshness_days'] ?? null],
        'lecture' => 'prix = prix payé pour l’article vu, TVA comprise ; comparer à taille égale (prixAuKg quand il est donné) ; la qualité notée au relevé compte autant que le prix ; '
            . 'maMargePct = (prix hors TVA − coût de recette) ÷ prix hors TVA. Les concurrents sont des commerces hors réseau : on peut les nommer.']
        + ($G === [] ? ['note' => 'aucun relevé pour ce produit : proposer d’en faire un (onglet Concurrence, « Relever un prix »)'] : []);
}

/**
 * Les points forts et faibles (fonction pure, testée). $moi, $res : hvProduits()['produits'] du magasin et du reste du
 * réseau ; $cat : svCatalogue ; $prod : aivProduits. Une part = ventes du produit ÷ ventes totales, en %.
 */
function aivForces(array $moi, array $res, array $cat, array $prod): array
{
    $agreger = static function (array $L) use ($cat): array {
        $P = []; $C = []; $tot = 0.0;
        foreach ($L as $x) {
            [$pid, , $nom, $q, $v] = $x;
            $P[$pid]['nom'] ??= (string) $nom;
            $P[$pid]['v'] = ($P[$pid]['v'] ?? 0.0) + (float) $v;
            $P[$pid]['q'] = ($P[$pid]['q'] ?? 0.0) + (float) $q;
            $c = trim((string) ($cat[$pid]['cat'] ?? '')) ?: 'sans catégorie';
            $C[$c] = ($C[$c] ?? 0.0) + (float) $v;
            $tot += (float) $v;
        }
        return [$P, $C, $tot];
    };
    [$Pm, $Cm, $Tm] = $agreger($moi);
    [$Pr, $Cr, $Tr] = $agreger($res);
    $part = static fn (float $v, float $t) => $t > 0 ? round(100 * $v / $t, 2) : 0.0;
    $cats = [];
    foreach (array_keys($Cm + $Cr) as $c) {
        $a = $part($Cm[$c] ?? 0.0, $Tm); $b = $part($Cr[$c] ?? 0.0, $Tr);
        if ($a < 0.5 && $b < 0.5) { continue; }
        $cats[] = ['categorie' => $c, 'partMagasin' => round($a, 1), 'partReseau' => round($b, 1), 'ecartPts' => round($a - $b, 1), 'ventesMagasin' => round($Cm[$c] ?? 0.0, 2)];
    }
    usort($cats, static fn ($x, $y) => $y['ecartPts'] <=> $x['ecartPts']);
    $prods = [];
    foreach (array_keys($Pm + $Pr) as $pid) {
        $a = $part($Pm[$pid]['v'] ?? 0.0, $Tm); $b = $part($Pr[$pid]['v'] ?? 0.0, $Tr);
        $f = $prod[$pid] ?? null;
        $prods[] = ['produit' => $Pm[$pid]['nom'] ?? $Pr[$pid]['nom'] ?? ('Produit ' . $pid), 'categorie' => trim((string) ($cat[$pid]['cat'] ?? '')) ?: null,
            'partMagasin' => round($a, 2), 'partReseau' => round($b, 2), 'ecartPts' => round($a - $b, 2), 'ventesMagasin' => round($Pm[$pid]['v'] ?? 0.0, 2),
            'quantiteMagasin' => round($Pm[$pid]['q'] ?? 0.0, 1), 'prixTtc' => $f['prixTtc'] ?? null, 'margePct' => $f['margePct'] ?? null];
    }
    $forts = array_values(array_filter($prods, static fn ($p) => $p['partMagasin'] >= AIV_PART_MIN && $p['ecartPts'] > 0));
    usort($forts, static fn ($x, $y) => $y['ecartPts'] <=> $x['ecartPts']);
    $faibles = array_values(array_filter($prods, static fn ($p) => $p['partReseau'] >= AIV_PART_MIN && $p['ecartPts'] < 0));
    usort($faibles, static fn ($x, $y) => $x['ecartPts'] <=> $y['ecartPts']);
    $marges = array_values(array_filter($prods, static fn ($p) => $p['partMagasin'] >= 0.5 && $p['margePct'] !== null));
    usort($marges, static fn ($x, $y) => $y['margePct'] <=> $x['margePct']);
    return ['ventesMagasin' => round($Tm, 2), 'categories' => $cats, 'pointsForts' => array_slice($forts, 0, 8), 'pointsFaibles' => array_slice($faibles, 0, 8),
        'meilleuresMarges' => array_slice($marges, 0, 6)];
}

/** L'outil forces_faiblesses. */
function aivOutilForces(int $sid, array $in): array
{
    $hier = date('Y-m-d', strtotime('-1 day'));
    $P = amPeriode(['du' => (string) ($in['du'] ?? '') ?: date('Y-m-d', strtotime('-' . AIV_PERIODE_JOURS . ' days')), 'au' => (string) ($in['au'] ?? '') ?: $hier]);
    if (is_string($P)) { return ['erreur' => $P]; }
    $autres = array_values(array_filter(amMagasinsActifs(), static fn ($x) => $x !== $sid));
    if ($autres === []) { return ['erreur' => 'aucun autre magasin actif : pas de réseau à comparer']; }
    $M = hvProduits([$sid], $P['du'], $P['au']);
    $R = hvProduits($autres, $P['du'], $P['au']);
    $out = ['periode' => amPeriodeTexte($P), 'reseau' => 'les ' . count($autres) . ' autres magasins actifs, ensemble (sans nom)']
        + aivForces($M['produits'], $R['produits'], function_exists('svCatalogue') ? svCatalogue() : [], aivProduits($sid));
    // La marge brute de chaque catégorie, du magasin et du réseau (17/11/2026) : ce que le radar des marges montre.
    try {
        $Mg = aivMarges($sid, $P['du'], $P['au']);
        $pm = aivMargesPct($Mg['moi']); $pr = aivMargesPct($Mg['reseau']);
        foreach ($out['categories'] as &$c) { $c['margeMagasinPct'] = $pm[$c['categorie']] ?? null; $c['margeReseauPct'] = $pr[$c['categorie']] ?? null; }
        unset($c);
    } catch (Throwable $e) { error_log('aiv marges : ' . $e->getMessage()); }
    $out = $out
        + ['lecture' => 'ecartPts = part du magasin − part du réseau, en points ; un point fort attire (on le met en tête d’un bundle), un point faible se fait découvrir '
            . '(on l’ajoute à prix doux, ou on le retire) ; la marge d’un bundle = somme des prix hors TVA de son prix − somme des coûts de recette ; '
            . 'margeMagasinPct et margeReseauPct : la marge brute de la catégorie (prix hors TVA − coût de recette, pondérée par les ventes), du magasin et du réseau.'];
    if (($c = amCouverture($M['couverture'], $M['aujourdhui'])) !== null) { $out['couverture'] = $c; }
    return $out;
}

/**
 * La marge brute par catégorie (fonction pure, testée) : ajoute à $acc les ventes d'UN magasin, avec SES prix et SES coûts.
 * Pour chaque produit vendu : ventes hors TVA = ventes × prix HT ÷ prix TTC ; marge = ventes hors TVA × (prix HT − coût)
 * ÷ prix HT. $ventes : hvProduits()['produits'] ; $prod : aivProduits ; $cat : svCatalogue. Un produit sans prix ou sans
 * coût de recette n'est pas compté. $acc : [catégorie => ['marge' => €, 'ht' => €, 'n' => produits comptés]].
 */
function aivMargesAjouter(array &$acc, array $ventes, array $prod, array $cat): void
{
    foreach ($ventes as $x) {
        $pid = (int) $x[0]; $v = (float) $x[4];
        $f = $prod[$pid] ?? null;
        if ($f === null || $v <= 0 || !is_numeric($f['prixHt'] ?? null) || !is_numeric($f['cout'] ?? null) || (float) $f['prixHt'] <= 0) { continue; }
        $ht = (float) $f['prixHt'];
        $vht = is_numeric($f['prixTtc'] ?? null) && (float) $f['prixTtc'] > 0 ? $v * $ht / (float) $f['prixTtc'] : $v;
        $c = trim((string) ($cat[$pid]['cat'] ?? '')) ?: (trim((string) ($f['categorie'] ?? '')) ?: 'sans catégorie');
        $acc[$c]['marge'] = ($acc[$c]['marge'] ?? 0.0) + $vht * ($ht - (float) $f['cout']) / $ht;
        $acc[$c]['ht'] = ($acc[$c]['ht'] ?? 0.0) + $vht;
        $acc[$c]['n'] = ($acc[$c]['n'] ?? 0) + 1;
    }
}

/** La marge brute en % de chaque catégorie ([catégorie => %]), dans l'ordre des ventes hors TVA, décroissantes. */
function aivMargesPct(array $acc): array
{
    uasort($acc, static fn ($a, $b) => ($b['ht'] ?? 0) <=> ($a['ht'] ?? 0));
    $out = [];
    foreach ($acc as $c => $x) { if (($x['ht'] ?? 0) > 0) { $out[(string) $c] = round(100 * $x['marge'] / $x['ht'], 1); } }
    return $out;
}

/**
 * Les marges par catégorie du magasin et du reste du réseau (les autres magasins actifs, chacun avec ses prix et ses
 * coûts), sur une période ; gardées une heure (aia_cache). ['moi' => acc, 'reseau' => acc, 'magasins' => n comptés].
 */
function aivMarges(int $sid, string $du, string $au, array $jours = [], ?int $hd = null, ?int $hf = null): array
{
    $cle = 'marges:' . $sid . ':' . md5(json_encode([$du, $au, $jours, $hd, $hf]));
    $r = aiaCache($cle, 3600, static function () use ($sid, $du, $au, $jours, $hd, $hf) {
        $cat = function_exists('svCatalogue') ? svCatalogue() : [];
        $moi = []; $res = []; $n = 0;
        aivMargesAjouter($moi, hvProduits([$sid], $du, $au, $jours, $hd, $hf)['produits'], aivProduits($sid), $cat);
        foreach (amMagasinsActifs() as $s) {
            if ($s === $sid) { continue; }
            $P = aivProduits($s);
            if ($P === []) { continue; }
            aivMargesAjouter($res, hvProduits([$s], $du, $au, $jours, $hd, $hf)['produits'], $P, $cat);
            $n++;
        }
        return ['moi' => $moi, 'reseau' => $res, 'magasins' => $n];
    });
    return is_array($r) ? $r + ['moi' => [], 'reseau' => [], 'magasins' => 0] : ['moi' => [], 'reseau' => [], 'magasins' => 0];
}

/** Le visuel `marges_categories` : la marge brute des catégories du magasin face au réseau (radar, barres ou tableau). */
function aivVisuelMarges(array $base, array $D, array $P, int $sid): array|string
{
    $Mg = aivMarges($sid, $P['du'], $P['au'], $P['jours'], $P['hd'], $P['hf']);
    $pm = aivMargesPct($Mg['moi']);
    if ($pm === []) { return 'aucune marge lisible : les produits vendus n’ont pas encore de coût de recette'; }
    $pr = aivMargesPct($Mg['reseau']);
    $filtre = aiaNorm($D['categorie']);
    $cats = array_values(array_filter(array_map('strval', array_keys($pm)), static fn ($c) => $c !== 'sans catégorie' && ($filtre === '' || str_contains(aiaNorm($c), $filtre))));
    $cats = array_slice($cats, 0, $D['limite'] ?: 8);
    if ($cats === []) { return 'aucune catégorie de ce nom n’a de marge lisible'; }
    $series = [['nom' => 'Mon magasin', 'role' => 'moi', 'valeurs' => array_map(static fn ($c) => $pm[$c], $cats)]];
    if ($D['comparer'] === 'reseau' && $Mg['magasins'] > 0) {
        $series[] = ['nom' => 'Le réseau', 'role' => 'ref', 'valeurs' => array_map(static fn ($c) => $pr[$c] ?? null, $cats)];
    }
    $lignes = [];
    foreach ($cats as $i => $c) {
        $lignes[] = array_merge([$c], array_map(static fn ($s) => $s['valeurs'][$i], $series), [round($Mg['moi'][$c]['ht'] ?? 0, 2)]);
    }
    $per = amPeriodeTexte($P);
    return $base + ['periode' => 'du ' . date('d/m/Y', strtotime($P['du'])) . ' au ' . date('d/m/Y', strtotime($P['au'])) . (isset($per['jours']) ? ' · ' . $per['jours'] : ''),
        'libelles' => $cats, 'series' => $series, 'surligne' => null,
        'tableau' => ['entetes' => array_merge(['Catégorie'], array_map(static fn ($s) => $s['nom'], $series), ['Ventes HT du magasin']), 'lignes' => $lignes, 'mesures' => array_merge(['', ], array_fill(0, count($series), 'marge'), ['ca'])],
        'source' => 'les tickets du panel ; pour chaque magasin, ses prix et ses coûts de recette (Recette & marge)',
        'note' => trim('Marge brute : prix hors TVA − coût de recette, pondérée par les ventes ; remises non déduites. Les produits sans coût de recette ne comptent pas.'
            . ($D['comparer'] === 'reseau' && $Mg['magasins'] > 0 ? ' Le réseau : les ' . $Mg['magasins'] . ' autres magasins actifs, ensemble (sans nom).' : ''))];
}

/** L'outil promotions_en_cours :  les promotions et bundles du panel qui touchent ce magasin, en cours ou à venir. */
function aivOutilPromos(int $sid): array
{
    if (!function_exists('pvRegles')) { return ['erreur' => 'les promotions ne se lisent pas ici']; }
    $R = pvRegles();
    $auj = date('Y-m-d');
    $L = [];
    foreach ((array) $R['regles'] as $r) {
        if (is_array($r['shops'] ?? null) && !in_array($sid, $r['shops'], true)) { continue; }
        if (($r['au'] ?? null) !== null && $r['au'] < $auj) { continue; }
        $L[] = array_filter(['nom' => $r['nom'], 'type' => $r['type'], 'regle' => function_exists('pvTexte') ? pvTexte($r) : null, 'statut' => $r['statut'] ?: null,
            'du' => $r['du'], 'au' => $r['au'], 'prixBundle' => $r['bundle']['prix'] ?? null,
            'contenu' => isset($r['bundle']['items']) ? array_map(static fn ($i) => ['produit' => $i['nom'], 'quantite' => $i['q']], (array) $r['bundle']['items']) : null,
            'pourTousLesMagasins' => ($r['shops'] ?? null) === null, 'activation' => $r['activation'] ?? null], static fn ($v) => $v !== null && $v !== []);
        if (count($L) >= 25) { break; }
    }
    return ['promotions' => $L, 'nombre' => count($L), 'lecture' => 'les ventes des bundles se lisent avec produits_vendus (catégorie « bundle ») ; les promotions se créent dans Vendre › Promotions.']
        + ($R['erreur'] ? ['note' => 'lecture partielle : ' . $R['erreur']] : []);
}

/** Les trois outils, tels que le modèle les voit (texte fixe : il fait partie du préfixe mis en cache). */
function aivOutils(): array
{
    return [
        ['name' => 'prix_concurrence',
         'description' => 'Les prix relevés chez les concurrents du magasin (boulangeries, chaînes, grandes surfaces ; relevés en magasin et sur le web), produit par produit : '
            . 'chaque concurrent avec sa distance, le produit vu, son prix, sa taille, le prix au kilo, la date et la qualité notée — face au prix TTC du magasin, à son coût de recette et à sa marge. '
            . 'À appeler pour toute question de prix, de positionnement face à la concurrence ou d’adaptation d’un prix.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'produit' => ['type' => 'string', 'description' => 'Un mot du nom du produit (« croissant », « baguette », « cougnou »). Absent : tous les produits relevés.'],
             'limite' => ['type' => 'integer', 'description' => 'Combien de produits rendre : de 1 à 30, 12 par défaut.'],
         ]]],
        ['name' => 'forces_faiblesses',
         'description' => 'Les points forts et les points faibles du magasin face au reste du réseau, sur une période (huit semaines par défaut) : les catégories et les produits '
            . 'dont la part des ventes est au-dessus ou en dessous de celle du réseau, avec leur prix et leur marge, et les produits qui rapportent le plus par euro vendu. '
            . 'À appeler pour composer un bundle, choisir quoi mettre en avant, ou dire ce que le magasin vend mieux ou moins bien que les autres.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'du' => ['type' => 'string', 'description' => 'Premier jour (AAAA-MM-JJ). Absent : il y a huit semaines.'],
             'au' => ['type' => 'string', 'description' => 'Dernier jour, compris (AAAA-MM-JJ). Absent : hier.'],
         ]]],
        ['name' => 'promotions_en_cours',
         'description' => 'Les promotions et bundles du panel qui touchent le magasin, en cours ou à venir : la règle en mots, le prix du bundle, son contenu, la période. '
            . 'À appeler avant de proposer un bundle (pour ne pas refaire l’existant) ou pour juger les bundles en place.',
         'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
    ];
}

/** Exécute un outil du Coach Vente ; null si ce n'en est pas un. */
function aivOutil(string $nom, array $in, int $sid): ?array
{
    return match ($nom) {
        'prix_concurrence' => aivOutilPrix($sid, $in),
        'forces_faiblesses' => aivOutilForces($sid, $in),
        'promotions_en_cours' => aivOutilPromos($sid),
        default => null,
    };
}

/** Le visuel `prix_concurrence` : le prix du magasin, puis chaque concurrent relevé pour ce produit (barres, tableau). */
function aivVisuelPrix(array $base, string $produit, int $sid): array|string
{
    if (trim($produit) === '') { return 'un produit est attendu (catégorie = un mot du nom du produit)'; }
    $B = aivBenchmark($sid);
    if ($B === null) { return 'les relevés de la concurrence ne se lisent pas pour le moment'; }
    $G = aivGrouperReleves($B, aivProduits($sid), $produit, 1)[0] ?? null;
    if ($G === null) { return 'aucun relevé pour ce produit'; }
    $libs = []; $vals = []; $lignes = [];
    if ($G['monPrixTtc'] !== null) {
        $libs[] = 'Mon magasin'; $vals[] = $G['monPrixTtc'];
        $lignes[] = ['Mon magasin', (string) $G['monProduit'], '', $G['monPrixTtc'], '', $G['maMargePct'] !== null ? 'marge ' . str_replace('.', ',', (string) $G['maMargePct']) . ' %' : ''];
    }
    foreach ($G['concurrents'] as $c) {
        $libs[] = $c['commerce'] . (isset($c['distanceKm']) ? ' (' . str_replace('.', ',', (string) $c['distanceKm']) . ' km)' : '');
        $vals[] = $c['prix'];
        $lignes[] = [$c['commerce'], $c['produitVu'], $c['taille'] ?? '', $c['prix'], isset($c['vuLe']) ? aivDate($c['vuLe']) : '', $c['qualiteNotee'] ?? ''];
    }
    $dates = array_values(array_filter(array_column($G['concurrents'], 'vuLe')));
    return array_merge($base, ['forme' => $base['forme'] === 'tableau' ? 'tableau' : 'barres', 'mesure' => 'prix', 'mesureNom' => 'Prix TTC', 'unite' => '€',
        'periode' => ($G['monProduit'] ? $G['monProduit'] . ' · ' : '') . ($dates ? 'relevés du ' . aivDate(min($dates)) . ' au ' . aivDate(max($dates)) : 'relevés sans date'),
        'libelles' => $libs, 'series' => [['nom' => 'Prix TTC', 'role' => 'moi', 'valeurs' => $vals]], 'surligne' => $G['monPrixTtc'] !== null ? 0 : null,
        'tableau' => ['entetes' => ['Commerce', 'Produit', 'Taille', 'Prix TTC', 'Vu le', 'Qualité notée'], 'lignes' => $lignes],
        'source' => 'les relevés de la concurrence (onglet Concurrence) et le prix du magasin au panel',
        'note' => 'Prix payé pour l’article vu : les tailles peuvent différer (voir le tableau).']);
}

/** Une date AAAA-MM-JJ dite JJ/MM/AAAA ; telle quelle sinon. */
function aivDate(string $d): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4) : $d;
}
