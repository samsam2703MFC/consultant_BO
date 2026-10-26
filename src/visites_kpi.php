<?php

declare(strict_types=1);

/**
 * KPI ET SANTÉ DU MAGASIN — l'étape des chiffres du contrôle, sur la tablette du consultant (26/10/2026).
 *
 * GET /visites/kpi/{shop} — trois lectures, chacune avec sa fenêtre et sa source :
 *
 *  - le CA PAR ETP des trois derniers mois clos : le CA du mois (relevé des ventes du panel, pvKpisMois) ÷ les ETP
 *    planifiés (heures du planning ÷ heuresEtpMois, ep_stores_etp — la règle de l'alerte CA/ETP des rapports), face au
 *    réseau (CA total ÷ ETP totaux des magasins qui ont les deux) et au seuil de la Table KPI (`ca-etp`). Un mois sans
 *    planning n'a pas d'ETP : il le dit, il ne le devine pas ;
 *  - la STRUCTURE DES VENTES par catégorie sur les trois derniers mois clos : la part de chaque catégorie dans le CA du
 *    magasin face à sa part dans le CA du reste du réseau (/consultant/shops/category-sales, un appel par mois pour
 *    tout le réseau, gravé une fois lu : les trois mois sont clos) ;
 *  - la STRUCTURE DU CA PAR CANAL sur les mêmes mois (26/10/2026) : comptoir, B2B (face à son plafond, 40 %), click &
 *    collect et livraison bureau, face au reste du réseau (vkCanaux).
 *
 * Gardé une heure (rcServi) et servi périmé six heures pendant le recalcul : des chiffres qui ne bougent pas pendant
 * une visite. La tablette les lit dès l'ouverture du contrôle, avant d'arriver à l'étape. L'année face à l'an passé et
 * les six leviers, elle les lit aux routes du dashboard magasin (/ventes/semaines, /leviers/magasins) et les dessine
 * avec ses cartes (assets/js/cartes-magasin.js).
 */

const VK_TTL_S = 3600;
const VK_PERIME_S = 21600;

/** GET /visites/kpi/{shop}[?rafraichir=1] */
function ep_visites_kpi(string $shop): array
{
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['shop' => $shop, 'indispo' => true, 'motif' => 'compte panel non configuré (Mon compte)']; }
    // vkKpi2 (26/10/2026) : la structure du CA par canal s'ajoute — un calcul gardé avant elle ne la porte pas.
    return rcServi('vkKpi2:' . $shop, static fn (): array => vkCalcul((int) $shop),
        static fn (array $x): int => empty($x['manques']) ? VK_TTL_S : 300, !empty($_GET['rafraichir']), '/visites/kpi/' . $shop, VK_PERIME_S);
}

/** Les deux lectures ; une qui échoue (ou un mois du panel resté muet) laisse les autres, se nomme dans `manques` et se relit dans cinq minutes. */
function vkCalcul(int $sid): array
{
    $mois = vkMoisClos(3);
    $out = ['shop' => (string) $sid, 'lu' => date('Y-m-d H:i')];
    $manques = [];
    foreach (['caEtp' => 'vkCaEtp', 'structure' => 'vkStructure', 'canaux' => 'vkCanaux'] as $k => $f) {
        try { $out[$k] = $f($sid, $mois); }
        catch (Throwable $e) { $out[$k] = null; $manques[] = $k; }
    }
    if (is_array($out['structure']) && $out['structure']['moisLus'] < $out['structure']['moisDemandes']) { $manques[] = 'structure'; }
    // Le webshop du magasin resté muet : relu dans cinq minutes. Des jours de caisse pas encore gravés ne relancent
    // rien — la moisson les complète à son rythme.
    if (is_array($out['canaux']) && empty($out['canaux']['webshop'])) { $manques[] = 'canaux'; }
    $out['manques'] = $manques;
    return $out;
}

/** Les n derniers mois clos, du plus ancien au plus récent ('AAAA-MM'). */
function vkMoisClos(int $n): array
{
    $out = [];
    for ($i = $n; $i >= 1; $i--) { $out[] = date('Y-m', mktime(12, 0, 0, (int) date('n') - $i, 1, (int) date('Y'))); }
    return $out;
}

