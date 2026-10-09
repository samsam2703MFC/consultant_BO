<?php
/**
 * Le P&L mois par mois d'un magasin, depuis l'API du panel — demande du 09/10/2026 (les chiffres de Halle depuis
 * l'ouverture : food cost, main-d'œuvre, frais généraux, résultat).
 *
 * Source : `/consultant/shops/{id}/pnl/daily?date_from&date_to`, la seule route de P&L du panel qui sert le passé
 * (mesuré le 09/10/2026 : septembre rend 30 jours avec `revenue`, `material`, `labour`, `overhead`, `result` ;
 * `/pnl/monthly` ne répond pas). Un appel par mois et par magasin, en parallèle ; les mois clos se gardent 24 h.
 *
 * Lecture seule.
 */
declare(strict_types=1);

const PM_HEURES = 24;

/** Un mois « YYYY-MM » → [premier jour, dernier jour servi (aujourd'hui au plus tard)]. */
function pmBornes(string $ym): array
{
    $du = $ym . '-01';
    $au = min(date('Y-m-t', strtotime($du)), date('Y-m-d'));
    return [$du, $au];
}

/** Le P&L quotidien d'un magasin sur un mois, agrégé : jours servis, jours avec ventes, sommes et parts du CA. */
function pmAgrege(string $ym, array $jours): array
{
    $t = ['ca' => 0.0, 'matiere' => 0.0, 'labour' => 0.0, 'overhead' => 0.0, 'resultat' => 0.0];
    $n = 0; $nVentes = 0; $premier = null; $dernier = null; $manque = ['matiere' => 0, 'labour' => 0, 'overhead' => 0];
    foreach ($jours as $j) {
        if (!is_array($j)) { continue; }
        $n++;
        $ca = is_numeric($j['revenue'] ?? null) ? (float) $j['revenue'] : 0.0;
        if ($ca > 0) { $nVentes++; $d = substr((string) ($j['date'] ?? ''), 0, 10); if ($d !== '') { $premier = $premier === null ? $d : min($premier, $d); $dernier = $dernier === null ? $d : max($dernier, $d); } }
        $t['ca'] += $ca;
        foreach (['matiere' => 'material', 'labour' => 'labour', 'overhead' => 'overhead', 'resultat' => 'result'] as $k => $c) {
            if (is_numeric($j[$c] ?? null)) { $t[$k] += (float) $j[$c]; } elseif ($ca > 0 && $k !== 'resultat') { $manque[$k]++; }
        }
    }
    $pct = static fn (float $v) => $t['ca'] > 0 ? round(100 * $v / $t['ca'], 1) : null;
    return ['mois' => $ym, 'jours' => $n, 'joursVentes' => $nVentes, 'premierJour' => $premier, 'dernierJour' => $dernier,
        'ca' => round($t['ca'], 2), 'matiere' => round($t['matiere'], 2), 'labour' => round($t['labour'], 2), 'overhead' => round($t['overhead'], 2), 'resultat' => round($t['resultat'], 2),
        'margeBrute' => round($t['ca'] - $t['matiere'], 2),
        'matierePct' => $pct($t['matiere']), 'labourPct' => $pct($t['labour']), 'overheadPct' => $pct($t['overhead']), 'resultatPct' => $pct($t['resultat']), 'margeBrutePct' => $pct($t['ca'] - $t['matiere']),
        'sansValeur' => $manque];
}

/**
 * GET /analyse/pnl/mois?shop=4&du=2026-02&au=2026-10[&tous=1][&rafraichir=1][&jours=1] — le P&L mensuel d'un
 * magasin (et de chaque magasin actif avec `tous=1`), depuis le P&L quotidien du panel. `jours=1` joint les jours.
 */
