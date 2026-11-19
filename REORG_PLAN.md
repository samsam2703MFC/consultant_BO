# REORG_PLAN — Réorganisation du bac à sable en quatre domaines

**Phase 2 : plan. Rien n'a été exécuté.** J'attends « GO » avant toute modification de code ou de base.

> **Statut (19/11/2026, soir)** : « GO » reçu (A1 : oui ; A3 : purge limitée à nos tables). Phases 1 à 3 faites sur la
> branche `reorg/structure-domaines`, rien livré sur le serveur, rien supprimé : voir `REORG_REPORT.md`.

| Domaine | Dossier | Préfixe des tables |
|---|---|---|
| Marketing | `marketing/` | `mar_` |
| Formation | `formation/` | `for_` |
| Pilotage (consultant) | `consultant/` | `con_` |
| Dashboard / day panel franchisé | `panel/` | `panel_` |
| Partagé | `shared/` | noms actuels conservés |

Pièces jointes, toutes générées par `python3 -I reorg/audit_statique.py` (lecture seule) :

- `REORG_AUDIT.md` : l'audit de la phase 1 (arborescence par domaine, inventaire des tables, où chaque table est citée,
  clés étrangères, vues, fichiers morts) ;
- `reorg/tables.tsv` : une ligne par table (domaine, nouveau nom, FK, nombre de fichiers par nature, **liste de tous les
  fichiers qui la citent**) ;
- `reorg/fichiers.tsv` : **les 6 994 fichiers du dépôt**, chacun avec son domaine et son nouveau chemin ;
- `reorg/cles.tsv` : les noms de clés, réglages secrets, variables d'environnement et URL d'intégration, par fichier
  (aucune valeur).

---

## 0. À lire d'abord : cinq constats qui changent le plan

1. **Aucun accès à la base depuis cet environnement.** Le conteneur n'a ni client MySQL ni identifiants, et la base
   du bac à sable (`atelierby_db`, serveur de test 185.180.206.46) n'est joignable que depuis ce serveur. Les
   **nombres de lignes**, les tables **vides**, les FK, vues, déclencheurs et fonctions **réellement présents** sont
   donc « à mesurer ». Je propose de les relever à l'étape 0 de la phase 3 (ligne de base en lecture seule), avant la
   moindre modification, puis de mettre à jour ce plan avec les vrais chiffres (question B1).

2. **Le dépôt a déjà une réorganisation documentée, différente de celle-ci.** `CLAUDE.md`, `README.md` et
   `docs/PORTAILS.md` (décisions des 6 et 7 nov. 2026) prévoient quatre portails **`rec_` / `mar_` / `pil_` / `frm_`**
   dans les dossiers `recrutement-developpement/`, `marketing/`, `pilotage-reseau/`, `formations/`, et une table des
   clés **`erp_cles`**. La mission remplace `pil_` par `con_` + `panel_`, `frm_` par `for_`, et **retire le
   Recrutement** des quatre domaines (question A1). Le code a d'ailleurs déjà commencé à créer des tables `pil_`
   (`pil_fil`, `pil_boite_*`, `pil_tresorerie_plan`…) : elles sont reprises ci-dessous.

3. **`erp_keys` n'existe pas : la table s'appelle `erp_cles`.** Toutes les mentions d'« erp keys » dans l'historique
   (« récupère-la dans la table erp keys », 12/11) désignent `erp_cles`. Je la traite comme la `erp_keys` de la
   mission : pas de préfixe, pas de renommage, jamais supprimée (question A2).

4. **La base n'est pas à nous seuls.** `atelierby_db` est la base du panel consultant (`pwa_consultant`) : le cockpit
   y lit **en SQL direct** les tables du panel TF Buddy (`transaction`, `shops`, `product`, `client`… 45 tables), de
   la PWA consultant (`mac_*`), du CRM de recrutement (`crm_*`, « même base en production ») et du signage (`sig_*`).
   La copie de l'ERP (`apps/production`) a sa **propre base, `erp`**. La phase 4 telle qu'écrite (« toutes les autres
   tables sont effacées ») **effacerait les données de ces applications** et casserait les quatre domaines eux-mêmes,
   qui lisent ces tables. Je recommande de limiter la purge aux tables **créées par ce dépôt** et non conservées
   (question A3).

5. **Le contrôle bloquant de la phase 4 échouerait tel quel** pour `ceo_scouting_*` (citées par `endpoints.php`,
   `installer.php`, `sql/schema.sql`, `bin/deploy.sh`, qui vont dans `shared/`) et pour les anciens noms gardés dans
   les registres de migration. Le code qui les cite doit être retiré **avant** le contrôle, pas après (§9, étape 7).

---

## 1. Correspondance des tables : ancien nom → nouveau nom → domaine

250 tables créées par ce dépôt, plus 94 tables d'autres applications que le code lit. **144
renommages** : Formation 19, Marketing 12, Dashboard franchisé 29, Pilotage
consultant 84. Règle appliquée : `{préfixe}_{entité}`, l'ancien préfixe remplacé, le reste du nom inchangé
(qui connaît `ceo_sop_suivi` retrouve `for_sop_suivi`). Aucune collision : aucun nouveau nom n'est déjà pris dans
l'inventaire (à revérifier en base à l'étape 0, en particulier qu'aucune table `panel_*` ou `for_*` n'existe déjà).

Le nombre de lignes de chaque table sera relevé à l'étape 0 ; la colonne « fichiers » compte les fichiers de code,
écrans, scripts, tests et SQL qui citent la table (la liste est dans `reorg/tables.tsv`).


#### Formation — 19 renommage(s)

| Ancien nom | Nouveau nom | Domaine | Fichiers de code à mettre à jour | Note |
|---|---|---|---:|---|
| `ceo_manuel_formation` | `for_manuel_formation` | Formation | 2 | Formation |
| `ceo_manuel_procedure` | `for_manuel_procedure` | Formation | 2 | Formation |
| `ceo_ref_bloc` | `for_ref_bloc` | Formation | 1 | Formation |
| `ceo_ref_corresp` | `for_ref_corresp` | Formation | 2 | Formation |
| `ceo_ref_domaine` | `for_ref_domaine` | Formation | 1 | Formation |
| `ceo_ref_formation` | `for_ref_formation` | Formation | 1 | Formation |
| `ceo_ref_item` | `for_ref_item` | Formation | 1 | Formation |
| `ceo_ref_po_point` | `for_ref_po_point` | Formation | 1 | Formation |
| `ceo_ref_po_section` | `for_ref_po_section` | Formation | 1 | Formation |
| `ceo_ref_souscat` | `for_ref_souscat` | Formation | 2 | Formation |
| `ceo_sop` | `for_sop` | Formation | 8 | Formation |
| `ceo_sop_personne` | `for_sop_personne` | Formation | 1 | Formation |
| `ceo_sop_suivi` | `for_sop_suivi` | Formation | 2 | Formation |
| `ceo_sop_tache` | `for_sop_tache` | Formation | 1 | ? écrite par le cadre des tâches (consultant) |
| `ceo_sop_version` | `for_sop_version` | Formation | 6 | Formation |
| `ceo_tablette_acces` | `for_tablette_acces` | Formation | 1 | Manuel du réseau / tablettes des magasins |
| `ceo_tablette_essai` | `for_tablette_essai` | Formation | 1 | Manuel du réseau / tablettes des magasins |
| `ceo_tablette_gestion_essai` | `for_tablette_gestion_essai` | Formation | 1 | Manuel du réseau / tablettes des magasins |
| `ceo_tablette_magasin` | `for_tablette_magasin` | Formation | 1 | Manuel du réseau / tablettes des magasins |

#### Marketing — 12 renommage(s)

| Ancien nom | Nouveau nom | Domaine | Fichiers de code à mettre à jour | Note |
|---|---|---|---:|---|
| `ceo_fid_jour` | `mar_fid_jour` | Marketing | 2 | fidélité |
| `ceo_fid_profil` | `mar_fid_profil` | Marketing | 2 | fidélité |
| `ceo_fid_regle` | `mar_fid_regle` | Marketing | 1 | fidélité |
| `ceo_fid_ticket` | `mar_fid_ticket` | Marketing | 4 | fidélité |
| `ceo_fid_visite` | `mar_fid_visite` | Marketing | 1 | fidélité |
| `ceo_md_form` | `mar_md_form` | Marketing | 1 | MétaDonnée |
| `ceo_md_journal` | `mar_md_journal` | Marketing | 1 | MétaDonnée |
| `ceo_md_lead` | `mar_md_lead` | Marketing | 1 | MétaDonnée |
| `ceo_md_shop` | `mar_md_shop` | Marketing | 3 | MétaDonnée |
| `ceo_promo` | `mar_promo` | Marketing | 4 | registre : à fondre plus tard dans mar_promotion |
| `ceo_promo_mecanique` | `mar_promo_mecanique` | Marketing | 2 |  |
| `ceo_prospect` | `mar_prospect` | Marketing | 1 | ? prospection B2B locale (ou Recrutement) |

#### Dashboard franchisé — 29 renommage(s)

| Ancien nom | Nouveau nom | Domaine | Fichiers de code à mettre à jour | Note |
|---|---|---|---:|---|
| `aia_bundle` | `panel_aia_bundle` | Dashboard franchisé | 1 | assistant IA du dashboard |
| `aia_cache` | `panel_aia_cache` | Dashboard franchisé | 3 | assistant IA du dashboard |
| `aia_conversation` | `panel_aia_conversation` | Dashboard franchisé | 3 | assistant IA du dashboard |
| `aia_fiche` | `panel_aia_fiche` | Dashboard franchisé | 2 | assistant IA du dashboard |
| `aia_manuel_passage` | `panel_aia_manuel_passage` | Dashboard franchisé | 2 | assistant IA du dashboard |
| `aia_modele` | `panel_aia_modele` | Dashboard franchisé | 2 | assistant IA du dashboard |
| `aia_question` | `panel_aia_question` | Dashboard franchisé | 2 | assistant IA du dashboard |
| `aia_reglage` | `panel_aia_reglage` | Dashboard franchisé | 4 | assistant IA du dashboard |
| `aia_visuel` | `panel_aia_visuel` | Dashboard franchisé | 2 | assistant IA du dashboard |
| `ceo_hist_heure` | `panel_hist_heure` | Dashboard franchisé | 2 | historique des ventes de l’assistant |
| `ceo_hist_jour` | `panel_hist_jour` | Dashboard franchisé | 4 | historique des ventes de l’assistant |
| `ceo_hist_produit` | `panel_hist_produit` | Dashboard franchisé | 1 | historique des ventes de l’assistant |
| `ceo_matiere_code` | `panel_matiere_code` | Dashboard franchisé | 1 | ? scan des cartons en magasin (créée par mesure.php) |
| `ceo_reclamation_photo` | `panel_reclamation_photo` | Dashboard franchisé | 1 | photos de réclamation prises depuis le dashboard |
| `ceo_revue_soir` | `panel_revue_soir` | Dashboard franchisé | 2 |  |
| `ceo_shop_day_note` | `panel_shop_day_note` | Dashboard franchisé | 2 |  |
| `ceo_tablette_remarque` | `panel_tablette_remarque` | Dashboard franchisé | 3 | remarques clients saisies sur la tablette des vendeuses |
| `mot_borne` | `panel_mot_borne` | Dashboard franchisé | 3 | ? app employés, primes, pointage |
| `mot_grand_prix` | `panel_mot_grand_prix` | Dashboard franchisé | 4 | ? app employés, primes, pointage |
| `mot_pointage` | `panel_mot_pointage` | Dashboard franchisé | 3 | ? app employés, primes, pointage |
| `mot_profil` | `panel_mot_profil` | Dashboard franchisé | 4 | ? app employés, primes, pointage |
| `pil_auto_reglage` | `panel_auto_reglage` | Dashboard franchisé | 1 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_auto` | `panel_boite_auto` | Dashboard franchisé | 2 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_etat` | `panel_boite_etat` | Dashboard franchisé | 1 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_journal` | `panel_boite_journal` | Dashboard franchisé | 2 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_boite_modele` | `panel_boite_modele` | Dashboard franchisé | 1 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_equipe_mail` | `panel_equipe_mail` | Dashboard franchisé | 2 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_frais_tva` | `panel_frais_tva` | Dashboard franchisé | 2 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |
| `pil_tresorerie_plan` | `panel_tresorerie_plan` | Dashboard franchisé | 3 | dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre) |

#### Pilotage consultant — 84 renommage(s)

