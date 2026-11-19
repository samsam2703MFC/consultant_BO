# REORG_AUDIT — Phase 1 : audit (lecture seule)

Généré par `python3 -I reorg/audit_statique.py` (lecture seule : aucune base, aucun réseau). Les données brutes sont dans `reorg/tables.tsv` (une ligne par table, avec la liste de tous les fichiers qui la citent), `reorg/fichiers.tsv` (un fichier par ligne : domaine et nouveau chemin proposés), `reorg/objets.json` (clés étrangères, vues, objets SQL) et `reorg/fichiers_morts.tsv`. Le plan, les décisions et les questions sont dans `REORG_PLAN.md`.

## 0. Limites de cet audit

- **Aucun accès à la base depuis cet environnement** (pas de client MySQL, pas d’identifiants, et la base du bac à sable n’est joignable que depuis le serveur 185.180.206.46). Les **nombres de lignes**, les tables **vides**, les clés étrangères **réellement posées**, les vues, déclencheurs et fonctions **réellement présents** sont donc notés « à mesurer » : ils seront relevés par la ligne de base de la phase 3 (étape 0 du plan), avant toute modification.
- L’inventaire est tiré du code : chaque `CREATE TABLE` du dépôt (SQL et chaînes PHP), chaque nom de table cité dans une requête (`FROM`, `JOIN`, `INTO`, `UPDATE`…), et les registres de migration (`MAR_TABLES`, `CON_TABLES`). Une table présente en base mais jamais citée par ce dépôt n’y apparaît pas : la ligne de base la fera apparaître.
- La copie de l’ERP (`apps/production`, base `erp` séparée, tables `n_` / `tfb_`) est inventoriée comme un bloc hors périmètre ; ses 546 `CREATE TABLE` ne sont pas détaillés.

## 1. Arborescence et domaine de chaque dossier

