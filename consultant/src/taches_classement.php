<?php
declare(strict_types=1);

/**
 * Le classement des tâches du réseau, lu au panel (GET /taches/classement ; classementFenetre pour les rapports,
 * consultant/src/rapports.php).
 *
 * Sorti tel quel de marketing/src/mesure.php le 21/11/2026 : c'est du pilotage, pas de la mesure de campagne.
 */

/* ─────────── Classement des tâches : ce que le panel calcule déjà ───────────
 *
 * Le cockpit reconstituait ce classement magasin par magasin. La route
 * `/consultant/network/tasks/ranking` le rend d'un coup, avec ce qu'on n'avait
 * pas : les tâches SAUTÉES, les ÉCHECS, la clôture de journée et les
 * obligatoires manquées. On le lit, on ne le recalcule plus.
 */
function classementJour(?string $date = null): ?array
{
    $r = PanelApi::classementTaches($date);
    if (!is_array($r)) { return null; }
    $n = (array) ($r['network'] ?? []);
    $nb = static fn ($v) => $v === null || $v === '' ? null : (int) $v;
    $pc = static fn ($v) => $v === null || $v === '' ? null : (float) $v;
    $mags = [];
    foreach ((array) ($r['shops'] ?? []) as $s) {
        $mags[] = [
            'shopId' => (string) ($s['shop_id'] ?? ''),
            'nom' => (string) ($s['shop_name'] ?? ''),
            'ville' => (string) ($s['shop_city'] ?? ''),
            'total' => $nb($s['tasks_total'] ?? null),
            'faites' => $nb($s['tasks_done'] ?? null),
            'sautees' => $nb($s['tasks_skipped'] ?? null),
            'ratees' => $nb($s['tasks_failed'] ?? null),
            'taux' => $pc($s['completion_rate'] ?? null),
            'jourClos' => !empty($s['day_closed']),
            'closA' => $s['closed_at'] ?? null,
            'obligatoiresManquees' => $nb($s['mandatory_missed'] ?? null),
        ];
    }
    // Du plus avancé au moins avancé : un classement se lit dans l'ordre.
    usort($mags, fn ($a, $b) => ($b['taux'] ?? -1) <=> ($a['taux'] ?? -1));
    return [
        'date' => (string) ($r['date'] ?? ($date ?? date('Y-m-d'))),
        'reseau' => [
            'total' => $nb($n['tasks_total'] ?? null), 'faites' => $nb($n['tasks_done'] ?? null),
            'sautees' => $nb($n['tasks_skipped'] ?? null), 'ratees' => $nb($n['tasks_failed'] ?? null),
            'magasins' => $nb($n['shops_total'] ?? null), 'magasinsClos' => $nb($n['shops_closed'] ?? null),
            'taux' => $pc($n['completion_rate'] ?? null),
        ],
        'magasins' => $mags,
        'source' => 'API panel — /consultant/network/tasks/ranking',
    ];
}

/** GET /taches/classement[?date=YYYY-MM-DD] */
function ep_taches_classement(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'compte consultant non configuré']; }
    $d = (string) ($_GET['date'] ?? '');
    $c = classementJour(preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null);
    return $c ?? ['indispo' => true, 'motif' => 'le panel n’a pas rendu le classement'];
}

/**
 * Le même classement, CUMULÉ sur une fenêtre.
 *
 * La route ne connaît qu'une journée : une semaine se lit en sept appels menés
 * de front. Au-delà d'un mois on s'arrête — un rapport annuel ne vaut pas
 * trois cent soixante-cinq allers-retours.
 */
function classementFenetre(string $du, string $au): ?array
{
    $jours = [];
    for ($d = $du; $d <= $au && count($jours) < 31; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($d <= date('Y-m-d')) { $jours[] = $d; }
    }
    if ($jours === []) { return null; }
    $req = [];
    foreach ($jours as $d) { $req[$d] = '/consultant/network/tasks/ranking?date=' . urlencode($d); }
    $par = [];
    $vu = 0;
    foreach (PanelApi::getParallele($req, 4) as $d => $r) {
        if (!is_array($r)) { continue; }
        $vu++;
        foreach ((array) ($r['shops'] ?? []) as $s) {
            $sid = (string) ($s['shop_id'] ?? '');
            if ($sid === '') { continue; }
            if (!isset($par[$sid])) {
                $par[$sid] = ['shopId' => $sid, 'nom' => (string) ($s['shop_name'] ?? ''), 'total' => 0,
                    'faites' => 0, 'sautees' => 0, 'ratees' => 0, 'obligatoiresManquees' => 0,
                    'joursClos' => 0, 'jours' => 0];
            }
            $par[$sid]['total'] += (int) ($s['tasks_total'] ?? 0);
            $par[$sid]['faites'] += (int) ($s['tasks_done'] ?? 0);
            $par[$sid]['sautees'] += (int) ($s['tasks_skipped'] ?? 0);
            $par[$sid]['ratees'] += (int) ($s['tasks_failed'] ?? 0);
            $par[$sid]['obligatoiresManquees'] += (int) ($s['mandatory_missed'] ?? 0);
            $par[$sid]['joursClos'] += !empty($s['day_closed']) ? 1 : 0;
            $par[$sid]['jours']++;
        }
    }
    if ($vu === 0 || $par === []) { return null; }
    foreach ($par as $sid => $m) {
        $par[$sid]['taux'] = $m['total'] > 0 ? round(100 * $m['faites'] / $m['total'], 1) : null;
    }
    $mags = array_values($par);
    usort($mags, fn ($a, $b) => ($b['taux'] ?? -1) <=> ($a['taux'] ?? -1));
    $tot = ['total' => 0, 'faites' => 0, 'sautees' => 0, 'ratees' => 0, 'obligatoiresManquees' => 0];
    foreach ($mags as $m) { foreach (array_keys($tot) as $k) { $tot[$k] += $m[$k]; } }
    $tot['taux'] = $tot['total'] > 0 ? round(100 * $tot['faites'] / $tot['total'], 1) : null;
    return ['du' => $jours[0], 'au' => end($jours), 'jours' => count($jours),
        'reseau' => $tot, 'magasins' => $mags];
}
