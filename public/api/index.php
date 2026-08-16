<?php
declare(strict_types=1);

/**
 * Cockpit CEO — contrôleur frontal de l'API REST.
 * Base d'URL attendue : /api/cockpit/… (voir contrat-api.md).
 */

require __DIR__ . '/../../src/Db.php';
require __DIR__ . '/../../src/endpoints.php';
require __DIR__ . '/../../src/writes.php';
require __DIR__ . '/../../src/installer.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/panel_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = preg_replace('#^.*?/api/cockpit#', '', $uri) ?: '/';
$path = rtrim($path, '/') ?: '/';

try {
    ensureInstalled();                      // tables + seed + secret au premier appel

    $out = authRoute($method, $path);       // /auth/* : toujours accessibles
    if ($out === null) {
        if (authEnabled() && !authOk()) {   // session exigée seulement si l'auth intégrée est activée
            http_response_code(401);
            $out = ['error' => 'auth', 'setup' => authIsSetup()];
        } else {
            $out = route($method, $path);
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode(['error' => 'base de données indisponible', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function route(string $method, string $path): mixed
{
    // --- lectures (une par écran + référentiels)
    if ($method === 'GET') {
        return match (true) {
            $path === '/meta'                          => ep_meta(),
            $path === '/taches/suivi'                  => ep_taches_suivi($_GET['periode'] ?? 'mois'),
            $path === '/referentiels/leviers'          => ep_leviers(),
            $path === '/referentiels/kpis'             => ep_kpis(),
            $path === '/referentiels/roles'            => ep_roles(),
            $path === '/referentiels/email-templates'  => ep_email_templates(),
            $path === '/referentiels/ceo_project-templates',
            $path === '/referentiels/project-templates' => ep_project_templates(),
            $path === '/stores'                        => ep_stores(),
            $path === '/stores/perf'                   => ep_perf(),
            $path === '/stores/budgets'                => ep_budgets(),
            $path === '/stores/etp'                    => ep_stores_etp(),
            $path === '/targets'                       => ep_targets(),
            $path === '/consultants'                   => ep_consultants(),
            $path === '/fournisseurs'                  => ep_suppliers(),
            $path === '/projects'                      => ep_projects(),
            $path === '/projects/crm'                  => ep_crm(),
            $path === '/people'                        => ep_people(),
            $path === '/reporting'                     => ep_reporting(),
            $path === '/journal'                       => ep_journal(),
            $path === '/production/catalogue'          => ep_prod_catalogue(),
            $path === '/production/params'             => ep_prod_params(),
            $path === '/production/categories'         => ep_prod_categories(),
            $path === '/production/groupes'            => ep_prod_groupes(),
            $path === '/production/categorie/produits' => ep_prod_categorie_produits(),
            $path === '/production/periodes'           => ep_prod_periodes(),
            $path === '/production/periode/produits'   => ep_prod_periode_produits(),
            $path === '/production/suivi'              => ep_prod_suivi(),
            $path === '/products/scoring'              => ep_products(),
            $path === '/products/waste'                => ep_product_waste(),
            $path === '/pwa/reports'                   => ep_pwa_reports(),
            $path === '/pwa/tasks'                     => ep_pwa_tasks(),
            $path === '/pwa/tasks/detail'              => ep_pwa_task_detail(),
            $path === '/pwa/waste/debug'               => ep_pwa_waste_debug(),
            $path === '/pwa/compte'                    => PanelApi::statut(),
            default                                    => notFound(),
        };
    }

    // --- écritures
    if ($method === 'POST' && $path === '/journal') { return wr_journal(); }
    if ($method === 'POST' && $path === '/pwa/tasks/validate') { return wr_pwa_task_validate(); }
    if ($method === 'POST' && $path === '/pwa/tasks/review') { return wr_pwa_task_review(); }
    if ($method === 'PUT'  && $path === '/pwa/compte') { return wr_pwa_compte(); }
    if ($method === 'POST' && $path === '/pwa/compte/test') { return wr_pwa_compte_test(); }
    if ($method === 'POST' && $path === '/projects') { return wr_project_create(); }
    if ($method === 'DELETE' && preg_match('#^/projects/([\w-]+)$#', $path, $m)) { return wr_project_delete($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/projects/([\w-]+)$#', $path, $m)) { return wr_project_patch($m[1]); }
    if ($method === 'POST' && preg_match('#^/projects/([\w-]+)/tasks$#', $path, $m)) { return wr_task_create($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/projects/([\w-]+)/tasks/([\w-]+)$#', $path, $m)) { return wr_task_patch($m[1], $m[2]); }
    if ($method === 'PATCH' && preg_match('#^/projects/([\w-]+)/milestones/(\d+)$#', $path, $m)) { return wr_milestone_patch($m[1], (int) $m[2]); }
    if ($method === 'POST' && preg_match('#^/tasks/([\w-]+)/reminder$#', $path, $m)) { return wr_task_reminder($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/task-issues/(\d+)$#', $path, $m)) { return wr_task_issue_patch($m[1]); }
    if ($method === 'PUT' && preg_match('#^/stores/([\w-]+)/budget$#', $path, $m)) { return wr_budget_put($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/reporting/reports/([\w-]+)$#', $path, $m)) { return wr_report_patch($m[1]); }
    if ($method === 'POST' && preg_match('#^/reporting/reports/([\w-]+)/send$#', $path, $m)) { return wr_report_send($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/reporting/alerts/([\w-]+)$#', $path, $m)) { return wr_alert_patch($m[1]); }
    if ($method === 'PUT' && preg_match('#^/parametres/([\w.-]+)$#', $path, $m)) { return wr_param_put($m[1]); }

    return notFound();
}

function notFound(): array
{
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