| Ancien nom | Nouveau nom | Domaine | Fichiers de code à mettre à jour | Note |
|---|---|---|---:|---|
| `ceo_alert_rule` | `con_alert_rule` | Pilotage consultant | 6 |  |
| `ceo_audit_alerte` | `con_audit_alerte` | Pilotage consultant | 1 |  |
| `ceo_audit_campagne` | `con_audit_campagne` | Pilotage consultant | 1 |  |
| `ceo_audit_copie` | `con_audit_copie` | Pilotage consultant | 1 |  |
| `ceo_audit_destinataire` | `con_audit_destinataire` | Pilotage consultant | 1 |  |
| `ceo_audit_question` | `con_audit_question` | Pilotage consultant | 1 |  |
| `ceo_audit_reponse` | `con_audit_reponse` | Pilotage consultant | 1 |  |
| `ceo_bench_correspondance` | `con_bench_correspondance` | Pilotage consultant | 2 |  |
| `ceo_bench_import` | `con_bench_import` | Pilotage consultant | 1 |  |
| `ceo_benchmark_snapshot` | `con_benchmark_snapshot` | Pilotage consultant | 1 | ? benchmark concurrence (ou Marketing) |
| `ceo_ca_demande` | `con_ca_demande` | Pilotage consultant | 2 |  |
| `ceo_cadre_cree` | `con_cadre_cree` | Pilotage consultant | 1 |  |
| `ceo_combo` | `con_combo` | Pilotage consultant | 4 |  |
| `ceo_crois_cache` | `con_crois_cache` | Pilotage consultant | 1 |  |
| `ceo_doc` | `con_doc` | Pilotage consultant | 2 |  |
| `ceo_email_template` | `con_email_template` | Pilotage consultant | 7 |  |
| `ceo_kpi_def` | `con_kpi_def` | Pilotage consultant | 7 | ? référentiel KPI lu aussi par le dashboard |
| `ceo_kpi_valeur` | `con_kpi_valeur` | Pilotage consultant | 2 | ? lu aussi par le dashboard |
| `ceo_magasin_fiche` | `con_magasin_fiche` | Pilotage consultant | 1 | ? lu aussi par le dashboard |
| `ceo_network_target` | `con_network_target` | Pilotage consultant | 5 |  |
| `ceo_operateur_remarque` | `con_operateur_remarque` | Pilotage consultant | 1 |  |
| `ceo_person` | `con_person` | Pilotage consultant | 5 |  |
| `ceo_plan_action` | `con_plan_action` | Pilotage consultant | 1 |  |
| `ceo_plan_engagement` | `con_plan_engagement` | Pilotage consultant | 1 |  |
| `ceo_plan_revue` | `con_plan_revue` | Pilotage consultant | 1 |  |
| `ceo_plan_trimestre` | `con_plan_trimestre` | Pilotage consultant | 1 |  |
| `ceo_plano_photo` | `con_plano_photo` | Pilotage consultant | 2 |  |
| `ceo_plano_std_emplacement` | `con_plano_std_emplacement` | Pilotage consultant | 2 |  |
| `ceo_plano_std_montage` | `con_plano_std_montage` | Pilotage consultant | 3 |  |
| `ceo_plano_std_photo` | `con_plano_std_photo` | Pilotage consultant | 3 |  |
| `ceo_plano_std_zone` | `con_plano_std_zone` | Pilotage consultant | 2 |  |
| `ceo_prod_fin` | `con_prod_fin` | Pilotage consultant | 3 |  |
| `ceo_prod_product` | `con_prod_product` | Pilotage consultant | 9 |  |
| `ceo_prod_revue` | `con_prod_revue` | Pilotage consultant | 4 |  |
| `ceo_product` | `con_product` | Pilotage consultant | 5 |  |
| `ceo_product_month_sales` | `con_product_month_sales` | Pilotage consultant | 6 |  |
| `ceo_project` | `con_project` | Pilotage consultant | 8 |  |
| `ceo_project_cost` | `con_project_cost` | Pilotage consultant | 6 |  |
| `ceo_project_crm` | `con_project_crm` | Pilotage consultant | 6 |  |
| `ceo_project_levid` | `con_project_levid` | Pilotage consultant | 6 |  |
| `ceo_project_milestone` | `con_project_milestone` | Pilotage consultant | 6 |  |
| `ceo_project_task` | `con_project_task` | Pilotage consultant | 10 |  |
| `ceo_project_template` | `con_project_template` | Pilotage consultant | 7 |  |
| `ceo_pwa_client` | `con_pwa_client` | Pilotage consultant | 1 | ? récurrence des clients PWA (levier) — ou Marketing |
| `ceo_pwa_jour` | `con_pwa_jour` | Pilotage consultant | 1 | ? récurrence des clients PWA (levier) — ou Marketing |
| `ceo_pwa_visite` | `con_pwa_visite` | Pilotage consultant | 1 | ? récurrence des clients PWA (levier) — ou Marketing |
| `ceo_rapport` | `con_rapport` | Pilotage consultant | 13 |  |
| `ceo_rapport_run` | `con_rapport_run` | Pilotage consultant | 7 |  |
| `ceo_report_schedule` | `con_report_schedule` | Pilotage consultant | 7 |  |
| `ceo_shop_budget` | `con_shop_budget` | Pilotage consultant | 8 |  |
| `ceo_shop_budget_line` | `con_shop_budget_line` | Pilotage consultant | 6 |  |
| `ceo_shop_budget_month` | `con_shop_budget_month` | Pilotage consultant | 5 |  |
| `ceo_shop_charge_month` | `con_shop_charge_month` | Pilotage consultant | 4 |  |
| `ceo_shop_month_note` | `con_shop_month_note` | Pilotage consultant | 3 |  |
| `ceo_shop_month_perf` | `con_shop_month_perf` | Pilotage consultant | 22 |  |
| `ceo_shop_profil_heure` | `con_shop_profil_heure` | Pilotage consultant | 1 |  |
| `ceo_shop_profil_jour` | `con_shop_profil_jour` | Pilotage consultant | 2 |  |
| `ceo_stock_evenement` | `con_stock_evenement` | Pilotage consultant | 2 |  |
| `ceo_stock_releve` | `con_stock_releve` | Pilotage consultant | 2 |  |
| `ceo_supplier` | `con_supplier` | Pilotage consultant | 8 |  |
| `ceo_task_annotation` | `con_task_annotation` | Pilotage consultant | 3 |  |
| `ceo_task_issue` | `con_task_issue` | Pilotage consultant | 5 |  |
| `ceo_verif_ca` | `con_verif_ca` | Pilotage consultant | 3 |  |
| `ceo_verif_correction` | `con_verif_correction` | Pilotage consultant | 2 |  |
| `ceo_verif_inventaire` | `con_verif_inventaire` | Pilotage consultant | 4 |  |
| `ceo_verif_jour` | `con_verif_jour` | Pilotage consultant | 6 |  |
| `ceo_verif_recette` | `con_verif_recette` | Pilotage consultant | 3 |  |
| `hdk_demande` | `con_hdk_demande` | Pilotage consultant | 1 | ? helpdesk : cockpit ET dashboard |
| `hdk_mission` | `con_hdk_mission` | Pilotage consultant | 1 | ? helpdesk : cockpit ET dashboard |
| `pil_cq_analyse` | `con_cq_analyse` | Pilotage consultant | 1 | cockpit seulement (déjà « pil_ ») |
| `pil_cq_exemple` | `con_cq_exemple` | Pilotage consultant | 2 | cockpit seulement (déjà « pil_ ») |
| `pil_cq_ia` | `con_cq_ia` | Pilotage consultant | 2 | cockpit seulement (déjà « pil_ ») |
| `pil_fil` | `con_fil` | Pilotage consultant | 2 | cockpit seulement (déjà « pil_ ») |
| `pil_fil_entree` | `con_fil_entree` | Pilotage consultant | 2 | cockpit seulement (déjà « pil_ ») |
| `pil_fil_piece` | `con_fil_piece` | Pilotage consultant | 2 | cockpit seulement (déjà « pil_ ») |
| `pla_contenant` | `con_pla_contenant` | Pilotage consultant | 3 | planogramme |
| `pla_format` | `con_pla_format` | Pilotage consultant | 3 | planogramme |
| `pla_meuble` | `con_pla_meuble` | Pilotage consultant | 4 | planogramme |
| `pla_montage` | `con_pla_montage` | Pilotage consultant | 1 | planogramme |
| `pla_niveau` | `con_pla_niveau` | Pilotage consultant | 4 | planogramme |
| `pla_note` | `con_pla_note` | Pilotage consultant | 4 | planogramme |
| `pla_placement` | `con_pla_placement` | Pilotage consultant | 4 | planogramme |
| `pla_slot` | `con_pla_slot` | Pilotage consultant | 4 | planogramme |
| `pla_zone` | `con_pla_zone` | Pilotage consultant | 4 | planogramme |

#### Tables qui gardent leur nom

- **Partagées** (`shared/`, nom actuel conservé) : `ceo_action_usage`, `ceo_app_setting`, `ceo_connecteur`, `ceo_ecran_vue`, `ceo_journal_entry`, `ceo_push_abonnement`, `ceo_shop`, `erp_cles`.
- **Marketing, déjà au bon préfixe** : `mar_agency`, `mar_agency_ask`, `mar_agency_campaign`, `mar_asset_render`, `mar_avis`, `mar_avis_fiche`, `mar_b2b_option`, `mar_b2b_prospect`, `mar_b2b_prospect_sector`, `mar_b2b_sector`, `mar_bg_controle`, `mar_bg_page`, `mar_bg_regle`, `mar_campagne_annexe`, `mar_campagne_annexe_type`, `mar_campagne_mesure`, `mar_campagne_objectif`, `mar_campagne_objectif_produit`, `mar_campagne_produit`, `mar_campagne_releve`, `mar_campagne_snapshot`, `mar_channel`, `mar_client_target`, `mar_cost_kind`, `mar_crm_lead`, `mar_crm_lead_event`, `mar_crm_segment`, `mar_format`, `mar_lead_status`, `mar_nl_campagne`, `mar_nl_contact`, `mar_nl_envoi`, `mar_nl_magasin`, `mar_nl_segment`, `mar_nl_source`, `mar_nl_voucher`, `mar_offer_item`, `mar_offer_item_season`, `mar_offer_template`, `mar_offer_template_item`, `mar_pos_answer_type`, `mar_position`, `mar_promotion_mechanic`, `mar_retroplanning_default`, `mar_retroplanning_step`, `mar_review_platform`, `mar_sales_channel`, `mar_shop_revenue`, `mar_shop_user`, `mar_social_ecart`, `mar_social_post`, `mar_social_publication`, `mar_tone`, `mar_uniform`, `mar_voucher`.
- **Pilotage consultant, déjà au bon préfixe** : `ceo_verif_commande`, `ceo_verif_geste`, `ceo_verif_matiere`, `ceo_verif_produit`, `ceo_verif_stock_jour`, `con_consultant`, `con_consultant_checklist`, `con_consultant_tache`, `con_consultant_visit`, `con_equipe_releve`, `con_franchise_meteo`, `con_msp`, `con_rayon_photo`, `con_scoring_google`, `con_scoring_msp`, `con_tache_jour`, `con_tache_jour_etat`, `con_visite`, `con_visite_action`, `con_visite_action_evt`, `con_visite_photo`, `con_visite_point`. Les cinq `ceo_verif_commande / geste / matiere /
  produit / stock_jour` ne sont décrites que dans `docs/plan-verification.md` : jamais créées par le code, rien à
  renommer (à confirmer en base).
- **Tables d'autres applications lues par les quatre domaines** (lecture seule, ni renommées ni supprimées) :

- **ERP / panel TF Buddy** (45) : `b2b_client`, `client`, `client_order`, `client_order_product`, `company`, `flattened_recipe_ingredient`, `franchisee_employee`, `franchisee_employee_schedule`, `kpi`, `material`, `material_category`, `material_inventory`, `material_inventory_log`, `material_movement_reason`, `material_order`, `material_order_item`, `material_order_item_delivery_log`, `of_tag`, `position`, `product`, `product_availability_period`, `product_availability_period_connection`, `product_category`, `product_category_group`, `product_category_group_connection`, `product_movement`, `product_portion`, `product_recipe`, `product_recipe_material_connection`, `recipe_cost`, `revenue_budget`, `shop_material_price_list`, `shop_product`, `shops`, `stocktaking`, `stocktaking_item`, `stocktaking_summary`, `todo_task`, `transaction`, `transaction_product`, `user_membership`, `user_profile`, `voucher_campaign`, `voucher_campaign_channel`, `voucher_code`
- **CRM Recrutement (dépôt atelier-espace-candidat)** (4) : `crm_mo_acces`, `crm_mo_chapitres`, `crm_mo_magasins`, `crm_mo_procedures`
- **PWA consultant (dépôt pwa_consultant)** (7) : `mac_consultant_param`, `mac_consultant_visit`, `mac_google_rating_snapshot`, `mac_kpi_threshold`, `mac_report_share`, `mac_shop_monthly_pnl`, `mac_task_review`
- **Module Marketing (dépôt marketing, migrations 0xx)** (36) : `mar_brand`, `mar_bundle`, `mar_bundle_item`, `mar_campaign`, `mar_campaign_agency_ask`, `mar_campaign_asset`, `mar_campaign_b2b_option`, `mar_campaign_b2b_sector`, `mar_campaign_challenge_prize`, `mar_campaign_channel`, `mar_campaign_kpi_snapshot`, `mar_campaign_lever_target`, `mar_campaign_offer`, `mar_campaign_offer_item`, `mar_campaign_pos_question`, `mar_campaign_shop`, `mar_campaign_shop_item_target`, `mar_campaign_status`, `mar_campaign_type`, `mar_campaign_uniform`, `mar_crm_customer`, `mar_crm_customer_segment`, `mar_fund_movement`, `mar_fund_recurrence`, `mar_fund_source`, `mar_kit`, `mar_kit_activation`, `mar_kit_asset`, `mar_lever`, `mar_promotion`, `mar_review`, `mar_roi_cost`, `mar_shop`, `mar_shop_presence`, `mar_voucher_channel`, `mar_voucher_redemption`
- **Signage (dépôt signage)** (2) : `sig_product_categories`, `sig_products`

---

## 2. Correspondance des fichiers : ancien chemin → nouveau chemin

Proposition (question A8) : un dossier par domaine **à la racine du dépôt**, avec la même organisation interne que
`pilotage-reseau/` aujourd'hui (`src/`, `public/`, `bin/`, `tests/`, `sql/`, `docs/`, `tools/`), plus `shared/` pour
ce qui sert à plusieurs domaines. Ce qui est hors périmètre **reste dans `pilotage-reseau/`** jusqu'à la phase 4.

```
consultant_BO/
├── marketing/     src/ public/ bin/ tests/ sql/ docs/ tools/
├── formation/     …
├── consultant/    …
├── panel/         … (src/franchise/* aplati dans panel/src/, apps/employee/ dans panel/apps/employee/)
├── shared/        Db, accès, clés, clients ERP/panel/IA, routeur de l'API, monolithes, déploiement, config
├── pilotage-reseau/   hors périmètre jusqu'à la phase 4 : apps/production (ERP), module RH (src/hr, public/hr…)
├── docs/          registre et design system communs (inchangé)
└── .github/workflows/
```

Les déplacements se font par `git mv` (historique gardé). **Les URL publiques ne changent pas** : le déploiement reste
unique (un workflow, un `deploy.sh`), et l'Apache du serveur sert chaque page à son adresse actuelle depuis son nouveau
dossier (`/consulant_bo/dashboard/` depuis `panel/public/dashboard/`, `/formation/` depuis `formation/public/formation/`,
`/consulant_bo/tablette/` — identité des tablettes installées — depuis `panel/public/tablette/`, etc.).

