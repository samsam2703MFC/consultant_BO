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
 * - RECOPIER LA SEMAINE PRÉCÉDENTE (23/11/2026, Sam : « le plus important est que ça aide le franchisé à faire ses
 *   horaires ») : GET /dashboard/equipe/semaine/recopie?shop=&du= — l'aperçu : les services de la semaine d'avant reportés
 *   sept jours plus tard, et ceux qui sont écartés avec leur motif (plus dans l'équipe, déjà au planning, chevauche) ;
 *   POST {shop, du, sources: [id…]} — crée au panel les services cochés, recalculés côté serveur (la liste du navigateur
 *   ne choisit que parmi eux) ; s'arrête au premier refus de droits (401, 403).
 *
 * - LA MATRICE A B C D et le SHIFT MAKER (test, 23/11/2026, Sam : « rajouter un shift maker (test) et la matrice a b c d
 *   (matin aprem, un week sur deux) ; rajouter dans la fiche des employés ») : quatre roulements sur deux semaines, matin
 *   ou après-midi, un week-end sur deux ; chaque employé porte sa lettre (sa fiche). La matrice du magasin, ses deux
 *   créneaux et sa semaine de référence : GET|POST /dashboard/equipe/matrice ; la lettre d'un employé : POST
 *   /dashboard/equipe/roulement {shop, employe, lettre} ; le shift maker : GET /dashboard/equipe/semaine/generer?du=
 *   (l'aperçu de la semaine tirée de la matrice) et POST {shop, du, cles} (crée les services cochés). Tables
 *   panel_equipe_matrice, panel_equipe_roulement (le panel ne connaît pas les roulements).
 *
 * Les écritures partent avec le compte du cockpit (PanelApi) : si le panel refuse (403), la réponse le dit telle quelle.
 * La route des employés rend aussi des secrets (hachages, jetons) : rien n'en sort que les champs listés ici.
 */

const EQP_POSTES_MAX = 300;          // le plafond du panel pour une liste de postes
const EQP_JOURS_MAX = 31;            // un planning lu couvre un mois au plus (la vue Mois, 23/11/2026)
const EQP_SEUIL_MORT = 10;           // un temps mort : 10 minutes et plus sans ticket (la valeur par défaut de l'onglet)
const EQP_CREUX_PART = 0.5;          // un creux habituel : un quart d'heure mort au moins un jour sur deux des 4 d'avant

function eqpRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/equipe' && !str_starts_with($path, '/dashboard/equipe/')) { return null; }
    if ($method === 'GET' && $path === '/dashboard/equipe/carte') { return ep_equipe_carte(); }
    if ($method === 'POST' && $path === '/dashboard/equipe/postes') { return wr_equipe_postes(); }
    if ($method === 'GET' && $path === '/dashboard/equipe/planning') { return ep_equipe_planning(); }
    if ($method === 'GET' && $path === '/dashboard/equipe/planning/pdf') { ep_equipe_planning_pdf(); return null; }
    if ($method === 'GET' && $path === '/dashboard/equipe/matrice') { return ep_equipe_matrice(); }
    if ($method === 'POST' && $path === '/dashboard/equipe/matrice') { return wr_equipe_matrice(); }
    if ($method === 'POST' && $path === '/dashboard/equipe/roulement') { return wr_equipe_roulement(); }
    if ($path === '/dashboard/equipe/semaine/generer') {
        if ($method === 'GET') { return ep_equipe_generer(); }
        if ($method === 'POST') { return wr_equipe_generer(); }
    }
    if ($path === '/dashboard/equipe/semaine/recopie') {
        if ($method === 'GET') { return ep_equipe_recopie(); }
        if ($method === 'POST') { return wr_equipe_recopie(); }
    }
    if ($path === '/dashboard/equipe/service') {
        if ($method === 'POST') { return wr_equipe_service_creer(); }
        if ($method === 'PATCH') { return wr_equipe_service_modifier(); }
        if ($method === 'DELETE') { return wr_equipe_service_supprimer(); }
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}

/* ── Le compte qui parle au panel (23/11/2026, Sam : « réessayer avec le compte admin ») ──────────────────────────
 * Le compte du cockpit (PanelApi, royaume consultant) d'abord ; ce qu'il n'obtient pas, le compte admin de l'ERP
 * (ErpApi) le relit ou le réécrit. Mesuré en ligne le 23/11/2026 : GET /shops/{id}/employees et /employees?shop_id=
 * ne rendent rien au compte du cockpit (403), alors que le swagger ne les restreint pas. Le cockpit garde ses propres
 * contrôles : le magasin de la session, l'employé et le service de ce magasin. */

/** Des GET en parallèle : [clé => corps | null] ; ce que le compte du cockpit n'obtient pas, l'admin le relit. */
function eqpGetParallele(array $chemins, int $front = 4): array
{
    $r = PanelApi::getParallele($chemins, $front);
    $manque = array_filter($chemins, static fn ($p, $k) => !is_array($r[$k] ?? null), ARRAY_FILTER_USE_BOTH);
    if ($manque !== [] && class_exists('ErpApi') && ErpApi::disponible()) {
        foreach (ErpApi::getParallele($manque, $front) as $k => $v) { if (is_array($v)) { $r[$k] = $v; } }
    }
    return $r;
}

function eqpGet(string $chemin): mixed
{
    return eqpGetParallele(['x' => $chemin], 1)['x'] ?? null;
}

/** Une écriture : {code, data, erreur} ; refusée au compte du cockpit (401, 403), elle repart avec le compte admin. */
function eqpEcrire(string $methode, string $chemin, ?array $corps = null): array
{
    $r = PanelApi::requete($methode, $chemin, $corps);
    if (in_array($r['code'], [401, 403], true) && class_exists('ErpApi') && ErpApi::disponible()) {
        $x = ErpApi::ecrire($methode, $chemin, $corps);
        return ['code' => (int) $x['code'], 'data' => $x['corps'], 'erreur' => $x['erreur']];
    }
    return $r;
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
    foreach (eqpGetParallele($chemins, 3) as $k => $r) {
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
        if ($L[$k] === null) { return eqpErreur(502, 'le panel n’a pas rendu ' . $quoi . ' : la carte est masquée pour ne pas signaler de faux manques', ['detail' => (class_exists('ErpApi') && ErpApi::$lastError ? ErpApi::$lastError : PanelApi::$lastError)]); }
    }
    // Sans le secteur des postes, aucune responsabilité ne se déduit (le module du panel refuse aussi).
    foreach ($L['ws'] as $w) {
        if (!array_key_exists('sector_id', $w)) { return eqpErreur(502, 'les postes du panel ne portent pas leur secteur (sector_id) : la carte ne peut pas se calculer'); }
    }
    $c = eqpCarte($shop, $L['emp'], $L['ws'], $L['prod']);
    $rl = eqpRoulements($shop);
    foreach ($c['employes'] as &$e) { $e['lettre'] = $rl[$e['id']] ?? null; }
    unset($e);
    return ['shop' => $shop] + $c;
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
    $r = eqpEcrire('PUT', '/employees/' . $emp . '/workstations', ['workstation_ids' => $ids]);
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

/** GET /dashboard/equipe/planning?shop=&du=AAAA-MM-JJ&jours=1..31 — les services, l'équipe, le CA prévu, le budget. */
function ep_equipe_planning(): array
{
    $shop = eqpShop();
    if ($shop <= 0) { return eqpErreur(400, 'shop manquant'); }
    $du = (string) ($_GET['du'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || strtotime($du) === false) { return eqpErreur(400, 'du : AAAA-MM-JJ'); }
    $n = max(1, min(EQP_JOURS_MAX, (int) ($_GET['jours'] ?? 7)));
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return eqpErreur(503, 'compte du panel non configuré'); }
    return eqpPlanningDonnees($shop, $du, $n);
}

/**
 * La durée d'un service, en heures (23/11/2026, Sam : « en rouge si plus court que 4 et si plus que 8 ») : la ligne tirée
 * dans la fenêtre d'un service est verte de `min` à `max`, rouge en dehors ; un marqueur, pas un refus. Réglage
 * `equipeDureesService` ({min, max}), sinon 4 h et 8 h.
 */
function eqpDurees(): array
{
    $s = function_exists('setting') ? setting('equipeDureesService', null) : null;
    if (is_array($s) && is_numeric($s['min'] ?? null) && is_numeric($s['max'] ?? null)) {
        $min = (float) $s['min']; $max = (float) $s['max'];
        if ($min > 0 && $max > $min && $max <= 24) { return ['min' => $min, 'max' => $max, 'source' => 'réglage']; }
    }
    return ['min' => 4.0, 'max' => 8.0, 'source' => 'départ'];
}

/**
 * Le chiffre d'un jour pour les % du personnel (23/11/2026, Sam : « le chiffre du jour complet, comptoir + B2B, pour
 * calculer les % ») : un jour passé, ce que la caisse a fait (margin-heatmap : comptoir et B2B ensemble, TTC comme
 * l'objectif) ; aujourd'hui, la projection de fin de journée du Résultat du jour (le relevé /exploitation/jour gardé
 * depuis moins d'une heure), sinon le fait s'il passe déjà l'objectif ; un jour à venir : rien, l'écran prend l'objectif.
 * Rend [ca, source] : source 'reel', 'projection', 'encours' ou null.
 */
function eqpCaDuJour(int $shop, string $j, string $auj, ?float $caisse, ?float $objectif): array
{
    if ($j < $auj) { return $caisse !== null ? [round($caisse, 2), 'reel'] : [null, null]; }
    if ($j > $auj) { return [null, null]; }
    $c = function_exists('setting') ? setting('exJour:' . $j, null) : null;
    if (is_array($c) && is_array($c['r'] ?? null) && time() - (int) ($c['le'] ?? 0) <= 3600) {
        foreach ((array) ($c['r']['magasins'] ?? []) as $m) {
            if (is_array($m) && (int) ($m['shopId'] ?? 0) === $shop && is_numeric($m['projection'] ?? null) && (float) $m['projection'] > 0) {
                return [round(max((float) $m['projection'], (float) ($caisse ?? 0)), 2), 'projection'];
            }
        }
    }
    if ($caisse !== null && ($objectif === null || $caisse >= $objectif)) { return [round($caisse, 2), 'encours']; }
    return [null, null];
}

/** Le planning de $n jours à partir de $du : ce que rend GET /dashboard/equipe/planning (l'écran et le PDF). */
function eqpPlanningDonnees(int $shop, string $du, int $n): array
{
    $jours = array_map(static fn ($i) => date('Y-m-d', strtotime($du . ' +' . $i . ' days')), range(0, $n - 1));
    $chemins = ['emp' => '/shops/' . $shop . '/employees?include=positions', 'ws' => '/operational-framework/workstations', 'prod' => '/shops/' . $shop . '/products/available'];
    foreach ($jours as $j) { $chemins['sch' . $j] = '/shops/' . $shop . '/schedule?date=' . $j; }
    // Le CA des jours passés et du jour : la caisse, comptoir et B2B ensemble (margin-heatmap, un mois par lecture).
    $auj = date('Y-m-d');
    $passes = array_values(array_filter($jours, static fn ($j) => $j <= $auj));
    foreach (array_unique(array_map(static fn ($j) => substr($j, 0, 7), $passes)) as $ym) {
        $dansMois = array_values(array_filter($passes, static fn ($j) => substr($j, 0, 7) === $ym));
        $chemins['hm' . $ym] = '/consultant/shops/' . $shop . '/margin-heatmap?' . http_build_query(['from' => $dansMois[0], 'to' => end($dansMois)]);
    }
    $res = eqpGetParallele($chemins, 6);
    $caCaisse = [];
    foreach ($res as $k => $hm) {
        if (!str_starts_with((string) $k, 'hm') || !is_array($hm)) { continue; }
        foreach ((array) ($hm['days'] ?? []) as $d) {
            if (is_array($d) && !empty($d['has_data']) && is_numeric($d['ca'] ?? null) && (float) $d['ca'] > 0 && !empty($d['date'])) { $caCaisse[substr((string) $d['date'], 0, 10)] = (float) $d['ca']; }
        }
    }
    if (!is_array($res['emp'] ?? null)) { return eqpErreur(502, 'le panel n’a pas rendu les employés', ['detail' => (class_exists('ErpApi') && ErpApi::$lastError ? ErpApi::$lastError : PanelApi::$lastError)]); }
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
        [$ca, $caSrc] = eqpCaDuJour($shop, $j, $auj, $caCaisse[$j] ?? null, $obj['jours'][$j] ?? null);
        $out[] = ['date' => $j, 'objectif' => $obj['jours'][$j] ?? null, 'ca' => $ca, 'caSource' => $caSrc, 'caEnCours' => $j === $auj ? ($caCaisse[$j] ?? null) : null,
            'lu' => is_array($rep), 'services' => array_map(static fn ($s) => array_diff_key($s, ['nom' => 1]), $sv)];
    }
    // Les inactifs ne restent que s'ils ont un service sur l'étendue.
    $auPlanning = [];
    foreach ($out as $j) { foreach ($j['services'] as $s) { $auPlanning[$s['employe']] = true; } }
    $equipe = array_filter($equipe, static fn ($e) => $e['actif'] || isset($auPlanning[$e['id']]));
    uasort($equipe, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    $rl = eqpRoulements($shop);
    foreach ($equipe as &$e) { $e['lettre'] = $rl[$e['id']] ?? null; }
    unset($e);
    $sect = [];
    foreach ($secteurs as $id => $nom) { $sect[] = ['id' => $id, 'nom' => $nom !== '' ? $nom : 'Secteur ' . $id]; }
    usort($sect, static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    $r = ['shop' => $shop, 'du' => $jours[0], 'au' => $jours[$n - 1], 'aujourdhui' => date('Y-m-d'), 'maintenant' => date('H:i'),
        'jours' => $out, 'employes' => array_values($equipe), 'secteurs' => $sect,
        'objectif' => ['source' => $obj['source'], 'motif' => $obj['motif']],
        'budget' => function_exists('personnelPaliers') ? personnelPaliers() : ['cible' => 17.0, 'rouge' => 20.0, 'noir' => 22.0, 'source' => 'départ'],
        'durees' => eqpDurees(),
        'nonLus' => $muets, 'ouvertures' => (object) eqpOuvertures($shop)];
    if ($n === 1) { $r['tempsMorts'] = eqpTempsMorts($shop, $jours[0]); }
    return $r;
}

/**
 * GET /dashboard/equipe/planning/pdf?shop=&du=&jours=7|14&presentation=magasin|departements — le planning imprimé, fait par
 * le cockpit (23/11/2026 : « le panel ne sert pas le PDF du planning » ; la route /shops/{id}/schedule/document du module
 * du panel n'est pas dans son swagger). A4 paysage au gabarit des documents (§13.4) : une page par semaine, ou par
 * semaine et par département (le secteur du premier poste, sinon le poste). Sort, puis exit.
 */
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
    if (!function_exists('rapPdfRendu')) { $json(501, 'aucun moteur PDF sur ce serveur'); }
    $P = eqpPlanningDonnees($shop, $du, $jours);
    if (isset($P['error'])) { $json(502, (string) $P['error']); }
    $nom = function_exists('magasinNom') ? (magasinNom((string) $shop) ?? 'Magasin ' . $shop) : 'Magasin ' . $shop;
    $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Planning</title><style>'
        . (function_exists('docCss') ? docCss() : '') . eqpPdfCss() . '</style></head><body>' . eqpPlanningPdfHtml($P, $pres, $nom) . '</body></html>';
    $pdf = rapPdfRendu($html, ['magasin' => $nom, 'rapport' => 'Planning de l’équipe', 'genere' => date('d/m/Y à H:i'), 'envoye' => '', 'paysage' => true]);
    if ($pdf === null || !str_starts_with($pdf, '%PDF')) { $json(501, 'le PDF ne s’est pas fait sur ce serveur'); }
    $fichier = 'Planning_' . preg_replace('/[^\w\-]+/u', '_', $nom) . '_' . $du . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . $fichier . '"');
    echo $pdf;
    exit;
}

function eqpPdfCss(): string
{
    return '.pl{width:100%;border-collapse:collapse;font-size:9.5px;table-layout:fixed}'
        . '.pl th{font-weight:400;font-size:8px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;background:#FBF9F5;padding:6px 5px;text-align:left;border-bottom:1px solid #e6e0d8}'
        . '.pl td{padding:5px;border-bottom:1px solid #eee8e0;vertical-align:top}'
        . '.pl td.n{width:17%}.pl td.h,.pl th.h{width:6%;text-align:right}'
        . '.pl .nm{font-size:10px}.pl .po{display:block;font-size:8px;color:#7a736a}'
        . '.pl .sv{display:block;white-space:nowrap}.pl .vide{color:#B8B2A8}'
        . '.pl tr.tot td{background:#F8F4EE;font-size:8.5px;color:#5c5852}'
        . '.pl-t{margin:0 0 8px;font-size:12px}.pl-t span{color:#7a736a;font-size:9.5px}'
        . '.pl-page{page-break-after:always}.pl-page:last-child{page-break-after:auto}';
}

/**
 * LE PLANNING IMPRIMÉ (pur) : une page par semaine (et par département si demandé) ; une ligne par personne (les
 * actifs, et ceux qui ont un service), une colonne par jour, les heures de la semaine ; la ligne « Par jour ».
 */
function eqpPlanningPdfHtml(array $P, string $presentation, string $magasin): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $jn = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $secteurs = [];
    foreach ((array) ($P['secteurs'] ?? []) as $s) { $secteurs[(int) $s['id']] = (string) $s['nom']; }
    $groupe = static function (array $p) use ($secteurs): string {
        $sid = (int) (($p['secteurs'] ?? [])[0] ?? 0);
        if ($sid > 0) { return $secteurs[$sid] ?? 'Secteur ' . $sid; }
        foreach ((array) ($p['postes'] ?? []) as $w) { if (empty($w['secteur']) && preg_match('/vente|vendeu|comptoir|caisse|verkoop|kassa|sprzeda|sales|counter/i', (string) ($w['nom'] ?? ''))) { return 'Comptoir'; } }
        return (string) ((($p['postes'] ?? [])[0]['nom'] ?? '') ?: 'Sans poste');
    };
    $semaines = array_chunk((array) ($P['jours'] ?? []), 7);
    $pages = [];
    foreach ($semaines as $sem) {
        $dates = array_column($sem, 'date');
        $parEmp = [];
        foreach ($sem as $J) { foreach ((array) $J['services'] as $s) { $parEmp[(int) $s['employe']][$J['date']][] = $s; } }
        $gens = array_values(array_filter((array) ($P['employes'] ?? []), static fn ($p) => !empty($p['actif']) || isset($parEmp[(int) $p['id']])));
        $lots = $presentation === 'departements' ? [] : ['' => $gens];
        if ($presentation === 'departements') { foreach ($gens as $p) { $lots[$groupe($p)][] = $p; } ksort($lots, SORT_NATURAL | SORT_FLAG_CASE); }
        $d0 = $dates[0]; $d1 = end($dates);
        $titreSem = 'Semaine du ' . (int) substr($d0, 8, 2) . (substr($d0, 5, 2) !== substr($d1, 5, 2) ? ' ' . $mois[(int) substr($d0, 5, 2)] : '') . ' au ' . (int) substr($d1, 8, 2) . ' ' . $mois[(int) substr($d1, 5, 2)] . ' ' . substr($d1, 0, 4);
        foreach ($lots as $dep => $L) {
            $th = '<tr><th class="n">Employé</th>';
            foreach ($dates as $d) { $th .= '<th>' . $jn[(int) date('N', strtotime($d)) - 1] . ' ' . (int) substr($d, 8, 2) . '</th>'; }
            $th .= '<th class="h">Heures</th></tr>';
            $rows = ''; $parJour = array_fill_keys($dates, [0, 0.0]); $tot = 0.0;
            foreach ($L as $p) {
                $id = (int) $p['id']; $h = 0.0;
                $rows .= '<tr><td class="n"><span class="nm">' . $e($p['nom']) . '</span><span class="po">' . $e(implode(', ', array_map(static fn ($w) => (string) $w['nom'], (array) ($p['postes'] ?? []))) ?: 'aucun poste') . '</span></td>';
                foreach ($dates as $d) {
                    $sv = $parEmp[$id][$d] ?? [];
                    $c = '';
                    foreach ($sv as $s) {
                        $m = (eqpMinutes($s['a']) - eqpMinutes($s['de'])) / 60; $h += $m;
                        $parJour[$d][0]++; $parJour[$d][1] += $m;
                        $c .= '<span class="sv">' . $e($s['de']) . '–' . $e($s['a']) . '</span>';
                    }
                    $rows .= '<td>' . ($c !== '' ? $c : '<span class="vide">—</span>') . '</td>';
                }
                $tot += $h;
                $rows .= '<td class="h">' . ($h > 0 ? str_replace('.', ',', (string) round($h, 1)) . ' h' : '—') . '</td></tr>';
            }
            $rows .= '<tr class="tot"><td class="n">Par jour</td>';
            foreach ($dates as $d) { $rows .= '<td>' . $parJour[$d][0] . ' serv. · ' . str_replace('.', ',', (string) round($parJour[$d][1], 1)) . ' h</td>'; }
            $rows .= '<td class="h">' . str_replace('.', ',', (string) round($tot, 1)) . ' h</td></tr>';
            $entete = function_exists('docEntete') ? docEntete('Planning de l’équipe', $magasin . ' · ' . $titreSem) : '<h2>Planning de l’équipe · ' . $e($magasin . ' · ' . $titreSem) . '</h2>';
            $pages[] = '<div class="pl-page">' . $entete . ($dep !== '' ? '<p class="pl-t">' . $e($dep) . ' <span>· ' . count($L) . ' personne' . (count($L) > 1 ? 's' : '') . '</span></p>' : '')
                . '<table class="pl">' . $th . $rows . '</table></div>';
        }
    }
    return implode('', $pages);
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
    $rep = eqpGetParallele(['sch' => '/shops/' . $shop . '/schedule?date=' . $date, 'emp' => '/shops/' . $shop . '/employees'], 2);
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
    $r = eqpEcrire('POST', '/schedule', ['id_employee' => $emp, 'work_date' => $c['date'], 'start_hour' => $h($c['de']), 'end_hour' => $h($c['a'])]);
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
    $r = eqpEcrire('PATCH', '/schedule/' . $id, ['start_hour' => $h($c['de']), 'end_hour' => $h($c['a'])]);
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
    $r = eqpEcrire('DELETE', '/schedule/' . $id);
    $nom = ($c['equipe'][$s['employe']]['nom'] ?? null) ?: 'Employé ' . $s['employe'];
    return eqpServiceRendu($r, 'la suppression', $c, 'Service retiré : ' . $nom . ', ' . $c['date'] . ' ' . $s['de'] . '–' . $s['a']);
}

/* ── Recopier la semaine précédente ───────────────────────────────────────────────────────────────────────────── */

const EQP_RECOPIE_MAX = 300;   // services recopiés au plus d'un coup

/**
 * LE CALCUL (pur) : chaque service de la semaine source, reporté de sept jours. Écarté s'il n'y a plus la personne
 * (inconnue ou désactivée), s'il est déjà au planning à la même heure, ou s'il chevauche un service de la personne ce
 * jour-là (déjà prévu, ou recopié juste avant). $source et $cible : date → services (eqpServices) ; $equipe : id → personne.
 * @return array{propositions: list<array>, ecartes: list<array>}
 */
function eqpRecopieCalcul(array $source, array $cible, array $equipe): array
{
    $prop = []; $ecart = []; $pris = [];
    foreach ($cible as $d => $L) { foreach ($L as $s) { $pris[$d][$s['employe']][] = [eqpMinutes($s['de']), eqpMinutes($s['a']), $s['de'], $s['a']]; } }
    ksort($source);
    foreach ($source as $d => $L) {
        $t = date('Y-m-d', strtotime($d . ' +7 days'));
        foreach ($L as $s) {
            $x = ['source' => $s['id'], 'employe' => $s['employe'], 'date' => $t, 'de' => $s['de'], 'a' => $s['a']];
            $p = $equipe[$s['employe']] ?? null;
            if ($p === null) { $ecart[] = $x + ['motif' => 'plus dans l’équipe']; continue; }
            if (empty($p['actif'])) { $ecart[] = $x + ['motif' => 'désactivé']; continue; }
            $de = eqpMinutes($s['de']); $a = eqpMinutes($s['a']); $motif = null;
            foreach ($pris[$t][$s['employe']] ?? [] as [$x0, $x1, $h0, $h1]) {
                if ($x0 === $de && $x1 === $a) { $motif = 'déjà au planning'; break; }
                if ($de < $x1 && $a > $x0) { $motif = 'chevauche ' . $h0 . '–' . $h1; break; }
            }
            if ($motif !== null) { $ecart[] = $x + ['motif' => $motif]; continue; }
            $pris[$t][$s['employe']][] = [$de, $a, $s['de'], $s['a']];
            $prop[] = $x;
        }
    }
    return ['propositions' => $prop, 'ecartes' => $ecart];
}

/** Les deux semaines lues au panel et le calcul ; [erreur, contexte]. */
function eqpRecopiePlan(int $shop, string $du): array
{
    if ($shop <= 0) { return [eqpErreur(400, 'shop manquant'), null]; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || strtotime($du) === false) { return [eqpErreur(422, 'du : AAAA-MM-JJ'), null]; }
    $ecart = (strtotime($du) - strtotime(date('Y-m-d'))) / 86400;
    if ($ecart < -31 || $ecart > 120) { return [eqpErreur(422, 'semaine : d’un mois avant à quatre mois après aujourd’hui'), null]; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return [eqpErreur(503, 'compte du panel non configuré'), null]; }
    $cible = array_map(static fn ($i) => date('Y-m-d', strtotime($du . ' +' . $i . ' days')), range(0, 6));
    $source = array_map(static fn ($d) => date('Y-m-d', strtotime($d . ' -7 days')), $cible);
    $chemins = ['emp' => '/shops/' . $shop . '/employees'];
    foreach (array_merge($source, $cible) as $d) { $chemins['sch' . $d] = '/shops/' . $shop . '/schedule?date=' . $d; }
    $res = eqpGetParallele($chemins, 6);
    if (!is_array($res['emp'] ?? null)) { return [eqpErreur(502, 'le panel n’a pas rendu les employés : rien n’est recopié'), null]; }
    $S = []; $C = [];
    foreach ($source as $d) {
        if (!is_array($res['sch' . $d] ?? null)) { return [eqpErreur(502, 'le panel n’a pas rendu le planning du ' . $d . ' : rien n’est recopié'), null]; }
        $S[$d] = eqpServices($res['sch' . $d], $shop, $d);
    }
    foreach ($cible as $d) {
        // Sans le planning cible, on ne saurait pas ce qui est déjà prévu : on ne recopie pas à l'aveugle.
        if (!is_array($res['sch' . $d] ?? null)) { return [eqpErreur(502, 'le panel n’a pas rendu le planning du ' . $d . ' : rien n’est recopié'), null]; }
        $C[$d] = eqpServices($res['sch' . $d], $shop, $d);
    }
    $equipe = [];
    foreach (analyseListe($res['emp']) as $r) { if (is_array($r)) { $e = eqpPersonne($r); if ($e['shop'] === $shop) { $equipe[$e['id']] = $e; } } }
    $noms = [];
    foreach ($S as $L) { foreach ($L as $s) { if ($s['nom'] !== '') { $noms[$s['employe']] = $s['nom']; } } }
    $calc = eqpRecopieCalcul($S, $C, $equipe);
    $nom = static fn (array $x) => ['nom' => ($equipe[$x['employe']]['nom'] ?? null) ?: ($noms[$x['employe']] ?? 'Employé ' . $x['employe'])];
    return [null, ['du' => $du, 'source' => ['du' => $source[0], 'au' => $source[6], 'services' => array_sum(array_map('count', $S))],
        'dejaPrevus' => array_sum(array_map('count', $C)),
        'propositions' => array_map(static fn ($x) => $x + $nom($x), $calc['propositions']),
        'ecartes' => array_map(static fn ($x) => $x + $nom($x), $calc['ecartes'])]];
}

/** GET /dashboard/equipe/semaine/recopie?shop=&du= — l'aperçu, rien n'est écrit. */
function ep_equipe_recopie(): array
{
    [$err, $c] = eqpRecopiePlan(eqpShop(), (string) ($_GET['du'] ?? ''));
    return $err ?? ['shop' => eqpShop()] + $c;
}

/** POST /dashboard/equipe/semaine/recopie {shop, du, sources: [id…]} — crée au panel les services cochés de l'aperçu. */
function wr_equipe_recopie(): array
{
    $b = body();
    $shop = eqpShop($b);
    $ids = $b['sources'] ?? null;
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > EQP_RECOPIE_MAX) { return eqpErreur(422, 'sources : la liste des services à recopier'); }
    $voulus = [];
    foreach ($ids as $x) { if (!is_int($x) || $x < 1) { return eqpErreur(422, 'sources : des numéros de services'); } $voulus[$x] = true; }
    [$err, $c] = eqpRecopiePlan($shop, is_string($b['du'] ?? null) ? $b['du'] : '');
    if ($err !== null) { return $err; }
    $a = array_values(array_filter($c['propositions'], static fn ($p) => isset($voulus[$p['source']])));
    if ($a === []) { return eqpErreur(422, 'aucun des services cochés n’est encore à recopier : relisez l’aperçu'); }
    return eqpCreerServices($shop, $a, static fn (int $n) => 'Semaine du ' . $c['du'] . ' : ' . $n . ' service' . ($n > 1 ? 's' : '') . ' recopié' . ($n > 1 ? 's' : '') . ' de la semaine du ' . $c['source']['du'], $c['du']);
}

