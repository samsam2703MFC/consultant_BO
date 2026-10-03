<?php
declare(strict_types=1);

/**
 * Le flux de production d'un magasin — l'application /production (demande du 03/10/2026).
 * Quatre pages, dans l'ordre de la journée :
 *
 *   1. Paramètres — par jour de la semaine, le nombre de cuissons et la production minimum de
 *      la 1re cuisson (en % de la journée) ; les produits obligatoires et leurs jours ; les
 *      catégories préparées la veille (la viennoiserie) et celles qui se gardent au lendemain ;
 *      les cuissons, les catégories × cuissons, les plaques et les règles (gpParams, partagés
 *      avec l'écran du cockpit).
 *   2. Plan — catégorie › sous-catégorie › produit : vendu à J−7 (en magasin, webshop,
 *      commandes magasin), à produire pour la 1re période (le matin), à préparer pour la 2e
 *      cuisson (et les suivantes), à préparer pour la 1re cuisson du lendemain.
 *   3. Validation et suivi — ce que le magasin a réellement sorti, cuisson par cuisson ; le
 *      stock de chaque produit heure par heure (vendu d'après les tickets, projeté d'après les
 *      6 derniers mêmes jours) et le manque prévu.
 *   4. Clôture — ce qui reste, ce qui se garde pour demain, ce qui se jette. Le report devient
 *      le stock de départ du plan du lendemain.
 *
 * Rien ne s'écrit au panel : réglages, validations et clôtures vivent dans ceo_app_setting
 * (gpEcrire). La poubelle à déclarer en caisse est listée, pas encodée.
 */

const PF_MIN_OBLIG = 2;           // pièces minimum d'un obligatoire sans réglage
const PF_RISQUE = 0.25;           // stock projeté sous 25 % de la vente de l'heure suivante : risque
const PF_TROP = 0.35;             // fin de journée projetée au-delà de 35 % de la prévision : trop produit
const PF_JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

/** Le jour de la semaine ISO (1 = lundi) d'une date. */
function pfJour(string $date): int { return (int) date('N', strtotime($date . ' 12:00:00')); }

/** Une date décalée de n jours. */
function pfDecale(string $date, int $n): string { return date('Y-m-d', strtotime($date . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' days')); }

/** Les obligatoires du réseau (assortiment obligatoire du cockpit) : [pid => pièces minimum]. */
function pfObligReseau(string $date): array
{
    if (!function_exists('aoObligatoires')) { return []; }
    $out = [];
    try {
        $q = [];
        foreach (Db::rows('SELECT ref, qmin FROM ceo_prod_product WHERE must = 1 AND actif = 1') as $r) { $q[(string) $r['ref']] = (int) $r['qmin']; }
        foreach (aoObligatoires($date)['exigibles'] as $x) {
            if (!preg_match('/^\d{1,9}$/', (string) $x['ref'])) { continue; }
            $q0 = (int) ($q[(string) $x['ref']] ?? 0);
            $out[(int) $x['ref']] = $q0 > 0 ? $q0 : PF_MIN_OBLIG;
        }
    } catch (Throwable $e) { /* pas d'assortiment : aucun obligatoire réseau */ }
    return $out;
}

/**
 * Les réglages du flux d'un magasin, complétés par les défauts :
 *   jours        [1..7 => {cuissons, minPct, auto}] — toutes les cuissons, la part de la 1re ;
 *   obligatoires [pid => {jours: [1..7], min, reseau}] — le réseau tous les jours, sauf réglage ;
 *   veille       [catCle] — les catégories préparées la veille (la viennoiserie par défaut) ;
 *   garde        [catCle] — celles qui se gardent au lendemain (biscuits, cakes, épicerie…).
 */
function pfParams(int $sid, array $gp, ?string $date = null): array
{
    $s = setting('pfParams:' . $sid);
    $s = is_array($s) ? $s : [];
    $nC = count($gp['cuissons']);
    $pct0 = $nC ? (float) $gp['cuissons'][0]['pct'] : 50.0;
    $jours = [];
    for ($j = 1; $j <= 7; $j++) {
        $e = $s['jours'][$j] ?? ($s['jours'][(string) $j] ?? null);
        $jours[$j] = is_array($e)
            ? ['cuissons' => max(1, min($nC ?: 1, (int) ($e['cuissons'] ?? $nC))), 'minPct' => max(0.0, min(100.0, (float) ($e['minPct'] ?? $pct0))), 'auto' => false]
            : ['cuissons' => max(1, $nC), 'minPct' => round($pct0, 1), 'auto' => true];
    }
    $cats = $gp['categories'];
    $grp = static function (string $k) use ($cats): string {
        $id = (int) ($cats[$k]['catId'] ?? 0);
        $g = $id > 0 ? (string) (gpCatalogue()['categories'][$id]['groupe'] ?? '') : '';
        return $g . ' ' . (string) ($cats[$k]['nom'] ?? '');
    };
    $veille = is_array($s['veille'] ?? null) ? array_values(array_map('strval', $s['veille']))
        : array_values(array_filter(array_map('strval', array_keys($cats)), static fn ($k) => (bool) preg_match('/viennoiserie/iu', $grp($k))));
    $garde = is_array($s['garde'] ?? null) ? array_values(array_map('strval', $s['garde']))
        : array_values(array_filter(array_map('strval', array_keys($cats)), static fn ($k) => (bool) preg_match('/biscuit|cookie|cake|épicerie|epicerie|confiserie|boisson/iu', $grp($k))));
    $ob = [];
    $res = pfObligReseau($date ?? date('Y-m-d'));
    foreach ($res as $pid => $min) { $ob[$pid] = ['jours' => [1, 2, 3, 4, 5, 6, 7], 'min' => $min, 'reseau' => true]; }
    foreach ((array) ($s['obligatoires'] ?? []) as $pid => $e) {
        if (!is_array($e) || !preg_match('/^\d{1,9}$/', (string) $pid)) { continue; }
        $j = array_values(array_unique(array_filter(array_map('intval', (array) ($e['jours'] ?? [])), static fn ($x) => $x >= 1 && $x <= 7)));
        sort($j);
        $ob[(int) $pid] = ['jours' => $j, 'min' => max(0, (int) ($e['min'] ?? PF_MIN_OBLIG)), 'reseau' => isset($res[(int) $pid])];
    }
    return ['jours' => $jours, 'obligatoires' => $ob, 'veille' => $veille, 'garde' => $garde,
        'enregistre' => $s !== [], 'maj' => $s['maj'] ?? null, 'par' => $s['par'] ?? null];
}

