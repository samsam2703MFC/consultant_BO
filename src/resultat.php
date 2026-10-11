<?php
declare(strict_types=1);

/**
 * LE RÉSULTAT SUR UNE ÉTENDUE — la semaine ou le mois — face à l'objectif.
 *
 * L'objectif est posé au MOIS (le budget validé, à défaut le CA théorique de
 * l'étude). Pour dire à un gérant, un jeudi soir, « il vous manque 271
 * clients sur la semaine », il faut ramener ce budget au jour : chaque jour
 * de semaine porte la part que la pondération réseau lui donne (voir
 * ponderation.php), et un jour où le magasin n'ouvre jamais ne prend rien
 * aux autres. La semaine est donc la somme de ses jours, et une semaine à
 * cheval sur deux mois prend ses jours d'août dans le budget d'août et ses
 * jours de septembre dans celui de septembre — sans prorata plat.
 *
 * Trois chiffres se lisent ensemble : l'OBJECTIF de l'étendue, l'ATTENDU à ce
 * jour (les jours déjà passés, la journée en cours comprise) et le RÉALISÉ.
 * L'écart réalisé − attendu, divisé par le panier moyen du magasin, donne le
 * nombre de clients qui manquent — ou qui sont d'avance.
 *
 * Sans pondération adoptée, il n'y a pas d'objectif journalier : l'écran le
 * dit et renvoie vers le calcul, il n'invente pas une répartition plate.
 */

/** GET /exploitation/periode?vue=semaine|mois&date=YYYY-MM-DD — le calcul gardé (rcServi) : cinq minutes pour l'étendue en cours, une heure pour une étendue close. */
function ep_exploitation_periode(): array
{
    $auj = date('Y-m-d');
    $vue = (string) ($_GET['vue'] ?? 'semaine');
    if (!in_array($vue, ['semaine', 'mois'], true)) { $vue = 'semaine'; }
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $_GET['vue'] = $vue; $_GET['date'] = $date;
    $fin = $vue === 'semaine' ? date('Y-m-d', strtotime(date('Y-m-d', strtotime('monday this week', strtotime($date))) . ' +6 days')) : date('Y-m-t', strtotime($date));
    $n = $fin >= $auj ? 300 : 3600;
    rcFondDemande();
    return rcServi('exPer:' . $vue . ':' . $date, 'exPeriodeCalcul', static fn (array $r) => rcTtl($r, $n), !empty($_GET['rafraichir']), '/exploitation/periode');
}

/**
 * Le personnel et les frais généraux de chaque jour tels que le panel les alloue lui-même
 * (/shops/{id}/statistics/daily-summary : employee_cost = le coût du planning du jour, shop_cost =
 * les frais du mois répartis) — la même source que Résultat du jour. Un jour lu deux jours plus tard
 * se grave (le planning n'y bouge plus) ; les jours récents et aujourd'hui se relisent.
 * @return array<int, array<string, array{lab: ?float, oh: ?float}>>
 */
function resJoursPanel(array $ids, array $jours, string $auj): array
{
    $out = []; $paths = [];
    $clos = date('Y-m-d', strtotime($auj . ' -2 day'));
    foreach ($ids as $id) {
        foreach ($jours as $j) {
            if ($j > $auj) { continue; }
            $g = $j <= $clos ? setting('dsJ:' . $id . ':' . $j) : null;
            if (is_array($g) && array_key_exists('lab', $g)) { $out[$id][$j] = ['lab' => $g['lab'], 'oh' => $g['oh'] ?? null, 'ca' => $g['ca'] ?? null]; continue; }
            $paths[$id . '|' . $j] = '/shops/' . $id . '/statistics/daily-summary?date=' . $j;
        }
    }
    foreach ($paths === [] ? [] : PanelApi::getParallele($paths, 6) as $k => $r) {
        if (!is_array($r)) { continue; }
        [$id, $j] = explode('|', (string) $k); $id = (int) $id;
        $lab = nombreOuNull($r, ['employee_cost']); $oh = nombreOuNull($r, ['shop_cost']); $caJ = nombreOuNull($r, ['income']);
        $out[$id][$j] = ['lab' => $lab, 'oh' => $oh, 'ca' => $caJ];
        // Un jour clos se grave dès que le panel a répondu, même sans planning (11/10/2026) : un jour sans
        // planning (avant l'ouverture, ou jamais encodé) ne se relit pas à chaque appel.
        if ($j <= $clos && ($lab !== null || $caJ !== null)) {
            try { Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['dsJ:' . $id . ':' . $j, json_encode(['lab' => $lab, 'oh' => $oh, 'ca' => $caJ, 'le' => $auj])]); }
            catch (Throwable $e) { /* sans gravure : relu la prochaine fois */ }
        }
    }
    return $out;
}

/**
 * Le personnel et les frais généraux d'une période, jour par jour, tels que le panel les alloue
 * (daily-summary : employee_cost, shop_cost ; gravés par resJoursPanel) — les mois passés compris.
 * Mesuré le 11/10/2026 : le résumé quotidien les rend pour tout jour passé (14/03, 15/06, 15/09 à
 * Halle). $budget borne les lectures au panel (un jour non gravé = une lecture) ; ce qui manque
 * se lit au battement suivant du cron (resCoutsMoisson). `manque` = les jours encore sans chiffre.
 * @return array{lab: float, oh: float, jours: int, manque: int, sansPlanning: int}
 */
