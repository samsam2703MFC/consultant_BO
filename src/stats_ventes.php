<?php

declare(strict_types=1);

/**
 * Statistiques de vente par heure — le cœur du dashboard magasin.
 *
 * Deux sources du panel, mesurées par la sonde ci-dessous (ep_stats_ventes_sonde) :
 *  · /shops/{id}/statistics/sales/hourly-distribution/{date} — heure par
 *    heure : tickets (transactions_qty), ventes (income), coût matière
 *    (material_cost), coût du personnel (employee_cost), personnes en poste
 *    (employee_qty), marge (total_margin). C'est la cascade de l'heure :
 *    ventes − matière = marge brute ; marge brute − travail = résultat.
 *  · /shops/{id}/transactions?date= puis /transactions/{id}?include=products —
 *    l'heure du ticket (insert_timestamp, heure locale) et ses lignes :
 *    id_product, product_name, quantity, total_gross_value_after_discount.
 *    Le coût matière d'une ligne vient des recettes (catalogueCouts) : la
 *    marge d'un produit à une heure = ventes − quantité × coût recette.
 *
 * Un jour clos se lit une fois et se grave (ceo_app_setting svH…/svP…) ; la
 * journée en cours se relit toutes les dix minutes. Les tickets se
 * moissonnent par lots bornés (budget), à la demande puis au cron horaire.
 */

const SV_DEBUT = '2026-08-01';        // premier jour moissonné pour les produits
const SV_TTL_JOUR = 600;              // la journée en cours : dix minutes
const SV_BUDGET_DEMANDE = 500;        // tickets lus au plus dans une requête
const SV_TEMPS_DEMANDE = 35;          // secondes de lecture de tickets au plus par requête
const SV_BUDGET_CRON = 900;           // tickets lus au plus par battement du cron
const SV_TOP = 5;
const SV_TOP_CATS = 3;

/** GET /ventes/stats/sonde?shop=2&date=2026-09-06 — les routes, telles qu'elles répondent. */
function ep_stats_ventes_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 2);
    $date = (string) ($_GET['date'] ?? date('Y-m-d', strtotime('-1 day')));
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $chemins = [
        'hourly' => '/shops/' . $sid . '/statistics/sales/hourly-distribution/' . $date,
        'heat'   => '/consultant/shops/' . $sid . '/margin-heatmap?from=' . $date . '&to=' . $date,
        'trans'  => '/shops/' . $sid . '/transactions?date=' . $date,
        'sched'  => '/shops/' . $sid . '/schedule?date=' . $date,
        'kpis'   => '/shops/' . $sid . '/statistics/sales/kpis?date_from=' . $date . '&date_to=' . $date,
        'daily'  => '/shops/' . $sid . '/statistics/daily-summary?date=' . $date,
        'notif'  => '/shops/' . $sid . '/notifications',
        'notif2' => '/shops/' . $sid . '/notifications?date=' . $date,
    ];
    $res = PanelApi::getParallele($chemins, 6);
    $coupe = static function ($v, int $n = 3) {
        if (!is_array($v)) { return $v; }
        $l = analyseListe($v);
        if ($l !== []) { return ['n' => count($l), 'cles' => array_keys((array) $l[0]), 'exemples' => array_slice($l, 0, $n)]; }
        return ['cles' => array_keys($v), 'valeur' => array_map(static fn ($x) => is_array($x) ? ['n' => count($x), 'premier' => array_slice($x, 0, 2)] : $x, $v)];
    };
    $out = ['shop' => $sid, 'date' => $date];
    foreach ($chemins as $k => $p) { $out[$k] = ['route' => $p, 'reponse' => $coupe($res[$k] ?? null)]; }
    $lt = analyseListe($res['trans'] ?? null);
    if ($lt !== []) {
        $id = (int) ($lt[0]['id'] ?? 0);
        $t = $id > 0 ? PanelApi::get('/transactions/' . $id . '?include=products') : null;
        $out['ticket'] = ['route' => '/transactions/' . $id . '?include=products',
            'cles' => is_array($t) ? array_keys($t) : null,
            'sansProduits' => is_array($t) ? array_filter($t, static fn ($v) => !is_array($v)) : $t,
            'produit0' => is_array($t) && isset($t['products'][0]) ? $t['products'][0] : null];
    }
    return $out;
}