Tableau par dossier ci-dessous : 2 552 fichiers déplacés ; 4 442 restent en place (4 241 de la copie de l'ERP, 183 du module RH et des maquettes du scouting, 18 fichiers communs de la racine du dépôt).
Le détail fichier par fichier est dans `reorg/fichiers.tsv`.

| Ancien chemin | Nouveau chemin | Fichiers | Domaine |
|---|---|---:|---|
| `pilotage-reseau/.gitignore` | `shared/.gitignore` | 1 | Partagé |
| `pilotage-reseau/CLAUDE.md` | `shared/CLAUDE.md` | 1 | Partagé |
| `pilotage-reseau/README.md` | `shared/README.md` | 1 | Partagé |
| `pilotage-reseau/apps/employee/` | `panel/apps/employee/` | 159 | Dashboard franchisé |
| `pilotage-reseau/apps/production/` | (reste en place) | 4241 | Hors périmètre |
| `pilotage-reseau/bin/benchmark.php` | `consultant/bin/benchmark.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/boite_cron.sh` | `panel/bin/boite_cron.sh` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/carte_sources.php` | `shared/bin/carte_sources.php` | 1 | Partagé |
| `pilotage-reseau/bin/console_bo.php` | `shared/bin/console_bo.php` | 1 | Partagé |
| `pilotage-reseau/bin/console_bo_seal.php` | `shared/bin/console_bo_seal.php` | 1 | Partagé |
| `pilotage-reseau/bin/db_admin.php` | `shared/bin/db_admin.php` | 1 | Partagé |
| `pilotage-reseau/bin/deploy.sh` | `shared/bin/deploy.sh` | 1 | Partagé |
| `pilotage-reseau/bin/disque_alerte.php` | `shared/bin/disque_alerte.php` | 1 | Partagé |
| `pilotage-reseau/bin/extract_panel_db.php` | `shared/bin/extract_panel_db.php` | 1 | Partagé |
| `pilotage-reseau/bin/historique_moisson.php` | `panel/bin/historique_moisson.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/hr_dimona_cron.php` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/bin/hr_dimona_cron.sh` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/bin/hr_dimona_schema.php` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/bin/menage_disque.sh` | `shared/bin/menage_disque.sh` | 1 | Partagé |
| `pilotage-reseau/bin/metadonnee_cron.sh` | `marketing/bin/metadonnee_cron.sh` | 1 | Marketing |
| `pilotage-reseau/bin/mix1000.php` | `consultant/bin/mix1000.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/newsletter_cron.sh` | `marketing/bin/newsletter_cron.sh` | 1 | Marketing |
| `pilotage-reseau/bin/push_stock.php` | `consultant/bin/push_stock.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/rapports_cron.sh` | `consultant/bin/rapports_cron.sh` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/seed.php` | `shared/bin/seed.php` | 1 | Partagé |
| `pilotage-reseau/bin/social_cron.sh` | `marketing/bin/social_cron.sh` | 1 | Marketing |
| `pilotage-reseau/bin/stock_cron.sh` | `consultant/bin/stock_cron.sh` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/test_chargement.php` | `shared/bin/test_chargement.php` | 1 | Partagé |
| `pilotage-reseau/bin/tests.php` | `shared/bin/tests.php` | 1 | Partagé |
| `pilotage-reseau/bin/tests_analyse.php` | `panel/bin/tests_analyse.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_assistant_ia.php` | `panel/bin/tests_assistant_ia.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_assortiment.php` | `consultant/bin/tests_assortiment.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_audits.php` | `consultant/bin/tests_audits.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_automatisations.php` | `panel/bin/tests_automatisations.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_benchmark.php` | `consultant/bin/tests_benchmark.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_benchmark_import.php` | `consultant/bin/tests_benchmark_import.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_boite_mail.php` | `panel/bin/tests_boite_mail.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_bons.php` | `marketing/bin/tests_bons.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_bundles.php` | `panel/bin/tests_bundles.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_ca_net.php` | `consultant/bin/tests_ca_net.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_canaux.php` | `marketing/bin/tests_canaux.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_catalogue.php` | `marketing/bin/tests_catalogue.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_cibles_reseau.php` | `consultant/bin/tests_cibles_reseau.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_coach_objectifs.php` | `panel/bin/tests_coach_objectifs.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_controles_photos.php` | `consultant/bin/tests_controles_photos.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_cq_mail.php` | `consultant/bin/tests_cq_mail.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_differes.php` | `consultant/bin/tests_differes.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_documents.php` | `consultant/bin/tests_documents.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_employes_relus.php` | `panel/bin/tests_employes_relus.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_evolution_pro.php` | `consultant/bin/tests_evolution_pro.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_fil.php` | `consultant/bin/tests_fil.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_formation.php` | `formation/bin/tests_formation.php` | 1 | Formation |
| `pilotage-reseau/bin/tests_grands_prix.php` | `panel/bin/tests_grands_prix.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_historique.php` | `consultant/bin/tests_historique.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_ia_assistant.php` | `consultant/bin/tests_ia_assistant.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_ia_note.php` | `panel/bin/tests_ia_note.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_jauges.php` | `panel/bin/tests_jauges.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_kpi_cibles.php` | `consultant/bin/tests_kpi_cibles.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_manuel_chiffres.php` | `formation/bin/tests_manuel_chiffres.php` | 1 | Formation |
| `pilotage-reseau/bin/tests_metadonnee.php` | `marketing/bin/tests_metadonnee.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_newsletter.php` | `marketing/bin/tests_newsletter.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_noms_globaux.php` | `consultant/bin/tests_noms_globaux.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_panel_reessai.php` | `consultant/bin/tests_panel_reessai.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_planning_moi.php` | `panel/bin/tests_planning_moi.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_pnl_ht.php` | `panel/bin/tests_pnl_ht.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_pointage_app.php` | `panel/bin/tests_pointage_app.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_pointages.php` | `panel/bin/tests_pointages.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_primes.php` | `panel/bin/tests_primes.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_profil.php` | `panel/bin/tests_profil.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_promos.php` | `marketing/bin/tests_promos.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_promos_reseau.php` | `marketing/bin/tests_promos_reseau.php` | 1 | Marketing |
| `pilotage-reseau/bin/tests_rapports_suspension.php` | `consultant/bin/tests_rapports_suspension.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_revue_soir.php` | `panel/bin/tests_revue_soir.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_suivi.php` | `consultant/bin/tests_suivi.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_temps_morts.php` | `panel/bin/tests_temps_morts.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_tresorerie.php` | `panel/bin/tests_tresorerie.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/bin/tests_verification.php` | `consultant/bin/tests_verification.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_verification_correction.php` | `consultant/bin/tests_verification_correction.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/tests_visites_kpi.php` | `consultant/bin/tests_visites_kpi.php` | 1 | Pilotage consultant |
| `pilotage-reseau/bin/visites_cron.sh` | `consultant/bin/visites_cron.sh` | 1 | Pilotage consultant |
| `pilotage-reseau/config/config.example.php` | `shared/config/config.example.php` | 1 | Partagé |
| `pilotage-reseau/config/console_bo_accounts.sealed` | `shared/config/console_bo_accounts.sealed` | 1 | Partagé |
| `pilotage-reseau/data/formation/` | `formation/data/formation/` | 64 | Formation |
| `pilotage-reseau/deploy.trigger` | `shared/deploy.trigger` | 1 | Partagé |
| `pilotage-reseau/docs/DEPLOIEMENT.md` | `shared/docs/DEPLOIEMENT.md` | 1 | Partagé |
| `pilotage-reseau/docs/api-promotions.md` | `marketing/docs/api-promotions.md` | 1 | Marketing |
| `pilotage-reseau/docs/audit-api-tfbuddy.md` | `shared/docs/audit-api-tfbuddy.md` | 1 | Partagé |
| `pilotage-reseau/docs/audit-api.md` | `shared/docs/audit-api.md` | 1 | Partagé |
| `pilotage-reseau/docs/audit-rail.md` | `shared/docs/audit-rail.md` | 1 | Partagé |
| `pilotage-reseau/docs/audit-taches-consultant.md` | `consultant/docs/audit-taches-consultant.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/audits-fournisseurs.md` | `consultant/docs/audits-fournisseurs.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/benchmarks/` | `consultant/docs/benchmarks/` | 3 | Pilotage consultant |
| `pilotage-reseau/docs/brand-guard.md` | `marketing/docs/brand-guard.md` | 1 | Marketing |
| `pilotage-reseau/docs/consignes/` | `panel/docs/consignes/` | 1 | Dashboard franchisé |
| `pilotage-reseau/docs/contrat-api.md` | `shared/docs/contrat-api.md` | 1 | Partagé |
| `pilotage-reseau/docs/contrat-panel-produits.md` | `consultant/docs/contrat-panel-produits.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/design-system/` | `shared/docs/design-system/` | 29 | Partagé |
| `pilotage-reseau/docs/dimona.md` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/docs/dimona/` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/docs/dossiers/` | `consultant/docs/dossiers/` | 9 | Pilotage consultant |
| `pilotage-reseau/docs/formation-routes.md` | `formation/docs/formation-routes.md` | 1 | Formation |
| `pilotage-reseau/docs/formation.md` | `formation/docs/formation.md` | 1 | Formation |
| `pilotage-reseau/docs/handoff-centrale-achat.md` | `consultant/docs/handoff-centrale-achat.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/alignement-fournisseurs/` | `consultant/docs/maquettes/alignement-fournisseurs/` | 9 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/annee-trimestres/` | `panel/docs/maquettes/annee-trimestres/` | 8 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/api-module/` | `consultant/docs/maquettes/api-module/` | 3 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/assistant-graphiques/` | `panel/docs/maquettes/assistant-graphiques/` | 24 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/assistant-ia-magasin/` | `panel/docs/maquettes/assistant-ia-magasin/` | 7 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/assistant-mise-en-page/` | `panel/docs/maquettes/assistant-mise-en-page/` | 7 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/assortiment-obligatoire/` | `consultant/docs/maquettes/assortiment-obligatoire/` | 6 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/audit-outils-gestion/` | `consultant/docs/maquettes/audit-outils-gestion/` | 12 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/b2b-b2c/` | `marketing/docs/maquettes/b2b-b2c/` | 5 | Marketing |
| `pilotage-reseau/docs/maquettes/b2b-commandes-mobile/` | `panel/docs/maquettes/b2b-commandes-mobile/` | 12 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/benchmark-concurrence/` | `consultant/docs/maquettes/benchmark-concurrence/` | 8 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/benchmark-import-pdf/` | `consultant/docs/maquettes/benchmark-import-pdf/` | 22 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/budget-pnl-crm/` | `consultant/docs/maquettes/budget-pnl-crm/` | 7 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/budget-tablette/` | `panel/docs/maquettes/budget-tablette/` | 6 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/caisse-reports/` | `panel/docs/maquettes/caisse-reports/` | 7 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/canaux-offres/` | `marketing/docs/maquettes/canaux-offres/` | 16 | Marketing |
| `pilotage-reseau/docs/maquettes/cartes-fidelite/` | `marketing/docs/maquettes/cartes-fidelite/` | 16 | Marketing |
| `pilotage-reseau/docs/maquettes/clients-annee-n1/` | `marketing/docs/maquettes/clients-annee-n1/` | 13 | Marketing |
| `pilotage-reseau/docs/maquettes/clients-magasin/` | `marketing/docs/maquettes/clients-magasin/` | 10 | Marketing |
| `pilotage-reseau/docs/maquettes/comptoir-webshop/` | `panel/docs/maquettes/comptoir-webshop/` | 12 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/controle-photos/` | `consultant/docs/maquettes/controle-photos/` | 23 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/controle-taches-photos/` | `consultant/docs/maquettes/controle-taches-photos/` | 33 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/controles-qualite/` | `consultant/docs/maquettes/controles-qualite/` | 42 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/dashboard-3-onglets/` | `panel/docs/maquettes/dashboard-3-onglets/` | 32 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/dashboard-categories/` | `panel/docs/maquettes/dashboard-categories/` | 6 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/dashboard-franchise-une-page/` | `panel/docs/maquettes/dashboard-franchise-une-page/` | 3 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/dashboard-mobile-v2/` | `panel/docs/maquettes/dashboard-mobile-v2/` | 11 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/dashboard-mobile-v3/` | `panel/docs/maquettes/dashboard-mobile-v3/` | 46 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/dashboard-mobile/` | `panel/docs/maquettes/dashboard-mobile/` | 11 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/dashboard-operationnel/` | `panel/docs/maquettes/dashboard-operationnel/` | 18 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/equipe-en-poste/` | `panel/docs/maquettes/equipe-en-poste/` | 7 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/evaluation-rapide/` | `consultant/docs/maquettes/evaluation-rapide/` | 14 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/evolution-canaux/` | `marketing/docs/maquettes/evolution-canaux/` | 17 | Marketing |
| `pilotage-reseau/docs/maquettes/evolution-magasin/` | `consultant/docs/maquettes/evolution-magasin/` | 15 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/fiche-matiere/` | `consultant/docs/maquettes/fiche-matiere/` | 9 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/fidelite-franchise/` | `marketing/docs/maquettes/fidelite-franchise/` | 3 | Marketing |
| `pilotage-reseau/docs/maquettes/fidelite-profils/` | `marketing/docs/maquettes/fidelite-profils/` | 7 | Marketing |
| `pilotage-reseau/docs/maquettes/fidelite-v3/` | `marketing/docs/maquettes/fidelite-v3/` | 10 | Marketing |
| `pilotage-reseau/docs/maquettes/fidelite/` | `marketing/docs/maquettes/fidelite/` | 23 | Marketing |
| `pilotage-reseau/docs/maquettes/fil-franchise/` | `consultant/docs/maquettes/fil-franchise/` | 25 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/formation-donnees/` | `formation/docs/maquettes/formation-donnees/` | 16 | Formation |
| `pilotage-reseau/docs/maquettes/formation/` | `formation/docs/maquettes/formation/` | 38 | Formation |
| `pilotage-reseau/docs/maquettes/frais-tva/` | `panel/docs/maquettes/frais-tva/` | 9 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/franchises-wizard/` | `consultant/docs/maquettes/franchises-wizard/` | 7 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/fusion-manuel/` | `formation/docs/maquettes/fusion-manuel/` | 12 | Formation |
| `pilotage-reseau/docs/maquettes/gestion-consultant/` | `consultant/docs/maquettes/gestion-consultant/` | 14 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/gestion-production/` | `panel/docs/maquettes/gestion-production/` | 12 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/helpdesk-franchises/` | `consultant/docs/maquettes/helpdesk-franchises/` | 16 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/jours-creux/` | `panel/docs/maquettes/jours-creux/` | 14 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/manuel-formation/` | `formation/docs/maquettes/manuel-formation/` | 12 | Formation |
| `pilotage-reseau/docs/maquettes/matieres-par-fournisseur/` | `consultant/docs/maquettes/matieres-par-fournisseur/` | 10 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/matieres-reference/` | `consultant/docs/maquettes/matieres-reference/` | 8 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/metadonnee/` | `marketing/docs/maquettes/metadonnee/` | 15 | Marketing |
| `pilotage-reseau/docs/maquettes/modale-produit/` | `panel/docs/maquettes/modale-produit/` | 20 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/modale-recette/` | `panel/docs/maquettes/modale-recette/` | 8 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/nc-compact/` | `consultant/docs/maquettes/nc-compact/` | 9 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/nc-veille/` | `consultant/docs/maquettes/nc-veille/` | 15 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/objectif-jauge/` | `panel/docs/maquettes/objectif-jauge/` | 11 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/objectifs-produits/` | `marketing/docs/maquettes/objectifs-produits/` | 5 | Marketing |
| `pilotage-reseau/docs/maquettes/onglet-produits-developpement/` | `panel/docs/maquettes/onglet-produits-developpement/` | 26 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/onglet-produits-simple/` | `panel/docs/maquettes/onglet-produits-simple/` | 21 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/onglet-produits/` | `panel/docs/maquettes/onglet-produits/` | 32 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/panier-heures/` | `panel/docs/maquettes/panier-heures/` | 7 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/parametrage-produits/` | `consultant/docs/maquettes/parametrage-produits/` | 12 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/parametres-mails/` | `panel/docs/maquettes/parametres-mails/` | 10 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/plan-campagne/` | `marketing/docs/maquettes/plan-campagne/` | 34 | Marketing |
| `pilotage-reseau/docs/maquettes/planogramme-standard/` | `consultant/docs/maquettes/planogramme-standard/` | 6 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/pointage-app/` | `panel/docs/maquettes/pointage-app/` | 8 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/portail-formation/` | `formation/docs/maquettes/portail-formation/` | 25 | Formation |
| `pilotage-reseau/docs/maquettes/primes-b2b/` | `panel/docs/maquettes/primes-b2b/` | 17 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/primes-epure/` | `panel/docs/maquettes/primes-epure/` | 39 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/primes/` | `panel/docs/maquettes/primes/` | 19 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/prix-volume/` | `marketing/docs/maquettes/prix-volume/` | 16 | Marketing |
| `pilotage-reseau/docs/maquettes/profil-employe/` | `panel/docs/maquettes/profil-employe/` | 4 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/promo-coach/` | `panel/docs/maquettes/promo-coach/` | 27 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/promotions-analyse/` | `marketing/docs/maquettes/promotions-analyse/` | 12 | Marketing |
| `pilotage-reseau/docs/maquettes/promotions-cockpit/` | `marketing/docs/maquettes/promotions-cockpit/` | 13 | Marketing |
| `pilotage-reseau/docs/maquettes/promotions-utilisation/` | `marketing/docs/maquettes/promotions-utilisation/` | 12 | Marketing |
| `pilotage-reseau/docs/maquettes/pwa-visites/` | `consultant/docs/maquettes/pwa-visites/` | 12 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/rappel-sms-controles/` | `consultant/docs/maquettes/rappel-sms-controles/` | 6 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/recettes-correction/` | `consultant/docs/maquettes/recettes-correction/` | 17 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/reclamations/` | `panel/docs/maquettes/reclamations/` | 31 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/reperes-photos/` | `consultant/docs/maquettes/reperes-photos/` | 15 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/resultat-vers-dashboard/` | `panel/docs/maquettes/resultat-vers-dashboard/` | 6 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/royalties/` | `consultant/docs/maquettes/royalties/` | 6 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/scoring-mensuel/` | `consultant/docs/maquettes/scoring-mensuel/` | 5 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/scouting-v2/` | (reste en place) | 17 | Hors périmètre |
| `pilotage-reseau/docs/maquettes/scouting-wizard/` | (reste en place) | 10 | Hors périmètre |
| `pilotage-reseau/docs/maquettes/semaine-mois/` | `panel/docs/maquettes/semaine-mois/` | 17 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/site-gamme-mise-en-avant/` | `marketing/docs/maquettes/site-gamme-mise-en-avant/` | 5 | Marketing |
| `pilotage-reseau/docs/maquettes/sop-dashboard/` | `formation/docs/maquettes/sop-dashboard/` | 18 | Formation |
| `pilotage-reseau/docs/maquettes/structure-gamme/` | `consultant/docs/maquettes/structure-gamme/` | 10 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/suivi-duree-de-vie/` | `consultant/docs/maquettes/suivi-duree-de-vie/` | 19 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/suivi-franchises/` | `consultant/docs/maquettes/suivi-franchises/` | 22 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/tablette-sop/` | `formation/docs/maquettes/tablette-sop/` | 17 | Formation |
| `pilotage-reseau/docs/maquettes/temps-morts-tickets/` | `panel/docs/maquettes/temps-morts-tickets/` | 7 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/temps-morts/` | `panel/docs/maquettes/temps-morts/` | 11 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/tresorerie/` | `panel/docs/maquettes/tresorerie/` | 6 | Dashboard franchisé |
| `pilotage-reseau/docs/maquettes/ventes-coef/` | `consultant/docs/maquettes/ventes-coef/` | 6 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/verification/` | `consultant/docs/maquettes/verification/` | 24 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/visibilite-google/` | `marketing/docs/maquettes/visibilite-google/` | 9 | Marketing |
| `pilotage-reseau/docs/maquettes/vue-generale/` | `consultant/docs/maquettes/vue-generale/` | 67 | Pilotage consultant |
| `pilotage-reseau/docs/maquettes/vue-jour-a4/` | `panel/docs/maquettes/vue-jour-a4/` | 16 | Dashboard franchisé |
| `pilotage-reseau/docs/module-motivation.md` | `panel/docs/module-motivation.md` | 1 | Dashboard franchisé |
| `pilotage-reseau/docs/plan-verification.md` | `consultant/docs/plan-verification.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/primes/` | `panel/docs/primes/` | 6 | Dashboard franchisé |
| `pilotage-reseau/docs/publication-reseaux.md` | `marketing/docs/publication-reseaux.md` | 1 | Marketing |
| `pilotage-reseau/docs/pwa-suivi-visites-analyse.md` | `consultant/docs/pwa-suivi-visites-analyse.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/spec-api-panel.md` | `shared/docs/spec-api-panel.md` | 1 | Partagé |
| `pilotage-reseau/docs/suivi-franchises.md` | `consultant/docs/suivi-franchises.md` | 1 | Pilotage consultant |
| `pilotage-reseau/docs/task-review-attachments-en.html` | `shared/docs/task-review-attachments-en.html` | 1 | Partagé |
| `pilotage-reseau/essai-vouchers.trigger` | `marketing/essai-vouchers.trigger` | 1 | Marketing |
| `pilotage-reseau/maquette-screens/README.md` | `consultant/maquette-screens/README.md` | 1 | Pilotage consultant |
| `pilotage-reseau/maquette-screens/captures/` | `consultant/maquette-screens/captures/` | 4 | Pilotage consultant |
| `pilotage-reseau/maquette-screens/maquette-qc-vision-screens.html` | `consultant/maquette-screens/maquette-qc-vision-screens.html` | 1 | Pilotage consultant |
| `pilotage-reseau/public/.gitignore` | `shared/public/.gitignore` | 1 | Partagé |
| `pilotage-reseau/public/.htaccess` | `shared/public/.htaccess` | 1 | Partagé |
| `pilotage-reseau/public/api/hr/` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/public/api/index.php` | `shared/public/api/index.php` | 1 | Partagé |
| `pilotage-reseau/public/api/marketing/` | `marketing/public/api/marketing/` | 38 | Marketing |
| `pilotage-reseau/public/assets/css/` | `shared/public/assets/css/` | 12 | Partagé |
| `pilotage-reseau/public/assets/ds/` | `shared/public/assets/ds/` | 18 | Partagé |
| `pilotage-reseau/public/assets/img/` | `shared/public/assets/img/` | 21 | Partagé |
| `pilotage-reseau/public/assets/js/api.js` | `shared/public/assets/js/api.js` | 1 | Partagé |
| `pilotage-reseau/public/assets/js/app.js` | `shared/public/assets/js/app.js` | 1 | Partagé |
| `pilotage-reseau/public/assets/js/assistant-coachs-tpl.js` | `panel/public/assets/js/assistant-coachs-tpl.js` | 1 | Dashboard franchisé |
| `pilotage-reseau/public/assets/js/assistant-coachs-vals.js` | `panel/public/assets/js/assistant-coachs-vals.js` | 1 | Dashboard franchisé |
| `pilotage-reseau/public/assets/js/benchmark-tpl.js` | `consultant/public/assets/js/benchmark-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/benchmark-vals.js` | `consultant/public/assets/js/benchmark-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/cartes-magasin.js` | `consultant/public/assets/js/cartes-magasin.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/clients-annee-tpl.js` | `marketing/public/assets/js/clients-annee-tpl.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/clients-annee-vals.js` | `marketing/public/assets/js/clients-annee-vals.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/fidelite-tpl.js` | `marketing/public/assets/js/fidelite-tpl.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/fidelite-vals.js` | `marketing/public/assets/js/fidelite-vals.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/fil-tpl.js` | `consultant/public/assets/js/fil-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/fil-vals.js` | `consultant/public/assets/js/fil-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/franchises-tpl.js` | `consultant/public/assets/js/franchises-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/franchises-vals.js` | `consultant/public/assets/js/franchises-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/garde.js` | `shared/public/assets/js/garde.js` | 1 | Partagé |
| `pilotage-reseau/public/assets/js/helpdesk-tpl.js` | `consultant/public/assets/js/helpdesk-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/helpdesk-vals.js` | `consultant/public/assets/js/helpdesk-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/metadonnee.js` | `marketing/public/assets/js/metadonnee.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/newsletter.js` | `marketing/public/assets/js/newsletter.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/produit-parametrage-tpl.js` | `consultant/public/assets/js/produit-parametrage-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/produit-parametrage-vals.js` | `consultant/public/assets/js/produit-parametrage-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/promotions-tpl.js` | `marketing/public/assets/js/promotions-tpl.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/promotions-vals.js` | `marketing/public/assets/js/promotions-vals.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/prospection.js` | `marketing/public/assets/js/prospection.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/structure-gamme-tpl.js` | `consultant/public/assets/js/structure-gamme-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/structure-gamme.js` | `consultant/public/assets/js/structure-gamme.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/structure-regles.js` | `consultant/public/assets/js/structure-regles.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/suivi-tpl.js` | `consultant/public/assets/js/suivi-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/suivi-vals.js` | `consultant/public/assets/js/suivi-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/templates.js` | `shared/public/assets/js/templates.js` | 1 | Partagé |
| `pilotage-reseau/public/assets/js/verification-ca-tpl.js` | `consultant/public/assets/js/verification-ca-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-ca-vals.js` | `consultant/public/assets/js/verification-ca-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-correction-tpl.js` | `consultant/public/assets/js/verification-correction-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-correction-vals.js` | `consultant/public/assets/js/verification-correction-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-evol-tpl.js` | `consultant/public/assets/js/verification-evol-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-evol-vals.js` | `consultant/public/assets/js/verification-evol-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-fichemat-tpl.js` | `consultant/public/assets/js/verification-fichemat-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-fichemat-vals.js` | `consultant/public/assets/js/verification-fichemat-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-flux-tpl.js` | `consultant/public/assets/js/verification-flux-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-flux-vals.js` | `consultant/public/assets/js/verification-flux-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-matieres-tpl.js` | `consultant/public/assets/js/verification-matieres-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-matieres-vals.js` | `consultant/public/assets/js/verification-matieres-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-mouvements-tpl.js` | `consultant/public/assets/js/verification-mouvements-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-mouvements-vals.js` | `consultant/public/assets/js/verification-mouvements-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-prix-tpl.js` | `consultant/public/assets/js/verification-prix-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-prix-vals.js` | `consultant/public/assets/js/verification-prix-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-tableau-tpl.js` | `consultant/public/assets/js/verification-tableau-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-tableau-vals.js` | `consultant/public/assets/js/verification-tableau-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-tpl.js` | `consultant/public/assets/js/verification-tpl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/verification-vals.js` | `consultant/public/assets/js/verification-vals.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/visibilite-tpl.js` | `marketing/public/assets/js/visibilite-tpl.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/visibilite-vals.js` | `marketing/public/assets/js/visibilite-vals.js` | 1 | Marketing |
| `pilotage-reseau/public/assets/js/visites-nl.js` | `consultant/public/assets/js/visites-nl.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/js/visites.js` | `consultant/public/assets/js/visites.js` | 1 | Pilotage consultant |
| `pilotage-reseau/public/assets/primes/` | `panel/public/assets/primes/` | 3 | Dashboard franchisé |
| `pilotage-reseau/public/assistant/` | `marketing/public/assistant/` | 3 | Marketing |
| `pilotage-reseau/public/audit/` | `consultant/public/audit/` | 2 | Pilotage consultant |
| `pilotage-reseau/public/audits/` | `consultant/public/audits/` | 2 | Pilotage consultant |
| `pilotage-reseau/public/borne/` | `panel/public/borne/` | 1 | Dashboard franchisé |
| `pilotage-reseau/public/caisse/` | `consultant/public/caisse/` | 3 | Pilotage consultant |
| `pilotage-reseau/public/connexion/` | `shared/public/connexion/` | 2 | Partagé |
| `pilotage-reseau/public/consultant.webmanifest` | `consultant/public/consultant.webmanifest` | 1 | Pilotage consultant |
| `pilotage-reseau/public/controle/` | `consultant/public/controle/` | 3 | Pilotage consultant |
| `pilotage-reseau/public/dashboard/` | `panel/public/dashboard/` | 30 | Dashboard franchisé |
| `pilotage-reseau/public/employee/` | `panel/public/employee/` | 26 | Dashboard franchisé |
| `pilotage-reseau/public/fidelite-app/` | `marketing/public/fidelite-app/` | 2 | Marketing |
| `pilotage-reseau/public/fidelite-test/` | `marketing/public/fidelite-test/` | 22 | Marketing |
| `pilotage-reseau/public/formation/` | `formation/public/formation/` | 50 | Formation |
| `pilotage-reseau/public/hr/` | (reste en place) | 7 | Hors périmètre |
| `pilotage-reseau/public/index.html` | `shared/public/index.html` | 1 | Partagé |
| `pilotage-reseau/public/marketing/` | `marketing/public/marketing/` | 1 | Marketing |
| `pilotage-reseau/public/newsletter/` | `marketing/public/newsletter/` | 1 | Marketing |
| `pilotage-reseau/public/planogramme/` | `consultant/public/planogramme/` | 3 | Pilotage consultant |
| `pilotage-reseau/public/production/` | `panel/public/production/` | 3 | Dashboard franchisé |
| `pilotage-reseau/public/prospection/` | `marketing/public/prospection/` | 1 | Marketing |
| `pilotage-reseau/public/resultat-mobile/` | `panel/public/resultat-mobile/` | 3 | Dashboard franchisé |
| `pilotage-reseau/public/router.php` | `shared/public/router.php` | 1 | Partagé |
| `pilotage-reseau/public/suivi/` | `consultant/public/suivi/` | 3 | Pilotage consultant |
| `pilotage-reseau/public/tablette/` | `panel/public/tablette/` | 70 | Dashboard franchisé |
| `pilotage-reseau/public/task/` | `consultant/public/task/` | 3 | Pilotage consultant |
| `pilotage-reseau/public/uploads/` | `shared/public/uploads/` | 3 | Partagé |
| `pilotage-reseau/public/visites/` | `consultant/public/visites/` | 3 | Pilotage consultant |
| `pilotage-reseau/scripts/dimona-smoke.php` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/sonde-vouchers.trigger` | `marketing/sonde-vouchers.trigger` | 1 | Marketing |
| `pilotage-reseau/sql/grants.sql` | `shared/sql/grants.sql` | 1 | Partagé |
| `pilotage-reseau/sql/hr_dimona.sql` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/sql/mar-referentiels.sql` | `marketing/sql/mar-referentiels.sql` | 1 | Marketing |
| `pilotage-reseau/sql/schema.sql` | `shared/sql/schema.sql` | 1 | Partagé |
| `pilotage-reseau/sql/seed.sql` | `shared/sql/seed.sql` | 1 | Partagé |
| `pilotage-reseau/src/Db.php` | `shared/src/Db.php` | 1 | Partagé |
| `pilotage-reseau/src/acces.php` | `shared/src/acces.php` | 1 | Partagé |
| `pilotage-reseau/src/analyse_produits.php` | `consultant/src/analyse_produits.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/anthropic.php` | `shared/src/anthropic.php` | 1 | Partagé |
| `pilotage-reseau/src/api_module.php` | `panel/src/api_module.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/assistant_magasin.php` | `panel/src/assistant_magasin.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/assortiment.php` | `consultant/src/assortiment.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/audits.php` | `consultant/src/audits.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/auth.php` | `shared/src/auth.php` | 1 | Partagé |
| `pilotage-reseau/src/benchmark.php` | `consultant/src/benchmark.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/benchmark_import.php` | `consultant/src/benchmark_import.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/brand_guard.php` | `marketing/src/brand_guard.php` | 1 | Marketing |
| `pilotage-reseau/src/budget_crm.php` | `consultant/src/budget_crm.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/ca_mail.php` | `consultant/src/ca_mail.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/ca_net_mois.php` | `consultant/src/ca_net_mois.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/cadence.php` | `consultant/src/cadence.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/caisse.php` | `consultant/src/caisse.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/canaux_offres.php` | `marketing/src/canaux_offres.php` | 1 | Marketing |
| `pilotage-reseau/src/checklists_codes.php` | `shared/src/checklists_codes.php` | 1 | Partagé |
| `pilotage-reseau/src/cibles_reseau.php` | `shared/src/cibles_reseau.php` | 1 | Partagé |
| `pilotage-reseau/src/coach_objectifs.php` | `panel/src/coach_objectifs.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/commandes.php` | `consultant/src/commandes.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/con_tables.php` | `consultant/src/con_tables.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/connecteurs.php` | `shared/src/connecteurs.php` | 1 | Partagé |
| `pilotage-reseau/src/controles_qualite.php` | `consultant/src/controles_qualite.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/cq_mail.php` | `consultant/src/cq_mail.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/crm/` | `marketing/src/crm/` | 1 | Marketing |
| `pilotage-reseau/src/croisements.php` | `consultant/src/croisements.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/data/` | `shared/src/data/` | 9 | Partagé |
| `pilotage-reseau/src/doc.php` | `shared/src/doc.php` | 1 | Partagé |
| `pilotage-reseau/src/documents.php` | `consultant/src/documents.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/dossier.php` | `consultant/src/dossier.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/dossiers_fichiers.php` | `consultant/src/dossiers_fichiers.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/endpoints.php` | `shared/src/endpoints.php` | 1 | Partagé |
| `pilotage-reseau/src/erp_api.php` | `shared/src/erp_api.php` | 1 | Partagé |
| `pilotage-reseau/src/erp_cles.php` | `shared/src/erp_cles.php` | 1 | Partagé |
| `pilotage-reseau/src/fbcontrole.php` | `marketing/src/fbcontrole.php` | 1 | Marketing |
| `pilotage-reseau/src/fidelite.php` | `marketing/src/fidelite.php` | 1 | Marketing |
| `pilotage-reseau/src/fidelite_app.php` | `marketing/src/fidelite_app.php` | 1 | Marketing |
| `pilotage-reseau/src/fidelite_bingo.php` | `marketing/src/fidelite_bingo.php` | 1 | Marketing |
| `pilotage-reseau/src/fidelite_cartes.php` | `marketing/src/fidelite_cartes.php` | 1 | Marketing |
| `pilotage-reseau/src/fidelite_profils.php` | `marketing/src/fidelite_profils.php` | 1 | Marketing |
| `pilotage-reseau/src/fil_franchise.php` | `consultant/src/fil_franchise.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/formation.php` | `formation/src/formation.php` | 1 | Formation |
| `pilotage-reseau/src/formation_postes.php` | `formation/src/formation_postes.php` | 1 | Formation |
| `pilotage-reseau/src/franchise/` | `panel/src/` | 16 | Dashboard franchisé |
| `pilotage-reseau/src/franchises.php` | `consultant/src/franchises.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/google_api.php` | `marketing/src/google_api.php` | 1 | Marketing |
| `pilotage-reseau/src/grands_prix.php` | `panel/src/grands_prix.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/helpdesk.php` | `consultant/src/helpdesk.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/historique_ventes.php` | `panel/src/historique_ventes.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/hr/` | (reste en place) | 102 | Hors périmètre |
| `pilotage-reseau/src/ia_assistant.php` | `consultant/src/ia_assistant.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/installer.php` | `shared/src/installer.php` | 1 | Partagé |
| `pilotage-reseau/src/invendus.php` | `consultant/src/invendus.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/inventaires.php` | `consultant/src/inventaires.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/jours_creux.php` | `marketing/src/jours_creux.php` | 1 | Marketing |
| `pilotage-reseau/src/kpi_table.php` | `consultant/src/kpi_table.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/kpis.php` | `consultant/src/kpis.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/leviers.php` | `consultant/src/leviers.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/linkedin_api.php` | `marketing/src/linkedin_api.php` | 1 | Marketing |
| `pilotage-reseau/src/manuel_chiffres_cles.php` | `formation/src/manuel_chiffres_cles.php` | 1 | Formation |
| `pilotage-reseau/src/marketing_hub.php` | `marketing/src/marketing_hub.php` | 1 | Marketing |
| `pilotage-reseau/src/marketing_sources.php` | `marketing/src/marketing_sources.php` | 1 | Marketing |
| `pilotage-reseau/src/marketing_tables.php` | `marketing/src/marketing_tables.php` | 1 | Marketing |
| `pilotage-reseau/src/matiere_fiche.php` | `panel/src/matiere_fiche.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/mesure.php` | `marketing/src/mesure.php` | 1 | Marketing |
| `pilotage-reseau/src/meta_graph.php` | `marketing/src/meta_graph.php` | 1 | Marketing |
| `pilotage-reseau/src/metadonnee.php` | `marketing/src/metadonnee.php` | 1 | Marketing |
| `pilotage-reseau/src/mkt_agences.php` | `marketing/src/mkt_agences.php` | 1 | Marketing |
| `pilotage-reseau/src/mkt_annexes.php` | `marketing/src/mkt_annexes.php` | 1 | Marketing |
| `pilotage-reseau/src/mkt_brief.php` | `marketing/src/mkt_brief.php` | 1 | Marketing |
| `pilotage-reseau/src/mkt_types.php` | `marketing/src/mkt_types.php` | 1 | Marketing |
| `pilotage-reseau/src/motivation.php` | `panel/src/motivation.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/newsletter.php` | `marketing/src/newsletter.php` | 1 | Marketing |
| `pilotage-reseau/src/newsletter_envoi.php` | `marketing/src/newsletter_envoi.php` | 1 | Marketing |
| `pilotage-reseau/src/notes_jour.php` | `panel/src/notes_jour.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/objectifs_produits.php` | `marketing/src/objectifs_produits.php` | 1 | Marketing |
| `pilotage-reseau/src/panel_api.php` | `shared/src/panel_api.php` | 1 | Partagé |
| `pilotage-reseau/src/panel_ventes.php` | `shared/src/panel_ventes.php` | 1 | Partagé |
| `pilotage-reseau/src/plan.php` | `consultant/src/plan.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/plan_pdf.php` | `consultant/src/plan_pdf.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/planning_moi.php` | `panel/src/planning_moi.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/planning_sync.php` | `panel/src/planning_sync.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/plano_proposition.php` | `consultant/src/plano_proposition.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/plano_std.php` | `consultant/src/plano_std.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/pnl_ht.php` | `shared/src/pnl_ht.php` | 1 | Partagé |
| `pilotage-reseau/src/pnl_mois.php` | `consultant/src/pnl_mois.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/pointage.php` | `panel/src/pointage.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/pointages_mail.php` | `panel/src/pointages_mail.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/ponderation.php` | `shared/src/ponderation.php` | 1 | Partagé |
| `pilotage-reseau/src/primes.php` | `panel/src/primes.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/primes_app.php` | `panel/src/primes_app.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/primes_b2b.php` | `panel/src/primes_b2b.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/primes_fiches.php` | `panel/src/primes_fiches.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/prod_manque.php` | `consultant/src/prod_manque.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/prod_utilisation.php` | `consultant/src/prod_utilisation.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/production_flux.php` | `panel/src/production_flux.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/production_plan.php` | `panel/src/production_plan.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/produit_parametrage.php` | `consultant/src/produit_parametrage.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/produits_coef.php` | `panel/src/produits_coef.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/profil_employe.php` | `panel/src/profil_employe.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/promos_bons.php` | `marketing/src/promos_bons.php` | 1 | Marketing |
| `pilotage-reseau/src/promos_doc.php` | `marketing/src/promos_doc.php` | 1 | Marketing |
| `pilotage-reseau/src/promos_magasin.php` | `marketing/src/promos_magasin.php` | 1 | Marketing |
| `pilotage-reseau/src/promos_reseau.php` | `marketing/src/promos_reseau.php` | 1 | Marketing |
| `pilotage-reseau/src/promos_ventes.php` | `marketing/src/promos_ventes.php` | 1 | Marketing |
| `pilotage-reseau/src/prospection.php` | `marketing/src/prospection.php` | 1 | Marketing |
| `pilotage-reseau/src/push.php` | `shared/src/push.php` | 1 | Partagé |
| `pilotage-reseau/src/rapports.php` | `consultant/src/rapports.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/rayons_photos.php` | `consultant/src/rayons_photos.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/recette_produit.php` | `panel/src/recette_produit.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/recurrence_pwa.php` | `consultant/src/recurrence_pwa.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/referentiel.php` | `formation/src/referentiel.php` | 1 | Formation |
| `pilotage-reseau/src/referentiel_guides.php` | `formation/src/referentiel_guides.php` | 1 | Formation |
| `pilotage-reseau/src/resultat.php` | `shared/src/resultat.php` | 1 | Partagé |
| `pilotage-reseau/src/resultat_pdf.php` | `shared/src/resultat_pdf.php` | 1 | Partagé |
| `pilotage-reseau/src/revue_soir.php` | `panel/src/revue_soir.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/scoring.php` | `consultant/src/scoring.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/scouting_osm.php` | `marketing/src/scouting_osm.php` | 1 | Marketing |
| `pilotage-reseau/src/sms.php` | `shared/src/sms.php` | 1 | Partagé |
| `pilotage-reseau/src/smtp.php` | `shared/src/smtp.php` | 1 | Partagé |
| `pilotage-reseau/src/social_publication.php` | `marketing/src/social_publication.php` | 1 | Marketing |
| `pilotage-reseau/src/sop.php` | `formation/src/sop.php` | 1 | Formation |
| `pilotage-reseau/src/stats_ventes.php` | `panel/src/stats_ventes.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/structure_gamme.php` | `consultant/src/structure_gamme.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/suivi.php` | `consultant/src/suivi.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/suivi_sources.php` | `consultant/src/suivi_sources.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/tablette.php` | `panel/src/tablette.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/tablette_objectifs.php` | `panel/src/tablette_objectifs.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/tablette_remarques.php` | `panel/src/tablette_remarques.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/src/tablettes.php` | `formation/src/tablettes.php` | 1 | Formation |
| `pilotage-reseau/src/taches_suivi.php` | `consultant/src/taches_suivi.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/task_cadre.php` | `consultant/src/task_cadre.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/ventes.php` | `consultant/src/ventes.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/ventes_pro.php` | `consultant/src/ventes_pro.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/ventes_semaines.php` | `shared/src/ventes_semaines.php` | 1 | Partagé |
| `pilotage-reseau/src/verification.php` | `consultant/src/verification.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_base.php` | `consultant/src/verification_base.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_ca.php` | `consultant/src/verification_ca.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_correction.php` | `consultant/src/verification_correction.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_flux.php` | `consultant/src/verification_flux.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_matiere_edition.php` | `consultant/src/verification_matiere_edition.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_matieres.php` | `consultant/src/verification_matieres.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_matieres_prix.php` | `consultant/src/verification_matieres_prix.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/verification_mouvements.php` | `consultant/src/verification_mouvements.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visibilite_google.php` | `marketing/src/visibilite_google.php` | 1 | Marketing |
| `pilotage-reseau/src/visites.php` | `consultant/src/visites.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_cadre.php` | `consultant/src/visites_cadre.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_conformite.php` | `consultant/src/visites_conformite.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_equipe.php` | `consultant/src/visites_equipe.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_evaluation.php` | `consultant/src/visites_evaluation.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_kpi.php` | `consultant/src/visites_kpi.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_outils.php` | `consultant/src/visites_outils.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/visites_questions.php` | `consultant/src/visites_questions.php` | 1 | Pilotage consultant |
| `pilotage-reseau/src/writes.php` | `shared/src/writes.php` | 1 | Partagé |
| `pilotage-reseau/tests/acces/` | `shared/tests/acces/` | 1 | Partagé |
| `pilotage-reseau/tests/developpement/` | `shared/tests/developpement/` | 1 | Partagé |
| `pilotage-reseau/tests/fidelite/` | `marketing/tests/fidelite/` | 7 | Marketing |
| `pilotage-reseau/tests/formation/` | `formation/tests/formation/` | 2 | Formation |
| `pilotage-reseau/tests/hr/` | (reste en place) | 38 | Hors périmètre |
| `pilotage-reseau/tests/visibilite/` | `marketing/tests/visibilite/` | 2 | Marketing |
| `pilotage-reseau/tools/assistant-src/` | `marketing/tools/assistant-src/` | 56 | Marketing |
| `pilotage-reseau/tools/dashboard-api-index.py` | `panel/tools/dashboard-api-index.py` | 1 | Dashboard franchisé |
| `pilotage-reseau/tools/design-system/` | `shared/tools/design-system/` | 1 | Partagé |
| `pilotage-reseau/tools/essai_voucher.php` | `marketing/tools/essai_voucher.php` | 1 | Marketing |
| `pilotage-reseau/tools/formation/` | `formation/tools/formation/` | 6 | Formation |
| `pilotage-reseau/tools/hr-dimona/` | (reste en place) | 1 | Hors périmètre |
| `pilotage-reseau/tools/metadonnee-essai/` | `marketing/tools/metadonnee-essai/` | 5 | Marketing |
| `pilotage-reseau/tools/sonde_antigaspi_halle.php` | `panel/tools/sonde_antigaspi_halle.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/tools/sonde_assistant_halle.php` | `panel/tools/sonde_assistant_halle.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/tools/sonde_cartes_fidelite.php` | `marketing/tools/sonde_cartes_fidelite.php` | 1 | Marketing |
| `pilotage-reseau/tools/sonde_clients_bon.php` | `marketing/tools/sonde_clients_bon.php` | 1 | Marketing |
| `pilotage-reseau/tools/sonde_dashboard_halle.php` | `panel/tools/sonde_dashboard_halle.php` | 1 | Dashboard franchisé |
| `pilotage-reseau/tools/sonde_fidelite_halle.php` | `marketing/tools/sonde_fidelite_halle.php` | 1 | Marketing |
| `pilotage-reseau/tools/sonde_profils_halle.php` | `marketing/tools/sonde_profils_halle.php` | 1 | Marketing |
| `pilotage-reseau/tools/sonde_vouchers.php` | `marketing/tools/sonde_vouchers.php` | 1 | Marketing |
| `pilotage-reseau/tools/tablette-LISEZMOI.md` | `panel/tools/tablette-LISEZMOI.md` | 1 | Dashboard franchisé |

Les fichiers qui servent plusieurs domaines et ne peuvent pas être découpés sans refonte vont dans `shared/` :
`src/endpoints.php` (9 175 lignes, toutes les lectures de tous les écrans), `src/writes.php` (toutes les écritures),
`src/installer.php` (toutes les créations de tables), `sql/schema.sql` et `sql/seed.sql`, `public/index.html`,
`public/assets/js/app.js` et `templates.js` (la SPA du cockpit, tous domaines). Les découper est un chantier à part
(question A13) : la règle « un fichier = un seul domaine » n'est donc tenue que pour les autres fichiers.

---

## 3. Clés et intégrations

### 3.1 `erp_cles` (= `erp_keys`) : en clair ou chiffrée ?

**En clair.** La colonne `valeur` (MEDIUMTEXT) porte la valeur telle quelle ; `secret = 1` ne fait que la masquer à
l'écran et `indice` garde les 4 derniers caractères. `docs/PORTAILS.md` prévoit qu'un portail chiffre « au besoin »
ses propres lignes (Marketing : `mar.{module}.{nom}`), les lignes `commun` restant en clair. **Il n'existe donc pas de
clé de chiffrement d'`erp_cles`** dans ce dépôt. Migrer d'autres secrets vers `erp_cles` les met en clair dans une
table lue par plusieurs applications : à garder en tête (question A11).

Accès aujourd'hui : un helper, `src/erp_cles.php` (`erpCle()`, `erpCleEcrire()`), qui ne lit que les portails `commun`
et `pil`. **Deux lectures directes en SQL** à remplacer par ce helper (exigé par la mission) : `src/metadonnee.php:1005`
(clés Meta) et `tools/sonde_assistant_halle.php` (sonde, lit aussi la colonne `valeur`). Un test simule la table
(`bin/tests_metadonnee.php:26`).

### 3.2 Tableau des clés

Noms et emplacements seulement. « Domaines » : qui les utilise. Action : **déjà** dans `erp_keys`, **à migrer** vers
`erp_keys`, **doublon** à supprimer (une fois la valeur reprise), **reste** (connexion à la base, secret interne,
cache, ou hors périmètre).

| Clé | Où aujourd'hui | Domaines | Action proposée |
|---|---|---|---|
| `meta_app_id`, `meta_app_secret`, `meta_page_id`, `meta_page_token`, `meta_system_token` | `erp_cles` (commun) ; lues en SQL direct par `src/metadonnee.php` | Marketing | **déjà** ; lecture à passer par le helper |
| `smtp_host`, `smtp_port`, `smtp_secure`, `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_from_name` | `erp_cles` (commun), `src/smtp.php` | tous | **déjà** |
| `anthropic_api_key`, `anthropic_workspace_id` | `erp_cles` (commun), `src/anthropic.php` ; lue en SQL direct (colonne `valeur` comprise) par `tools/sonde_assistant_halle.php` | Dashboard, Pilotage | **déjà** |
| `tfbuddy_base`, `tfbuddy_phone`, `tfbuddy_password` | `erp_cles` (commun), écrites par `src/writes.php`, lues par `src/panel_api.php` | tous | **déjà** |
| `erp_jeton_service` | `erp_cles` (commun) | partagé (API d'intégration), Dashboard | **déjà** |
| `rec_api_url`, `pil_api_url`, `mar_api_url` | `erp_cles` (commun) | partagé, Dashboard | **déjà** (noms gardés : lus par les autres dépôts, question A12) |
| `pil_boite_{magasin}_adresse`, `_utilisateur`, `_mot_de_passe`, `_imap`, `_nom` | `erp_cles` (portail `pil`) | Dashboard | **déjà** (noms et portail gardés, question A12) |
| réglage `anthropic` (clé, espace) | `ceo_app_setting`, `src/anthropic.php` (prime sur `erp_cles`) | Pilotage, Dashboard | **doublon** de `anthropic_api_key` |
| réglage `panelApi` (adresse, téléphone, mot de passe) ; `PANEL_API_BASE`, `PANEL_API_PHONE`, `PANEL_API_PASSWORD` ; `config.php › panelApi` | `ceo_app_setting`, environnement, `config.php` | tous | **doublon** de `tfbuddy_*` |
| réglage `erpAdmin` (adresse, téléphone, mot de passe du compte admin ERP) ; `ERP_ADMIN_BASE/PHONE/PASSWORD` ; `config.php › erpAdmin` | `ceo_app_setting`, environnement, `config.php` ; `src/erp_api.php`, `src/writes.php` | Dashboard, Pilotage | **à migrer** (nouvelles clés communes `erp_admin_*`) |
| réglage `google` ; `GOOGLE_API_KEY` ; `config.php › googleApiKey` | `ceo_app_setting`, environnement, `config.php` ; `src/google_api.php`, `src/writes.php` | Marketing (réputation) | **à migrer** (une clé commune, ex. `google_places_api_key`) ; les deux autres = doublons |
| réglage `smtp` | `ceo_app_setting`, `src/smtp.php` (prime s'il est complet) | tous | **doublon** de `smtp_*` |
| réglages `mdMeta` (jeton de vérification du webhook), `mdMetaSecret`, `mdMetaJeton`, `mdMetaJetonCrm`, `mdJeton`, `mdSmsJeton` | `ceo_app_setting`, `src/metadonnee.php` | Marketing | **à migrer** (portail `mar`) ; `mdMetaSecret` / `mdMetaJeton` probablement doublons de `meta_app_secret` / `meta_page_token` (à comparer à l'étape 0, sans afficher) |
| `META_SYSTEM_TOKEN`, `META_APP_SECRET`, `META_VERIFY_TOKEN` ; `config.php › meta.*` | environnement, `config.php` | Marketing | **doublons** de `meta_*` |
| réglage `brandGuard` (accès Meta) | `ceo_app_setting`, `src/meta_graph.php:38` | Marketing | **à migrer** ou doublon de `meta_system_token` |
| réglage `socialPublication` ; `LINKEDIN_ACCESS_TOKEN` | `ceo_app_setting`, environnement, `src/linkedin_api.php` | Marketing | **à migrer** (`linkedin_access_token`, portail `mar`) |
| `SMSAPI_TOKEN`, `SMSAPI_BASE` ; `config.php › smsapi` | environnement, `config.php` (MétaDonnée) | Marketing | **à migrer** |
| `TWILIO_SID`, `TWILIO_TOKEN`, `SMS_EXPEDITEUR` ; `config.php › sms` | environnement, `config.php` (SMS des primes) | Dashboard | **à migrer** |
| réglage `marketingApi` ; `config.php › marketingApi` | `ceo_app_setting`, `config.php` | Dashboard | **doublon** de `mar_api_url` |
| réglage `marketingServiceTokenFile` (chemin d'un fichier de jeton, défaut en dur `/var/www/private/marketing/service-token`) | `src/endpoints.php:4822` | Marketing, Dashboard | **à migrer** (jeton dans `erp_cles`) ou doublon de `erp_jeton_service` |
| réglage `pushVapid` (paire de clés Web Push) | `ceo_app_setting`, `src/push.php` | partagé | **à migrer** (question A11) |
| `MAR_ERP_API_BASE`, `MAR_ERP_API_TOKEN` (copie du module Marketing, fichier `MAR_ENV_FILE`) | fichier d'environnement du serveur, `public/api/marketing` | Marketing | **à migrer** ; probable doublon de `tfbuddy_*` |
| réglages `pwaBase`, `erpUrl`, `espaceBoutiqueUrl` ; `COCKPIT_PWA_BASE`, `COCKPIT_ERP_URL`, `COCKPIT_ESPACE_BOUTIQUE_URL` | `ceo_app_setting`, environnement, `config.php` | Pilotage, Dashboard | **à migrer** (adresses d'autres applications, comme `rec_api_url`) — question A11 |
| réglages `authSecret`, `authPasswordHash`, `boiteSecret`, `accesCodes`, `rapportsJeton`, `visitesJeton`, `nlJeton`, `socialJeton`, `auditsJeton`, `boiteJeton` ; `aia_reglage › sel` | `ceo_app_setting`, `aia_reglage` | tous | **reste** : secrets internes (signature de sessions et de liens, mot de passe haché, codes d'accès), pas des clés d'intégration — question A11 |
| réglages `panelApiToken`, `erpAdminToken`, `foodCostApi` | `ceo_app_setting` | partagé | **reste** : caches (jeton de session obtenu, chiffres), pas des clés |
| réglages `cockpitBase`, `urlPublique`, `formationUrl`, `cqMailBase` ; `COCKPIT_URL`, `FORMATION_URL` | `ceo_app_setting`, environnement | tous | **reste** : adresses du cockpit lui-même (configuration) |
| `TABLETTES_CODE` (fichier privé `tablettes_code`) | environnement, `config.php`, `/var/lib/consulant_bo/prive` | Formation | **reste** : code de gestion interne (question A11) |
| `CB_SEAL_KEY_FILE`, `CONSOLE_BO_ACCOUNTS` ; `config/console_bo_accounts.sealed` (commis, scellé) | environnement, secret GitHub, dépôt | partagé | **reste** : comptes de la console, chiffrés hors dépôt |
| `HR_*`, `hr.masterKey`, `hr_dimona.key`, secret GitHub `HR_DIMONA_MASTER_KEY` | environnement, dossier privé | RH | **reste** (hors périmètre) |
| secrets de la copie de l'ERP (`deepl_api_key`, `smsapi_token`, `anthropic_api_key` dans son propre magasin de secrets ; `app/.env`) | base `erp`, serveur | ERP | **reste** (hors périmètre) — doublons possibles de `erp_cles`, signalés |

**Restent dans l'environnement, sans bouger** (listés seulement) : `COCKPIT_DB_HOST`, `COCKPIT_DB_PORT`,
`COCKPIT_DB_NAME`, `COCKPIT_DB_USER`, `COCKPIT_DB_PASSWORD` (aussi secrets GitHub `COCKPIT_DB_USER` /
`COCKPIT_DB_PASSWORD`) ; `MAR_DB_HOST/PORT/NAME/USER/PASSWORD/SOCKET` (copie Marketing) ; `HR_DB_DSN/USER/PASSWORD` ;
`DEPLOY_SSH_PASSWORD` (secret GitHub du déploiement). Clé de chiffrement d'`erp_cles` : **aucune** (elle est en clair).

### 3.3 URL d'intégration écrites en dur (la mission veut une variable de configuration)

À lister, pas à corriger dans cette mission (sinon changement de comportement) — question A16 :

- `https://atelierby.tfbuddy.com/api/v1` comme **valeur par défaut** : `src/panel_api.php:56`, `src/erp_api.php:47`,
  `src/verification.php:862`, `apps/employee/config/app.php:45` ;
- liens vers l'ERP en dur : `src/ventes.php:1577` (`VENTE_APPLI_URL`), `src/metadonnee.php:198` (lien d'un lead),
  `src/ca_mail.php:67`, `:458`, `:1042` (portail fournisseur, réquisitions, commandes) ;