function vkMoisLab(string $m): string
{
    $MN = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $MN[(int) substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
}

/** Un endpoint appelé avec d'autres paramètres de requête, puis la requête rendue telle qu'elle était. */
function vkAvecGet(array $get, callable $ep): mixed
{
    $avant = $_GET;
    $_GET = $get + $_GET;
    try { return $ep(); } finally { $_GET = $avant; }
}

/** Un écart en %, arrondi au dixième ; null sans base. */
function vkEcart(?float $n, ?float $n1): ?float
{
    return ($n !== null && $n1 !== null && $n1 > 0) ? round(($n / $n1 - 1) * 100, 1) : null;
}

/* --- CA par ETP ------------------------------------------------------------------ */

function vkCaEtp(int $sid, array $mois): array
{
    $shops = array_map('intval', array_keys(viMagasins()));
    $paires = [];
    foreach ($shops as $s) { foreach ($mois as $m) { $paires[] = [$s, $m]; } }
    if (function_exists('pvKpisMoisLot')) { pvKpisMoisLot($paires); }
    $annees = array_values(array_unique(array_map(static fn (string $m): int => (int) substr($m, 0, 4), $mois)));
    $etp = [];
    foreach ((array) vkAvecGet(['annees' => implode(',', $annees)], 'ep_stores_etp') as $r) {
        if (($r['etp'] ?? null) !== null) { $etp[(int) $r['storeId']][sprintf('%04d-%02d', (int) $r['annee'], (int) $r['mois'])] = (float) $r['etp']; }
    }
    $lignes = [];
    foreach ($mois as $m) {
        $par = [];
        foreach ($shops as $s) {
            $k = pvKpisMois($s, $m); $e = $etp[$s][$m] ?? null;
            $ca = $k !== null && $k['ca'] > 0 ? (float) $k['ca'] : null;
            $par[$s] = ['ca' => $ca, 'etp' => $e, 'caEtp' => ($ca !== null && $e !== null && $e > 0) ? (int) round($ca / $e) : null];
        }
        $avec = array_filter($par, static fn (array $x): bool => $x['caEtp'] !== null);
        $caR = array_sum(array_column($avec, 'ca')); $etpR = array_sum(array_column($avec, 'etp'));
        $ici = $par[$sid] ?? ['ca' => null, 'etp' => null, 'caEtp' => null];
        $lignes[] = ['mois' => $m, 'lab' => vkMoisLab($m), 'ca' => $ici['ca'] !== null ? round($ici['ca']) : null, 'etp' => $ici['etp'], 'caEtp' => $ici['caEtp'],
            'reseau' => $etpR > 0 ? (int) round($caR / $etpR) : null,
            'rang' => $ici['caEtp'] !== null ? 1 + count(array_filter($avec, static fn (array $x): bool => $x['caEtp'] > $ici['caEtp'])) : null,
            'sur' => count($avec)];
    }
    return ['seuil' => function_exists('kpiSeuil') ? kpiSeuil('ca-etp', 13000.0) : 13000.0, 'heuresEtp' => (float) setting('heuresEtpMois', 168),
        'mois' => $lignes, 'source' => 'CA : relevé des ventes du panel (mois clos) · ETP : heures du planning ÷ ' . (float) setting('heuresEtpMois', 168) . ' h'];
}

/* --- La structure des ventes face au reste du réseau ------------------------------- */

/** Une réponse category-sales ventilée : [magasin => [catégorie => CA]]. */
function vkCatsParMagasin(mixed $rep): array
{
    $par = [];
    $liste = is_array($rep) ? ($rep['shops'] ?? (array_is_list($rep) ? $rep : [])) : [];
    foreach ((array) $liste as $e) {
        if (!is_array($e) || !isset($e['categories']) || !is_array($e['categories'])) { continue; }
        $id = (int) (nombreOuNull($e, ['shop_id', 'id_shop', 'id']) ?? 0);
        if ($id <= 0) { continue; }
        foreach ($e['categories'] as $c) {
            if (!is_array($c)) { continue; }
            $v = nombreOuNull($c, ['ca', 'value', 'amount', 'turnover']);
            $nom = trim((string) ($c['name'] ?? $c['label'] ?? ''));
            if ($nom === '' || $v === null) { continue; }
            $par[$id][$nom] = ($par[$id][$nom] ?? 0) + $v;
        }
    }
    return $par;
}

function vkStructure(int $sid, array $mois): array
{
    $par = []; $paths = [];
    foreach ($mois as $m) {
        $c = setting('vkCat:' . $m);
        if (is_array($c) && $c !== []) { $par[$m] = $c; continue; }
        $du = $m . '-01';
        $paths[$m] = '/consultant/shops/category-sales?' . http_build_query(['date_from' => $du, 'date_to' => date('Y-m-t', strtotime($du))]);
    }
    if ($paths !== []) {
        foreach (PanelApi::getParallele($paths, 3) as $m => $rep) {
            $c = vkCatsParMagasin($rep);
            if ($c === []) { continue; }
            $par[$m] = $c;
            try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['vkCat:' . $m, json_encode($c)]); }
            catch (Throwable $e) { /* sans gravure : relu au prochain calcul */ }
        }
    }
    // Le reste du réseau : les autres magasins ouverts — pas les entrées techniques que la route rend parfois.
    $ouverts = array_map('intval', array_keys(viMagasins()));
    $ici = []; $reste = []; $autres = [];
    foreach ($par as $c) {
        foreach ($c as $s => $cats) {
            $s = (int) $s;
            if ($s !== $sid && !in_array($s, $ouverts, true)) { continue; }
            foreach ($cats as $nom => $v) {
                if ($s === $sid) { $ici[$nom] = ($ici[$nom] ?? 0) + (float) $v; }
                else { $reste[$nom] = ($reste[$nom] ?? 0) + (float) $v; $autres[$s] = true; }
            }
        }
    }
    $tI = array_sum($ici); $tR = array_sum($reste);
    $lignes = [];
    foreach (array_unique(array_merge(array_keys($ici), array_keys($reste))) as $nom) {
        $pI = $tI > 0 ? ($ici[$nom] ?? 0) / $tI * 100 : null; $pR = $tR > 0 ? ($reste[$nom] ?? 0) / $tR * 100 : null;
        $lignes[] = ['categorie' => (string) $nom, 'ca' => round($ici[$nom] ?? 0), 'part' => $pI !== null ? round($pI, 1) : null, 'partReste' => $pR !== null ? round($pR, 1) : null,
            'ecart' => $pI !== null && $pR !== null ? round($pI - $pR, 1) : null];
    }
    usort($lignes, static fn (array $a, array $b): int => [$b['ca'], $b['partReste'] ?? 0] <=> [$a['ca'], $a['partReste'] ?? 0]);
    $lus = array_values(array_filter($mois, static fn (string $m): bool => isset($par[$m])));
    return ['du' => $lus ? $lus[0] . '-01' : null, 'au' => $lus ? date('Y-m-t', strtotime(end($lus) . '-01')) : null, 'moisLus' => count($lus), 'moisDemandes' => count($mois),
        'ca' => round($tI), 'caReste' => round($tR), 'autres' => count($autres), 'categories' => $tI > 0 ? $lignes : [],
        'source' => 'panel : ventes par catégorie (/consultant/shops/category-sales), mois clos'];
}

