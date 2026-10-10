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
 * journée en cours se relit toutes les dix minutes. Un relevé n'est définitif
 * que s'il a été pris un jour plus tard : celui d'une journée en cours reste
 * un instantané, même quand la date est passée (svGraveValide). Les tickets
 * se moissonnent par lots bornés (budget), à la demande puis au cron horaire.
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
        'emp'    => '/shops/' . $sid . '/employees',
        'emp2'   => '/employees?shop_id=' . $sid,
        'notif'  => '/shops/' . $sid . '/notifications',
        'notif2' => '/shops/' . $sid . '/notifications?date=' . $date,
    ];
    $res = PanelApi::getParallele($chemins, 6);
    $nEx = max(1, min(100, (int) ($_GET['n'] ?? 3)));
    // Jamais de secret dans une sonde : mots de passe, jetons et codes PIN sont retirés.
    $propre = static function ($x) { if (is_array($x)) { unset($x['password'], $x['refresh_token'], $x['pin']); } return $x; };
    $coupe = static function ($v, int $n = 3) use ($nEx, $propre) {
        if (!is_array($v)) { return $v; }
        $l = analyseListe($v);
        if ($l !== []) { return ['n' => count($l), 'cles' => array_keys((array) $l[0]), 'exemples' => array_map($propre, array_slice($l, 0, max($n, $nEx)))]; }
        return ['cles' => array_keys($v), 'valeur' => array_map(static fn ($x) => is_array($x) ? ['n' => count($x), 'premier' => array_slice($x, 0, 2)] : $x, $v)];
    };
    $out = ['shop' => $sid, 'date' => $date];
    // Un produit précis (?pid=) : ce que chaque source du panel en dit — la fiche, la ligne
    // « disponible » du magasin, le rapport par catégorie du jour, la copie locale, le ticket.
    $pid = (int) ($_GET['pid'] ?? 0);
    if ($pid > 0) {
        $d = [];
        $fiche = PanelApi::get('/products/' . $pid);
        $d['fiche'] = is_array($fiche) ? $propre(array_map(static fn ($x) => is_array($x) ? ['n' => count($x), 'cles' => array_keys($x)] : $x, $fiche)) : $fiche;
        $ligne = null;
        foreach (method_exists('PanelApi', 'produitsDisponibles') ? PanelApi::produitsDisponibles($sid) : [] as $l) {
            foreach (['id', 'product_id', 'id_product'] as $k) { if (isset($l[$k]) && (int) $l[$k] === $pid) { $ligne = $l; break 2; } }
        }
        $d['disponible'] = $ligne === null ? null : $propre(array_map(static fn ($x) => is_array($x) ? ['n' => count($x), 'cles' => array_keys($x)] : $x, $ligne));
        $d['disponibleCategorie'] = $ligne['category'] ?? null;
        $g = PanelApi::get('/shops/' . $sid . '/statistics/sales/product-category-groups?date_from=' . $date . '&date_to=' . $date);
        $d['groupes'] = ['cles' => is_array($g) && analyseListe($g) !== [] ? array_keys((array) analyseListe($g)[0]) : null,
            'ligne' => array_values(array_filter(analyseListe(is_array($g) ? $g : []), static fn ($l) => (int) ($l['product_id'] ?? 0) === $pid))];
        try { $d['copie'] = Db::rows('SELECT id, name, id_category, is_active FROM product WHERE id = ?', [$pid]); } catch (Throwable $e) { $d['copie'] = 'table absente'; }
        $d['categorieLocale'] = svCategories()[$pid] ?? null;
        $gr = setting('svP' . $sid . ':' . $date);
        $d['ticketGrave'] = null;
        foreach ((array) ($gr['p'] ?? []) as $h => $lst) { if (isset($lst[$pid])) { $d['ticketGrave'] = ['h' => $h, 'ligne' => $lst[$pid]]; break; } }
        // La ligne de ticket BRUTE qui porte ce produit, telle que le panel la rend : les tickets du
        // jour, puis chaque ticket jusqu'à trouver la ligne (soixante tickets au plus).
        $d['ligneTicket'] = null;
        $ids = array_values(array_filter(array_map(static fn ($tk) => (int) ($tk['id'] ?? 0), analyseListe($res['trans'] ?? null))));
        $d['ticketsDuJour'] = count($ids);
        foreach (array_chunk($ids, 40) as $lot) {
            $chemins = [];
            foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
            $rs = PanelApi::getParallele($chemins, 8);
            foreach ($lot as $id) {
                $t = $rs[$id] ?? null;
                foreach ((array) (is_array($t) ? ($t['products'] ?? []) : []) as $l) {
                    if ((int) ($l['id_product'] ?? 0) === $pid) { $d['ligneTicket'] = ['ticket' => $id, 'ligne' => $propre($l), 'clesTicket' => array_keys($t), 'autresLignes' => array_map(static fn ($x) => ['id_product' => $x['id_product'] ?? null, 'nom' => $x['product_name'] ?? null, 'q' => $x['quantity'] ?? null], (array) $t['products'])]; break 3; }
                }
            }
        }
        $out['produit'] = $d;
        return $out;
    }
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

/**
 * GET /ventes/tendance?shop=4&date=2026-09-13 — les 7 derniers mêmes jours de
 * semaine AVANT la date (CA, marge brute, clients, panier), relus dans le
 * margin-heatmap du panel sur huit semaines. Gravé pour la journée. Le front
 * dessine la mini-courbe des tuiles et l'écart avec le dernier même jour.
 */
function ep_stats_tendance(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); return ['error' => 'shop ou date manquant']; }
    $cle = 'svTend|' . $sid . '|' . $date;
    if (empty($_GET['force'])) {
        $v = setting($cle);
        if (is_string($v)) { $v = json_decode($v, true); }
        if (is_array($v) && isset($v['jours'])) { return $v + ['cache' => true]; }
    }
    $jd = new DateTimeImmutable($date);
    $wd = (int) $jd->format('N');
    if (!PanelApi::configured()) { return ['shop' => $sid, 'date' => $date, 'jours' => [], 'indispo' => true]; }
    $au = $jd->modify('-1 day'); $du = $jd->modify('-56 days');
    $mid = $du->modify('+30 days');
    $res = PanelApi::getParallele([
        'a' => '/consultant/shops/' . $sid . '/margin-heatmap?from=' . $du->format('Y-m-d') . '&to=' . $mid->format('Y-m-d'),
        'b' => '/consultant/shops/' . $sid . '/margin-heatmap?from=' . $mid->modify('+1 day')->format('Y-m-d') . '&to=' . $au->format('Y-m-d')], 2);
    $jours = [];
    foreach (['a', 'b'] as $k) {
        foreach ((array) (is_array($res[$k] ?? null) ? ($res[$k]['days'] ?? []) : []) as $d) {
            $dt = (string) ($d['date'] ?? ''); $ca = (float) ($d['ca'] ?? 0);
            if ($dt === '' || $dt >= $date || empty($d['has_data']) || $ca <= 0) { continue; }
            if ((int) (new DateTimeImmutable($dt))->format('N') !== $wd) { continue; }
            $tk = (int) ($d['tickets'] ?? 0); $mb = isset($d['margin_value']) ? (float) $d['margin_value'] : null;
            $jours[$dt] = ['date' => $dt, 'ca' => round($ca, 2), 'mb' => $mb !== null ? round($mb, 2) : null, 'mbPct' => ($mb !== null && $ca > 0) ? round(100 * $mb / $ca, 1) : null,
                'tickets' => $tk ?: null, 'panier' => $tk > 0 ? round($ca / $tk, 2) : null];
        }
    }
    ksort($jours);
    $jours = array_values(array_slice($jours, -7));
    $out = ['shop' => $sid, 'date' => $date, 'jourSemaine' => $wd, 'jours' => $jours, 'quand' => date('c')];
    if ($jours !== []) { try { svGrave($cle, $out); } catch (Throwable $e) { /* sans cache */ } }
    return $out;
}

