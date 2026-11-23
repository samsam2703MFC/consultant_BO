<?php
declare(strict_types=1);

/**
 * MON ÉQUIPE — la gestion de l'équipe du panel dans le dashboard franchisé (23/11/2026, étape 1 ; maquettes
 * panel/docs/maquettes/equipe-gestion/). Repris du module « Team » du panel TF Buddy (paquet panel-team-files :
 * TeamResponsibilityMap, TeamManagementService), réécrit pour le cockpit. Tout vit au panel : aucune table.
 *
 * - LA CARTE DES RESPONSABILITÉS : chaque employé a des postes (workstations), un poste est lié à un secteur de
 *   production, le secteur donne les produits de l'assortiment actif du magasin. Les secteurs sans personne, les
 *   produits sans secteur, les postes sans secteur. Seuls les employés actifs du magasin comptent.
 *   GET /dashboard/equipe/carte?shop= ; POST /dashboard/equipe/postes {shop, employe, postes, attendus} — les postes
 *   d'une personne, avec le verrou du panel : `attendus` (ses postes lus à l'écran) doit valoir ses postes au panel,
 *   sinon 409 (quelqu'un d'autre les a changés).
 * - LE PLANNING : les services des jours demandés (/shops/{id}/schedule?date=, la route de l'Opérationnel), les
 *   employés et leur taux horaire, l'objectif de chaque jour (resObjectifsJours : le CA prévu), les paliers du budget
 *   d'heures (personnelPaliers : 17 %, 20 %, 22 %) et, pour un jour, ses temps morts (le module Temps morts : les creux
 *   habituels des 4 mêmes jours d'avant, les tickets par heure, les trous du jour s'il est passé ou en cours).
 *   GET /dashboard/equipe/planning?shop=&du=&jours= ; GET /dashboard/equipe/planning/pdf?shop=&du=&jours=&presentation=
 *   (le PDF du panel, /shops/{id}/schedule/document).
 * - MODIFIER UN SERVICE sur son linéaire : POST /dashboard/equipe/service {shop, employe, date, de, a} (POST /schedule),
 *   PATCH {shop, id, date, de, a} (PATCH /schedule/{id}), DELETE {shop, id, date} (DELETE /schedule/{id}). Un service
 *   désigné par son numéro doit être au planning du magasin ce jour-là ; deux services d'une personne ne se chevauchent pas.
 *
 * Les écritures partent avec le compte du cockpit (PanelApi) : si le panel refuse (403), la réponse le dit telle quelle.
 * La route des employés rend aussi des secrets (hachages, jetons) : rien n'en sort que les champs listés ici.
 */

const EQP_POSTES_MAX = 300;          // le plafond du panel pour une liste de postes
const EQP_JOURS_MAX = 14;            // un planning lu couvre deux semaines au plus
const EQP_SEUIL_MORT = 10;           // un temps mort : 10 minutes et plus sans ticket (la valeur par défaut de l'onglet)
const EQP_CREUX_PART = 0.5;          // un creux habituel : un quart d'heure mort au moins un jour sur deux des 4 d'avant

function eqpRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/equipe' && !str_starts_with($path, '/dashboard/equipe/')) { return null; }
    if ($method === 'GET' && $path === '/dashboard/equipe/carte') { return ep_equipe_carte(); }
    if ($method === 'POST' && $path === '/dashboard/equipe/postes') { return wr_equipe_postes(); }
    if ($method === 'GET' && $path === '/dashboard/equipe/planning') { return ep_equipe_planning(); }
    if ($method === 'GET' && $path === '/dashboard/equipe/planning/pdf') { ep_equipe_planning_pdf(); return null; }
    if ($path === '/dashboard/equipe/service') {
        if ($method === 'POST') { return wr_equipe_service_creer(); }
        if ($method === 'PATCH') { return wr_equipe_service_modifier(); }
        if ($method === 'DELETE') { return wr_equipe_service_supprimer(); }
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}

/* ── Lectures ─────────────────────────────────────────────────────────────────────────────────────────────────── */

/** Le magasin de la requête (la requête, sinon le corps) ; l'accès a déjà vérifié qu'il est de la session. */
function eqpShop(?array $b = null): int
{
    $v = $_GET['shop'] ?? ($b['shop'] ?? 0);
    return is_scalar($v) ? (int) $v : 0;
}

/** Une erreur : le code HTTP et le message. */
function eqpErreur(int $code, string $msg, array $plus = []): array
{
    http_response_code($code);
    return ['error' => $msg] + $plus;
}

