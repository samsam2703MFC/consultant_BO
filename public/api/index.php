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
require __DIR__ . '/../../src/erp_api.php';
require __DIR__ . '/../../src/anthropic.php';

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
            $path === '/lacunes'                       => ep_lacunes(),
            $path === '/audit/fraicheur'               => ep_audit_fraicheur(),
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
            $path === '/exploitation'                  => ep_exploitation(),
            $path === '/exploitation/magasin'          => ep_exploitation_magasin(),
            $path === '/exploitation/reseau'           => ep_exploitation_reseau(),
            $path === '/targets'                       => ep_targets(),
            $path === '/consultants'                   => ep_consultants(),
            $path === '/fournisseurs'                  => ep_suppliers(),
            $path === '/reputation'                    => ep_reputation(),
            $path === '/marketing'                     => ep_mkt(),
            $path === '/admin/marketing-nettoyage'     => ep_mar_nettoyage(),
            $path === '/admin/erp-essai'               => ep_erp_essai(),
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
            $path === '/production/produit/fiche'      => ep_prod_produit_fiche(),
            $path === '/planogramme'                   => ep_planogramme(),
            $path === '/fonds'                         => ep_fonds(),
            $path === '/produits/analyse'              => ep_produits_analyse(),
            $path === '/produits/analyse/options'      => ep_produits_analyse_options(),
            $path === '/produits/analyse/sonde'        => ep_produits_analyse_sonde(),
            $path === '/centrale/cockpit'              => ep_ca_cockpit(),
            $path === '/centrale/catalogue'            => ep_ca_catalogue(),
            $path === '/centrale/ventes'               => ep_ca_ventes(),
            $path === '/centrale/reglages'             => ep_ca_reglages(),
            $path === '/centrale/demandes'             => ep_ca_demandes(),
            $path === '/centrale/campagnes'            => ep_ca_manquant('campagnes'),
            $path === '/centrale/achats'               => ep_ca_achats(),
            $path === '/centrale/commandes'            => ep_ca_commandes(),
            $path === '/centrale/stock'                => ep_ca_stock(),
            $path === '/centrale/facturation'          => ep_ca_manquant('facturation'),
            $path === '/products/scoring'              => ep_products(),
            $path === '/products/waste'                => ep_product_waste(),
            $path === '/pwa/reports'                   => ep_pwa_reports(),
            $path === '/pwa/tasks'                     => ep_pwa_tasks(),
            $path === '/pwa/tasks/detail'              => ep_pwa_task_detail(),
            $path === '/pwa/waste/debug'               => ep_pwa_waste_debug(),
            $path === '/pwa/tasks/sonde'               => ep_pwa_tasks_sonde(),
            $path === '/ia/note'                       => ep_ia_note(),
            $path === '/ia/statut'                     => ep_ia_statut(),
            $path === '/pwa/compte'                    => PanelApi::statut(),
            $path === '/erp/compte'                    => ErpApi::statut(),
            default                                    => notFound(),
        };
    }

    // --- écritures
    if ($method === 'POST' && $path === '/journal') { return wr_journal(); }
    if ($method === 'POST' && $path === '/pwa/tasks/validate') { return wr_pwa_task_validate(); }
    if ($method === 'POST' && $path === '/pwa/tasks/review') { return wr_pwa_task_review(); }
    if ($method === 'PUT'  && $path === '/pwa/tasks/annotation') { return wr_pwa_annotation(); }
    if ($method === 'PUT'  && $path === '/pwa/compte') { return wr_pwa_compte(); }
    if ($method === 'POST' && $path === '/pwa/compte/test') { return wr_pwa_compte_test(); }
    if ($method === 'PUT'  && $path === '/erp/compte') { return wr_erp_compte(); }
    if ($method === 'POST' && $path === '/erp/compte/test') { return wr_erp_compte_test(); }
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
    if ($method === 'PUT' && preg_match('#^/stores/([\w-]+)/charges$#', $path, $m)) { return wr_shop_charges($m[1]); }
    if ($method === 'PUT' && preg_match('#^/production/fin/([\w-]+)$#', $path, $m)) { return wr_prod_fin($m[1]); }
    if ($method === 'POST' && $path === '/consultants/note') { return wr_consultant_note(); }
    // --- campagnes marketing (tables mar_*, reprises du module supprimé)
    if ($method === 'POST' && $path === '/marketing/campagne') { return wr_mkt_campagne(null); }
    if ($method === 'PATCH' && preg_match('#^/marketing/campagne/(\d+)$#', $path, $m)) { return wr_mkt_campagne((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/marketing/campagne/(\d+)$#', $path, $m)) { return wr_mkt_campagne_suppr((int) $m[1]); }
    if ($method === 'POST' && $path === '/marketing/type') { return wr_mkt_type(null); }
    if ($method === 'PATCH' && preg_match('#^/marketing/type/(\d+)$#', $path, $m)) { return wr_mkt_type((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/marketing/type/(\d+)$#', $path, $m)) { return wr_mkt_type_suppr((int) $m[1]); }
    if ($method === 'POST' && $path === '/admin/marketing-nettoyage') { return wr_mar_nettoyage(); }
    if ($method === 'POST' && $path === '/admin/marketing-restaure') { return wr_mar_restaure(); }
    if ($method === 'POST' && $path === '/admin/erp-token') { return wr_erp_token(); }
    if ($method === 'PUT' && preg_match('#^/production/produit/([\w-]+)$#', $path, $m)) { return wr_prod_produit($m[1]); }
    if ($method === 'PUT' && preg_match('#^/production/planogramme/([\w-]+)$#', $path, $m)) { return wr_prod_planogramme($m[1]); }
    // --- planogramme : structure du comptoir, placements, consignes
    if ($method === 'POST' && preg_match('#^/planogramme/(zone|meuble|niveau)$#', $path, $m)) { return wr_plano_creer($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/planogramme/(zone|meuble|niveau)/(\d+)$#', $path, $m)) { return wr_plano_patch($m[1], (int) $m[2]); }
    if ($method === 'DELETE' && preg_match('#^/planogramme/(zone|meuble|niveau)/(\d+)$#', $path, $m)) { return wr_plano_supprimer($m[1], (int) $m[2]); }
    if ($method === 'POST' && $path === '/planogramme/emplacement') { return wr_plano_slots(); }
    if ($method === 'DELETE' && preg_match('#^/planogramme/emplacement/(\d+)$#', $path, $m)) { return wr_plano_slot_supprimer((int) $m[1]); }
    if ($method === 'PUT' && preg_match('#^/planogramme/placement/([\w-]+)$#', $path, $m)) { return wr_plano_placer($m[1]); }
    if ($method === 'PUT' && $path === '/planogramme/note') { return wr_plano_note(); }
    if ($method === 'POST' && $path === '/planogramme/photo') { return wr_plano_photo(); }
    // --- fonds & redevances : le cockpit ÉCRIT dans le module marketing, qui
    //     reste le seul tenant du grand livre. Aucune écriture locale.
    if ($method === 'POST' && $path === '/fonds/mouvement') { return wr_fonds_mouvement(null); }
    if ($method === 'PATCH' && preg_match('#^/fonds/mouvement/(\d+)$#', $path, $m)) { return wr_fonds_mouvement((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/fonds/mouvement/(\d+)$#', $path, $m)) { return wr_fonds_mouvement_suppr((int) $m[1]); }
    if ($method === 'POST' && $path === '/fonds/recurrence') { return wr_fonds_recurrence(); }
    if ($method === 'DELETE' && preg_match('#^/fonds/recurrence/(\d+)$#', $path, $m)) { return wr_fonds_recurrence_suppr((int) $m[1]); }
    if ($method === 'PUT' && $path === '/fonds/royalties') { return wr_fonds_royalties(); }
    if ($method === 'POST' && $path === '/fonds/royalties/generer') { return wr_fonds_royalties_generer(); }
    if ($method === 'PUT'  && $path === '/ia/compte') { return wr_ia_compte(); }
    if ($method === 'PUT' && preg_match('#^/parametres/([\w.-]+)$#', $path, $m)) { return wr_param_put($m[1]); }

    return notFound();
}

function notFound(): array
{
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