function ep_analyse_pnl_mois(): array
{
    $sid = (int) ($_GET['shop'] ?? 0); $tous = !empty($_GET['tous']);
    if ($sid <= 0 && !$tous) { http_response_code(400); return ['error' => 'shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'API panel non configurée (Mon compte)']; }
    $du = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['du'] ?? '')) ? (string) $_GET['du'] : date('Y-m', strtotime('-6 months'));
    $au = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['au'] ?? '')) ? (string) $_GET['au'] : date('Y-m');
    if ($au > date('Y-m')) { $au = date('Y-m'); }
    if ($du > $au) { $du = $au; }
    $frais = !empty($_GET['rafraichir']); $avecJours = !empty($_GET['jours']);
    $noms = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) { $noms[(int) $s['id']] = (string) $s['name']; } } catch (Throwable $e) { /* sans noms */ }
    $sids = $tous ? array_keys($noms) : [$sid];
    if ($sids === []) { $sids = [$sid]; }
    $mois = [];
    for ($m = $du; $m <= $au; $m = date('Y-m', strtotime($m . '-01 +1 month'))) { $mois[] = $m; if (count($mois) > 36) { break; } }
    // Les mois clos gardés 24 h ; le mois en cours relu à chaque fois.
    $chemins = []; $resultats = [];
    foreach ($sids as $s) {
        foreach ($mois as $m) {
            $cle = 'pnlMois:' . $s . ':' . $m;
            $clos = $m < date('Y-m');
            if ($clos && !$frais) {
                try { $c = setting($cle); } catch (Throwable $e) { $c = null; }
                if (is_array($c) && isset($c['le'], $c['v']) && is_array($c['v']) && time() - (int) $c['le'] < PM_HEURES * 3600) { $resultats[$s][$m] = $c['v']; continue; }
            }
            [$d1, $d2] = pmBornes($m);
            $chemins[$s . '|' . $m] = '/consultant/shops/' . $s . '/pnl/daily?' . http_build_query(['date_from' => $d1, 'date_to' => $d2, 'from' => $d1, 'to' => $d2]);
        }
    }
    $rep = $chemins !== [] ? PanelApi::getParallele($chemins, 4, 40) : [];
    foreach ($chemins as $k => $chemin) {
        [$s, $m] = explode('|', $k); $s = (int) $s;
        $r = $rep[$k] ?? null;
        $jours = is_array($r) ? (array) ($r['days'] ?? (array_is_list($r) ? $r : [])) : null;
        if ($jours === null) { $resultats[$s][$m] = ['mois' => $m, 'indispo' => true, 'motif' => PanelApi::$lastError ?: 'sans réponse']; continue; }
        $a = pmAgrege($m, $jours);
        if ($avecJours) { $a['parJour'] = array_values(array_map(static fn ($j) => ['date' => substr((string) ($j['date'] ?? ''), 0, 10), 'ca' => is_numeric($j['revenue'] ?? null) ? round((float) $j['revenue'], 2) : null, 'matiere' => is_numeric($j['material'] ?? null) ? round((float) $j['material'], 2) : null, 'labour' => is_numeric($j['labour'] ?? null) ? round((float) $j['labour'], 2) : null, 'overhead' => is_numeric($j['overhead'] ?? null) ? round((float) $j['overhead'], 2) : null, 'resultat' => is_numeric($j['result'] ?? null) ? round((float) $j['result'], 2) : null], array_filter($jours, 'is_array'))); }
        $resultats[$s][$m] = $a;
        if ($m < date('Y-m')) {
            try { $j = json_encode(['le' => time(), 'v' => $a], JSON_UNESCAPED_UNICODE); if ($j !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['pnlMois:' . $s . ':' . $m, $j]); } } catch (Throwable $e) { /* sans cache */ }
        }
    }
    $out = ['du' => $du, 'au' => $au, 'magasins' => [], 'source' => 'API du panel : /consultant/shops/{id}/pnl/daily par mois (revenue, material, labour, overhead, result), agrégé ; mois clos gardés 24 h'];
    foreach ($sids as $s) {
        $liste = []; foreach ($mois as $m) { $liste[] = $resultats[$s][$m] ?? ['mois' => $m, 'indispo' => true, 'motif' => 'non lu']; }
        $cum = ['ca' => 0.0, 'matiere' => 0.0, 'labour' => 0.0, 'overhead' => 0.0, 'resultat' => 0.0, 'mois' => 0];
        foreach ($liste as $x) { if (empty($x['indispo']) && ($x['ca'] ?? 0) > 0) { foreach (['ca', 'matiere', 'labour', 'overhead', 'resultat'] as $k) { $cum[$k] += (float) $x[$k]; } $cum['mois']++; } }
        $pct = static fn (float $v) => $cum['ca'] > 0 ? round(100 * $v / $cum['ca'], 1) : null;
        $out['magasins'][] = ['id' => (string) $s, 'nom' => $noms[$s] ?? ('Magasin ' . $s), 'court' => preg_replace('/^.* - /', '', $noms[$s] ?? ('Magasin ' . $s)), 'mois' => $liste,
            'cumul' => ['mois' => $cum['mois'], 'ca' => round($cum['ca'], 2), 'matiere' => round($cum['matiere'], 2), 'labour' => round($cum['labour'], 2), 'overhead' => round($cum['overhead'], 2), 'resultat' => round($cum['resultat'], 2),
                'matierePct' => $pct($cum['matiere']), 'labourPct' => $pct($cum['labour']), 'overheadPct' => $pct($cum['overhead']), 'resultatPct' => $pct($cum['resultat'])]];
    }
    return $out;
}