/** Les lectures du panel d'un magasin, en parallèle : clé → liste (ou null si la route n'a pas répondu). */
function eqpLire(int $shop, array $quoi): array
{
    $chemins = [];
    foreach ($quoi as $k) {
        $chemins[$k] = match ($k) {
            'emp' => '/shops/' . $shop . '/employees?include=positions',
            'ws' => '/operational-framework/workstations',
            'prod' => '/shops/' . $shop . '/products/available',
        };
    }
    $out = [];
    foreach (PanelApi::getParallele($chemins, 3) as $k => $r) {
        $out[$k] = is_array($r) ? array_values(array_filter(analyseListe($r), 'is_array')) : null;
    }
    return $out;
}

/** Une personne, réduite aux champs utiles (jamais les secrets de la route employés). */
function eqpPersonne(array $r): array
{
    $nom = trim((string) ($r['name'] ?? '') . ' ' . (string) ($r['surname'] ?? ''));
    $postes = [];
    foreach ((array) ($r['workstations'] ?? []) as $w) {
        $id = is_array($w) ? (int) ($w['id'] ?? 0) : 0;
        if ($id > 0 && !isset($postes[$id])) { $postes[$id] = ['id' => $id, 'nom' => trim((string) ($w['name'] ?? ''))]; }
    }
    return ['id' => (int) ($r['id'] ?? 0), 'shop' => (int) ($r['id_shop'] ?? 0),
        'nom' => $nom !== '' ? $nom : (trim((string) ($r['display_name'] ?? '')) ?: 'Employé ' . (int) ($r['id'] ?? 0)),
        'fonctions' => array_values(array_filter(array_map(static fn ($p) => is_array($p) ? trim((string) ($p['name'] ?? '')) : '', (array) ($r['positions'] ?? [])))),
        'postes' => array_values($postes),
        'actif' => empty($r['soft_delete']) && (!array_key_exists('active', $r) || (bool) $r['active']),
        'taux' => isset($r['money_per_hour']) && is_numeric($r['money_per_hour']) ? (float) $r['money_per_hour'] : null,
        'franchise' => (string) ($r['franchise_role'] ?? '') === 'franchisee'];
}

/**
 * LA CARTE (la projection de TeamResponsibilityMap, pure) : employés actifs du magasin × postes × produits.
 * Un poste sans secteur va dans « autres postes » ; un secteur sans produit dans l'assortiment ne compte pas.
 */