/* --- La structure du CA par canal : comptoir, B2B, click & collect, livraison bureau (26/10/2026, demande de Sam) --- */

/**
 * Sur les trois derniers mois clos, les jours dont la caisse est gravée — le relevé pro du jour (`svP{shop}:{jour}`,
 * ventes_pro.php) garde le CA de tous les tickets et les tickets des clients pro :
 *  - B2B = les tickets des clients pro (is_client_b2b, TVA valide : vpLu) ;
 *  - comptoir = le reste de la caisse, moins les commandes du webshop encaissées en caisse (déjà dans un ticket) ;
 *  - click & collect et livraison bureau = les commandes du webshop (coCommandes), retirées ou livrées ces jours-là.
 * Total = comptoir + B2B + click & collect + livraison. Le reste du réseau se compte de même, magasin par magasin,
 * ceux dont le webshop a répondu. La part B2B se lit face à son plafond (réglage `b2bMax` des visites, 40 %).
 */
function vkCanaux(int $sid, array $mois): array
{
    $du = $mois[0] . '-01'; $au = date('Y-m-t', strtotime($mois[count($mois) - 1] . '-01'));
    $jours = [];
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $jours[] = $d; }
    $max = (float) (viSeuils()['b2bMax'] ?? 40);
    $par = []; $web = [];
    foreach (array_map('intval', array_keys(viMagasins())) as $s) {
        $cmds = function_exists('coCommandes') ? coCommandes($s, $du) : null;
        $web[$s] = $cmds !== null;
        $w = [];
        foreach ((array) $cmds as $c) {
            $j = substr((string) ($c['quand'] ?? ''), 0, 10);
            if ($j < $du || $j > $au || !in_array($c['canal'] ?? '', ['cc', 'liv'], true)) { continue; }
            $x = $w[$j] ?? ['cc' => 0.0, 'liv' => 0.0, 'enc' => 0.0];
            $x[$c['canal']] += (float) $c['montant'];
            if (!empty($c['encaissee'])) { $x['enc'] += (float) $c['montant']; }
            $w[$j] = $x;
        }
        $t = ['caisse' => 0.0, 'b2b' => 0.0, 'cc' => 0.0, 'liv' => 0.0, 'enc' => 0.0, 'lus' => 0];
        foreach ($jours as $j) {
            $b = function_exists('vpLu') ? vpLu($s, $j) : null;
            if ($b === null) { continue; }
            $t['lus']++; $t['caisse'] += (float) ($b['ca'] ?? 0);
            foreach ((array) ($b['t'] ?? []) as $tk) { $t['b2b'] += (float) ($tk[2] ?? 0); }
            if (isset($w[$j])) { $t['cc'] += $w[$j]['cc']; $t['liv'] += $w[$j]['liv']; $t['enc'] += $w[$j]['enc']; }
        }
        $par[$s] = $t;
    }
    $ici = $par[$sid] ?? ['lus' => 0];
    $reste = ['caisse' => 0.0, 'b2b' => 0.0, 'cc' => 0.0, 'liv' => 0.0, 'enc' => 0.0, 'lus' => 0]; $autres = 0;
    foreach ($par as $s => $t) {
        if ($s === $sid || !$web[$s] || $t['lus'] === 0) { continue; }
        foreach ($reste as $k => $v) { $reste[$k] = $v + $t[$k]; }
        $autres++;
    }
    $mag = !empty($web[$sid]) && $ici['lus'] > 0 ? vkCanauxParts($ici) : null;
    $b2b = $mag['parts']['b2b'] ?? null;
    return ['du' => $du, 'au' => $au, 'jours' => count($jours), 'joursLus' => (int) $ici['lus'], 'webshop' => !empty($web[$sid]),
        'magasin' => $mag, 'reste' => $autres > 0 ? vkCanauxParts($reste) + ['magasins' => $autres] : null,
        'b2bMax' => $max, 'b2bStatut' => $b2b === null ? null : ($b2b > $max ? 'au-dessus' : ($b2b >= $max - 5 ? 'proche' : 'ok')),
        'motif' => empty($web[$sid]) ? 'le webshop du panel n’a pas répondu (click & collect et livraisons inconnus)' : ($ici['lus'] === 0 ? 'aucun jour de caisse gravé sur ces mois' : null),
        'source' => 'caisse : relevé des tickets du panel par jour (B2B = clients pro à TVA valide) · webshop : commandes du panel, retrait (click & collect) ou livraison · '
            . (int) $ici['lus'] . ' jours lus sur ' . count($jours)];
}