/** Grave une valeur dans ceo_app_setting. */
function svGrave(string $cle, array $v): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Un relevé gravé du jour $j vaut-il encore ?
 *
 * Définitif seulement s'il a été pris un jour calendaire PLUS TARD : la
 * journée était close, le panel avait tous ses tickets. Pris le jour même,
 * ce n'est qu'un instantané — bon dix minutes, comme la journée en cours —
 * même si la date est passée depuis. Sans cela, un dimanche lu à 9 h par le
 * premier qui ouvre le dashboard restait figé à 9 h pour toujours, parce que
 * le lundi le tenait pour « jour clos » : 531 € de matin et rien l'après-midi.
 * Un relevé sans horodatage (ancienne forme) est tenu pour clos.
 */
function svGraveValide(mixed $c, string $champ, string $j): bool
{
    if (!is_array($c) || !isset($c[$champ])) { return false; }
    $quand = (int) ($c['quand'] ?? 0);
    if ($quand <= 0) { return true; }
    return date('Y-m-d', $quand) > $j || $quand > time() - SV_TTL_JOUR;
}

/** Une ligne d'heure normalisée depuis hourly-distribution. */
function svLigneHeure(array $r): ?array
{
    $h = (int) substr((string) ($r['hour_from'] ?? ''), 0, 2);
    if ($h < 0 || $h > 23) { return null; }
    $ca = round((float) ($r['income'] ?? 0), 2);
    // Le coût matière de l'heure : null quand le panel ne le connaît pas (material_cost absent,
    // margin_status COST_INCOMPLETE) — pas zéro, qui ferait une marge de 100 %. Il se recompose
    // ensuite depuis les tickets (svMatiereJour).
    $matConnu = isset($r['material_cost']) && is_numeric($r['material_cost']);
    $mat = $matConnu ? round((float) $r['material_cost'], 2) : null;
    $trav = round((float) ($r['employee_cost'] ?? 0), 2);
    return ['h' => $h, 'tickets' => (int) ($r['transactions_qty'] ?? 0), 'ca' => $ca, 'mat' => $mat, 'matConnu' => $matConnu,
        'trav' => $trav, 'poste' => (int) ($r['employee_qty'] ?? 0),
        'marge' => isset($r['total_margin']) && is_numeric($r['total_margin']) ? round((float) $r['total_margin'], 2) : ($mat !== null ? round($ca - $mat - $trav, 2) : null)];
}

/**
 * Le coût matière d'une journée recomposé depuis les tickets : la somme des coûts de recette des
 * lignes vendues (svProduitsJour, le coût du panel pour ce magasin, la portion à sa fraction), et
 * pour les lignes dont la recette n'a pas de coût, leur CA au taux des lignes connues. Mesuré à
 * Halle le 03/10/2026 : le panel rend margin_value et material_cost nuls (COST_INCOMPLETE) dès
 * qu'un produit vendu n'a pas de coût, et le P&L affichait un coût matière de 100 %. Null si les
 * tickets du jour ne sont pas lus ; avec $budget = 0, ne lit que le gravé.
 */
function svMatiereJour(int $sid, string $j, int &$cout, int $budget): ?array
{
    static $memo = [];
    $k = $sid . ':' . $j;
    if (array_key_exists($k, $memo) && ($memo[$k] !== null || $budget <= 0)) { return $memo[$k]; }
    if ($j < SV_DEBUT) { return $memo[$k] = null; }
    $p = svProduitsJour($sid, $j, $cout, $budget);
    if ($p === null) { return $memo[$k] = null; }
    $connu = 0.0; $caConnu = 0.0; $caInconnu = 0.0; $parHeure = []; $aberrantes = 0;
    foreach ($p as $h => $lst) {
        $hc = 0.0; $hk = 0.0; $hi = 0.0;
        foreach ((array) $lst as $x) {
            $v = (float) ($x[2] ?? 0);
            $c = $x[3] ?? null;
            // Un coût invraisemblable (au-dessus du prix de vente, ou sous 5 % de celui-ci : une
            // recette mal chiffrée en amont, comme le contrôle du scoring) vaut un coût inconnu —
            // mesuré à Gosselies, trois jours à −100 % de marge venaient de là.
            if ($c !== null && $v > 0 && !svCoutPlausible((float) $c, $v)) { $aberrantes++; $c = null; }
            if ($c === null) { $hi += $v; } else { $hc += (float) $c; $hk += $v; }
        }
        $parHeure[(int) $h] = [$hc, $hk, $hi];
        $connu += $hc; $caConnu += $hk; $caInconnu += $hi;
    }
    $ca = $caConnu + $caInconnu;
    if ($ca <= 0 || $caConnu <= 0) { return $memo[$k] = null; }
    $taux = $connu / $caConnu;
    $est = [];
    foreach ($parHeure as $h => [$hc, $hk, $hi]) { $est[$h] = round($hc + $hi * $taux, 2); }
    $couv = round(100 * $caConnu / $ca, 1);
    return $memo[$k] = ['estime' => round($connu + $caInconnu * $taux, 2), 'connu' => round($connu, 2), 'ca' => round($ca, 2),
        'couverture' => $couv, 'taux' => round(100 * $taux, 1), 'parHeure' => $est, 'aberrantes' => $aberrantes,
        'source' => 'recettes vendues' . ($couv < 99.5 ? ' · estimé, ' . round($couv) . ' % du CA avec coût connu' : '')];
}