/**
 * GET /ventes/notifications?shop=4 — les messages du panel pour un magasin,
 * tels que la route /shops/{id}/notifications les rend (titre, message,
 * priorité, type, statut, visibilité, date, action). Les messages publiés et
 * visibles aujourd'hui, du plus récent au plus ancien.
 */
function ep_stats_notifications(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!PanelApi::configured()) { return ['shop' => $sid, 'messages' => [], 'indispo' => true, 'motif' => 'compte panel non configuré']; }
    $r = PanelApi::get('/shops/' . $sid . '/notifications');
    if (!is_array($r)) { return ['shop' => $sid, 'messages' => [], 'indispo' => true, 'motif' => 'le panel n’a pas répondu']; }
    $auj = date('Y-m-d');
    $out = [];
    foreach (analyseListe($r) as $n) {
        if ((string) ($n['status'] ?? 'published') !== 'published') { continue; }
        $du = isset($n['visible_from']) && $n['visible_from'] ? substr((string) $n['visible_from'], 0, 10) : null;
        $au = isset($n['visible_to']) && $n['visible_to'] ? substr((string) $n['visible_to'], 0, 10) : null;
        if (($du !== null && $du > $auj) || ($au !== null && $au < $auj)) { continue; }
        $pri = strtolower((string) ($n['priority'] ?? 'info'));
        $out[] = ['id' => (int) ($n['id'] ?? 0), 'titre' => trim((string) ($n['title'] ?? '')), 'message' => trim((string) ($n['message'] ?? '')),
            'priorite' => in_array($pri, ['urgent', 'high', 'critical'], true) ? 'urgent' : (in_array($pri, ['warning', 'attention', 'medium'], true) ? 'attention' : 'info'),
            'type' => (string) ($n['type'] ?? 'once'), 'jour' => $n['day_of_week'] ?? null, 'du' => $du, 'au' => $au,
            'quand' => (string) ($n['created_at'] ?? ''), 'global' => !empty($n['is_global']),
            'source' => (string) ($n['source_type'] ?? ''), 'action' => (string) ($n['action_url'] ?? ''), 'actionLib' => (string) ($n['action_label'] ?? '')];
    }
    usort($out, static fn ($a, $b) => strcmp($b['quand'], $a['quand']));
    $cfgBase = Db::config()['pwaBase'] ?? null;
    $base = rtrim((string) ($cfgBase ?: setting('pwaBase', '')), '/');
    return ['shop' => $sid, 'messages' => $out, 'panel' => $base, 'quand' => date('c')];
}

/**
 * GET /ventes/record?shop=4&date=2026-09-13 — le record du magasin POUR CE
 * JOUR DE SEMAINE avant la date (le meilleur dimanche à battre, le meilleur
 * lundi…), sur trois ans au plus, relu dans le margin-heatmap du panel par
 * fenêtres de 31 jours (six en parallèle) jusqu'à trois mois sans vente.
 * Le résultat est gravé pour la journée : le passé ne bouge pas, et l'on
 * ne relit pas trois ans de ventes à chaque rendu. Le front compare le CA
 * du jour au record de son jour de semaine.
 */
