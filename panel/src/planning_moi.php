<?php

declare(strict_types=1);

/**
 * Le planning de l'app employé (31/10/2026, Sam : « /employee/schedule est faux, il ne relaie pas via les API
 * planning »). L'app lisait GET /employees/{id}/schedule du panel avec le jeton de la personne ; le cockpit, lui, lit
 * le planning par GET /shops/{id}/schedule, la route du planning que le panel sert en production (src/planning_sync.php).
 * L'app demande maintenant ses services au cockpit, qui les lit à cette même route : les magasins actifs, de front,
 * gardés cinq minutes (une renfort dans un autre magasin y figure). Un magasin muet, ou un jour hors de la fenêtre
 * gardée, se complète par la table locale que la synchro horaire remplit depuis la même route
 * (franchisee_employee_schedule).
 *
 *   GET /ventes/moi/planning?du=AAAA-MM-JJ&au=AAAA-MM-JJ — l'app (identité signée de « Mes primes ») ; ses services
 *   à elle seule, sans le nom ni le téléphone de personne, chacun avec le nom court de son magasin (« Corbais »).
 */

const PLAN_TTL = 300;              // un magasin relu au plus toutes les cinq minutes
const PLAN_GARDE_PANNE = 6 * 3600; // panel muet : la dernière lecture sert encore six heures
const PLAN_AVANT = 42;             // la fenêtre gardée : six semaines avant aujourd'hui…
const PLAN_APRES = 70;             // … dix semaines après
const PLAN_JOURS_MAX = 62;         // une demande couvre deux mois au plus