/** Un coût de ligne est-il plausible face à sa vente ? Entre 5 % et 100 % du prix, exclu. */
function svCoutPlausible(float $cout, float $vente): bool
{
    if ($vente <= 0) { return true; }
    if ($cout <= 0 || $cout >= $vente) { return false; }
    $p = setting('production', []);
    $min = (is_array($p) && isset($p['coutRatioMin'])) ? (float) $p['coutRatioMin'] : 0.05;
    return $min <= 0 || ($cout / $vente) >= $min;
}

/**
 * Les heures d'une journée avec le coût matière recomposé quand le panel ne le donne pas : les
 * heures à `mat` null prennent l'estimation de svMatiereJour pour cette heure. Le gravé seul,
 * jamais de lecture de tickets ici.
 */
function svHeuresAvecMatiere(int $sid, string $j, array $hs): array
{
    $manque = false;
    foreach ($hs as $l) { if (is_array($l) && ($l['mat'] ?? null) === null) { $manque = true; break; } }
    if (!$manque) { return $hs; }
    $zero = 0;
    $e = svMatiereJour($sid, $j, $zero, 0);
    foreach ($hs as $h => $l) {
        if (!is_array($l) || ($l['mat'] ?? null) !== null) { continue; }
        $m = $e !== null ? ($e['parHeure'][(int) $h] ?? 0.0) : null;
        $hs[$h]['mat'] = $m; $hs[$h]['matEstime'] = $e !== null;
        if ($m !== null && ($l['marge'] ?? null) === null) { $hs[$h]['marge'] = round((float) $l['ca'] - $m - (float) ($l['trav'] ?? 0), 2); }
    }
    return $hs;
}

/**
 * Les trois périodes de la journée — matin, midi, après-midi — jour par jour.
 *
 * Bornes du réseau : matin 6 – 10 h, midi 11 – 14 h, après-midi 15 – 19 h.
 * Ce qui se vend avant 6 h compte dans le matin, ce qui se vend après 19 h
 * dans l'après-midi : une vente ne tombe jamais entre deux périodes. Par
 * période et par jour : ventes, clients, marge brute, marge nette — les mêmes
 * chiffres que les heures, additionnés.
 *
 * @param array<string,array<string,array>> $heures  [date => [h => ligne]]
 */
function svPeriodes(array $heures): array
{
    $bornes = [['matin', 'Matin', 6, 10], ['midi', 'Midi', 11, 14], ['aprem', 'Après-midi', 15, 19]];
    $de = static function (int $h) use ($bornes): string {
        foreach ($bornes as $b) { if ($h >= $b[2] && $h <= $b[3]) { return $b[0]; } }
        return $h < $bornes[0][2] ? $bornes[0][0] : $bornes[2][0];
    };
    $vide = static fn () => ['ca' => 0.0, 'tickets' => 0, 'mb' => 0.0, 'res' => 0.0];
    $jours = [];
    ksort($heures);
    foreach ($heures as $date => $hs) {
        $j = ['matin' => $vide(), 'midi' => $vide(), 'aprem' => $vide()];
        foreach ($hs as $l) {
            $k = $de((int) $l['h']);
            $j[$k]['ca'] += (float) $l['ca']; $j[$k]['tickets'] += (int) $l['tickets'];
            $j[$k]['mb'] += (float) $l['ca'] - (float) $l['mat']; $j[$k]['res'] += (float) $l['marge'];
        }
        foreach ($j as &$x) { $x['ca'] = round($x['ca'], 2); $x['mb'] = round($x['mb'], 2); $x['res'] = round($x['res'], 2); }
        unset($x);
        $jours[(string) $date] = $j;
    }
    return ['bornes' => array_map(static fn ($b) => ['cle' => $b[0], 'nom' => $b[1], 'de' => $b[2], 'a' => $b[3]], $bornes), 'jours' => $jours];
}

/**
 * GET /ventes/periodes?shop=4&vue=semaine&date=… — les périodes seules.
 *
 * La lecture complète (/ventes/stats) relit les tickets et prend vingt
 * secondes ; les périodes n'ont besoin que des heures, gravées jour par jour.
 * Cet appel rend en moins d'une seconde ce que le drop d'un magasin, dans
 * Résultat › Semaine, affiche à l'ouverture.
 */
function ep_ventes_periodes(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $vue = in_array($_GET['vue'] ?? '', ['jour', 'semaine', 'mois'], true) ? (string) $_GET['vue'] : 'semaine';
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    [$du, $au, $jours] = svJours($vue, $date);
    $heures = svHeuresJours($sid, $jours);
    // Les heures additionnées sur la période, et leur moyenne par jour ouvert :
    // de quoi dire l'heure de pointe sans relire les tickets.
    $parH = []; $nJ = 0;
    foreach ($heures as $hs) {
        if ($hs === []) { continue; }
        $nJ++;
        foreach ($hs as $l) {
            $h = (int) $l['h'];
            $parH[$h] ??= ['h' => $h, 'ca' => 0.0, 'tickets' => 0, 'res' => 0.0];
            $parH[$h]['ca'] += (float) $l['ca']; $parH[$h]['tickets'] += (int) $l['tickets']; $parH[$h]['res'] += (float) $l['marge'];
        }
    }
    ksort($parH);
    $lignesH = array_values(array_map(static fn ($x) => ['h' => $x['h'], 'ca' => round($x['ca'], 2), 'tickets' => $x['tickets'], 'res' => round($x['res'], 2),
        'moy' => ['ca' => round($x['ca'] / max(1, $nJ), 2), 'tickets' => round($x['tickets'] / max(1, $nJ), 1), 'res' => round($x['res'] / max(1, $nJ), 2)]], $parH));
    return ['shop' => $sid, 'vue' => $vue, 'du' => $du, 'au' => $au, 'joursServis' => array_keys($heures), 'nJoursOuverts' => $nJ, 'heures' => $lignesH] + svPeriodes($heures);
}

/**
 * Les heures de plusieurs jours d'un magasin — [date => [h => ligne]] ; un jour
 * muet est absent. Les jours clos gravés ne se relisent pas.
 */
function svHeuresJours(int $sid, array $jours): array
{
    $out = []; $chemins = []; $instantanes = [];
    foreach ($jours as $j) {
        $c = setting('svH' . $sid . ':' . $j);
        if (svGraveValide($c, 'h', $j)) { $out[$j] = $c['h']; continue; }
        // Un instantané périmé sert de repli si le panel ne répond pas : mieux
        // vaut la matinée d'un dimanche que rien du tout.
        if (is_array($c) && isset($c['h'])) { $instantanes[$j] = $c['h']; }
        $chemins[$j] = '/shops/' . $sid . '/statistics/sales/hourly-distribution/' . $j;
    }
    if ($chemins !== []) {
        $res = PanelApi::getParallele($chemins, 6);
        foreach (array_keys($chemins) as $j) {
            $r = $res[$j] ?? null;
            if (!is_array($r)) { if (isset($instantanes[$j])) { $out[$j] = $instantanes[$j]; } continue; }
            $hs = [];
            foreach (analyseListe($r) as $x) { $l = svLigneHeure((array) $x); if ($l !== null) { $hs[(string) $l['h']] = $l; } }
            $out[$j] = $hs;
            svGrave('svH' . $sid . ':' . $j, ['quand' => time(), 'h' => $hs]);
        }
    }
    ksort($out);
    foreach ($out as $j => $hs) { $out[$j] = svHeuresAvecMatiere($sid, (string) $j, $hs); }
    return $out;
}