| Chemin | Fichiers | Domaine proposé |
|---|---:|---|
| `.github/` | 10 | RACINE |
| `CLAUDE.md` | 1 | RACINE |
| `README.md` | 1 | RACINE |
| `docs/` | 6 | RACINE |
| `pilotage-reseau/.gitignore` | 1 | SHARED |
| `pilotage-reseau/CLAUDE.md` | 1 | SHARED |
| `pilotage-reseau/README.md` | 1 | SHARED |
| `pilotage-reseau/apps/employee/` | 159 | PANEL |
| `pilotage-reseau/apps/production/` | 4241 | HORS |
| `pilotage-reseau/bin/benchmark.php` | 1 | CON |
| `pilotage-reseau/bin/boite_cron.sh` | 1 | PANEL |
| `pilotage-reseau/bin/carte_sources.php` | 1 | SHARED |
| `pilotage-reseau/bin/console_bo.php` | 1 | SHARED |
| `pilotage-reseau/bin/console_bo_seal.php` | 1 | SHARED |
| `pilotage-reseau/bin/db_admin.php` | 1 | SHARED |
| `pilotage-reseau/bin/deploy.sh` | 1 | SHARED |
| `pilotage-reseau/bin/disque_alerte.php` | 1 | SHARED |
| `pilotage-reseau/bin/extract_panel_db.php` | 1 | SHARED |
| `pilotage-reseau/bin/historique_moisson.php` | 1 | PANEL |
| `pilotage-reseau/bin/hr_dimona_cron.php` | 1 | HORS |
| `pilotage-reseau/bin/hr_dimona_cron.sh` | 1 | HORS |
| `pilotage-reseau/bin/hr_dimona_schema.php` | 1 | HORS |
| `pilotage-reseau/bin/menage_disque.sh` | 1 | SHARED |
| `pilotage-reseau/bin/metadonnee_cron.sh` | 1 | MAR |
| `pilotage-reseau/bin/mix1000.php` | 1 | CON |
| `pilotage-reseau/bin/newsletter_cron.sh` | 1 | MAR |
| `pilotage-reseau/bin/push_stock.php` | 1 | CON |
| `pilotage-reseau/bin/rapports_cron.sh` | 1 | CON |
| `pilotage-reseau/bin/seed.php` | 1 | SHARED |
| `pilotage-reseau/bin/social_cron.sh` | 1 | MAR |
| `pilotage-reseau/bin/stock_cron.sh` | 1 | CON |
| `pilotage-reseau/bin/test_chargement.php` | 1 | SHARED |
| `pilotage-reseau/bin/tests.php` | 1 | SHARED |
| `pilotage-reseau/bin/tests_analyse.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_assistant_ia.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_assortiment.php` | 1 | CON |
| `pilotage-reseau/bin/tests_audits.php` | 1 | CON |
| `pilotage-reseau/bin/tests_automatisations.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_benchmark.php` | 1 | CON |
| `pilotage-reseau/bin/tests_benchmark_import.php` | 1 | CON |
| `pilotage-reseau/bin/tests_boite_mail.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_bons.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_bundles.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_ca_net.php` | 1 | CON |
| `pilotage-reseau/bin/tests_canaux.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_catalogue.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_cibles_reseau.php` | 1 | CON |
| `pilotage-reseau/bin/tests_coach_objectifs.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_controles_photos.php` | 1 | CON |
| `pilotage-reseau/bin/tests_cq_mail.php` | 1 | CON |
| `pilotage-reseau/bin/tests_differes.php` | 1 | CON |
| `pilotage-reseau/bin/tests_documents.php` | 1 | CON |
| `pilotage-reseau/bin/tests_employes_relus.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_evolution_pro.php` | 1 | CON |
| `pilotage-reseau/bin/tests_fil.php` | 1 | CON |
| `pilotage-reseau/bin/tests_formation.php` | 1 | FOR |
| `pilotage-reseau/bin/tests_grands_prix.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_historique.php` | 1 | CON |
| `pilotage-reseau/bin/tests_ia_assistant.php` | 1 | CON |
| `pilotage-reseau/bin/tests_ia_note.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_jauges.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_kpi_cibles.php` | 1 | CON |
| `pilotage-reseau/bin/tests_manuel_chiffres.php` | 1 | FOR |
| `pilotage-reseau/bin/tests_metadonnee.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_newsletter.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_noms_globaux.php` | 1 | CON |
| `pilotage-reseau/bin/tests_panel_reessai.php` | 1 | CON |
| `pilotage-reseau/bin/tests_planning_moi.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_pnl_ht.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_pointage_app.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_pointages.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_primes.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_profil.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_promos.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_promos_reseau.php` | 1 | MAR |
| `pilotage-reseau/bin/tests_rapports_suspension.php` | 1 | CON |
| `pilotage-reseau/bin/tests_revue_soir.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_suivi.php` | 1 | CON |
| `pilotage-reseau/bin/tests_temps_morts.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_tresorerie.php` | 1 | PANEL |
| `pilotage-reseau/bin/tests_verification.php` | 1 | CON |
| `pilotage-reseau/bin/tests_verification_correction.php` | 1 | CON |
| `pilotage-reseau/bin/tests_visites_kpi.php` | 1 | CON |
| `pilotage-reseau/bin/visites_cron.sh` | 1 | CON |
| `pilotage-reseau/config/config.example.php` | 1 | SHARED |
| `pilotage-reseau/config/console_bo_accounts.sealed` | 1 | SHARED |
| `pilotage-reseau/data/formation/` | 64 | FOR |
| `pilotage-reseau/deploy.trigger` | 1 | SHARED |
| `pilotage-reseau/docs/DEPLOIEMENT.md` | 1 | SHARED |
| `pilotage-reseau/docs/api-promotions.md` | 1 | MAR |
| `pilotage-reseau/docs/audit-api-tfbuddy.md` | 1 | SHARED |
| `pilotage-reseau/docs/audit-api.md` | 1 | SHARED |
| `pilotage-reseau/docs/audit-rail.md` | 1 | SHARED |
| `pilotage-reseau/docs/audit-taches-consultant.md` | 1 | CON |
| `pilotage-reseau/docs/audits-fournisseurs.md` | 1 | CON |
| `pilotage-reseau/docs/benchmarks/` | 3 | CON |
| `pilotage-reseau/docs/brand-guard.md` | 1 | MAR |
| `pilotage-reseau/docs/consignes/` | 1 | PANEL |
| `pilotage-reseau/docs/contrat-api.md` | 1 | SHARED |
| `pilotage-reseau/docs/contrat-panel-produits.md` | 1 | CON |
| `pilotage-reseau/docs/design-system/` | 29 | SHARED |
| `pilotage-reseau/docs/dimona.md` | 1 | HORS |
| `pilotage-reseau/docs/dimona/` | 1 | HORS |
| `pilotage-reseau/docs/dossiers/` | 9 | CON |
| `pilotage-reseau/docs/formation-routes.md` | 1 | FOR |
| `pilotage-reseau/docs/formation.md` | 1 | FOR |
| `pilotage-reseau/docs/handoff-centrale-achat.md` | 1 | CON |
| `pilotage-reseau/docs/maquettes/alignement-fournisseurs/` | 9 | CON |
| `pilotage-reseau/docs/maquettes/annee-trimestres/` | 8 | PANEL |
| `pilotage-reseau/docs/maquettes/api-module/` | 3 | CON |
| `pilotage-reseau/docs/maquettes/assistant-graphiques/` | 24 | PANEL |
| `pilotage-reseau/docs/maquettes/assistant-ia-magasin/` | 7 | PANEL |
| `pilotage-reseau/docs/maquettes/assistant-mise-en-page/` | 7 | PANEL |
| `pilotage-reseau/docs/maquettes/assortiment-obligatoire/` | 6 | CON |
| `pilotage-reseau/docs/maquettes/audit-outils-gestion/` | 12 | CON |
| `pilotage-reseau/docs/maquettes/b2b-b2c/` | 5 | MAR |
| `pilotage-reseau/docs/maquettes/b2b-commandes-mobile/` | 12 | PANEL |
| `pilotage-reseau/docs/maquettes/benchmark-concurrence/` | 8 | CON |
| `pilotage-reseau/docs/maquettes/benchmark-import-pdf/` | 22 | CON |
| `pilotage-reseau/docs/maquettes/budget-pnl-crm/` | 7 | CON |
| `pilotage-reseau/docs/maquettes/budget-tablette/` | 6 | PANEL |
| `pilotage-reseau/docs/maquettes/caisse-reports/` | 7 | PANEL |
| `pilotage-reseau/docs/maquettes/canaux-offres/` | 16 | MAR |
| `pilotage-reseau/docs/maquettes/cartes-fidelite/` | 16 | MAR |
| `pilotage-reseau/docs/maquettes/clients-annee-n1/` | 13 | MAR |
| `pilotage-reseau/docs/maquettes/clients-magasin/` | 10 | MAR |
| `pilotage-reseau/docs/maquettes/comptoir-webshop/` | 12 | PANEL |
| `pilotage-reseau/docs/maquettes/controle-photos/` | 23 | CON |
| `pilotage-reseau/docs/maquettes/controle-taches-photos/` | 33 | CON |
| `pilotage-reseau/docs/maquettes/controles-qualite/` | 42 | CON |
| `pilotage-reseau/docs/maquettes/dashboard-3-onglets/` | 32 | PANEL |
| `pilotage-reseau/docs/maquettes/dashboard-categories/` | 6 | PANEL |
| `pilotage-reseau/docs/maquettes/dashboard-franchise-une-page/` | 3 | PANEL |
| `pilotage-reseau/docs/maquettes/dashboard-mobile-v2/` | 11 | PANEL |
| `pilotage-reseau/docs/maquettes/dashboard-mobile-v3/` | 46 | PANEL |
| `pilotage-reseau/docs/maquettes/dashboard-mobile/` | 11 | PANEL |
| `pilotage-reseau/docs/maquettes/dashboard-operationnel/` | 18 | PANEL |
| `pilotage-reseau/docs/maquettes/equipe-en-poste/` | 7 | PANEL |
| `pilotage-reseau/docs/maquettes/evaluation-rapide/` | 14 | CON |
| `pilotage-reseau/docs/maquettes/evolution-canaux/` | 17 | MAR |
| `pilotage-reseau/docs/maquettes/evolution-magasin/` | 15 | CON |
| `pilotage-reseau/docs/maquettes/fiche-matiere/` | 9 | CON |
| `pilotage-reseau/docs/maquettes/fidelite-franchise/` | 3 | MAR |
| `pilotage-reseau/docs/maquettes/fidelite-profils/` | 7 | MAR |
| `pilotage-reseau/docs/maquettes/fidelite-v3/` | 10 | MAR |
| `pilotage-reseau/docs/maquettes/fidelite/` | 23 | MAR |
| `pilotage-reseau/docs/maquettes/fil-franchise/` | 25 | CON |
| `pilotage-reseau/docs/maquettes/formation-donnees/` | 16 | FOR |
| `pilotage-reseau/docs/maquettes/formation/` | 38 | FOR |
| `pilotage-reseau/docs/maquettes/frais-tva/` | 9 | PANEL |
| `pilotage-reseau/docs/maquettes/franchises-wizard/` | 7 | CON |
| `pilotage-reseau/docs/maquettes/fusion-manuel/` | 12 | FOR |
| `pilotage-reseau/docs/maquettes/gestion-consultant/` | 14 | CON |
| `pilotage-reseau/docs/maquettes/gestion-production/` | 12 | PANEL |
| `pilotage-reseau/docs/maquettes/helpdesk-franchises/` | 16 | CON |
| `pilotage-reseau/docs/maquettes/jours-creux/` | 14 | PANEL |
| `pilotage-reseau/docs/maquettes/manuel-formation/` | 12 | FOR |
| `pilotage-reseau/docs/maquettes/matieres-par-fournisseur/` | 10 | CON |
| `pilotage-reseau/docs/maquettes/matieres-reference/` | 8 | CON |
| `pilotage-reseau/docs/maquettes/metadonnee/` | 15 | MAR |
| `pilotage-reseau/docs/maquettes/modale-produit/` | 20 | PANEL |
| `pilotage-reseau/docs/maquettes/modale-recette/` | 8 | PANEL |
| `pilotage-reseau/docs/maquettes/nc-compact/` | 9 | CON |
| `pilotage-reseau/docs/maquettes/nc-veille/` | 15 | CON |
| `pilotage-reseau/docs/maquettes/objectif-jauge/` | 11 | PANEL |
| `pilotage-reseau/docs/maquettes/objectifs-produits/` | 5 | MAR |
| `pilotage-reseau/docs/maquettes/onglet-produits-developpement/` | 26 | PANEL |
| `pilotage-reseau/docs/maquettes/onglet-produits-simple/` | 21 | PANEL |
| `pilotage-reseau/docs/maquettes/onglet-produits/` | 32 | PANEL |
| `pilotage-reseau/docs/maquettes/panier-heures/` | 7 | PANEL |
| `pilotage-reseau/docs/maquettes/parametrage-produits/` | 12 | CON |
| `pilotage-reseau/docs/maquettes/parametres-mails/` | 10 | PANEL |
| `pilotage-reseau/docs/maquettes/plan-campagne/` | 34 | MAR |
| `pilotage-reseau/docs/maquettes/planogramme-standard/` | 6 | CON |
| `pilotage-reseau/docs/maquettes/pointage-app/` | 8 | PANEL |
| `pilotage-reseau/docs/maquettes/portail-formation/` | 25 | FOR |
| `pilotage-reseau/docs/maquettes/primes-b2b/` | 17 | PANEL |
| `pilotage-reseau/docs/maquettes/primes-epure/` | 39 | PANEL |
| `pilotage-reseau/docs/maquettes/primes/` | 19 | PANEL |
| `pilotage-reseau/docs/maquettes/prix-volume/` | 16 | MAR |
| `pilotage-reseau/docs/maquettes/profil-employe/` | 4 | PANEL |
| `pilotage-reseau/docs/maquettes/promo-coach/` | 27 | PANEL |
| `pilotage-reseau/docs/maquettes/promotions-analyse/` | 12 | MAR |
| `pilotage-reseau/docs/maquettes/promotions-cockpit/` | 13 | MAR |
| `pilotage-reseau/docs/maquettes/promotions-utilisation/` | 12 | MAR |
| `pilotage-reseau/docs/maquettes/pwa-visites/` | 12 | CON |
| `pilotage-reseau/docs/maquettes/rappel-sms-controles/` | 6 | CON |
| `pilotage-reseau/docs/maquettes/recettes-correction/` | 17 | CON |
| `pilotage-reseau/docs/maquettes/reclamations/` | 31 | PANEL |
| `pilotage-reseau/docs/maquettes/reperes-photos/` | 15 | CON |
| `pilotage-reseau/docs/maquettes/resultat-vers-dashboard/` | 6 | PANEL |
| `pilotage-reseau/docs/maquettes/royalties/` | 6 | CON |
| `pilotage-reseau/docs/maquettes/scoring-mensuel/` | 5 | CON |
| `pilotage-reseau/docs/maquettes/scouting-v2/` | 17 | HORS |
| `pilotage-reseau/docs/maquettes/scouting-wizard/` | 10 | HORS |
| `pilotage-reseau/docs/maquettes/semaine-mois/` | 17 | PANEL |
| `pilotage-reseau/docs/maquettes/site-gamme-mise-en-avant/` | 5 | MAR |
| `pilotage-reseau/docs/maquettes/sop-dashboard/` | 18 | FOR |
| `pilotage-reseau/docs/maquettes/structure-gamme/` | 10 | CON |
| `pilotage-reseau/docs/maquettes/suivi-duree-de-vie/` | 19 | CON |
| `pilotage-reseau/docs/maquettes/suivi-franchises/` | 22 | CON |
| `pilotage-reseau/docs/maquettes/tablette-sop/` | 17 | FOR |
| `pilotage-reseau/docs/maquettes/temps-morts-tickets/` | 7 | PANEL |
| `pilotage-reseau/docs/maquettes/temps-morts/` | 11 | PANEL |
| `pilotage-reseau/docs/maquettes/tresorerie/` | 6 | PANEL |
| `pilotage-reseau/docs/maquettes/ventes-coef/` | 6 | CON |
| `pilotage-reseau/docs/maquettes/verification/` | 24 | CON |
| `pilotage-reseau/docs/maquettes/visibilite-google/` | 9 | MAR |
| `pilotage-reseau/docs/maquettes/vue-generale/` | 67 | CON |
| `pilotage-reseau/docs/maquettes/vue-jour-a4/` | 16 | PANEL |
| `pilotage-reseau/docs/module-motivation.md` | 1 | PANEL |
| `pilotage-reseau/docs/plan-verification.md` | 1 | CON |
| `pilotage-reseau/docs/primes/` | 6 | PANEL |
| `pilotage-reseau/docs/publication-reseaux.md` | 1 | MAR |
| `pilotage-reseau/docs/pwa-suivi-visites-analyse.md` | 1 | CON |
| `pilotage-reseau/docs/spec-api-panel.md` | 1 | SHARED |
| `pilotage-reseau/docs/suivi-franchises.md` | 1 | CON |
| `pilotage-reseau/docs/task-review-attachments-en.html` | 1 | SHARED |
| `pilotage-reseau/essai-vouchers.trigger` | 1 | MAR |
| `pilotage-reseau/maquette-screens/README.md` | 1 | CON |
| `pilotage-reseau/maquette-screens/captures/` | 4 | CON |
| `pilotage-reseau/maquette-screens/maquette-qc-vision-screens.html` | 1 | CON |
| `pilotage-reseau/public/.gitignore` | 1 | SHARED |
| `pilotage-reseau/public/.htaccess` | 1 | SHARED |
| `pilotage-reseau/public/api/` | 40 | MAR (38), SHARED (1), HORS (1) |
| `pilotage-reseau/public/assets/` | 109 | SHARED (55), CON (38), MAR (11), PANEL (5) |
| `pilotage-reseau/public/assistant/` | 3 | MAR |
| `pilotage-reseau/public/audit/` | 2 | CON |
| `pilotage-reseau/public/audits/` | 2 | CON |
| `pilotage-reseau/public/borne/` | 1 | PANEL |
| `pilotage-reseau/public/caisse/` | 3 | CON |
| `pilotage-reseau/public/connexion/` | 2 | SHARED |
| `pilotage-reseau/public/consultant.webmanifest` | 1 | CON |
| `pilotage-reseau/public/controle/` | 3 | CON |
| `pilotage-reseau/public/dashboard/` | 30 | PANEL |
| `pilotage-reseau/public/employee/` | 26 | PANEL |
| `pilotage-reseau/public/fidelite-app/` | 2 | MAR |
| `pilotage-reseau/public/fidelite-test/` | 22 | MAR |
| `pilotage-reseau/public/formation/` | 50 | FOR |
| `pilotage-reseau/public/hr/` | 7 | HORS |
| `pilotage-reseau/public/index.html` | 1 | SHARED |
| `pilotage-reseau/public/marketing/` | 1 | MAR |
| `pilotage-reseau/public/newsletter/` | 1 | MAR |
| `pilotage-reseau/public/planogramme/` | 3 | CON |
| `pilotage-reseau/public/production/` | 3 | PANEL |
| `pilotage-reseau/public/prospection/` | 1 | MAR |
| `pilotage-reseau/public/resultat-mobile/` | 3 | PANEL |
| `pilotage-reseau/public/router.php` | 1 | SHARED |
| `pilotage-reseau/public/suivi/` | 3 | CON |
| `pilotage-reseau/public/tablette/` | 70 | PANEL |
| `pilotage-reseau/public/task/` | 3 | CON |
| `pilotage-reseau/public/uploads/` | 3 | SHARED |
| `pilotage-reseau/public/visites/` | 3 | CON |
| `pilotage-reseau/scripts/dimona-smoke.php` | 1 | HORS |
| `pilotage-reseau/sonde-vouchers.trigger` | 1 | MAR |
| `pilotage-reseau/sql/grants.sql` | 1 | SHARED |
| `pilotage-reseau/sql/hr_dimona.sql` | 1 | HORS |
| `pilotage-reseau/sql/mar-referentiels.sql` | 1 | MAR |
| `pilotage-reseau/sql/schema.sql` | 1 | SHARED |
| `pilotage-reseau/sql/seed.sql` | 1 | SHARED |
| `pilotage-reseau/src/Db.php` | 1 | SHARED |
| `pilotage-reseau/src/acces.php` | 1 | SHARED |
| `pilotage-reseau/src/analyse_produits.php` | 1 | CON |
| `pilotage-reseau/src/anthropic.php` | 1 | SHARED |
| `pilotage-reseau/src/api_module.php` | 1 | PANEL |
| `pilotage-reseau/src/assistant_magasin.php` | 1 | PANEL |
| `pilotage-reseau/src/assortiment.php` | 1 | CON |
| `pilotage-reseau/src/audits.php` | 1 | CON |
| `pilotage-reseau/src/auth.php` | 1 | SHARED |
| `pilotage-reseau/src/benchmark.php` | 1 | CON |
| `pilotage-reseau/src/benchmark_import.php` | 1 | CON |
| `pilotage-reseau/src/brand_guard.php` | 1 | MAR |
| `pilotage-reseau/src/budget_crm.php` | 1 | CON |
| `pilotage-reseau/src/ca_mail.php` | 1 | CON |
| `pilotage-reseau/src/ca_net_mois.php` | 1 | CON |
| `pilotage-reseau/src/cadence.php` | 1 | CON |
| `pilotage-reseau/src/caisse.php` | 1 | CON |
| `pilotage-reseau/src/canaux_offres.php` | 1 | MAR |
| `pilotage-reseau/src/checklists_codes.php` | 1 | SHARED |
| `pilotage-reseau/src/cibles_reseau.php` | 1 | SHARED |
| `pilotage-reseau/src/coach_objectifs.php` | 1 | PANEL |
| `pilotage-reseau/src/commandes.php` | 1 | CON |
| `pilotage-reseau/src/con_tables.php` | 1 | CON |
| `pilotage-reseau/src/connecteurs.php` | 1 | SHARED |
| `pilotage-reseau/src/controles_qualite.php` | 1 | CON |
| `pilotage-reseau/src/cq_mail.php` | 1 | CON |
| `pilotage-reseau/src/crm/` | 1 | MAR |
| `pilotage-reseau/src/croisements.php` | 1 | CON |
| `pilotage-reseau/src/data/` | 9 | SHARED |
| `pilotage-reseau/src/doc.php` | 1 | SHARED |
| `pilotage-reseau/src/documents.php` | 1 | CON |
| `pilotage-reseau/src/dossier.php` | 1 | CON |
| `pilotage-reseau/src/dossiers_fichiers.php` | 1 | CON |
| `pilotage-reseau/src/endpoints.php` | 1 | SHARED |
| `pilotage-reseau/src/erp_api.php` | 1 | SHARED |
| `pilotage-reseau/src/erp_cles.php` | 1 | SHARED |
| `pilotage-reseau/src/fbcontrole.php` | 1 | MAR |
| `pilotage-reseau/src/fidelite.php` | 1 | MAR |
| `pilotage-reseau/src/fidelite_app.php` | 1 | MAR |
| `pilotage-reseau/src/fidelite_bingo.php` | 1 | MAR |
| `pilotage-reseau/src/fidelite_cartes.php` | 1 | MAR |
| `pilotage-reseau/src/fidelite_profils.php` | 1 | MAR |
| `pilotage-reseau/src/fil_franchise.php` | 1 | CON |
| `pilotage-reseau/src/formation.php` | 1 | FOR |
| `pilotage-reseau/src/formation_postes.php` | 1 | FOR |
| `pilotage-reseau/src/franchise/` | 16 | PANEL |
| `pilotage-reseau/src/franchises.php` | 1 | CON |
| `pilotage-reseau/src/google_api.php` | 1 | MAR |
| `pilotage-reseau/src/grands_prix.php` | 1 | PANEL |
| `pilotage-reseau/src/helpdesk.php` | 1 | CON |
| `pilotage-reseau/src/historique_ventes.php` | 1 | PANEL |
| `pilotage-reseau/src/hr/` | 102 | HORS |
| `pilotage-reseau/src/ia_assistant.php` | 1 | CON |
| `pilotage-reseau/src/installer.php` | 1 | SHARED |
| `pilotage-reseau/src/invendus.php` | 1 | CON |
| `pilotage-reseau/src/inventaires.php` | 1 | CON |
| `pilotage-reseau/src/jours_creux.php` | 1 | MAR |
| `pilotage-reseau/src/kpi_table.php` | 1 | CON |
| `pilotage-reseau/src/kpis.php` | 1 | CON |
| `pilotage-reseau/src/leviers.php` | 1 | CON |
| `pilotage-reseau/src/linkedin_api.php` | 1 | MAR |
| `pilotage-reseau/src/manuel_chiffres_cles.php` | 1 | FOR |
| `pilotage-reseau/src/marketing_hub.php` | 1 | MAR |
| `pilotage-reseau/src/marketing_sources.php` | 1 | MAR |
| `pilotage-reseau/src/marketing_tables.php` | 1 | MAR |
| `pilotage-reseau/src/matiere_fiche.php` | 1 | PANEL |
| `pilotage-reseau/src/mesure.php` | 1 | MAR |
| `pilotage-reseau/src/meta_graph.php` | 1 | MAR |
| `pilotage-reseau/src/metadonnee.php` | 1 | MAR |
| `pilotage-reseau/src/mkt_agences.php` | 1 | MAR |
| `pilotage-reseau/src/mkt_annexes.php` | 1 | MAR |
| `pilotage-reseau/src/mkt_brief.php` | 1 | MAR |
| `pilotage-reseau/src/mkt_types.php` | 1 | MAR |
| `pilotage-reseau/src/motivation.php` | 1 | PANEL |
| `pilotage-reseau/src/newsletter.php` | 1 | MAR |
| `pilotage-reseau/src/newsletter_envoi.php` | 1 | MAR |
| `pilotage-reseau/src/notes_jour.php` | 1 | PANEL |
| `pilotage-reseau/src/objectifs_produits.php` | 1 | MAR |
| `pilotage-reseau/src/panel_api.php` | 1 | SHARED |
| `pilotage-reseau/src/panel_ventes.php` | 1 | SHARED |
| `pilotage-reseau/src/plan.php` | 1 | CON |
| `pilotage-reseau/src/plan_pdf.php` | 1 | CON |
| `pilotage-reseau/src/planning_moi.php` | 1 | PANEL |
| `pilotage-reseau/src/planning_sync.php` | 1 | PANEL |
| `pilotage-reseau/src/plano_proposition.php` | 1 | CON |
| `pilotage-reseau/src/plano_std.php` | 1 | CON |
| `pilotage-reseau/src/pnl_ht.php` | 1 | SHARED |
| `pilotage-reseau/src/pnl_mois.php` | 1 | CON |
| `pilotage-reseau/src/pointage.php` | 1 | PANEL |
| `pilotage-reseau/src/pointages_mail.php` | 1 | PANEL |
| `pilotage-reseau/src/ponderation.php` | 1 | SHARED |
| `pilotage-reseau/src/primes.php` | 1 | PANEL |
| `pilotage-reseau/src/primes_app.php` | 1 | PANEL |
| `pilotage-reseau/src/primes_b2b.php` | 1 | PANEL |
| `pilotage-reseau/src/primes_fiches.php` | 1 | PANEL |
| `pilotage-reseau/src/prod_manque.php` | 1 | CON |
| `pilotage-reseau/src/prod_utilisation.php` | 1 | CON |
| `pilotage-reseau/src/production_flux.php` | 1 | PANEL |
| `pilotage-reseau/src/production_plan.php` | 1 | PANEL |
| `pilotage-reseau/src/produit_parametrage.php` | 1 | CON |
| `pilotage-reseau/src/produits_coef.php` | 1 | PANEL |
| `pilotage-reseau/src/profil_employe.php` | 1 | PANEL |
| `pilotage-reseau/src/promos_bons.php` | 1 | MAR |
| `pilotage-reseau/src/promos_doc.php` | 1 | MAR |
| `pilotage-reseau/src/promos_magasin.php` | 1 | MAR |
| `pilotage-reseau/src/promos_reseau.php` | 1 | MAR |
| `pilotage-reseau/src/promos_ventes.php` | 1 | MAR |
| `pilotage-reseau/src/prospection.php` | 1 | MAR |
| `pilotage-reseau/src/push.php` | 1 | SHARED |
| `pilotage-reseau/src/rapports.php` | 1 | CON |
| `pilotage-reseau/src/rayons_photos.php` | 1 | CON |
| `pilotage-reseau/src/recette_produit.php` | 1 | PANEL |
| `pilotage-reseau/src/recurrence_pwa.php` | 1 | CON |
| `pilotage-reseau/src/referentiel.php` | 1 | FOR |
| `pilotage-reseau/src/referentiel_guides.php` | 1 | FOR |
| `pilotage-reseau/src/resultat.php` | 1 | SHARED |
| `pilotage-reseau/src/resultat_pdf.php` | 1 | SHARED |
| `pilotage-reseau/src/revue_soir.php` | 1 | PANEL |
| `pilotage-reseau/src/scoring.php` | 1 | CON |
| `pilotage-reseau/src/scouting_osm.php` | 1 | MAR |
| `pilotage-reseau/src/sms.php` | 1 | SHARED |
| `pilotage-reseau/src/smtp.php` | 1 | SHARED |
| `pilotage-reseau/src/social_publication.php` | 1 | MAR |
| `pilotage-reseau/src/sop.php` | 1 | FOR |
| `pilotage-reseau/src/stats_ventes.php` | 1 | PANEL |
| `pilotage-reseau/src/structure_gamme.php` | 1 | CON |
| `pilotage-reseau/src/suivi.php` | 1 | CON |
| `pilotage-reseau/src/suivi_sources.php` | 1 | CON |
| `pilotage-reseau/src/tablette.php` | 1 | PANEL |
| `pilotage-reseau/src/tablette_objectifs.php` | 1 | PANEL |
| `pilotage-reseau/src/tablette_remarques.php` | 1 | PANEL |
| `pilotage-reseau/src/tablettes.php` | 1 | FOR |
| `pilotage-reseau/src/taches_suivi.php` | 1 | CON |
| `pilotage-reseau/src/task_cadre.php` | 1 | CON |
| `pilotage-reseau/src/ventes.php` | 1 | CON |
| `pilotage-reseau/src/ventes_pro.php` | 1 | CON |
| `pilotage-reseau/src/ventes_semaines.php` | 1 | SHARED |
| `pilotage-reseau/src/verification.php` | 1 | CON |
| `pilotage-reseau/src/verification_base.php` | 1 | CON |
| `pilotage-reseau/src/verification_ca.php` | 1 | CON |
| `pilotage-reseau/src/verification_correction.php` | 1 | CON |
| `pilotage-reseau/src/verification_flux.php` | 1 | CON |
| `pilotage-reseau/src/verification_matiere_edition.php` | 1 | CON |
| `pilotage-reseau/src/verification_matieres.php` | 1 | CON |
| `pilotage-reseau/src/verification_matieres_prix.php` | 1 | CON |
| `pilotage-reseau/src/verification_mouvements.php` | 1 | CON |
| `pilotage-reseau/src/visibilite_google.php` | 1 | MAR |
| `pilotage-reseau/src/visites.php` | 1 | CON |
| `pilotage-reseau/src/visites_cadre.php` | 1 | CON |
| `pilotage-reseau/src/visites_conformite.php` | 1 | CON |
| `pilotage-reseau/src/visites_equipe.php` | 1 | CON |
| `pilotage-reseau/src/visites_evaluation.php` | 1 | CON |
| `pilotage-reseau/src/visites_kpi.php` | 1 | CON |
| `pilotage-reseau/src/visites_outils.php` | 1 | CON |
| `pilotage-reseau/src/visites_questions.php` | 1 | CON |
| `pilotage-reseau/src/writes.php` | 1 | SHARED |
| `pilotage-reseau/tests/acces/` | 1 | SHARED |
| `pilotage-reseau/tests/developpement/` | 1 | SHARED |
| `pilotage-reseau/tests/fidelite/` | 7 | MAR |
| `pilotage-reseau/tests/formation/` | 2 | FOR |
| `pilotage-reseau/tests/hr/` | 38 | HORS |
| `pilotage-reseau/tests/visibilite/` | 2 | MAR |
| `pilotage-reseau/tools/assistant-src/` | 56 | MAR |
| `pilotage-reseau/tools/dashboard-api-index.py` | 1 | PANEL |
| `pilotage-reseau/tools/design-system/` | 1 | SHARED |
| `pilotage-reseau/tools/essai_voucher.php` | 1 | MAR |
| `pilotage-reseau/tools/formation/` | 6 | FOR |
| `pilotage-reseau/tools/hr-dimona/` | 1 | HORS |
| `pilotage-reseau/tools/metadonnee-essai/` | 5 | MAR |
| `pilotage-reseau/tools/sonde_antigaspi_halle.php` | 1 | PANEL |
| `pilotage-reseau/tools/sonde_assistant_halle.php` | 1 | PANEL |
| `pilotage-reseau/tools/sonde_cartes_fidelite.php` | 1 | MAR |
| `pilotage-reseau/tools/sonde_clients_bon.php` | 1 | MAR |
| `pilotage-reseau/tools/sonde_dashboard_halle.php` | 1 | PANEL |
| `pilotage-reseau/tools/sonde_fidelite_halle.php` | 1 | MAR |
| `pilotage-reseau/tools/sonde_profils_halle.php` | 1 | MAR |
| `pilotage-reseau/tools/sonde_vouchers.php` | 1 | MAR |
| `pilotage-reseau/tools/tablette-LISEZMOI.md` | 1 | PANEL |

Légende : MAR = Marketing, FOR = Formation, CON = Pilotage consultant, PANEL = Dashboard franchisé, SHARED = partagé, HORS = hors périmètre (reste en place jusqu’à la phase 4), RACINE = fichiers communs du dépôt.

## 2. Inventaire des tables

Colonnes « réf. » : nombre de fichiers qui citent la table, par nature — code serveur / écran / script (bin, tools, workflows) / test / SQL / documentation. « Lu par » : domaines des fichiers de code qui la citent.