/** Les quatre canaux d'un cumul (pure) : le CA de chacun, le total, la part de chacun en %. null sans CA. */
function vkCanauxParts(array $t): ?array
{
    $ca = ['comptoir' => max(0.0, $t['caisse'] - $t['b2b'] - $t['enc']), 'b2b' => $t['b2b'], 'cc' => $t['cc'], 'liv' => $t['liv']];
    $total = array_sum($ca);
    if ($total <= 0) { return null; }
    return ['ca' => array_map(static fn (float $v): float => round($v), $ca), 'total' => round($total),
        'parts' => array_map(static fn (float $v): float => round($v / $total * 100, 1), $ca)];
}

/* --- Les tâches du magasin, mois par mois (26/10/2026, demande de Sam) ----------------------------- */

/**
 * GET /visites/taches/{shop}[?mois=AAAA-MM][&n=12] — pour l'étape « Contrôles photo » de la tablette :
 *  - `mois[]` : les n derniers mois (12 au plus), du plus récent au plus ancien — les tâches demandées et faites (le
 *    relevé quotidien du panel, `ceo_tache_jour`, journées révolues : le mois en cours s'arrête à hier), les notes du
 *    contrôle qualité (`mac_task_review`) : combien notées, combien sous le seuil (4), la moyenne ;
 *  - `taches[]` : pour le mois choisi (d'office le mois en cours, ou le précédent s'il n'a encore rien), les tâches
 *    notées sous le seuil ou refusées, avec la note, le commentaire et le nom du contrôleur (ep_pwa_tasks_nc) ; la
 *    tablette lit la photo et les repères de chacune à /pwa/tasks/detail (taskId, jour).
 * Tout se lit dans la base du cockpit : rien au panel, rien à garder en cache.
 */