/**
 * Les produits d'un jour, heure par heure — {h: {pid: [nom, q, ventes, coût|null]}}.
 * Lit les tickets du jour si le jour n'est pas gravé ; null si le budget est
 * épuisé ou le panel muet (un jour à moitié lu ne se grave pas).
 *
 * La relecture est INCRÉMENTALE (05/10/2026) : le relevé garde les tickets déjà lus
 * (`ids`) ; la liste du jour se relit (un appel), seuls les tickets nouveaux se lisent
 * (un appel chacun) et s'ajoutent au relevé. Mesuré avant : la journée entière relue
 * toutes les dix minutes, 298 tickets à Corbais, 8 s. Un ticket déjà lu qui a disparu
 * de la liste (annulé) fait tout relire.
 */
function svProduitsJour(int $sid, string $j, int &$cout, int $budget, bool $detail = false): ?array
{
    $cle = 'svP' . $sid . ':' . $j;
    $c = setting($cle);
    // `sp` (10/10/2026) : les lignes de caisse sans produit et les écarts de ticket. Un relevé qui ne les
    // porte pas encore se relit une fois en entier : aujourd'hui, ou un jour passé ouvert seul (`$detail`).
    $sansSp = is_array($c) && !array_key_exists('sp', $c) && ($j === date('Y-m-d') || $detail);
    if (!$sansSp && svGraveValide($c, 'p', $j)) { return $c['p']; }
    if ($cout >= $budget) { return null; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    if (!is_array($liste)) { return null; }
    $ids = []; $pro = [];
    foreach (analyseListe($liste) as $t) {
        if ((int) ($t['id'] ?? 0) <= 0) { continue; }
        $ids[] = (int) $t['id'];
        if (!empty($t['is_client_b2b'])) { $pro[(int) $t['id']] = true; }
    }
    // Ce qui est déjà lu : un relevé qui garde ses tickets, et aucun d'eux disparu de la liste.
    $base = null;
    if (!$sansSp && is_array($c) && isset($c['p']) && is_array($c['p']) && is_array($c['ids'] ?? null)) {
        $lus = array_map('intval', $c['ids']);
        if (array_diff($lus, $ids) === []) { $base = $c; }
    }
    $nouveaux = $base !== null ? array_values(array_diff($ids, $lus)) : $ids;
    if ($cout + count($nouveaux) > $budget && $cout > 0) { return null; }   // ce jour attendra le prochain lot
    $couts = catalogueCouts();
    // Le coût de CE magasin d'abord (products/available le chiffre pour chacun), la moyenne du réseau sinon.
    $coutsM = function_exists('coutsPanelMagasin') ? coutsPanelMagasin($sid) : [];
    $p = $base !== null ? $base['p'] : [];
    // La part des clients pro, produit par produit : elle ne passe pas par le
    // comptoir, les rotations du planogramme la retirent.
    $pb = $base !== null ? (array) ($base['pb'] ?? []) : [];
    // La minute du dernier ticket de chaque produit (portions comprises) : la clôture la montre.
    $der = $base !== null ? (array) ($base['d'] ?? []) : [];
    // Ce que les produits ne ventilent pas : les lignes sans produit (libellé de caisse, montant) et l'écart
    // entre le total du ticket et la somme de ses lignes (remise sur le ticket entier, arrondi).
    $sp = $base !== null ? (array) ($base['sp'] ?? []) : [];
    foreach (array_chunk($nouveaux, 40) as $lot) {
        $chemins = [];
        foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
        $res = PanelApi::getParallele($chemins, 8);
        foreach ($lot as $id) {
            $t = $res[$id] ?? null;
            if (!is_array($t)) { return null; }
            $h = (string) (int) substr((string) ($t['insert_timestamp'] ?? '00'), 11, 2);
            $mn = substr((string) ($t['insert_timestamp'] ?? ''), 11, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $mn)) { $mn = null; }
            $sommeL = 0.0;
            foreach ((array) ($t['products'] ?? []) as $l) {
                $sommeL += (float) ($l['total_gross_value_after_discount'] ?? 0);
                $pid = (int) ($l['id_product'] ?? 0);
                if ($pid <= 0) {
                    $vL = round((float) ($l['total_gross_value_after_discount'] ?? 0), 2);
                    if ($vL != 0.0) { $sp[] = ['t' => $id, 'mn' => $mn, 'nom' => trim((string) ($l['product_display_name'] ?? $l['product_name'] ?? '')) ?: 'ligne sans produit', 'q' => round((float) ($l['quantity'] ?? 0), 3), 'v' => $vL, 'type' => 'ligne']; }
                    continue;
                }
                $q = (float) ($l['quantity'] ?? 0);
                $v = (float) ($l['total_gross_value_after_discount'] ?? 0);
                // Une PORTION (une demi-tarte, un quart) est une ligne à part : sa clé est
                // « produit:portion », son nom porte le libellé, son coût est la fraction du
                // coût de la pièce — mesuré à Halle, le panel chiffre la demi Frangipane &
                // Pommes à 4,42 € quand la pièce vaut 8,85 €.
                $portion = svPortion($l);
                $k = $portion['id'] > 0 ? $pid . ':' . $portion['id'] : $pid;   // la clé de la ligne, pas celle du gravé
                $piece = isset($coutsM[$pid]) ? (float) $coutsM[$pid] : (isset($couts[$pid]['mat']) ? (float) $couts[$pid]['mat'] : null);
                $cu = $piece !== null ? $piece * $portion['fraction'] : $portion['cout'];
                if (!isset($p[$h][$k])) {
                    // Le nom du catalogue du panel (le nom réseau, en français) avant celui de
                    // la ligne de ticket, qui est celui de la caisse du magasin — en néerlandais à Halle.
                    $nom = svCatalogue()[$pid]['nom'] ?? trim((string) ($l['product_name'] ?? ('Produit ' . $pid)));
                    if ($nom === '') { $nom = trim((string) ($l['product_name'] ?? ('Produit ' . $pid))); }
                    if ($portion['id'] > 0) { $nom .= ' — ' . $portion['libelle']; }
                    $p[$h][$k] = [$nom, 0.0, 0.0, $cu === null ? null : 0.0];
                }
                $p[$h][$k][1] += $q;
                $p[$h][$k][2] += $v;
                if ($cu !== null && $p[$h][$k][3] !== null) { $p[$h][$k][3] += $q * $cu; }
                if (isset($pro[$id])) { $pb[(string) $pid] = ($pb[(string) $pid] ?? 0.0) + $q; }
                if ($mn !== null && $q > 0 && $mn > ($der[(string) $pid] ?? '')) { $der[(string) $pid] = $mn; }
            }
            $totT = isset($t['total_gross_amount_after_discount']) && is_numeric($t['total_gross_amount_after_discount']) ? (float) $t['total_gross_amount_after_discount'] : null;
            if ($totT !== null && abs($totT - $sommeL) >= 0.01) {
                $sp[] = ['t' => $id, 'mn' => $mn, 'nom' => $totT < $sommeL ? 'remise sur le ticket entier' : 'écart sur le ticket', 'q' => 0, 'v' => round($totT - $sommeL, 2), 'type' => 'ticket'];
            }
        }
    }
    $cout += count($nouveaux);
    foreach ($p as $h => $lst) { foreach ($lst as $pid => $x) { $p[$h][$pid] = [$x[0], round($x[1], 3), round($x[2], 2), $x[3] === null ? null : round($x[3], 2)]; } }
    foreach ($pb as $pid => $q) { $pb[$pid] = round($q, 3); }
    // Le pro du jour (tickets B2B) se grave avec les produits : la liste est déjà lue.
    // `pb` = les unités vendues aux clients pro, par produit ({} si aucune).
    // `d` = la minute du dernier ticket par produit ({pid: "HH:MM"}) ; absente des jours gravés avant le 04/10/2026.
    // `ids` = les tickets déjà lus : la relecture suivante ne lit que les nouveaux.
    $grave = ['quand' => time(), 'n' => count($ids), 'p' => $p, 'pb' => (object) $pb, 'd' => (object) $der, 'ids' => $ids, 'sp' => $sp];
    if (function_exists('vpDuListe')) { $grave['b'] = vpDuListe($liste); }
    svGrave($cle, $grave);
    return $p;
}