function eqpCarte(int $shop, array $emps, array $ws, array $prods): array
{
    $postes = [];
    foreach ($ws as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id < 1) { continue; }
        $postes[$id] = ['id' => $id, 'nom' => (string) ($r['name'] ?? ''), 'secteur' => isset($r['sector_id']) && (int) $r['sector_id'] > 0 ? (int) $r['sector_id'] : null];
    }
    $secteurs = []; $vus = []; $sansSecteur = [];
    foreach ($prods as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id < 1 || isset($vus[$id]) || (isset($r['is_active']) && !$r['is_active']) || (isset($r['sale_enabled']) && !$r['sale_enabled'])) { continue; }
        $sid = isset($r['sector_id']) && (int) $r['sector_id'] > 0 ? (int) $r['sector_id'] : null;
        $p = ['id' => $id, 'nom' => (string) ($r['name'] ?? '')];
        $vus[$id] = true;
        if ($sid === null) { $sansSecteur[] = $p; continue; }
        $secteurs[$sid] ??= ['id' => $sid, 'nom' => (string) ($r['sector_name'] ?? ''), 'produits' => [], 'employes' => []];
        if ($secteurs[$sid]['nom'] === '' && !empty($r['sector_name'])) { $secteurs[$sid]['nom'] = (string) $r['sector_name']; }
        $secteurs[$sid]['produits'][] = $p;
    }
    $gens = []; $occupes = []; $sansSecteurPostes = [];
    foreach ($emps as $r) {
        $e = eqpPersonne($r);
        // Jamais la personne d'un autre magasin, même si la source répond mal ; seulement les actifs.
        if ($e['id'] < 1 || $e['shop'] !== $shop || !$e['actif']) { continue; }
        $e['secteurs'] = []; $e['autres'] = []; $e['produits'] = 0;
        foreach ($e['postes'] as $i => $w) {
            $st = $postes[$w['id']] ?? ['id' => $w['id'], 'nom' => $w['nom'], 'secteur' => null];
            if ($w['nom'] !== '') { $st['nom'] = $w['nom']; }
            $e['postes'][$i] = $st;
            $occupes[$st['id']] = true;
            if ($st['secteur'] === null) { $e['autres'][] = $st['nom']; $sansSecteurPostes[$st['id']] = $st; continue; }
            if (!isset($secteurs[$st['secteur']])) { continue; }   // aucun produit de ce secteur dans l'assortiment
            $e['secteurs'][$st['secteur']] = $st['secteur'];
            $secteurs[$st['secteur']]['employes'][$e['id']] = $e['id'];
        }
        $e['secteurs'] = array_values($e['secteurs']);
        foreach ($e['secteurs'] as $sid) { $e['produits'] += count($secteurs[$sid]['produits']); }
        unset($e['shop'], $e['actif']);
        $gens[$e['id']] = $e;
    }
    $couverts = 0; $vides = [];
    foreach ($secteurs as &$s) {
        $s['employes'] = array_values($s['employes']);
        usort($s['produits'], static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
        if ($s['employes'] !== []) { $couverts += count($s['produits']); } else { $vides[] = $s['id']; }
        if ($s['nom'] === '') { $s['nom'] = 'Secteur ' . $s['id']; }
    }
    unset($s);
    uasort($secteurs, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    uasort($gens, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    usort($sansSecteur, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    return ['employes' => array_values($gens), 'postes' => array_values($postes), 'secteurs' => array_values($secteurs),
        'produitsSansSecteur' => $sansSecteur, 'postesSansSecteur' => array_values($sansSecteurPostes), 'secteursVides' => $vides,
        'resume' => ['employes' => count($gens), 'postesOccupes' => count($occupes), 'postes' => count($postes), 'produits' => count($vus),
            'couverts' => $couverts, 'nonCouverts' => count($vus) - $couverts, 'secteursVides' => count($vides),
            'sansPoste' => count(array_filter($gens, static fn ($e) => $e['postes'] === []))]];
}

/** GET /dashboard/equipe/carte?shop= ; après une écriture, le magasin du corps. */
function ep_equipe_carte(?int $shop = null): array
{
    $shop ??= eqpShop();
    if ($shop <= 0) { return eqpErreur(400, 'shop manquant'); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return eqpErreur(503, 'compte du panel non configuré'); }
    $L = eqpLire($shop, ['emp', 'ws', 'prod']);
    foreach (['emp' => 'les employés', 'ws' => 'les postes', 'prod' => 'l’assortiment'] as $k => $quoi) {
        if ($L[$k] === null) { return eqpErreur(502, 'le panel n’a pas rendu ' . $quoi . ' : la carte est masquée pour ne pas signaler de faux manques', ['detail' => PanelApi::$lastError]); }
    }
    // Sans le secteur des postes, aucune responsabilité ne se déduit (le module du panel refuse aussi).
    foreach ($L['ws'] as $w) {
        if (!array_key_exists('sector_id', $w)) { return eqpErreur(502, 'les postes du panel ne portent pas leur secteur (sector_id) : la carte ne peut pas se calculer'); }
    }
    return ['shop' => $shop] + eqpCarte($shop, $L['emp'], $L['ws'], $L['prod']);
}

/** Une liste d'identifiants : entiers ≥ 1, sans doublon, triés ; null si la forme est mauvaise. */
function eqpIds(mixed $v): ?array
{
    if (!is_array($v) || !array_is_list($v) || count($v) > EQP_POSTES_MAX) { return null; }
    $ids = [];
    foreach ($v as $x) {
        if (!is_int($x) || $x < 1) { return null; }
        $ids[$x] = $x;
    }
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
}

/** POST /dashboard/equipe/postes {shop, employe, postes: [id…], attendus: [id…]} — les postes d'une personne. */
function wr_equipe_postes(): array
{
    $b = body();
    $shop = eqpShop($b); $emp = is_int($b['employe'] ?? null) ? $b['employe'] : 0;
    if ($shop <= 0 || $emp <= 0) { return eqpErreur(400, 'magasin et employé attendus'); }
    $ids = eqpIds($b['postes'] ?? null); $attendus = eqpIds($b['attendus'] ?? null);
    if ($ids === null || $attendus === null) { return eqpErreur(422, 'postes : une liste de numéros de postes', ['motif' => 'postes_invalides']); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return eqpErreur(503, 'compte du panel non configuré'); }
    $L = eqpLire($shop, ['emp', 'ws']);
    if ($L['emp'] === null || $L['ws'] === null) { return eqpErreur(502, 'le panel ne répond pas : rien n’est enregistré'); }
    $p = null;
    foreach ($L['emp'] as $r) { if ((int) ($r['id'] ?? 0) === $emp && (int) ($r['id_shop'] ?? 0) === $shop) { $p = eqpPersonne($r); break; } }
    if ($p === null) { return eqpErreur(404, 'cette personne n’est pas de l’équipe du magasin', ['motif' => 'employe_inconnu']); }
    if (!$p['actif']) { return eqpErreur(422, 'cette personne est désactivée', ['motif' => 'employe_inactif']); }
    $actuels = array_map(static fn ($w) => $w['id'], $p['postes']); sort($actuels, SORT_NUMERIC);
    if ($actuels !== $attendus) { return eqpErreur(409, 'les postes de cette personne ont changé entre-temps : relisez la carte avant de les modifier', ['motif' => 'conflit']); }
    $connus = [];
    foreach ($L['ws'] as $w) { $connus[(int) ($w['id'] ?? 0)] = (string) ($w['name'] ?? ''); }
    foreach ($ids as $id) { if (!isset($connus[$id])) { return eqpErreur(422, 'poste inconnu du panel : ' . $id, ['motif' => 'postes_invalides']); } }
    $r = PanelApi::requete('PUT', '/employees/' . $emp . '/workstations', ['workstation_ids' => $ids]);
    if ($r['code'] < 200 || $r['code'] >= 300) {
        return eqpErreur(in_array($r['code'], [403, 404, 409, 422], true) ? $r['code'] : 502, 'le panel a refusé les postes', ['detail' => $r['erreur']]);
    }
    try { journalAdd('Magasin ' . $shop, 'Équipe', null, 'Postes de ' . $p['nom'] . ' : ' . ($ids === [] ? 'aucun' : implode(', ', array_map(static fn ($i) => $connus[$i] ?: '#' . $i, $ids)))); }
    catch (Throwable $e) { /* le journal ne bloque pas */ }
    return ['ok' => true] + ep_equipe_carte($shop);
}

/* ── Le planning ──────────────────────────────────────────────────────────────────────────────────────────────── */

/** Une heure « HH:MM » (ou « HH:MM:SS ») → minutes depuis minuit ; null si illisible. */
function eqpMinutes(string $h): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $h, $m) || (int) $m[1] > 24 || (int) $m[2] > 59) { return null; }
    return 60 * (int) $m[1] + (int) $m[2];
}