/**
 * Crée au panel les services d'un aperçu (recopie, shift maker), un par un ; s'arrête au premier refus de droits.
 * $journal(n) : la ligne du journal quand n services sont créés.
 */
function eqpCreerServices(int $shop, array $a, callable $journal, string $du): array
{
    @set_time_limit(180);
    $crees = 0; $echecs = []; $arret = null;
    foreach ($a as $p) {
        $r = eqpEcrire('POST', '/schedule', ['id_employee' => $p['employe'], 'work_date' => $p['date'], 'start_hour' => $p['de'] . ':00', 'end_hour' => $p['a'] . ':00']);
        if ($r['code'] >= 200 && $r['code'] < 300) { $crees++; continue; }
        $echecs[] = ['source' => $p['source'] ?? null, 'cle' => $p['cle'] ?? null, 'nom' => $p['nom'], 'date' => $p['date'], 'de' => $p['de'], 'a' => $p['a'], 'motif' => (string) ($r['erreur'] ?? 'refusé')];
        // Un refus de droits vaudra pour tous les suivants : on s'arrête là plutôt que d'en essuyer cent.
        if (in_array($r['code'], [401, 403], true)) { $arret = 'le panel refuse les écritures du planning à ce compte (HTTP ' . $r['code'] . ')'; break; }
    }
    if ($crees > 0) {
        try { journalAdd('Magasin ' . $shop, 'Planning', null, $journal($crees)); }
        catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
    if ($crees === 0) { return eqpErreur($arret !== null ? 403 : 502, $arret ?? 'le panel a refusé tous les services', ['echecs' => $echecs]); }
    return ['ok' => true, 'du' => $du, 'crees' => $crees, 'demandes' => count($a), 'echecs' => $echecs, 'arret' => $arret];
}

/* ── La matrice A B C D et le shift maker (test) ──────────────────────────────────────────────────────────────── */

const EQP_LETTRES = ['A', 'B', 'C', 'D'];
const EQP_CODES = ['M', 'P', 'R'];   // matin, après-midi (« aprem »), repos

function ensureEquipeMatrice(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_equipe_matrice ('
        . 'shop_id VARCHAR(16) NOT NULL PRIMARY KEY,'
        . 'matrice TEXT NOT NULL,'                 // {creneaux: {M, P}, reference, lettres: {A: [[7], [7]], …}}
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_equipe_roulement ('
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'employe_id INT UNSIGNED NOT NULL,'      // l'identifiant de l'employé au panel
        . 'lettre CHAR(1) NOT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'PRIMARY KEY (shop_id, employe_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/**
 * La matrice de départ : deux semaines, un week-end sur deux, matin et après-midi qui s'échangent d'une semaine à
 * l'autre. A et C tiennent le week-end de la semaine 1 (repos lundi et mardi), B et D celui de la semaine 2 ; chaque jour
 * a au moins un matin et un après-midi. Créneaux : matin 07:00–14:00, après-midi 12:30–18:30 (ils se croisent à midi).
 */
function eqpMatriceDepart(?string $reference = null): array
{
    $c = static fn (string $x) => str_split($x);
    return ['creneaux' => ['M' => ['de' => '07:00', 'a' => '14:00'], 'P' => ['de' => '12:30', 'a' => '18:30']],
        'reference' => $reference ?? date('Y-m-d', strtotime('monday this week')),
        'lettres' => ['A' => [$c('RRMMMMM'), $c('PPPPPRR')], 'B' => [$c('PPPPPRR'), $c('RRMMMMM')],
            'C' => [$c('RRPPPPP'), $c('MMMMMRR')], 'D' => [$c('MMMMMRR'), $c('RRPPPPP')]]];
}

/** Une matrice reçue, vérifiée et remise en forme ; null si elle ne tient pas. La référence est ramenée à son lundi. */
function eqpMatriceValide(mixed $m): ?array
{
    if (!is_array($m) || !is_array($m['creneaux'] ?? null) || !is_array($m['lettres'] ?? null)) { return null; }
    $cr = [];
    foreach (['M', 'P'] as $k) {
        $x = $m['creneaux'][$k] ?? null;
        $de = is_array($x) && is_string($x['de'] ?? null) ? eqpMinutes($x['de']) : null; $a = is_array($x) && is_string($x['a'] ?? null) ? eqpMinutes($x['a']) : null;
        if ($de === null || $a === null || $a <= $de || $a > 24 * 60 || $de % 5 || $a % 5) { return null; }
        $cr[$k] = ['de' => sprintf('%02d:%02d', intdiv($de, 60), $de % 60), 'a' => sprintf('%02d:%02d', intdiv($a, 60), $a % 60)];
    }
    $ref = is_string($m['reference'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $m['reference']) && strtotime($m['reference']) !== false ? $m['reference'] : null;
    if ($ref === null) { return null; }
    $ref = date('Y-m-d', strtotime($ref . ' -' . ((int) date('N', strtotime($ref)) - 1) . ' days'));
    $L = [];
    foreach (EQP_LETTRES as $l) {
        $s = $m['lettres'][$l] ?? null;
        if (!is_array($s) || count($s) !== 2) { return null; }
        foreach ($s as $i => $sem) {
            if (!is_array($sem) || count($sem) !== 7) { return null; }
            foreach ($sem as $code) { if (!in_array($code, EQP_CODES, true)) { return null; } }
            $L[$l][$i] = array_values($sem);
        }
    }
    return ['creneaux' => $cr, 'reference' => $ref, 'lettres' => $L];
}

/** La semaine du roulement (0 : semaine 1, 1 : semaine 2) du lundi $lundi, la semaine de $reference étant la semaine 1. */
function eqpCycle(string $reference, string $lundi): int
{
    $n = (int) round((strtotime($lundi . ' 12:00') - strtotime($reference . ' 12:00')) / 86400);
    return ((intdiv($n - (($n % 7 + 7) % 7), 7) % 2) + 2) % 2;
}

/**
 * LE SHIFT MAKER (pur) : la semaine de $du tirée de la matrice, pour chaque employé qui a une lettre. Écarté : plus
 * dans l'équipe, désactivé, déjà au planning, chevauche un service déjà prévu. `sansLettre` : les actifs sans roulement.
 */
function eqpShiftMaker(array $matrice, array $roulements, array $equipe, array $cible, string $du): array
{
    $cycle = eqpCycle($matrice['reference'], $du);
    $prop = []; $ecart = []; $pris = [];
    foreach ($cible as $d => $L) { foreach ($L as $s) { $pris[$d][$s['employe']][] = [eqpMinutes($s['de']), eqpMinutes($s['a']), $s['de'], $s['a']]; } }
    ksort($roulements);
    foreach ($roulements as $emp => $lettre) {
        if (!isset($matrice['lettres'][$lettre])) { continue; }
        $p = $equipe[$emp] ?? null;
        foreach ($matrice['lettres'][$lettre][$cycle] as $i => $code) {
            if ($code === 'R') { continue; }
            $cr = $matrice['creneaux'][$code];
            $date = date('Y-m-d', strtotime($du . ' +' . $i . ' days'));
            $x = ['cle' => 'e' . $emp . '-' . $date . '-' . $cr['de'], 'employe' => (int) $emp, 'lettre' => $lettre, 'creneau' => $code, 'date' => $date, 'de' => $cr['de'], 'a' => $cr['a']];
            if ($p === null) { $ecart[] = $x + ['motif' => 'plus dans l’équipe']; continue; }
            if (empty($p['actif'])) { $ecart[] = $x + ['motif' => 'désactivé']; continue; }
            $de = eqpMinutes($cr['de']); $a = eqpMinutes($cr['a']); $motif = null;
            foreach ($pris[$date][$emp] ?? [] as [$x0, $x1, $h0, $h1]) {
                if ($x0 === $de && $x1 === $a) { $motif = 'déjà au planning'; break; }
                if ($de < $x1 && $a > $x0) { $motif = 'chevauche ' . $h0 . '–' . $h1; break; }
            }
            if ($motif !== null) { $ecart[] = $x + ['motif' => $motif]; continue; }
            $pris[$date][$emp][] = [$de, $a, $cr['de'], $cr['a']];
            $prop[] = $x;
        }
    }
    usort($prop, static fn ($x, $y) => strcmp($x['date'] . $x['de'], $y['date'] . $y['de']) ?: $x['employe'] <=> $y['employe']);
    $sans = array_values(array_map(static fn ($e) => $e['id'], array_filter($equipe, static fn ($e) => !empty($e['actif']) && !isset($roulements[$e['id']]))));
    return ['cycle' => $cycle + 1, 'propositions' => $prop, 'ecartes' => $ecart, 'sansLettre' => $sans];
}

/** La matrice du magasin (celle de départ s'il n'en a pas). @return array{0: array, 1: bool} [matrice, de départ ?] */
function eqpMatriceLire(int $shop): array
{
    try {
        ensureEquipeMatrice();
        $r = Db::row('SELECT matrice FROM panel_equipe_matrice WHERE shop_id = ?', [(string) $shop]);
        $m = $r !== null ? eqpMatriceValide(json_decode((string) $r['matrice'], true)) : null;
        if ($m !== null) { return [$m, false]; }
    } catch (Throwable $e) { /* la matrice de départ */ }
    return [eqpMatriceDepart(), true];
}

/** Les lettres des employés du magasin : [id => 'A'…]. */
function eqpRoulements(int $shop): array
{
    try {
        ensureEquipeMatrice();
        $o = [];
        foreach (Db::rows('SELECT employe_id, lettre FROM panel_equipe_roulement WHERE shop_id = ?', [(string) $shop]) as $r) {
            if (in_array($r['lettre'], EQP_LETTRES, true)) { $o[(int) $r['employe_id']] = $r['lettre']; }
        }
        return $o;
    } catch (Throwable $e) { return []; }
}

function eqpQui(): ?string
{
    $i = function_exists('accesIdentite') ? accesIdentite() : null;
    return is_array($i) ? mb_substr((string) ($i['nom'] ?? ''), 0, 120) : null;
}

/** GET /dashboard/equipe/matrice?shop= */
function ep_equipe_matrice(): array
{
    $shop = eqpShop();
    if ($shop <= 0) { return eqpErreur(400, 'shop manquant'); }
    [$m, $depart] = eqpMatriceLire($shop);
    return ['shop' => $shop, 'matrice' => $m, 'depart' => $depart, 'roulements' => (object) eqpRoulements($shop), 'lettres' => EQP_LETTRES];
}

/** POST /dashboard/equipe/matrice {shop, matrice} — la matrice du magasin (ou {depart: true} pour revenir à celle de départ). */
function wr_equipe_matrice(): array
{
    $b = body();
    $shop = eqpShop($b);
    if ($shop <= 0) { return eqpErreur(400, 'shop manquant'); }
    $m = !empty($b['depart']) ? eqpMatriceDepart() : eqpMatriceValide($b['matrice'] ?? null);
    if ($m === null) { return eqpErreur(422, 'matrice : deux créneaux (HH:MM, par 5 minutes), une semaine de référence, et pour A, B, C, D deux semaines de sept jours en M, P ou R'); }
    try {
        ensureEquipeMatrice();
        Db::exec('INSERT INTO panel_equipe_matrice (shop_id, matrice, maj_le, maj_par) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE matrice = VALUES(matrice), maj_le = VALUES(maj_le), maj_par = VALUES(maj_par)',
            [(string) $shop, json_encode($m, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), eqpQui()]);
    } catch (Throwable $e) { return eqpErreur(503, 'la matrice ne s’enregistre pas : ' . $e->getMessage()); }
    return ['ok' => true] + ep_equipe_matrice_de($shop);
}

function ep_equipe_matrice_de(int $shop): array
{
    [$m, $depart] = eqpMatriceLire($shop);
    return ['shop' => $shop, 'matrice' => $m, 'depart' => $depart, 'roulements' => (object) eqpRoulements($shop), 'lettres' => EQP_LETTRES];
}

/** POST /dashboard/equipe/roulement {shop, employe, lettre: 'A'…'D' | null} — la lettre d'un employé (sa fiche). */
function wr_equipe_roulement(): array
{
    $b = body();
    $shop = eqpShop($b); $emp = is_int($b['employe'] ?? null) ? $b['employe'] : 0; $l = $b['lettre'] ?? null;
    if ($shop <= 0 || $emp <= 0) { return eqpErreur(400, 'magasin et employé attendus'); }
    if ($l !== null && !in_array($l, EQP_LETTRES, true)) { return eqpErreur(422, 'lettre : A, B, C, D ou null'); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return eqpErreur(503, 'compte du panel non configuré'); }
    $emps = eqpGet('/shops/' . $shop . '/employees');
    if (!is_array($emps)) { return eqpErreur(502, 'le panel ne répond pas : rien n’est enregistré'); }
    $p = null;
    foreach (analyseListe($emps) as $r) { if (is_array($r) && (int) ($r['id'] ?? 0) === $emp && (int) ($r['id_shop'] ?? 0) === $shop) { $p = eqpPersonne($r); break; } }
    if ($p === null) { return eqpErreur(404, 'cette personne n’est pas de l’équipe du magasin'); }
    try {
        ensureEquipeMatrice();
        if ($l === null) { Db::exec('DELETE FROM panel_equipe_roulement WHERE shop_id = ? AND employe_id = ?', [(string) $shop, $emp]); }
        else {
            Db::exec('INSERT INTO panel_equipe_roulement (shop_id, employe_id, lettre, maj_le, maj_par) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE lettre = VALUES(lettre), maj_le = VALUES(maj_le), maj_par = VALUES(maj_par)',
                [(string) $shop, $emp, $l, date('Y-m-d H:i:s'), eqpQui()]);
        }
    } catch (Throwable $e) { return eqpErreur(503, 'le roulement ne s’enregistre pas : ' . $e->getMessage()); }
    return ['ok' => true, 'employe' => $emp, 'lettre' => $l, 'roulements' => (object) eqpRoulements($shop)];
}

/** L'aperçu du shift maker pour la semaine de $du (un lundi) : [erreur, contexte]. */
function eqpGenererPlan(int $shop, string $du): array
{
    if ($shop <= 0) { return [eqpErreur(400, 'shop manquant'), null]; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || strtotime($du) === false) { return [eqpErreur(422, 'du : AAAA-MM-JJ'), null]; }
    $du = date('Y-m-d', strtotime($du . ' -' . ((int) date('N', strtotime($du)) - 1) . ' days'));
    $ecart = (strtotime($du) - strtotime(date('Y-m-d'))) / 86400;
    if ($ecart < -31 || $ecart > 120) { return [eqpErreur(422, 'semaine : d’un mois avant à quatre mois après aujourd’hui'), null]; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return [eqpErreur(503, 'compte du panel non configuré'), null]; }
    $jours = array_map(static fn ($i) => date('Y-m-d', strtotime($du . ' +' . $i . ' days')), range(0, 6));
    $chemins = ['emp' => '/shops/' . $shop . '/employees'];
    foreach ($jours as $d) { $chemins['sch' . $d] = '/shops/' . $shop . '/schedule?date=' . $d; }
    $res = eqpGetParallele($chemins, 6);
    if (!is_array($res['emp'] ?? null)) { return [eqpErreur(502, 'le panel n’a pas rendu les employés'), null]; }
    $C = [];
    foreach ($jours as $d) {
        if (!is_array($res['sch' . $d] ?? null)) { return [eqpErreur(502, 'le panel n’a pas rendu le planning du ' . $d . ' : rien n’est généré'), null]; }
        $C[$d] = eqpServices($res['sch' . $d], $shop, $d);
    }
    $equipe = [];
    foreach (analyseListe($res['emp']) as $r) { if (is_array($r)) { $e = eqpPersonne($r); if ($e['shop'] === $shop) { $equipe[$e['id']] = $e; } } }
    [$m] = eqpMatriceLire($shop);
    $g = eqpShiftMaker($m, eqpRoulements($shop), $equipe, $C, $du);
    $nom = static fn (array $x) => ['nom' => ($equipe[$x['employe']]['nom'] ?? null) ?: 'Employé ' . $x['employe']];
    return [null, ['du' => $du, 'cycle' => $g['cycle'], 'matrice' => $m, 'dejaPrevus' => array_sum(array_map('count', $C)),
        'propositions' => array_map(static fn ($x) => $x + $nom($x), $g['propositions']),
        'ecartes' => array_map(static fn ($x) => $x + $nom($x), $g['ecartes']),
        'sansLettre' => array_map(static fn ($id) => ['id' => $id, 'nom' => $equipe[$id]['nom']], $g['sansLettre'])]];
}

/** GET /dashboard/equipe/semaine/generer?shop=&du= — l'aperçu du shift maker, rien n'est écrit. */
function ep_equipe_generer(): array
{
    [$err, $c] = eqpGenererPlan(eqpShop(), (string) ($_GET['du'] ?? ''));
    return $err ?? ['shop' => eqpShop()] + $c;
}

/** POST /dashboard/equipe/semaine/generer {shop, du, cles: [clé…]} — crée au panel les services cochés de l'aperçu. */
function wr_equipe_generer(): array
{
    $b = body();
    $shop = eqpShop($b);
    $cles = $b['cles'] ?? null;
    if (!is_array($cles) || !array_is_list($cles) || count($cles) > EQP_RECOPIE_MAX) { return eqpErreur(422, 'cles : la liste des services à créer'); }
    $voulus = [];
    foreach ($cles as $x) { if (!is_string($x) || !preg_match('/^e\d+-\d{4}-\d{2}-\d{2}-\d{2}:\d{2}$/', $x)) { return eqpErreur(422, 'cles : des clés de l’aperçu'); } $voulus[$x] = true; }
    [$err, $c] = eqpGenererPlan($shop, is_string($b['du'] ?? null) ? $b['du'] : '');
    if ($err !== null) { return $err; }
    $a = array_values(array_filter($c['propositions'], static fn ($p) => isset($voulus[$p['cle']])));
    if ($a === []) { return eqpErreur(422, 'aucun des services cochés n’est encore à créer : relisez l’aperçu'); }
    return eqpCreerServices($shop, $a, static fn (int $n) => 'Semaine du ' . $c['du'] . ' : ' . $n . ' service' . ($n > 1 ? 's' : '') . ' créé' . ($n > 1 ? 's' : '') . ' par le shift maker (matrice A B C D, semaine ' . $c['cycle'] . ')', $c['du']);
}