function resCoutsPeriode(int $id, string $du, string $au, int &$budget, string $auj): array
{
    $clos = date('Y-m-d', strtotime($auj . ' -2 day'));
    $fin = min($au, $auj);
    // Un mois entier et clos, complet : sa somme se garde (dsM), le tableau des mois ne relit plus ses trente jours.
    $moisEntier = substr($du, 8) === '01' && $au === date('Y-m-t', strtotime($du)) && $au <= $clos;
    $cleM = 'dsM:' . $id . ':' . substr($du, 0, 7);
    if ($moisEntier) { $gM = setting($cleM); if (is_array($gM) && isset($gM['lab'], $gM['jours'])) { return ['lab' => (float) $gM['lab'], 'oh' => (float) ($gM['oh'] ?? 0), 'jours' => (int) $gM['jours'], 'manque' => 0, 'sansPlanning' => (int) ($gM['sansPlanning'] ?? 0)]; } }
    $jours = []; $manque = 0;
    for ($d = $du; $d <= $fin; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $g = $d <= $clos ? setting('dsJ:' . $id . ':' . $d) : null;
        if (is_array($g) && array_key_exists('lab', $g)) { $jours[] = $d; continue; }
        if ($budget > 0) { $jours[] = $d; $budget--; } else { $manque++; }
    }
    $r = $jours === [] ? [] : (resJoursPanel([$id], $jours, $auj)[$id] ?? []);
    $lab = 0.0; $oh = 0.0; $n = 0; $sansPl = 0;
    foreach ($jours as $d) {
        $x = $r[$d] ?? null;
        if ($x === null || ($x['lab'] === null && ($x['ca'] ?? null) === null)) { $manque++; continue; }
        // Sans planning au panel : un jour sans vente (fermé, avant l'ouverture) compte 0 € ; un jour qui a
        // vendu laisse la main-d'œuvre du mois inconnue (`sansPlanning`).
        if ($x['lab'] === null && (float) ($x['ca'] ?? 0) > 0) { $sansPl++; }
        $lab += (float) ($x['lab'] ?? 0); $oh += (float) ($x['oh'] ?? 0); $n++;
    }
    if ($moisEntier && $manque === 0 && $n > 0) {
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cleM, json_encode(['lab' => round($lab, 2), 'oh' => round($oh, 2), 'jours' => $n, 'sansPlanning' => $sansPl, 'le' => $auj])]); }
        catch (Throwable $e) { /* sans gravure : la somme se refait */ }
    }
    return ['lab' => $lab, 'oh' => $oh, 'jours' => $n, 'manque' => $manque, 'sansPlanning' => $sansPl];
}

/**
 * Au cron : graver le personnel et les frais de chaque jour de l'année en cours, magasin par magasin,
 * du plus récent au plus ancien, $budget lectures au plus — le tableau « mois par mois » et le P&L
 * d'un mois passé les lisent ensuite sans appel.
 */
function resCoutsMoisson(int $budget = 400): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    $ids = [];
    foreach (analyseListe(PanelApi::consultantShops() ?? []) as $sh) { if ((int) ($sh['id'] ?? 0) > 0) { $ids[] = (int) $sh['id']; } }
    if ($ids === []) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $auj = date('Y-m-d'); $restants = 0; $lus = $budget;
    // L'année en cours puis la précédente : /stores/perf les montre toutes les deux.
    for ($t = strtotime(date('Y-m-01')); $t >= strtotime((date('Y') - 1) . '-01-01'); $t = strtotime('-1 month', $t)) {
        $du = date('Y-m-01', $t);
        foreach ($ids as $id) { $r = resCoutsPeriode($id, $du, date('Y-m-t', $t), $budget, $auj); $restants += $r['manque']; }
    }
    return ['ok' => true, 'lectures' => $lus - $budget, 'joursRestants' => $restants];
}

/**
 * Le P&L mensuel du panel (11/10/2026) : /consultant/shops/{id}/pnl/monthly?from=AAAA-MM&to=AAAA-MM (24 mois au plus,
 * mesuré : sans `from`/`to` la route répond 422). Personnel (`labour`) et frais généraux (`overhead`, la somme des
 * frais du magasin au panel, /shops/{id}/owner/costs) de chaque mois, en un appel par magasin. Le poste matière y
 * vaut 0 (le panel ne le chiffre pas au mois) : le food cost vient de la heatmap. Recoupé à Halle avec le résumé
 * quotidien additionné jour par jour : septembre 9 477,50 € contre 9 458 € de personnel, 13 042,65 € de frais des deux
 * côtés. Gardé dans `pnlM:{shop}` ; le mois en cours se relit passée l'heure.
 * @return array<string, array{ca: ?float, lab: ?float, oh: ?float}> ['AAAA-MM' => …]
 */
function resPnlMensuel(int $sid, string $de, string $a): array
{
    $cle = 'pnlM:' . $sid;
    $g = setting($cle);
    $g = is_array($g) && is_array($g['m'] ?? null) ? $g : ['m' => [], 'quand' => 0];
    $moisC = date('Y-m');
    $relire = false;
    for ($m = $de; $m <= $a; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        if (!isset($g['m'][$m]) || ($m >= $moisC && (int) ($g['quand'] ?? 0) < time() - 3600)) { $relire = true; break; }
    }
    if ($relire && class_exists('PanelApi') && PanelApi::configured()) {
        // Une année par appel : mesuré le 11/10/2026, janvier 2025 → octobre 2026 d'un coup ne répond pas pour trois
        // magasins sur quatre, une année à la fois répond pour tous.
        $chemins = [];
        for ($y = (int) substr($de, 0, 4); $y <= (int) substr($a, 0, 4); $y++) {
            $chemins[$y] = '/consultant/shops/' . $sid . '/pnl/monthly?' . http_build_query(['from' => max($de, $y . '-01'), 'to' => min($a, $y . '-12')]);
        }
        $lu = false;
        foreach (PanelApi::getParallele($chemins, 4) as $yR => $r) {
            if (!is_array($r) || !is_array($r['months'] ?? null)) { continue; }
            $lu = true;
            // Les mois de l'année que le panel ne rend pas (avant l'ouverture) se notent vides : ils ne se redemandent pas.
            for ($m = max($de, $yR . '-01'); $m <= min($a, $yR . '-12'); $m = date('Y-m', strtotime($m . '-01 +1 month'))) { $g['m'][$m] ??= ['ca' => null, 'lab' => null, 'oh' => null]; }
            foreach ($r['months'] as $x) {
                $m = is_array($x) ? substr((string) ($x['month'] ?? ''), 0, 7) : '';
                if (!preg_match('/^\d{4}-\d{2}$/', $m)) { continue; }
                $g['m'][$m] = ['ca' => nombreOuNull($x, ['turnover']), 'lab' => nombreOuNull($x, ['labour']), 'oh' => nombreOuNull($x, ['overhead'])];
            }
        }
        if ($lu) {
            $g['quand'] = time();
            try { Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($g)]); }
            catch (Throwable $e) { /* sans gravure : relu la prochaine fois */ }
        }
    }
    return $g['m'];
}

