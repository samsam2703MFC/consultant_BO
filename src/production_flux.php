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
const PF_STEPS = [1, 8, 20];      // les steps de production proposés (pièces par fournée) ; un autre nombre reste permis
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
    // J−7 : ajuster la proposition (oui par défaut) ; le « trop peu » relève la production, ou le
    // stock minimum de recuisson (modePeu = stock) ; le stock minimum de recuisson par catégorie.
    $smin = []; foreach ((array) ($s['stockMin'] ?? []) as $k => $q) { if (is_numeric($q) && (int) $q > 0) { $smin[(string) $k] = (int) $q; } }
    return ['jours' => $jours, 'obligatoires' => $ob, 'veille' => $veille, 'garde' => $garde,
        'ajusterJ7' => !array_key_exists('ajusterJ7', $s) || !empty($s['ajusterJ7']), 'modePeu' => ($s['modePeu'] ?? '') === 'stock' ? 'stock' : 'production', 'stockMin' => $smin,
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
    $smin = [];
    foreach ((array) ($p['stockMin'] ?? []) as $k => $q) {
        if (!preg_match('/^(\d{1,9}|n:.{1,80})$/u', (string) $k) || $q === null || $q === '') { continue; }
        if (!is_numeric($q) || (int) $q < 0 || (int) $q > 500) { return [false, 'stock minimum de recuisson invalide pour « ' . $k . ' »', null]; }
        if ((int) $q > 0) { $smin[(string) $k] = (int) $q; }
    }
    $mode = (string) ($p['modePeu'] ?? 'production');
    if (!in_array($mode, ['production', 'stock'], true)) { return [false, 'réponse au « trop peu » inconnue', null]; }
    return [true, null, ['jours' => $jours, 'obligatoires' => $ob, 'veille' => $cle($p['veille'] ?? []), 'garde' => $cle($p['garde'] ?? []),
        'ajusterJ7' => !array_key_exists('ajusterJ7', $p) || !empty($p['ajusterJ7']), 'modePeu' => $mode, 'stockMin' => $smin]];
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
function pfVentesJour(int $sid, string $date, int &$cout, int $budget, ?array $A = null): ?array
{
    $p = function_exists('svProduitsJour') ? svProduitsJour($sid, $date, $cout, $budget) : null;
    if ($p === null) { return null; }
    $f = gpPlier($p);
    $h = $f['q'];
    // Le comptoir : les tickets du jour moins ceux des commandes encaissées ce jour-là.
    if ($A !== null) { foreach (gpCmdTicketsDuJour($A, $date) as $pid => $hs) { foreach ($hs as $hh => $q) { if (isset($h[$pid][$hh])) { $h[$pid][$hh] = max(0.0, $h[$pid][$hh] - $q); } } } }
    $tot = []; foreach ($h as $pid => $hs) { $tot[(int) $pid] = array_sum($hs); }
    // Les commandes retirées ce jour-là : POS et webshop, et heure par heure à l'heure du retrait.
    $cmd = []; $ws = []; $hr = []; $n = ['pos' => 0, 'web' => 0, 'caPos' => 0.0, 'caWeb' => 0.0, 'sansDetail' => 0];
    foreach ($A !== null ? gpCmdRetraits($A, $date) : [] as $o) {
        $web = !empty($o['webshop']);
        $n[$web ? 'web' : 'pos']++; $n[$web ? 'caWeb' : 'caPos'] += (float) $o['montant'];
        if ($o['sansDetail']) { $n['sansDetail']++; }
        $hh = (int) substr((string) $o['heure'], 0, 2);
        foreach ($o['lignes'] as [$pid, $q]) {
            $pid = (int) $pid;
            if ($web) { $ws[$pid] = ($ws[$pid] ?? 0.0) + (float) $q; } else { $cmd[$pid] = ($cmd[$pid] ?? 0.0) + (float) $q; }
            $hr[$pid][$hh] = ($hr[$pid][$hh] ?? 0.0) + (float) $q;
        }
    }
    return ['tot' => $tot, 'h' => $h, 'cmd' => $cmd, 'ws' => $ws, 'hRetraits' => $hr, 'commandes' => $n, 'cmdLues' => $A !== null, 'noms' => $f['noms']];
}

/** Ce qui est sorti de la vitrine heure par heure : le comptoir et les commandes retirées. */
function pfSortiVitrine(?array $V): array
{
    if ($V === null) { return []; }
    $h = $V['h'];
    foreach ($V['hRetraits'] as $pid => $hs) { foreach ($hs as $hh => $q) { $h[$pid][$hh] = ($h[$pid][$hh] ?? 0.0) + $q; } }
    return $h;
}

/** Ce qu'il faut pour planifier un jour : base, paramètres du jour, obligatoires, report, plan. */
function pfCalcul(int $sid, string $date, int &$cout, int $budget, bool $avecCommandes = true, ?array $A = null): array
{
    $dp = gpDayparts();
    $s = setting('gpParams:' . $sid);
    $sem = is_array($s) && isset($s['regles']['semaines']) ? max(1, min(12, (int) $s['regles']['semaines'])) : gpReglesDefaut()['semaines'];
    // Les commandes depuis le plus vieux jour de la base : leurs tickets sortent du comptoir, et
    // celles retirées ce jour-là s'ajoutent au plan (demande du 03/10/2026).
    if ($A === null || ($A['du'] ?? '9') > pfDecale($date, -7 * $sem)) { $A = gpCommandesArticles($sid, pfDecale($date, -7 * $sem), $cout, $budget); }
    $base = gpBase($sid, $date, $sem, $cout, $budget, $A);
    $gp = gpParams($sid, $dp, $base);
    $pf = pfParams($sid, $gp, $date);
    $jour = pfJour($date);
    $jp = pfJourParams($gp, $pf, $jour);
    $oblig = pfObligDuJour($pf, $jour);
    $stock0 = pfReport($sid, pfDecale($date, -1));
    $F = pfFaits($sid, $date);
    $cmds = $avecCommandes ? ($A !== null ? gpCmdRetraits($A, $date) : (gpCommandes($sid, $date) ?? [])) : [];
    $prix = gpPrix($sid);
    $plan = gpPlan($jp['params'], $base, $cmds, $F['faits'], $prix, ['stock0' => $stock0, 'minPct' => $jp['minPct'], 'oblig' => $oblig]);
    // J−7 : trop ou trop peu, et la proposition ajustée en steps entiers.
    // Le plan face au comptoir de J−7 : sans les commandes du jour.
    $T = []; foreach ($plan as $c) { foreach ($c['lignes'] as $l) { $T[(int) $l['pid']] = ($T[(int) $l['pid']] ?? 0) + (int) $l['sortie'] - $l['cmd'] - $l['ws']; } }
    foreach ($T as $k => $v) { $T[$k] = max(0, (int) round($v)); }
    $J7 = pfJ7($sid, $date, $base, $jp['params'], $cout, $budget, $T, $A);
    if ($pf['ajusterJ7'] && $J7['lu']) { $plan = pfAjuster($plan, $J7['par'], $pf['modePeu']); }
    // Le stock minimum de recuisson de chaque produit : celui de sa catégorie, relevé du « trop
    // peu » de J−7 quand c'est la réponse choisie ; il ne vaut que jusqu'à la dernière recuisson.
    $smin = [];
    foreach ($plan as $c) {
        foreach ($c['lignes'] as $l) {
            $pid = (int) $l['pid'];
            if (isset($smin[$pid])) { continue; }
            $q = (int) ($pf['stockMin'][(string) $l['catCle']] ?? 0);
            if ($pf['ajusterJ7'] && $pf['modePeu'] === 'stock') { $q += (int) ($J7['par'][$pid]['plus'] ?? 0); }
            $lim = gpHeure($jp['params']['categories'][(string) $l['catCle']]['limite'] ?? null);
            if ($q > 0) { $smin[$pid] = ['q' => $q, 'limite' => $lim]; }
        }
    }
    return ['sem' => $sem, 'base' => $base, 'gp' => $gp, 'pf' => $pf, 'jour' => $jour, 'jp' => $jp, 'oblig' => $oblig, 'stock0' => $stock0, 'faits' => $F['faits'], 'valid' => $F['valid'], 'cmds' => $cmds, 'prix' => $prix, 'plan' => $plan, 'j7' => $J7, 'smin' => $smin, 'A' => $A];
}

/** Le step de production d'une catégorie (pièces par fournée) : le réglage, 1 — à l'unité — sinon. */
function pfStep(?array $cfg): int { $p = (int) ($cfg['plaque'] ?? 0); return $p > 0 ? $p : 1; }

/**
 * J−7, le même jour la semaine d'avant, produit par produit (demande du 03/10/2026) : y en a-t-il
 * eu trop ou trop peu ? Trop : des pièces jetées (la poubelle déclarée au panel, ou le jeté de la
 * clôture). Trop peu : le produit ne vendait plus à partir d'une heure où les 6 derniers mêmes
 * jours vendent encore au moins une pièce jusqu'à la fermeture — il était sans doute épuisé.
 * La proposition vise le besoin de J−7 (vendu + manqué) : ce qui manque au plan du jour pour
 * l'atteindre, arrondi au step supérieur (plus) ; ce qui le dépasse, au plus la poubelle, arrondi
 * au step inférieur (moins) — moins d'un step ne retire rien.
 */
function pfJ7(int $sid, string $date, array $base, array $params, int &$cout, int $budget, array $T = [], ?array $A = null): array
{
    $j7 = pfDecale($date, -7);
    $v7 = pfVentesJour($sid, $j7, $cout, $budget, $A);
    $jete = null;
    if (function_exists('invLignes')) { $il = invLignes($sid, $j7, $j7); if (is_array($il)) { $jete = []; foreach ($il as $x) { $jete[(int) $x['pid']] = ($jete[(int) $x['pid']] ?? 0.0) + (float) $x['pieces']; } } }
    $cl = setting('pfCloture:' . $sid . ':' . $j7);
    $jeteCl = []; if (is_array($cl) && is_array($cl['l'] ?? null)) { foreach ($cl['l'] as $pid => $x) { $jeteCl[(int) $pid] = (float) ($x['jete'] ?? 0); } }
    $der = []; $fin = null;
    if ($v7 !== null) { foreach ($v7['h'] as $pid => $hs) { $hh = array_keys(array_filter($hs, static fn ($q) => $q > 0)); if ($hh !== []) { $der[(int) $pid] = (int) max($hh); $fin = max($fin ?? 0, (int) max($hh)); } } }
    $par = [];
    foreach ($base['produits'] as $pid => $p) {
        $pid = (int) $pid;
        $step = pfStep($params['categories'][$p['catCle']] ?? null);
        $d = $der[$pid] ?? null;
        $w = max((float) ($jete[$pid] ?? 0), (float) ($jeteCl[$pid] ?? 0));
        $manque = ($d !== null && $fin !== null) ? gpSomme($p['h'], $d + 1, $fin + 1) : 0.0;
        if ($manque < 1) { $manque = 0.0; }
        $peu = $manque > 0; $trop = $w > 0;
        $verdict = $v7 === null ? null : ($d === null ? 'aucune' : ($peu && $trop ? 'mixte' : ($peu ? 'peu' : ($trop ? 'trop' : 'juste'))));
        // Ce qu'il fallait à J−7 : le vendu et ce qui a manqué. Le plan du jour (avant ajustement)
        // n'est relevé que s'il reste en dessous, et ne descend pas sous ce besoin : la poubelle de
        // J−7 ne se retire que de ce qui le dépasse (pas de double compte quand la prévision est
        // déjà plus basse que J−7). Sans plan pour le produit : le manque et la poubelle bruts.
        $vendu = $v7 !== null ? (float) ($v7['tot'][$pid] ?? 0) : 0.0;
        $besoin = $vendu + round($manque);
        $t = $T[$pid] ?? null;
        $plus = !$peu ? 0 : round($t === null ? $manque : max(0.0, $besoin - $t));
        $moins = !$trop ? 0 : ($t === null ? $w : min($w, max(0.0, $t - $besoin)));
        $par[$pid] = ['derniere' => $d, 'poubelle' => ($jete === null && !isset($jeteCl[$pid])) ? null : round($w, 1), 'manque' => round($manque, 1), 'verdict' => $verdict, 'step' => $step,
            'vendu' => round($vendu, 1), 'besoin' => round($besoin, 1), 'plan' => $t,
            'plus' => $plus > 0 ? (int) (ceil($plus / $step - 1e-9) * $step) : 0, 'moins' => $moins > 0 ? (int) (floor($moins / $step + 1e-9) * $step) : 0];
    }
    return ['date' => $j7, 'lu' => $v7 !== null, 'v7' => $v7, 'fin' => $fin, 'poubelleLue' => $jete !== null, 'poubelle' => $jete === null ? null : round(array_sum($jete), 1), 'par' => $par];
}

/**
 * La proposition ajustée sur J−7 : « trop peu » ajoute ses steps à la cuisson qui couvre l'heure
 * où le produit manquait (la dernière à défaut) — sauf si la réponse choisie est le stock minimum
 * de recuisson ; « trop » retire ses steps en partant de la dernière cuisson (un obligatoire garde
 * au moins un step en 1re cuisson). Plaques, CA et totaux recalculés.
 */
function pfAjuster(array $plan, array $par, string $mode): array
{
    $ix = [];
    foreach ($plan as $ci => $c) { foreach ($c['lignes'] as $li => $l) { $ix[(int) $l['pid']][] = [$ci, $li]; } }
    foreach ($par as $pid => $j) {
        if (!isset($ix[$pid])) { continue; }
        $delta = ($mode === 'stock' ? 0 : (int) $j['plus']) - (int) $j['moins'];
        if ($delta === 0) { continue; }
        $L = $ix[$pid];
        if ($delta > 0) {
            $t = (float) (($j['derniere'] ?? 0) + 1); $cible = $L[count($L) - 1];
            foreach ($L as [$ci, $li]) { $z = $plan[$ci]['lignes'][$li]['zone']; $a = gpHeure($z[0]) ?? 0.0; $b = gpHeure($z[1]) ?? 24.0; if ($t >= $a && $t < $b) { $cible = [$ci, $li]; } }
            [$ci, $li] = $cible;
            $plan[$ci]['lignes'][$li]['sortie'] += $delta;
            $plan[$ci]['lignes'][$li]['ajustJ7'] = ($plan[$ci]['lignes'][$li]['ajustJ7'] ?? 0) + $delta;
        } else {
            $reste = -$delta; $n = count($L);
            for ($k = $n - 1; $k >= 0 && $reste > 0; $k--) {
                [$ci, $li] = $L[$k];
                $l = $plan[$ci]['lignes'][$li];
                $garde = (!empty($l['oblig']) && $k === 0) ? min((int) $l['sortie'], (int) $j['step']) : 0;
                $pris = min($reste, max(0, (int) $l['sortie'] - $garde));
                if ($pris > 0) { $plan[$ci]['lignes'][$li]['sortie'] -= $pris; $plan[$ci]['lignes'][$li]['ajustJ7'] = ($l['ajustJ7'] ?? 0) - $pris; $reste -= $pris; }
            }
        }
    }
    foreach ($plan as $ci => $c) {
        $t = ['pieces' => 0, 'plaques' => 0, 'ca' => 0.0, 'sansPrix' => 0];
        foreach ($c['lignes'] as $li => $l) {
            if (!empty($l['plaque'])) { $l['plaques'] = (int) round($l['sortie'] / $l['plaque']); }
            $l['ca'] = $l['prix'] !== null ? round((float) $l['prix'] * $l['sortie'], 2) : null;
            $t['pieces'] += $l['sortie']; $t['plaques'] += (int) $l['plaques'];
            if ($l['ca'] !== null) { $t['ca'] += $l['ca']; } elseif ($l['sortie'] > 0) { $t['sansPrix']++; }
            $plan[$ci]['lignes'][$li] = $l;
        }
        $plan[$ci]['total'] = array_merge($c['total'], ['pieces' => $t['pieces'], 'plaques' => $t['plaques'], 'ca' => round($t['ca'], 2), 'sansPrix' => $t['sansPrix']]);
    }
    return $plan;
}

/** Les lignes du plan regroupées par produit : une ligne par produit, une colonne par cuisson. */
function pfParProduit(array $plan): array
{
    $out = [];
    foreach ($plan as $c) {
        foreach ($c['lignes'] as $l) {
            $pid = (int) $l['pid'];
            $out[$pid] ??= ['pid' => $pid, 'nom' => $l['nom'], 'groupe' => $l['groupe'], 'cat' => $l['cat'], 'catCle' => $l['catCle'], 'prix' => $l['prix'], 'prevJ' => $l['prevJ'], 'oblig' => !empty($l['oblig']), 'h' => $l['h'], 'c' => []];
            $out[$pid]['c'][$c['id']] = ['sortie' => $l['sortie'], 'plaques' => $l['plaques'], 'plaque' => $l['plaque'], 'stock' => $l['stock'], 'prevu' => $l['prevu'], 'fait' => $l['fait'], 'zone' => $l['zone'], 'ajustJ7' => (int) ($l['ajustJ7'] ?? 0), 'cmd' => (float) ($l['cmd'] ?? 0), 'ws' => (float) ($l['ws'] ?? 0)];
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
            'veille' => in_array((string) $k, $pf['veille'], true), 'garde' => in_array((string) $k, $pf['garde'], true), 'stockMin' => (int) ($pf['stockMin'][(string) $k] ?? 0)];
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
        'flux' => ['jours' => $pf['jours'], 'veille' => $pf['veille'], 'garde' => $pf['garde'], 'ajusterJ7' => $pf['ajusterJ7'], 'modePeu' => $pf['modePeu'], 'enregistre' => $pf['enregistre'], 'maj' => $pf['maj'], 'par' => $pf['par']],
        'steps' => PF_STEPS,
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
    $J7 = $K['j7']; $j7 = $J7['date']; $v7 = $J7['v7'];
    $n7 = $v7['commandes'] ?? ['pos' => 0, 'web' => 0, 'caPos' => 0.0, 'caWeb' => 0.0, 'sansDetail' => 0];
    $cmdLues = $v7 !== null && !empty($v7['cmdLues']);
    // Les commandes retirées le jour planifié : POS et webshop, ce qui en est connu.
    $nJ = ['pos' => 0, 'web' => 0, 'ca' => 0.0, 'sansDetail' => 0, 'pieces' => 0.0];
    foreach ($K['cmds'] as $o) { $nJ[!empty($o['webshop']) ? 'web' : 'pos']++; $nJ['ca'] += (float) $o['montant']; if ($o['sansDetail']) { $nJ['sansDetail']++; } foreach ($o['lignes'] as [, $q]) { $nJ['pieces'] += (float) $q; } }
    // Le lendemain : sa 1re cuisson, pour les catégories préparées la veille.
    $d1 = pfDecale($date, 1);
    $K1 = null; $veille = [];
    if ($K['pf']['veille'] !== []) {
        $K1 = pfCalcul($sid, $d1, $cout, $budget, false, $K['A']);
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
        $mag = $v7 !== null ? (float) ($v7['tot'][$pid] ?? 0) : null;
        $cmdJ = 0.0; $wsJ = 0.0; foreach ($l['c'] as $x) { $cmdJ += (float) ($x['cmd'] ?? 0); $wsJ += (float) ($x['ws'] ?? 0); }
        $lignes[] = ['pid' => $pid, 'nom' => $l['nom'], 'groupe' => $l['groupe'] !== '' ? $l['groupe'] : $l['cat'], 'cat' => $l['cat'], 'catCle' => $l['catCle'], 'oblig' => $l['oblig'], 'prix' => $l['prix'],
            'commandesJour' => ['pos' => round($cmdJ, 1), 'webshop' => round($wsJ, 1)],
            'j7' => ['magasin' => $mag !== null ? round(max(0.0, $mag), 1) : null, 'webshop' => $cmdLues ? round((float) ($v7['ws'][$pid] ?? 0), 1) : null, 'commandes' => $cmdLues ? round((float) ($v7['cmd'][$pid] ?? 0), 1) : null,
                'derniere' => $J7['par'][$pid]['derniere'] ?? null, 'poubelle' => $J7['par'][$pid]['poubelle'] ?? ($J7['poubelleLue'] ? 0.0 : null),
                'verdict' => $J7['par'][$pid]['verdict'] ?? null, 'manque' => $J7['par'][$pid]['manque'] ?? 0, 'plus' => $J7['par'][$pid]['plus'] ?? 0, 'moins' => $J7['par'][$pid]['moins'] ?? 0,
                'vendu' => $J7['par'][$pid]['vendu'] ?? null, 'besoin' => $J7['par'][$pid]['besoin'] ?? null, 'plan' => $J7['par'][$pid]['plan'] ?? null],
            'step' => $J7['par'][$pid]['step'] ?? pfStep($K['jp']['params']['categories'][(string) $l['catCle']] ?? null),
            'ajustJ7' => array_sum(array_map(static fn ($x) => (int) ($x['ajustJ7'] ?? 0), (array) $l['c'])),
            'stockMin' => $K['smin'][$pid]['q'] ?? 0,
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
        'j7' => ['date' => $j7, 'lu' => $v7 !== null, 'webshop' => ['n' => $n7['web'], 'ca' => round($n7['caWeb'], 2)], 'commandes' => ['n' => $n7['pos'], 'ca' => round($n7['caPos'], 2)], 'commandesLues' => $cmdLues, 'commandesSansDetail' => $n7['sansDetail'],
            'derniereVente' => $J7['fin'], 'poubelleLue' => $J7['poubelleLue'], 'poubelle' => $J7['poubelle'],
            'ajuster' => $K['pf']['ajusterJ7'], 'modePeu' => $K['pf']['modePeu']],
        'lendemain' => ['date' => $d1, 'jourNom' => PF_JOURS[pfJour($d1)], 'categories' => count($K['pf']['veille']), 'complet' => $K1 === null || $K1['base']['manquants'] === [], 'lus' => $K1 !== null ? count($K1['base']['lus']) : 0, 'jours' => $K1 !== null ? count($K1['base']['jours']) : 0],
        'base' => ['semaines' => $K['sem'], 'jours' => count($K['base']['jours']), 'lus' => count($K['base']['lus']), 'manquants' => $K['base']['manquants'], 'commandes' => $K['base']['commandes'] ?? null],
        'commandes' => ['lues' => $K['A'] !== null, 'complet' => $K['A'] !== null && !$K['A']['incomplet'], 'pos' => $nJ['pos'], 'webshop' => $nJ['web'], 'ca' => round($nJ['ca'], 2), 'sansDetail' => $nJ['sansDetail'], 'pieces' => round($nJ['pieces'], 1)],
        'source' => 'prévision : ventes comptoir du panel (tickets moins ceux des commandes), moyenne des ' . $K['sem'] . ' derniers ' . PF_JOURS[$K['jour']] . 's heure par heure, + les commandes POS et webshop du jour (articles de leur ticket) · J−7 : tickets du ' . $j7];
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
    $V = pfVentesJour($sid, $date, $cout, $budget, $K['A']);
    $jete = [];
    if (function_exists('invLignes')) { foreach ((array) invLignes($sid, $date, $date) as $l) { $jete[(int) $l['pid']] = ($jete[(int) $l['pid']] ?? 0.0) + (float) $l['pieces']; } }
    $R = (float) $K['gp']['regles']['securite'];
    $suivi = pfSuivi($K['plan'], $K['stock0'], pfSortiVitrine($V), $now, $K['smin']);
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
function pfSuivi(array $plan, array $stock0, array $vendu, float $now, array $smin = []): array
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
        // Le stock minimum de recuisson : la vitrine ne doit pas passer dessous avant la dernière
        // recuisson de la catégorie (sinon la dernière heure) — en dessous, c'est un manque.
        $sm = (float) ($smin[$pid]['q'] ?? 0); $smJusqua = $smin[$pid]['limite'] ?? null; if ($smJusqua === null) { $smJusqua = (float) $h1; }
        $defMax = 0.0;
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
                $seuil = (float) $h < $smJusqua ? $sm : 0.0;
                if ($manque === null && $proj < $seuil - 0.5) { $manque = ['h' => $h, 'q' => round($seuil - $proj, 1)]; }
                $defMax = max($defMax, $seuil - $proj);
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
            $def = max($manque['q'] ?? 0.0, $defMax, -$finJour, $verdict === 'rupture' ? $resteJ - max(0.0, $stockNow) : 0.0);
            $pl = null; foreach ($p['c'] as $x) { if (!empty($x['plaque'])) { $pl = (int) $x['plaque']; } }
            $n = max(1, (int) ceil($def - 1e-6));
            $conseil = ['pieces' => $n, 'plaques' => $pl ? (int) ceil($n / $pl) : null, 'plaque' => $pl];
        }
        $T['sorti'] += $sortiTot; $T['vendu'] += $venduTot; $T['stock'] += max(0.0, $stockNow); $T['finJour'] += max(0.0, $finJour); $T['report'] += $s0;
        if ($verdict === 'manque') { $T['manques']++; } elseif ($verdict === 'rupture') { $T['ruptures']++; } elseif ($verdict === 'trop') { $T['trop']++; }
        $out[] = ['pid' => $pid, 'nom' => $p['nom'], 'groupe' => $p['groupe'], 'cat' => $p['cat'], 'catCle' => $p['catCle'], 'oblig' => $p['oblig'], 'prix' => $p['prix'], 'stockMin' => $sm,
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
    $K = pfCalcul($sid, $date, $cout, $budget);
    $V = pfVentesJour($sid, $date, $cout, $budget, $K['A']);
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
        $vd = $V !== null ? (float) ($V['tot'][$pid] ?? 0) + (float) ($V['cmd'][$pid] ?? 0) + (float) ($V['ws'][$pid] ?? 0) : 0.0;
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

/**
 * GET /production/flux/sonde?shop=4&date=YYYY-MM-DD — lecture seule, des comptes et des noms de
 * champs, jamais un client : les commandes du jour (canal, encaissée ou non, articles joints ou
 * non), les routes du panel qui pourraient joindre les articles d'une commande à venir, et la
 * part des tickets pro (B2B) qui sont des commandes encaissées.
 */
function ep_production_flux_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 4);
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $cles = static fn ($b) => is_array($b) ? array_values(array_filter(array_keys($b), 'is_string')) : null;
    $articles = static function ($b) {
        if (!is_array($b)) { return null; }
        $o = [];
        foreach ($b as $k => $v) { if (is_array($v) && array_is_list($v) && $v !== [] && is_array($v[0])) { $o[(string) $k] = ['n' => count($v), 'champs' => array_slice(array_keys($v[0]), 0, 30)]; } }
        if (array_is_list($b) && $b !== [] && is_array($b[0])) { $o['(liste)'] = ['n' => count($b), 'champs' => array_slice(array_keys($b[0]), 0, 30)]; }
        return $o;
    };
    $r = PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $date, 25);
    $L = analyseListe(is_array($r['corps'] ?? null) ? $r['corps'] : []);
    $out = ['shop' => $sid, 'date' => $date, 'listeCode' => $r['code'] ?? null, 'champsCommande' => $L !== [] && is_array($L[0]) ? array_keys($L[0]) : [], 'commandes' => [], 'routes' => [],
        'liste' => ['n' => count($L), 'enveloppe' => is_array($r['corps'] ?? null) && !array_is_list($r['corps']) ? array_keys($r['corps']) : 'liste',
            'meta' => is_array($r['corps'] ?? null) && !array_is_list($r['corps']) ? array_map(static fn ($v) => is_array($v) ? array_slice($v, 0, 8, true) : $v, array_diff_key($r['corps'], ['data' => 1, 'items' => 1])) : null,
            'retraitMin' => $L !== [] ? min(array_map(static fn ($o) => substr((string) ($o['pick_up_datetime'] ?? '9'), 0, 10), $L)) : null,
            'retraitMax' => $L !== [] ? max(array_map(static fn ($o) => substr((string) ($o['pick_up_datetime'] ?? ''), 0, 10), $L)) : null,
            'avecTicket' => count(array_filter($L, static fn ($o) => (int) ($o['id_transaction'] ?? 0) > 0))]];
    if (!empty($_GET['liste'])) { return ['shop' => $sid, 'date' => $date, 'liste' => $out['liste']]; }
    $ids = []; $tick = [];
    foreach ($L as $o) {
        if (!is_array($o) || substr((string) ($o['pick_up_datetime'] ?? ''), 0, 10) !== $date) { continue; }
        $canal = coCanal($o);
        $t = (int) ($o['id_transaction'] ?? 0);
        $paye = []; foreach ($o as $k => $v) { if (is_string($k) && preg_match('/paid|payment|prepa|online/i', $k) && !is_array($v)) { $paye[$k] = $v; } }
        $out['commandes'][] = ['canal' => $canal, 'statut' => coStatut($o, $canal), 'heure' => substr((string) $o['pick_up_datetime'], 11, 5), 'montant' => round((float) ($o['total_value'] ?? 0), 2),
            'ticket' => $t > 0, 'articlesJoints' => count((array) ($o['products'] ?? [])), 'paiement' => $paye];
        if (count($ids) < 2 && (int) ($o['id'] ?? 0) > 0) { $ids[] = (int) $o['id']; }
        if ($t > 0) { $tick[$t] = $canal; }
    }
    foreach ($ids as $i => $id) {
        foreach (['/client-order/' . $id, '/client-orders/' . $id, '/client-order/' . $id . '/pickup-transaction', '/client-orders/' . $id . '/products'] as $p) {
            $x = PanelApi::sondeGet($p, 10);
            $out['routes'][] = ['commande' => $i + 1, 'route' => preg_replace('#/\d+#', '/{id}', $p), 'code' => $x['code'] ?? null, 'champs' => $cles($x['corps'] ?? null), 'listes' => $articles($x['corps'] ?? null)];
        }
    }
    foreach (['/franchisee-shop/' . $sid . '/client-order/' . $date, '/franchise/1/client-order/unpicked'] as $p) {
        $x = PanelApi::sondeGet($p, 10);
        $out['routes'][] = ['route' => $p, 'code' => $x['code'] ?? null, 'champs' => $cles($x['corps'] ?? null), 'listes' => $articles($x['corps'] ?? null)];
    }
    // Les tickets du jour : combien de pro, combien sont des commandes encaissées, et les champs
    // d'un ticket qui parlent de commande ou de pro.
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $date);
    $T = ['tickets' => 0, 'pro' => 0, 'commandes' => 0, 'commandesPro' => 0, 'commandesParCanal' => [], 'champsLies' => []];
    foreach (analyseListe(is_array($liste) ? $liste : []) as $t) {
        if (!is_array($t)) { continue; }
        $T['tickets']++;
        $pro = !empty($t['is_client_b2b']);
        if ($pro) { $T['pro']++; }
        if ($T['champsLies'] === []) { $T['champsLies'] = array_values(array_filter(array_keys($t), static fn ($k) => is_string($k) && preg_match('/order|b2b|webshop|pick|source|channel|type/i', $k))); }
        $id = (int) ($t['id'] ?? 0);
        if (isset($tick[$id])) { $T['commandes']++; $T['commandesParCanal'][$tick[$id]] = ($T['commandesParCanal'][$tick[$id]] ?? 0) + 1; if ($pro) { $T['commandesPro']++; } }
    }
    $T['commandesEncaissees'] = count($tick);
    // Les sources des tickets et la référence de commande : des comptes par valeur de source,
    // jamais la référence elle-même.
    $T['sources'] = []; $T['avecRefCommande'] = 0; $T['refCommandePro'] = 0; $T['refCommandeEncaissee'] = 0; $T['sourceDesRefs'] = [];
    $vus = [];
    foreach (analyseListe(is_array($liste) ? $liste : []) as $t) {
        if (!is_array($t)) { continue; }
        $src = (string) ($t['source'] ?? '');
        $T['sources'][$src] = ($T['sources'][$src] ?? 0) + 1;
        $ref = trim((string) ($t['order_ref'] ?? ''));
        $vus[(int) ($t['id'] ?? 0)] = true;
        if ($ref !== '' && $ref !== '0') { $T['avecRefCommande']++; $T['sourceDesRefs'][$src] = ($T['sourceDesRefs'][$src] ?? 0) + 1; if (!empty($t['is_client_b2b'])) { $T['refCommandePro']++; } if (isset($tick[(int) $t['id']])) { $T['refCommandeEncaissee']++; } }
    }
    // Les commandes encaissées dont le ticket n'est pas de ce jour : le jour du ticket.
    $T['ticketsAilleurs'] = [];
    foreach ($tick as $id => $canal) {
        if (isset($vus[$id])) { continue; }
        $x = PanelApi::sondeGet('/transactions/' . $id, 10); $b = is_array($x['corps'] ?? null) ? $x['corps'] : [];
        $T['ticketsAilleurs'][] = ['canal' => $canal, 'code' => $x['code'] ?? null, 'jour' => substr((string) ($b['insert_timestamp'] ?? ''), 0, 10), 'heure' => substr((string) ($b['insert_timestamp'] ?? ''), 11, 2), 'pro' => !empty($b['is_client_b2b']), 'source' => $b['source'] ?? null, 'refCommande' => trim((string) ($b['order_ref'] ?? '')) !== ''];
    }
    $out['tickets'] = $T;
    return $out;
}