/** La minute du dernier ticket de chaque produit un jour : [pid => "HH:MM"] ; vide si le relevé ne la porte pas. */
function svDernieresVentes(int $sid, string $j): array
{
    $c = setting('svP' . $sid . ':' . $j);
    $out = [];
    foreach (is_array($c) && is_array($c['d'] ?? null) ? $c['d'] : [] as $pid => $mn) {
        if ((int) $pid > 0 && is_string($mn) && preg_match('/^\d{2}:\d{2}$/', $mn)) { $out[(int) $pid] = $mn; }
    }
    return $out;
}

/**
 * La portion d'une ligne de ticket : son identifiant, son libellé, la fraction de la pièce
 * et, quand le panel l'a chiffré, le coût matière de la portion. Mesuré à Halle : la ligne
 * porte id_product_portion (77), product_portion_type (ONE_HALF), product_portion_label
 * (« 1/2 ») et manufacturing_cost_snapshot — un JSON avec portion_fraction (0.5),
 * cost_complete et recipe_cost.net (le coût de LA portion). Une pièce entière : id 0, fraction 1.
 */
function svPortion(array $l): array
{
    $id = (int) ($l['id_product_portion'] ?? $l['product_portion_id'] ?? 0);
    $type = strtoupper((string) ($l['product_portion_type'] ?? ''));
    $lib = trim((string) ($l['product_portion_label'] ?? ''));
    $snap = $l['manufacturing_cost_snapshot'] ?? null;
    if (is_string($snap)) { $snap = json_decode($snap, true); }
    $frac = is_array($snap) && isset($snap['portion_fraction']) && (float) $snap['portion_fraction'] > 0 ? (float) $snap['portion_fraction'] : 0.0;
    if ($frac <= 0) {
        $frac = ['ONE_HALF' => 0.5, 'HALF' => 0.5, 'ONE_THIRD' => 1 / 3, 'ONE_QUARTER' => 0.25, 'QUARTER' => 0.25, 'THREE_QUARTERS' => 0.75, 'ONE_SIXTH' => 1 / 6, 'ONE_EIGHTH' => 0.125][$type] ?? 0.0;
        if ($frac <= 0 && preg_match('#^(\d+)\s*/\s*(\d+)$#', $lib, $m) && (int) $m[2] > 0) { $frac = (int) $m[1] / (int) $m[2]; }
        if ($frac <= 0) { $frac = 1.0; }
    }
    if ($id > 0 && $lib === '') { $lib = $type !== '' ? strtolower(str_replace('_', ' ', $type)) : 'portion'; }
    $cout = null;
    if (is_array($snap) && !empty($snap['cost_complete'])) {
        $n = $snap['recipe_cost']['net'] ?? ($snap['manufacturing_cost']['net'] ?? null);
        if (is_numeric($n) && (float) $n > 0) { $cout = (float) $n; }
    }
    return ['id' => $id, 'libelle' => $lib, 'fraction' => $id > 0 ? $frac : 1.0, 'cout' => $cout];
}

/**
 * Le catalogue produit lu CHEZ LE PANEL — [pid => ['nom', 'cat']] — depuis
 * /shops/{id}/products/available de chaque magasin du compte : le nom réseau (base_name,
 * en français) et la catégorie. Pas la copie locale de la base, qui ignore toute référence
 * créée depuis son extraction. Gardé une heure dans ceo_app_setting ; si le panel se tait,
 * la dernière lecture ressert.
 */