- `https://185.180.206.46/consulant_bo/employee/` : `src/primes_fiches.php:21` (`PRIMES_FICHES_APP`) ;
- `https://webshop.atelierby.be` : `public/assets/js/newsletter.js:32` ; `https://atelierby.be` : `src/benchmark.php:658` ;
- sondes et outils : `tools/sonde_vouchers.php` (`atelierby.tfbuddy.com`, `test.tfbuddy.com`),
  `tools/assistant-src` (`test.tfbuddy.com`), `bin/deploy.sh` (adresse du serveur par défaut), `.github/workflows/*.yml`
  (adresse du serveur : configuration du déploiement, normal).

Aucun **vrai** secret n'est commis : la seule « clé » Anthropic trouvée (`apps/production/deploy/server.sh:700`) est une
fausse clé volontaire d'un diagnostic ; les autres correspondances sont des jeux d'essai ou des noms de clés.

---

## 4. Liste « à conserver »

Après la phase 3, la base ne doit plus contenir que :

1. **Les tables des quatre domaines** : toutes les `mar_*` (y compris celles du module Marketing du dépôt `marketing`,
   lues et écrites ici par sa copie `public/api/marketing`), `for_*`, `con_*`, `panel_*` ;
2. **Les tables partagées** : `ceo_action_usage`, `ceo_app_setting`, `ceo_connecteur`, `ceo_ecran_vue`, `ceo_journal_entry`, `ceo_push_abonnement`, `ceo_shop`, `erp_cles` ;
3. **Les tables d'autres applications lues par les quatre domaines** (liste au §1) : sans elles, les écrans des quatre
   domaines tombent, et le contrôle bloquant de la phase 4 échoue ;