/**
 * GET /exploitation/couts/sonde?shop=4[&mois=2026-09] (11/10/2026) — les routes de coûts du panel, en lecture seule :
 * leur contrat Swagger (résumé, paramètres, champs de la réponse) et ce qu'elles rendent pour un magasin
 * (/costs, /costs/{id}, /shops/{id}/costs, /shops/costs/{id}, /shops/{id}/owner/costs, /consultant/shops/{id}/pnl/monthly,
 * /consultant/shops/pnl/monthly, /consultant/shops/pnl-summary). Ni mot de passe, ni jeton, ni coordonnées.
 */
function ep_couts_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 4);
    $mois = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? (string) $_GET['mois'] : date('Y-m', strtotime('first day of last month'));
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    @set_time_limit(90);
    $propre = static function ($v, int $n = 0) use (&$propre) {
        if (!is_array($v)) { return $v; }
        $o = []; $i = 0;
        foreach ($v as $k => $x) {
            if (is_string($k) && preg_match('/pass|secret|token|mail|phone|iban|bic|bank|street|address|zip|first_?name|last_?name|surname|login|contact|birth|national/i', $k)) { continue; }
            if (array_is_list($v) && ++$i > 15) { $o[] = '… ' . (count($v) - 15) . ' de plus'; break; }
            $o[$k] = is_array($x) ? ($n > 5 ? '[…]' : $propre($x, $n + 1)) : $x;
        }
        return $o;
    };
    // 1. Le contrat Swagger des routes de coûts.
    $doc = PanelApi::sondeGet('/../swagger/openapi.json', 25);
    $spec = is_array($doc['corps'] ?? null) ? $doc['corps'] : [];
    $schemas = is_array($spec['components']['schemas'] ?? null) ? $spec['components']['schemas'] : [];
    $champs = static function ($sch, int $n = 0) use (&$champs, $schemas) {
        if (!is_array($sch) || $n > 3) { return null; }
        if (isset($sch['$ref']) && preg_match('#/([^/]+)$#', (string) $sch['$ref'], $m)) { return [$m[1] => $champs($schemas[$m[1]] ?? null, $n + 1)]; }
        if (($sch['type'] ?? '') === 'array') { return ['[]' => $champs($sch['items'] ?? null, $n + 1)]; }
        if (isset($sch['properties']) && is_array($sch['properties'])) { $o = []; foreach ($sch['properties'] as $k => $p) { $o[$k] = is_array($p) && (isset($p['properties']) || isset($p['$ref']) || ($p['type'] ?? '') === 'array') ? $champs($p, $n + 1) : ($p['type'] ?? '?'); } return $o; }
        foreach (['allOf', 'oneOf', 'anyOf'] as $c) { if (isset($sch[$c]) && is_array($sch[$c])) { return array_map(static fn ($x) => $champs($x, $n + 1), $sch[$c]); } }
        return $sch['type'] ?? null;
    };
    $out = ['shop' => $sid, 'mois' => $mois, 'swagger' => []];
    foreach ((array) ($spec['paths'] ?? []) as $ch => $ops) {
        if (!preg_match('#/costs|pnl/monthly|pnl-summary|owner/costs#', (string) $ch) || !is_array($ops)) { continue; }
        foreach ($ops as $m => $op) {
            if (!is_array($op)) { continue; }
            $rep = $op['responses']['200']['content']['application/json']['schema'] ?? null;
            $out['swagger'][strtoupper((string) $m) . ' ' . $ch] = ['resume' => $op['summary'] ?? ($op['description'] ?? null),
                'parametres' => array_map(static fn ($p) => is_array($p) ? ($p['name'] ?? '?') . ' (' . ($p['in'] ?? '?') . (!empty($p['required']) ? ', requis' : '') . ')' : $p, (array) ($op['parameters'] ?? [])),
                'corps' => isset($op['requestBody']) ? $champs($op['requestBody']['content']['application/json']['schema'] ?? null) : null,
                'reponse' => $champs($rep)];
        }
    }
    // 2. Ce que les routes de lecture rendent pour ce magasin (GET seulement).
    $routes = ['costs' => '/costs', 'shop-costs' => '/shops/' . $sid . '/costs', 'shop-costs-mois' => '/shops/' . $sid . '/costs?month=' . $mois,
        'costs-shop' => '/shops/costs/' . $sid, 'owner-costs' => '/shops/' . $sid . '/owner/costs',
        'pnl-mensuel' => '/consultant/shops/' . $sid . '/pnl/monthly?month=' . $mois, 'pnl-mensuel-date' => '/consultant/shops/' . $sid . '/pnl/monthly?date=' . $mois . '-15',
        'pnl-mensuel-reseau' => '/consultant/shops/pnl/monthly?month=' . $mois, 'pnl-resume' => '/consultant/shops/pnl-summary',
        // Les variantes de période (11/10/2026) : le mois demandé, et le P&L mensuel sur une plage.
        'owner-period' => '/shops/' . $sid . '/owner/costs?period=' . $mois, 'owner-month' => '/shops/' . $sid . '/owner/costs?month=' . $mois,
        'owner-date' => '/shops/' . $sid . '/owner/costs?date=' . $mois . '-15', 'owner-from' => '/shops/' . $sid . '/owner/costs?from=' . $mois . '-01&to=' . date('Y-m-t', strtotime($mois . '-01')),
        'shop-costs-period' => '/shops/' . $sid . '/costs?period=' . $mois, 'shop-costs-from' => '/shops/' . $sid . '/costs?date_from=' . $mois . '-01&date_to=' . date('Y-m-t', strtotime($mois . '-01')),
        'pnl-mensuel-ym' => '/consultant/shops/' . $sid . '/pnl/monthly?from=' . substr($mois, 0, 4) . '-01&to=' . $mois,
        'pnl-mensuel-dates' => '/consultant/shops/' . $sid . '/pnl/monthly?from=' . substr($mois, 0, 4) . '-01-01&to=' . date('Y-m-t', strtotime($mois . '-01')),
        'pnl-mensuel-dfdt' => '/consultant/shops/' . $sid . '/pnl/monthly?date_from=' . substr($mois, 0, 4) . '-01-01&date_to=' . date('Y-m-t', strtotime($mois . '-01')),
        'pnl-mensuel-start' => '/consultant/shops/' . $sid . '/pnl/monthly?start=' . substr($mois, 0, 4) . '-01&end=' . $mois];
    $out['routes'] = [];
    foreach ($routes as $nom => $ch) {
        $r = PanelApi::sondeGet($ch, 20);
        $lst = analyseListe($r['corps'] ?? null); if ($lst === [] && is_array($r['corps']['items'] ?? null)) { $lst = $r['corps']['items']; }
        $dates = array_values(array_filter(array_map(static fn ($x) => is_array($x) ? substr((string) ($x['date'] ?? ''), 0, 7) : '', $lst)));
        $out['routes'][$nom] = ['chemin' => $ch, 'code' => $r['code'] ?? null, 'erreur' => isset($r['erreur']) ? mb_substr((string) $r['erreur'], 0, 200) : null,
            'n' => $lst !== [] ? count($lst) : null, 'moisVus' => $dates !== [] ? array_count_values($dates) : null, 'corps' => $propre($r['corps'] ?? null)];
    }
    // 3. Une ligne de coût par identifiant, la première de la liste.
    $l = analyseListe($out['routes']['costs']['corps'] ?? null);
    $id = is_array($l[0] ?? null) ? ($l[0]['id'] ?? null) : null;
    if ($id !== null) { $r = PanelApi::sondeGet('/costs/' . rawurlencode((string) $id), 20); $out['routes']['cost-' . $id] = ['chemin' => '/costs/' . $id, 'code' => $r['code'] ?? null, 'corps' => $propre($r['corps'] ?? null)]; }
    return $out;
}