function ep_visites_taches(string $shop): array
{
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $sid = (int) $shop;
    $n = max(3, min(12, (int) ($_GET['n'] ?? 12)));
    $sig = setting('signalement', []);
    $seuil = is_array($sig) && isset($sig['seuil']) ? (int) $sig['seuil'] : 4;
    $courant = date('Y-m');
    $liste = [];
    for ($i = 0; $i < $n; $i++) { $liste[] = date('Y-m', mktime(12, 0, 0, (int) date('n') - $i, 1, (int) date('Y'))); }
    $du = end($liste) . '-01'; $au = date('Y-m-d');
    $par = [];
    if (function_exists('tachesSuiviTables')) { tachesSuiviTables(); }
    try {
        foreach (Db::rows('SELECT DATE_FORMAT(jour, \'%Y-%m\') AS m, COUNT(*) AS t, SUM(fait) AS f, COUNT(DISTINCT jour) AS j FROM ceo_tache_jour'
            . ' WHERE id_shop = ? AND jour BETWEEN ? AND ? GROUP BY m', [$sid, $du, $au]) as $r) {
            $par[(string) $r['m']] = ['demandees' => (int) $r['t'], 'faites' => (int) $r['f'], 'jours' => (int) $r['j']];
        }
    } catch (PDOException $e) { /* relevé absent : les mois le disent */ }
    try {
        foreach (Db::rows('SELECT DATE_FORMAT(review_date, \'%Y-%m\') AS m, COUNT(rating) AS notees, SUM(rating < ?) AS sous, AVG(rating) AS moy FROM mac_task_review'
            . ' WHERE id_shop = ? AND review_date BETWEEN ? AND ? GROUP BY m', [$seuil, $sid, $du, $au]) as $r) {
            $par[(string) $r['m']] = ($par[(string) $r['m']] ?? []) + ['notees' => (int) $r['notees'], 'sous' => (int) $r['sous'], 'moyenne' => $r['moy'] !== null ? round((float) $r['moy'], 2) : null];
        }
    } catch (PDOException $e) { /* pas de notes du contrôle qualité */ }
    $mois = [];
    foreach ($liste as $m) {
        $x = $par[$m] ?? [];
        $d = $x['demandees'] ?? 0; $f = $x['faites'] ?? 0;
        $mois[] = ['mois' => $m, 'lab' => vkMoisLab($m), 'enCours' => $m === $courant, 'demandees' => $d, 'faites' => $f, 'jours' => $x['jours'] ?? 0,
            'pct' => $d > 0 ? (int) round($f / $d * 100) : null, 'notees' => $x['notees'] ?? 0, 'sous' => $x['sous'] ?? 0, 'moyenne' => $x['moyenne'] ?? null];
    }
    // Le mois choisi : celui demandé s'il est dans la liste, sinon le mois en cours, ou le précédent s'il n'a encore rien.
    $choisi = (string) ($_GET['mois'] ?? '');
    if (!in_array($choisi, $liste, true)) {
        $choisi = ($mois[0]['demandees'] || $mois[0]['notees'] || !isset($mois[1])) ? $liste[0] : $liste[1];
    }
    $fin = $choisi === $courant ? date('Y-m-d') : date('Y-m-t', strtotime($choisi . '-01'));
    $nc = vkAvecGet(['shop' => (string) $sid, 'du' => $choisi . '-01', 'au' => $fin, 'date' => ''], 'ep_pwa_tasks_nc');
    $taches = array_map(static fn (array $t): array => ['taskId' => (string) $t['taskId'], 'jour' => $t['jour'], 'tache' => $t['tache'], 'note' => $t['note'], 'commentaire' => $t['comment'],
        'controleur' => $t['consultant'], 'releveeLe' => $t['releveeLe'], 'recidive' => $t['recidive'], 'suite' => $t['suite']], (array) ($nc['nc'] ?? []));
    return ['shop' => (string) $sid, 'seuil' => $seuil, 'mois' => $mois, 'choisi' => $choisi, 'choisiLab' => vkMoisLab($choisi), 'taches' => $taches,
        'indispo' => !empty($nc['indispo']),
        'source' => 'tâches : relevé quotidien du panel (journées révolues, le mois en cours jusqu’à hier) · notes : contrôle qualité des tâches'];
}


/* --- Le CA face au budget : semaine, mois, trimestre, année (26/10/2026, demande de Sam) ------------------ */

/*
 * GET /visites/budget/{shop} — où le magasin se place face à son budget, sur quatre étendues qui finissent toutes à
 * la VEILLE (le dernier jour clos : une journée en cours, à moitié vendue, mentirait en rouge chaque matin) :
 *
 *  - la SEMAINE et le MOIS sont ceux de Résultat (ep_exploitation_periode, la même lecture que la tablette des
 *    vendeuses) : le CA TTC encaissé jour par jour, et l'ATTENDU = la part du budget des jours écoulés, le budget du
 *    mois étant réparti par la pondération réseau sur les jours où le magasin ouvre ;
 *  - le TRIMESTRE et l'ANNÉE ajoutent au mois en cours les mois clos de l'étendue : leur CA (relevé des ventes du
 *    panel, pvKpisMois) face à leur budget entier. Un mois clos sans budget, ou sans aucune vente (pas encore ouvert),
 *    sort des deux côtés de la comparaison (`sansBudget`, ses numéros de mois) : il ne gonfle ni le réalisé ni l'attendu ;
 *  - le budget d'un mois est le budget validé (ceo_shop_month_perf.revenue_budget), à défaut le CA théorique de
 *    l'étude — `source` dit lequel (budget, theorique, mixte).
 *
 * L'écart = réalisé ÷ attendu − 1. Sous le seuil (le réglage `caOrange` des visites, 10 % par défaut), le
 * consultant note avec le franchisé pourquoi, et le franchisé donne trois pistes de développement (PUT /visites/{id},
 * champ `budgetCa`). Le rang compare l'écart des magasins du réseau sur la même étendue.
 */