4. **Tables techniques** : ce dépôt n'en crée aucune (pas d'outil de migration ; l'empreinte du schéma vit dans
   `ceo_app_setting`). Le module Marketing a `mar_schema_migration` (préfixe `mar_`, donc déjà conservée). Je propose
   de garder l'historique des nouvelles migrations dans `ceo_app_setting` aussi, sans nouvelle table (question A10).
5. **Recommandé (question A3)** : toutes les tables des autres applications de la même base, même non lues ici
   (`crm_*`, reste du panel TF Buddy, `mac_*`, `sig_*`, `lp_*`, `mar_sa_*` de Social…) : elles ne sont pas à nous.

---

## 5. Liste « à supprimer en phase 4 » (proposition)

Uniquement des tables **créées par ce dépôt**. Les nombres de lignes seront relevés à l'étape 0 puis remis à jour au
moment de la purge. Aucune n'a de clé étrangère venant d'une table conservée **dans le code** (à confirmer en base).

| Table | Lignes | FK venant d’une table conservée | Références restantes (4 domaines + shared) | Pourquoi |
|---|---|---|---|---|
| `ceo_scouting_candidate` | à mesurer | aucune dans le code (à confirmer en base) | `sql/schema.sql`, `src/installer.php` | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_competitor` | à mesurer | aucune dans le code (à confirmer en base) | `sql/schema.sql`, `src/installer.php` | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_etude` | à mesurer | aucune dans le code (à confirmer en base) | `sql/schema.sql`, `src/endpoints.php`, `src/installer.php` | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_population` | à mesurer | aucune dans le code (à confirmer en base) | `sql/schema.sql`, `src/installer.php` | scouting = Recrutement (absent des 4 domaines) |
| `ceo_scouting_tile` | à mesurer | aucune dans le code (à confirmer en base) | `bin/deploy.sh`, `sql/schema.sql`, `src/installer.php` | scouting = Recrutement (absent des 4 domaines) |
| `ceo_x` | à mesurer | aucune dans le code (à confirmer en base) | `bin/tests.php` | table de test (bin/tests.php), jamais en base normalement |
| `hr_dimona_audit` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_credentials` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_declarations` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_employer_shops` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_employers` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_meta` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_periods` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_tokens` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `hr_dimona_workers` | à mesurer | aucune dans le code (à confirmer en base) | aucune | module RH / Dimona |
| `ceo_assistant_question` | à mesurer (absente ?) | aucune dans le code (à confirmer en base) | `src/assistant_magasin.php`, `src/franchise/assistant_ia.php` | obsolète : reprise une fois dans aia_question (src/franchise/assistant_ia.php) ; plus écrite |
| `ceo_parcours` | à mesurer (absente ?) | aucune dans le code (à confirmer en base) | `src/formation_postes.php` | obsolète : effacée par src/formation_postes.php au déploiement (lignes gardées dans ceo_app_setting) |
| `ceo_parcours_etape` | à mesurer (absente ?) | aucune dans le code (à confirmer en base) | `src/formation_postes.php` | obsolète : idem |
| `ceo_prod_planogram` | à mesurer (absente ?) | aucune dans le code (à confirmer en base) | `src/installer.php` | obsolète : reprise dans pla_placement par planoMigrer() (src/installer.php) |