function svCatalogue(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    // Une seule lecture du panel pour tout le monde : le catalogue complet (panelCatalogue) quand
    // il est chargé, sa propre lecture sinon (banc, module seul).
    if (function_exists('panelCatalogue')) {
        $pc = panelCatalogue();
        if ($pc['produits'] !== []) {
            $memo = [];
            foreach ($pc['produits'] as $pid => $x) { $memo[(int) $pid] = ['nom' => (string) ($x['nom'] ?? ''), 'cat' => (string) ($x['cat'] ?? '')]; }
            return $memo;
        }
    }
    $c = setting('svCatalogue');
    if (is_string($c)) { $c = json_decode($c, true); }
    if (is_array($c) && isset($c['ts'], $c['p']) && time() - (int) $c['ts'] < 3600) { return $memo = (array) $c['p']; }
    $out = [];
    if (PanelApi::configured()) {
        foreach (PanelApi::consultantShops() ?? [] as $sh) {
            $sid = (int) ($sh['id'] ?? 0);
            if ($sid <= 0) { continue; }
            foreach (PanelApi::produitsDisponibles($sid) as $l) {
                $pid = 0;
                foreach (['id', 'product_id', 'id_product'] as $k) { if (isset($l[$k]) && is_numeric($l[$k])) { $pid = (int) $l[$k]; break; } }
                if ($pid <= 0 || isset($out[$pid])) { continue; }
                $nom = '';
                foreach (['base_name', 'name', 'product_name'] as $k) { if (!empty($l[$k]) && is_string($l[$k])) { $nom = trim($l[$k]); break; } }
                $cat = '';
                foreach (['base_category_name', 'category_name'] as $k) { if (!empty($l[$k]) && is_string($l[$k])) { $cat = trim($l[$k]); break; } }
                if ($cat === '' && isset($l['category']) && is_array($l['category'])) { $cat = trim((string) ($l['category']['base_name'] ?? $l['category']['name'] ?? '')); }
                $out[$pid] = ['nom' => $nom, 'cat' => $cat];
            }
        }
    }
    if ($out !== []) {
        try { svGrave('svCatalogue', ['ts' => time(), 'p' => $out]); } catch (Throwable $e) { /* sans mémo */ }
        return $memo = $out;
    }
    return $memo = (is_array($c) && isset($c['p'])) ? (array) $c['p'] : [];
}

/** Le nom à afficher d'une ligne gravée : celui du catalogue du panel, la portion conservée ; sinon le nom lu. */
function svNomProduit(int|string $cle, string $nomLu): string
{
    $c = svCatalogue()[(int) $cle] ?? null;
    if ($c === null || ($c['nom'] ?? '') === '') { return $nomLu; }
    if (str_contains((string) $cle, ':')) {
        $pos = mb_strrpos($nomLu, ' — ');
        return $c['nom'] . ($pos !== false ? mb_substr($nomLu, $pos) : '');
    }
    return (string) $c['nom'];
}

/**
 * La catégorie de chaque produit — [pid => nom de catégorie]. D'abord le catalogue du panel
 * (l'API), la copie locale de la base ne comblant que les références que le panel ne liste
 * plus (retirées de la vente mais encore dans d'anciens tickets).
 */
function svCategories(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    foreach (svCatalogue() as $pid => $c) { if (($c['cat'] ?? '') !== '') { $cache[(int) $pid] = (string) $c['cat']; } }
    $cats = function_exists('catalogueCategories') ? (catalogueCategories() ?? []) : [];
    try {
        foreach (Db::rows('SELECT id, id_category FROM product') as $r) {
            if (isset($cache[(int) $r['id']])) { continue; }
            $c = $cats[(int) ($r['id_category'] ?? 0)] ?? null;
            if ($c !== null) { $cache[(int) $r['id']] = (string) $c['nom']; }
        }
    } catch (PDOException $e) { /* sans copie : le panel suffit */ }
    return $cache;
}

/** La famille de chaque catégorie (nom de catégorie → groupe du catalogue), quand le catalogue en connaît. */
function svGroupes(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    foreach ((function_exists('catalogueCategories') ? (catalogueCategories() ?? []) : []) as $c) {
        if (!empty($c['groupe'])) { $cache[(string) $c['nom']] = (string) $c['groupe']; }
    }
    return $cache;
}

/**
 * Les bundles vendus (10/10/2026) : les produits de la catégorie « Bundle » (groupe « Bundle & Promotion »)
 * lus dans les tickets de la période, avec la quantité, le prix moyen encaissé, le CA, la marge et les heures de vente.
 * $prod : {jour: {heure: {pid: [nom, q, v, c|null]}}}.
 */
