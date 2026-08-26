<?php
declare(strict_types=1);

/**
 * Cockpit CEO — contrôleur frontal de l'API REST.
 * Base d'URL attendue : /api/cockpit/… (voir contrat-api.md).
 */

require __DIR__ . '/../../src/Db.php';
require __DIR__ . '/../../src/endpoints.php';
require __DIR__ . '/../../src/writes.php';
require __DIR__ . '/../../src/mkt_types.php';
require __DIR__ . '/../../src/installer.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/panel_api.php';
require __DIR__ . '/../../src/erp_api.php';
require __DIR__ . '/../../src/anthropic.php';
require __DIR__ . '/../../src/google_api.php';
require __DIR__ . '/../../src/smtp.php';
require __DIR__ . '/../../src/rapports.php';
require __DIR__ . '/../../src/ca_mail.php';
require __DIR__ . '/../../src/mkt_brief.php';
require __DIR__ . '/../../src/mkt_annexes.php';
require __DIR__ . '/../../src/mkt_agences.php';
require __DIR__ . '/../../src/prod_utilisation.php';
require __DIR__ . '/../../src/kpis.php';
require __DIR__ . '/../../src/cadence.php';
require __DIR__ . '/../../src/connecteurs.php';
require __DIR__ . '/../../src/mesure.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = preg_replace('#^.*?/api/cockpit#', '', $uri) ?: '/';
$path = rtrim($path, '/') ?: '/';

