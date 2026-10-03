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
/** Rien de sensible dans une sonde : la fiche magasin se réduit à l'id et au nom, et toute clé qui sent l'identifiant disparaît. */
function invScrub(mixed $v): mixed
{
    if (!is_array($v)) { return $v; }
    if (isset($v['shop']) && is_array($v['shop'])) { $v['shop'] = ['id' => $v['shop']['id'] ?? null, 'nom' => $v['shop']['representative_name'] ?? ($v['shop']['name'] ?? null)]; }
    $o = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && preg_match('/pass|secret|token|mail|phone|iban|bank|street|address|zip|city|vat|tva|login|owner/i', $k)) { continue; }
        $o[$k] = is_array($x) ? invScrub($x) : $x;
    }
    return $o;
}

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
    $out['waste'] = ['code' => $w['code'], 'corps' => invScrub($w['corps'])];
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
    // `q` : d'autres chemins à essayer, séparés par des virgules — produits, stock, mouvements, documentation ; jamais un client.
    $extra = (string) ($_GET['q'] ?? '');
    if ($extra !== '') {
        $cands = [];
        foreach (array_slice(array_filter(array_map('trim', explode(',', $extra))), 0, 30) as $e) {
            if (preg_match('#^/(shops/\d+/(products|stock|inventory|movements|product-movements|productions?|transfers|closings?|waste|product-waste|reports?|day-end|end-of-day)[A-Za-z0-9_\-/.]*|[a-z\-]*waste[a-z\-/]*|(docs?|api-docs|openapi|swagger|redoc|documentation|schema)[A-Za-z0-9_\-/.]*)(\?[A-Za-z0-9_=&\-%.]*)?$#', $e)) { $cands[] = $e; }
        }
    }
    // `texte` : une page lue telle quelle (documentation) ; `spec` : un document OpenAPI dont on liste les chemins.
    $texte = (string) ($_GET['texte'] ?? '');
    if ($texte !== '' && preg_match('#^/(docs?|api-docs|openapi|swagger|redoc|documentation|schema)[A-Za-z0-9_\-/.]*(\?[A-Za-z0-9_=&\-%.]*)?$#', $texte)) {
        $t = PanelApi::sondeTexte($texte);
        $out['texte'] = ['chemin' => $texte, 'code' => $t['code'], 'longueur' => $t['texte'] === null ? null : mb_strlen($t['texte']),
            'urls' => $t['texte'] === null ? [] : array_values(array_unique(array_slice(preg_match_all('#["\']([^"\' ]*(?:swagger|openapi|spec|api-docs|\.json|\.yaml)[^"\' ]*)["\']#i', $t['texte'], $m) ? $m[1] : [], 0, 40))),
            'extrait' => $t['texte'] === null ? null : mb_substr($t['texte'], 0, 3000)];
    }
    $out['candidats'] = [];
    foreach ($cands as $c) {
        $p = str_replace(['{s}', '{d}'], [(string) $sid, $date], $c);
        $r = PanelApi::sondeGet($p, 8);
        $b = $r['corps'];
        if (is_string($b) && preg_match('/(href|location)=["\']?([^"\' >]+)/i', $b, $mm)) { $b = ['redirection' => $mm[2]]; }
        $ap = null;
        if (is_array($b) && isset($b['paths']) && is_array($b['paths'])) {
            // Un document OpenAPI : la liste des chemins, et le détail de ceux qui parlent de produits, de pertes, de stock.
            $det = [];
            foreach ($b['paths'] as $ch => $ops) {
                if (!preg_match('/waste|product|stock|movement|carry|unsold|production|inventor|loss|leftover|closing|report/i', (string) $ch) || !is_array($ops)) { continue; }
                foreach ($ops as $meth => $op) {
                    if (!is_array($op)) { continue; }
                    $det[$ch . ' ' . strtoupper((string) $meth)] = ['resume' => $op['summary'] ?? ($op['description'] ?? null), 'params' => array_map(static fn ($x) => is_array($x) ? ($x['name'] ?? '') . (isset($x['in']) ? ' (' . $x['in'] . ')' : '') : $x, (array) ($op['parameters'] ?? []))];
                }
            }
            $ap = ['openapi' => $b['openapi'] ?? ($b['swagger'] ?? null), 'chemins' => array_keys($b['paths']), 'detail' => $det];
        } elseif (is_array($b)) {
            if (array_is_list($b)) { $ap = ['liste' => count($b), 'premier' => is_array($b[0] ?? null) ? array_slice($b[0], 0, 20, true) : ($b[0] ?? null)]; }
            else { $ap = ['cles' => array_slice(array_keys($b), 0, 20), 'extrait' => array_map(static fn ($v) => is_array($v) ? (array_is_list($v) ? ['liste' => count($v), 'premier' => $v[0] ?? null] : array_slice($v, 0, 12, true)) : $v, array_slice($b, 0, 8, true))]; }
        } elseif ($b !== null) { $ap = ['brut' => mb_substr((string) $b, 0, 300)]; }
        $out['candidats'][] = ['chemin' => $p, 'code' => $r['code'], 'erreur' => $r['erreur'] !== null ? mb_substr((string) $r['erreur'], 0, 200) : null, 'apercu' => invScrub($ap)];
    }
    return $out;
}