/** Les services d'une réponse du planning, pour un magasin et une date : [{id, employe, de, a}]. */
function eqpServices(mixed $rep, int $shop, string $date): array
{
    $out = [];
    foreach (analyseListe($rep) as $r) {
        if (!is_array($r) || substr((string) ($r['work_date'] ?? $date), 0, 10) !== $date) { continue; }
        if (isset($r['id_shop']) && (int) $r['id_shop'] > 0 && (int) $r['id_shop'] !== $shop) { continue; }
        $de = eqpMinutes((string) ($r['start_hour'] ?? '')); $a = eqpMinutes((string) ($r['end_hour'] ?? ''));
        if ($de === null || $a === null || $a <= $de) { continue; }   // un service qui passe minuit sort du cadre du jour
        $out[] = ['id' => (int) ($r['id_schedule'] ?? $r['id'] ?? 0), 'employe' => (int) ($r['id_employee'] ?? 0),
            'de' => sprintf('%02d:%02d', intdiv($de, 60), $de % 60), 'a' => sprintf('%02d:%02d', intdiv($a, 60), $a % 60),
            'nom' => trim((string) ($r['display_name'] ?? '')) ?: trim((string) ($r['name'] ?? '') . ' ' . (string) ($r['surname'] ?? ''))];
    }
    usort($out, static fn ($x, $y) => strcmp($x['de'], $y['de']));
    return $out;
}

/**
 * LES TEMPS MORTS d'un jour pour le planogramme : les 4 mêmes jours de semaine d'avant (avant aujourd'hui pour un jour
 * à venir), leurs creux habituels (un quart d'heure mort au moins un jour sur deux), les tickets moyens par heure, et
 * les trous du jour lui-même s'il est passé ou en cours. Minutes depuis minuit.
 */
function eqpTempsMorts(int $shop, string $date): array
{
    if (!function_exists('tmJours') || !function_exists('tmMesure')) { return ['indispo' => true]; }
    $auj = date('Y-m-d');
    $habitude = []; $d = $date;
    while (count($habitude) < 4) { $d = date('Y-m-d', strtotime($d . ' -7 days')); if ($d < $auj) { $habitude[] = $d; } }
    $R = tmJours($shop, $date <= $auj ? array_merge([$date], $habitude) : $habitude, 8);
    $mes = [];
    foreach ($habitude as $h) {
        $c = $R[$h] ?? null;
        if (is_array($c) && isset($c['t'])) { $mes[] = tmMesure((array) $c['t'], EQP_SEUIL_MORT, true); }
    }
    $jour = $date <= $auj && is_array($R[$date] ?? null) && isset($R[$date]['t']) ? tmMesure((array) $R[$date]['t'], EQP_SEUIL_MORT, true) : null;
    return eqpTempsMortsCalcul($habitude, $mes, $jour, $date === $auj);
}

/**
 * Le calcul (pur) : des mesures tmMesure (avec le détail des trous) des jours d'habitude, et celle du jour lui-même.
 * Un quart d'heure est mort un jour donné quand dix de ses minutes au moins sont dans un trou ; il est un creux habituel
 * quand il l'est au moins un jour mesuré sur deux (deux jours mesurés au moins).
 */
