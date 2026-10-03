<?php
declare(strict_types=1);

/**
 * Invendus et poubelle — ce que le panel sait de ce qui n'a pas été vendu :
 * jeté, goûté, reporté au lendemain. Lecture seule, API du panel d'abord.
 *
 * Première étape : la sonde, pour lire la forme exacte de ce que le panel
 * rend avant d'écrire un calcul qui échouerait en silence.
 */

/**
 * GET /exploitation/invendus/sonde?shop=4&date=YYYY-MM-DD[&q=/shops/4/…]
 * La réponse brute de /shops/{id}/products/waste pour le jour, les motifs
 * connus du journal des mouvements, et l'existence de quelques routes
 * candidates (stock, report, motifs). Produits seulement : jamais un client.
 */
function ep_exploitation_invendus_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 4);
    $date = (string) ($_GET['date'] ?? date('Y-m-d', strtotime('-1 day')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d', strtotime('-1 day')); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    @set_time_limit(120);
    $out = ['shop' => $sid, 'date' => $date];
    $q = http_build_query(['from' => $date, 'date_from' => $date, 'to' => $date, 'date_to' => $date]);
    $w = PanelApi::sondeGet('/shops/' . $sid . '/products/waste?' . $q, 20);
    $out['waste'] = ['code' => $w['code'], 'corps' => $w['corps']];
    $out['mouvements'] = null;
    try {
        $out['mouvements'] = Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */ movement_type, reason, COUNT(*) n, MIN(DATE(created_at)) premier, MAX(DATE(created_at)) dernier FROM product_movement WHERE created_at >= ? GROUP BY movement_type, reason ORDER BY movement_type, n DESC", ['2026-06-01 00:00:00']);
        $out['mouvementsColonnes'] = array_keys(Db::rows('SELECT * FROM product_movement ORDER BY id DESC LIMIT 1')[0] ?? []);
        $out['mouvementsDernier'] = Db::rows("SELECT * FROM product_movement WHERE id_shop = ? AND movement_type = 'WASTE' ORDER BY id DESC LIMIT 3", [$sid]);
    } catch (PDOException $e) { $out['mouvementsErreur'] = 'journal des mouvements indisponible'; }
    $cands = [];
    foreach (['/shops/{s}/products/unsold?date={d}', '/shops/{s}/products/leftovers?date={d}', '/shops/{s}/products/stock?date={d}', '/shops/{s}/stock?date={d}',
        '/shops/{s}/inventory?date={d}', '/shops/{s}/products/inventory?date={d}', '/shops/{s}/product-movements?date={d}', '/shops/{s}/products/movements?date={d}',
        '/shops/{s}/movements?date={d}', '/shops/{s}/products/production?date={d}', '/shops/{s}/productions?date={d}', '/shops/{s}/products/transfers?date={d}',
        '/shops/{s}/transfers?date={d}', '/shops/{s}/products/carry-over?date={d}', '/shops/{s}/closing?date={d}', '/shops/{s}/closings?date={d}',
        '/shops/{s}/products/waste/reasons?from={d}&to={d}', '/shops/{s}/products/waste-details?from={d}&to={d}', '/shops/{s}/waste?from={d}&to={d}',
        '/shops/{s}/product-waste?from={d}&to={d}', '/shops/{s}/products/waste?from={d}&to={d}&group_by=reason', '/waste-reasons', '/product-waste-reasons', '/products/waste-reasons',
        '/shops/{s}/waste-reasons', '/shops/{s}/products/waste/summary?from={d}&to={d}'] as $c) { $cands[] = $c; }
    $extra = (string) ($_GET['q'] ?? '');
    if ($extra !== '' && preg_match('#^/(shops/\d+/(products|stock|inventory|movements|product-movements|productions?|transfers|closings?|waste|product-waste)[A-Za-z0-9_\-/]*|[a-z\-]*waste[a-z\-/]*)(\?[A-Za-z0-9_=&\-]*)?$#', $extra)) { $cands[] = $extra; }
    $out['candidats'] = [];
    foreach ($cands as $c) {
        $p = str_replace(['{s}', '{d}'], [(string) $sid, $date], $c);
        $r = PanelApi::sondeGet($p, 8);
        $b = $r['corps'];
        $ap = null;
        if (is_array($b)) {
            if (array_is_list($b)) { $ap = ['liste' => count($b), 'premier' => is_array($b[0] ?? null) ? array_slice($b[0], 0, 20, true) : ($b[0] ?? null)]; }
            else { $ap = ['cles' => array_slice(array_keys($b), 0, 20), 'extrait' => array_map(static fn ($v) => is_array($v) ? (array_is_list($v) ? ['liste' => count($v), 'premier' => $v[0] ?? null] : array_slice($v, 0, 12, true)) : $v, array_slice($b, 0, 8, true))]; }
        } elseif ($b !== null) { $ap = ['brut' => mb_substr((string) $b, 0, 300)]; }
        $out['candidats'][] = ['chemin' => $p, 'code' => $r['code'], 'erreur' => $r['erreur'] !== null ? mb_substr((string) $r['erreur'], 0, 200) : null, 'apercu' => $ap];
    }
    return $out;
}