function ep_stats_record(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); return ['error' => 'shop ou date manquant']; }
    $cle = 'svRecord|' . $sid . '|' . $date;
    if (empty($_GET['force'])) {
        $v = setting($cle);
        if (is_string($v)) { $v = json_decode($v, true); }
        if (is_array($v) && isset($v['parJour'])) { return $v + ['cache' => true]; }
    }
    $jd = new DateTimeImmutable($date);
    $wd = (int) $jd->format('N');
    $noms = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $vide = ['shop' => $sid, 'date' => $date, 'jourSemaine' => $wd, 'nom' => $noms[$wd], 'meilleur' => null, 'parJour' => [], 'jours' => 0];
    if (!PanelApi::configured()) { return $vide + ['indispo' => true, 'motif' => 'compte panel non configuré']; }
    @set_time_limit(150);
    $au = $jd->modify('-1 day');
    $limite = $jd->modify('-3 years');
    $parJour = []; $jours = 0; $fenetres = 0; $videsSuite = 0; $depuis = null;
    while ($fenetres < 37 && $videsSuite < 3 && $au >= $limite) {
        $paths = []; $cur = $au;
        for ($k = 0; $k < 6 && $fenetres + $k < 37 && $cur >= $limite; $k++) {
            $du = $cur->modify('-30 days');
            $paths['w' . $k] = '/consultant/shops/' . $sid . '/margin-heatmap?from=' . $du->format('Y-m-d') . '&to=' . $cur->format('Y-m-d');
            $cur = $du->modify('-1 day');
        }
        if (!$paths) { break; }
        $res = PanelApi::getParallele($paths, 6);
        foreach (array_keys($paths) as $k) {
            $fenetres++;
            $r = $res[$k] ?? null; $n = 0;
            foreach ((array) (is_array($r) ? ($r['days'] ?? []) : []) as $d) {
                $ca = (float) ($d['ca'] ?? 0);
                $dt = (string) ($d['date'] ?? '');
                if (empty($d['has_data']) || $ca <= 0 || $dt === '' || $dt >= $date) { continue; }
                $n++; $jours++;
                if ($depuis === null || $dt < $depuis) { $depuis = $dt; }
                $w = (int) (new DateTimeImmutable($dt))->format('N');
                $m = $parJour[$w] ?? null;
                if ($m === null || $ca > $m['ca']) {
                    $parJour[$w] = ['date' => $dt, 'ca' => round($ca, 2), 'margeBrute' => isset($d['margin_value']) ? round((float) $d['margin_value'], 2) : null];
                }
            }
            $videsSuite = $n === 0 ? $videsSuite + 1 : 0;
        }
        $au = $cur;
    }
    ksort($parJour);
    $out = ['shop' => $sid, 'date' => $date, 'jourSemaine' => $wd, 'nom' => $noms[$wd], 'meilleur' => $parJour[$wd] ?? null,
        'parJour' => $parJour, 'jours' => $jours, 'depuis' => $depuis, 'fenetres' => $fenetres, 'quand' => date('c')];
    if ($jours > 0) { try { svGrave($cle, $out); } catch (Throwable $e) { /* sans cache */ } }
    return $out;
}

/** Grave une valeur dans ceo_app_setting. */
function svGrave(string $cle, array $v): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

/** Une ligne d'heure normalisée depuis hourly-distribution. */
function svLigneHeure(array $r): ?array
{
    $h = (int) substr((string) ($r['hour_from'] ?? ''), 0, 2);
    if ($h < 0 || $h > 23) { return null; }
    $ca = round((float) ($r['income'] ?? 0), 2);
    $mat = round((float) ($r['material_cost'] ?? 0), 2);
    $trav = round((float) ($r['employee_cost'] ?? 0), 2);
    return ['h' => $h, 'tickets' => (int) ($r['transactions_qty'] ?? 0), 'ca' => $ca, 'mat' => $mat,
        'trav' => $trav, 'poste' => (int) ($r['employee_qty'] ?? 0),
        'marge' => isset($r['total_margin']) ? round((float) $r['total_margin'], 2) : round($ca - $mat - $trav, 2)];
}

/**
 * Les heures de plusieurs jours d'un magasin — [date => [h => ligne]] ; un jour
 * muet est absent. Les jours clos gravés ne se relisent pas.
 */
function svHeuresJours(int $sid, array $jours): array
{
    $auj = date('Y-m-d');
    $out = []; $chemins = [];
    foreach ($jours as $j) {
        $c = setting('svH' . $sid . ':' . $j);
        if (is_array($c) && isset($c['h']) && ($j < $auj || (int) ($c['quand'] ?? 0) > time() - SV_TTL_JOUR)) { $out[$j] = $c['h']; continue; }
        $chemins[$j] = '/shops/' . $sid . '/statistics/sales/hourly-distribution/' . $j;
    }
    if ($chemins !== []) {
        foreach (PanelApi::getParallele($chemins, 6) as $j => $r) {
            if (!is_array($r)) { continue; }
            $hs = [];
            foreach (analyseListe($r) as $x) { $l = svLigneHeure((array) $x); if ($l !== null) { $hs[(string) $l['h']] = $l; } }
            $out[$j] = $hs;
            svGrave('svH' . $sid . ':' . $j, ['quand' => time(), 'h' => $hs]);
        }
    }
    return $out;
}

/**
 * Les produits d'un jour, heure par heure — {h: {pid: [nom, q, ventes, coût|null]}}.
 * Lit les tickets du jour si le jour n'est pas gravé ; null si le budget est
 * épuisé ou le panel muet (un jour à moitié lu ne se grave pas).
 */
