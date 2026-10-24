# Base de données « erp » : tables d'origine et tables ajoutées (préfixe `n_`)

Une seule base, **`erp`** (serveur de test : l'ancienne base `erp_production` y a été déplacée le 24/10/2026 par `deploy/server.sh`, données gardées).

- **131 tables d'origine** de l'ERP SL Food : noms inchangés.
- **116 tables ajoutées** pendant le développement : toutes préfixées **`n_`** (« nouveau »), jusqu'à la migration 069 elles s'appelaient `tfb_…`. Elles se reconnaissent d'un coup d'œil pour l'intégration.

## Installer / intégrer

Sur une base ERP d'origine (copie de production) : `php Bin/tfb_migrate.php apply` depuis `apps/production/app`. Les migrations `migrations/tfb/001 → 070` créent les tables (`tfb_` puis renommées `n_` par la 069), posent les données de départ (paramètres, rôles, modèles d'e-mails, questions du pointage…) et sont enregistrées dans `n_schema_migration` (une migration déjà appliquée ne peut plus être modifiée). `docs/db/n_schema.sql` donne le schéma final des tables `n_` en un seul fichier, pour lecture.

## Ce que le développement change dans les tables d'origine

| Table d'origine | Changement | Migration |
|---|---|---|
| `customer` | colonne ajoutée `communication_lang CHAR(2)` (langue des e-mails / SMS : pl, fr, nl, en), remplie d'après la langue des documents et le pays | 060 |
| `document_wdt` | index `idx_document_wdt_established` (`established_date`) | 019 |
| `document_wdt_product` | index `idx_document_wdt_product_document` (`id_document_wdt`) | 019 |
| `communication_template` | 84 modèles d'e-mails ajoutés (logistique, factures, proforma, rappels, avis de livraison, distribution ; fr, nl, pl) | 006, 061–066 |
| `final_product` | `stock_minimum` aligné sur le stock min. des fiches MTS ; `pre_calculated_raw_price_per_carton` = coût de la recette | 034, 057 |
| `raw_material` | catégories inexistantes corrigées (matières rattachées à une catégorie existante) | 021 |

Aucune table d'origine n'est supprimée ni renommée. Le code lit aussi les tables d'origine (commandes, produits, recettes, clients, WDT, employés, utilisateurs…) ; les tables `n_` y sont reliées par des clés étrangères (`id_final_product`, `id_customer`, `id_user`, `id_production_employee`, `id_section`, `id_recipe`, `id_carrier_vehicle`, `id_test_product`…).

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
| `n_prod_forecast` | 8 | 0 | — |
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
| `n_employee_competency` | 5 | 0 | `production_employee`, `recipe_competency` |
| `n_staff_pay` | 6 | 0 | `production_employee` |

### Logistique : véhicules, trajets clients, affectations du jour — `014_logistics`
| Table | Colonnes | Lignes | Clés étrangères vers les tables d'origine |
|---|---:|---:|---|
| `n_customer_route` | 5 | 0 | `carrier_vehicle`, `customer` |
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

## Conventions

- Préfixe `n_` pour toute nouvelle table ; migrations versionnées dans `migrations/tfb` (le nom du dossier et des scripts `Bin/tfb_*.php` reste, ce ne sont pas des tables).
- InnoDB, `utf8mb4`, collation `utf8mb4_general_ci` comme l'ERP (migration 070) ; clé primaire partout, clé étrangère et index sur les colonnes de liaison.
- Les noms internes de certains index et contraintes contiennent encore `tfb` (sans effet sur le fonctionnement).