function exPeriodeCalcul(): array
{
    $auj  = date('Y-m-d');
    $vue  = (string) ($_GET['vue'] ?? 'semaine');
    if (!in_array($vue, ['semaine', 'mois'], true)) { $vue = 'semaine'; }
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $ts = strtotime($date);
    if ($vue === 'semaine') {
        $du = date('Y-m-d', strtotime('monday this week', $ts));
        $au = date('Y-m-d', strtotime($du . ' +6 days'));
    } else {
        $du = date('Y-m-01', $ts);
        $au = date('Y-m-t', $ts);
    }
    // Le dernier jour qui a pu vendre : aujourd'hui au plus tard. Une semaine
    // future n'existe pas, une semaine passée est close.
    $jusqua = min($au, $auj);

    $seuils = ['food' => 32.0, 'labour' => 33.0, 'overhead' => 13.5];
    try {
        foreach (Db::rows("SELECT code, seuil_bas, seuil_haut FROM kpi
                            WHERE code IN ('food','labour','overhead')") as $k) {
            $seuils[(string) $k['code']] = $k['seuil_haut'] !== null
                ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
        }
    } catch (PDOException $e) { /* seuils par défaut */ }

    $pj = pjPoids();
    $out = ['vue' => $vue, 'date' => $date, 'du' => $du, 'au' => $au, 'aujourdhui' => $auj,
        'jusqua' => $jusqua, 'enCours' => $au >= $auj, 'seuils' => $seuils,
        'ponderation' => ['ok' => $pj !== null, 'adopteLe' => $pj['adopteLe'] ?? null,
            'motif' => $pj === null ? 'Aucune pondération des jours adoptée : les objectifs du jour et de la semaine ne se calculent pas. Calculez-la dans Paramètres du budget.' : null],
        'magasins' => [], 'reseau' => null,
        'source' => 'API panel — ventes et marge brute mesurées par jour ; main-d’œuvre et frais généraux du mois courant répartis par jour d’ouverture'];
    if (!PanelApi::configured()) {
        return $out + ['indispo' => true, 'motif' => 'compte consultant non configuré (Mon compte)'];
    }
    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    } catch (PDOException $e) {
        $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name");
    }
    if (!$shops) { return $out + ['indispo' => true, 'motif' => 'aucun magasin actif']; }

    // Les BUDGETS des mois que l'étendue touche : un par (magasin, mois).
    $moisTouches = [];
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $moisTouches[substr($d, 0, 7)] = true; }
    $budgets = [];
    foreach (array_keys($moisTouches) as $ym) {
        try {
            foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf
                                WHERE year = ? AND month = ?', [(int) substr($ym, 0, 4), (int) substr($ym, 5, 2)]) as $b) {
                $v = null; $src = null;
                if ($b['revenue_budget'] !== null && (float) $b['revenue_budget'] > 0) { $v = (float) $b['revenue_budget']; $src = 'budget'; }
                elseif ($b['ca_theorique'] !== null && (float) $b['ca_theorique'] > 0) { $v = (float) $b['ca_theorique']; $src = 'theorique'; }
                if ($v !== null) { $budgets[(string) $b['shop_id']][$ym] = ['montant' => $v, 'source' => $src]; }
            }
        } catch (PDOException $e) { /* pas de budget : pas d'objectif */ }
    }

    // Les ventes par jour : l'étendue, précédée de quatre semaines pour savoir
    // quels jours de la semaine chaque magasin ouvre. `margin-heatmap` ne
    // rend pas plus d'un mois : on découpe.
    $lectDu = date('Y-m-d', strtotime($du . ' -28 days'));
    $fenetres = [];
    for ($cur = $lectDu; $cur <= $jusqua;) {
        $fin = date('Y-m-d', min(strtotime($cur . ' +30 days'), strtotime($jusqua)));
        $fenetres[] = [$cur, $fin];
        $cur = date('Y-m-d', strtotime($fin . ' +1 day'));
    }
    $moisCourant = substr($auj, 0, 7);
    $paths = [];
    foreach ($shops as $s) {
        $id = (int) $s['id'];
        foreach ($fenetres as $k => [$d1, $d2]) {
            $paths['hm' . $id . '_' . $k] = '/consultant/shops/' . $id . '/margin-heatmap?' . http_build_query(['from' => $d1, 'to' => $d2]);
        }
        // Le P&L mensuel du panel ne connaît que le mois COURANT (mesuré : la
        // date transmise est ignorée). Il ne sert qu'aux jours de ce mois-là.
        if (isset($moisTouches[$moisCourant])) {
            $paths['pnlM' . $id] = '/consultant/shops/' . $id . '/pnl?period=month&date=' . $auj;
            // Les frais généraux DU JOUR tels que le panel les alloue lui-même
            // (daily-summary.shop_cost) : la même source que Résultat du jour.
            // Mesuré : /pnl?period=month rend overhead = 0 sur deux magasins
            // là où l'allocation quotidienne est bien là — sans elle, le
            // résultat de la semaine se lisait sans frais généraux.
            $paths['ds' . $id] = '/shops/' . $id . '/statistics/daily-summary?date=' . $auj;
        }
    }
    $res = PanelApi::getParallele($paths, 6);
    // Le personnel et les frais de CHAQUE jour, tels que le panel les alloue (10/10/2026) : toute semaine,
    // tout mois. 11/10/2026 : les mois passés aussi — le résumé quotidien les rend pour tout jour passé,
    // un jour clos se grave (resJoursPanel) et le cron les remplit d'avance (resCoutsMoisson).
    $joursDs = [];
    for ($d = $du; $d <= $jusqua; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $joursDs[] = $d; }
    $dsJ = resJoursPanel(array_map(static fn ($s) => (int) $s['id'], $shops), $joursDs, $auj);
    // Les clients pro de l'étendue : le relevé de chaque jour (gravé par la
    // moisson ou le dashboard), complété par une trentaine de listes lues en
    // parallèle. Un jour non lu se dit — le comptoir ne s'en déduit pas.
    $joursE = [];
    for ($d = $du; $d <= $jusqua; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $joursE[] = $d; }
    $proJ = function_exists('vpJoursLus') ? vpJoursLus(array_map(static fn ($s) => (int) $s['id'], $shops), $joursE, 30) : null;

    $joursMoisC = (int) date('t', strtotime($auj));
    $premierC   = new DateTimeImmutable(date('Y-m-01', strtotime($auj)));
    // Les invendus de l'étendue : une lecture par magasin sur la fenêtre, le coût de
    // production net des pièces jetées se retranche du résultat.
    $invM = function_exists('invCoutsMagasins') ? invCoutsMagasins(array_map(static fn ($s) => (int) $s['id'], $shops), $du, $jusqua) : [];
    $lignes = [];
    foreach ($shops as $s) {
        $id = (int) $s['id']; $nom = (string) $s['name'];
        $parJour = []; $repondu = false;
        foreach (array_keys($fenetres) as $k) {
            $hm = $res['hm' . $id . '_' . $k] ?? null;
            if (!is_array($hm) || !isset($hm['days'])) { continue; }
            $repondu = true;
            foreach ((array) $hm['days'] as $d) {
                $j = (string) ($d['date'] ?? '');
                if ($j === '') { continue; }
                $ca = (float) ($d['ca'] ?? 0);
                // La marge du panel ne vaut que si son coût est complet ; sinon la journée se
                // recompose depuis les tickets gravés (svMatiereJour), jamais zéro.
                $mbConnu = isset($d['margin_value']) && is_numeric($d['margin_value']) && empty($d['cost_unknown']);
                $parJour[$j] = ['ca' => $ca, 'mb' => $mbConnu ? (float) $d['margin_value'] : null, 'mbConnu' => $mbConnu,
                    'tickets' => (int) ($d['tickets'] ?? 0), 'ouvert' => !empty($d['has_data']) && $ca > 0];
            }
        }
        if (!$repondu) {
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => false,
                'motif' => 'margin-heatmap sans réponse pour ce magasin'];
            continue;
        }
        // Les jours de semaine où ce magasin ouvre : vus actifs au moins une
        // fois sur les cinq semaines lues.
        $wdOuverts = [];
        foreach ($parJour as $j => $d) { if ($d['ouvert']) { $wdOuverts[(int) date('N', strtotime($j))] = true; } }

        // Le dénominateur de chaque mois touché : la somme des poids de ses
        // jours OUVERTS. C'est lui qui fait qu'un mois à cinq dimanches ne
        // vaut pas un mois à quatre, et qu'un lundi fermé ne pèse rien.
        $denom = [];
        foreach (array_keys($moisTouches) as $ym) {
            $n = (int) date('t', strtotime($ym . '-01')); $t = 0.0;
            for ($i = 1; $i <= $n; $i++) {
                $wd = (int) date('N', strtotime(sprintf('%s-%02d', $ym, $i)));
                if ($pj !== null && isset($wdOuverts[$wd])) { $t += $pj['poids'][$wd]; }
            }
            $denom[$ym] = $t;
        }

        // La main-d'œuvre et les frais généraux : le mois courant réparti par
        // jour d'ouverture — le même diviseur que Résultat du jour.
        $joursOuvertsC = 0;
        for ($i = 0; $i < $joursMoisC; $i++) {
            if (isset($wdOuverts[(int) $premierC->modify('+' . $i . ' days')->format('N')])) { $joursOuvertsC++; }
        }
        $pnlM = $res['pnlM' . $id] ?? null;
        $labourMois = is_array($pnlM) ? nombreOuNull((array) ($pnlM['labour'] ?? []), ['value', 'amount']) : null;
        $ohMois = is_array($pnlM) ? nombreOuNull((array) ($pnlM['overhead']['breakdown']['month'] ?? []), ['value', 'amount']) : null;
        if (($ohMois === null || $ohMois <= 0) && is_array($pnlM)) {
            $v = nombreOuNull((array) ($pnlM['overhead'] ?? []), ['value', 'amount']);
            if ($v !== null && $v > 0) { $ohMois = $v; }
        }
        // Le personnel de /pnl?period=month est le CUMUL du 1er à aujourd'hui (mesuré le 10/10/2026) :
        // en repli, il se répartit sur les jours ouverts ÉCOULÉS, pas sur tout le mois.
        $joursOuvertsAuj = 0;
        for ($i = 0; $i < (int) date('j', strtotime($auj)); $i++) {
            if (isset($wdOuverts[(int) $premierC->modify('+' . $i . ' days')->format('N')])) { $joursOuvertsAuj++; }
        }
        $labJ = ($labourMois !== null && $joursOuvertsAuj > 0) ? $labourMois / $joursOuvertsAuj : null;
        $ohJ  = ($ohMois !== null && $ohMois > 0 && $joursOuvertsC > 0) ? $ohMois / $joursOuvertsC : null;
        $ds = $res['ds' . $id] ?? null;
        $dsOh = is_array($ds) ? nombreOuNull($ds, ['shop_cost']) : null;
        if ($dsOh !== null && $dsOh > 0) { $ohJ = $dsOh; }
        // Deux magasins n'ont AUCUN frais général encodé au panel (mesuré :
        // /pnl et daily-summary rendent 0). Résultat du jour les compte à
        // zéro et le dit ; on fait pareil ici, sinon la semaine afficherait
        // « sans réponse » là où la journée affiche un résultat.
        $ohManque = $ohJ === null && $labJ !== null;
        if ($ohManque) { $ohJ = 0.0; }

        $jours = []; $objectif = 0.0; $attendu = 0.0; $prevu = 0.0; $realise = 0.0; $tickets = 0; $mb = 0.0;
        $caMb = 0.0; $matEstimes = 0; $matInconnus = 0;   // la matière : le CA des jours qui la portent, les jours recomposés, les jours sans tickets
        $labour = 0.0; $oh = 0.0; $joursOuvertsPasses = 0; $joursHorsMoisC = 0; $sansBudget = [];
        $labMesures = 0; $joursSansCout = 0;
        $objAucun = true; $src = null; $ouvertUnJour = false;
        $pro = ['ca' => 0.0, 'tk' => 0, 'lus' => 0, 'jours' => 0];
        for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $ym = substr($d, 0, 7); $wd = (int) date('N', strtotime($d));
            $b = $budgets[(string) $id][$ym] ?? null;
            $ferme = !isset($wdOuverts[$wd]);
            $obj = null;
            if ($b !== null && $pj !== null && !$ferme && $denom[$ym] > 0) {
                $obj = $b['montant'] * $pj['poids'][$wd] / $denom[$ym];
                $objAucun = false;
                $src = ($src === 'theorique' || $b['source'] === 'theorique') ? 'theorique' : 'budget';
            } elseif ($b === null && !$ferme) { $sansBudget[$ym] = true; }
            $passe = $d <= $jusqua;
            $x = $parJour[$d] ?? null;
            $ouvert = $passe && $x !== null && $x['ouvert'];
            if ($ouvert) { $ouvertUnJour = true; }
            $ligne = ['date' => $d, 'jour' => $wd, 'court' => PJ_COURTS[$wd] . ' ' . (int) substr($d, 8, 2),
                'objectif' => $obj !== null ? round($obj, 2) : null, 'ferme' => $ferme, 'passe' => $passe,
                'ca' => $ouvert ? round($x['ca'], 2) : null, 'tickets' => $ouvert ? $x['tickets'] : null,
                'aujourdhui' => $d === $auj];
            if ($obj !== null) { $objectif += $obj; if ($passe) { $attendu += $obj; } else { $prevu += $obj; } }
            if ($ouvert) {
                $realise += $x['ca']; $tickets += $x['tickets'];
                $mbJ = $x['mb'];
                if (!$x['mbConnu'] && function_exists('svMatiereJour')) {
                    $zeroM = 0; $eM = svMatiereJour($id, $d, $zeroM, 0);
                    if ($eM !== null) { $mbJ = $x['ca'] - (float) $eM['estime']; $matEstimes++; }
                }
                if ($mbJ !== null) { $mb += $mbJ; $caMb += $x['ca']; } else { $matInconnus++; }
                $pro['jours']++;
                $bP = $proJ[$id][$d] ?? null;
                if ($bP !== null) { $pro['lus']++; $pro['tk'] += count($bP['t']); foreach ($bP['t'] as $tP) { $pro['ca'] += (float) $tP[2]; } }
                // Le personnel du jour : le coût du planning que le panel alloue à CE jour
                // (daily-summary.employee_cost). Mesuré le 10/10/2026 : /pnl?period=month rend le
                // personnel CUMULÉ du 1er à aujourd'hui (3 898,84 € au 10 octobre pour Halle) ;
                // le répartir sur les 31 jours d'octobre donnait 1 257,69 € au lieu de ~3 900 €.
                // La répartition du mois ne sert plus qu'en repli, au mois courant.
                $dj = $dsJ[$id][$d] ?? null;
                $labD = $dj['lab'] ?? null; $ohD = $dj['oh'] ?? null;
                if ($labD !== null) { $labMesures++; }
                if ($ym === $moisCourant) {
                    $joursOuvertsPasses++;
                    $labD ??= $labJ; $ohD ??= $ohJ;
                } else { $joursHorsMoisC++; }
                if ($labD !== null && $ohD !== null) { $labour += $labD; $oh += $ohD; } else { $joursSansCout++; }
            }
            $jours[] = $ligne;
        }
        if (!$ouvertUnJour && $jusqua >= $du) {
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => false,
                'motif' => 'aucune vente sur la période', 'jours' => $jours,
                'objectif' => $objAucun ? null : round($objectif, 2)];
            continue;
        }
        // Le coût matière de la période : le taux des jours dont la marge est connue ou recomposée,
        // appliqué à tout le CA ; inconnu si aucun jour ne le porte.
        $matSrc = $matInconnus === 0 ? ($matEstimes > 0 ? 'recettes vendues sur ' . $matEstimes . ' jour' . ($matEstimes > 1 ? 's' : '') : 'panel')
            : ($caMb > 0 ? 'taux de ' . ($pro['jours'] - $matInconnus) . ' jour' . ($pro['jours'] - $matInconnus > 1 ? 's' : '') . ' sur ' . $pro['jours'] . ', les autres sans tickets lus' : 'coût matière inconnu : tickets non lus');
        if ($caMb > 0) { $fc = $realise * ($caMb - $mb) / $caMb; $mb = $realise - $fc; }
        else { $fc = null; $mb = null; }
        // Le résultat n'est complet que si chaque jour vendu a sa main-d'œuvre
        // et ses frais : hors du mois courant, le panel ne les rend pas.
        $netOk = $mb !== null && $joursSansCout === 0 && ($joursOuvertsPasses + $joursHorsMoisC) > 0;
        $inv = $invM[$id] ?? ['cout' => null, 'pieces' => null, 'declare' => null, 'source' => 'panel muet'];
        $net = $netOk ? $mb - ($inv['cout'] ?? 0.0) - $labour - $oh : null;
        $panier = $tickets > 0 ? $realise / $tickets : null;
        $ecart = $objAucun ? null : $realise - $attendu;
        $pct = static fn (?float $v) => ($v !== null && $realise > 0) ? round($v / $realise * 100, 1) : null;
        $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => true,
            'objectif' => $objAucun ? null : round($objectif, 2),
            'objectifSource' => $src,
            'sansBudget' => array_keys($sansBudget),
            'attendu' => $objAucun ? null : round($attendu, 2),
            'prevu' => $objAucun ? null : round($prevu, 2),          // ce que la pondération attend encore
            'realise' => round($realise, 2),
            'reste' => $objAucun ? null : round($objectif - $realise, 2),
            'ecart' => $ecart !== null ? round($ecart, 2) : null,
            'atteinte' => (!$objAucun && $attendu > 0) ? round($realise / $attendu, 4) : null,
            'tickets' => $tickets,
            'panier' => $panier !== null ? round($panier, 2) : null,
            // Positif : il manque des clients. Négatif : ils sont d'avance.
            'clientsManquants' => ($ecart !== null && $panier !== null && $panier > 0) ? (int) round(-$ecart / $panier) : null,
            'coutMatiere' => $fc !== null ? round($fc, 2) : null, 'coutMatierePct' => $pct($fc), 'coutMatiereSource' => $matSrc,
            'margeBrute' => $mb !== null ? round($mb, 2) : null, 'margeBrutePct' => $pct($mb),
            'invendus' => $inv['cout'] !== null ? round((float) $inv['cout'], 2) : null, 'invendusPct' => $inv['cout'] !== null ? $pct((float) $inv['cout']) : null,
            'invendusPieces' => $inv['pieces'], 'invendusDeclare' => $inv['declare'], 'invendusSource' => $inv['source'],
            'labour' => $netOk ? round($labour, 2) : null, 'labourPct' => $netOk ? $pct($labour) : null,
            'labourSource' => $netOk ? ($labMesures === $pro['jours'] ? 'planning du panel, jour par jour' : ($labMesures > 0 ? 'planning du panel sur ' . $labMesures . ' jour' . ($labMesures > 1 ? 's' : '') . ', le reste réparti' : 'mois du panel réparti par jour d’ouverture')) : null,
            'overhead' => $netOk ? round($oh, 2) : null, 'overheadPct' => $netOk ? $pct($oh) : null,
            'net' => $net !== null ? round($net, 2) : null, 'netPct' => $pct($net),
            'motifNet' => $netOk ? ($ohManque ? 'frais généraux absents du panel pour ce magasin — résultat avant frais généraux' : null) : ($mb === null ? 'coût matière inconnu — le panel ne le chiffre pas et les tickets ne sont pas lus' : ($joursHorsMoisC > 0
                ? 'main-d’œuvre et frais généraux connus pour le mois courant seulement'
                : 'P&L mensuel sans réponse — main-d’œuvre ou frais généraux indisponibles')),
            'joursOuverts' => array_keys($wdOuverts),
            'jours' => $jours] + resProSplit($proJ === null ? null : $pro, $realise, $tickets);
        // Les clients manquants se comptent au comptoir : (CA comptoir − attendu)
        // ÷ panier comptoir, le CA pro exclu — quand tous les jours sont lus.
        if (!$objAucun && function_exists('vpClientsComptoir')) {
            $iL = count($lignes) - 1;
            $lignes[$iL] = array_merge($lignes[$iL], vpClientsComptoir($lignes[$iL], (float) $attendu));
        }
    }
    usort($lignes, static fn ($a, $b) => ($b['realise'] ?? -1) <=> ($a['realise'] ?? -1));
    $out['magasins'] = $lignes;

    // Réseau : la somme de ce qui est connu. Un magasin sans objectif ne
    // compte pas pour zéro dans l'objectif — la ligne le dit.
    $t = ['objectif' => 0.0, 'attendu' => 0.0, 'prevu' => 0.0, 'realise' => 0.0, 'tickets' => 0, 'fc' => 0.0,
        'mb' => 0.0, 'labour' => 0.0, 'oh' => 0.0, 'net' => 0.0, 'inv' => 0.0, 'invPieces' => 0.0];
    $nObj = 0; $nOuv = 0; $netComplet = true; $realiseObj = 0.0; $invLus = 0; $invDeclarent = 0;
    $parJourR = [];
    $proR = ['ca' => 0.0, 'tk' => 0, 'lus' => 0, 'jours' => 0]; $proAucun = true;
    foreach ($lignes as $l) {
        if (empty($l['ouvert'])) { continue; }
        $nOuv++;
        if (array_key_exists('proJours', $l) && $l['proJours'] !== null) {
            $proAucun = false; $proR['jours'] += $l['proJours']; $proR['lus'] += $l['proJoursLus'];
            $proR['ca'] += (float) ($l['caPro'] ?? 0); $proR['tk'] += (int) ($l['ticketsPro'] ?? 0);
        }
        $t['realise'] += $l['realise']; $t['tickets'] += $l['tickets']; $t['fc'] += (float) ($l['coutMatiere'] ?? 0); $t['mb'] += (float) ($l['margeBrute'] ?? 0);
        if (($l['invendus'] ?? null) !== null) { $invLus++; $t['inv'] += (float) $l['invendus']; $t['invPieces'] += (float) ($l['invendusPieces'] ?? 0); if (!empty($l['invendusDeclare'])) { $invDeclarent++; } }
        if ($l['objectif'] !== null) {
            $nObj++; $t['objectif'] += $l['objectif']; $t['attendu'] += $l['attendu']; $t['prevu'] += $l['prevu']; $realiseObj += $l['realise'];
        }
        if ($l['net'] === null) { $netComplet = false; }
        else { $t['labour'] += $l['labour']; $t['oh'] += $l['overhead']; $t['net'] += $l['net']; }
        foreach ($l['jours'] as $j) {
            $e = $parJourR[$j['date']] ?? ['date' => $j['date'], 'court' => $j['court'], 'objectif' => 0.0, 'ca' => 0.0, 'passe' => $j['passe'], 'aujourdhui' => $j['aujourdhui'], 'aObjectif' => false];
            if ($j['objectif'] !== null) { $e['objectif'] += $j['objectif']; $e['aObjectif'] = true; }
            if ($j['ca'] !== null) { $e['ca'] += $j['ca']; }
            $parJourR[$j['date']] = $e;
        }
    }
    // Les clients manquants du réseau : la somme de ceux de chaque magasin
    // (au comptoir quand son split est complet), pas l'écart réseau ÷ un panier.
    $cmR = null; $ecR = null; $bases = [];
    foreach ($lignes as $l) {
        if (empty($l['ouvert']) || ($l['objectif'] ?? null) === null || !isset($l['clientsManquants'])) { continue; }
        $cmR = ($cmR ?? 0) + (int) $l['clientsManquants'];
        if (isset($l['ecartComptoir'])) { $ecR = ($ecR ?? 0.0) + (float) $l['ecartComptoir']; }
        $bases[$l['clientsBase'] ?? 'total'] = true;
    }
    $cmBase = count($bases) === 1 ? (string) array_key_first($bases) : 'mixte';
    if ($ecR !== null) { $ecR = round($ecR, 2); }
    $ca = $t['realise'];
    $panierR = $t['tickets'] > 0 ? $ca / $t['tickets'] : null;
    $ecartR = $nObj ? $realiseObj - $t['attendu'] : null;
    $pctR = static fn (?float $v) => ($v !== null && $ca > 0) ? round($v / $ca * 100, 1) : null;
    $out['reseau'] = ['magasins' => $nOuv, 'magasinsAvecObjectif' => $nObj,
        'objectif' => $nObj ? round($t['objectif'], 2) : null,
        'attendu' => $nObj ? round($t['attendu'], 2) : null,
        'prevu' => $nObj ? round($t['prevu'], 2) : null,
        'realise' => round($ca, 2),
        'reste' => $nObj ? round($t['objectif'] - $realiseObj, 2) : null,
        'ecart' => $ecartR !== null ? round($ecartR, 2) : null,
        'atteinte' => ($nObj && $t['attendu'] > 0) ? round($realiseObj / $t['attendu'], 4) : null,
        'tickets' => $t['tickets'], 'panier' => $panierR !== null ? round($panierR, 2) : null,
        'clientsManquants' => $cmR !== null ? $cmR : (($ecartR !== null && $panierR) ? (int) round(-$ecartR / $panierR) : null),
        'clientsBase' => $cmR !== null ? $cmBase : 'total',
        'ecartComptoir' => $ecR,
        'coutMatiere' => round($t['fc'], 2), 'coutMatierePct' => $pctR($t['fc']),
        'margeBrute' => round($t['mb'], 2), 'margeBrutePct' => $pctR($t['mb']),
        'invendus' => $invLus ? round($t['inv'], 2) : null, 'invendusPct' => $invLus ? $pctR($t['inv']) : null,
        'invendusPieces' => $invLus ? round($t['invPieces'], 1) : null, 'invendusDeclare' => $invLus ? $invDeclarent > 0 : null,
        'invendusSource' => $invLus ? ($invDeclarent . ' magasin' . ($invDeclarent > 1 ? 's' : '') . ' sur ' . $nOuv . ' déclare' . ($invDeclarent > 1 ? 'nt' : '') . ' la poubelle') : 'panel muet',
        'labour' => $netComplet ? round($t['labour'], 2) : null, 'labourPct' => $netComplet ? $pctR($t['labour']) : null,
        'overhead' => $netComplet ? round($t['oh'], 2) : null, 'overheadPct' => $netComplet ? $pctR($t['oh']) : null,
        'net' => $netComplet ? round($t['net'], 2) : null, 'netPct' => $netComplet ? $pctR($t['net']) : null,
        'jours' => array_values(array_map(static fn ($e) => ['date' => $e['date'], 'court' => $e['court'],
            'objectif' => $e['aObjectif'] ? round($e['objectif'], 2) : null, 'ca' => $e['passe'] ? round($e['ca'], 2) : null,
            'passe' => $e['passe'], 'aujourdhui' => $e['aujourdhui']], $parJourR))]
        + resProSplit($proAucun ? null : $proR, $ca, (int) $t['tickets']);
    return $out;
}

