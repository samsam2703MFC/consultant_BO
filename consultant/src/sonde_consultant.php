<?php
declare(strict_types=1);

/**
 * Diagnostic : ce que les routes « consultant » du panel rendent (GET /diagnostic/panel-consultant).
 *
 * Sorti tel quel de marketing/src/mesure.php le 21/11/2026 : ce n'est pas de la mesure de campagne, c'est une
 * sonde du pilotage (tâches, checklists, P&L, réclamations, fournisseurs, contrat Swagger des tâches).
 */

/* ─────────────── Sonde : ce que les routes « consultant » rendent ───────────
 *
 * Une liste FIXE de chemins, lus tels quels, et rendus par leur FORME (clés,
 * nombre d'éléments, premier élément tronqué). Aucun chemin ne vient de la
 * requête : une sonde qui accepterait un chemin libre serait un proxy vers
 * toute l'API amont, et ce n'est pas ce qu'on veut ouvrir ici.
 */
function ep_panel_sonde_consultant(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $sid = (int) ($_GET['shop'] ?? 3);
    $du = date('Y-m-01', strtotime('-1 month'));
    $au = date('Y-m-t', strtotime('-1 month'));
    $an = date('Y');

    $chemins = [
        'note-types'        => '/consultant/note-types',
        'shop-notes'        => '/consultant/shops/' . $sid . '/notes',
        'tasks'             => '/consultant/tasks',
        'shop-tasks'        => '/consultant/shops/' . $sid . '/tasks',
        'network-tasks'     => '/consultant/network/tasks',
        'network-ranking'   => '/consultant/network/tasks/ranking',
        'targets'           => '/consultant/targets',
        'shop-targets'      => '/consultant/shops/' . $sid . '/targets',
        'shop-targets-range' => '/consultant/shops/' . $sid . '/targets/range?' . http_build_query(['date_from' => $an . '-01-01', 'date_to' => $an . '-12-31']),
        'pnl-daily'         => '/consultant/shops/' . $sid . '/pnl/daily?' . http_build_query(['date_from' => $du, 'date_to' => $au, 'from' => $du, 'to' => $au]),
        'pnl-monthly-shop'  => '/consultant/shops/' . $sid . '/pnl/monthly?' . http_build_query(['date_from' => $an . '-01-01', 'date_to' => $au]),
        'pnl-monthly-res'   => '/consultant/shops/pnl/monthly?' . http_build_query(['date_from' => $an . '-01-01', 'date_to' => $au]),
        'metric-definitions' => '/consultant/metric-definitions',
        'product-sectors'   => '/consultant/product-sectors',
        'responsibility-areas' => '/consultant/responsibility-areas',
        'material-complaints' => '/consultant/shops/material-complaints',
        // Sonde temporaire : ce que le panel porte VRAIMENT sur les
        // fournisseurs matière — c'est de là que doit venir leur adresse.
        'material-suppliers' => '/material-suppliers',
        'material-suppliers-BE' => '/material-suppliers?country_code=BE',
        // Sonde : les matières portent-elles un code-barres (EAN, GTIN) ? Le
        // scan de la réclamation au téléphone en dépend.
        'shop-materials' => '/shops/' . $sid . '/materials',
        'supplier-materials' => '/material-suppliers/1/materials',
        'supplier-connected' => '/material-suppliers/1/connected-materials',
        // Les checklists (09/10/2026) : celles du jour du magasin, leur avancement sur une période, leur définition au cadre opérationnel.
        'shop-checklists' => '/consultant/shops/' . $sid . '/checklists?date=' . date('Y-m-d'),
        'shop-checklists-periode' => '/consultant/shops/' . $sid . '/checklists/progress?' . http_build_query(['from' => date('Y-m-d', strtotime('-6 days')), 'to' => date('Y-m-d')]),
        'framework-checklists' => '/operational-framework/checklists',
    ];

    $apercu = static function ($v, int $prof = 0) use (&$apercu) {
        if (!is_array($v)) { return is_scalar($v) ? mb_substr((string) $v, 0, 60) : gettype($v); }
        if (array_is_list($v)) {
            $out = ['liste' => count($v)];
            if ($v && is_array($v[0])) {
                $out['clés'] = array_keys($v[0]);
                if ($prof < 1) { $out['premier'] = $apercu($v[0], $prof + 1); }
            } elseif ($v) { $out['premier'] = $apercu($v[0], $prof + 1); }
            return $out;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (is_array($x)) { $out[$k] = $prof < 2 ? $apercu($x, $prof + 1) : (array_is_list($x) ? count($x) . ' éléments' : array_keys($x)); }
            else { $out[$k] = is_scalar($x) ? mb_substr((string) $x, 0, 60) : gettype($x); }
        }
        return $out;
    };

    $out = ['magasin' => $sid, 'fenetre' => $du . ' → ' . $au, 'routes' => [], 'codes' => []];
    $lus = [];
    foreach (PanelApi::getParallele($chemins) as $nom => $r) {
        $lus[$nom] = $r;
        $out['routes'][$nom] = ['chemin' => $chemins[$nom],
            'reponse' => $r === null ? 'aucune réponse' : $apercu($r)];
        // Les champs qui ressemblent à un code (EAN, GTIN, code-barres, SKU) :
        // combien de matières en portent un, et trois exemples.
        if (str_contains($nom, 'materials') && is_array($r)) {
            $liste = array_is_list($r) ? $r : (array) ($r['materials'] ?? $r['data'] ?? []);
            $c = [];
            foreach ($liste as $m) {
                if (!is_array($m)) { continue; }
                foreach ($m as $k => $v) {
                    if (!preg_match('/ean|gtin|bar|code|sku|upc/i', (string) $k) || is_array($v)) { continue; }
                    $c[$k] ??= ['remplis' => 0, 'exemples' => []];
                    if ($v !== null && trim((string) $v) !== '') {
                        $c[$k]['remplis']++;
                        if (count($c[$k]['exemples']) < 3) { $c[$k]['exemples'][] = mb_substr((string) $v, 0, 40); }
                    }
                }
            }
            $out['codes'][$nom] = ['matieres' => count($liste), 'champs' => $c];
        }
    }
    // Second niveau : l'avancement d'une checklist du jour, la définition d'une checklist du cadre et ses tâches.
    $premier = static function ($r): int { foreach (PanelApi::liste(is_array($r) ? $r : []) as $c) { $id = (int) ($c['id'] ?? $c['checklist_id'] ?? 0); if ($id > 0) { return $id; } } return 0; };
    $suite = [];
    if (($id1 = $premier($lus['shop-checklists'] ?? null)) > 0) { $suite['shop-checklist-progress'] = '/consultant/shops/' . $sid . '/checklists/' . $id1 . '/progress?date=' . date('Y-m-d'); }
    if (($id2 = $premier($lus['framework-checklists'] ?? null)) > 0) {
        $suite['framework-checklist'] = '/operational-framework/checklists/' . $id2;
        $suite['framework-checklist-tasks'] = '/operational-framework/checklists/' . $id2 . '/tasks';
    }
    foreach ($suite ? PanelApi::getParallele($suite) : [] as $nom => $r) {
        $out['routes'][$nom] = ['chemin' => $suite[$nom], 'reponse' => $r === null ? 'aucune réponse' : $apercu($r)];
    }
    // Les tâches des consultants (09/10/2026) : la liste des consultants et leur poste, les postes et leurs niveaux,
    // les tâches d'un niveau, et les tâches du jour du compte, chacune en entier (premier élément détaillé).
    $plein = static function ($r) { $l = PanelApi::liste(is_array($r) ? $r : []); return ['n' => count($l), 'premiers' => array_slice($l, 0, 3)]; };
    $tc = ['consultant-tasks' => '/consultant/tasks?date=' . date('Y-m-d'), 'admin-memberships' => '/admin/consultant-memberships', 'panel-consultants' => '/panel/consultants',
        'positions' => '/positions', 'position-levels' => '/position-levels', 'workstations' => '/operational-framework/workstations'];
    $lusT = PanelApi::getParallele($tc);
    $out['tachesConsultants'] = [];
    foreach ($lusT as $nom => $r) { $out['tachesConsultants'][$nom] = ['chemin' => $tc[$nom], 'cles' => is_array($r) ? array_keys($r) : null, 'contenu' => $r === null ? 'aucune réponse' : $plein($r)]; }
    $pos = is_array($lusT['consultant-tasks']['position'] ?? null) ? $lusT['consultant-tasks']['position'] : [];
    $lvl = (int) ($pos['level_id'] ?? 0); $pid = (int) ($pos['position_id'] ?? 0);
    $tc2 = [];
    if ($lvl > 0) { $tc2['level-tasks'] = '/levels/' . $lvl . '/tasks'; $tc2['position-level-tasks'] = '/position-levels/' . $lvl . '/tasks'; }
    if ($pid > 0) { $tc2['position-levels-of'] = '/positions/' . $pid . '/levels'; }
    foreach ($tc2 ? PanelApi::getParallele($tc2) : [] as $nom => $r) { $out['tachesConsultants'][$nom] = ['chemin' => $tc2[$nom], 'cles' => is_array($r) ? array_keys($r) : null, 'contenu' => $r === null ? 'aucune réponse' : $plein($r)]; }
    // Le contrat Swagger des routes qui remplissent une tâche de consultant : corps attendu, réponses (lecture du document).
    $doc = PanelApi::sondeGet('/../swagger/openapi.json', 20);
    $paths = is_array($doc['corps']['paths'] ?? null) ? $doc['corps']['paths'] : [];
    $out['swaggerTaches'] = [];
    foreach ($paths as $ch => $ops) {
        if (!preg_match('#consultant/tasks|tasks/completions|operational-framework/checklists/\{[^}]+\}/tasks$#', (string) $ch) || !is_array($ops)) { continue; }
        foreach ($ops as $m => $op) { if (is_array($op)) { $out['swaggerTaches'][strtoupper((string) $m) . ' ' . $ch] = ['parametres' => $op['parameters'] ?? null, 'corps' => $op['requestBody'] ?? null, 'reponses' => $op['responses'] ?? null]; } }
    }
    $out['swaggerComposants'] = array_slice(array_keys(is_array($doc['corps']['components']['schemas'] ?? null) ? $doc['corps']['components']['schemas'] : []), 0, 400);
    // La période : la forme d'une checklist dans un jour.
    $per = $lus['shop-checklists-periode'] ?? null;
    if (is_array($per) && is_array($per['days'][0] ?? null)) { $out['routes']['shop-checklists-periode']['jour'] = $apercu($per['days'][0]['checklists'] ?? []); }
    return $out;
}