const VB_TTL_S = 1800;
const VB_PERIME_S = 21600;
const VB_ETENDUES = ['semaine', 'mois', 'trimestre', 'annee'];

/** GET /visites/budget/{shop}[?rafraichir=1] — gardé une demi-heure, cinq minutes si une lecture a manqué. */
function ep_visites_budget(string $shop): array
{
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['shop' => $shop, 'indispo' => true, 'motif' => 'compte panel non configuré (Mon compte)']; }
    @set_time_limit(120);
    return rcServi('vkBud:' . $shop, static fn (): array => vbCalcul((int) $shop, date('Y-m-d', strtotime('-1 day'))),
        static fn (array $x): int => empty($x['manques']) ? VB_TTL_S : 300, !empty($_GET['rafraichir']), '/visites/budget/' . $shop, VB_PERIME_S);
}

/** Les quatre étendues pour un magasin, arrêtées à `$ref` (la veille). */
function vbCalcul(int $sid, string $ref): array
{
    $seuil = (float) (viSeuils()['caOrange'] ?? 10);
    $shops = array_map('strval', array_keys(viMagasins()));
    $annee = (int) substr($ref, 0, 4); $moisRef = (int) substr($ref, 5, 2);
    $manques = []; $res = [];
    foreach (['semaine', 'mois'] as $vue) {
        try { $res[$vue] = vbResultat($vue, $ref); }
        catch (Throwable $e) { $res[$vue] = ['ok' => false, 'motif' => 'Résultat illisible (' . $e->getMessage() . ')', 'magasins' => []]; }
        if (!$res[$vue]['ok']) { $manques[] = $vue; }
    }
    $budgets = vbBudgets($annee);
    $clos = [];
    for ($m = 1; $m < $moisRef; $m++) { $clos[] = sprintf('%04d-%02d', $annee, $m); }
    $paires = [];
    foreach ($shops as $s) { foreach ($clos as $ym) { $paires[] = [(int) $s, $ym]; } }
    if ($paires !== [] && function_exists('pvKpisMoisLot')) { try { pvKpisMoisLot($paires); } catch (Throwable $e) { /* chaque mois se relit seul */ } }
    $caClos = [];
    foreach ($shops as $s) {
        foreach ($clos as $ym) {
            // Le magasin visité relit seul un mois resté muet ; les autres magasins (le rang) se contentent du lot.
            if ($s === (string) $sid) { try { $k = pvKpisMois((int) $s, $ym); } catch (Throwable $e) { $k = null; } }
            else { $c = setting('pvCa' . $s . ':' . $ym); $k = is_array($c) && isset($c['ca']) ? $c : null; }
            $caClos[$s][(int) substr($ym, 5, 2)] = $k !== null ? (float) $k['ca'] : null;
        }
    }
    $bornes = tobBornes($ref);
    $q0 = intdiv($moisRef - 1, 3) * 3 + 1;
    $mois = [
        'semaine' => null, 'mois' => null,
        'trimestre' => range($q0, $q0 + 2),
        'annee' => range(1, 12),
    ];
    $out = ['shop' => (string) $sid, 'arrete' => $ref, 'lu' => date('Y-m-d H:i'), 'seuil' => $seuil, 'periodes' => [], 'sous' => []];
    foreach (VB_ETENDUES as $et) {
        $par = [];
        if ($mois[$et] === null) {
            foreach ($shops as $s) { $par[$s] = $res[$et]['magasins'][$s] ?? null; }
        } else {
            foreach ($shops as $s) { $par[$s] = vbCumulMois($mois[$et], $moisRef, $caClos[$s] ?? [], $budgets[$s] ?? [], $res['mois']['magasins'][$s] ?? null); }
        }
        $p = vbEtendue($par, (string) $sid, $seuil);
        [$du, $au] = match ($et) {
            'semaine' => $bornes['semaine'],
            'mois' => $bornes['mois'],
            'trimestre' => [sprintf('%04d-%02d-01', $annee, $q0), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $annee, $q0 + 2)))],
            'annee' => [$annee . '-01-01', $annee . '-12-31'],
        };
        $p = ['lab' => vbLab($et, $ref, $q0), 'du' => $du, 'au' => $au] + $p;
        if ($p['statut'] === 'sans') { $p['motif'] = vbMotif($mois[$et] === null ? $res[$et] : $res['mois'], $par[(string) $sid] ?? null, $mois[$et] === null); }
        if ($p['statut'] === 'sous') { $out['sous'][] = $et; }
        $out['periodes'][$et] = $p;
    }
    foreach (['trimestre', 'annee'] as $et) {
        $x = $out['periodes'][$et];
        if ($x['statut'] === 'sans' && !in_array($et, $manques, true) && !empty($x['incomplet'])) { $manques[] = $et; }
    }
    $out['manques'] = $manques;
    $out['source'] = 'Réalisé : CA TTC encaissé jusqu’au ' . date('d/m/Y', strtotime($ref)) . ' inclus (la veille) — semaine et mois tels que Résultat, mois clos au relevé des ventes du panel'
        . ' · Budget : le budget validé du mois, à défaut le CA théorique de l’étude ; attendu = la part du budget des jours écoulés (pondération réseau des jours d’ouverture), les mois clos en entier'
        . ' · Seuil : ' . str_replace('.', ',', (string) $seuil) . ' % sous l’attendu (réglage caOrange des visites)';
    return $out;
}