function eqpTempsMortsCalcul(array $habitude, array $mesures, ?array $jour, bool $enCours): array
{
    $quarts = array_fill(0, 96, 0); $tk = []; $mesures = array_values(array_filter($mesures, static fn ($m) => is_array($m) && empty($m['ferme'])));
    foreach ($mesures as $m) {
        foreach ((array) ($m['heures'] ?? []) as $hh => $x) { $tk[(int) $hh] = ($tk[(int) $hh] ?? 0) + (int) ($x['tk'] ?? 0); }
        $vu = [];
        foreach ((array) ($m['trous'] ?? []) as [$a, $b]) {
            for ($q = intdiv((int) $a, 900); $q * 900 < (int) $b && $q < 96; $q++) {
                $o = min((int) $b, ($q + 1) * 900) - max((int) $a, $q * 900);
                if ($o >= 600) { $vu[$q] = true; }   // dix minutes du quart d'heure sans ticket
            }
        }
        foreach (array_keys($vu) as $q) { $quarts[$q]++; }
    }
    $n = count($mesures);
    $creux = [];
    if ($n >= 2) {
        $deb = null;
        for ($q = 0; $q <= 96; $q++) {
            $on = $q < 96 && $quarts[$q] / $n >= EQP_CREUX_PART;
            if ($on && $deb === null) { $deb = $q; }
            if (!$on && $deb !== null) { $creux[] = [$deb * 15, $q * 15]; $deb = null; }
        }
    }
    $ticketsHeure = [];
    ksort($tk);
    foreach ($tk as $h => $t) { $ticketsHeure['h' . $h] = round($t / $n, 1); }
    $out = ['seuil' => EQP_SEUIL_MORT, 'habitude' => ['dates' => $habitude, 'jours' => $n, 'creux' => $creux, 'ticketsHeure' => (object) $ticketsHeure]];
    if (is_array($jour) && empty($jour['ferme'])) {
        $out['jour'] = ['etat' => $enCours ? 'encours' : 'lu', 'tickets' => (int) $jour['tickets'], 'mort' => (int) $jour['mort'],
            'trous' => array_map(static fn ($t) => [intdiv((int) $t[0], 60), (int) ceil((int) $t[1] / 60)], (array) ($jour['trous'] ?? []))];
    }
    return $out;
}

/**
 * Les heures d'ouverture de chaque jour de semaine, qui ne sont saisies nulle part (temps_morts.php) : la médiane du
 * premier et du dernier ticket des 28 jours d'avant aujourd'hui (tmMesure écarte les tickets isolés de bord).
 * Sert à vérifier le comptoir pendant l'ouverture. @return array<string, array{0:int,1:int}> minutes, clés '1'..'7'
 */
function eqpOuvertures(int $shop): array
{
    if (!function_exists('tmJours') || !function_exists('tmMesure')) { return []; }
    $auj = date('Y-m-d');
    $dates = array_map(static fn ($i) => date('Y-m-d', strtotime($auj . ' -' . $i . ' days')), range(1, 28));
    $par = [];
    foreach (tmJours($shop, $dates, 6) as $d => $c) {
        if (!is_array($c) || !isset($c['t'])) { continue; }
        $m = tmMesure((array) $c['t'], EQP_SEUIL_MORT);
        if (!empty($m['ferme'])) { continue; }
        $par[(int) date('N', strtotime((string) $d))][] = [eqpMinutes((string) $m['debut']), eqpMinutes((string) $m['fin'])];
    }
    return eqpOuverturesCalcul($par);
}

/** La médiane des débuts et des fins de chaque jour de semaine, au quart d'heure (début vers le bas, fin vers le haut). */
function eqpOuverturesCalcul(array $par): array
{
    $med = static function (array $v): float { sort($v); $n = count($v); return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; };
    $out = [];
    foreach ($par as $wd => $l) {
        $l = array_values(array_filter($l, static fn ($x) => is_int($x[0] ?? null) && is_int($x[1] ?? null) && $x[1] > $x[0]));
        if ($l === []) { continue; }
        $out[(string) $wd] = [(int) (floor($med(array_column($l, 0)) / 15) * 15), (int) (ceil($med(array_column($l, 1)) / 15) * 15)];
    }
    ksort($out);
    return $out;
}