### Partagé (shared/) — 8 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `ceo_action_usage` | `ceo_` | = | à mesurer | — | — | 2/0/0/0/0/1 | SHARED | compteur d’usage des boutons, technique |
| `ceo_app_setting` | `ceo_` | = | à mesurer | — | — | 88/2/16/19/3/16 | CON FOR HORS MAR PANEL SHARED | réglages de tous les domaines ; porte des secrets à migrer |
| `ceo_connecteur` | `ceo_` | = | à mesurer | — | — | 1/1/0/0/1/3 | SHARED | état des connecteurs (aucun secret) |
| `ceo_ecran_vue` | `ceo_` | = | à mesurer | — | — | 2/0/0/0/0/1 | SHARED | compteur d’ouverture des écrans, technique |
| `ceo_journal_entry` | `ceo_` | = | à mesurer | — | — | 3/0/1/2/3/4 | CON SHARED | journal commun ; lu aussi par Social (dépôt marketing) |
| `ceo_push_abonnement` | `ceo_` | = | à mesurer | — | — | 1/0/1/0/0/2 | CON SHARED | web push, transport commun |
| `ceo_shop` | `ceo_` | = | à mesurer | — | ceo_project_task ceo_shop_budget ceo_shop_month_perf mar_avis mar_avis_fiche mar_social_post | 16/0/3/0/3/5 | CON FOR MAR PANEL SHARED | entité magasin, lue par les quatre domaines (FK entrantes) |
| `erp_cles` | `erp_` | = | à mesurer | — | — | 14/0/9/1/1/6 | MAR PANEL SHARED | = « erp_keys » de la mission : la table des clés commune |

### Marketing (mar_) — 67 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `ceo_fid_jour` | `ceo_` | `mar_fid_jour` | à mesurer | — | — | 1/0/0/1/0/0 | MAR | fidélité |
| `ceo_fid_profil` | `ceo_` | `mar_fid_profil` | à mesurer | — | — | 1/0/0/1/0/0 | MAR | fidélité |
| `ceo_fid_regle` | `ceo_` | `mar_fid_regle` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | fidélité |
| `ceo_fid_ticket` | `ceo_` | `mar_fid_ticket` | à mesurer | — | — | 2/0/1/1/0/0 | MAR PANEL | fidélité |
| `ceo_fid_visite` | `ceo_` | `mar_fid_visite` | à mesurer | — | — | 1/0/0/0/0/3 | MAR | fidélité |
| `ceo_md_form` | `ceo_` | `mar_md_form` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | MétaDonnée |
| `ceo_md_journal` | `ceo_` | `mar_md_journal` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | MétaDonnée |
| `ceo_md_lead` | `ceo_` | `mar_md_lead` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | MétaDonnée |
| `ceo_md_shop` | `ceo_` | `mar_md_shop` | à mesurer | — | — | 2/0/0/1/0/2 | CON MAR | MétaDonnée |
| `ceo_promo` | `ceo_` | `mar_promo` | à mesurer | — | — | 3/0/0/0/1/2 | CON MAR SHARED | registre : à fondre plus tard dans mar_promotion |
| `ceo_promo_mecanique` | `ceo_` | `mar_promo_mecanique` | à mesurer | — | — | 1/0/0/0/1/2 | MAR SHARED |  |
| `ceo_prospect` | `ceo_` | `mar_prospect` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | ? prospection B2B locale (ou Recrutement) |
| `mar_agency` | `mar_` | = | à mesurer | mar_lever | mar_agency_campaign | 5/0/1/0/1/1 | MAR | déjà au bon préfixe |
| `mar_agency_ask` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_agency_campaign` | `mar_` | = | à mesurer | mar_agency mar_campaign mar_channel | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_asset_render` | `mar_` | = | à mesurer | mar_campaign_asset mar_format | — | 3/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_avis` | `mar_` | = | à mesurer | ceo_shop | — | 5/0/0/0/1/4 | MAR SHARED | déjà au bon préfixe |
| `mar_avis_fiche` | `mar_` | = | à mesurer | ceo_shop | — | 9/0/0/1/1/5 | CON MAR PANEL SHARED | déjà au bon préfixe |
| `mar_b2b_option` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_b2b_prospect` | `mar_` | = | à mesurer | mar_b2b_sector mar_brand mar_shop | mar_b2b_prospect_sector mar_crm_lead | 3/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_b2b_prospect_sector` | `mar_` | = | à mesurer | mar_b2b_prospect mar_b2b_sector | — | 3/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_b2b_sector` | `mar_` | = | à mesurer | — | mar_b2b_prospect mar_b2b_prospect_sector mar_crm_lead | 5/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_bg_controle` | `mar_` | = | à mesurer | — | — | 3/0/0/0/1/3 | MAR SHARED | déjà au bon préfixe |
| `mar_bg_page` | `mar_` | = | à mesurer | — | — | 4/0/0/0/2/2 | MAR PANEL SHARED | déjà au bon préfixe |
| `mar_bg_regle` | `mar_` | = | à mesurer | — | — | 2/0/0/0/2/2 | MAR SHARED | déjà au bon préfixe |
| `mar_campagne_annexe` | `mar_` | = | à mesurer | — | — | 3/0/0/0/0/2 | MAR PANEL | déjà au bon préfixe |
| `mar_campagne_annexe_type` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_campagne_mesure` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_campagne_objectif` | `mar_` | = | à mesurer | — | — | 4/0/0/0/0/2 | CON MAR SHARED | déjà au bon préfixe |
| `mar_campagne_objectif_produit` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/2 | MAR SHARED | déjà au bon préfixe |
| `mar_campagne_produit` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/2 | MAR SHARED | déjà au bon préfixe |
| `mar_campagne_releve` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_campagne_snapshot` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_channel` | `mar_` | = | à mesurer | — | mar_agency_campaign | 5/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_client_target` | `mar_` | = | à mesurer | — | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_cost_kind` | `mar_` | = | à mesurer | — | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_crm_lead` | `mar_` | = | à mesurer | mar_b2b_prospect mar_b2b_sector mar_campaign mar_lead_status mar_shop | mar_crm_lead_event | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_crm_lead_event` | `mar_` | = | à mesurer | mar_crm_lead | — | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_crm_segment` | `mar_` | = | à mesurer | — | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_format` | `mar_` | = | à mesurer | — | mar_asset_render | 3/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_lead_status` | `mar_` | = | à mesurer | — | mar_crm_lead | 3/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_nl_campagne` | `mar_` | = | à mesurer | — | — | 4/0/1/1/0/3 | MAR SHARED | déjà au bon préfixe |
| `mar_nl_contact` | `mar_` | = | à mesurer | — | — | 4/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_nl_envoi` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_nl_magasin` | `mar_` | = | à mesurer | — | — | 3/0/0/1/0/2 | MAR | déjà au bon préfixe |
| `mar_nl_segment` | `mar_` | = | à mesurer | — | — | 4/0/0/1/0/2 | MAR PANEL | déjà au bon préfixe |
| `mar_nl_source` | `mar_` | = | à mesurer | — | — | 2/0/0/1/0/2 | MAR | déjà au bon préfixe |
| `mar_nl_voucher` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/2 | MAR | déjà au bon préfixe |
| `mar_offer_item` | `mar_` | = | à mesurer | — | mar_offer_item_season mar_offer_template_item | 9/0/2/0/1/1 | MAR SHARED | déjà au bon préfixe |
| `mar_offer_item_season` | `mar_` | = | à mesurer | mar_offer_item | — | 3/0/1/0/1/1 | MAR SHARED | déjà au bon préfixe |
| `mar_offer_template` | `mar_` | = | à mesurer | — | mar_offer_template_item | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_offer_template_item` | `mar_` | = | à mesurer | mar_offer_item mar_offer_template | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_pos_answer_type` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_position` | `mar_` | = | à mesurer | — | mar_retroplanning_default mar_retroplanning_step | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_promotion_mechanic` | `mar_` | = | à mesurer | — | — | 2/0/1/0/1/1 | MAR | déjà au bon préfixe |
| `mar_retroplanning_default` | `mar_` | = | à mesurer | mar_position | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_retroplanning_step` | `mar_` | = | à mesurer | mar_campaign mar_position | — | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_review_platform` | `mar_` | = | à mesurer | — | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_sales_channel` | `mar_` | = | à mesurer | — | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_shop_revenue` | `mar_` | = | à mesurer | mar_shop | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_shop_user` | `mar_` | = | à mesurer | mar_shop | — | 1/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_social_ecart` | `mar_` | = | à mesurer | mar_social_post | — | 4/0/1/0/2/3 | MAR SHARED | déjà au bon préfixe |
| `mar_social_post` | `mar_` | = | à mesurer | ceo_shop | mar_social_ecart | 9/0/2/0/2/4 | MAR PANEL SHARED | déjà au bon préfixe |
| `mar_social_publication` | `mar_` | = | à mesurer | — | — | 3/0/0/0/1/4 | MAR SHARED | déjà au bon préfixe |
| `mar_tone` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_uniform` | `mar_` | = | à mesurer | mar_campaign | — | 3/0/0/0/1/1 | MAR | déjà au bon préfixe |
| `mar_voucher` | `mar_` | = | à mesurer | mar_campaign | — | 2/0/2/0/1/1 | MAR SHARED | déjà au bon préfixe |