/**
 * Une étendue de Résultat (semaine ou mois) coupée à `$ref` : la lecture d'aujourd'hui, celle de Résultat et de la
 * tablette des vendeuses, quand `$ref` est dans l'étendue en cours ; l'étendue close de `$ref` sinon (un lundi, la
 * semaine passée ; un 1er, le mois passé).
 */
function vbResultat(string $vue, string $ref): array
{
    $auj = date('Y-m-d');
    $date = $ref >= tobBornes($auj)[$vue][0] ? $auj : $ref;
    return tobExtraire(tobPeriode($vue, $date), $ref);
}

/** Les budgets de l'année : [magasin][mois] => {v, src} — le budget validé, à défaut le CA théorique. */
function vbBudgets(int $annee): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT shop_id, month, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE year = ?', [$annee]) as $b) {
            if ($b['revenue_budget'] !== null && (float) $b['revenue_budget'] > 0) { $out[(string) $b['shop_id']][(int) $b['month']] = ['v' => (float) $b['revenue_budget'], 'src' => 'budget']; }
            elseif ($b['ca_theorique'] !== null && (float) $b['ca_theorique'] > 0) { $out[(string) $b['shop_id']][(int) $b['month']] = ['v' => (float) $b['ca_theorique'], 'src' => 'theorique']; }
        }
    } catch (PDOException $e) { /* pas de budget : pas d'attendu */ }
    return $out;
}

/**
 * Trimestre ou année d'un magasin (pure) : les mois clos (CA du panel face au budget entier), le mois de la date
 * d'arrêt (réalisé et attendu de Résultat), les mois à venir (leur budget compte dans l'objectif, pas dans l'attendu).
 *
 * @param int[] $mois les mois de l'étendue ; @param array<int, ?float> $ca ; @param array<int, array{v: float, src: string}> $bud
 * @return array{realise: ?float, attendu: ?float, objectif: ?float, source: ?string, sansBudget: int[], incomplet: bool}
 */
function vbCumulMois(array $mois, int $moisRef, array $ca, array $bud, ?array $courant): array
{
    $realise = 0.0; $attendu = 0.0; $objectif = 0.0; $srcs = []; $sansBudget = []; $incomplet = false; $aAttendu = false;
    foreach ($mois as $m) {
        $b = $bud[$m] ?? null;
        if ($b !== null) { $srcs[$b['src']] = true; }
        if ($m < $moisRef) {
            if ($b === null) { $sansBudget[] = $m; continue; }
            $c = array_key_exists($m, $ca) ? $ca[$m] : null;
            if ($c === null) { $incomplet = true; continue; }
            // Un mois clos sans aucune vente (pas encore ouvert, fermé) sort de la comparaison comme un mois sans budget.
            if ($c <= 0) { $sansBudget[] = $m; continue; }
            $realise += $c; $attendu += $b['v']; $objectif += $b['v']; $aAttendu = true;
        } elseif ($m === $moisRef) {
            if ($courant === null || $courant['realise'] === null) { $incomplet = true; $objectif += $b['v'] ?? 0; continue; }
            if ($courant['attendu'] === null) { $sansBudget[] = $m; continue; }
            $realise += (float) $courant['realise']; $attendu += (float) $courant['attendu']; $aAttendu = true;
            $objectif += $courant['objectif'] !== null ? (float) $courant['objectif'] : ($b['v'] ?? 0);
        } elseif ($b !== null) {
            $objectif += $b['v'];
        }
    }
    $ok = $aAttendu && !$incomplet;
    return ['realise' => $ok ? round($realise, 2) : null, 'attendu' => $ok ? round($attendu, 2) : null, 'objectif' => $objectif > 0 ? round($objectif, 2) : null,
        'source' => $srcs === [] ? null : (count($srcs) > 1 ? 'mixte' : (string) array_key_first($srcs)), 'sansBudget' => $sansBudget, 'incomplet' => $incomplet];
}