/** GET /dashboard/equipe/planning?shop=&du=AAAA-MM-JJ&jours=1..14 — les services, l'équipe, le CA prévu, le budget. */
function ep_equipe_planning(): array
{
    $shop = eqpShop();
    if ($shop <= 0) { return eqpErreur(400, 'shop manquant'); }
    $du = (string) ($_GET['du'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || strtotime($du) === false) { return eqpErreur(400, 'du : AAAA-MM-JJ'); }
    $n = max(1, min(EQP_JOURS_MAX, (int) ($_GET['jours'] ?? 7)));
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return eqpErreur(503, 'compte du panel non configuré'); }
    $jours = array_map(static fn ($i) => date('Y-m-d', strtotime($du . ' +' . $i . ' days')), range(0, $n - 1));
    $chemins = ['emp' => '/shops/' . $shop . '/employees?include=positions', 'ws' => '/operational-framework/workstations', 'prod' => '/shops/' . $shop . '/products/available'];
    foreach ($jours as $j) { $chemins['sch' . $j] = '/shops/' . $shop . '/schedule?date=' . $j; }
    $res = PanelApi::getParallele($chemins, 6);
    if (!is_array($res['emp'] ?? null)) { return eqpErreur(502, 'le panel n’a pas rendu les employés', ['detail' => PanelApi::$lastError]); }
    $postes = [];
    foreach (analyseListe($res['ws'] ?? null) as $w) {
        if (is_array($w) && (int) ($w['id'] ?? 0) > 0) { $postes[(int) $w['id']] = isset($w['sector_id']) && (int) $w['sector_id'] > 0 ? (int) $w['sector_id'] : null; }
    }
    $secteurs = [];
    foreach (analyseListe($res['prod'] ?? null) as $p) {
        $sid = is_array($p) && isset($p['sector_id']) ? (int) $p['sector_id'] : 0;
        if ($sid > 0 && (!isset($secteurs[$sid]) || $secteurs[$sid] === '')) { $secteurs[$sid] = (string) ($p['sector_name'] ?? ''); }
    }
    $equipe = [];
    foreach (analyseListe($res['emp']) as $r) {
        if (!is_array($r)) { continue; }
        $e = eqpPersonne($r);
        if ($e['id'] < 1 || ($e['shop'] > 0 && $e['shop'] !== $shop)) { continue; }
        foreach ($e['postes'] as $i => $w) { $e['postes'][$i]['secteur'] = $postes[$w['id']] ?? null; }
        $e['secteurs'] = array_values(array_unique(array_filter(array_map(static fn ($w) => $w['secteur'], $e['postes']))));
        unset($e['shop']);
        $equipe[$e['id']] = $e;
    }
    $obj = function_exists('resObjectifsJours') ? resObjectifsJours($shop, $jours) : ['jours' => [], 'source' => null, 'motif' => 'objectifs indisponibles'];
    $out = []; $muets = [];
    foreach ($jours as $j) {
        $rep = $res['sch' . $j] ?? null;
        if (!is_array($rep)) { $muets[] = $j; }
        $sv = is_array($rep) ? eqpServices($rep, $shop, $j) : [];
        // Une personne au planning que la liste ne connaît pas (désactivée depuis) garde son nom.
        foreach ($sv as $s) {
            if ($s['employe'] > 0 && !isset($equipe[$s['employe']])) {
                $equipe[$s['employe']] = ['id' => $s['employe'], 'nom' => $s['nom'] ?: 'Employé ' . $s['employe'], 'fonctions' => [], 'postes' => [],
                    'actif' => false, 'taux' => null, 'franchise' => false, 'secteurs' => []];
            }
        }
        $out[] = ['date' => $j, 'objectif' => $obj['jours'][$j] ?? null, 'lu' => is_array($rep),
            'services' => array_map(static fn ($s) => array_diff_key($s, ['nom' => 1]), $sv)];
    }
    // Les inactifs ne restent que s'ils ont un service sur l'étendue.
    $auPlanning = [];
    foreach ($out as $j) { foreach ($j['services'] as $s) { $auPlanning[$s['employe']] = true; } }
    $equipe = array_filter($equipe, static fn ($e) => $e['actif'] || isset($auPlanning[$e['id']]));
    uasort($equipe, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    $sect = [];
    foreach ($secteurs as $id => $nom) { $sect[] = ['id' => $id, 'nom' => $nom !== '' ? $nom : 'Secteur ' . $id]; }
    usort($sect, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    $r = ['shop' => $shop, 'du' => $jours[0], 'au' => $jours[$n - 1], 'aujourdhui' => date('Y-m-d'), 'maintenant' => date('H:i'),
        'jours' => $out, 'employes' => array_values($equipe), 'secteurs' => $sect,
        'objectif' => ['source' => $obj['source'], 'motif' => $obj['motif']],
        'budget' => function_exists('personnelPaliers') ? personnelPaliers() : ['cible' => 17.0, 'rouge' => 20.0, 'noir' => 22.0, 'source' => 'départ'],
        'nonLus' => $muets, 'ouvertures' => (object) eqpOuvertures($shop)];
    if ($n === 1) { $r['tempsMorts'] = eqpTempsMorts($shop, $jours[0]); }
    return $r;
}

/** GET /dashboard/equipe/planning/pdf?shop=&du=&jours=7|14&presentation=magasin|departements — le PDF du panel (sort, puis exit). */
function ep_equipe_planning_pdf(): void
{
    $json = static function (int $code, string $msg): void {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit;
    };
    $shop = eqpShop(); $du = (string) ($_GET['du'] ?? '');
    $jours = (int) ($_GET['jours'] ?? 7); $pres = (string) ($_GET['presentation'] ?? 'magasin');
    if ($shop <= 0) { $json(400, 'shop manquant'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || abs(strtotime($du) - time()) > 400 * 86400) { $json(422, 'du : AAAA-MM-JJ, à un an au plus'); }
    if (!in_array($jours, [7, 14], true)) { $json(422, 'jours : 7 ou 14'); }
    if (!in_array($pres, ['magasin', 'departements'], true)) { $json(422, 'presentation : magasin ou departements'); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { $json(503, 'compte du panel non configuré'); }
    [$code, $pdf] = PanelApi::binaire('/shops/' . $shop . '/schedule/document?' . http_build_query(['start_date' => $du, 'days' => $jours, 'layout' => $pres === 'magasin' ? 'shop' : 'departments']));
    if ($code !== 200 || !is_string($pdf) || !str_starts_with($pdf, '%PDF')) {
        $json($code === 404 ? 404 : 502, $code === 404 ? 'le panel ne sert pas le PDF du planning' : 'le panel n’a pas rendu le PDF du planning (HTTP ' . $code . ')');
    }
    $nom = 'Planning_' . $shop . '_' . $du . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen((string) $pdf));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    echo $pdf;
    exit;
}

/* ── Modifier un service ──────────────────────────────────────────────────────────────────────────────────────── */

/**
 * Le contrôle commun d'une écriture de service : le magasin, la date (d'un mois avant à quatre mois après), les heures
 * (HH:MM, début avant fin), le planning du jour lu au panel. Rend [erreur, contexte].
 */
function eqpServiceCadre(array $b, bool $heures): array
{
    $shop = eqpShop($b); $date = is_string($b['date'] ?? null) ? $b['date'] : '';
    if ($shop <= 0) { return [eqpErreur(400, 'shop manquant'), null]; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) { return [eqpErreur(422, 'date : AAAA-MM-JJ'), null]; }
    $ecart = (strtotime($date) - strtotime(date('Y-m-d'))) / 86400;
    if ($ecart < -31 || $ecart > 120) { return [eqpErreur(422, 'date : d’un mois avant à quatre mois après aujourd’hui'), null]; }
    $de = $a = null;
    if ($heures) {
        $de = is_string($b['de'] ?? null) ? eqpMinutes($b['de']) : null; $a = is_string($b['a'] ?? null) ? eqpMinutes($b['a']) : null;
        if ($de === null || $a === null || $a <= $de || $a > 24 * 60) { return [eqpErreur(422, 'heures : de et a en HH:MM, le début avant la fin, dans la journée'), null]; }
        if ($de % 5 || $a % 5) { return [eqpErreur(422, 'heures : par pas de 5 minutes'), null]; }
    }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return [eqpErreur(503, 'compte du panel non configuré'), null]; }
    $rep = PanelApi::getParallele(['sch' => '/shops/' . $shop . '/schedule?date=' . $date, 'emp' => '/shops/' . $shop . '/employees'], 2);
    if (!is_array($rep['sch'] ?? null) || !is_array($rep['emp'] ?? null)) { return [eqpErreur(502, 'le panel ne répond pas : rien n’est enregistré'), null]; }
    $equipe = [];
    foreach (analyseListe($rep['emp']) as $r) { if (is_array($r)) { $e = eqpPersonne($r); if ($e['shop'] === $shop) { $equipe[$e['id']] = $e; } } }
    return [null, ['shop' => $shop, 'date' => $date, 'de' => $de, 'a' => $a, 'services' => eqpServices($rep['sch'], $shop, $date), 'equipe' => $equipe]];
}

/** Deux services d'une même personne le même jour ne se chevauchent pas (sauf le service lui-même). */
function eqpChevauche(array $services, int $emp, int $de, int $a, int $sauf = 0): ?array
{
    foreach ($services as $s) {
        if ($s['employe'] !== $emp || ($sauf > 0 && $s['id'] === $sauf)) { continue; }
        if ($de < eqpMinutes($s['a']) && $a > eqpMinutes($s['de'])) { return $s; }
    }
    return null;
}

/** La réponse du panel à une écriture de service, ou l'erreur à rendre. */
function eqpServiceRendu(array $r, string $quoi, array $c, string $journal): array
{
    if ($r['code'] < 200 || $r['code'] >= 300) {
        return eqpErreur(in_array($r['code'], [403, 404, 409, 422], true) ? $r['code'] : 502, 'le panel a refusé ' . $quoi, ['detail' => $r['erreur']]);
    }
    try { journalAdd('Magasin ' . $c['shop'], 'Planning', null, $journal); } catch (Throwable $e) { /* le journal ne bloque pas */ }
    $id = is_array($r['data']) ? (int) ($r['data']['inserted_id'] ?? $r['data']['id_schedule'] ?? $r['data']['id'] ?? ($r['data']['data']['id_schedule'] ?? 0)) : 0;
    return ['ok' => true, 'id' => $id ?: null, 'date' => $c['date']];
}

/** POST /dashboard/equipe/service {shop, employe, date, de, a} — un service ajouté sur le linéaire. */
function wr_equipe_service_creer(): array
{
    $b = body();
    [$err, $c] = eqpServiceCadre($b, true);
    if ($err !== null) { return $err; }
    $emp = is_int($b['employe'] ?? null) ? $b['employe'] : 0;
    $p = $c['equipe'][$emp] ?? null;
    if ($p === null) { return eqpErreur(404, 'cette personne n’est pas de l’équipe du magasin'); }
    if (!$p['actif']) { return eqpErreur(422, 'cette personne est désactivée'); }
    if (($x = eqpChevauche($c['services'], $emp, $c['de'], $c['a'])) !== null) { return eqpErreur(422, 'chevauche son service de ' . $x['de'] . ' à ' . $x['a'], ['motif' => 'chevauchement']); }
    $h = static fn (int $m) => sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    $r = PanelApi::requete('POST', '/schedule', ['id_employee' => $emp, 'work_date' => $c['date'], 'start_hour' => $h($c['de']), 'end_hour' => $h($c['a'])]);
    return eqpServiceRendu($r, 'le service', $c, 'Service ajouté : ' . $p['nom'] . ', ' . $c['date'] . ' ' . substr($h($c['de']), 0, 5) . '–' . substr($h($c['a']), 0, 5));
}

/** PATCH /dashboard/equipe/service {shop, id, date, de, a} — un service déplacé ou étiré sur son linéaire. */
function wr_equipe_service_modifier(): array
{
    $b = body();
    [$err, $c] = eqpServiceCadre($b, true);
    if ($err !== null) { return $err; }
    $id = is_int($b['id'] ?? null) ? $b['id'] : 0;
    $s = null;
    foreach ($c['services'] as $x) { if ($x['id'] === $id && $id > 0) { $s = $x; break; } }
    if ($s === null) { return eqpErreur(404, 'ce service n’est pas au planning du magasin ce jour-là', ['motif' => 'service_inconnu']); }
    if (($x = eqpChevauche($c['services'], $s['employe'], $c['de'], $c['a'], $id)) !== null) { return eqpErreur(422, 'chevauche son service de ' . $x['de'] . ' à ' . $x['a'], ['motif' => 'chevauchement']); }
    $h = static fn (int $m) => sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    $r = PanelApi::requete('PATCH', '/schedule/' . $id, ['start_hour' => $h($c['de']), 'end_hour' => $h($c['a'])]);
    $nom = ($c['equipe'][$s['employe']]['nom'] ?? null) ?: 'Employé ' . $s['employe'];
    return eqpServiceRendu($r, 'la modification', $c, 'Service modifié : ' . $nom . ', ' . $c['date'] . ' ' . $s['de'] . '–' . $s['a'] . ' → ' . substr($h($c['de']), 0, 5) . '–' . substr($h($c['a']), 0, 5));
}

/** DELETE /dashboard/equipe/service {shop, id, date} — un service retiré du planning. */
function wr_equipe_service_supprimer(): array
{
    $b = body();
    [$err, $c] = eqpServiceCadre($b, false);
    if ($err !== null) { return $err; }
    $id = is_int($b['id'] ?? null) ? $b['id'] : 0;
    $s = null;
    foreach ($c['services'] as $x) { if ($x['id'] === $id && $id > 0) { $s = $x; break; } }
    if ($s === null) { return eqpErreur(404, 'ce service n’est pas au planning du magasin ce jour-là', ['motif' => 'service_inconnu']); }
    $r = PanelApi::requete('DELETE', '/schedule/' . $id);
    $nom = ($c['equipe'][$s['employe']]['nom'] ?? null) ?: 'Employé ' . $s['employe'];
    return eqpServiceRendu($r, 'la suppression', $c, 'Service retiré : ' . $nom . ', ' . $c['date'] . ' ' . $s['de'] . '–' . $s['a']);
}