À ajouter s'ils sont encore en base (l'étape 0 le dira) : les **anciens noms** que les registres de migration
gardent en mémoire (`ceo_fb_post`, `ceo_fb_finding`, `ceo_nl_*`, `ceo_campagne_*`, `ceo_mkt_annexe*`, `ceo_visite*`,
`ceo_consultant*`, `ceo_msp`, `ceo_scoring_*`, `ceo_tache_jour*`, `ceo_rayon_photo`, `ceo_franchise_meteo`,
`ceo_equipe_releve`, `ceo_shop_review`, `ceo_shop_reputation`, `ceo_plano_meuble/niveau/note/slot/zone`) : ils n'existent
que si une migration a trouvé « les deux pleines » et s'est arrêtée — dans ce cas c'est un doublon à trancher avec
vous, pas à effacer d'office.

Le module RH (`hr_dimona_*`) et le scouting (`ceo_scouting_*`) n'y sont que si vous le confirmez (questions A4 et A5).

---

## 6. Cas ambigus : recommandation et question

| # | Cas | Ma recommandation | La question |
|---|---|---|---|
| A1 | La mission contredit le registre du dépôt (06–07/11 : `rec_` / `mar_` / `pil_` / `frm_`, dossiers `recrutement-developpement/`, `pilotage-reseau/`, `formations/`). | La mission prévaut. À l'étape 8, je réécris `CLAUDE.md`, `README.md` et `docs/PORTAILS.md` pour qu'ils décrivent les quatre domaines de la mission. | Confirmez-vous que cette mission **remplace** la décision du 06/11 (y compris : le Recrutement n'est plus un des quatre domaines) ? |
| A2 | `erp_keys` n'existe pas ; la table est `erp_cles`. | `erp_keys` = `erp_cles`, **non renommée** : le CRM, le Marketing et Social la lisent sous ce nom. | D'accord pour garder le nom `erp_cles` ? |
| A3 | `atelierby_db` est partagée avec d'autres applications ; la base `erp` (copie de l'ERP) est à part. | Purge limitée aux tables créées par ce dépôt (§5). Jamais les tables d'une autre application ; la base `erp` hors champ. | Quelle base purger, et confirmez-vous qu'on ne touche pas aux tables des autres applications ? |
| A4 | Recrutement : `ceo_scouting_*` (5 tables, créées ici) ; `crm_*` (CRM). | `crm_*` : jamais. `ceo_scouting_*` : à supprimer **seulement** si le Recrutement en a une copie ; sinon conservées, hors périmètre. | Que fait-on des données du scouting ? |
| A5 | Module RH / Dimona (`hr_dimona_*`, `src/hr/`, `public/hr/`, déclarations ONSS, certificats). | Le garder en place, hors périmètre, **non purgé** : ce sont des déclarations légales et des certificats. | Le module RH reste-t-il, ou est-il à supprimer (tables + code) en phase 4 ? |
| A6 | App employés, primes, pointage, grands prix (`mot_*`, `apps/employee`, `public/employee`, `public/borne`). | **Dashboard franchisé** (`panel_mot_*`) : l'équipe du magasin, au quotidien. | Dashboard franchisé ou Pilotage consultant ? |
| A7 | Le préfixe `panel_` : dans ce code, « panel » désigne déjà le panel TF Buddy (`src/panel_api.php`, réglage `panelApi`, colonnes `panel_id`, `panel_note`), et `atelierby_db` est « la base du panel ». | Garder `panel_` comme demandé, en vérifiant à l'étape 0 qu'aucune table `panel_*` n'existe déjà. | `panel_` confirmé, ou un autre préfixe (ex. `fra_`, `dash_`) pour éviter la confusion ? |
| A8 | Dossiers à la racine et déploiement. | Disposition du §2 ; **un seul déploiement**, Apache sert chaque page à son adresse actuelle. Les fichiers déposés (`public/uploads/` : photos de visites, réclamations, documents, SOP, planogramme…) restent **au même endroit sur le serveur**, désigné par une seule constante partagée. | D'accord pour cette disposition, et pour garder un seul déploiement (un déploiement par domaine serait une étape ultérieure) ? |
| A9 | Vérifier les nombres de lignes demande d'exécuter du code **sur le serveur du bac à sable** (étape 0, puis après chaque domaine). Les branches : la mission demande `reorg/structure-domaines`, la session travaille sur `claude/sandbox-reorganize-domains-6ewtpn`, et le serveur ne se met à jour que par un push de `deploy.trigger` sur `claude/deploiement-application-serveur-lmz1bm`. | Une sonde en lecture seule (workflow dédié, comme les `sonde-*` existants) pour l'étape 0 ; puis une livraison sur le serveur de test après chaque domaine, migrations comprises. | M'autorisez-vous (1) à pousser sur `reorg/structure-domaines`, (2) à livrer chaque étape sur le serveur de test (185.180.206.46), ou préférez-vous lancer vous-même les scripts ? |
| A10 | Aucun outil de migration. Le code crée ses tables au premier appel (`CREATE TABLE IF NOT EXISTS`) : si le nouveau code tourne avant le renommage, il crée des tables **vides** au nouveau nom, et le `RENAME` échoue. Le mécanisme existant (`marMigrer`) supprime alors la table vide : c'est un `DROP`. | Migrations `shared/db/migrations/NNN_*.php` avec `up()` / `down()` (un seul `RENAME TABLE … TO …` atomique, données, index et FK suivent), lancées par `shared/bin/migrer.php up|down|statut` **et** en tête de `ensureInstalled()`, avant toute création. Si le nouveau nom existe déjà : **arrêt et rapport, jamais de DROP**. Historique dans `ceo_app_setting` (pas de nouvelle table). | D'accord ? Faut-il des **vues de compatibilité** `ancien_nom → nouveau_nom` pour d'autres applications qui liraient des tables `ceo_` en direct (impossible à vérifier d'ici : leur code n'est pas dans ce dépôt) ? |
| A11 | Quoi migrer vers `erp_cles`, qui est **en clair** ? | Migrer les clés et adresses d'**intégration** marquées « à migrer » au §3.2 ; laisser les secrets **internes** (signature, mot de passe haché, codes d'accès, sel, code des tablettes) et les caches où ils sont. Après copie : la lecture passe par le helper, l'ancien emplacement est vidé. Le chiffrement d'`erp_cles` est un sujet à part. | Validez-vous la colonne « Action » du §3.2, ligne par ligne ? |
| A12 | Valeurs de `portail` dans `erp_cles` (`pil`, `frm`) et noms de clés `pil_api_url`, `pil_boite_*`. | **Inchangés** : d'autres dépôts les lisent ; les renommer est une donnée partagée, pas un rangement. | D'accord ? |
| A13 | Monolithes multi-domaines (`endpoints.php`, `writes.php`, `installer.php`, `schema.sql`, `seed.sql`, `index.html`, `app.js`, `templates.js`). | Dans `shared/` pour cette mission ; leur découpage par domaine plus tard. | D'accord ? |
| A14 | Tables à cheval : helpdesk `hdk_*` (cockpit **et** dashboard), benchmark `ceo_bench*`, KPI `ceo_kpi_*` / `ceo_magasin_fiche`, récurrence PWA `ceo_pwa_*`, prospection `ceo_prospect`, `ceo_sop_tache` (écrite par le cadre des tâches), `ceo_matiere_code` (scan de cartons, créée par `mesure.php`), `ceo_tablette_remarque`, tablette des vendeuses (`public/tablette`). | Helpdesk, benchmark, KPI, récurrence → `con_` ; prospection → `mar_` ; `ceo_sop_tache` → `for_` ; `ceo_matiere_code`, `ceo_tablette_remarque` et la tablette des vendeuses → `panel_`. | Validez-vous ces affectations (notées « ? » au §1) ? |
| A15 | 45 tables du panel TF Buddy, 7 `mac_`, 4 `crm_mo_`, 2 `sig_`, 36 `mar_` du module Marketing, lues ici. | À conserver telles quelles, en lecture seule (§4.3). | D'accord ? |
| A16 | Écarts d'architecture trouvés (§8) : accès directs, URL en dur, routes hors format `/api/v1/{app}/{domaine}/{ressource}`. | **Listés, pas corrigés** dans cette mission (comme demandé) ; aucune URL publique ne change. | D'accord ? |
| A17 | Maquettes (`docs/maquettes/*`, 101 dossiers) : elles citent parfois d'anciens noms de tables. | Déplacées par domaine, **contenu non réécrit** (archives de conception) ; la documentation vivante (`docs/*.md`, `LISEZMOI.md`, `CLAUDE.md`) est mise à jour. | D'accord ? |