/**
 * Une étendue face au budget (pure) : réalisé, attendu, écart, statut, et la place du magasin parmi ceux du réseau
 * qui ont un écart sur la même étendue.
 *
 * @param array<string, ?array{realise: ?float, attendu: ?float, objectif?: ?float, source?: ?string}> $par
 */
function vbEtendue(array $par, string $sid, float $seuil): array
{
    $ecart = static fn (?array $x): ?float => ($x !== null && ($x['realise'] ?? null) !== null && ($x['attendu'] ?? null) !== null && (float) $x['attendu'] > 0)
        ? round(((float) $x['realise'] / (float) $x['attendu'] - 1) * 100, 1) : null;
    $ecarts = []; $sR = 0.0; $sA = 0.0;
    foreach ($par as $s => $x) {
        $e = $ecart($x);
        if ($e === null) { continue; }
        $ecarts[(string) $s] = $e; $sR += (float) $x['realise']; $sA += (float) $x['attendu'];
    }
    $ici = $par[$sid] ?? null; $e = $ecarts[$sid] ?? null;
    $obj = isset($ici['objectif']) && $ici['objectif'] !== null ? (float) $ici['objectif'] : null;
    $real = isset($ici['realise']) && $ici['realise'] !== null ? (float) $ici['realise'] : null;
    $att = isset($ici['attendu']) && $ici['attendu'] !== null ? (float) $ici['attendu'] : null;
    return ['realise' => $real !== null ? round($real) : null, 'attendu' => $att !== null ? round($att) : null, 'objectif' => $obj !== null ? round($obj) : null,
        'ecartPct' => $e, 'manque' => $e !== null ? round($att - $real) : null,
        'atteintePct' => $real !== null && $obj !== null && $obj > 0 ? round($real / $obj * 100, 1) : null,
        'source' => $ici['source'] ?? null,
        'sansBudget' => array_values((array) ($ici['sansBudget'] ?? [])), 'incomplet' => !empty($ici['incomplet']),
        'statut' => $e === null ? 'sans' : ($e < -$seuil ? 'sous' : ($e < 0 ? 'proche' : 'ok')),
        'reseau' => $sA > 0 ? round(($sR / $sA - 1) * 100, 1) : null,
        'rang' => $e !== null ? 1 + count(array_filter($ecarts, static fn (float $x): bool => $x > $e)) : null,
        'sur' => count($ecarts)];
}

/** Pourquoi une étendue n'a pas d'écart : Résultat muet, pas de pondération, CA non lu, pas de budget. */
function vbMotif(array $res, ?array $ici, bool $resultat): string
{
    if (empty($res['ok'])) { return (string) ($res['motif'] ?? 'Résultat indisponible'); }
    if ($resultat && empty($res['ponderation']['ok'])) { return (string) (($res['ponderation']['motif'] ?? '') ?: 'aucune pondération des jours adoptée'); }
    if ($ici === null) { return 'CA non lu au panel'; }
    if (!empty($ici['incomplet'])) { return 'un mois n’a pas été lu au panel — relu dans quelques minutes'; }
    if ($resultat && ($ici['realise'] ?? null) === null) { return 'CA non lu au panel'; }
    return 'pas de budget ni de CA théorique encodé sur cette étendue';
}

function vbLab(string $et, string $ref, int $q0): string
{
    $MN = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $a = substr($ref, 0, 4);
    return match ($et) {
        'semaine' => 'semaine du ' . date('d/m', strtotime(tobBornes($ref)['semaine'][0])),
        'mois' => $MN[(int) substr($ref, 5, 2)] . ' ' . $a,
        'trimestre' => ($q0 === 1 ? '1er' : intdiv($q0 + 2, 3) . 'e') . ' trimestre ' . $a,
        default => 'année ' . $a,
    };
}