function svBundles(array $prod, array $catDe, array $grpDe): array
{
    $B = [];
    foreach ($prod as $ph) {
        foreach ((array) $ph as $h => $lst) {
            foreach ((array) $lst as $pid => $x) {
                $cat = $catDe[(int) $pid] ?? '';
                if ($cat === '' || preg_match('/bundle|promotion/iu', $cat . ' ' . ($grpDe[$cat] ?? '')) !== 1) { continue; }
                if (!isset($B[$pid])) { $B[$pid] = ['id' => is_numeric($pid) ? (int) $pid : (string) $pid, 'nom' => svNomProduit($pid, (string) $x[0]), 'cat' => $cat, 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false, 'heures' => []]; }
                $B[$pid]['q'] += $x[1]; $B[$pid]['v'] += $x[2];
                if ($x[3] === null) { $B[$pid]['cInconnu'] = true; } else { $B[$pid]['c'] += $x[3]; }
                if ($x[1] > 0) { $B[$pid]['heures'][(int) $h] = ($B[$pid]['heures'][(int) $h] ?? 0) + $x[1]; }
            }
        }
    }
    $L = []; $q = 0.0; $v = 0.0; $mV = 0.0; $mM = 0.0;
    foreach ($B as $b) {
        if ($b['q'] <= 0 && $b['v'] <= 0) { continue; }
        ksort($b['heures']);
        $m = $b['cInconnu'] ? null : round($b['v'] - $b['c'], 2);
        $hs = array_keys($b['heures']);
        $L[] = ['id' => $b['id'], 'nom' => $b['nom'], 'cat' => $b['cat'], 'q' => round($b['q'], 1), 'v' => round($b['v'], 2), 'prix' => $b['q'] > 0 ? round($b['v'] / $b['q'], 2) : null,
            'c' => $b['cInconnu'] ? null : round($b['c'], 2), 'm' => $m, 'taux' => ($m !== null && $b['v'] > 0) ? round(100 * $m / $b['v'], 1) : null,
            'heures' => array_map(static fn ($n) => round($n, 1), $b['heures']), 'premiere' => $hs[0] ?? null, 'derniere' => $hs !== [] ? $hs[count($hs) - 1] : null];
        $q += $b['q']; $v += $b['v'];
        if ($m !== null) { $mV += $b['v']; $mM += $m; }
    }
    usort($L, static fn ($a, $b) => ($b['q'] <=> $a['q']) ?: ($b['v'] <=> $a['v']));
    return ['lignes' => $L, 'n' => count($L), 'pieces' => round($q, 1), 'ca' => round($v, 2), 'taux' => $mV > 0 ? round(100 * $mM / $mV, 1) : null];
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
        $dejaLu = svGraveValide(setting('svP' . $sid . ':' . $j), 'p', $j);
        if (!$dejaLu && microtime(true) - $t0 > SV_TEMPS_DEMANDE) { $tempsEpuise = true; continue; }
        $p = svProduitsJour($sid, $j, $cout, $budget, $vue === 'jour');
        if ($p !== null) { $prod[$j] = $p; $joursProd[] = $j; }
    }

    // Agrégat par heure : somme sur les jours ouverts (CA > 0 dans l'heure ou le jour).
    $agg = []; $joursOuverts = []; $matEstimee = 0; $matInconnue = 0;
    foreach ($heures as $j => $hs) {
        $tot = 0.0; foreach ($hs as $l) { $tot += $l['ca']; }
        if ($tot <= 0) { continue; }
        $joursOuverts[] = $j;
        // Les tickets viennent d'être lus : une heure sans coût chez le panel prend l'estimation.
        $hs = svHeuresAvecMatiere($sid, (string) $j, $hs);
        foreach ($hs as $h => $l) {
            if (!isset($agg[$h])) { $agg[$h] = ['h' => (int) $h, 'tickets' => 0, 'ca' => 0.0, 'mat' => 0.0, 'trav' => 0.0, 'poste' => 0.0, 'marge' => 0.0, 'jours' => 0]; }
            $a =& $agg[$h];
            if (!empty($l['matEstime'])) { $matEstimee++; } elseif (($l['mat'] ?? null) === null) { $matInconnue++; }
            $a['tickets'] += $l['tickets']; $a['ca'] += $l['ca']; $a['mat'] += (float) ($l['mat'] ?? 0); $a['trav'] += $l['trav']; $a['marge'] += (float) ($l['marge'] ?? ($l['ca'] - (float) ($l['mat'] ?? 0) - $l['trav']));
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
    // Chaque produit sur toute la période, rangé sous sa catégorie : le troisième niveau de la liste groupe › catégorie › produit.
    $ppT = [];
    foreach ($agg as $h => $a) {
        if ((int) $h < $hMin || (int) $h > $hMax) { continue; }
        // Le top 5 de l'heure, par marge — sur les jours dont les tickets sont lus.
        $pp = [];
        foreach ($prod as $j => $ph) {
            foreach ((array) ($ph[(string) $h] ?? []) as $pid => $x) {
                if (!isset($pp[$pid])) { $pp[$pid] = ['id' => is_numeric($pid) ? (int) $pid : (string) $pid, 'pid' => (int) $pid, 'nom' => svNomProduit($pid, (string) $x[0]), 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false]; }
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
            $cn = $catDe[$x['pid']] ?? 'Sans catégorie';
            if (!isset($cc[$cn])) { $cc[$cn] = ['nom' => $cn, 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false, 'refs' => 0]; }
            $cc[$cn]['q'] += $x['q']; $cc[$cn]['v'] += $x['v']; $cc[$cn]['refs']++;
            if ($x['cInconnu']) { $cc[$cn]['cInconnu'] = true; } else { $cc[$cn]['c'] += $x['c']; }
            // Et la même somme sur toute la période : la marge de chaque catégorie, CA − coût matière.
            if (!isset($ccT[$cn])) { $ccT[$cn] = ['nom' => $cn, 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false, 'refs' => []]; }
            $ccT[$cn]['q'] += $x['q']; $ccT[$cn]['v'] += $x['v']; $ccT[$cn]['refs'][$x['id']] = true;
            if ($x['cInconnu']) { $ccT[$cn]['cInconnu'] = true; } else { $ccT[$cn]['c'] += $x['c']; }
            if (!isset($ppT[$x['id']])) { $ppT[$x['id']] = ['id' => $x['id'], 'nom' => $x['nom'], 'cat' => $cn, 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false]; }
            $ppT[$x['id']]['q'] += $x['q']; $ppT[$x['id']]['v'] += $x['v'];
            if ($x['cInconnu']) { $ppT[$x['id']]['cInconnu'] = true; } else { $ppT[$x['id']]['c'] += $x['c']; }
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
    // Chaque catégorie porte sa famille (le groupe de catégories du catalogue) pour la liste famille › catégorie.
    $catsT = []; $vT = array_sum(array_map(static fn ($x) => $x['v'], $ccT));
    $grpDe = svGroupes();
    // Les produits de chaque catégorie, du plus vendu au moins vendu, avec leur part dans la catégorie.
    $prodDe = [];
    foreach ($ppT as $x) {
        if ($x['v'] <= 0 && $x['q'] <= 0) { continue; }
        $m = $x['cInconnu'] ? null : round($x['v'] - $x['c'], 2);
        $prodDe[$x['cat']][] = ['id' => $x['id'], 'nom' => $x['nom'], 'q' => round($x['q'], 1), 'v' => round($x['v'], 2), 'c' => $x['cInconnu'] ? null : round($x['c'], 2), 'm' => $m,
            'taux' => ($m !== null && $x['v'] > 0) ? round(100 * $m / $x['v'], 1) : null];
    }
    foreach ($ccT as $x) {
        $m = $x['cInconnu'] ? null : round($x['v'] - $x['c'], 2);
        $prods = $prodDe[$x['nom']] ?? [];
        usort($prods, static fn ($a2, $b2) => $b2['v'] <=> $a2['v']);
        foreach ($prods as &$pr) { $pr['part'] = $x['v'] > 0 ? round(100 * $pr['v'] / $x['v'], 1) : null; }
        unset($pr);
        $catsT[] = ['nom' => $x['nom'], 'groupe' => $grpDe[$x['nom']] ?? null, 'q' => round($x['q'], 1), 'v' => round($x['v'], 2), 'c' => $x['cInconnu'] ? null : round($x['c'], 2), 'm' => $m,
            'taux' => ($m !== null && $x['v'] > 0) ? round(100 * $m / $x['v'], 1) : null, 'part' => $vT > 0 ? round(100 * $x['v'] / $vT, 1) : null, 'refs' => count($x['refs']),
            'produits' => $prods];
    }
    usort($catsT, static fn ($a2, $b2) => $b2['v'] <=> $a2['v']);
    $bundles = svBundles($prod, $catDe, $grpDe);
    // À compléter pour des chiffres justes (10/10/2026) : les lignes de caisse que les produits ne ventilent
    // pas (relevé `sp` des jours lus, quand il le porte) et les produits vendus sans coût de recette.
    $nvL = []; $nvDetail = $joursProd !== [];
    foreach ($joursProd as $jP) {
        $gP = setting('svP' . $sid . ':' . $jP);
        if (!is_array($gP) || !array_key_exists('sp', $gP)) { $nvDetail = false; continue; }
        foreach ((array) $gP['sp'] as $x) { if (is_array($x)) { $nvL[] = $x + ['j' => $jP]; } }
    }
    usort($nvL, static fn ($a, $b) => strcmp(($a['j'] ?? '') . ($a['mn'] ?? ''), ($b['j'] ?? '') . ($b['mn'] ?? '')));
    $sansCout = [];
    foreach ($ppT as $x) {
        if (!$x['cInconnu'] || $x['v'] <= 0) { continue; }
        $sansCout[] = ['id' => $x['id'], 'nom' => $x['nom'], 'cat' => $x['cat'], 'q' => round($x['q'], 1), 'v' => round($x['v'], 2)];
    }
    usort($sansCout, static fn ($a, $b) => $b['v'] <=> $a['v']);
    $aCompleter = ['detail' => $nvDetail, 'lignes' => array_slice($nvL, 0, 80), 'nLignes' => count($nvL),
        'caLignes' => round(array_sum(array_map(static fn ($x) => ($x['type'] ?? '') === 'ligne' ? (float) $x['v'] : 0.0, $nvL)), 2),
        'caTickets' => round(array_sum(array_map(static fn ($x) => ($x['type'] ?? '') === 'ticket' ? (float) $x['v'] : 0.0, $nvL)), 2),
        'sansCout' => array_slice($sansCout, 0, 60), 'nSansCout' => count($sansCout), 'caSansCout' => round(array_sum(array_column($sansCout, 'v')), 2)];
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
        'heures' => $lignes, 'categories' => $catsT, 'bundles' => $bundles + ['part' => $tot['ca'] > 0 ? round(100 * $bundles['ca'] / $tot['ca'], 1) : null], 'aCompleter' => $aCompleter, 'totaux' => $tot, 'nJoursOuverts' => count($joursOuverts),
        'matiere' => ['heuresEstimees' => $matEstimee, 'heuresInconnues' => $matInconnue,
            'source' => $matInconnue > 0 ? 'coût matière inconnu sur ' . $matInconnue . ' heure' . ($matInconnue > 1 ? 's' : '') . ' (tickets non lus)' : ($matEstimee > 0 ? 'recettes vendues sur ' . $matEstimee . ' heure' . ($matEstimee > 1 ? 's' : '') . ' que le panel ne chiffre pas' : 'panel')],
        'periodes' => svPeriodes($heures),
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
            // Un jour lu pendant qu'il était en cours se relit : ses tickets
            // de l'après-midi manquaient.
            if (svGraveValide(setting('svP' . $sid . ':' . $j), 'p', $j)) { continue; }
            if ($cout >= $budget) { $restants++; continue; }
            $r = svProduitsJour($sid, $j, $cout, $budget);
            if ($r !== null) { $faits++; } else { $restants++; }
        }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'tickets' => $cout, 'joursRestants' => $restants,
        'etat' => $restants === 0 ? 'à jour' : $restants . ' jour(s)-magasin restants'];
}

/**
 * Les unités vendues aux clients pro, par produit, pour un jour déjà relevé
 * sans elles — [pid => unités], ou null si le jour n'est pas relevé ou si le
 * budget ne suffit pas. Seuls les tickets pro se relisent : la liste du jour
 * (un appel) dit lesquels, puis un appel par ticket pro — quelques-uns par jour,
 * pas les trois cents tickets du comptoir. Le relevé des produits garde son
 * horodatage : on n'y ajoute que `pb`.
 */
function svProduitsB2b(int $sid, string $j, int &$cout, int $budget): ?array
{
    $cle = 'svP' . $sid . ':' . $j;
    $c = setting($cle);
    if (!is_array($c) || !isset($c['p'])) { return null; }
    if (isset($c['pb']) && is_array($c['pb'])) { return $c['pb']; }
    if ($cout >= $budget || !PanelApi::configured()) { return null; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    $cout++;
    if (!is_array($liste)) { return null; }
    $ids = [];
    foreach (analyseListe($liste) as $t) {
        if ((int) ($t['id'] ?? 0) > 0 && !empty($t['is_client_b2b'])) { $ids[] = (int) $t['id']; }
    }
    if ($cout + count($ids) > $budget) { return null; }   // ce jour attendra le prochain lot
    $pb = [];
    foreach (array_chunk($ids, 40) as $lot) {
        $chemins = [];
        foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
        $res = PanelApi::getParallele($chemins, 8);
        foreach ($lot as $id) {
            $t = $res[$id] ?? null;
            if (!is_array($t)) { return null; }   // un ticket pro manquant : le jour ne se grave pas à moitié
            foreach ((array) ($t['products'] ?? []) as $l) {
                $pid = (int) ($l['id_product'] ?? 0);
                if ($pid > 0) { $pb[(string) $pid] = ($pb[(string) $pid] ?? 0.0) + (float) ($l['quantity'] ?? 0); }
            }
        }
    }
    $cout += count($ids);
    foreach ($pb as $pid => $q) { $pb[$pid] = round($q, 3); }
    $c['pb'] = (object) $pb;
    if (!isset($c['b']) && function_exists('vpDuListe')) { $c['b'] = vpDuListe($liste); }
    svGrave($cle, $c);
    return $pb;
}

/**
 * Au cron : compléter `pb` sur les jours relevés avant qu'on la lise, du plus
 * récent au plus ancien. Un jour-magasin coûte un appel plus un par ticket pro.
 */
function svB2bMoisson(int $budget = 250): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    try { $shops = array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $cout = 0; $faits = 0; $restants = 0;
    $hier = date('Y-m-d', strtotime('-1 day'));
    for ($j = $hier; $j >= SV_DEBUT; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        foreach ($shops as $sid) {
            $c = setting('svP' . $sid . ':' . $j);
            if (!is_array($c) || !isset($c['p']) || isset($c['pb'])) { continue; }
            if ($cout >= $budget) { $restants++; continue; }
            if (svProduitsB2b($sid, $j, $cout, $budget) !== null) { $faits++; } else { $restants++; }
        }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'appels' => $cout, 'joursRestants' => $restants];
}

/** Le battement horaire, accroché au cron des rapports. */
function svCron(): string
{
    $r = svMoisson();
    // Le pro des jours gravés avant sa lecture se complète au même battement.
    $pro = '';
    if (function_exists('vpMoisson')) { try { $vp = vpMoisson(); $pro = $vp['ok'] ? ' · pro : ' . $vp['joursFaits'] . ' jour(s) complétés, ' . $vp['joursRestants'] . ' restants' : ''; } catch (Throwable $e) { $pro = ' · pro : échec'; } }
    // Et la part pro produit par produit, que les rotations du comptoir retirent.
    try { $pb = svB2bMoisson(); $pro .= $pb['ok'] ? ' · produits pro : ' . $pb['joursFaits'] . ' jour(s) complétés, ' . $pb['joursRestants'] . ' restants' : ''; } catch (Throwable $e) { $pro .= ' · produits pro : échec'; }
    return ($r['ok'] ? ($r['joursFaits'] . ' jour(s) moissonnés, ' . $r['etat']) : ('échec : ' . ($r['motif'] ?? '?'))) . $pro;
}

/** POST /ventes/stats-moisson — forcer une passe, voir l'état. */
function wr_stats_ventes_moisson(): array
{
    return svMoisson((int) (body()['budget'] ?? SV_BUDGET_CRON));
}