/** Valide les réglages du flux envoyés par l'écran : [ok, erreur|null, réglages]. */
function pfValiderParams(array $p, int $nCuissons): array
{
    $jours = [];
    for ($j = 1; $j <= 7; $j++) {
        $e = $p['jours'][$j] ?? ($p['jours'][(string) $j] ?? null);
        if (!is_array($e)) { return [false, 'réglage du ' . PF_JOURS[$j] . ' manquant', null]; }
        $n = $e['cuissons'] ?? null; $m = $e['minPct'] ?? null;
        if (!is_numeric($n) || (int) $n < 1 || (int) $n > max(1, $nCuissons)) { return [false, 'nombre de cuissons du ' . PF_JOURS[$j] . ' : entre 1 et ' . max(1, $nCuissons), null]; }
        if (!is_numeric($m) || (float) $m < 0 || (float) $m > 100) { return [false, 'production minimum du ' . PF_JOURS[$j] . ' : entre 0 et 100 %', null]; }
        $jours[$j] = ['cuissons' => (int) $n, 'minPct' => round((float) $m, 1)];
    }
    $ob = [];
    foreach ((array) ($p['obligatoires'] ?? []) as $pid => $e) {
        if (!preg_match('/^\d{1,9}$/', (string) $pid) || !is_array($e)) { continue; }
        $min = $e['min'] ?? PF_MIN_OBLIG;
        if (!is_numeric($min) || (int) $min < 0 || (int) $min > 500) { return [false, 'minimum invalide pour le produit ' . $pid, null]; }
        $j = array_values(array_unique(array_filter(array_map('intval', (array) ($e['jours'] ?? [])), static fn ($x) => $x >= 1 && $x <= 7)));
        sort($j);
        $ob[(string) $pid] = ['jours' => $j, 'min' => (int) $min];
    }
    if (count($ob) > 2000) { return [false, 'trop de produits obligatoires', null]; }
    $cle = static fn ($l) => array_values(array_unique(array_filter(array_map('strval', (array) $l), static fn ($k) => (bool) preg_match('/^(\d{1,9}|n:.{1,80})$/u', $k))));
    return [true, null, ['jours' => $jours, 'obligatoires' => $ob, 'veille' => $cle($p['veille'] ?? []), 'garde' => $cle($p['garde'] ?? [])]];
}

/**
 * Les paramètres de production d'UN jour : les n premières cuissons (leurs parts ramenées à
 * 100 %), les catégories limitées à ces cuissons — une catégorie qui ne cuisait que plus tard
 * passe à la dernière cuisson du jour — et la production minimum de la 1re cuisson.
 */
function pfJourParams(array $gp, array $pf, int $jour): array
{
    $j = $pf['jours'][$jour] ?? ['cuissons' => count($gp['cuissons']), 'minPct' => 50.0];
    $C = array_slice($gp['cuissons'], 0, max(1, (int) $j['cuissons']));
    $tot = array_sum(array_map(static fn ($c) => (float) $c['pct'], $C));
    foreach ($C as &$c) { $c['pct'] = $tot > 0 ? round(100 * (float) $c['pct'] / $tot, 1) : round(100 / count($C), 1); }
    unset($c);
    $ids = array_column($C, 'id'); $der = end($ids);
    $cats = [];
    foreach ($gp['categories'] as $k => $e) {
        $co = array_values(array_filter((array) $e['cuissons'], static fn ($x) => in_array($x, $ids, true)));
        if ($co === [] && (array) $e['cuissons'] !== []) { $co = [$der]; }
        $cats[(string) $k] = array_merge($e, ['cuissons' => $co]);
    }
    return ['params' => array_merge($gp, ['cuissons' => $C, 'categories' => $cats]), 'minPct' => (float) $j['minPct'] / 100, 'cuissons' => count($C)];
}

/** Les obligatoires d'un jour de la semaine : [pid => pièces minimum]. */
function pfObligDuJour(array $pf, int $jour): array
{
    $o = [];
    foreach ($pf['obligatoires'] as $pid => $e) { if (in_array($jour, $e['jours'], true)) { $o[(int) $pid] = max(0, (int) $e['min']); } }
    return $o;
}

/** Le report de la clôture d'un jour (ce qui se garde pour le lendemain) : [pid => pièces]. */
function pfReport(int $sid, string $date): array
{
    $c = setting('pfCloture:' . $sid . ':' . $date);
    $out = [];
    if (is_array($c) && is_array($c['l'] ?? null)) { foreach ($c['l'] as $pid => $x) { $q = (float) ($x['report'] ?? 0); if ($q > 0) { $out[(int) $pid] = $q; } } }
    return $out;
}

/** Les cuissons validées d'un jour : [faits: cuisson => [pid => pièces], valid: cuisson => {le, par}]. */
function pfFaits(int $sid, string $date): array
{
    $f = setting('ppFait:' . $sid . ':' . $date);
    return ['faits' => is_array($f) && is_array($f['c'] ?? null) ? $f['c'] : [], 'valid' => is_array($f) && is_array($f['v'] ?? null) ? $f['v'] : []];
}

/** Les ventes d'un jour par produit : [pid => pièces] (portions ramenées à la pièce), et la part des clients pro. */
function pfVentesJour(int $sid, string $date, int &$cout, int $budget): ?array
{
    $p = function_exists('svProduitsJour') ? svProduitsJour($sid, $date, $cout, $budget) : null;
    if ($p === null) { return null; }
    $f = gpPlier($p);
    $tot = []; foreach ($f['q'] as $pid => $hs) { $tot[(int) $pid] = array_sum($hs); }
    $g = setting('svP' . $sid . ':' . $date);
    $pro = [];
    if (is_array($g) && isset($g['pb'])) { foreach ((array) $g['pb'] as $pid => $q) { $pro[(int) $pid] = min((float) $q, (float) ($tot[(int) $pid] ?? 0)); } }
    return ['tot' => $tot, 'pro' => $pro, 'h' => $f['q'], 'noms' => $f['noms']];
}

