# Base de données « erp » : tables d'origine et tables ajoutées (préfixe `n_`)

Une seule base, **`erp`** (serveur de test : l'ancienne base `erp_production` y a été déplacée le 24/10/2026 par `deploy/server.sh`, données gardées).

- **121 tables d'origine** de l'ERP SL Food : noms inchangés (131 avant le nettoyage du 24/10/2026, qui a supprimé les 10 copies de sauvegarde).
- **116 tables ajoutées** pendant le développement : toutes préfixées **`n_`** (« nouveau »), jusqu'à la migration 069 elles s'appelaient `tfb_…`. Elles se reconnaissent d'un coup d'œil pour l'intégration.

## Installer / intégrer

Sur une base ERP d'origine (copie de production) : `php Bin/tfb_migrate.php apply` depuis `apps/production/app`. Les migrations `migrations/tfb/001 → 087` créent les tables (`tfb_` puis renommées `n_` par la 069), posent les données de départ (paramètres, rôles, modèles d'e-mails, questions du pointage…) et sont enregistrées dans `n_schema_migration` (une migration déjà appliquée ne peut plus être modifiée). `docs/db/n_schema.sql` donne le schéma final des tables `n_` en un seul fichier, pour lecture.

## Ce que le développement change dans les tables d'origine

| Table d'origine | Changement | Migration |
|---|---|---|
| `customer` | colonne ajoutée `communication_lang CHAR(2)` (langue des e-mails / SMS : pl, fr, nl, en), remplie d'après la langue des documents et le pays | 060 |
| `document_wdt` | index `idx_document_wdt_established` (`established_date`) | 019 |
| `document_wdt_product` | index `idx_document_wdt_product_document` (`id_document_wdt`) | 019 |
| `communication_template` | 84 modèles d'e-mails ajoutés (logistique, factures, proforma, rappels, avis de livraison, distribution ; fr, nl, pl) | 006, 061–066 |
| `final_product` | `stock_minimum` aligné sur le stock min. des fiches MTS ; `pre_calculated_raw_price_per_carton` = coût de la recette | 034, 057 |
| `raw_material` | catégories inexistantes corrigées (matières rattachées à une catégorie existante) | 021 |
| `document_proforma` | statut `archived` pour les proformas de plus de 90 jours sans paiement ni annulation | 072 |

### Nettoyage du 24/10/2026 (migrations 074 → 080)

Avant d'appliquer des migrations en attente, `deploy/server.sh` sauvegarde toute la base (`/root/erp_import/sauvegardes`, 5 dernières). **En production, faire la même sauvegarde avant `tfb_migrate.php apply`.**

| Migration | Changement |
|---|---|
| 074 | 10 copies de sauvegarde supprimées : `carton_stock_2`, `customer_order_product_cc`, `document_order_shipping_difference_products_01102026` / `_1709` / `_cc`, `document_proforma_product_cc`, `document_wdt_product_cc`, `final_product_customer_price_cc` / `_cc_01_05`, `final_product_to_compare` |
| 075 | clé primaire `id` auto-incrémentée **invisible** pour les 16 tables qui n'en avaient pas (`carton_log`, `customer_order_product`, `document_wz_product`, `product_recipe_connection`… ; `smp` : `row_id`) : absente de `SELECT *` et des INSERT sans liste de colonnes |
| 076 | orphelins supprimés puis clés étrangères : lignes → en-tête des documents (WDT, WZ, facture, différences, soumission KSeF) en RESTRICT ; lignes de commande et de proforma, commandes franchisés, colis CMR, pièces jointes de réclamation, prix clients et franchisés, historique des prix matière en CASCADE ; `product_recipe.id_product` en SET NULL |
| 077 | dates en texte → `DATE` (NULL autorisé) : `established_date` (WZ, CMR, WDT, proforma, différences), `invoiced_date` (facture, WDT), `shipping_date` (WDT) ; proformas AAAA/MM/JJ converties, écrites désormais en AAAA-MM-JJ |
| 078 | 29 index sur les colonnes de liaison lues souvent (stocks cartons, palettes, réclamations, messages, commandes, documents par client et par produit) |
| 079 | 23 tables passées de `utf8mb4_unicode_ci` à `utf8mb4_general_ci` |
| 080 | tables `n_` vides de fonctions retirées supprimées : `n_customer_route`, `n_employee_competency`, `n_prod_forecast` |

### Fiches de poste (migration 081, 26/10/2026)