### Formation (for_) — 19 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `ceo_manuel_formation` | `ceo_` | `for_manuel_formation` | à mesurer | — | — | 2/0/0/0/0/2 | FOR | Formation |
| `ceo_manuel_procedure` | `ceo_` | `for_manuel_procedure` | à mesurer | — | — | 2/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_bloc` | `ceo_` | `for_ref_bloc` | à mesurer | — | — | 1/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_corresp` | `ceo_` | `for_ref_corresp` | à mesurer | — | — | 2/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_domaine` | `ceo_` | `for_ref_domaine` | à mesurer | — | — | 1/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_formation` | `ceo_` | `for_ref_formation` | à mesurer | — | — | 1/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_item` | `ceo_` | `for_ref_item` | à mesurer | — | — | 1/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_po_point` | `ceo_` | `for_ref_po_point` | à mesurer | — | — | 1/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_po_section` | `ceo_` | `for_ref_po_section` | à mesurer | — | — | 1/0/0/0/0/2 | FOR | Formation |
| `ceo_ref_souscat` | `ceo_` | `for_ref_souscat` | à mesurer | — | — | 2/0/0/0/0/3 | FOR SHARED | Formation |
| `ceo_sop` | `ceo_` | `for_sop` | à mesurer | — | — | 6/0/0/2/0/6 | CON FOR PANEL SHARED | Formation |
| `ceo_sop_personne` | `ceo_` | `for_sop_personne` | à mesurer | — | — | 1/0/0/0/0/3 | FOR | Formation |
| `ceo_sop_suivi` | `ceo_` | `for_sop_suivi` | à mesurer | — | — | 2/0/0/0/0/3 | FOR | Formation |
| `ceo_sop_tache` | `ceo_` | `for_sop_tache` | à mesurer | — | — | 1/0/0/0/0/1 | CON | ? écrite par le cadre des tâches (consultant) |
| `ceo_sop_version` | `ceo_` | `for_sop_version` | à mesurer | — | — | 4/0/0/2/0/5 | FOR SHARED | Formation |
| `ceo_tablette_acces` | `ceo_` | `for_tablette_acces` | à mesurer | — | — | 1/0/0/0/0/3 | FOR | Manuel du réseau / tablettes des magasins |
| `ceo_tablette_essai` | `ceo_` | `for_tablette_essai` | à mesurer | — | — | 1/0/0/0/0/3 | FOR | Manuel du réseau / tablettes des magasins |
| `ceo_tablette_gestion_essai` | `ceo_` | `for_tablette_gestion_essai` | à mesurer | — | — | 1/0/0/0/0/3 | FOR | Manuel du réseau / tablettes des magasins |
| `ceo_tablette_magasin` | `ceo_` | `for_tablette_magasin` | à mesurer | — | — | 1/0/0/0/0/3 | FOR | Manuel du réseau / tablettes des magasins |

### Pilotage consultant (con_) — 106 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `ceo_alert_rule` | `ceo_` | `con_alert_rule` | à mesurer | — | — | 2/0/1/0/3/2 | SHARED |  |
| `ceo_audit_alerte` | `ceo_` | `con_audit_alerte` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_audit_campagne` | `ceo_` | `con_audit_campagne` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_audit_copie` | `ceo_` | `con_audit_copie` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_audit_destinataire` | `ceo_` | `con_audit_destinataire` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_audit_question` | `ceo_` | `con_audit_question` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_audit_reponse` | `ceo_` | `con_audit_reponse` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_bench_correspondance` | `ceo_` | `con_bench_correspondance` | à mesurer | — | — | 1/0/0/1/0/2 | CON |  |
| `ceo_bench_import` | `ceo_` | `con_bench_import` | à mesurer | — | — | 1/0/0/0/0/0 | CON |  |
| `ceo_benchmark_snapshot` | `ceo_` | `con_benchmark_snapshot` | à mesurer | — | — | 1/0/0/0/0/2 | CON | ? benchmark concurrence (ou Marketing) |
| `ceo_ca_demande` | `ceo_` | `con_ca_demande` | à mesurer | — | — | 2/0/0/0/0/1 | SHARED |  |
| `ceo_cadre_cree` | `ceo_` | `con_cadre_cree` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_combo` | `ceo_` | `con_combo` | à mesurer | — | — | 4/0/0/0/0/2 | CON PANEL |  |
| `ceo_crois_cache` | `ceo_` | `con_crois_cache` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_doc` | `ceo_` | `con_doc` | à mesurer | — | — | 1/0/0/1/0/2 | CON |  |
| `ceo_email_template` | `ceo_` | `con_email_template` | à mesurer | — | — | 3/0/1/0/3/2 | SHARED |  |
| `ceo_kpi_def` | `ceo_` | `con_kpi_def` | à mesurer | — | — | 5/1/0/1/0/2 | CON SHARED | ? référentiel KPI lu aussi par le dashboard |
| `ceo_kpi_valeur` | `ceo_` | `con_kpi_valeur` | à mesurer | — | — | 2/0/0/0/0/1 | CON | ? lu aussi par le dashboard |
| `ceo_magasin_fiche` | `ceo_` | `con_magasin_fiche` | à mesurer | — | — | 1/0/0/0/0/1 | CON | ? lu aussi par le dashboard |
| `ceo_network_target` | `ceo_` | `con_network_target` | à mesurer | — | — | 1/0/1/0/3/3 | SHARED |  |
| `ceo_operateur_remarque` | `ceo_` | `con_operateur_remarque` | à mesurer | — | — | 1/0/0/0/0/3 | CON |  |
| `ceo_person` | `ceo_` | `con_person` | à mesurer | — | — | 1/0/1/0/3/3 | SHARED |  |
| `ceo_plan_action` | `ceo_` | `con_plan_action` | à mesurer | — | — | 1/0/0/0/0/2 | CON |  |
| `ceo_plan_engagement` | `ceo_` | `con_plan_engagement` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_plan_revue` | `ceo_` | `con_plan_revue` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_plan_trimestre` | `ceo_` | `con_plan_trimestre` | à mesurer | — | — | 1/0/0/0/0/1 | CON |  |
| `ceo_plano_photo` | `ceo_` | `con_plano_photo` | à mesurer | — | — | 2/0/0/0/0/2 | SHARED |  |
| `ceo_plano_std_emplacement` | `ceo_` | `con_plano_std_emplacement` | à mesurer | — | — | 1/0/0/0/1/1 | CON SHARED |  |
| `ceo_plano_std_montage` | `ceo_` | `con_plano_std_montage` | à mesurer | — | — | 2/0/0/0/1/1 | CON SHARED |  |
| `ceo_plano_std_photo` | `ceo_` | `con_plano_std_photo` | à mesurer | — | — | 2/0/0/0/1/2 | CON PANEL SHARED |  |
| `ceo_plano_std_zone` | `ceo_` | `con_plano_std_zone` | à mesurer | — | — | 1/0/0/0/1/1 | CON SHARED |  |
| `ceo_prod_fin` | `ceo_` | `con_prod_fin` | à mesurer | — | — | 3/0/0/0/0/1 | SHARED |  |
| `ceo_prod_product` | `ceo_` | `con_prod_product` | à mesurer | — | — | 7/0/0/1/1/3 | CON PANEL SHARED |  |
| `ceo_prod_revue` | `ceo_` | `con_prod_revue` | à mesurer | — | — | 3/0/0/1/0/2 | CON SHARED |  |
| `ceo_product` | `ceo_` | `con_product` | à mesurer | — | ceo_product_month_sales | 1/0/1/0/3/3 | SHARED |  |
| `ceo_product_month_sales` | `ceo_` | `con_product_month_sales` | à mesurer | ceo_product | — | 1/0/2/0/3/4 | CON SHARED |  |
| `ceo_project` | `ceo_` | `con_project` | à mesurer | — | — | 4/0/1/0/3/3 | SHARED |  |
| `ceo_project_cost` | `ceo_` | `con_project_cost` | à mesurer | — | — | 2/0/1/0/3/3 | SHARED |  |
| `ceo_project_crm` | `ceo_` | `con_project_crm` | à mesurer | — | — | 2/0/1/0/3/2 | SHARED |  |
| `ceo_project_levid` | `ceo_` | `con_project_levid` | à mesurer | — | — | 2/0/1/0/3/3 | SHARED |  |
| `ceo_project_milestone` | `ceo_` | `con_project_milestone` | à mesurer | — | — | 2/0/1/0/3/3 | SHARED |  |
| `ceo_project_task` | `ceo_` | `con_project_task` | à mesurer | ceo_shop | ceo_task_issue | 4/0/3/0/3/4 | CON SHARED |  |
| `ceo_project_template` | `ceo_` | `con_project_template` | à mesurer | — | — | 3/0/1/0/3/2 | SHARED |  |
| `ceo_pwa_client` | `ceo_` | `con_pwa_client` | à mesurer | — | — | 1/0/0/0/0/2 | CON | ? récurrence des clients PWA (levier) — ou Marketing |
| `ceo_pwa_jour` | `ceo_` | `con_pwa_jour` | à mesurer | — | — | 1/0/0/0/0/2 | CON | ? récurrence des clients PWA (levier) — ou Marketing |
| `ceo_pwa_visite` | `ceo_` | `con_pwa_visite` | à mesurer | — | — | 1/0/0/0/0/2 | CON | ? récurrence des clients PWA (levier) — ou Marketing |
| `ceo_rapport` | `ceo_` | `con_rapport` | à mesurer | — | — | 8/1/0/4/0/2 | CON PANEL SHARED |  |
| `ceo_rapport_run` | `ceo_` | `con_rapport_run` | à mesurer | — | — | 5/0/0/2/0/1 | CON PANEL SHARED |  |
| `ceo_report_schedule` | `ceo_` | `con_report_schedule` | à mesurer | — | — | 2/0/2/0/3/4 | SHARED |  |
| `ceo_shop_budget` | `ceo_` | `con_shop_budget` | à mesurer | ceo_shop | — | 4/0/1/0/3/4 | CON PANEL SHARED |  |
| `ceo_shop_budget_line` | `ceo_` | `con_shop_budget_line` | à mesurer | — | — | 2/0/1/0/3/3 | SHARED |  |
| `ceo_shop_budget_month` | `ceo_` | `con_shop_budget_month` | à mesurer | — | — | 1/0/1/0/3/3 | SHARED |  |
| `ceo_shop_charge_month` | `ceo_` | `con_shop_charge_month` | à mesurer | — | — | 4/0/0/0/0/1 | CON SHARED |  |
| `ceo_shop_month_note` | `ceo_` | `con_shop_month_note` | à mesurer | — | — | 2/0/0/0/1/2 | SHARED |  |
| `ceo_shop_month_perf` | `ceo_` | `con_shop_month_perf` | à mesurer | ceo_shop | — | 14/0/2/3/3/7 | CON MAR PANEL SHARED |  |
| `ceo_shop_profil_heure` | `ceo_` | `con_shop_profil_heure` | à mesurer | — | — | 1/0/0/0/0/1 | SHARED |  |
| `ceo_shop_profil_jour` | `ceo_` | `con_shop_profil_jour` | à mesurer | — | — | 2/0/0/0/0/1 | MAR SHARED |  |
| `ceo_stock_evenement` | `ceo_` | `con_stock_evenement` | à mesurer | — | — | 2/0/0/0/0/1 | CON |  |
| `ceo_stock_releve` | `ceo_` | `con_stock_releve` | à mesurer | — | — | 2/0/0/0/0/2 | CON |  |
| `ceo_supplier` | `ceo_` | `con_supplier` | à mesurer | — | — | 4/0/1/0/3/4 | CON SHARED |  |
| `ceo_task_annotation` | `ceo_` | `con_task_annotation` | à mesurer | — | — | 3/0/0/0/0/2 | SHARED |  |
| `ceo_task_issue` | `ceo_` | `con_task_issue` | à mesurer | ceo_project_task | — | 3/0/1/0/1/4 | SHARED |  |
| `ceo_verif_ca` | `ceo_` | `con_verif_ca` | à mesurer | — | — | 2/0/0/1/0/3 | CON SHARED |  |
| `ceo_verif_commande` | `ceo_` | = | à mesurer | — | — | 0/0/0/0/0/1 | — | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `ceo_verif_correction` | `ceo_` | `con_verif_correction` | à mesurer | — | — | 1/0/0/1/0/4 | CON |  |
| `ceo_verif_geste` | `ceo_` | = | à mesurer | — | — | 0/0/0/0/0/1 | — | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `ceo_verif_inventaire` | `ceo_` | `con_verif_inventaire` | à mesurer | — | — | 3/0/0/1/0/2 | CON |  |
| `ceo_verif_jour` | `ceo_` | `con_verif_jour` | à mesurer | — | — | 3/2/0/1/0/5 | CON |  |
| `ceo_verif_matiere` | `ceo_` | = | à mesurer | — | — | 0/0/0/0/0/1 | — | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `ceo_verif_produit` | `ceo_` | = | à mesurer | — | — | 0/0/0/0/0/1 | — | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `ceo_verif_recette` | `ceo_` | `con_verif_recette` | à mesurer | — | — | 1/0/0/2/0/2 | CON |  |
| `ceo_verif_stock_jour` | `ceo_` | = | à mesurer | — | — | 0/0/0/0/0/1 | — | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `con_consultant` | `con_` | = | à mesurer | — | — | 3/0/2/0/3/3 | CON SHARED | déjà au bon préfixe |
| `con_consultant_checklist` | `con_` | = | à mesurer | — | — | 2/0/1/0/0/2 | CON SHARED | déjà au bon préfixe |
| `con_consultant_tache` | `con_` | = | à mesurer | — | — | 6/0/1/0/0/5 | CON SHARED | déjà au bon préfixe |
| `con_consultant_visit` | `con_` | = | à mesurer | — | — | 2/0/2/0/3/4 | CON SHARED | déjà au bon préfixe |
| `con_equipe_releve` | `con_` | = | à mesurer | — | — | 2/0/1/0/0/4 | CON SHARED | déjà au bon préfixe |
| `con_franchise_meteo` | `con_` | = | à mesurer | — | — | 3/0/1/0/0/2 | CON SHARED | déjà au bon préfixe |
| `con_msp` | `con_` | = | à mesurer | — | — | 2/0/2/0/0/4 | CON SHARED | déjà au bon préfixe |
| `con_rayon_photo` | `con_` | = | à mesurer | — | — | 3/0/0/1/0/2 | CON | déjà au bon préfixe |
| `con_scoring_google` | `con_` | = | à mesurer | — | — | 2/0/1/0/1/2 | CON SHARED | déjà au bon préfixe |
| `con_scoring_msp` | `con_` | = | à mesurer | — | — | 5/0/1/1/1/5 | CON SHARED | déjà au bon préfixe |
| `con_tache_jour` | `con_` | = | à mesurer | — | — | 10/1/2/3/0/4 | CON PANEL SHARED | déjà au bon préfixe |
| `con_tache_jour_etat` | `con_` | = | à mesurer | — | — | 4/0/1/1/0/2 | CON SHARED | déjà au bon préfixe |
| `con_visite` | `con_` | = | à mesurer | — | — | 9/1/2/1/0/8 | CON SHARED | déjà au bon préfixe |
| `con_visite_action` | `con_` | = | à mesurer | — | — | 5/0/1/1/0/3 | CON SHARED | déjà au bon préfixe |
| `con_visite_action_evt` | `con_` | = | à mesurer | — | — | 4/0/1/0/0/2 | CON SHARED | déjà au bon préfixe |
| `con_visite_photo` | `con_` | = | à mesurer | — | — | 4/0/1/2/0/6 | CON SHARED | déjà au bon préfixe |
| `con_visite_point` | `con_` | = | à mesurer | — | — | 6/0/2/1/0/4 | CON SHARED | déjà au bon préfixe |
| `hdk_demande` | `hdk_` | `con_hdk_demande` | à mesurer | — | — | 1/0/0/0/0/0 | CON | ? helpdesk : cockpit ET dashboard |
| `hdk_mission` | `hdk_` | `con_hdk_mission` | à mesurer | — | — | 1/0/0/0/0/0 | CON | ? helpdesk : cockpit ET dashboard |
| `pil_cq_analyse` | `pil_` | `con_cq_analyse` | à mesurer | — | — | 1/0/0/0/0/0 | CON | cockpit seulement (déjà « pil_ ») |
| `pil_cq_exemple` | `pil_` | `con_cq_exemple` | à mesurer | — | — | 1/0/0/1/0/0 | CON | cockpit seulement (déjà « pil_ ») |
| `pil_cq_ia` | `pil_` | `con_cq_ia` | à mesurer | — | — | 1/0/0/1/0/0 | CON | cockpit seulement (déjà « pil_ ») |
| `pil_fil` | `pil_` | `con_fil` | à mesurer | — | — | 1/0/0/1/0/2 | CON | cockpit seulement (déjà « pil_ ») |
| `pil_fil_entree` | `pil_` | `con_fil_entree` | à mesurer | — | — | 1/0/0/1/0/2 | CON | cockpit seulement (déjà « pil_ ») |
| `pil_fil_piece` | `pil_` | `con_fil_piece` | à mesurer | — | — | 1/0/0/1/0/2 | CON | cockpit seulement (déjà « pil_ ») |
| `pla_contenant` | `pla_` | `con_pla_contenant` | à mesurer | — | — | 3/0/0/0/0/1 | SHARED | planogramme |
| `pla_format` | `pla_` | `con_pla_format` | à mesurer | — | — | 3/0/0/0/0/1 | SHARED | planogramme |
| `pla_meuble` | `pla_` | `con_pla_meuble` | à mesurer | — | — | 3/0/0/0/1/1 | SHARED | planogramme |
| `pla_montage` | `pla_` | `con_pla_montage` | à mesurer | — | — | 1/0/0/0/0/1 | SHARED | planogramme |
| `pla_niveau` | `pla_` | `con_pla_niveau` | à mesurer | — | — | 3/0/0/0/1/1 | SHARED | planogramme |
| `pla_note` | `pla_` | `con_pla_note` | à mesurer | — | — | 3/0/0/0/1/1 | SHARED | planogramme |
| `pla_placement` | `pla_` | `con_pla_placement` | à mesurer | — | — | 3/0/0/0/1/1 | SHARED | planogramme |
| `pla_slot` | `pla_` | `con_pla_slot` | à mesurer | — | — | 3/0/0/0/1/2 | SHARED | planogramme |
| `pla_zone` | `pla_` | `con_pla_zone` | à mesurer | — | — | 3/0/0/0/1/1 | SHARED | planogramme |

### Dashboard franchisé (panel_) — 31 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `aia_bundle` | `aia_` | `panel_aia_bundle` | à mesurer | — | — | 1/0/0/0/0/1 | PANEL | assistant IA du dashboard |
| `aia_cache` | `aia_` | `panel_aia_cache` | à mesurer | — | — | 3/0/0/0/0/1 | PANEL | assistant IA du dashboard |
| `aia_conversation` | `aia_` | `panel_aia_conversation` | à mesurer | — | — | 2/1/0/0/0/1 | PANEL | assistant IA du dashboard |
| `aia_fiche` | `aia_` | `panel_aia_fiche` | à mesurer | — | — | 1/0/0/1/0/1 | PANEL | assistant IA du dashboard |
| `aia_manuel_passage` | `aia_` | `panel_aia_manuel_passage` | à mesurer | — | — | 1/0/0/1/0/1 | PANEL | assistant IA du dashboard |
| `aia_modele` | `aia_` | `panel_aia_modele` | à mesurer | — | — | 1/1/0/0/0/1 | PANEL | assistant IA du dashboard |
| `aia_question` | `aia_` | `panel_aia_question` | à mesurer | — | — | 2/0/0/0/0/1 | PANEL | assistant IA du dashboard |
| `aia_reglage` | `aia_` | `panel_aia_reglage` | à mesurer | — | — | 2/0/0/2/0/1 | PANEL | assistant IA du dashboard |
| `aia_visuel` | `aia_` | `panel_aia_visuel` | à mesurer | — | — | 2/0/0/0/0/1 | PANEL | assistant IA du dashboard |
| `ceo_hist_heure` | `ceo_` | `panel_hist_heure` | à mesurer | — | — | 1/0/0/1/0/1 | PANEL | historique des ventes de l’assistant |
| `ceo_hist_jour` | `ceo_` | `panel_hist_jour` | à mesurer | — | — | 2/0/0/2/0/1 | PANEL | historique des ventes de l’assistant |
| `ceo_hist_produit` | `ceo_` | `panel_hist_produit` | à mesurer | — | — | 1/0/0/0/0/1 | PANEL | historique des ventes de l’assistant |
| `ceo_matiere_code` | `ceo_` | `panel_matiere_code` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | ? scan des cartons en magasin (créée par mesure.php) |
| `ceo_reclamation_photo` | `ceo_` | `panel_reclamation_photo` | à mesurer | — | — | 1/0/0/0/0/2 | MAR | photos de réclamation prises depuis le dashboard |
| `ceo_revue_soir` | `ceo_` | `panel_revue_soir` | à mesurer | — | — | 2/0/0/0/0/2 | CON PANEL |  |
| `ceo_shop_day_note` | `ceo_` | `panel_shop_day_note` | à mesurer | — | — | 1/0/0/0/1/2 | PANEL SHARED |  |
| `ceo_tablette_remarque` | `ceo_` | `panel_tablette_remarque` | à mesurer | — | — | 2/0/0/0/1/2 | PANEL SHARED | remarques clients saisies sur la tablette des vendeuses |
| `mot_borne` | `mot_` | `panel_mot_borne` | à mesurer | — | — | 2/0/0/1/0/2 | PANEL | ? app employés, primes, pointage |
| `mot_cache` | `mot_` | = | à mesurer | — | — | 3/0/0/2/0/3 | PANEL | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `mot_grand_prix` | `mot_` | `panel_mot_grand_prix` | à mesurer | — | — | 3/0/0/1/0/4 | PANEL | ? app employés, primes, pointage |
| `mot_pointage` | `mot_` | `panel_mot_pointage` | à mesurer | — | — | 2/0/0/1/0/2 | PANEL | ? app employés, primes, pointage |
| `mot_profil` | `mot_` | `panel_mot_profil` | à mesurer | — | — | 3/0/0/1/0/4 | PANEL | ? app employés, primes, pointage |
| `mot_reglage` | `mot_` | = | à mesurer | — | — | 4/0/0/1/0/3 | PANEL | définie seulement dans la documentation : jamais créée par le code (à vérifier en base) |
| `pil_auto_reglage` | `pil_` | `panel_auto_reglage` | à mesurer | — | — | 1/0/0/0/0/1 | PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_auto` | `pil_` | `panel_boite_auto` | à mesurer | — | — | 2/0/0/0/0/1 | PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_etat` | `pil_` | `panel_boite_etat` | à mesurer | — | — | 1/0/0/0/0/1 | PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_journal` | `pil_` | `panel_boite_journal` | à mesurer | — | — | 2/0/0/0/0/1 | PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_modele` | `pil_` | `panel_boite_modele` | à mesurer | — | — | 1/0/0/0/0/1 | PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_equipe_mail` | `pil_` | `panel_equipe_mail` | à mesurer | — | — | 2/0/0/0/0/1 | PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_frais_tva` | `pil_` | `panel_frais_tva` | à mesurer | — | — | 2/0/0/0/0/4 | PANEL SHARED | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_tresorerie_plan` | `pil_` | `panel_tresorerie_plan` | à mesurer | — | — | 2/0/0/1/0/5 | CON PANEL | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |

### Hors périmètre — 19 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `ceo_scouting_candidate` | `ceo_` | = | à mesurer | — | — | 1/0/0/0/1/2 | SHARED | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_competitor` | `ceo_` | = | à mesurer | — | — | 1/0/0/0/1/2 | SHARED | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_etude` | `ceo_` | = | à mesurer | — | — | 2/0/0/0/1/2 | SHARED | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_population` | `ceo_` | = | à mesurer | — | — | 1/0/0/0/1/3 | SHARED | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_tile` | `ceo_` | = | à mesurer | — | — | 1/0/1/0/1/3 | SHARED | scouting = Recrutement (absent des 4 domaines) |
| `ceo_x` | `ceo_` | = | à mesurer | — | — | 0/0/1/0/0/1 | SHARED | table de test (bin/tests.php), jamais en base normalement |
| `hr_dimona_audit` | `hr_` | = | à mesurer | — | — | 2/0/0/0/1/1 | HORS | module RH / Dimona |
| `hr_dimona_credentials` | `hr_` | = | à mesurer | — | — | 2/0/0/1/1/1 | HORS | module RH / Dimona |
| `hr_dimona_declarations` | `hr_` | = | à mesurer | — | — | 2/0/0/1/1/1 | HORS | module RH / Dimona |
| `hr_dimona_employer_shops` | `hr_` | = | à mesurer | — | — | 2/0/0/0/1/1 | HORS | module RH / Dimona |
| `hr_dimona_employers` | `hr_` | = | à mesurer | — | — | 2/0/0/1/1/1 | HORS | module RH / Dimona |
| `hr_dimona_meta` | `hr_` | = | à mesurer | — | — | 2/0/0/0/1/0 | HORS | module RH / Dimona |
| `hr_dimona_periods` | `hr_` | = | à mesurer | — | — | 2/0/0/0/1/1 | HORS | module RH / Dimona |
| `hr_dimona_tokens` | `hr_` | = | à mesurer | — | — | 2/0/0/0/1/1 | HORS | module RH / Dimona |
| `hr_dimona_workers` | `hr_` | = | à mesurer | — | — | 2/0/0/0/1/1 | HORS | module RH / Dimona |
| `ceo_assistant_question` | `ceo_` | = | à mesurer (absente ?) | — | — | 2/0/0/0/0/1 | — | obsolète : reprise une fois dans aia_question (src/franchise/assistant_ia.php) ; plus écrite |
| `ceo_parcours` | `ceo_` | = | à mesurer (absente ?) | — | — | 1/0/0/0/0/3 | — | obsolète : effacée par src/formation_postes.php au déploiement (lignes gardées dans ceo_app_setting) |
| `ceo_parcours_etape` | `ceo_` | = | à mesurer (absente ?) | — | — | 1/0/0/0/0/3 | — | obsolète : idem |
| `ceo_prod_planogram` | `ceo_` | = | à mesurer (absente ?) | — | — | 1/0/0/0/0/0 | — | obsolète : reprise dans pla_placement par planoMigrer() (src/installer.php) |

### Autre application (lue) — 94 table(s)

| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |
|---|---|---|---|---|---|---|---|---|
| `b2b_client` | `b2b_` | = | à mesurer | — | — | 1/0/0/0/0/0 | MAR | ERP / panel TF Buddy |
| `client` | `(aucun)` | = | à mesurer | — | — | 5/0/0/1/0/1 | CON PANEL SHARED | ERP / panel TF Buddy |
| `client_order` | `client_` | = | à mesurer | — | — | 3/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `client_order_product` | `client_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `company` | `(aucun)` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `crm_mo_acces` | `crm_` | = | à mesurer | — | — | 1/0/0/0/0/1 | FOR | CRM Recrutement (dépôt atelier-espace-candidat) |
| `crm_mo_chapitres` | `crm_` | = | à mesurer | — | — | 1/0/0/0/0/1 | FOR | CRM Recrutement (dépôt atelier-espace-candidat) |
| `crm_mo_magasins` | `crm_` | = | à mesurer | — | — | 1/0/0/0/0/4 | FOR | CRM Recrutement (dépôt atelier-espace-candidat) |
| `crm_mo_procedures` | `crm_` | = | à mesurer | — | — | 1/0/0/0/0/3 | FOR | CRM Recrutement (dépôt atelier-espace-candidat) |
| `flattened_recipe_ingredient` | `flattened_` | = | à mesurer | — | — | 2/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `franchisee_employee` | `franchisee_` | = | à mesurer | — | — | 4/0/0/2/0/1 | CON HORS PANEL | ERP / panel TF Buddy |
| `franchisee_employee_schedule` | `franchisee_` | = | à mesurer | — | — | 7/0/0/1/0/0 | CON PANEL SHARED | ERP / panel TF Buddy |
| `kpi` | `(aucun)` | = | à mesurer | — | — | 10/5/1/2/2/6 | CON FOR SHARED | ERP / panel TF Buddy |
| `mac_consultant_param` | `mac_` | = | à mesurer | — | — | 0/0/1/0/0/1 | SHARED | PWA consultant (dépôt pwa_consultant) |
| `mac_consultant_visit` | `mac_` | = | à mesurer | — | — | 1/0/0/0/0/1 | SHARED | PWA consultant (dépôt pwa_consultant) |
| `mac_google_rating_snapshot` | `mac_` | = | à mesurer | — | — | 2/0/0/0/0/2 | CON | PWA consultant (dépôt pwa_consultant) |
| `mac_kpi_threshold` | `mac_` | = | à mesurer | — | — | 0/0/1/0/0/1 | SHARED | PWA consultant (dépôt pwa_consultant) |
| `mac_report_share` | `mac_` | = | à mesurer | — | — | 2/0/2/0/3/5 | SHARED | PWA consultant (dépôt pwa_consultant) |
| `mac_shop_monthly_pnl` | `mac_` | = | à mesurer | — | — | 2/0/1/0/0/3 | CON SHARED | PWA consultant (dépôt pwa_consultant) |
| `mac_task_review` | `mac_` | = | à mesurer | — | — | 9/1/0/1/0/7 | CON SHARED | PWA consultant (dépôt pwa_consultant) |
| `mar_brand` | `mar_` | = | à mesurer | — | — | 8/1/0/0/1/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_bundle` | `mar_` | = | à mesurer | — | — | 1/0/1/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_bundle_item` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign` | `mar_` | = | à mesurer | — | — | 21/1/2/0/2/2 | CON MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_agency_ask` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_asset` | `mar_` | = | à mesurer | — | — | 5/0/0/0/1/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_b2b_option` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_b2b_sector` | `mar_` | = | à mesurer | — | — | 3/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_challenge_prize` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_channel` | `mar_` | = | à mesurer | — | — | 4/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_kpi_snapshot` | `mar_` | = | à mesurer | — | — | 1/0/0/0/1/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_lever_target` | `mar_` | = | à mesurer | — | — | 2/0/0/0/1/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_offer` | `mar_` | = | à mesurer | — | — | 5/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_offer_item` | `mar_` | = | à mesurer | — | — | 7/0/0/0/1/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_pos_question` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_shop` | `mar_` | = | à mesurer | — | — | 12/0/0/0/1/2 | CON MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_shop_item_target` | `mar_` | = | à mesurer | — | — | 3/0/0/0/0/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_status` | `mar_` | = | à mesurer | — | — | 10/0/0/0/0/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_type` | `mar_` | = | à mesurer | — | — | 13/0/0/0/0/2 | CON MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_campaign_uniform` | `mar_` | = | à mesurer | — | — | 2/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_crm_customer` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_crm_customer_segment` | `mar_` | = | à mesurer | — | — | 1/0/1/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_fund_movement` | `mar_` | = | à mesurer | — | — | 4/0/0/0/1/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_fund_recurrence` | `mar_` | = | à mesurer | — | — | 3/0/0/0/0/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_fund_source` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_kit` | `mar_` | = | à mesurer | — | — | 1/0/1/0/0/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_kit_activation` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_kit_asset` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_lever` | `mar_` | = | à mesurer | — | — | 11/3/1/0/1/1 | HORS MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_promotion` | `mar_` | = | à mesurer | — | — | 1/0/3/0/0/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_review` | `mar_` | = | à mesurer | — | — | 1/0/1/0/0/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_roi_cost` | `mar_` | = | à mesurer | — | — | 2/0/1/0/1/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_shop` | `mar_` | = | à mesurer | — | — | 13/1/1/0/1/1 | MAR SHARED | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_shop_presence` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_voucher_channel` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `mar_voucher_redemption` | `mar_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | Module Marketing (dépôt marketing, migrations 0xx) |
| `material` | `(aucun)` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `material_category` | `material_` | = | à mesurer | — | — | 2/0/0/0/0/0 | CON PANEL | ERP / panel TF Buddy |
| `material_inventory` | `material_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `material_inventory_log` | `material_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `material_movement_reason` | `material_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `material_order` | `material_` | = | à mesurer | — | — | 3/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `material_order_item` | `material_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `material_order_item_delivery_log` | `material_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `of_tag` | `of_` | = | à mesurer | — | — | 1/0/1/0/2/1 | SHARED | ERP / panel TF Buddy |
| `position` | `(aucun)` | = | à mesurer | — | — | 2/0/1/0/2/0 | SHARED | ERP / panel TF Buddy |
| `product` | `(aucun)` | = | à mesurer | — | — | 12/0/1/1/0/1 | CON MAR PANEL SHARED | ERP / panel TF Buddy |
| `product_availability_period` | `product_` | = | à mesurer | — | — | 3/0/0/0/0/0 | CON SHARED | ERP / panel TF Buddy |
| `product_availability_period_connection` | `product_` | = | à mesurer | — | — | 3/0/0/0/0/0 | CON SHARED | ERP / panel TF Buddy |
| `product_category` | `product_` | = | à mesurer | — | — | 3/0/0/0/0/0 | CON SHARED | ERP / panel TF Buddy |
| `product_category_group` | `product_` | = | à mesurer | — | — | 1/0/0/0/0/0 | SHARED | ERP / panel TF Buddy |
| `product_category_group_connection` | `product_` | = | à mesurer | — | — | 1/0/0/0/0/0 | SHARED | ERP / panel TF Buddy |
| `product_movement` | `product_` | = | à mesurer | — | — | 2/0/1/0/0/0 | CON SHARED | ERP / panel TF Buddy |
| `product_portion` | `product_` | = | à mesurer | — | — | 1/0/0/0/0/1 | MAR | ERP / panel TF Buddy |
| `product_recipe` | `product_` | = | à mesurer | — | — | 3/0/0/0/0/0 | CON SHARED | ERP / panel TF Buddy |
| `product_recipe_material_connection` | `product_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `recipe_cost` | `recipe_` | = | à mesurer | — | — | 1/0/1/0/0/0 | SHARED | ERP / panel TF Buddy |
| `revenue_budget` | `revenue_` | = | à mesurer | — | — | 1/0/0/0/0/0 | SHARED | ERP / panel TF Buddy |
| `shop_material_price_list` | `shop_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `shop_product` | `shop_` | = | à mesurer | — | — | 1/0/1/0/0/0 | SHARED | ERP / panel TF Buddy |
| `shops` | `(aucun)` | = | à mesurer | — | — | 53/0/3/14/0/3 | CON HORS MAR PANEL SHARED | ERP / panel TF Buddy |
| `sig_product_categories` | `sig_` | = | à mesurer | — | — | 0/0/1/0/0/1 | SHARED | Signage (dépôt signage) |
| `sig_products` | `sig_` | = | à mesurer | — | — | 1/0/1/0/0/2 | SHARED | Signage (dépôt signage) |
| `stocktaking` | `(aucun)` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `stocktaking_item` | `stocktaking_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `stocktaking_summary` | `stocktaking_` | = | à mesurer | — | — | 1/0/0/0/0/0 | CON | ERP / panel TF Buddy |
| `todo_task` | `todo_` | = | à mesurer | — | — | 1/0/0/0/0/0 | SHARED | ERP / panel TF Buddy |
| `transaction` | `(aucun)` | = | à mesurer | — | — | 13/0/1/0/0/1 | CON MAR PANEL SHARED | ERP / panel TF Buddy |
| `transaction_product` | `transaction_` | = | à mesurer | — | — | 7/0/1/0/0/0 | CON SHARED | ERP / panel TF Buddy |
| `user_membership` | `user_` | = | à mesurer | — | — | 2/0/1/0/0/0 | SHARED | ERP / panel TF Buddy |
| `user_profile` | `user_` | = | à mesurer | — | — | 2/0/1/0/0/0 | SHARED | ERP / panel TF Buddy |
| `voucher_campaign` | `voucher_` | = | à mesurer | — | — | 0/0/1/0/0/0 | MAR | ERP / panel TF Buddy |
| `voucher_campaign_channel` | `voucher_` | = | à mesurer | — | — | 0/0/1/0/0/0 | MAR | ERP / panel TF Buddy |
| `voucher_code` | `voucher_` | = | à mesurer | — | — | 0/0/1/0/0/0 | MAR | ERP / panel TF Buddy |

## 3. Où chaque table est citée

Fichiers de code, écrans, scripts, tests et SQL (la documentation est comptée au §2, pas listée). Liste brute et triable : `reorg/tables.tsv`, colonne `fichiers`.

<details><summary><code>aia_bundle</code> — 1 fichier(s)</summary>

`src/franchise/bundles.php`

</details>
<details><summary><code>aia_cache</code> — 3 fichier(s)</summary>

`src/coach_objectifs.php`, `src/franchise/assistant_ia.php`, `src/franchise/assistant_vente.php`

</details>
<details><summary><code>aia_conversation</code> — 3 fichier(s)</summary>

`public/dashboard/dashboard.js`, `src/assistant_magasin.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>aia_fiche</code> — 2 fichier(s)</summary>

`bin/tests_controles_photos.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>aia_manuel_passage</code> — 2 fichier(s)</summary>

`bin/tests_assistant_ia.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>aia_modele</code> — 2 fichier(s)</summary>

`public/dashboard/dashboard.js`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>aia_question</code> — 2 fichier(s)</summary>

`src/assistant_magasin.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>aia_reglage</code> — 4 fichier(s)</summary>

`bin/tests_assistant_ia.php`, `bin/tests_controles_photos.php`, `src/assistant_magasin.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>aia_visuel</code> — 2 fichier(s)</summary>

`src/assistant_magasin.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>ceo_action_usage</code> — 2 fichier(s)</summary>

`src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_alert_rule</code> — 6 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_app_setting</code> — 128 fichier(s)</summary>

`bin/boite_cron.sh`, `bin/carte_sources.php`, `bin/deploy.sh`, `bin/disque_alerte.php`, `bin/metadonnee_cron.sh`, `bin/newsletter_cron.sh`, `bin/push_stock.php`, `bin/rapports_cron.sh`, `bin/seed.php`, `bin/social_cron.sh`, `bin/tests.php`, `bin/tests_bons.php`, `bin/tests_ca_net.php`, `bin/tests_controles_photos.php`, `bin/tests_cq_mail.php`, `bin/tests_employes_relus.php`, `bin/tests_evolution_pro.php`, `bin/tests_kpi_cibles.php`, `bin/tests_metadonnee.php`, `bin/tests_panel_reessai.php`, `bin/tests_pnl_ht.php`, `bin/tests_pointages.php`, `bin/tests_primes.php`, `bin/tests_promos.php`, `bin/tests_rapports_suspension.php`, `bin/tests_revue_soir.php`, `bin/tests_verification.php`, `bin/tests_verification_correction.php`, `bin/visites_cron.sh`, `config/config.example.php`, `public/assets/js/app.js`, `public/assets/js/templates.js`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/acces.php`, `src/analyse_produits.php`, `src/anthropic.php`, `src/api_module.php`, `src/assistant_magasin.php`, `src/assortiment.php`, `src/audits.php`, `src/auth.php`, `src/benchmark.php`, `src/benchmark_import.php`, `src/brand_guard.php`, `src/budget_crm.php`, `src/ca_mail.php`, `src/ca_net_mois.php`, `src/cadence.php`, `src/caisse.php`, `src/canaux_offres.php`, `src/commandes.php`, `src/con_tables.php`, `src/connecteurs.php`, `src/controles_qualite.php`, `src/cq_mail.php`, `src/doc.php`, `src/dossier.php`, `src/endpoints.php`, `src/erp_api.php`, `src/fbcontrole.php`, `src/fidelite_profils.php`, `src/formation.php`, `src/formation_postes.php`, `src/franchise/assistant_ia.php`, `src/franchise/boite_mail.php`, `src/franchise/frais.php`, `src/franchise/offres_client.php`, `src/google_api.php`, `src/historique_ventes.php`, `src/hr/bootstrap.php`, `src/ia_assistant.php`, `src/installer.php`, `src/inventaires.php`, `src/kpi_table.php`, `src/kpis.php`, `src/marketing_sources.php`, `src/matiere_fiche.php`, `src/metadonnee.php`, `src/mkt_brief.php`, `src/motivation.php`, `src/newsletter_envoi.php`, `src/panel_api.php`, `src/panel_ventes.php`, `src/planning_sync.php`, `src/pnl_ht.php`, `src/pnl_mois.php`, `src/pointages_mail.php`, `src/ponderation.php`, `src/production_flux.php`, `src/production_plan.php`, `src/promos_bons.php`, `src/promos_magasin.php`, `src/prospection.php`, `src/push.php`, `src/rapports.php`, `src/recette_produit.php`, `src/referentiel.php`, `src/referentiel_guides.php`, `src/resultat.php`, `src/revue_soir.php`, `src/scoring.php`, `src/smtp.php`, `src/social_publication.php`, `src/sop.php`, `src/stats_ventes.php`, `src/structure_gamme.php`, `src/suivi_sources.php`, `src/tablette.php`, `src/tablette_objectifs.php`, `src/tablettes.php`, `src/taches_suivi.php`, `src/ventes.php`, `src/ventes_semaines.php`, `src/verification.php`, `src/verification_correction.php`, `src/verification_flux.php`, `src/visibilite_google.php`, `src/visites.php`, `src/visites_kpi.php`, `src/writes.php`, `tests/fidelite/banc.php`, `tests/fidelite/lib.php`, `tools/sonde_assistant_halle.php`, `tools/sonde_cartes_fidelite.php`, `tools/sonde_clients_bon.php`, `tools/sonde_vouchers.php`

</details>
<details><summary><code>ceo_audit_alerte</code> — 1 fichier(s)</summary>

`src/audits.php`

</details>
<details><summary><code>ceo_audit_campagne</code> — 1 fichier(s)</summary>

`src/audits.php`

</details>
<details><summary><code>ceo_audit_copie</code> — 1 fichier(s)</summary>

`src/audits.php`

</details>
<details><summary><code>ceo_audit_destinataire</code> — 1 fichier(s)</summary>

`src/audits.php`

</details>
<details><summary><code>ceo_audit_question</code> — 1 fichier(s)</summary>

`src/audits.php`

</details>
<details><summary><code>ceo_audit_reponse</code> — 1 fichier(s)</summary>

`src/audits.php`

</details>
<details><summary><code>ceo_bench_correspondance</code> — 2 fichier(s)</summary>

`bin/tests_benchmark_import.php`, `src/benchmark_import.php`

</details>
<details><summary><code>ceo_bench_import</code> — 1 fichier(s)</summary>

`src/benchmark_import.php`

</details>
<details><summary><code>ceo_benchmark_snapshot</code> — 1 fichier(s)</summary>

`src/benchmark.php`

</details>
<details><summary><code>ceo_ca_demande</code> — 2 fichier(s)</summary>

`src/endpoints.php`, `src/installer.php`

</details>
<details><summary><code>ceo_cadre_cree</code> — 1 fichier(s)</summary>

`src/task_cadre.php`

</details>
<details><summary><code>ceo_combo</code> — 4 fichier(s)</summary>

`src/croisements.php`, `src/rapports.php`, `src/tablette.php`, `src/ventes.php`

</details>
<details><summary><code>ceo_connecteur</code> — 3 fichier(s)</summary>

`public/assets/js/templates.js`, `sql/schema.sql`, `src/connecteurs.php`

</details>
<details><summary><code>ceo_crois_cache</code> — 1 fichier(s)</summary>

`src/croisements.php`

</details>
<details><summary><code>ceo_doc</code> — 2 fichier(s)</summary>

`bin/tests_documents.php`, `src/documents.php`

</details>
<details><summary><code>ceo_ecran_vue</code> — 2 fichier(s)</summary>

`src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_email_template</code> — 7 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_fid_jour</code> — 2 fichier(s)</summary>

`src/fidelite_profils.php`, `tests/fidelite/banc.php`

</details>
<details><summary><code>ceo_fid_profil</code> — 2 fichier(s)</summary>

`src/fidelite_profils.php`, `tests/fidelite/banc.php`

</details>
<details><summary><code>ceo_fid_regle</code> — 1 fichier(s)</summary>

`src/fidelite.php`

</details>
<details><summary><code>ceo_fid_ticket</code> — 4 fichier(s)</summary>

`src/fidelite_cartes.php`, `src/fidelite_profils.php`, `tests/fidelite/banc.php`, `tools/sonde_antigaspi_halle.php`

</details>
<details><summary><code>ceo_fid_visite</code> — 1 fichier(s)</summary>

`src/fidelite.php`

</details>
<details><summary><code>ceo_hist_heure</code> — 2 fichier(s)</summary>

`bin/tests_historique.php`, `src/historique_ventes.php`

</details>
<details><summary><code>ceo_hist_jour</code> — 4 fichier(s)</summary>

`bin/tests_assistant_ia.php`, `bin/tests_historique.php`, `src/franchise/assistant_ia.php`, `src/historique_ventes.php`

</details>
<details><summary><code>ceo_hist_produit</code> — 1 fichier(s)</summary>

`src/historique_ventes.php`

</details>
<details><summary><code>ceo_journal_entry</code> — 9 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/suivi_sources.php`, `src/writes.php`, `tests/fidelite/banc.php`, `tests/fidelite/lib.php`