Les affectations « ? » de fichiers (même logique) : `benchmark*.php`, `helpdesk.php`, `prospection.php`,
`scouting_osm.php`, `kpi*.php`, `recurrence_pwa.php`, modules primes / pointage / planning de l'app employés,
`tablette*.php`, `promos_magasin.php` (rangé avec les promotions au Marketing, bien que la page soit dans le dashboard),
`stats_ventes.php` (cœur du dashboard), `checklists_codes.php` (référentiel commun → `shared/`).

---

## 7. Lectures croisées qui resteront après le renommage

Le renommage ne change rien aux lectures entre domaines : le code d'un domaine continuera de lire en SQL des tables
d'un autre (même base, même API). Les faire passer par une API serait une nouvelle fonctionnalité, hors mission. À
trancher avant le codage final :

- `ceo_combo` → `con_combo` (Pilotage consultant) lue par Dashboard franchisé
- `ceo_fid_ticket` → `mar_fid_ticket` (Marketing) lue par Dashboard franchisé
- `ceo_matiere_code` → `panel_matiere_code` (Dashboard franchisé) lue par Marketing
- `ceo_md_shop` → `mar_md_shop` (Marketing) lue par Pilotage consultant
- `ceo_plano_std_photo` → `con_plano_std_photo` (Pilotage consultant) lue par Dashboard franchisé
- `ceo_prod_product` → `con_prod_product` (Pilotage consultant) lue par Dashboard franchisé
- `ceo_promo` → `mar_promo` (Marketing) lue par Pilotage consultant
- `ceo_rapport` → `con_rapport` (Pilotage consultant) lue par Dashboard franchisé
- `ceo_rapport_run` → `con_rapport_run` (Pilotage consultant) lue par Dashboard franchisé
- `ceo_reclamation_photo` → `panel_reclamation_photo` (Dashboard franchisé) lue par Marketing
- `ceo_revue_soir` → `panel_revue_soir` (Dashboard franchisé) lue par Pilotage consultant
- `ceo_shop_budget` → `con_shop_budget` (Pilotage consultant) lue par Dashboard franchisé
- `ceo_shop_month_perf` → `con_shop_month_perf` (Pilotage consultant) lue par Marketing, Dashboard franchisé
- `ceo_shop_profil_jour` → `con_shop_profil_jour` (Pilotage consultant) lue par Marketing
- `ceo_sop` → `for_sop` (Formation) lue par Pilotage consultant, Dashboard franchisé
- `ceo_sop_tache` → `for_sop_tache` (Formation) lue par Pilotage consultant
- `con_tache_jour` → `con_tache_jour` (Pilotage consultant) lue par Dashboard franchisé
- `mar_avis_fiche` → `mar_avis_fiche` (Marketing) lue par Pilotage consultant, Dashboard franchisé
- `mar_bg_page` → `mar_bg_page` (Marketing) lue par Dashboard franchisé
- `mar_campagne_annexe` → `mar_campagne_annexe` (Marketing) lue par Dashboard franchisé
- `mar_campagne_objectif` → `mar_campagne_objectif` (Marketing) lue par Pilotage consultant
- `mar_nl_segment` → `mar_nl_segment` (Marketing) lue par Dashboard franchisé
- `mar_social_post` → `mar_social_post` (Marketing) lue par Dashboard franchisé
- `pil_tresorerie_plan` → `panel_tresorerie_plan` (Dashboard franchisé) lue par Pilotage consultant