`n_position` reçoit `kind` (`erp` = bureau, `atelier` = production et chauffeurs), `id_group` (groupe de production) et `id_reports_to` (responsable, SET NULL) ; 7 postes de l'atelier sont ajoutés (chef d'équipe, boulanger, tourier, pâtissier, cuisinier traiteur, conditionnement-expédition, chauffeur-livreur). `n_position_sheet` (1 ligne par poste) porte la fiche en PL et FR : mission, responsabilités, compétences, exigences, conditions, indicateurs, hygiène et sécurité, relations (une ligne = un point), avec version et validation. `n_prod_employee.id_position` rattache un salarié de production à son poste. Les 12 fiches sont pré-remplies, à relire et valider dans Administracja › Stanowiska i zadania.

### Tablette chauffeur (migration 082, 26/10/2026)

`n_prod_device` reçoit `mode` (`atelier` / `driver`) et `id_carrier_vehicle` (véhicule par défaut d'une tablette chauffeur). `n_delivery_run` : départ et fin de tournée d'un véhicule un jour d'expédition (chauffeur). `n_delivery_stop` : un enregistrement par client et jour d'expédition — `delivered` (réceptionnaire, palettes livrées et rendues) ou `problem` (raison : fermé, refus, casse, manquant, retard, autre), note, position, heure. `n_delivery_media` : signature (PNG) et photo (JPEG) d'un arrêt. Paramètre `driver.photo_max_kb`. Pages : `/driver` (tablette), Logistyka › Dostawy (`/logistics/deliveries`, PDF de preuve de livraison).

### Versions de recette « en vigueur depuis » (migration 087, 26/10/2026)

`n_recipe_version` : une ligne par version d'une recette (`product_recipe`, CASCADE), numérotée (`version_no`, unique par recette). `valid_from` = date à partir de laquelle la version s'applique (NULL pour la version de départ : « depuis toujours », avant le suivi). `source` : `baseline` (version de départ, créée par `Bin/mail_dispatch.php` et juste avant toute modification), `create` (nouvelle recette), `edit` (la recette elle-même), `component` (un półprodukt ou une kompozycja contenue a changé ; `note` = son nom). `composition` (JSON : rendement, unité, matières, półprodukty, préparations — avec les noms pour l'affichage), `ingredients` (JSON : liste aplatie `[[material_id, quantité]]` = `flattened_recipe_ingredient`), `material_cost` (coût au prix du jour du changement), `content_hash` (empreinte sur identifiants et quantités : renommer ne crée pas de version), `created_by` (`user`, SET NULL). Écrite à chaque réécriture de `flattened_recipe_ingredient` (`FlattenedRecipeService`). La rentabilité valorise chaque mois passé avec la version en vigueur à la fin du mois (la dernière version = composition actuelle).

### Alertes et échéances (migration 086, 26/10/2026)

Aucune table : paramètres `alerts.complaint_days` (3 : réclamation nouvelle ou en vérification sans décision → alerte), `alerts.complaint_escalate_days` (7 : alerte rouge) et `alerts.cost_due_day` (10 : à partir de ce jour, alerte pour chaque mois sans coûts saisis depuis le dernier mois saisi). Le contrôle tourne avec `Bin/mail_dispatch.php` et écrit dans `app_notification` (une fois par clé `dedup_key`). Un mois de coûts compte comme saisi dès qu'il a un salaire compté (`cost_month_employee.is_included`) ; un mois ouvert mais vide est désormais remplacé par le dernier mois saisi dans la rentabilité (estimation signalée).

### Nouveaux produits : étapes, essais sur la tablette (migration 085, 26/10/2026)

`n_dev_product` reçoit `id_group` (groupe de production qui teste : seule sa tablette voit le produit ; sans groupe, règle d'avant par section), `stage` (`idea` → `test1` → `test2` → `recipe` → `launch` → `launched`, ou `stopped`), `stage_changed_at`, `target_date`, `recipe_confirmed_at` / `recipe_confirmed_by` (personne identifiée sur la tablette, `n_prod_employee`) et `id_final_product` (produit ERP lancé). `n_dev_trial` : essais n° 1, 2, 3… d'un produit test — planifiés au bureau (date, consignes, groupe) puis faits une seule fois sur la tablette (heure, personne `n_prod_employee`, quantité, résultat `ok` / `fix` / `ko`, ce qu'il faut changer) ; unique (produit, n°). `n_dev_trial_photo` : photos d'un essai (JPEG redimensionné par la tablette, heure de prise de vue), CASCADE. Les produits test déjà « en production » / « terminés » reçoivent l'étape `launch` / `launched`. Le lancement écrit aussi la date dans `n_mgr_launch` (module Kierownik produkcji, rapport mensuel) si elle manque.

### Photos avant / après, consignes de livraison (migration 084, 26/10/2026)

`n_delivery_stop.status` reçoit `arrived` (sur place, déchargement en cours) et `arrived_at` (heure d'arrivée = première photo avant déchargement). `n_delivery_media.kind` reçoit `photo_before` et `photo_after`, avec `taken_at` (heure de prise de vue, aussi incrustée sur l'image par la tablette) et `client_uuid` (unique par arrêt : un envoi rejoué par la file hors ligne ne crée pas de doublon). `n_customer_delivery_info` : consignes de livraison d'un client (procédure de déchargement, alarme, clé, congélateur, contact) en un seul bloc JSON chiffré AES-256-GCM (clé dérivée de `PRODUCTION_JWT_SECRET`, préfixe `d1:`), FK client ON DELETE CASCADE. Paramètre `driver.max_photos` (6 par sorte et par client). Numéros de palettes : `palets_delivery` (scanner), lu tel quel.

### Rapport mensuel (migration 083, 26/10/2026)

Aucune table : paramètres `report.monthly_recipients` (adresses de l'envoi automatique, vide = désactivé), `report.monthly_send_day` (jour du mois, 1–28) et `report.monthly_lang` (pl / fr), modèles d'e-mail `management_report` (PL, FR). Le rapport lit les ventes WDT, les coûts mensuels, les recettes, `complaint`, `n_nc` et `n_delivery_stop` ; les envois sont dans `communication_message` (`message_type = 'management_report'`, envoi automatique : `created_by = 'monthly-report:AAAA-MM'`, un seul par mois).

Sans clé étrangère, volontairement : lignes de documents légaux vers un produit ou un client supprimé (gardées telles quelles), historiques (`customer_order_changelog` — l'entrée « delete » est écrite après la suppression —, `test_product_changelog`, `shipment_log`), tables écrites par le scanner d'entrepôt (`carton_log`, `carton_stock`, `palets_delivery`, `carton_pallet_connection`), colonnes où 0 veut dire « aucun ».

Tables jamais lues par l'ERP : `carton_log`, `carton_stock` et `palets_delivery` sont écrites par le programme du scanner d'entrepôt (hors de ce dépôt, actif) ; `smp` (dernière écriture 17/11/2023), `pre_calculated_nutrition` (10/08/2023), `slfood_order_collector_products`, `pre_defined_values_for_label`, `company_cost`, `company_info`, `category_group`, `raw_material_ppwr_document` : anciennes, gardées.

Aucune table d'origine utilisée n'est supprimée ni renommée. Le code lit aussi les tables d'origine (commandes, produits, recettes, clients, WDT, employés, utilisateurs…) ; les tables `n_` y sont reliées par des clés étrangères (`id_final_product`, `id_customer`, `id_user`, `id_production_employee`, `id_section`, `id_recipe`, `id_carrier_vehicle`, `id_test_product`…).

## Tables `n_` par module

Lignes : base de développement le 24/10/2026 (indicatif).

### Programme des migrations
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_schema_migration` | 6 | 70 | — |

### Socle atelier : paramètres, rôles, jetons API, journal — `001_core`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_audit_log` | 9 | 85 | — |
| `n_prod_login_attempt` | 5 | 3 | — |
| `n_prod_param` | 9 | 70 | — |
| `n_prod_refresh_token` | 10 | 2 | `user` |
| `n_prod_role` | 3 | 5 | — |
| `n_prod_role_permission` | 2 | 46 | — |
| `n_prod_user_role` | 5 | 1 | `user` |

### Référentiels atelier : groupes, postes, shifts, équipes, employés — `002_referentiels`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_employee` | 11 | 16 | `production_employee` |
| `n_prod_group` | 10 | 7 | `production_sections` |
| `n_prod_shift_template` | 13 | 3 | — |
| `n_prod_team` | 9 | 11 | — |
| `n_prod_team_member` | 6 | 16 | — |
| `n_prod_workstation` | 17 | 25 | — |
| `n_prod_workstation_shift` | 3 | 25 | — |

### Articles planifiables, réglages produit, pertes, gammes — `003_products_routing`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_bom_loss` | 7 | 6 | `product_recipe` |
| `n_prod_item` | 6 | 262 | `final_product`, `product_recipe` |
| `n_prod_product_settings` | 21 | 262 | — |
| `n_prod_routing_alternative` | 7 | 14 | — |
| `n_prod_routing_step` | 18 | 99 | — |

### Prévisions, plans, OF, opérations, temps passés — `004_planning`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_operation` | 17 | 0 | — |
| `n_prod_order` | 12 | 0 | — |
| `n_prod_order_demand` | 5 | 0 | `customer_order` |
| `n_prod_plan` | 12 | 1 | — |
| `n_prod_plan_line` | 15 | 27 | — |
| `n_prod_time_log` | 9 | 0 | — |

### E-mails automatiques (règles, envois) — `006_mail_automation`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_mail_dispatch` | 7 | 0 | — |
| `n_mail_rule` | 15 | 13 | — |

### Achats MP (offres, appels d’offres, non-conformités) et postes / tâches — `007_purchasing_positions`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_mp_offer` | 17 | 0 | — |
| `n_nc` | 23 | 0 | — |
| `n_position` | 7 | 5 | — |
| `n_position_task` | 11 | 21 | — |
| `n_task_occurrence` | 8 | 7 | — |
| `n_tender` | 11 | 0 | — |
| `n_tender_item` | 6 | 0 | — |
| `n_tender_supplier` | 7 | 0 | — |
| `n_user_position` | 3 | 0 | `user` |

### Rentabilité : objectifs par section — `008_profitability_targets`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_section_target` | 7 | 8 | `production_sections` |

### Chronométrages des étapes — `009_time_studies`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_time_study` | 15 | 0 | — |

### Actions de développement du CA — `010_growth_actions`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_growth_action` | 17 | 0 | `production_sections` |

### Salaires minimum, primes, compétences — `013_staff_pay_competencies`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_staff_pay` | 6 | 0 | `production_employee` |

### Logistique : véhicules, trajets clients, affectations du jour — `014_logistics`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_shipping_assignment` | 9 | 0 | `carrier_vehicle`, `customer` |
| `n_vehicle` | 8 | 0 | `carrier_vehicle` |

### Effectif du jour et heures sup. — `015_staffing_optimizer`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_staffing` | 9 | 0 | — |

### Tablettes : appareils, identification, pointage, signatures — `016_execution_kiosk`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_clock` | 12 | 0 | — |
| `n_prod_device` | 10 | 1 | — |
| `n_prod_employee_credential` | 10 | 1 | — |
| `n_prod_signature` | 10 | 0 | — |

### Pointage au téléphone (QR) — `017_qr_clock`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_phone` | 7 | 0 | — |

### Tournées — `018_tours`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_tour` | 10 | 0 | — |
| `n_tour_customer` | 3 | 0 | `customer` |
| `n_tour_vehicle` | 3 | 0 | `carrier_vehicle` |

### WDT remplacées (exclues des analyses) — `020_wdt_superseded`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_wdt_superseded` | 4 | 2 | — |

### Fiche de production : logistique, cuisson — `022_product_card_logistics`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_item_logistics` | 11 | 1 | — |

### Semaine de production (produit × jour) — `023_week_slots`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_week_slot` | 7 | 20 | — |

### Recalcul des marges — `027_margin_recalc`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_margin_recalc` | 6 | 254 | — |

### Compteur de pages — `028_page_views`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_page_view` | 6 | 500 | — |

### Tablette du groupe : production du jour, lots — `029_production_output`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_lot_photo` | 8 | 1 | — |
| `n_prod_output` | 19 | 1 | — |
| `n_prod_output_lot` | 10 | 2 | — |

### Historique des recalculs de marges — `033_margin_recalc_runs`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_margin_recalc_run` | 6 | 1 | — |

### Lots fixes chaque semaine — `036_fixed_batches`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_fixed_batch` | 8 | 0 | — |
| `n_prod_fixed_batch_week` | 7 | 0 | — |

### Module responsable de production — `041_manager_module`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_mgr_action` | 9 | 0 | — |
| `n_mgr_competency_audit` | 8 | 0 | — |
| `n_mgr_haccp_event` | 12 | 0 | — |
| `n_mgr_launch` | 11 | 0 | — |
| `n_mgr_objective` | 8 | 0 | — |
| `n_mgr_plan` | 20 | 0 | — |
| `n_mgr_value` | 7 | 0 | — |

### Approvisionnement : besoins, commandes fournisseurs — `043_supply`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_mp_count` | 7 | 0 | — |
| `n_mp_supply` | 6 | 0 | — |
| `n_po` | 15 | 0 | — |
| `n_po_line` | 6 | 0 | — |
| `n_po_receipt` | 7 | 0 | — |

### Contrôle des factures d’achat — `044_invoice_control`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_inv` | 21 | 0 | — |
| `n_inv_file` | 5 | 0 | — |
| `n_inv_line` | 12 | 0 | — |
| `n_inv_mapping` | 12 | 0 | — |
| `n_inv_supplier` | 3 | 0 | — |

### Tablette : pertes du jour, validation — `046_production_waste`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_day_close` | 8 | 0 | — |
| `n_prod_waste` | 14 | 0 | — |

### Tâches : catégories, formulaires — `047_task_categories_forms`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_task_action` | 10 | 0 | — |
| `n_task_category` | 7 | 8 | — |
| `n_task_form` | 12 | 0 | — |

### TRS (OEE) : états des machines — `049_oee`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_prod_ws_state` | 11 | 0 | — |

### Droits des postes sur les pages — `050_page_access`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_page_access` | 4 | 155 | — |

### Produits à développer sur la tablette — `051_dev_products_tablet`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_dev_product` | 6 | 0 | `test_product` |
| `n_dev_product_photo` | 8 | 0 | `test_product` |

### Activité dans l’ERP (sessions, clics, coût) — `052_activity`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_activity_click` | 4 | 23 | — |
| `n_activity_session` | 5 | 9 | — |
| `n_activity_view` | 11 | 1506 | — |
| `n_user_cost` | 4 | 0 | `user` |

### Prix magasins (Belgique) — `053_store_prices`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_store_price_config` | 12 | 1 | — |
| `n_store_price_sync` | 9 | 0 | — |
| `n_store_product` | 11 | 0 | — |
| `n_store_product_link` | 4 | 0 | — |
| `n_store_product_shop` | 5 | 0 | — |

### Valeurs nutritionnelles (Open Food Facts) — `055_nutrition_internet`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_material_nutrition_source` | 7 | 0 | — |
| `n_nutrition_category` | 4 | 0 | — |
| `n_nutrition_off_cache` | 5 | 0 | — |

### Historique des prix produits — `056_product_price_history`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_product_price_change` | 8 | 0 | — |

### Navigation (pages favorites) — `059_navigation`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_position_home` | 4 | 0 | — |

### Avis de livraison au client (e-mail / SMS) — `062_delivery_notices`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_delivery_notice` | 14 | 0 | — |

### Page client, coefficients de vente — `064_client_space`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_category_sales_coefficient` | 5 | 2 | — |
| `n_customer_space` | 7 | 5 | — |

### Distribution des documents — `066_document_distribution`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_doc_distribution` | 8 | 91 | — |
| `n_doc_distribution_moment` | 4 | 11 | — |
| `n_price_list_state` | 3 | 0 | — |

### Photos produit / étapes, groupe producteur — `067_product_media_recipe_producer`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_product_media` | 9 | 5 | — |
| `n_recipe_producer` | 4 | 2 | — |

### Questions du pointage — `068_clock_questions`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_clock_answer` | 7 | 0 | — |
| `n_clock_question` | 9 | 4 | — |

### Poczta : la boîte mail de chaque utilisateur — `071_user_mailbox`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_user_mailbox` | 20 | 0 | `user` |
| `n_user_mail` | 23 | 0 | `user` |
| `n_user_mail_sent` | 14 | 0 | `user` |

Mots de passe IMAP / SMTP chiffrés (AES-256-GCM, clé dérivée de `PRODUCTION_JWT_SECRET`) ; `n_user_mail` garde les en-têtes et le texte des messages (index plein texte pour la recherche), le HTML et les pièces jointes restent dans la boîte et sont lus à l'ouverture.

## Conventions

- Préfixe `n_` pour toute nouvelle table ; migrations versionnées dans `migrations/tfb` (le nom du dossier et des scripts `Bin/tfb_*.php` reste, ce ne sont pas des tables).
- InnoDB, `utf8mb4`, collation `utf8mb4_general_ci` partout (migrations 070 et 079) ; clé primaire partout, clé étrangère et index sur les colonnes de liaison.
- Les noms internes de certains index et contraintes contiennent encore `tfb` (sans effet sur le fonctionnement).