/**
 * Le split pro / comptoir d'une étendue. Pro = la somme des tickets des clients
 * pro sur les jours LUS ; le comptoir = le reste du réalisé, seulement si tous
 * les jours ouverts sont lus — sinon il porterait le pro des jours manquants.
 */
function resProSplit(?array $p, float $realise, int $tickets): array
{
    if ($p === null) { return []; }
    $complet = $p['jours'] > 0 && $p['lus'] === $p['jours'];
    $caC = $realise - $p['ca']; $nC = $tickets - $p['tk'];
    return ['caPro' => $p['lus'] ? round($p['ca'], 2) : null, 'ticketsPro' => $p['lus'] ? $p['tk'] : null,
        'panierPro' => $p['tk'] > 0 ? round($p['ca'] / $p['tk'], 2) : null,
        'proJours' => $p['jours'], 'proJoursLus' => $p['lus'], 'proComplet' => $complet,
        'caComptoir' => $complet ? round(max(0.0, $caC), 2) : null,
        'ticketsComptoir' => $complet ? max(0, $nC) : null,
        'panierComptoir' => ($complet && $nC > 0) ? round($caC / $nC, 2) : null,
        'partPro' => ($complet && $realise > 0) ? round(100 * $p['ca'] / $realise, 1) : null];
}