/** Ce qu'il faut pour planifier un jour : base, paramètres du jour, obligatoires, report, plan. */
function pfCalcul(int $sid, string $date, int &$cout, int $budget, bool $avecCommandes = true): array
{
    $dp = gpDayparts();
    $s = setting('gpParams:' . $sid);
    $sem = is_array($s) && isset($s['regles']['semaines']) ? max(1, min(12, (int) $s['regles']['semaines'])) : gpReglesDefaut()['semaines'];
    $base = gpBase($sid, $date, $sem, $cout, $budget);
    $gp = gpParams($sid, $dp, $base);
    $pf = pfParams($sid, $gp, $date);
    $jour = pfJour($date);
    $jp = pfJourParams($gp, $pf, $jour);
    $oblig = pfObligDuJour($pf, $jour);
    $stock0 = pfReport($sid, pfDecale($date, -1));
    $F = pfFaits($sid, $date);
    $cmds = $avecCommandes ? (gpCommandes($sid, $date) ?? []) : [];
    $prix = gpPrix($sid);
    $plan = gpPlan($jp['params'], $base, $cmds, $F['faits'], $prix, ['stock0' => $stock0, 'minPct' => $jp['minPct'], 'oblig' => $oblig]);
    return ['sem' => $sem, 'base' => $base, 'gp' => $gp, 'pf' => $pf, 'jour' => $jour, 'jp' => $jp, 'oblig' => $oblig, 'stock0' => $stock0, 'faits' => $F['faits'], 'valid' => $F['valid'], 'cmds' => $cmds, 'prix' => $prix, 'plan' => $plan];
}

/** Les lignes du plan regroupées par produit : une ligne par produit, une colonne par cuisson. */
function pfParProduit(array $plan): array
{
    $out = [];
    foreach ($plan as $c) {
        foreach ($c['lignes'] as $l) {
            $pid = (int) $l['pid'];
            $out[$pid] ??= ['pid' => $pid, 'nom' => $l['nom'], 'groupe' => $l['groupe'], 'cat' => $l['cat'], 'catCle' => $l['catCle'], 'prix' => $l['prix'], 'prevJ' => $l['prevJ'], 'oblig' => !empty($l['oblig']), 'h' => $l['h'], 'c' => []];
            $out[$pid]['c'][$c['id']] = ['sortie' => $l['sortie'], 'plaques' => $l['plaques'], 'plaque' => $l['plaque'], 'stock' => $l['stock'], 'prevu' => $l['prevu'], 'fait' => $l['fait'], 'zone' => $l['zone']];
        }
    }
    return $out;
}

function pfShopDate(bool $futur): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = $auj; }
    if ($futur) { if ($date > pfDecale($auj, 7)) { $date = $auj; } } elseif ($date > $auj) { $date = $auj; }
    return [$sid, $date, $auj];
}

/**
 * GET /production/flux/params?shop=4 — les réglages du flux et de la production (cuissons,
 * catégories, règles), les catégories et les produits à cocher (vendus dans la base ou
 * obligatoires), pour la page 1.
 */
function ep_production_flux_params(): array
{
    [$sid, , $auj] = pfShopDate(false);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    @set_time_limit(90);
    $cout = 0;
    $date = pfDecale($auj, 1);
    $dp = gpDayparts();
    $s = setting('gpParams:' . $sid);
    $sem = is_array($s) && isset($s['regles']['semaines']) ? max(1, min(12, (int) $s['regles']['semaines'])) : gpReglesDefaut()['semaines'];
    $base = gpBase($sid, $date, $sem, $cout, defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 500);
    $gp = gpParams($sid, $dp, $base);
    $pf = pfParams($sid, $gp, $auj);
    $cat = gpCatalogue();
    $cc = $cat['categories'];
    $cats = [];
    foreach ($gp['categories'] as $k => $e) {
        $cats[] = ['cle' => (string) $k, 'nom' => $e['nom'], 'catId' => $e['catId'], 'groupe' => $e['catId'] > 0 ? (string) ($cc[$e['catId']]['groupe'] ?? '') : '',
            'cuissons' => $e['cuissons'], 'plaque' => $e['plaque'], 'limite' => $e['limite'], 'auto' => $e['auto'],
            'veille' => in_array((string) $k, $pf['veille'], true), 'garde' => in_array((string) $k, $pf['garde'], true)];
    }
    // Les produits à régler : ceux de la base (vendus ces semaines-là) et les obligatoires.
    $prods = [];
    foreach ($base['produits'] as $pid => $p) {
        $prods[(int) $pid] = ['pid' => (int) $pid, 'nom' => $p['nom'], 'cat' => $p['cat'], 'catCle' => $p['catCle'], 'groupe' => (string) $p['groupe'], 'parJour' => round(array_sum($p['h']), 1)];
    }
    foreach ($pf['obligatoires'] as $pid => $e) {
        if (isset($prods[$pid])) { continue; }
        $x = $cat['produits'][$pid] ?? null;
        $k = gpCatDe((int) $pid, $x);
        $prods[$pid] = ['pid' => $pid, 'nom' => (string) ($x['nom'] ?? ('Produit ' . $pid)), 'cat' => $k['cat'], 'catCle' => $k['catCle'], 'groupe' => $k['groupe'], 'parJour' => 0.0];
    }
    foreach ($prods as $pid => &$p) { $o = $pf['obligatoires'][$pid] ?? null; $p['oblig'] = $o; } unset($p);
    $prods = array_values($prods);
    usort($prods, static fn ($a, $b) => [$a['groupe'] === '' ? 'zzz' : $a['groupe'], $a['cat'], -$a['parJour'], $a['nom']] <=> [$b['groupe'] === '' ? 'zzz' : $b['groupe'], $b['cat'], -$b['parJour'], $b['nom']]);
    usort($cats, static fn ($a, $b) => [$a['groupe'] === '' ? 'zzz' : $a['groupe'], $a['nom']] <=> [$b['groupe'] === '' ? 'zzz' : $b['groupe'], $b['nom']]);
    return ['shop' => $sid, 'aujourdhui' => $auj, 'jours' => PF_JOURS,
        'flux' => ['jours' => $pf['jours'], 'veille' => $pf['veille'], 'garde' => $pf['garde'], 'enregistre' => $pf['enregistre'], 'maj' => $pf['maj'], 'par' => $pf['par']],
        'cuissons' => $gp['cuissons'], 'regles' => $gp['regles'], 'gpEnregistre' => $gp['enregistre'], 'dayparts' => $dp,
        'categories' => $cats, 'produits' => $prods, 'reseau' => count(pfObligReseau($auj)),
        'base' => ['semaines' => $sem, 'lus' => count($base['lus']), 'jours' => count($base['jours']), 'manquants' => $base['manquants']]];
}