try {
    ensureInstalled();
    // L'aperçu du courrier fournisseur sort en HTML : il s'affiche dans un
    // cadre, il ne se lit pas en JSON.
    if ($method === 'GET' && $path === '/centrale/commandes/mail/apercu') {
        if (authEnabled() && !authOk()) { http_response_code(401); exit; }
        header('Content-Type: text/html; charset=utf-8');
        echo ep_ca_mail_apercu();
        exit;
    }                      // tables + seed + secret au premier appel

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
            $path === '/stores/kpis-annuels'           => ep_stores_kpis_annuels(),
            $path === '/exploitation'                  => ep_exploitation(),
            $path === '/exploitation/magasin'          => ep_exploitation_magasin(),
            $path === '/exploitation/reseau'           => ep_exploitation_reseau(),
            $path === '/exploitation/rentabilite'      => ep_exploitation_rentabilite(),
            $path === '/exploitation/jour'             => ep_exploitation_jour(),
            $path === '/targets'                       => ep_targets(),
            $path === '/consultants'                   => ep_consultants(),
            $path === '/fournisseurs'                  => ep_suppliers(),
            $path === '/connecteurs'                   => ep_connecteurs(),
            $path === '/rapports'                      => ep_rapports(),
            $path === '/kpi-defs'                      => ep_kpi_referentiel(),
            $path === '/cadence'                       => ep_cadence(),
            $path === '/parametres/smtp'               => ep_smtp(),
            $path === '/rapports/cron'                 => ep_rapports_cron(),
            preg_match('#^/rapports/run/(\d+)$#', $path, $m) === 1 => ep_rapport_run((int) $m[1]),
            preg_match('#^/rapports/run/(\d+)/pdf$#', $path, $m) === 1 => ep_rapport_run_pdf((int) $m[1]),
            $path === '/reputation'                    => ep_reputation(),
            $path === '/reputation/recherche'          => ep_reputation_recherche(),
            $path === '/marketing'                     => ep_mkt(),
            // Le calendrier des campagnes posé sur la courbe du budget, et le
            // détail magasin par magasin de la campagne regardée.
            $path === '/marketing/budget-campagnes'    => ep_budget_campagnes(),
            $path === '/marketing/mesure'              => ep_mesure(),
            $path === '/marketing/mesure/comparaison'  => ep_mesure_comparaison(),
            $path === '/marketing/kpi-periode'         => ep_mkt_kpi_periode(),
            $path === '/marketing/note-config'         => ep_mkt_brief_config(),
            $path === '/marketing/agences'             => ep_mkt_agences(),
            (bool) preg_match('#^/marketing/campagne/(\d+)/annexes$#', $path, $ma) => ep_mkt_annexes((int) $ma[1]),
            (bool) preg_match('#^/marketing/annexe/(\d+)/fichier$#', $path, $mf) => ep_mkt_annexe_fichier((int) $mf[1]),
            (bool) preg_match('#^/marketing/campagne/(\d+)/note$#', $path, $mn) => ep_mkt_brief((int) $mn[1]),
            (bool) preg_match('#^/marketing/campagne/(\d+)/note\.pdf$#', $path, $mp) => ep_mkt_brief_pdf((int) $mp[1]),
            $path === '/taches/classement'             => ep_taches_classement(),
            $path === '/fournisseurs/reclamations'     => ep_fournisseurs_reclamations(),
            $path === '/magasins/profil-jour'          => ep_profil_jour(),
            $path === '/fournisseurs/reclamation-refs' => ep_reclamation_refs(),
            $path === '/diagnostic/panel-consultant'   => ep_panel_sonde_consultant(),
            // Ce qui est ouvert, et à quelle fréquence : de quoi affiner le rail.
            $path === '/ecrans/vues'                   => ep_ecran_vues(),
            $path === '/admin/marketing-nettoyage'     => ep_mar_nettoyage(),
            $path === '/admin/erp-essai'               => ep_erp_essai(),
            $path === '/projects'                      => ep_projects(),
            $path === '/projects/crm'                  => ep_crm(),
            $path === '/people'                        => ep_people(),
            $path === '/reporting'                     => ep_reporting(),
            $path === '/journal'                       => ep_journal(),
            $path === '/journal/mails'                 => ep_journal_mails(),
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
            $path === '/planogramme/photos'            => ep_plano_photos(),
            $path === '/fonds'                         => ep_fonds(),
            $path === '/produits/analyse'              => ep_produits_analyse(),
            $path === '/produits/analyse/options'      => ep_produits_analyse_options(),
            $path === '/produits/analyse/sonde'        => ep_produits_analyse_sonde(),
            $path === '/produits/utilisation'          => ep_prod_utilisation(),
            $path === '/centrale/cockpit'              => ep_ca_cockpit(),
            $path === '/centrale/catalogue'            => ep_ca_catalogue(),
            $path === '/centrale/ventes'               => ep_ca_ventes(),
            $path === '/centrale/reglages'             => ep_ca_reglages(),
            $path === '/centrale/fournisseurs/annee'   => ep_ca_fournisseurs_annee(),
            $path === '/centrale/demandes'             => ep_ca_demandes(),
            $path === '/centrale/campagnes'            => ep_ca_manquant('campagnes'),
            $path === '/centrale/achats'               => ep_ca_achats(),
            $path === '/centrale/achats/catalogue'     => ep_ca_achats_catalogue(),
            $path === '/centrale/commandes'            => ep_ca_commandes(),
            $path === '/centrale/commandes/mail'       => caMailEtat(),
            $path === '/centrale/commandes/mail/courriers' => ep_ca_mail_courriers(),
            $path === '/centrale/achats/relance'       => caRelanceEtat(),
            $path === '/centrale/commandes/mail/cron'  => ep_ca_mail_cron(),
            $path === '/centrale/stock'                => ep_ca_stock(),
            $path === '/centrale/facturation'          => ep_ca_facturation(),
            $path === '/products/scoring'              => ep_products(),
            $path === '/products/waste'                => ep_product_waste(),
            $path === '/products/periodes'             => ep_product_periodes(),
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
    if ($method === 'PUT' && $path === '/taches/maitrise') { return wr_taches_maitrise(); }
    if ($method === 'POST' && $path === '/pwa/tasks/validate') { return wr_pwa_task_validate(); }
    if ($method === 'POST' && $path === '/pwa/tasks/review') { return wr_pwa_task_review(); }
    if ($method === 'PUT'  && $path === '/pwa/tasks/annotation') { return wr_pwa_annotation(); }
    if ($method === 'PUT'  && $path === '/pwa/compte') { return wr_pwa_compte(); }
    if ($method === 'POST' && $path === '/pwa/compte/test') { return wr_pwa_compte_test(); }
    if ($method === 'PUT'  && $path === '/erp/compte') { return wr_erp_compte(); }
    if ($method === 'POST' && $path === '/erp/compte/test') { return wr_erp_compte_test(); }
    if ($method === 'PUT' && $path === '/centrale/fournisseur-pct') { return wr_ca_fournisseur_pct(); }
    if ($method === 'POST' && $path === '/projects') { return wr_project_create(); }
    if ($method === 'DELETE' && preg_match('#^/projects/([\w-]+)$#', $path, $m)) { return wr_project_delete($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/projects/([\w-]+)$#', $path, $m)) { return wr_project_patch($m[1]); }
    if ($method === 'POST' && preg_match('#^/projects/([\w-]+)/tasks$#', $path, $m)) { return wr_task_create($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/projects/([\w-]+)/tasks/([\w-]+)$#', $path, $m)) { return wr_task_patch($m[1], $m[2]); }
    if ($method === 'DELETE' && preg_match('#^/projects/([\w-]+)/tasks/([\w-]+)$#', $path, $m)) { return wr_task_delete($m[1], $m[2]); }
    if ($method === 'PATCH' && preg_match('#^/projects/([\w-]+)/milestones/(\d+)$#', $path, $m)) { return wr_milestone_patch($m[1], (int) $m[2]); }
    if ($method === 'POST' && preg_match('#^/tasks/([\w-]+)/reminder$#', $path, $m)) { return wr_task_reminder($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/task-issues/(\d+)$#', $path, $m)) { return wr_task_issue_patch($m[1]); }
    if ($method === 'PUT' && preg_match('#^/stores/([\w-]+)/budget$#', $path, $m)) { return wr_budget_put($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/reporting/reports/([\w-]+)$#', $path, $m)) { return wr_report_patch($m[1]); }
    if ($method === 'POST' && preg_match('#^/reporting/reports/([\w-]+)/send$#', $path, $m)) { return wr_report_send($m[1]); }
    if ($method === 'PATCH' && preg_match('#^/reporting/alerts/([\w-]+)$#', $path, $m)) { return wr_alert_patch($m[1]); }
    if ($method === 'PUT' && preg_match('#^/stores/([\w-]+)/charges$#', $path, $m)) { return wr_shop_charges($m[1]); }
    // La variation par mois seule : sert à pousser une courbe d'un magasin aux
    // autres sans toucher à leur budget ni au reste de leur étude.
    if ($method === 'PUT' && preg_match('#^/stores/([\w-]+)/saisonnalite$#', $path, $m)) { return wr_shop_saisonnalite($m[1]); }
    // Budget × Campagnes : l'objectif de CA d'une campagne, magasin par magasin.
    if ($method === 'PUT' && preg_match('#^/marketing/campagnes/(\d+)/objectifs$#', $path, $m)) { return wr_campagne_objectifs((int) $m[1]); }
    if ($method === 'PUT' && preg_match('#^/marketing/mesure/(\d+)$#', $path, $m)) { return wr_mesure_param((int) $m[1]); }
    if ($method === 'PUT' && preg_match('#^/marketing/mesure/(\d+)/releve$#', $path, $m)) { return wr_mesure_releve((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/marketing/mesure/(\d+)/gel$#', $path, $m)) { return wr_mesure_gel((int) $m[1]); }
    if ($method === 'POST' && $path === '/ecrans/vue') { return wr_ecran_vue(); }
    if ($method === 'PUT' && preg_match('#^/production/fin/([\w-]+)$#', $path, $m)) { return wr_prod_fin($m[1]); }
    if ($method === 'POST' && $path === '/consultants/note') { return wr_consultant_note(); }
    if ($method === 'POST' && $path === '/fournisseurs/reclamation') { return wr_reclamation_creer(); }
    // --- campagnes marketing (tables mar_*, reprises du module supprimé)
    if ($method === 'POST' && $path === '/marketing/campagne') { return wr_mkt_campagne(null); }
    if ($method === 'PATCH' && preg_match('#^/marketing/campagne/(\d+)$#', $path, $m)) { return wr_mkt_campagne((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/marketing/campagne/(\d+)$#', $path, $m)) { return wr_mkt_campagne_suppr((int) $m[1]); }
    if ($method === 'PUT' && $path === '/marketing/types/ordre') { return wr_mkt_types_ordre(); }
    // --- référentiel des KPI (catalogue + seuils, éditable au formulaire)
    if ($method === 'POST' && $path === '/kpi-defs') { return wr_kpi(); }
    if ($method === 'PUT' && preg_match('#^/kpi-defs/(\d+)$#', $path, $m)) { return wr_kpi_patch((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/kpi-defs/(\d+)$#', $path, $m)) { return wr_kpi_suppr((int) $m[1]); }
    // --- cadence dynamique des contrôles (règles + plan calculé)
    if ($method === 'PUT' && $path === '/cadence') { return wr_cadence(); }
    if ($method === 'POST' && $path === '/cadence/plan') { return wr_cadence_plan(); }
    // --- machine d'envoi SMTP (identifiants côté serveur uniquement)
    if ($method === 'PUT' && $path === '/parametres/smtp') { return wr_smtp(); }
    if ($method === 'POST' && $path === '/parametres/smtp/test') { return wr_smtp_test(); }
    if ($method === 'POST' && $path === '/centrale/commandes/mail/test') { return wr_ca_mail_test(); }
    if ($method === 'PUT' && $path === '/centrale/fournisseurs/mail') { return wr_ca_fournisseur_mail(); }
    if ($method === 'POST' && $path === '/centrale/commandes/mail/classer') { return wr_ca_mail_classer(); }
    if ($method === 'POST' && $path === '/centrale/commandes/relance-franchise') { return wr_ca_relance_franchise(); }
    if ($method === 'POST' && $path === '/centrale/commandes/mail/envoyer') { return wr_ca_mail_envoyer(); }
    if ($method === 'POST' && $path === '/centrale/achats/relance') { return wr_ca_relance(); }
    // --- générateur de rapports (par levier, à seuils) + compositeur
    if ($method === 'POST' && $path === '/rapports') { return wr_rapport_creer(); }
    if ($method === 'POST' && $path === '/rapports/apercu') { return wr_rapport_apercu(); }
    if ($method === 'PUT' && preg_match('#^/rapports/(\d+)$#', $path, $m)) { return wr_rapport_patch((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/rapports/(\d+)$#', $path, $m)) { return wr_rapport_suppr((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/rapports/(\d+)/generer$#', $path, $m)) { return wr_rapport_generer((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/rapports/(\d+)/envoyer$#', $path, $m)) { return wr_rapport_envoyer((int) $m[1]); }
    if ($method === 'POST' && $path === '/reputation/sync') { return wr_reputation_sync(); }
    if ($method === 'PUT' && preg_match('#^/reputation/([\w-]+)/fiche$#', $path, $m)) { return wr_reputation_fiche($m[1]); }
    if ($method === 'PUT' && $path === '/parametres/google-cle') { return wr_google_compte(); }
    if ($method === 'POST' && preg_match('#^/marketing/campagne/(\d+)/note$#', $path, $m)) { return wr_mkt_brief_envoyer((int) $m[1]); }
    if ($method === 'PUT' && $path === '/marketing/note-config') { return wr_mkt_brief_config(); }
    if ($method === 'POST' && $path === '/marketing/agence') { return wr_mkt_agence(null); }
    if ($method === 'PATCH' && preg_match('#^/marketing/agence/(\d+)$#', $path, $m)) { return wr_mkt_agence((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/marketing/agence/(\d+)$#', $path, $m)) { return wr_mkt_agence_suppr((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/marketing/campagne/(\d+)/annexe$#', $path, $m)) { return wr_mkt_annexe((int) $m[1]); }
    if ($method === 'PATCH' && preg_match('#^/marketing/annexe/(\d+)$#', $path, $m)) { return wr_mkt_annexe_maj((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/marketing/annexe/(\d+)$#', $path, $m)) { return wr_mkt_annexe_suppr((int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/marketing/annexe-type/(\d+)$#', $path, $m)) { return wr_mkt_annexe_type_suppr((int) $m[1]); }
    if ($method === 'PUT' && preg_match('#^/marketing/campagne/(\d+)/note-mot$#', $path, $m)) { return wr_mkt_brief_mot((int) $m[1]); }
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
    if ($method === 'PATCH' && preg_match('#^/planogramme/emplacement/(\d+)$#', $path, $m)) { return wr_plano_slot_maj((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/planogramme/referentiel/(formats|contenants)$#', $path, $m)) { return wr_plano_referentiel_creer($m[1]); }
    if ($method === 'DELETE' && preg_match('#^/planogramme/referentiel/(formats|contenants)/(\d+)$#', $path, $m)) { return wr_plano_referentiel_supprimer($m[1], (int) $m[2]); }
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
