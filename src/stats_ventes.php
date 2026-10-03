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
    $mat = round((float) ($r['material_cost'] ?? 0), 2);
    $trav = round((float) ($r['employee_cost'] ?? 0), 2);
    return ['h' => $h, 'tickets' => (int) ($r['transactions_qty'] ?? 0), 'ca' => $ca, 'mat' => $mat,
        'trav' => $trav, 'poste' => (int) ($r['employee_qty'] ?? 0),
        'marge' => isset($r['total_margin']) ? round((float) $r['total_margin'], 2) : round($ca - $mat - $trav, 2)];
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
    return $out;
}

/**
 * Les produits d'un jour, heure par heure — {h: {pid: [nom, q, ventes, coût|null]}}.
 * Lit les tickets du jour si le jour n'est pas gravé ; null si le budget est
 * épuisé ou le panel muet (un jour à moitié lu ne se grave pas).
 */
function svProduitsJour(int $sid, string $j, int &$cout, int $budget): ?array
{
    $cle = 'svP' . $sid . ':' . $j;
    $c = setting($cle);
    if (svGraveValide($c, 'p', $j)) { return $c['p']; }
    if ($cout >= $budget) { return null; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    if (!is_array($liste)) { return null; }
    $ids = []; $pro = [];
    foreach (analyseListe($liste) as $t) {
        if ((int) ($t['id'] ?? 0) <= 0) { continue; }
        $ids[] = (int) $t['id'];
        if (!empty($t['is_client_b2b'])) { $pro[(int) $t['id']] = true; }
    }
    if ($cout + count($ids) > $budget && $cout > 0) { return null; }   // ce jour attendra le prochain lot
    $couts = catalogueCouts();
    // Le coût de CE magasin d'abord (products/available le chiffre pour chacun), la moyenne du réseau sinon.
    $coutsM = function_exists('coutsPanelMagasin') ? coutsPanelMagasin($sid) : [];
    $p = [];
    // La part des clients pro, produit par produit : elle ne passe pas par le
    // comptoir, les rotations du planogramme la retirent.
    $pb = [];
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
            }
        }
    }
    $cout += count($ids);
    foreach ($p as $h => $lst) { foreach ($lst as $pid => $x) { $p[$h][$pid] = [$x[0], round($x[1], 3), round($x[2], 2), $x[3] === null ? null : round($x[3], 2)]; } }
    foreach ($pb as $pid => $q) { $pb[$pid] = round($q, 3); }
    // Le pro du jour (tickets B2B) se grave avec les produits : la liste est déjà lue.
    // `pb` = les unités vendues aux clients pro, par produit ({} si aucune).
    $grave = ['quand' => time(), 'n' => count($ids), 'p' => $p, 'pb' => (object) $pb];
    if (function_exists('vpDuListe')) { $grave['b'] = vpDuListe($liste); }
    svGrave($cle, $grave);
    return $p;
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