/** Une ligne de planning mise à plat (panel ou table locale) : les seuls champs utiles, ou null si illisible. */
function planLigne(array $r): ?array
{
    $d = substr((string) ($r['work_date'] ?? ''), 0, 10);
    $de = (string) ($r['start_hour'] ?? '');
    $a = (string) ($r['end_hour'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^\d{1,2}:\d{2}/', $de) || !preg_match('/^\d{1,2}:\d{2}/', $a)) { return null; }
    return ['id_schedule' => (int) ($r['id_schedule'] ?? $r['id'] ?? 0), 'id_employee' => (int) ($r['id_employee'] ?? 0),
        'work_date' => $d, 'start_hour' => $de, 'end_hour' => $a, 'id_shop' => (int) ($r['id_shop'] ?? 0)];
}

/** La fenêtre gardée autour de $maintenant : [du, au]. */
function planFenetre(int $maintenant): array
{
    return [date('Y-m-d', strtotime('-' . PLAN_AVANT . ' days', $maintenant)), date('Y-m-d', strtotime('+' . PLAN_APRES . ' days', $maintenant))];
}

/**
 * Le planning de chaque magasin sur la fenêtre gardée, lu à GET /shops/{id}/schedule (la route rend tout l'historique :
 * seule la fenêtre est gardée, dans mot_cache « planShop:<id> »). Rend sid → {t, du, au, lignes, garde?}, ou null pour
 * un magasin que le panel ne sert pas et dont aucune lecture récente ne reste.
 */
function planMagasins(array $sids, int $maintenant): array
{
    [$du, $au] = planFenetre($maintenant);
    $out = []; $aLire = []; $vieux = [];
    foreach ($sids as $sid) {
        $c = motCache('planShop:' . $sid);
        if (is_array($c) && (int) ($c['t'] ?? 0) > $maintenant - PLAN_TTL) { $out[$sid] = $c; continue; }
        if (is_array($c) && (int) ($c['t'] ?? 0) > $maintenant - PLAN_GARDE_PANNE) { $vieux[$sid] = $c; }
        $aLire[$sid] = '/shops/' . $sid . '/schedule';
    }
    $reps = $aLire !== [] && PanelApi::configured() ? PanelApi::getParallele($aLire, 4) : [];
    foreach (array_keys($aLire) as $sid) {
        $rep = $reps[$sid] ?? null;
        if (!is_array($rep)) { $out[$sid] = isset($vieux[$sid]) ? $vieux[$sid] + ['garde' => true] : null; continue; }
        $lignes = [];
        foreach (analyseListe($rep) as $r) {
            $l = is_array($r) ? planLigne($r) : null;
            if ($l !== null && $l['work_date'] >= $du && $l['work_date'] <= $au) { $lignes[] = $l; }
        }
        $out[$sid] = ['t' => $maintenant, 'du' => $du, 'au' => $au, 'lignes' => $lignes];
        motCacheEcrire('planShop:' . $sid, $out[$sid]);
    }
    return $out;
}

/** Les services d'une personne dans la table locale (la synchro horaire), du … au … */
function planTable(int $emp, string $du, string $au): array
{
    try {
        $rows = Db::rows('SELECT * FROM franchisee_employee_schedule WHERE id_employee = ? AND work_date >= ? AND work_date <= ?', [$emp, $du, $au]);
    } catch (Throwable $e) { return []; }
    return array_values(array_filter(array_map(static fn ($r) => planLigne((array) $r), $rows)));
}

/**
 * Les services de la personne du … au … : ceux de la route du planning, magasin par magasin ; la table locale pour un
 * magasin muet ou un jour hors de la fenêtre gardée. Rend {emp, du, au, services, source, muets}.
 */
function planningPersonne(int $emp, string $du, string $au, ?int $maintenant = null): array
{
    $maintenant ??= time();
    $sids = [];
    foreach (Db::rows('SELECT id FROM shops WHERE active = 1') as $s) { $sids[] = (int) $s['id']; }
    $mag = planMagasins($sids, $maintenant);
    $services = []; $muets = [];
    foreach ($mag as $sid => $m) {
        if ($m === null) { $muets[] = (int) $sid; continue; }
        foreach ($m['lignes'] as $l) {
            if ($l['id_employee'] === $emp && $l['work_date'] >= $du && $l['work_date'] <= $au) { $services[$l['id_schedule'] ?: $l['work_date'] . $l['start_hour'] . $sid] = $l; }
        }
    }
    // Le complément : ce que la route n'a pas couvert (magasin muet, jour hors fenêtre, magasin inactif).
    $complete = false;
    foreach (planTable($emp, $du, $au) as $l) {
        $m = $mag[$l['id_shop']] ?? null;
        if ($m !== null && $l['work_date'] >= $m['du'] && $l['work_date'] <= $m['au']) { continue; }
        $services[$l['id_schedule'] ?: $l['work_date'] . $l['start_hour'] . $l['id_shop']] ??= $l;
        $complete = true;
    }
    $noms = planNomsMagasins();
    $services = array_map(static fn ($l) => $l + ['shop_name' => (string) ($noms[$l['id_shop']] ?? '')], array_values($services));
    usort($services, static fn ($a, $b) => [$a['work_date'], $a['start_hour']] <=> [$b['work_date'], $b['start_hour']]);
    $garde = array_keys(array_filter($mag, static fn ($m) => is_array($m) && !empty($m['garde'])));
    return ['emp' => $emp, 'du' => $du, 'au' => $au, 'services' => $services,
        'source' => $complete || $muets !== [] ? 'panel /shops/{id}/schedule + table locale' : 'panel /shops/{id}/schedule',
        'muets' => $muets, 'garde' => array_map('intval', $garde)];
}

/** Le nom court de chaque magasin, actif ou non : « Atelier by Berlo - Corbais » → « Corbais » (comme le cockpit). */
function planNomsMagasins(): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops') as $s) {
            $nom = trim((string) ($s['name'] ?? ''));
            $out[(int) $s['id']] = str_contains($nom, ' - ') ? trim(substr($nom, strrpos($nom, ' - ') + 3)) : $nom;
        }
    } catch (Throwable $e) { /* sans noms : l'app montre le numéro */ }
    return $out;
}

/** GET /ventes/moi/planning?du=&au= — les services de l'employé connecté (identité signée de l'app). */
function ep_planning_moi(): array
{
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $du = (string) ($_GET['du'] ?? '');
    $au = (string) ($_GET['au'] ?? '');
    if (profilDate($du) === null || profilDate($au) === null || $au < $du) { http_response_code(400); return ['error' => 'du et au attendus (AAAA-MM-JJ, du ≤ au)']; }
    if ((strtotime($au) - strtotime($du)) / 86400 > PLAN_JOURS_MAX) { http_response_code(400); return ['error' => 'deux mois au plus']; }
    return planningPersonne($emp, $du, $au);
}