</details>
<details><summary><code>ceo_kpi_def</code> — 7 fichier(s)</summary>

`bin/tests_kpi_cibles.php`, `public/assets/js/app.js`, `src/kpi_table.php`, `src/kpis.php`, `src/leviers.php`, `src/rapports.php`, `src/recurrence_pwa.php`

</details>
<details><summary><code>ceo_kpi_valeur</code> — 2 fichier(s)</summary>

`src/kpi_table.php`, `src/rapports.php`

</details>
<details><summary><code>ceo_magasin_fiche</code> — 1 fichier(s)</summary>

`src/kpi_table.php`

</details>
<details><summary><code>ceo_manuel_formation</code> — 2 fichier(s)</summary>

`src/formation.php`, `src/referentiel.php`

</details>
<details><summary><code>ceo_manuel_procedure</code> — 2 fichier(s)</summary>

`src/formation.php`, `src/referentiel.php`

</details>
<details><summary><code>ceo_matiere_code</code> — 1 fichier(s)</summary>

`src/mesure.php`

</details>
<details><summary><code>ceo_md_form</code> — 1 fichier(s)</summary>

`src/metadonnee.php`

</details>
<details><summary><code>ceo_md_journal</code> — 1 fichier(s)</summary>

`src/metadonnee.php`

</details>
<details><summary><code>ceo_md_lead</code> — 1 fichier(s)</summary>

`src/metadonnee.php`

</details>
<details><summary><code>ceo_md_shop</code> — 3 fichier(s)</summary>

`bin/tests_suivi.php`, `src/metadonnee.php`, `src/suivi.php`

</details>
<details><summary><code>ceo_network_target</code> — 5 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`

</details>
<details><summary><code>ceo_operateur_remarque</code> — 1 fichier(s)</summary>

`src/invendus.php`

</details>
<details><summary><code>ceo_person</code> — 5 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`

</details>
<details><summary><code>ceo_plan_action</code> — 1 fichier(s)</summary>

`src/plan.php`

</details>
<details><summary><code>ceo_plan_engagement</code> — 1 fichier(s)</summary>

`src/plan.php`

</details>
<details><summary><code>ceo_plan_revue</code> — 1 fichier(s)</summary>

`src/plan.php`

</details>
<details><summary><code>ceo_plan_trimestre</code> — 1 fichier(s)</summary>

`src/plan.php`

</details>
<details><summary><code>ceo_plano_photo</code> — 2 fichier(s)</summary>

`src/endpoints.php`, `src/installer.php`

</details>
<details><summary><code>ceo_plano_std_emplacement</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/plano_std.php`

</details>
<details><summary><code>ceo_plano_std_montage</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/plano_std.php`, `src/visites_conformite.php`

</details>
<details><summary><code>ceo_plano_std_photo</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/plano_std.php`, `src/tablette.php`

</details>
<details><summary><code>ceo_plano_std_zone</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/plano_std.php`

</details>
<details><summary><code>ceo_prod_fin</code> — 3 fichier(s)</summary>

`src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_prod_product</code> — 9 fichier(s)</summary>

`bin/tests_assortiment.php`, `sql/schema.sql`, `src/assortiment.php`, `src/endpoints.php`, `src/installer.php`, `src/production_flux.php`, `src/structure_gamme.php`, `src/visites_conformite.php`, `src/writes.php`

</details>
<details><summary><code>ceo_prod_revue</code> — 4 fichier(s)</summary>

`bin/tests_assortiment.php`, `src/analyse_produits.php`, `src/assortiment.php`, `src/installer.php`

</details>
<details><summary><code>ceo_product</code> — 5 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`

</details>
<details><summary><code>ceo_product_month_sales</code> — 6 fichier(s)</summary>

`bin/mix1000.php`, `bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`

</details>
<details><summary><code>ceo_project</code> — 8 fichier(s)</summary>

`bin/seed.php`, `public/api/index.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_project_cost</code> — 6 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_project_crm</code> — 6 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_project_levid</code> — 6 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_project_milestone</code> — 6 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_project_task</code> — 10 fichier(s)</summary>

`bin/db_admin.php`, `bin/deploy.sh`, `bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/dossier.php`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_project_template</code> — 7 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_promo</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/analyse_produits.php`, `src/canaux_offres.php`, `src/jours_creux.php`

</details>
<details><summary><code>ceo_promo_mecanique</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/jours_creux.php`

</details>
<details><summary><code>ceo_prospect</code> — 1 fichier(s)</summary>

`src/prospection.php`

</details>
<details><summary><code>ceo_push_abonnement</code> — 2 fichier(s)</summary>

`bin/push_stock.php`, `src/push.php`

</details>
<details><summary><code>ceo_pwa_client</code> — 1 fichier(s)</summary>

`src/recurrence_pwa.php`

</details>
<details><summary><code>ceo_pwa_jour</code> — 1 fichier(s)</summary>

`src/recurrence_pwa.php`

</details>
<details><summary><code>ceo_pwa_visite</code> — 1 fichier(s)</summary>

`src/recurrence_pwa.php`

</details>
<details><summary><code>ceo_rapport</code> — 13 fichier(s)</summary>

`bin/tests_cq_mail.php`, `bin/tests_documents.php`, `bin/tests_pointages.php`, `bin/tests_rapports_suspension.php`, `public/assets/js/app.js`, `src/cq_mail.php`, `src/documents.php`, `src/dossiers_fichiers.php`, `src/endpoints.php`, `src/pointages_mail.php`, `src/rapports.php`, `src/scoring.php`, `src/suivi.php`

</details>
<details><summary><code>ceo_rapport_run</code> — 7 fichier(s)</summary>

`bin/tests_cq_mail.php`, `bin/tests_pointages.php`, `src/cq_mail.php`, `src/endpoints.php`, `src/pointages_mail.php`, `src/rapports.php`, `src/scoring.php`

</details>
<details><summary><code>ceo_reclamation_photo</code> — 1 fichier(s)</summary>

`src/mesure.php`

</details>
<details><summary><code>ceo_ref_bloc</code> — 1 fichier(s)</summary>

`src/referentiel_guides.php`

</details>
<details><summary><code>ceo_ref_corresp</code> — 2 fichier(s)</summary>

`src/referentiel.php`, `src/referentiel_guides.php`

</details>
<details><summary><code>ceo_ref_domaine</code> — 1 fichier(s)</summary>

`src/referentiel.php`

</details>
<details><summary><code>ceo_ref_formation</code> — 1 fichier(s)</summary>

`src/referentiel.php`

</details>
<details><summary><code>ceo_ref_item</code> — 1 fichier(s)</summary>

`src/referentiel_guides.php`

</details>
<details><summary><code>ceo_ref_po_point</code> — 1 fichier(s)</summary>

`src/referentiel_guides.php`

</details>
<details><summary><code>ceo_ref_po_section</code> — 1 fichier(s)</summary>

`src/referentiel_guides.php`

</details>
<details><summary><code>ceo_ref_souscat</code> — 2 fichier(s)</summary>

`src/checklists_codes.php`, `src/referentiel.php`

</details>
<details><summary><code>ceo_report_schedule</code> — 7 fichier(s)</summary>

`bin/db_admin.php`, `bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_revue_soir</code> — 2 fichier(s)</summary>

`src/revue_soir.php`, `src/visites_outils.php`

</details>
<details><summary><code>ceo_scouting_candidate</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/installer.php`

</details>
<details><summary><code>ceo_scouting_competitor</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/installer.php`

</details>
<details><summary><code>ceo_scouting_etude</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`

</details>
<details><summary><code>ceo_scouting_population</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/installer.php`

</details>
<details><summary><code>ceo_scouting_tile</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `sql/schema.sql`, `src/installer.php`

</details>
<details><summary><code>ceo_shop</code> — 22 fichier(s)</summary>

`bin/deploy.sh`, `bin/seed.php`, `bin/tests.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/brand_guard.php`, `src/endpoints.php`, `src/fbcontrole.php`, `src/formation.php`, `src/franchise/temps_morts.php`, `src/installer.php`, `src/leviers.php`, `src/marketing_sources.php`, `src/mkt_brief.php`, `src/plano_std.php`, `src/resultat.php`, `src/social_publication.php`, `src/tablette.php`, `src/tablette_remarques.php`, `src/ventes_semaines.php`, `src/writes.php`

</details>
<details><summary><code>ceo_shop_budget</code> — 8 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/franchise/tresorerie.php`, `src/plan.php`, `src/writes.php`

</details>
<details><summary><code>ceo_shop_budget_line</code> — 6 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/installer.php`

</details>
<details><summary><code>ceo_shop_budget_month</code> — 5 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/writes.php`

</details>
<details><summary><code>ceo_shop_charge_month</code> — 4 fichier(s)</summary>

`src/budget_crm.php`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_shop_day_note</code> — 2 fichier(s)</summary>

`sql/schema.sql`, `src/notes_jour.php`

</details>
<details><summary><code>ceo_shop_month_note</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_shop_month_perf</code> — 22 fichier(s)</summary>

`bin/deploy.sh`, `bin/seed.php`, `bin/tests_primes.php`, `bin/tests_tresorerie.php`, `bin/tests_visites_kpi.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/api_module.php`, `src/assistant_magasin.php`, `src/dossier.php`, `src/endpoints.php`, `src/franchise/tresorerie.php`, `src/mkt_brief.php`, `src/objectifs_produits.php`, `src/primes.php`, `src/rapports.php`, `src/resultat.php`, `src/tablette_objectifs.php`, `src/visites.php`, `src/visites_kpi.php`, `src/writes.php`

</details>
<details><summary><code>ceo_shop_profil_heure</code> — 1 fichier(s)</summary>

`src/endpoints.php`

</details>
<details><summary><code>ceo_shop_profil_jour</code> — 2 fichier(s)</summary>

`src/endpoints.php`, `src/mesure.php`

</details>
<details><summary><code>ceo_sop</code> — 8 fichier(s)</summary>

`bin/tests_assistant_ia.php`, `bin/tests_manuel_chiffres.php`, `src/checklists_codes.php`, `src/franchise/assistant_ia.php`, `src/manuel_chiffres_cles.php`, `src/referentiel.php`, `src/sop.php`, `src/task_cadre.php`

</details>
<details><summary><code>ceo_sop_personne</code> — 1 fichier(s)</summary>

`src/sop.php`

</details>
<details><summary><code>ceo_sop_suivi</code> — 2 fichier(s)</summary>

`src/sop.php`, `src/tablettes.php`

</details>
<details><summary><code>ceo_sop_tache</code> — 1 fichier(s)</summary>

`src/task_cadre.php`

</details>
<details><summary><code>ceo_sop_version</code> — 6 fichier(s)</summary>

`bin/tests_assistant_ia.php`, `bin/tests_manuel_chiffres.php`, `src/checklists_codes.php`, `src/manuel_chiffres_cles.php`, `src/referentiel.php`, `src/sop.php`

</details>
<details><summary><code>ceo_stock_evenement</code> — 2 fichier(s)</summary>

`src/inventaires.php`, `src/verification_mouvements.php`

</details>
<details><summary><code>ceo_stock_releve</code> — 2 fichier(s)</summary>

`src/inventaires.php`, `src/verification_mouvements.php`

</details>
<details><summary><code>ceo_supplier</code> — 8 fichier(s)</summary>

`bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/audits.php`, `src/ca_mail.php`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>ceo_tablette_acces</code> — 1 fichier(s)</summary>

`src/tablettes.php`

</details>
<details><summary><code>ceo_tablette_essai</code> — 1 fichier(s)</summary>

`src/tablettes.php`

</details>
<details><summary><code>ceo_tablette_gestion_essai</code> — 1 fichier(s)</summary>

`src/tablettes.php`

</details>
<details><summary><code>ceo_tablette_magasin</code> — 1 fichier(s)</summary>

`src/tablettes.php`

</details>
<details><summary><code>ceo_tablette_remarque</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/installer.php`, `src/tablette_remarques.php`

</details>
<details><summary><code>ceo_task_annotation</code> — 3 fichier(s)</summary>

`src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_task_issue</code> — 5 fichier(s)</summary>

`bin/deploy.sh`, `sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_verif_ca</code> — 3 fichier(s)</summary>

`bin/tests_pnl_ht.php`, `src/pnl_ht.php`, `src/verification_ca.php`

</details>
- `ceo_verif_commande` : aucun fichier hors documentation
<details><summary><code>ceo_verif_correction</code> — 2 fichier(s)</summary>

`bin/tests_verification_correction.php`, `src/verification_correction.php`

</details>
- `ceo_verif_geste` : aucun fichier hors documentation
<details><summary><code>ceo_verif_inventaire</code> — 4 fichier(s)</summary>

`bin/tests_verification.php`, `src/verification_base.php`, `src/verification_ca.php`, `src/verification_matieres.php`

</details>
<details><summary><code>ceo_verif_jour</code> — 6 fichier(s)</summary>

`bin/tests_verification.php`, `public/assets/js/verification-evol-tpl.js`, `public/assets/js/verification-evol-vals.js`, `src/verification_base.php`, `src/verification_ca.php`, `src/verification_flux.php`

</details>
- `ceo_verif_matiere` : aucun fichier hors documentation
- `ceo_verif_produit` : aucun fichier hors documentation
<details><summary><code>ceo_verif_recette</code> — 3 fichier(s)</summary>

`bin/tests_verification.php`, `bin/tests_verification_correction.php`, `src/verification.php`

</details>
- `ceo_verif_stock_jour` : aucun fichier hors documentation
<details><summary><code>ceo_x</code> — 1 fichier(s)</summary>

`bin/tests.php`

</details>
<details><summary><code>con_consultant</code> — 8 fichier(s)</summary>

`bin/deploy.sh`, `bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/con_tables.php`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>con_consultant_checklist</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `src/con_tables.php`, `src/franchises.php`

</details>
<details><summary><code>con_consultant_tache</code> — 7 fichier(s)</summary>

`bin/deploy.sh`, `src/con_tables.php`, `src/franchises.php`, `src/helpdesk.php`, `src/suivi.php`, `src/visites.php`, `src/visites_cadre.php`

</details>
<details><summary><code>con_consultant_visit</code> — 7 fichier(s)</summary>

`bin/deploy.sh`, `bin/seed.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/con_tables.php`, `src/endpoints.php`

</details>
<details><summary><code>con_equipe_releve</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `src/con_tables.php`, `src/visites.php`

</details>
<details><summary><code>con_franchise_meteo</code> — 4 fichier(s)</summary>

`bin/deploy.sh`, `src/con_tables.php`, `src/franchises.php`, `src/suivi.php`

</details>
<details><summary><code>con_msp</code> — 4 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests.php`, `src/con_tables.php`, `src/visites.php`

</details>
<details><summary><code>con_rayon_photo</code> — 4 fichier(s)</summary>

`bin/tests_ia_assistant.php`, `src/con_tables.php`, `src/ia_assistant.php`, `src/rayons_photos.php`

</details>
<details><summary><code>con_scoring_google</code> — 4 fichier(s)</summary>

`bin/deploy.sh`, `sql/schema.sql`, `src/con_tables.php`, `src/scoring.php`

</details>
<details><summary><code>con_scoring_msp</code> — 8 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests_fil.php`, `sql/schema.sql`, `src/con_tables.php`, `src/fil_franchise.php`, `src/franchises.php`, `src/scoring.php`, `src/suivi.php`

</details>
<details><summary><code>con_tache_jour</code> — 16 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests.php`, `bin/tests_cq_mail.php`, `bin/tests_suivi.php`, `bin/tests_visites_kpi.php`, `public/dashboard/dashboard.js`, `src/con_tables.php`, `src/cq_mail.php`, `src/dossier.php`, `src/endpoints.php`, `src/franchises.php`, `src/rapports.php`, `src/scoring.php`, `src/taches_suivi.php`, `src/visites_kpi.php`, `src/visites_outils.php`

</details>
<details><summary><code>con_tache_jour_etat</code> — 6 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests_cq_mail.php`, `src/con_tables.php`, `src/cq_mail.php`, `src/rapports.php`, `src/taches_suivi.php`

</details>
<details><summary><code>con_visite</code> — 13 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests.php`, `bin/tests_fil.php`, `public/assets/js/visites.js`, `src/con_tables.php`, `src/fil_franchise.php`, `src/franchises.php`, `src/helpdesk.php`, `src/suivi.php`, `src/visites.php`, `src/visites_cadre.php`, `src/visites_equipe.php`, `src/visites_outils.php`

</details>
<details><summary><code>con_visite_action</code> — 7 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests_fil.php`, `src/con_tables.php`, `src/fil_franchise.php`, `src/franchises.php`, `src/suivi.php`, `src/visites.php`

</details>
<details><summary><code>con_visite_action_evt</code> — 5 fichier(s)</summary>

`bin/deploy.sh`, `src/con_tables.php`, `src/franchises.php`, `src/suivi.php`, `src/visites.php`

</details>
<details><summary><code>con_visite_photo</code> — 7 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests_fil.php`, `bin/tests_suivi.php`, `src/con_tables.php`, `src/fil_franchise.php`, `src/suivi.php`, `src/visites.php`

</details>
<details><summary><code>con_visite_point</code> — 9 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests.php`, `bin/tests_suivi.php`, `src/con_tables.php`, `src/franchises.php`, `src/suivi.php`, `src/visites.php`, `src/visites_cadre.php`, `src/visites_questions.php`

</details>
<details><summary><code>erp_cles</code> — 25 fichier(s)</summary>