/**
 * POST /production/flux/params — { shop, gp: {cuissons, categories, regles}, flux: {jours,
 * obligatoires, veille, garde}, par }. Les deux réglages se valident avant que l'un s'écrive.
 */
function wr_production_flux_params(): array
{
    $b = body();
    $sid = (int) ($b['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'magasin manquant']; }
    [$ok, $err, $gp] = gpValider(is_array($b['gp'] ?? null) ? $b['gp'] : []);
    if (!$ok) { http_response_code(422); return ['error' => $err]; }
    [$ok, $err, $pf] = pfValiderParams(is_array($b['flux'] ?? null) ? $b['flux'] : [], count($gp['cuissons']));
    if (!$ok) { http_response_code(422); return ['error' => $err]; }
    $par = mb_substr(trim((string) ($b['par'] ?? '')), 0, 80) ?: null;
    $gp['maj'] = date('c'); $gp['par'] = $par;
    $pf['maj'] = date('c'); $pf['par'] = $par;
    gpEcrire('gpParams:' . $sid, $gp);
    gpEcrire('pfParams:' . $sid, $pf);
    return ['ok' => true, 'cuissons' => count($gp['cuissons']), 'obligatoires' => count($pf['obligatoires'])];
}

/**
 * GET /production/flux/plan?shop=4&date=YYYY-MM-DD — le tableau de production du jour :
 * catégorie › sous-catégorie › produit, vendu à J−7 par canal, à produire par cuisson, à
 * préparer pour la 1re cuisson du lendemain (catégories préparées la veille).
 */
function ep_production_flux_plan(): array
{
    [$sid, $date, $auj] = pfShopDate(true);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    @set_time_limit(120);
    $budget = defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 500;
    $cout = 0;
    $K = pfCalcul($sid, $date, $cout, $budget);
    $L = pfParProduit($K['plan']);
    // J−7 : le même jour la semaine d'avant, par canal. Les tickets donnent le comptoir et les
    // clients pro (les commandes passées en magasin) ; le webshop n'a pas d'articles au panel.
    $j7 = pfDecale($date, -7);
    $v7 = pfVentesJour($sid, $j7, $cout, $budget);
    $c7 = gpCommandes($sid, $j7);
    $ws7 = ['n' => 0, 'ca' => 0.0]; $cm7 = ['n' => 0, 'ca' => 0.0];
    foreach ((array) $c7 as $o) { if (!empty($o['webshop'])) { $ws7['n']++; $ws7['ca'] += (float) $o['montant']; } else { $cm7['n']++; $cm7['ca'] += (float) $o['montant']; } }
    // Le lendemain : sa 1re cuisson, pour les catégories préparées la veille.
    $d1 = pfDecale($date, 1);
    $K1 = null; $veille = [];
    if ($K['pf']['veille'] !== []) {
        $K1 = pfCalcul($sid, $d1, $cout, $budget, false);
        $c1 = $K1['plan'][0] ?? null;
        if ($c1 !== null) { foreach ($c1['lignes'] as $l) { if (in_array((string) $l['catCle'], $K['pf']['veille'], true)) { $veille[(int) $l['pid']] = ['sortie' => $l['sortie'], 'plaques' => $l['plaques'], 'plaque' => $l['plaque'], 'nom' => $l['nom'], 'groupe' => $l['groupe'], 'cat' => $l['cat'], 'catCle' => $l['catCle'], 'prix' => $l['prix']]; } }
        }
    }
    // Un produit préparé pour demain qui ne se cuit pas aujourd'hui garde sa ligne.
    foreach ($veille as $pid => $v) {
        if (!isset($L[$pid])) { $L[$pid] = ['pid' => $pid, 'nom' => $v['nom'], 'groupe' => $v['groupe'], 'cat' => $v['cat'], 'catCle' => $v['catCle'], 'prix' => $v['prix'], 'prevJ' => 0.0, 'oblig' => false, 'h' => [], 'c' => []]; }
    }
    $C = array_map(static fn ($c) => ['id' => $c['id'], 'k' => $c['k'], 'nom' => $c['nom'], 'de' => $c['de'], 'a' => $c['a'], 'pct' => $c['pct'], 'four' => $c['four']], $K['plan']);
    $lignes = [];
    foreach ($L as $pid => $l) {
        $tot = 0; $ca = 0.0;
        foreach ($l['c'] as $x) { $tot += (int) $x['sortie']; }
        if ($l['prix'] !== null) { $ca = round($tot * (float) $l['prix'], 2); }
        $mag = $v7 !== null ? (float) ($v7['tot'][$pid] ?? 0) - (float) ($v7['pro'][$pid] ?? 0) : null;
        $lignes[] = ['pid' => $pid, 'nom' => $l['nom'], 'groupe' => $l['groupe'] !== '' ? $l['groupe'] : $l['cat'], 'cat' => $l['cat'], 'catCle' => $l['catCle'], 'oblig' => $l['oblig'], 'prix' => $l['prix'],
            'j7' => ['magasin' => $mag !== null ? round(max(0.0, $mag), 1) : null, 'webshop' => null, 'commandes' => $v7 !== null ? round((float) ($v7['pro'][$pid] ?? 0), 1) : null],
            'prevJ' => $l['prevJ'], 'report' => round((float) ($K['stock0'][$pid] ?? 0), 1),
            'c' => (object) $l['c'], 'total' => $tot, 'ca' => $l['prix'] !== null ? $ca : null,
            'veille' => isset($veille[$pid]) ? ['sortie' => $veille[$pid]['sortie'], 'plaques' => $veille[$pid]['plaques'], 'plaque' => $veille[$pid]['plaque']] : null];
    }
    // Section (groupe) › catégorie › produit, du plus produit au moins produit.
    $pg = []; $pc = [];
    foreach ($lignes as $l) { $w = $l['total'] + ($l['veille']['sortie'] ?? 0); $pg[$l['groupe']] = ($pg[$l['groupe']] ?? 0) + $w; $pc[$l['catCle']] = ($pc[$l['catCle']] ?? 0) + $w; }
    usort($lignes, static fn ($a, $b) => [-($pg[$a['groupe']] ?? 0), $a['groupe'], -($pc[$a['catCle']] ?? 0), $a['cat'], -($a['total'] + ($a['veille']['sortie'] ?? 0)), $a['nom']]
        <=> [-($pg[$b['groupe']] ?? 0), $b['groupe'], -($pc[$b['catCle']] ?? 0), $b['cat'], -($b['total'] + ($b['veille']['sortie'] ?? 0)), $b['nom']]);
    return ['shop' => $sid, 'date' => $date, 'aujourdhui' => $auj, 'jourSemaine' => $K['jour'], 'jourNom' => PF_JOURS[$K['jour']],
        'cuissons' => $C, 'minPct' => round(100 * $K['jp']['minPct'], 1), 'nCuissons' => $K['jp']['cuissons'],
        'lignes' => $lignes, 'obligatoires' => count($K['oblig']), 'reportVeille' => ['pieces' => round(array_sum($K['stock0']), 1), 'produits' => count($K['stock0']), 'cloture' => setting('pfCloture:' . $sid . ':' . pfDecale($date, -1)) !== null],
        'j7' => ['date' => $j7, 'lu' => $v7 !== null, 'webshop' => ['n' => $ws7['n'], 'ca' => round($ws7['ca'], 2)], 'commandes' => ['n' => $cm7['n'], 'ca' => round($cm7['ca'], 2)], 'commandesLues' => $c7 !== null],
        'lendemain' => ['date' => $d1, 'jourNom' => PF_JOURS[pfJour($d1)], 'categories' => count($K['pf']['veille']), 'complet' => $K1 === null || $K1['base']['manquants'] === [], 'lus' => $K1 !== null ? count($K1['base']['lus']) : 0, 'jours' => $K1 !== null ? count($K1['base']['jours']) : 0],
        'base' => ['semaines' => $K['sem'], 'jours' => count($K['base']['jours']), 'lus' => count($K['base']['lus']), 'manquants' => $K['base']['manquants']],
        'source' => 'prévision : tickets du panel, moyenne des ' . $K['sem'] . ' derniers ' . PF_JOURS[$K['jour']] . 's heure par heure · J−7 : tickets du ' . $j7 . ' (clients pro = commandes passées en magasin) · webshop : commandes du panel, sans articles'];
}

/**
 * GET /production/flux/suivi?shop=4&date=YYYY-MM-DD — la validation des cuissons et la
 * surveillance heure par heure : pour chaque produit, le stock à la fin de chaque heure
 * passée (report + sorti − vendu) et projeté pour les heures à venir (6 derniers mêmes jours),
 * l'heure du premier manque et le conseil.
 */
function ep_production_flux_suivi(): array
{
    [$sid, $date, $auj] = pfShopDate(false);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    @set_time_limit(120);
    $budget = defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 500;
    $cout = 0;
    $K = pfCalcul($sid, $date, $cout, $budget);
    $now = $date === $auj ? (int) date('G') + (int) date('i') / 60 : 24.0;
    $V = pfVentesJour($sid, $date, $cout, $budget);
    $jete = [];
    if (function_exists('invLignes')) { foreach ((array) invLignes($sid, $date, $date) as $l) { $jete[(int) $l['pid']] = ($jete[(int) $l['pid']] ?? 0.0) + (float) $l['pieces']; } }
    $R = (float) $K['gp']['regles']['securite'];
    $suivi = pfSuivi($K['plan'], $K['stock0'], $V !== null ? $V['h'] : [], $now);
    $C = [];
    foreach ($K['plan'] as $c) {
        $v = $K['valid'][$c['id']] ?? null;
        $C[] = ['id' => $c['id'], 'k' => $c['k'], 'nom' => $c['nom'], 'de' => $c['de'], 'a' => $c['a'], 'four' => $c['four'],
            'valide' => isset($K['faits'][$c['id']]), 'le' => is_array($v) ? ($v['le'] ?? null) : null, 'par' => is_array($v) ? ($v['par'] ?? null) : null,
            'pieces' => $c['total']['pieces'], 'fait' => isset($K['faits'][$c['id']]) ? round(array_sum(array_map('floatval', $K['faits'][$c['id']])), 1) : null,
            'lignes' => array_map(static fn ($l) => ['pid' => $l['pid'], 'nom' => $l['nom'], 'groupe' => $l['groupe'], 'cat' => $l['cat'], 'sortie' => $l['sortie'], 'plaques' => $l['plaques'], 'plaque' => $l['plaque'], 'fait' => $l['fait'], 'oblig' => !empty($l['oblig'])], $c['lignes'])];
    }
    foreach ($suivi['produits'] as &$p) { $p['jete'] = round((float) ($jete[$p['pid']] ?? 0), 1); } unset($p);
    return ['shop' => $sid, 'date' => $date, 'aujourdhui' => $auj, 'maintenant' => $date === $auj ? date('H:i') : null, 'jourNom' => PF_JOURS[$K['jour']],
        'cuissons' => $C, 'heures' => $suivi['heures'], 'produits' => $suivi['produits'], 'totaux' => $suivi['totaux'],
        'ventesLues' => $V !== null, 'securite' => $R,
        'base' => ['semaines' => $K['sem'], 'lus' => count($K['base']['lus']), 'jours' => count($K['base']['jours']), 'manquants' => $K['base']['manquants']],
        'source' => 'sorti : cuissons validées en magasin (le plan tant qu’une cuisson n’est pas validée) · vendu : tickets du panel heure par heure · projeté : moyenne des ' . $K['sem'] . ' derniers ' . PF_JOURS[$K['jour']] . 's'];
}

/**
 * Le stock de chaque produit heure par heure. Une cuisson met ses pièces en vitrine à
 * l'ouverture de sa période (validé, sinon le plan). Heures passées : report + sorti − vendu.
 * Heures à venir : le stock actuel + les cuissons à venir − la prévision (au prorata de
 * l'heure entamée). Le premier manque, le stock projeté à la fermeture, le verdict.
 */
function pfSuivi(array $plan, array $stock0, array $vendu, float $now): array
{
    $P = pfParProduit($plan);
    $dispo = [];   // pid => [heure de mise en vitrine => pièces]
    foreach ($plan as $c) {
        $h = gpHeure($c['de']) ?? 0.0;
        foreach ($c['lignes'] as $l) { $q = $l['fait'] !== null ? (float) $l['fait'] : (float) $l['sortie']; if ($q > 0) { $dispo[(int) $l['pid']][(string) $h] = ($dispo[(int) $l['pid']][(string) $h] ?? 0.0) + $q; } }
    }
    // Les heures de la journée : celles où le magasin vend vraiment (au moins une pièce prévue
    // ou vendue, tous produits confondus) — un ticket isolé à 21 h n'ouvre pas trois colonnes.
    $parH = [];
    foreach ($P as $p) { foreach ($p['h'] as $h => $q) { $parH[(int) $h] = ($parH[(int) $h] ?? 0.0) + (float) $q; } }
    foreach ($vendu as $hs) { foreach ($hs as $h => $q) { $parH[(int) $h] = max($parH[(int) $h] ?? 0.0, 0.0) + (float) $q; } }
    // Bornée par les périodes de vente des cuissons (de l'ouverture de la 1re à la fin de la
    // dernière) et par les heures réellement vendues ce jour : la base garde parfois un ticket
    // tardif (mesuré à Halle, des ventes vers 21 h certains samedis) qui n'est pas une heure d'ouverture.
    $vendues = []; foreach ($vendu as $hs) { foreach ($hs as $h => $q) { if ($q > 0) { $vendues[] = (int) $h; } } }
    $deb = []; $fin = [];
    foreach ($plan as $c) { $a = gpHeure($c['de'] ?? null); $b = gpHeure($c['a'] ?? null); if ($a !== null) { $deb[] = (int) floor($a); } if ($b !== null) { $fin[] = (int) ceil($b) - 1; } }
    if ($deb !== [] && $fin !== []) { $h0 = min(array_merge($deb, $vendues)); $h1 = max(array_merge($fin, $vendues)); }
    else { $vraies = array_keys(array_filter($parH, static fn ($q) => $q >= 1.0)); $h0 = $vraies ? min($vraies) : 6; $h1 = $vraies ? max($vraies) : 19; }
    if ($h0 > $h1) { $h0 = 6; $h1 = 19; }
    $heures = range($h0, $h1);
    $out = []; $T = ['sorti' => 0.0, 'vendu' => 0.0, 'stock' => 0.0, 'finJour' => 0.0, 'manques' => 0, 'ruptures' => 0, 'trop' => 0, 'report' => 0.0];
    $pids = array_unique(array_merge(array_keys($P), array_map('intval', array_keys($stock0))));
    foreach ($pids as $pid) {
        $p = $P[$pid] ?? null;
        if ($p === null) { continue; }   // pas planifié aujourd'hui : rien à surveiller
        $s0 = (float) ($stock0[$pid] ?? 0);
        $vd = $vendu[$pid] ?? [];
        $prof = $p['h'];
        $cases = []; $stockNow = null; $manque = null; $sortiTot = 0.0; $venduTot = array_sum($vd);
        $cumDispo = static function (float $t) use ($dispo, $pid): float { $s = 0.0; foreach ($dispo[$pid] ?? [] as $h => $q) { if ((float) $h <= $t + 1e-9) { $s += $q; } } return $s; };
        foreach ($dispo[$pid] ?? [] as $q) { $sortiTot += $q; }
        $vCum = 0.0;
        foreach ($heures as $h) {
            $fin = $h + 1.0;
            if ($fin <= $now + 1e-9) {
                // Heure passée : le réel.
                $vCum += (float) ($vd[$h] ?? 0);
                $st = $s0 + $cumDispo($h + 0.999) - $vCum;
                $cases[] = ['h' => $h, 'q' => round($st, 1), 'v' => round((float) ($vd[$h] ?? 0), 1), 'reel' => true];
            } else {
                if ($stockNow === null) {
                    // L'heure entamée : ce qui est déjà vendu, et la prévision du reste de l'heure.
                    $dejaH = $h < $now ? (float) ($vd[$h] ?? 0) : 0.0;
                    $vCum += $dejaH;
                    $stockNow = $s0 + $cumDispo(max($now, (float) $h)) - $vCum;
                    $proj = $stockNow;
                }
                $debut = max((float) $h, $now);
                $prev = (float) ($prof[$h] ?? 0) * max(0.0, $fin - $debut);
                // Les cuissons qui sortent pendant l'heure à venir.
                foreach ($dispo[$pid] ?? [] as $hh => $q) { if ((float) $hh > $now + 1e-9 && (float) $hh >= (float) $h && (float) $hh < $fin) { $proj += $q; } }
                $proj -= $prev;
                if ($manque === null && $proj < -0.5) { $manque = ['h' => $h, 'q' => round(-$proj, 1)]; }
                $cases[] = ['h' => $h, 'q' => round($proj, 1), 'prev' => round($prev, 1), 'reel' => false];
            }
        }
        if ($stockNow === null) { $stockNow = $s0 + $sortiTot - $venduTot; $proj = $stockNow; }
        $prevJ = array_sum($prof);
        $finJour = $proj;
        $verdict = 'ok';
        $resteJ = gpSomme($prof, $now, (float) $h1 + 1);   // ce que la journée vend encore, jusqu'à la fermeture
        if ($now < 24 && $stockNow <= 0.5 && $prevJ > 0 && $resteJ > 0.5) { $verdict = 'rupture'; }
        elseif ($manque !== null) { $verdict = 'manque'; }
        elseif ($prevJ > 0 && $finJour > PF_TROP * $prevJ && $finJour >= 2) { $verdict = 'trop'; }
        // Le conseil : de quoi tenir jusqu'à la fermeture (déficit projeté), arrondi à la plaque.
        $conseil = null;
        if ($verdict === 'rupture' || $verdict === 'manque') {
            // De quoi tenir jusqu'à la fermeture : le déficit projeté, ou en rupture ce que la journée vend encore.
            $def = max($manque['q'] ?? 0.0, -$finJour, $verdict === 'rupture' ? $resteJ - max(0.0, $stockNow) : 0.0);
            $pl = null; foreach ($p['c'] as $x) { if (!empty($x['plaque'])) { $pl = (int) $x['plaque']; } }
            $n = max(1, (int) ceil($def - 1e-6));
            $conseil = ['pieces' => $n, 'plaques' => $pl ? (int) ceil($n / $pl) : null, 'plaque' => $pl];
        }
        $T['sorti'] += $sortiTot; $T['vendu'] += $venduTot; $T['stock'] += max(0.0, $stockNow); $T['finJour'] += max(0.0, $finJour); $T['report'] += $s0;
        if ($verdict === 'manque') { $T['manques']++; } elseif ($verdict === 'rupture') { $T['ruptures']++; } elseif ($verdict === 'trop') { $T['trop']++; }
        $out[] = ['pid' => $pid, 'nom' => $p['nom'], 'groupe' => $p['groupe'], 'cat' => $p['cat'], 'catCle' => $p['catCle'], 'oblig' => $p['oblig'], 'prix' => $p['prix'],
            'report' => round($s0, 1), 'sorti' => round($sortiTot, 1), 'vendu' => round($venduTot, 1), 'stock' => round($stockNow, 1), 'finJour' => round($finJour, 1), 'prevJ' => round($prevJ, 1),
            'manque' => $manque, 'verdict' => $verdict, 'conseil' => $conseil, 'cases' => $cases];
    }
    $rang = ['rupture' => 0, 'manque' => 1, 'trop' => 2, 'ok' => 3];
    usort($out, static fn ($a, $b) => [$rang[$a['verdict']], $a['manque']['h'] ?? 99, $a['groupe'], $a['cat'], -$a['prevJ']] <=> [$rang[$b['verdict']], $b['manque']['h'] ?? 99, $b['groupe'], $b['cat'], -$b['prevJ']]);
    foreach ($T as $k => $v) { if (is_float($v)) { $T[$k] = round($v, 1); } }
    return ['heures' => $heures, 'produits' => $out, 'totaux' => $T];
}

/**
 * POST /production/flux/valider — { shop, date, cuisson, lignes: { pid: pièces }, par } : ce
 * que le magasin a réellement sorti du four. Le même enregistrement que l'écran du cockpit
 * (ppFait), avec qui et quand.
 */
function wr_production_flux_valider(): array
{
    $b = body();
    $sid = (int) ($b['shop'] ?? 0); $date = (string) ($b['date'] ?? ''); $cu = (string) ($b['cuisson'] ?? '');
    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^c\d{1,2}$/', $cu)) { http_response_code(400); return ['error' => 'magasin, date ou cuisson manquant']; }
    if ($date > date('Y-m-d')) { http_response_code(422); return ['error' => 'une cuisson de demain ne se valide pas encore']; }
    $l = [];
    foreach ((array) ($b['lignes'] ?? []) as $pid => $q) {
        if (!preg_match('/^\d{1,9}$/', (string) $pid) || !is_numeric($q) || (float) $q < 0 || (float) $q > 5000) { http_response_code(422); return ['error' => 'quantité invalide pour le produit ' . $pid]; }
        $l[(string) $pid] = round((float) $q, 2);
    }
    $cle = 'ppFait:' . $sid . ':' . $date;
    $c = setting($cle); $c = is_array($c) && is_array($c['c'] ?? null) ? $c : ['c' => []];
    $c['c'][$cu] = $l; $c['le'] = date('c');
    $c['v'] = is_array($c['v'] ?? null) ? $c['v'] : [];
    $c['v'][$cu] = ['le' => date('c'), 'par' => mb_substr(trim((string) ($b['par'] ?? '')), 0, 80) ?: null];
    gpEcrire($cle, $c);
    return ['ok' => true, 'cuisson' => $cu, 'lignes' => count($l), 'pieces' => round(array_sum($l), 1)];
}

/**
 * GET /production/flux/cloture?shop=4&date=YYYY-MM-DD — la fin de journée : pour chaque
 * produit, report de la veille + sorti − vendu − jeté déjà déclaré = reste ; ce qui se garde
 * pour demain (les catégories « se garde »), ce qui se jette, et ce qui reste à déclarer en
 * caisse. La clôture enregistrée remplace la proposition.
 */
function ep_production_flux_cloture(): array
{
    [$sid, $date, $auj] = pfShopDate(false);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    @set_time_limit(120);
    $budget = defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 500;
    $cout = 0;
    $K = pfCalcul($sid, $date, $cout, $budget, false);
    $V = pfVentesJour($sid, $date, $cout, $budget);
    $jete = [];
    $invLu = function_exists('invLignes');
    if ($invLu) { foreach ((array) invLignes($sid, $date, $date) as $l) { $jete[(int) $l['pid']] = ($jete[(int) $l['pid']] ?? 0.0) + (float) $l['pieces']; } }
    $P = pfParProduit($K['plan']);
    $enr = setting('pfCloture:' . $sid . ':' . $date);
    $E = is_array($enr) && is_array($enr['l'] ?? null) ? $enr['l'] : null;
    // Les cuissons qui ont quelque chose à sortir ; une cuisson vide n'attend pas de validation.
    $aValider = array_values(array_map(static fn ($c) => $c['id'], array_filter($K['plan'], static fn ($c) => $c['total']['pieces'] > 0)));
    $valides = array_values(array_intersect($aValider, array_map('strval', array_keys($K['faits']))));
    $lignes = []; $T = ['reste' => 0.0, 'report' => 0.0, 'jete' => 0.0, 'aDeclarer' => 0.0, 'valeurJetee' => 0.0, 'valeurGardee' => 0.0];
    $pids = array_unique(array_merge(array_keys($P), array_map('intval', array_keys($K['stock0']))));
    foreach ($pids as $pid) {
        $p = $P[$pid] ?? null;
        $sorti = 0.0; $toutValide = true;
        if ($p !== null) { foreach ($p['c'] as $id => $x) { $f = $x['fait']; if ($f === null) { $toutValide = false; } $sorti += $f !== null ? (float) $f : (float) $x['sortie']; } }
        $s0 = (float) ($K['stock0'][$pid] ?? 0);
        $vd = $V !== null ? (float) ($V['tot'][$pid] ?? 0) : 0.0;
        $jd = (float) ($jete[$pid] ?? 0);
        $reste = max(0.0, $s0 + $sorti - $vd - $jd);
        $nom = $p['nom'] ?? (gpCatalogue()['produits'][$pid]['nom'] ?? ('Produit ' . $pid));
        $catCle = $p['catCle'] ?? null; $garde = $catCle !== null && in_array((string) $catCle, $K['pf']['garde'], true);
        $e = $E !== null ? ($E[(string) $pid] ?? null) : null;
        $rep = $e !== null ? (float) ($e['report'] ?? 0) : ($garde ? round($reste) : 0.0);
        $jet = $e !== null ? (float) ($e['jete'] ?? 0) : ($garde ? 0.0 : round($reste));
        if ($reste < 0.5 && $e === null && $s0 <= 0 && $sorti <= 0) { continue; }
        $prix = $p['prix'] ?? null;
        $lignes[] = ['pid' => $pid, 'nom' => $nom, 'groupe' => $p !== null ? $p['groupe'] : '', 'cat' => $p['cat'] ?? '', 'catCle' => $catCle, 'garde' => $garde, 'prix' => $prix,
            'report0' => round($s0, 1), 'sorti' => round($sorti, 1), 'sortiValide' => $p !== null && $toutValide, 'vendu' => round($vd, 1), 'jeteDeclare' => round($jd, 1),
            'reste' => round($reste, 1), 'report' => round($rep, 1), 'jete' => round($jet, 1), 'aDeclarer' => round(max(0.0, $jet), 1)];
        $T['reste'] += $reste; $T['report'] += $rep; $T['jete'] += $jet; $T['aDeclarer'] += max(0.0, $jet);
        if ($prix !== null) { $T['valeurJetee'] += $jet * (float) $prix; $T['valeurGardee'] += $rep * (float) $prix; }
    }
    // Section › catégorie › produit, la section qui a le plus de reste d'abord.
    $pg = []; foreach ($lignes as $x) { $pg[$x['groupe']] = ($pg[$x['groupe']] ?? 0) + $x['reste']; }
    usort($lignes, static fn ($a, $b) => [-($pg[$a['groupe']] ?? 0), $a['groupe'], $a['cat'], -$a['reste'], $a['nom']] <=> [-($pg[$b['groupe']] ?? 0), $b['groupe'], $b['cat'], -$b['reste'], $b['nom']]);
    foreach ($T as $k => $v) { $T[$k] = round($v, 1); }
    return ['shop' => $sid, 'date' => $date, 'aujourdhui' => $auj, 'jourNom' => PF_JOURS[$K['jour']], 'lendemain' => pfDecale($date, 1),
        'lignes' => $lignes, 'totaux' => $T, 'enregistree' => $E !== null, 'le' => is_array($enr) ? ($enr['le'] ?? null) : null, 'par' => is_array($enr) ? ($enr['par'] ?? null) : null,
        'cuissonsValidees' => count($valides), 'cuissons' => count($aValider), 'ventesLues' => $V !== null, 'poubelleLue' => $invLu,
        'source' => 'sorti : cuissons validées (sinon le plan) · vendu : tickets du panel · jeté déclaré : /shops/{id}/products/waste · le report devient le stock de départ du plan du lendemain'];
}

/** POST /production/flux/cloture — { shop, date, lignes: { pid: {report, jete, reste} }, par }. */
function wr_production_flux_cloture(): array
{
    $b = body();
    $sid = (int) ($b['shop'] ?? 0); $date = (string) ($b['date'] ?? '');
    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); return ['error' => 'magasin ou date manquant']; }
    if ($date > date('Y-m-d')) { http_response_code(422); return ['error' => 'une journée à venir ne se clôture pas'];
    }
    $l = [];
    foreach ((array) ($b['lignes'] ?? []) as $pid => $x) {
        if (!preg_match('/^\d{1,9}$/', (string) $pid) || !is_array($x)) { continue; }
        $r = $x['report'] ?? 0; $j = $x['jete'] ?? 0; $re = $x['reste'] ?? null;
        if (!is_numeric($r) || !is_numeric($j) || (float) $r < 0 || (float) $j < 0 || (float) $r > 5000 || (float) $j > 5000) { http_response_code(422); return ['error' => 'quantité invalide pour le produit ' . $pid]; }
        $l[(string) $pid] = ['report' => round((float) $r, 1), 'jete' => round((float) $j, 1), 'reste' => is_numeric($re) ? round((float) $re, 1) : null];
    }
    if (count($l) > 3000) { http_response_code(422); return ['error' => 'trop de lignes']; }
    gpEcrire('pfCloture:' . $sid . ':' . $date, ['l' => $l, 'le' => date('c'), 'par' => mb_substr(trim((string) ($b['par'] ?? '')), 0, 80) ?: null]);
    $rep = 0.0; $jet = 0.0; foreach ($l as $x) { $rep += $x['report']; $jet += $x['jete']; }
    return ['ok' => true, 'lignes' => count($l), 'report' => round($rep, 1), 'jete' => round($jet, 1)];
}