Et les clés étrangères entre domaines (déclarées dans le code) : `mar_avis`, `mar_avis_fiche`, `mar_social_post` →
`ceo_shop` (partagée) ; `con_project_task`, `con_shop_budget`, `con_shop_month_perf` → `ceo_shop` ; `con_task_issue` →
`con_project_task` ; `con_product_month_sales` → `con_product`. Un `RENAME TABLE` les garde (InnoDB suit les
références) ; aucune ne pointe vers une table à supprimer.

---

## 8. Écarts d'architecture relevés (listés, non corrigés)

**Accès directs à des tables qui ne sont pas les siennes** (la règle : passer par une API) :

- le cockpit lit **en SQL** 45 tables du panel TF Buddy (`transaction`, `transaction_product`, `shops`, `product*`,
  `client*`, `material*`, `stocktaking*`, `user_membership`, `user_profile`, `franchisee_employee_schedule`…) dans une
  98 fichiers (code, scripts et tests : `src/ventes.php`, `src/endpoints.php`, `src/inventaires.php`, `src/commandes.php`,
  `src/primes.php`…) ;
- il lit les tables de la PWA consultant (`mac_task_review` : 21 requêtes, `mac_shop_monthly_pnl`,
  `mac_google_rating_snapshot`…), du CRM (`crm_mo_*` : `src/tablettes.php`, `src/referentiel.php`) et du signage
  (`sig_products` : `src/endpoints.php`) ;
- `public/api/marketing/` est une **copie** de l'API du module Marketing qui écrit les tables `mar_campaign*`,
  `mar_fund*`, `mar_lever`… dont le dépôt `marketing` est aussi propriétaire (deux propriétaires, deux définitions) ;
- **scripts et crons en accès direct à la base** : `bin/seed.php`, `bin/push_stock.php`, `bin/console_bo.php`,
  `bin/db_admin.php`, `bin/carte_sources.php`, `bin/disque_alerte.php`, les crons `bin/*_cron.sh` (via PHP) ; les
  sondes `tools/sonde_*.php` (dont la lecture directe d'`erp_cles`) ;
- `erp_cles` lue en SQL direct par `src/metadonnee.php` (corrigé à l'étape 1, exigé par la mission).

**Routes** : l'API du cockpit est servie à `/consulant_bo/api/cockpit/{ressource}` (et `/formation/api/cockpit/…`),
pas au format `{API_BASE_URL}/api/v1/{app}/{domaine}/{ressource}`. Conformes : `/hr/api/v1/erp/dimona/*`,
`/api/v1/marketing/*` (copie Marketing). **Aucune URL publique ne change dans ce plan.**

---

## 9. Ordre d'exécution (après « GO »)

Chaque étape : un commit (ou une courte série) sur la branche de travail, puis **vérification** : `php -l` sur tous
les PHP ; `bin/tests_noms_globaux.php` et `bin/test_chargement.php` (bloquants en CI) ; tous les `bin/tests_*.php`
(ligne de base aujourd'hui : **48 verts, 4 en échec déjà connus et ignorés par la CI** — `tests_ca_net`,
`tests_catalogue`, `tests_cq_mail`, `tests_documents`) ; `tests/hr/run.php --unit` (vert) ; une recherche qui prouve
qu'aucun ancien nom de table ne reste dans le code hors registres de migration ; puis, sur le serveur de test, **le
nombre de lignes de chaque table renommée identique à celui de l'étape 0**. Si quelque chose casse, je corrige avant
de passer à l'étape suivante.

0. **Ligne de base (lecture seule, sur le serveur de test).** Hôte et nom de la base (sans mot de passe) ; toutes les
   tables d'`atelierby_db` avec `COUNT(*)` exact ; FK réelles (`information_schema.KEY_COLUMN_USAGE`), vues et leurs
   tables (`VIEW_TABLE_USAGE`), déclencheurs, fonctions, événements ; droits du compte (`SHOW GRANTS` : un `RENAME`
   demande `ALTER`, `DROP`, `CREATE`, `INSERT`) ; présence de tables `panel_*` / `for_*` ; anciens noms en double.
   Résultat gardé dans le dossier privé du serveur et résumé dans ce plan (noms et nombres, jamais de valeurs).
1. **`erp_keys`** : un seul accès aux clés, `shared/src/cles.php` (le helper actuel, déplacé, avec la lecture par
   portail), appelé par les quatre domaines ; plus aucune requête SQL directe sur `erp_cles` ; migration des clés
   validées au §3.2 (copie sans affichage, ancien emplacement vidé ensuite).
2. **Socle `shared/`** : création des dossiers, `git mv` des fichiers partagés, mise à jour des `require`, constante
   unique du dossier `uploads` (le dossier du serveur ne bouge pas), coureur de migrations, `deploy.sh` (alias Apache
   par page, mêmes URL, mêmes exclusions de `rsync`), workflows (chemins). Aucune table renommée.
3. **Formation** (19 tables, peu de liens avec le reste, alias `/formation/` à garder).
4. **Marketing** (12 tables + déplacement des modules `mar_`, de la copie `public/api/marketing`).
5. **Dashboard franchisé** (29 tables : `aia_`, `pil_` du dashboard, `mot_` selon A6, historique,
   revue du soir…).
6. **Pilotage consultant** (84 tables : le plus gros lot, en dernier quand les autres sont stables).
7. **Préparer la purge sans rien supprimer** : retirer du code partagé les seules références aux tables de la liste
   « à supprimer » validée (sinon le contrôle bloquant de la phase 4 échoue) — ou, si vous préférez que tout retrait
   de code attende la phase 4, déplacer ce retrait juste avant le contrôle (question A4/A5).
8. **Documentation** : `CLAUDE.md` (racine et un par domaine), `README.md`, `docs/PORTAILS.md`, `LISEZMOI.md`,
   `docs/*.md` ; `REORG_REPORT.md` amorcé ; liste définitive « à supprimer » remise à jour avec les nombres de lignes.
   **STOP** : j'attends « PURGE ».

Phase 4 ensuite, comme vous l'avez écrite : vérification hôte/base, dump complet vérifié et restaurable (emplacement
et taille donnés), contrôle bloquant, suppression des seules tables validées et de leurs objets dépendants, code mort
dans un commit séparé, tests et nombres de lignes, puis export.

---

## 10. Risques identifiés

| Risque | Gravité | Parade |
|---|---|---|
| Purge appliquée à une base partagée : perte des données du CRM, de la PWA consultant, du panel TF Buddy, du signage | très haute | Purge limitée aux tables de ce dépôt (A3) ; contrôle à l'étape 0 et au moment de la purge |
| Nouveau code lancé avant le renommage → tables vides créées au nouveau nom, `RENAME` impossible | haute | Migrations en tête de `ensureInstalled()` et en ligne de commande au déploiement ; arrêt sans `DROP` si le nouveau nom existe |
| Fichiers déposés sur le serveur (`public/uploads/*`) « perdus » par le changement de chemins ou effacés par `rsync --delete` | haute | Le dossier du serveur ne bouge pas ; une constante partagée le désigne ; exclusion `rsync` gardée ; vérifié à la livraison de l'étape 2 |
| URL changées : tablettes installées (`/tablette/`), service workers (`visites/`, `controle/`, `employee/`), liens envoyés par e-mail, alias `/formation/` et `/hr/` | haute | Mêmes URL servies depuis les nouveaux dossiers (alias Apache) ; test de chaque adresse après livraison |
| Autres applications lisant des tables `ceo_` en direct (Social, `pwa_consultant`, CRM : leur code n'est pas ici) | moyenne | Ligne de base : vues existantes ; vues de compatibilité si vous le souhaitez (A10) |
| Droits du compte MySQL insuffisants pour `RENAME` | moyenne | `SHOW GRANTS` à l'étape 0 (le compte est celui du panel, a priori complet ; `sql/grants.sql` est facultatif) |
| Noms de tables écrits en clair dans les chaînes SQL, construits dans des boucles (`motivation.php`, `formation_postes.php`), listés en dur dans `bin/deploy.sh` (remise à zéro, inspection) et `sql/grants.sql` | moyenne | Remplacement outillé et revu, recherche finale « aucun ancien nom hors registres », tests |
| Monolithes partagés (endpoints/writes/installer) touchés à chaque étape → conflits avec le travail en cours d'autres sessions sur la branche de déploiement | moyenne | Étapes courtes, synchronisation avec la branche par défaut avant chacune |
| Secrets migrés vers une table en clair lue par plusieurs applications | moyenne | Migrer seulement les clés d'intégration (A11) ; chiffrement à décider à part |
| Deux propriétaires des tables `mar_campaign*` (copie ici, dépôt `marketing`) | basse | Inchangé dans cette mission ; signalé |
| `ceo_verif_*` (5 noms) décrits dans la doc mais jamais créés | basse | Vérifié à l'étape 0 |

---

## 11. Audit : fichiers morts, doublons, tables vides ou orphelines

- **Fichiers sans aucun appelant** (candidats, rien n'est supprimé avant la phase 4) : `bin/db_admin.php` et
  `bin/mix1000.php` — outils en ligne de commande lancés à la main (documentés dans leur en-tête), pas du code mort au
  sens strict.
- **Doublons de code** : `src/crm/meta_graph.php` (copie déclarée du CRM) ; `public/api/marketing/` (copie du module
  Marketing) ; `public/fidelite-test/` et `public/fidelite-app/` (page de test et app en direct de la fidélité).
- **Doublons de définition** : plusieurs tables sont définies deux fois (`sql/schema.sql` et un `ensure…()` PHP, par ex.
  `ceo_scouting_*`, `mar_avis*`, `pla_*`, `ceo_prod_product`) ; les tables `mar_` des référentiels sont définies ici
  (`sql/mar-referentiels.sql`) **et** par la migration 045 du dépôt `marketing`.
- **Doublons de clés** : voir §3.2 (réglages qui priment sur `erp_cles` : `anthropic`, `smtp`, `panelApi`…).
- **Tables obsolètes** : `ceo_parcours`, `ceo_parcours_etape` (effacées au déploiement par le code), `ceo_assistant_question`
  (reprise dans `aia_question`), `ceo_prod_planogram` (reprise dans `pla_placement`), `ceo_x` (test).
- **Tables vides, orphelines en base** : à mesurer à l'étape 0 (aucun accès base ici).

---

## 12. La routine du matin

Demandée en cours de tâche : « en faire une routine tous les matins ». Mise en place à la fin de cette phase :
chaque matin, une session neuve relance cet audit **en lecture seule** sur le dernier état de la branche par défaut
(`python3 -I reorg/audit_statique.py --ecart`) et les tests hors ligne, puis signale ce qui a bougé depuis cet
inventaire : nouvelles tables, nouveaux fichiers (et leur domaine proposé), nouvelles clés ou URL hors `erp_cles`,
collisions de noms, tests passés au rouge. Elle ne touche ni à la base, ni au serveur, ni aux branches, et n'exécute
jamais les phases 3 ou 4 : elles restent soumises à « GO » et « PURGE ».