`.github/workflows/sonde-assistant-halle.yml`, `bin/deploy.sh`, `bin/historique_moisson.php`, `bin/tests_metadonnee.php`, `config/config.example.php`, `public/api/index.php`, `public/fidelite-app/api.php`, `public/fidelite-test/api.php`, `sql/grants.sql`, `src/anthropic.php`, `src/endpoints.php`, `src/erp_cles.php`, `src/franchise/boite_mail.php`, `src/franchise/catalogues_magasin.php`, `src/franchise/dashboard_marketing.php`, `src/metadonnee.php`, `src/panel_api.php`, `src/smtp.php`, `src/writes.php`, `tools/sonde_antigaspi_halle.php`, `tools/sonde_assistant_halle.php`, `tools/sonde_cartes_fidelite.php`, `tools/sonde_dashboard_halle.php`, `tools/sonde_fidelite_halle.php`, `tools/sonde_profils_halle.php`

</details>
<details><summary><code>hdk_demande</code> — 1 fichier(s)</summary>

`src/helpdesk.php`

</details>
<details><summary><code>hdk_mission</code> — 1 fichier(s)</summary>

`src/helpdesk.php`

</details>
<details><summary><code>hr_dimona_audit</code> — 3 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/AuditLog.php`, `src/hr/Support/Schema.php`

</details>
<details><summary><code>hr_dimona_credentials</code> — 4 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/CredentialRepository.php`, `src/hr/Support/Schema.php`, `tests/hr/run.php`

</details>
<details><summary><code>hr_dimona_declarations</code> — 4 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/DeclarationRepository.php`, `src/hr/Support/Schema.php`, `tests/hr/run.php`

</details>
<details><summary><code>hr_dimona_employer_shops</code> — 3 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/EmployerRepository.php`, `src/hr/Support/Schema.php`

</details>
<details><summary><code>hr_dimona_employers</code> — 4 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/EmployerRepository.php`, `src/hr/Support/Schema.php`, `tests/hr/run.php`

</details>
<details><summary><code>hr_dimona_meta</code> — 3 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Directory/CockpitDirectory.php`, `src/hr/Support/Schema.php`

</details>
<details><summary><code>hr_dimona_periods</code> — 3 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/PeriodRepository.php`, `src/hr/Support/Schema.php`

</details>
<details><summary><code>hr_dimona_tokens</code> — 3 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Dimona/DbTokenCache.php`, `src/hr/Support/Schema.php`

</details>
<details><summary><code>hr_dimona_workers</code> — 3 fichier(s)</summary>

`sql/hr_dimona.sql`, `src/hr/Repository/WorkerRepository.php`, `src/hr/Support/Schema.php`

</details>
<details><summary><code>mar_agency</code> — 7 fichier(s)</summary>

`public/api/marketing/src/Repository/AgencyRepository.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_agences.php`, `src/mkt_brief.php`, `tools/assistant-src/src/views/AgenciesView.tsx`

</details>
<details><summary><code>mar_agency_ask</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_agency_campaign</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/AgencyRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_asset_render</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_avis</code> — 6 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/marketing_hub.php`, `src/marketing_tables.php`, `src/writes.php`

</details>
<details><summary><code>mar_avis_fiche</code> — 11 fichier(s)</summary>

`bin/tests_primes.php`, `sql/schema.sql`, `src/api_module.php`, `src/endpoints.php`, `src/installer.php`, `src/leviers.php`, `src/marketing_hub.php`, `src/marketing_tables.php`, `src/primes.php`, `src/scoring.php`, `src/writes.php`

</details>
<details><summary><code>mar_b2b_option</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_b2b_prospect</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_b2b_prospect_sector</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_b2b_sector</code> — 6 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_bg_controle</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/brand_guard.php`, `src/marketing_hub.php`, `src/marketing_tables.php`

</details>
<details><summary><code>mar_bg_page</code> — 6 fichier(s)</summary>

`sql/schema.sql`, `sql/seed.sql`, `src/brand_guard.php`, `src/franchise/dashboard_marketing.php`, `src/marketing_tables.php`, `src/social_publication.php`

</details>
<details><summary><code>mar_bg_regle</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `sql/seed.sql`, `src/brand_guard.php`, `src/marketing_tables.php`

</details>
<details><summary><code>mar_campagne_annexe</code> — 3 fichier(s)</summary>

`src/franchise/dashboard_marketing.php`, `src/marketing_tables.php`, `src/mkt_annexes.php`

</details>
<details><summary><code>mar_campagne_annexe_type</code> — 2 fichier(s)</summary>

`src/marketing_tables.php`, `src/mkt_annexes.php`

</details>
<details><summary><code>mar_campagne_mesure</code> — 2 fichier(s)</summary>

`src/marketing_tables.php`, `src/mesure.php`

</details>
<details><summary><code>mar_campagne_objectif</code> — 4 fichier(s)</summary>

`src/endpoints.php`, `src/marketing_tables.php`, `src/visites.php`, `src/writes.php`

</details>
<details><summary><code>mar_campagne_objectif_produit</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/marketing_tables.php`, `src/objectifs_produits.php`

</details>
<details><summary><code>mar_campagne_produit</code> — 3 fichier(s)</summary>

`sql/schema.sql`, `src/marketing_tables.php`, `src/objectifs_produits.php`

</details>
<details><summary><code>mar_campagne_releve</code> — 2 fichier(s)</summary>

`src/marketing_tables.php`, `src/mesure.php`

</details>
<details><summary><code>mar_campagne_snapshot</code> — 2 fichier(s)</summary>

`src/marketing_tables.php`, `src/mesure.php`

</details>
<details><summary><code>mar_channel</code> — 6 fichier(s)</summary>

`public/api/marketing/src/Repository/AgencyRepository.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_client_target</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_cost_kind</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_crm_lead</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_crm_lead_event</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_crm_segment</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/NetworkRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_format</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/DiffusionRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_lead_status</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_nl_campagne</code> — 6 fichier(s)</summary>

`bin/tests.php`, `bin/tests_newsletter.php`, `src/marketing_hub.php`, `src/marketing_tables.php`, `src/newsletter.php`, `src/newsletter_envoi.php`

</details>
<details><summary><code>mar_nl_contact</code> — 4 fichier(s)</summary>

`src/marketing_hub.php`, `src/marketing_tables.php`, `src/newsletter.php`, `src/newsletter_envoi.php`

</details>
<details><summary><code>mar_nl_envoi</code> — 2 fichier(s)</summary>

`src/marketing_tables.php`, `src/newsletter_envoi.php`

</details>
<details><summary><code>mar_nl_magasin</code> — 4 fichier(s)</summary>

`bin/tests_newsletter.php`, `src/marketing_tables.php`, `src/newsletter.php`, `src/newsletter_envoi.php`

</details>
<details><summary><code>mar_nl_segment</code> — 5 fichier(s)</summary>

`bin/tests_newsletter.php`, `src/franchise/offres_newsletter.php`, `src/marketing_tables.php`, `src/newsletter.php`, `src/newsletter_envoi.php`

</details>
<details><summary><code>mar_nl_source</code> — 3 fichier(s)</summary>

`bin/tests_newsletter.php`, `src/marketing_tables.php`, `src/newsletter.php`

</details>
<details><summary><code>mar_nl_voucher</code> — 2 fichier(s)</summary>

`src/marketing_tables.php`, `src/newsletter_envoi.php`

</details>
<details><summary><code>mar_offer_item</code> — 12 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/PriceListRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `public/api/marketing/src/Repository/SalesRepository.php`, `sql/mar-referentiels.sql`, `src/mesure.php`, `src/writes.php`, `tools/assistant-src/src/lib/api/module.ts`, `tools/assistant-src/src/views/builder/CampaignBuilder.tsx`

</details>
<details><summary><code>mar_offer_item_season</code> — 5 fichier(s)</summary>

`public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/ErpSyncRepository.php`, `sql/mar-referentiels.sql`, `src/writes.php`, `tools/assistant-src/src/lib/api/module.ts`

</details>
<details><summary><code>mar_offer_template</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_offer_template_item</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_pos_answer_type</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_position</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_promotion_mechanic</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`, `tools/assistant-src/src/views/builder/CampaignBuilder.tsx`

</details>
<details><summary><code>mar_retroplanning_default</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_retroplanning_step</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_review_platform</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_sales_channel</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_shop_revenue</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/FundRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_shop_user</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Support/Scope.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_social_ecart</code> — 7 fichier(s)</summary>

`bin/seed.php`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/installer.php`, `src/marketing_tables.php`, `src/writes.php`

</details>
<details><summary><code>mar_social_post</code> — 13 fichier(s)</summary>

`bin/seed.php`, `bin/tests.php`, `sql/schema.sql`, `sql/seed.sql`, `src/brand_guard.php`, `src/endpoints.php`, `src/fbcontrole.php`, `src/franchise/dashboard_marketing.php`, `src/installer.php`, `src/marketing_hub.php`, `src/marketing_tables.php`, `src/social_publication.php`, `src/writes.php`

</details>
<details><summary><code>mar_social_publication</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/marketing_tables.php`, `src/social_publication.php`, `src/writes.php`

</details>
<details><summary><code>mar_tone</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_uniform</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_voucher</code> — 5 fichier(s)</summary>

`bin/tests.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `sql/mar-referentiels.sql`, `tools/assistant-src/src/views/VouchersView.tsx`

</details>
<details><summary><code>mot_borne</code> — 3 fichier(s)</summary>

`bin/tests_pointage_app.php`, `src/api_module.php`, `src/pointage.php`

</details>
<details><summary><code>mot_cache</code> — 5 fichier(s)</summary>

`bin/tests_grands_prix.php`, `bin/tests_primes.php`, `src/api_module.php`, `src/motivation.php`, `src/planning_moi.php`

</details>
<details><summary><code>mot_grand_prix</code> — 4 fichier(s)</summary>

`bin/tests_grands_prix.php`, `src/api_module.php`, `src/grands_prix.php`, `src/motivation.php`

</details>
<details><summary><code>mot_pointage</code> — 3 fichier(s)</summary>

`bin/tests_pointage_app.php`, `src/api_module.php`, `src/pointage.php`

</details>
<details><summary><code>mot_profil</code> — 4 fichier(s)</summary>

`bin/tests_profil.php`, `src/api_module.php`, `src/primes_fiches.php`, `src/profil_employe.php`

</details>
<details><summary><code>mot_reglage</code> — 5 fichier(s)</summary>

`bin/tests_primes.php`, `src/api_module.php`, `src/grands_prix.php`, `src/motivation.php`, `src/primes_app.php`

</details>
<details><summary><code>pil_auto_reglage</code> — 1 fichier(s)</summary>

`src/franchise/automatisations.php`

</details>
<details><summary><code>pil_boite_auto</code> — 2 fichier(s)</summary>

`src/franchise/automatisations.php`, `src/franchise/boite_mail.php`

</details>
<details><summary><code>pil_boite_etat</code> — 1 fichier(s)</summary>

`src/franchise/boite_mail.php`

</details>
<details><summary><code>pil_boite_journal</code> — 2 fichier(s)</summary>

`src/franchise/automatisations.php`, `src/franchise/boite_mail.php`

</details>
<details><summary><code>pil_boite_modele</code> — 1 fichier(s)</summary>

`src/franchise/boite_mail.php`

</details>
<details><summary><code>pil_cq_analyse</code> — 1 fichier(s)</summary>

`src/ia_assistant.php`

</details>
<details><summary><code>pil_cq_exemple</code> — 2 fichier(s)</summary>

`bin/tests_ia_assistant.php`, `src/ia_assistant.php`

</details>
<details><summary><code>pil_cq_ia</code> — 2 fichier(s)</summary>

`bin/tests_ia_assistant.php`, `src/ia_assistant.php`

</details>
<details><summary><code>pil_equipe_mail</code> — 2 fichier(s)</summary>

`src/franchise/automatisations.php`, `src/franchise/bundles.php`

</details>
<details><summary><code>pil_fil</code> — 2 fichier(s)</summary>

`bin/tests_fil.php`, `src/fil_franchise.php`

</details>
<details><summary><code>pil_fil_entree</code> — 2 fichier(s)</summary>

`bin/tests_fil.php`, `src/fil_franchise.php`

</details>
<details><summary><code>pil_fil_piece</code> — 2 fichier(s)</summary>

`bin/tests_fil.php`, `src/fil_franchise.php`

</details>
<details><summary><code>pil_frais_tva</code> — 2 fichier(s)</summary>

`src/franchise/frais.php`, `src/pnl_ht.php`

</details>
<details><summary><code>pil_tresorerie_plan</code> — 3 fichier(s)</summary>

`bin/tests_tresorerie.php`, `src/franchise/tresorerie.php`, `src/visites_outils.php`

</details>
<details><summary><code>pla_contenant</code> — 3 fichier(s)</summary>

`src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_format</code> — 3 fichier(s)</summary>

`src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_meuble</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_montage</code> — 1 fichier(s)</summary>

`src/writes.php`

</details>
<details><summary><code>pla_niveau</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_note</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_placement</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_slot</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>pla_zone</code> — 4 fichier(s)</summary>

`sql/schema.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>ceo_assistant_question</code> — 2 fichier(s)</summary>

`src/assistant_magasin.php`, `src/franchise/assistant_ia.php`

</details>
<details><summary><code>ceo_parcours</code> — 1 fichier(s)</summary>

`src/formation_postes.php`

</details>
<details><summary><code>ceo_parcours_etape</code> — 1 fichier(s)</summary>

`src/formation_postes.php`

</details>
<details><summary><code>ceo_prod_planogram</code> — 1 fichier(s)</summary>

`src/installer.php`

</details>
<details><summary><code>b2b_client</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/ErpSyncRepository.php`

</details>
<details><summary><code>client</code> — 6 fichier(s)</summary>

`bin/tests_evolution_pro.php`, `src/grands_prix.php`, `src/primes_b2b.php`, `src/ventes.php`, `src/ventes_pro.php`, `src/ventes_semaines.php`

</details>
<details><summary><code>client_order</code> — 3 fichier(s)</summary>

`src/commandes.php`, `src/ventes.php`, `src/visites_outils.php`

</details>
<details><summary><code>client_order_product</code> — 1 fichier(s)</summary>

`src/commandes.php`

</details>
<details><summary><code>company</code> — 1 fichier(s)</summary>

`src/ventes.php`

</details>
<details><summary><code>crm_mo_acces</code> — 1 fichier(s)</summary>

`src/tablettes.php`

</details>
<details><summary><code>crm_mo_chapitres</code> — 1 fichier(s)</summary>

`src/referentiel.php`

</details>
<details><summary><code>crm_mo_magasins</code> — 1 fichier(s)</summary>

`src/tablettes.php`

</details>
<details><summary><code>crm_mo_procedures</code> — 1 fichier(s)</summary>

`src/referentiel.php`

</details>
<details><summary><code>flattened_recipe_ingredient</code> — 2 fichier(s)</summary>

`src/inventaires.php`, `src/verification_mouvements.php`

</details>
<details><summary><code>franchisee_employee</code> — 6 fichier(s)</summary>

`bin/tests_employes_relus.php`, `bin/tests_primes.php`, `src/hr/Directory/CockpitDirectory.php`, `src/planning_sync.php`, `src/primes_app.php`, `src/ventes.php`

</details>
<details><summary><code>franchisee_employee_schedule</code> — 8 fichier(s)</summary>

`bin/tests_planning_moi.php`, `src/api_module.php`, `src/endpoints.php`, `src/planning_moi.php`, `src/planning_sync.php`, `src/primes.php`, `src/ventes.php`, `src/visites_equipe.php`

</details>
<details><summary><code>kpi</code> — 20 fichier(s)</summary>

`bin/seed.php`, `bin/tests_kpi_cibles.php`, `bin/tests_visites_kpi.php`, `data/formation/dashboard/art-pil-01.json`, `public/assets/js/app.js`, `public/assets/js/cartes-magasin.js`, `public/assets/js/templates.js`, `public/assets/js/visites-nl.js`, `public/assets/js/visites.js`, `sql/schema.sql`, `sql/seed.sql`, `src/cibles_reseau.php`, `src/endpoints.php`, `src/kpi_table.php`, `src/kpis.php`, `src/leviers.php`, `src/rapports.php`, `src/recurrence_pwa.php`, `src/visites_kpi.php`, `src/writes.php`

</details>
<details><summary><code>mac_consultant_param</code> — 1 fichier(s)</summary>

`bin/deploy.sh`

</details>
<details><summary><code>mac_consultant_visit</code> — 1 fichier(s)</summary>

`src/endpoints.php`

</details>
<details><summary><code>mac_google_rating_snapshot</code> — 2 fichier(s)</summary>

`src/leviers.php`, `src/suivi_sources.php`

</details>
<details><summary><code>mac_kpi_threshold</code> — 1 fichier(s)</summary>

`bin/deploy.sh`

</details>
<details><summary><code>mac_report_share</code> — 7 fichier(s)</summary>

`bin/deploy.sh`, `bin/seed.php`, `config/config.example.php`, `sql/grants.sql`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`

</details>
<details><summary><code>mac_shop_monthly_pnl</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `src/endpoints.php`, `src/ventes.php`

</details>
<details><summary><code>mac_task_review</code> — 11 fichier(s)</summary>

`bin/tests_visites_kpi.php`, `public/assets/js/templates.js`, `src/commandes.php`, `src/endpoints.php`, `src/franchises.php`, `src/panel_api.php`, `src/suivi_sources.php`, `src/task_cadre.php`, `src/visites_kpi.php`, `src/visites_outils.php`, `src/writes.php`

</details>
<details><summary><code>mar_brand</code> — 10 fichier(s)</summary>

`public/api/marketing/src/Controller/LeadController.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `public/assets/js/templates.js`, `sql/mar-referentiels.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>mar_bundle</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/CatalogRepository.php`, `tools/assistant-src/src/views/BundlesView.tsx`