function svProduitsJour(int $sid, string $j, int &$cout, int $budget): ?array
{
    $auj = date('Y-m-d');
    $cle = 'svP' . $sid . ':' . $j;
    $c = setting($cle);
    if (is_array($c) && isset($c['p']) && ($j < $auj || (int) ($c['quand'] ?? 0) > time() - SV_TTL_JOUR)) { return $c['p']; }
    if ($cout >= $budget) { return null; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    if (!is_array($liste)) { return null; }
    $ids = [];
    foreach (analyseListe($liste) as $t) { if ((int) ($t['id'] ?? 0) > 0) { $ids[] = (int) $t['id']; } }
    if ($cout + count($ids) > $budget && $cout > 0) { return null; }   // ce jour attendra le prochain lot
    $couts = catalogueCouts();
    $p = [];
    foreach (array_chunk($ids, 40) as $lot) {
        $chemins = [];
        foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
        $res = PanelApi::getParallele($chemins, 8);
        foreach ($lot as $id) {
            $t = $res[$id] ?? null;
            if (!is_array($t)) { return null; }
            $h = (string) (int) substr((string) ($t['insert_timestamp'] ?? '00'), 11, 2);
            foreach ((array) ($t['products'] ?? []) as $l) {
                $pid = (int) ($l['id_product'] ?? 0);
                if ($pid <= 0) { continue; }
                $q = (float) ($l['quantity'] ?? 0);
                $v = (float) ($l['total_gross_value_after_discount'] ?? 0);
                $cu = isset($couts[$pid]['mat']) ? (float) $couts[$pid]['mat'] : null;
                if (!isset($p[$h][$pid])) { $p[$h][$pid] = [trim((string) ($l['product_name'] ?? ('Produit ' . $pid))), 0.0, 0.0, $cu === null ? null : 0.0]; }
                $p[$h][$pid][1] += $q;
                $p[$h][$pid][2] += $v;
                if ($cu !== null && $p[$h][$pid][3] !== null) { $p[$h][$pid][3] += $q * $cu; }
            }
        }
    }
    $cout += count($ids);
    foreach ($p as $h => $lst) { foreach ($lst as $pid => $x) { $p[$h][$pid] = [$x[0], round($x[1], 3), round($x[2], 2), $x[3] === null ? null : round($x[3], 2)]; } }
    svGrave($cle, ['quand' => time(), 'n' => count($ids), 'p' => $p]);
    return $p;
}

/** La catégorie de chaque produit du panel — [pid => nom de catégorie], lue une fois. */
function svCategories(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    $cats = function_exists('catalogueCategories') ? (catalogueCategories() ?? []) : [];
    try {
        foreach (Db::rows('SELECT id, id_category FROM product') as $r) {
            $c = $cats[(int) ($r['id_category'] ?? 0)] ?? null;
            if ($c !== null) { $cache[(int) $r['id']] = (string) $c['nom']; }
        }
    } catch (PDOException $e) { /* sans catégories : le top reste par produit */ }
    return $cache;
}

/** Les jours d'une vue : jour, semaine (lundi → aujourd'hui), mois (1er → aujourd'hui). */
function svJours(string $vue, string $date): array
{
    $auj = date('Y-m-d');
    if ($vue === 'jour') { return [$date, $date, [$date]]; }
    $ts = strtotime($date);
    if ($vue === 'semaine') { $du = date('Y-m-d', strtotime('monday this week', $ts)); $au = date('Y-m-d', strtotime($du . ' +6 days')); }
    else { $du = date('Y-m-01', $ts); $au = date('Y-m-t', $ts); }
    $fin = min($au, $auj);
    $jours = [];
    for ($j = $du; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { $jours[] = $j; }
    return [$du, $au, $jours];
}

/**
 * GET /ventes/stats?shop=4&vue=jour|semaine|mois&date=YYYY-MM-DD
 *
 * Les heures de la période (somme et moyenne par jour ouvert), la cascade de
 * chaque heure, le top 5 des produits par marge de chaque heure, et la
 * couverture : combien de jours portent leurs heures, combien leurs tickets.
 */
function ep_stats_ventes(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    $vue = (string) ($_GET['vue'] ?? 'jour');
    if (!in_array($vue, ['jour', 'semaine', 'mois'], true)) { $vue = 'jour'; }
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $nom = magasinConnu((string) $sid);
    [$du, $au, $jours] = svJours($vue, $date);

    @set_time_limit(180);
    $t0 = microtime(true);
    $heures = svHeuresJours($sid, $jours);
    $cout = 0; $budget = SV_BUDGET_DEMANDE;
    $prod = []; $joursProd = []; $tempsEpuise = false;
    // Les tickets : d'abord les jours déjà gravés (gratuits), puis les autres
    // du plus récent au plus ancien, jusqu'au budget de la requête — en
    // tickets ET en secondes : une réponse partielle vaut mieux qu'un 500,
    // le reste se grave à la relecture suivante et au cron.
    foreach (array_reverse($jours) as $j) {
        if ($j < SV_DEBUT) { continue; }
        $grave = setting('svP' . $sid . ':' . $j);
        $dejaLu = is_array($grave) && isset($grave['p']) && ($j < $auj || (int) ($grave['quand'] ?? 0) > time() - SV_TTL_JOUR);
        if (!$dejaLu && microtime(true) - $t0 > SV_TEMPS_DEMANDE) { $tempsEpuise = true; continue; }
        $p = svProduitsJour($sid, $j, $cout, $budget);
        if ($p !== null) { $prod[$j] = $p; $joursProd[] = $j; }
    }

    // Agrégat par heure : somme sur les jours ouverts (CA > 0 dans l'heure ou le jour).
    $agg = []; $joursOuverts = [];
    foreach ($heures as $j => $hs) {
        $tot = 0.0; foreach ($hs as $l) { $tot += $l['ca']; }
        if ($tot <= 0) { continue; }
        $joursOuverts[] = $j;
        foreach ($hs as $h => $l) {
            if (!isset($agg[$h])) { $agg[$h] = ['h' => (int) $h, 'tickets' => 0, 'ca' => 0.0, 'mat' => 0.0, 'trav' => 0.0, 'poste' => 0.0, 'marge' => 0.0, 'jours' => 0]; }
            $a =& $agg[$h];
            $a['tickets'] += $l['tickets']; $a['ca'] += $l['ca']; $a['mat'] += $l['mat']; $a['trav'] += $l['trav']; $a['marge'] += $l['marge'];
            if ($l['ca'] > 0 || $l['poste'] > 0) { $a['poste'] += $l['poste']; $a['jours']++; }
            unset($a);
        }
    }
    ksort($agg, SORT_NUMERIC);
    // La nuit ne se dessine pas : de la première à la dernière heure vendue.
    $actives = array_keys(array_filter($agg, static fn ($a) => $a['ca'] > 0));
    $hMin = $actives === [] ? 0 : min($actives); $hMax = $actives === [] ? 23 : max($actives);
    $nJ = max(1, count($joursOuverts));
    $catDe = svCategories();
    $lignes = []; $ccT = [];
    foreach ($agg as $h => $a) {
        if ((int) $h < $hMin || (int) $h > $hMax) { continue; }
        // Le top 5 de l'heure, par marge — sur les jours dont les tickets sont lus.
        $pp = [];
        foreach ($prod as $j => $ph) {
            foreach ((array) ($ph[(string) $h] ?? []) as $pid => $x) {
                if (!isset($pp[$pid])) { $pp[$pid] = ['id' => (int) $pid, 'nom' => $x[0], 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false]; }
                $pp[$pid]['q'] += $x[1]; $pp[$pid]['v'] += $x[2];
                if ($x[3] === null) { $pp[$pid]['cInconnu'] = true; } else { $pp[$pid]['c'] += $x[3]; }
            }
        }
        $top = []; $cc = [];
        foreach ($pp as $x) {
            $m = $x['cInconnu'] ? null : round($x['v'] - $x['c'], 2);
            $top[] = ['id' => $x['id'], 'nom' => $x['nom'], 'q' => round($x['q'], 1), 'v' => round($x['v'], 2),
                'c' => $x['cInconnu'] ? null : round($x['c'], 2), 'm' => $m,
                'taux' => ($m !== null && $x['v'] > 0) ? round(100 * $m / $x['v'], 1) : null];
            // La même somme par catégorie : ce qui fait la marge de l'heure, famille par famille.
            $cn = $catDe[$x['id']] ?? 'Sans catégorie';
            if (!isset($cc[$cn])) { $cc[$cn] = ['nom' => $cn, 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false, 'refs' => 0]; }
            $cc[$cn]['q'] += $x['q']; $cc[$cn]['v'] += $x['v']; $cc[$cn]['refs']++;
            if ($x['cInconnu']) { $cc[$cn]['cInconnu'] = true; } else { $cc[$cn]['c'] += $x['c']; }
            // Et la même somme sur toute la période : la marge de chaque catégorie, CA − coût matière.
            if (!isset($ccT[$cn])) { $ccT[$cn] = ['nom' => $cn, 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false, 'refs' => []]; }
            $ccT[$cn]['q'] += $x['q']; $ccT[$cn]['v'] += $x['v']; $ccT[$cn]['refs'][$x['id']] = true;
            if ($x['cInconnu']) { $ccT[$cn]['cInconnu'] = true; } else { $ccT[$cn]['c'] += $x['c']; }
        }
        $tri = static fn ($a2, $b2) => ($b2['m'] ?? -INF) <=> ($a2['m'] ?? -INF) ?: $b2['v'] <=> $a2['v'];
        usort($top, $tri);
        $cats = [];
        $vH = array_sum(array_column($pp, 'v'));
        foreach ($cc as $x) {
            $m = $x['cInconnu'] ? null : round($x['v'] - $x['c'], 2);
            $cats[] = ['nom' => $x['nom'], 'q' => round($x['q'], 1), 'v' => round($x['v'], 2), 'c' => $x['cInconnu'] ? null : round($x['c'], 2),
                'm' => $m, 'taux' => ($m !== null && $x['v'] > 0) ? round(100 * $m / $x['v'], 1) : null,
                'part' => $vH > 0 ? round(100 * $x['v'] / $vH, 1) : null, 'refs' => $x['refs']];
        }
        usort($cats, $tri);
        $nRef = count($top);
        $mbH = round($a['ca'] - $a['mat'], 2);
        $lignes[] = ['h' => (int) $h, 'tickets' => $a['tickets'], 'ca' => round($a['ca'], 2), 'mat' => round($a['mat'], 2),
            'mb' => $mbH, 'mbPct' => $a['ca'] > 0 ? round(100 * $mbH / $a['ca'], 1) : null,
            'trav' => round($a['trav'], 2), 'poste' => $a['jours'] > 0 ? round($a['poste'] / $a['jours'], 1) : 0,
            'res' => round($a['marge'], 2), 'resPct' => $a['ca'] > 0 ? round(100 * $a['marge'] / $a['ca'], 1) : null,
            'panier' => $a['tickets'] > 0 ? round($a['ca'] / $a['tickets'], 2) : null,
            // La moyenne par jour ouvert, pour lire une semaine ou un mois comme une journée.
            'moy' => ['tickets' => round($a['tickets'] / $nJ, 1), 'ca' => round($a['ca'] / $nJ, 2), 'mat' => round($a['mat'] / $nJ, 2),
                'mb' => round($mbH / $nJ, 2), 'trav' => round($a['trav'] / $nJ, 2), 'res' => round($a['marge'] / $nJ, 2)],
            'top' => array_slice($top, 0, SV_TOP), 'cats' => array_slice($cats, 0, SV_TOP_CATS), 'categories' => count($cats), 'references' => $nRef,
            'topSur' => count(array_filter($prod, static fn ($ph) => isset($ph[(string) $h])))];
    }
    // Les catégories sur la période : CA, coût matière, marge brute et son taux — pour colorer le treemap par la marge.
    $catsT = []; $vT = array_sum(array_map(static fn ($x) => $x['v'], $ccT));
    foreach ($ccT as $x) {
        $m = $x['cInconnu'] ? null : round($x['v'] - $x['c'], 2);
        $catsT[] = ['nom' => $x['nom'], 'q' => round($x['q'], 1), 'v' => round($x['v'], 2), 'c' => $x['cInconnu'] ? null : round($x['c'], 2), 'm' => $m,
            'taux' => ($m !== null && $x['v'] > 0) ? round(100 * $m / $x['v'], 1) : null, 'part' => $vT > 0 ? round(100 * $x['v'] / $vT, 1) : null, 'refs' => count($x['refs'])];
    }
    usort($catsT, static fn ($a2, $b2) => $b2['v'] <=> $a2['v']);
    $tot = ['tickets' => 0, 'ca' => 0.0, 'mat' => 0.0, 'trav' => 0.0, 'res' => 0.0];
    foreach ($lignes as $l) { $tot['tickets'] += $l['tickets']; $tot['ca'] += $l['ca']; $tot['mat'] += $l['mat']; $tot['trav'] += $l['trav']; $tot['res'] += $l['res']; }
    $tot['mb'] = round($tot['ca'] - $tot['mat'], 2);
    $tot['panier'] = $tot['tickets'] > 0 ? round($tot['ca'] / $tot['tickets'], 2) : null;
    $tot['mbPct'] = $tot['ca'] > 0 ? round(100 * $tot['mb'] / $tot['ca'], 1) : null;
    $tot['resPct'] = $tot['ca'] > 0 ? round(100 * $tot['res'] / $tot['ca'], 1) : null;
    foreach (['ca', 'mat', 'trav', 'res'] as $k) { $tot[$k] = round($tot[$k], 2); }
    $meilleure = null; $pire = null;
    foreach ($lignes as $l) {
        if ($meilleure === null || $l['res'] > $meilleure['res']) { $meilleure = $l; }
        if ($pire === null || $l['res'] < $pire['res']) { $pire = $l; }
    }
    sort($joursProd);
    return ['shop' => $sid, 'magasin' => $nom, 'vue' => $vue, 'date' => $date, 'du' => $du, 'au' => $au, 'aujourdhui' => $auj,
        'jours' => $jours, 'joursOuverts' => $joursOuverts, 'joursServis' => array_keys($heures),
        'produits' => ['jours' => $joursProd, 'total' => count(array_filter($jours, static fn ($j) => $j >= SV_DEBUT)),
            'ticketsLus' => $cout, 'complet' => count($joursProd) === count(array_filter($jours, static fn ($j) => $j >= SV_DEBUT)),
            'aSuivre' => $tempsEpuise || $cout >= $budget, 'secondes' => round(microtime(true) - $t0, 1)],
        'heures' => $lignes, 'categories' => $catsT, 'totaux' => $tot, 'nJoursOuverts' => count($joursOuverts),
        'meilleure' => $meilleure ? ['h' => $meilleure['h'], 'res' => $meilleure['res'], 'moy' => $meilleure['moy']['res']] : null,
        'pire' => $pire ? ['h' => $pire['h'], 'res' => $pire['res'], 'moy' => $pire['moy']['res']] : null,
        'source' => ['heures' => 'panel hourly-distribution (ventes, matière, personnel, marge de l’heure)',
            'produits' => 'tickets du panel avec leurs lignes, coût matière des recettes']];
}

/**
 * La moisson des tickets au cron : du premier jour moissonné jusqu'à hier,
 * tous les magasins, un lot borné par battement. Dit où elle en est.
 */
function svMoisson(int $budget = SV_BUDGET_CRON): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    try { $shops = array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $cout = 0; $faits = 0; $restants = 0;
    $hier = date('Y-m-d', strtotime('-1 day'));
    // Du plus récent au plus ancien : le dashboard regarde d'abord la semaine.
    for ($j = $hier; $j >= SV_DEBUT; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        foreach ($shops as $sid) {
            $c = setting('svP' . $sid . ':' . $j);
            if (is_array($c) && isset($c['p'])) { continue; }
            if ($cout >= $budget) { $restants++; continue; }
            $r = svProduitsJour($sid, $j, $cout, $budget);
            if ($r !== null) { $faits++; } else { $restants++; }
        }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'tickets' => $cout, 'joursRestants' => $restants,
        'etat' => $restants === 0 ? 'à jour' : $restants . ' jour(s)-magasin restants'];
}

/** Le battement horaire, accroché au cron des rapports. */
function svCron(): string
{
    $r = svMoisson();
    return $r['ok'] ? ($r['joursFaits'] . ' jour(s) moissonnés, ' . $r['etat']) : ('échec : ' . ($r['motif'] ?? '?'));
}

/** POST /ventes/stats-moisson — forcer une passe, voir l'état. */
function wr_stats_ventes_moisson(): array
{
    return svMoisson((int) (body()['budget'] ?? SV_BUDGET_CRON));
}