</details>
<details><summary><code>mar_bundle_item</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/CatalogRepository.php`

</details>
<details><summary><code>mar_campaign</code> — 26 fichier(s)</summary>

`bin/tests.php`, `public/api/marketing/routes.php`, `public/api/marketing/src/Repository/AgencyRepository.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CampaignTypeRepository.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `public/api/marketing/src/Repository/FundRepository.php`, `public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `public/assets/js/templates.js`, `sql/mar-referentiels.sql`, `sql/schema.sql`, `src/endpoints.php`, `src/marketing_hub.php`, `src/marketing_tables.php`, `src/mesure.php`, `src/mkt_annexes.php`, `src/mkt_brief.php`, `src/objectifs_produits.php`, `src/suivi_sources.php`, `src/visites.php`, `src/writes.php`, `tools/assistant-src/src/lib/api/module.ts`

</details>
<details><summary><code>mar_campaign_agency_ask</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`

</details>
<details><summary><code>mar_campaign_asset</code> — 6 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_campaign_b2b_option</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`

</details>
<details><summary><code>mar_campaign_b2b_sector</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`

</details>
<details><summary><code>mar_campaign_challenge_prize</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`

</details>
<details><summary><code>mar_campaign_channel</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `src/mkt_agences.php`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_campaign_kpi_snapshot</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `sql/mar-referentiels.sql`

</details>
<details><summary><code>mar_campaign_lever_target</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `sql/mar-referentiels.sql`, `src/mkt_brief.php`

</details>
<details><summary><code>mar_campaign_offer</code> — 5 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `src/mesure.php`

</details>
<details><summary><code>mar_campaign_offer_item</code> — 8 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `sql/mar-referentiels.sql`, `src/mesure.php`, `src/writes.php`

</details>
<details><summary><code>mar_campaign_pos_question</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`

</details>
<details><summary><code>mar_campaign_shop</code> — 13 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `public/api/marketing/src/Support/Scope.php`, `sql/mar-referentiels.sql`, `src/endpoints.php`, `src/marketing_hub.php`, `src/mesure.php`, `src/mkt_brief.php`, `src/objectifs_produits.php`, `src/visites.php`, `src/writes.php`

</details>
<details><summary><code>mar_campaign_shop_item_target</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `src/writes.php`

</details>
<details><summary><code>mar_campaign_status</code> — 10 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `src/endpoints.php`, `src/marketing_hub.php`, `src/mesure.php`, `src/mkt_brief.php`, `src/objectifs_produits.php`, `src/writes.php`

</details>
<details><summary><code>mar_campaign_type</code> — 13 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CampaignTypeRepository.php`, `public/api/marketing/src/Repository/NetworkRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `src/endpoints.php`, `src/marketing_hub.php`, `src/mesure.php`, `src/mkt_brief.php`, `src/mkt_types.php`, `src/visites.php`, `src/writes.php`

</details>
<details><summary><code>mar_campaign_uniform</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/DiffusionRepository.php`

</details>
<details><summary><code>mar_crm_customer</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/NetworkRepository.php`

</details>
<details><summary><code>mar_crm_customer_segment</code> — 2 fichier(s)</summary>

`public/api/marketing/src/Repository/NetworkRepository.php`, `tools/assistant-src/src/views/CrmView.tsx`

</details>
<details><summary><code>mar_fund_movement</code> — 5 fichier(s)</summary>

`public/api/marketing/src/Repository/FundRepository.php`, `sql/mar-referentiels.sql`, `src/endpoints.php`, `src/installer.php`, `src/writes.php`

</details>
<details><summary><code>mar_fund_recurrence</code> — 3 fichier(s)</summary>

`public/api/marketing/src/Repository/FundRepository.php`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>mar_fund_source</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/ReferenceRepository.php`

</details>
<details><summary><code>mar_kit</code> — 2 fichier(s)</summary>

`bin/tests.php`, `public/api/marketing/src/Repository/NetworkRepository.php`

</details>
<details><summary><code>mar_kit_activation</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/NetworkRepository.php`

</details>
<details><summary><code>mar_kit_asset</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/NetworkRepository.php`

</details>
<details><summary><code>mar_lever</code> — 16 fichier(s)</summary>

`public/api/marketing/src/Repository/AgencyRepository.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/CampaignTypeRepository.php`, `public/api/marketing/src/Repository/FundRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `public/assets/js/app.js`, `public/assets/js/templates.js`, `public/hr/ds.css`, `sql/mar-referentiels.sql`, `src/endpoints.php`, `src/marketing_tables.php`, `src/mesure.php`, `src/mkt_brief.php`, `src/mkt_types.php`, `tools/assistant-src/src/styles/global.css`

</details>
<details><summary><code>mar_promotion</code> — 4 fichier(s)</summary>

`bin/tests.php`, `public/api/marketing/src/Repository/CatalogRepository.php`, `tools/assistant-src/src/lib/api/module.ts`, `tools/assistant-src/src/views/PromotionsView.tsx`

</details>
<details><summary><code>mar_review</code> — 2 fichier(s)</summary>

`bin/tests.php`, `public/api/marketing/src/Repository/NetworkRepository.php`

</details>
<details><summary><code>mar_roi_cost</code> — 4 fichier(s)</summary>

`public/api/marketing/src/Repository/AgencyRepository.php`, `public/api/marketing/src/Repository/FundRepository.php`, `sql/mar-referentiels.sql`, `tools/assistant-src/src/views/RoiView.tsx`

</details>
<details><summary><code>mar_shop</code> — 16 fichier(s)</summary>

`bin/tests.php`, `public/api/marketing/src/Repository/CampaignRepository.php`, `public/api/marketing/src/Repository/ErpSyncRepository.php`, `public/api/marketing/src/Repository/FundRepository.php`, `public/api/marketing/src/Repository/LeadRepository.php`, `public/api/marketing/src/Repository/NetworkRepository.php`, `public/api/marketing/src/Repository/PriceListRepository.php`, `public/api/marketing/src/Repository/PrintRepository.php`, `public/api/marketing/src/Repository/ProspectRepository.php`, `public/api/marketing/src/Repository/PublicCampaignRepository.php`, `public/api/marketing/src/Repository/ReferenceRepository.php`, `public/api/marketing/src/Repository/SalesRepository.php`, `public/assets/js/templates.js`, `sql/mar-referentiels.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>mar_shop_presence</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/NetworkRepository.php`

</details>
<details><summary><code>mar_voucher_channel</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/CatalogRepository.php`

</details>
<details><summary><code>mar_voucher_redemption</code> — 1 fichier(s)</summary>

`public/api/marketing/src/Repository/CatalogRepository.php`

</details>
<details><summary><code>material</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>material_category</code> — 2 fichier(s)</summary>

`src/inventaires.php`, `src/recette_produit.php`

</details>
<details><summary><code>material_inventory</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>material_inventory_log</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>material_movement_reason</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>material_order</code> — 3 fichier(s)</summary>

`src/commandes.php`, `src/inventaires.php`, `src/visites_outils.php`

</details>
<details><summary><code>material_order_item</code> — 1 fichier(s)</summary>

`src/commandes.php`

</details>
<details><summary><code>material_order_item_delivery_log</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>of_tag</code> — 4 fichier(s)</summary>

`bin/seed.php`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`

</details>
<details><summary><code>position</code> — 5 fichier(s)</summary>

`bin/seed.php`, `sql/schema.sql`, `sql/seed.sql`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>product</code> — 14 fichier(s)</summary>

`bin/deploy.sh`, `bin/tests_assortiment.php`, `src/analyse_produits.php`, `src/assortiment.php`, `src/endpoints.php`, `src/inventaires.php`, `src/jours_creux.php`, `src/objectifs_produits.php`, `src/prod_utilisation.php`, `src/recette_produit.php`, `src/stats_ventes.php`, `src/tablette.php`, `src/verification_mouvements.php`, `src/writes.php`

</details>
<details><summary><code>product_availability_period</code> — 3 fichier(s)</summary>

`src/assortiment.php`, `src/endpoints.php`, `src/prod_utilisation.php`

</details>
<details><summary><code>product_availability_period_connection</code> — 3 fichier(s)</summary>

`src/assortiment.php`, `src/endpoints.php`, `src/prod_utilisation.php`

</details>
<details><summary><code>product_category</code> — 3 fichier(s)</summary>

`src/assortiment.php`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>product_category_group</code> — 1 fichier(s)</summary>

`src/endpoints.php`

</details>
<details><summary><code>product_category_group_connection</code> — 1 fichier(s)</summary>

`src/endpoints.php`

</details>
<details><summary><code>product_movement</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `src/invendus.php`, `src/inventaires.php`

</details>
<details><summary><code>product_portion</code> — 1 fichier(s)</summary>

`src/promos_magasin.php`

</details>
<details><summary><code>product_recipe</code> — 3 fichier(s)</summary>

`src/endpoints.php`, `src/inventaires.php`, `src/verification_mouvements.php`

</details>
<details><summary><code>product_recipe_material_connection</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>recipe_cost</code> — 2 fichier(s)</summary>

`bin/deploy.sh`, `src/endpoints.php`

</details>
<details><summary><code>revenue_budget</code> — 1 fichier(s)</summary>

`src/writes.php`

</details>
<details><summary><code>shop_material_price_list</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>shop_product</code> — 2 fichier(s)</summary>

`bin/deploy.sh`, `src/endpoints.php`

</details>
<details><summary><code>shops</code> — 70 fichier(s)</summary>

`bin/console_bo.php`, `bin/deploy.sh`, `bin/push_stock.php`, `bin/tests_ca_net.php`, `bin/tests_cq_mail.php`, `bin/tests_documents.php`, `bin/tests_employes_relus.php`, `bin/tests_evolution_pro.php`, `bin/tests_historique.php`, `bin/tests_planning_moi.php`, `bin/tests_pointage_app.php`, `bin/tests_primes.php`, `bin/tests_promos.php`, `bin/tests_promos_reseau.php`, `bin/tests_verification.php`, `bin/tests_verification_correction.php`, `src/analyse_produits.php`, `src/api_module.php`, `src/assistant_magasin.php`, `src/ca_mail.php`, `src/ca_net_mois.php`, `src/caisse.php`, `src/cq_mail.php`, `src/croisements.php`, `src/documents.php`, `src/dossier.php`, `src/endpoints.php`, `src/franchise/bundles.php`, `src/franchise/catalogues_magasin.php`, `src/franchise/dashboard_analyse.php`, `src/franchise/temps_morts.php`, `src/historique_ventes.php`, `src/hr/Directory/CockpitDirectory.php`, `src/inventaires.php`, `src/jours_creux.php`, `src/kpi_table.php`, `src/matiere_fiche.php`, `src/mesure.php`, `src/mkt_brief.php`, `src/objectifs_produits.php`, `src/panel_ventes.php`, `src/planning_moi.php`, `src/planning_sync.php`, `src/plano_std.php`, `src/pnl_mois.php`, `src/pointage.php`, `src/ponderation.php`, `src/primes.php`, `src/primes_app.php`, `src/prod_manque.php`, `src/prod_utilisation.php`, `src/produits_coef.php`, `src/promos_magasin.php`, `src/promos_reseau.php`, `src/rapports.php`, `src/recurrence_pwa.php`, `src/resultat.php`, `src/scoring.php`, `src/stats_ventes.php`, `src/tablette.php`, `src/tablette_objectifs.php`, `src/tablette_remarques.php`, `src/taches_suivi.php`, `src/task_cadre.php`, `src/ventes.php`, `src/ventes_pro.php`, `src/ventes_semaines.php`, `src/verification.php`, `src/writes.php`, `tests/hr/console_bo_test.php`

</details>
<details><summary><code>sig_product_categories</code> — 1 fichier(s)</summary>

`bin/deploy.sh`

</details>
<details><summary><code>sig_products</code> — 2 fichier(s)</summary>

`bin/deploy.sh`, `src/endpoints.php`

</details>
<details><summary><code>stocktaking</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>stocktaking_item</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>stocktaking_summary</code> — 1 fichier(s)</summary>

`src/inventaires.php`

</details>
<details><summary><code>todo_task</code> — 1 fichier(s)</summary>

`src/endpoints.php`

</details>
<details><summary><code>transaction</code> — 14 fichier(s)</summary>

`bin/deploy.sh`, `src/analyse_produits.php`, `src/commandes.php`, `src/croisements.php`, `src/endpoints.php`, `src/grands_prix.php`, `src/inventaires.php`, `src/mesure.php`, `src/primes_b2b.php`, `src/prod_manque.php`, `src/prod_utilisation.php`, `src/ventes.php`, `src/ventes_semaines.php`, `src/visites_conformite.php`

</details>
<details><summary><code>transaction_product</code> — 8 fichier(s)</summary>

`bin/deploy.sh`, `src/croisements.php`, `src/endpoints.php`, `src/inventaires.php`, `src/prod_manque.php`, `src/prod_utilisation.php`, `src/ventes.php`, `src/visites_conformite.php`

</details>
<details><summary><code>user_membership</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>user_profile</code> — 3 fichier(s)</summary>

`bin/deploy.sh`, `src/endpoints.php`, `src/writes.php`

</details>
<details><summary><code>voucher_campaign</code> — 1 fichier(s)</summary>

`tools/sonde_vouchers.php`

</details>
<details><summary><code>voucher_campaign_channel</code> — 1 fichier(s)</summary>

`tools/sonde_vouchers.php`

</details>
<details><summary><code>voucher_code</code> — 1 fichier(s)</summary>

`tools/sonde_vouchers.php`

</details>

## 4. Clés étrangères, vues, déclencheurs, fonctions

Déclarées dans le code (à confirmer en base à l’étape 0) :

| Table | → Référence | Déclarée dans |
|---|---|---|
| `bo_user_shops` | `bo_users` | `tests/hr/console_bo_test.php` |
| `bo_user_shops` | `shops` | `tests/hr/console_bo_test.php` |
| `ceo_product_month_sales` | `ceo_product` | `docs/contrat-api.md` |
| `ceo_product_month_sales` | `ceo_product` | `sql/schema.sql` |
| `ceo_project_task` | `ceo_shop` | `docs/contrat-api.md` |
| `ceo_project_task` | `ceo_shop` | `sql/schema.sql` |
| `ceo_shop_budget` | `ceo_shop` | `docs/contrat-api.md` |
| `ceo_shop_budget` | `ceo_shop` | `sql/schema.sql` |
| `ceo_shop_month_perf` | `ceo_shop` | `docs/contrat-api.md` |
| `ceo_shop_month_perf` | `ceo_shop` | `sql/schema.sql` |
| `ceo_task_issue` | `ceo_project_task` | `sql/schema.sql` |
| `mar_agency` | `mar_lever` | `sql/mar-referentiels.sql` |
| `mar_agency_campaign` | `mar_agency` | `sql/mar-referentiels.sql` |
| `mar_agency_campaign` | `mar_campaign` | `sql/mar-referentiels.sql` |
| `mar_agency_campaign` | `mar_channel` | `sql/mar-referentiels.sql` |
| `mar_asset_render` | `mar_campaign_asset` | `sql/mar-referentiels.sql` |
| `mar_asset_render` | `mar_format` | `sql/mar-referentiels.sql` |
| `mar_avis` | `ceo_shop` | `sql/schema.sql` |
| `mar_avis_fiche` | `ceo_shop` | `sql/schema.sql` |
| `mar_b2b_prospect` | `mar_b2b_sector` | `sql/mar-referentiels.sql` |
| `mar_b2b_prospect` | `mar_brand` | `sql/mar-referentiels.sql` |
| `mar_b2b_prospect` | `mar_shop` | `sql/mar-referentiels.sql` |
| `mar_b2b_prospect_sector` | `mar_b2b_prospect` | `sql/mar-referentiels.sql` |
| `mar_b2b_prospect_sector` | `mar_b2b_sector` | `sql/mar-referentiels.sql` |
| `mar_crm_lead` | `mar_b2b_prospect` | `sql/mar-referentiels.sql` |
| `mar_crm_lead` | `mar_b2b_sector` | `sql/mar-referentiels.sql` |
| `mar_crm_lead` | `mar_campaign` | `sql/mar-referentiels.sql` |
| `mar_crm_lead` | `mar_lead_status` | `sql/mar-referentiels.sql` |
| `mar_crm_lead` | `mar_shop` | `sql/mar-referentiels.sql` |
| `mar_crm_lead_event` | `mar_crm_lead` | `sql/mar-referentiels.sql` |
| `mar_offer_item_season` | `mar_offer_item` | `sql/mar-referentiels.sql` |
| `mar_offer_template_item` | `mar_offer_item` | `sql/mar-referentiels.sql` |
| `mar_offer_template_item` | `mar_offer_template` | `sql/mar-referentiels.sql` |
| `mar_retroplanning_default` | `mar_position` | `sql/mar-referentiels.sql` |
| `mar_retroplanning_step` | `mar_campaign` | `sql/mar-referentiels.sql` |
| `mar_retroplanning_step` | `mar_position` | `sql/mar-referentiels.sql` |
| `mar_shop_revenue` | `mar_shop` | `sql/mar-referentiels.sql` |
| `mar_shop_user` | `mar_shop` | `sql/mar-referentiels.sql` |
| `mar_social_ecart` | `mar_social_post` | `docs/contrat-api.md` |
| `mar_social_ecart` | `mar_social_post` | `sql/schema.sql` |
| `mar_social_post` | `ceo_shop` | `docs/contrat-api.md` |
| `mar_social_post` | `ceo_shop` | `sql/schema.sql` |
| `mar_uniform` | `mar_campaign` | `sql/mar-referentiels.sql` |
| `mar_voucher` | `mar_campaign` | `sql/mar-referentiels.sql` |

Vues : `mar_v_fund_ledger_by_period` (`sql/mar-referentiels.sql`), `mar_v_lever_performance` (`sql/mar-referentiels.sql`), `mar_v_campaign_monitor` (`sql/mar-referentiels.sql`), `mar_v_lead_funnel` (`sql/mar-referentiels.sql`), `mar_v_lead_funnel_by_shop` (`sql/mar-referentiels.sql`).

Déclencheurs, fonctions, procédures, événements : aucun dans le code du dépôt (hors copie de l’ERP).

## 5. Fichiers morts (candidats)

Nom de fichier cité nulle part ailleurs dans le code (ni `require`, ni script, ni workflow, ni page). À confirmer avant toute suppression : rien n’est supprimé avant la phase 4.

- `pilotage-reseau/bin/db_admin.php`
- `pilotage-reseau/bin/mix1000.php`

