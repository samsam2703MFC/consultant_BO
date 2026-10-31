# Base de données « erp » : tables d'origine et tables ajoutées (préfixe `n_`)

Une seule base, **`erp`** (serveur de test : l'ancienne base `erp_production` y a été déplacée le 24/10/2026 par `deploy/server.sh`, données gardées).

- **121 tables d'origine** de l'ERP SL Food : noms inchangés (131 avant le nettoyage du 24/10/2026, qui a supprimé les 10 copies de sauvegarde).
- **164 tables ajoutées** pendant le développement (29/10/2026) : toutes préfixées **`n_`** (« nouveau »), jusqu'à la migration 069 elles s'appelaient `tfb_…`. Elles se reconnaissent d'un coup d'œil pour l'intégration.

## Installer / intégrer

Sur une base ERP d'origine (copie de production) : `php Bin/tfb_migrate.php apply` depuis `apps/production/app`. Les migrations `migrations/tfb/001 → 100` créent les tables (`tfb_` puis renommées `n_` par la 069), posent les données de départ (paramètres, rôles, modèles d'e-mails, questions du pointage…) et sont enregistrées dans `n_schema_migration` (une migration déjà appliquée ne peut plus être modifiée). `docs/db/n_schema.sql` donne le schéma final des tables `n_` en un seul fichier, pour lecture.

## Ce que le développement change dans les tables d'origine

| Table d'origine | Changement | Migration |
|---|---|---|
| `customer` | colonne ajoutée `communication_lang CHAR(2)` (langue des e-mails / SMS : pl, fr, nl, en), remplie d'après la langue des documents et le pays | 060 |
| `document_wdt` | index `idx_document_wdt_established` (`established_date`) | 019 |
| `document_wdt_product` | index `idx_document_wdt_product_document` (`id_document_wdt`) | 019 |
| `communication_template` | 84 modèles d'e-mails ajoutés (logistique, factures, proforma, rappels, avis de livraison, distribution ; fr, nl, pl) ; 4 modèles `buy_request` (demande d'offre des achats de la semaine : pl, fr, nl, en) | 006, 061–066, 118 |
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

### Nouveau produit : étape « Koszt i cena » (migration 124, 29/10/2026)

- 4ᵉ étape de l'assistant `/test-products/new` (après la fiche) : la recette de la fiche (IA ou .docx) est rapprochée de
  nos matières (`raw_material`, sans les emballages) — mots du nom sans descriptions, racines polonaises (mąki ↔ mąka,
  soli ↔ sól, cukru ↔ cukier ; mak ≠ mąka), quelques synonymes (kaşar → ser żółty), quantités converties dans l'unité
  de la matière (g, ml, szt ; cuillère ≈ 15 g, cuillère à café ≈ 5 g, verre ≈ 250 ml, œuf ≈ 55 g, « na sztukę » × pièces
  de la fournée, levure sèche → fraîche × 3), eau sans coût. Coût de ligne = quantité × `price_per_unit` (prix le plus
  haut validé, comme le calcul des recettes) ; matière absente : prix estimé en zł/kg.
- `n_dev_card` reçoit `cost_json` (lignes : clé du texte de la fiche, matière, quantité, prix estimé, statut `auto` /
  `check` / `ok` / `missing` / `estimate` / `free`, remarque), `yield_pcs` (pièces de la fournée, lue dans « Wydajność »),
  `factory_coef` (prix usine = coût matières par pièce × coefficient), `store_mult` (prix magasin HT = prix usine ×
  multiplicateur), `store_price_eur` (prix magasin final arrondi ou saisi), `cost_pc_pln`, `cost_at`, `cobot`
  (`yes` / `no` / `check`) et `cobot_steps` (étapes du cobot, séparées par « | »). Une ligne de la fiche modifiée est
  rapprochée à nouveau ; les autres gardent le choix enregistré.
- `n_dev_ingredient_map` : mémoire des choix de l'opérateur (`name_key` = nom de l'ingrédient sans accents ni
  descriptions → `id_raw_material`, `uses`) ; utilisée en premier sur les fiches suivantes.
- Paramètres (`n_prod_param`, Ustawienia › Pozostałe › Ceny nowych produktów) : `new_product.factory_coefs` (2.5,3,4)
  et `new_product.factory_coef` (3), `new_product.store_mults` (3,4,5) et `new_product.store_mult` (4),
  `new_product.store_round_eur` (0,10 €). Cours EUR : `erp_company_other_settings.exchange_rate_euro` ; vendabilité
  en Belgique (magasin ÷ usine) avec les seuils de `n_store_price_config` (× 2,5 / 2,2).
- Résumé sur la fiche du produit en développement (`/test-products/{id}/tablet`) : coût par pièce, prix usine, prix
  magasin, cobot, lien « Popraw koszt i cenę » (`/test-products/new/{id}#koszt`).

### Feuille de route de la semaine (migration 139, 30/10/2026)

- Dzisiaj « Mój tydzień » (maquette 219-1) : les tâches fixes du poste de l'utilisateur, jour par jour pour la semaine ISO en
  cours (jours ouvrés `tasks.workdays`), chaque carte avec la catégorie (couleur), les minutes, le lien direct vers la page
  ERP (« Kreator faktur → », « Kreator cen → »…) et la case « Zrobione » (PATCH `/ajax/my-tasks/{id}`, sans rechargement,
  « Cofnij » remet à faire). Tâches quotidiennes de tous les jours dans un bandeau au-dessus (pastilles Pn…Pt) ; tuiles
  zrobione / total et minutes, aujourd'hui (tâches, minutes, liens), en retard, part des 40 h, mois et trimestre ; en dessous
  « Zaległe » (ouvertes des semaines passées) et « Miesiąc i kwartał ». Les jours passés sans occurrence restent grisés
  (personne n'a ouvert la liste ce jour-là), les jours à venir sont « zaplanowane » (occurrence créée le jour même, comme
  « Moje zadania »). `PositionTaskService::week()`.
- Validation automatique (`n_position_task.auto_code`) : la tâche passe « faite » toute seule (sans auteur, note
  « Automatycznie : … ») quand l'assistant lié est terminé dans sa période : `inv_checked` (au moins une ligne de facture
  vérifiée dans la période et plus aucune ligne de facture marchandises ouverte à vérifier), `costs_recalc`
  (`n_margin_recalc_run` dans la période), `prices_planned` (publication de prix créée, non annulée), `buy_sent`
  (`n_buy_round.sent_at`). Évaluée à l'ouverture de Dzisiaj / Moje zadania.
- Plan de la semaine du poste (maquette 219-2, administrateur seul : catalogue d'accès `week_plan`,
  `/organization/week-plan/{id}`, bouton « Plan tygodnia » sur chaque poste de Funkcje i zadania) : tâche, catégorie, jours
  (1 jour = hebdomadaire ce jour-là, tous les jours ouvrés = quotidienne, 2 à 4 jours = quotidienne ces jours-là
  `weekdays`, ou « Mies. » / « Kw. »), minutes (0–480), lien (liste des pages : kreator faktur, faktury, kreator cen étapes
  1 / 2 / 3, zakupy tygodnia, dostawcy, oferty, NC… ou autre adresse), « zalicza się samo » ; colonne « Rzeczywiście » :
  temps actif moyen par exécution des personnes du poste sur la page du lien (`n_activity_view`, 4 dernières semaines,
  préfixe le plus long, partagé au prorata du plan entre tâches d'une même page ; rouge au-delà de 125 % du plan) ; charge
  par jour et de la semaine (% de 40 h). Enregistrement du plan entier en une transaction (`PUT
  /ajax/organization/week-plan/{id}` : tasks [id, title_pl, category_id, period week | monthly | quarterly, days, minutes,
  link_path, auto_code], removed [ids] → tâches inactives) ; un changement de jours supprime les occurrences ouvertes depuis
  lundi (recréées selon le nouveau plan). « Kopiuj z innego stanowiska » (`POST …/copy` {from}) ajoute les tâches actives
  d'un autre poste, sauf celles du même nom. `GET /ajax/organization/week-plan/{id}` relit le plan.
- Colonnes ajoutées à `n_position_task` : `minutes` (SMALLINT, durée d'une exécution), `weekdays` (ex. « 1,4 »),
  `auto_code`. Migration : plan du poste ZAKUPY (minutes et jours des tâches existantes laissées vides ; tâche du mercredi
  « Zaimportować ceny z faktur » → lien kreator faktur + `inv_checked` ; « Zakupy tygodnia » → `buy_sent` ; nouvelles tâches
  kreator cen : coûts et marges mercredi 20 min `costs_recalc`, nouveaux prix jeudi 30 min `prices_planned`, contrôle de
  la publication vendredi 10 min). Total : 4 h 15 par semaine.
- Migration 140 (décision du 30/10/2026 : pas d'acheteur, les achats sont faits par la production) : toutes les tâches du poste
  ZAKUPY passent au poste KIEROWNIK_PRODUKCJI (Anna Szeremeta ; occurrences conservées, ordre après ses tâches), poste ZAKUPY
  désactivé s'il n'a personne. Les alertes d'achat de « Do obsłużenia » et de Dzisiaj (commande fournisseur non envoyée,
  zakupy tygodnia, offres à décider, articles < 3 fournisseurs) visent ZAKUPY et KIEROWNIK_PRODUKCJI.

### Tâche = assistant, cron, rappels, « Kto co zrobił » (migration 146, 31/10/2026)

- Chaque tâche de poste peut ouvrir un assistant (`n_position_task.auto_code`, catalogue `TaskWizards::CATALOG`) et se valide
  seule sur sa dernière étape, avec un résultat chiffré enregistré dans `n_task_occurrence.summary` : `forecast_approved`
  (`n_forecast_run.approved_at` : « 10 988 kart. · zatwierdzona 09:04 — Anna »), `buy_sent` (`n_buy_round` envoyée ;
  brouillon = étape 2 sur 3 « Komu »), `inv_checked`, `costs_recalc`, `prices_planned`, `week_plan_validated` (`n_prod_plan`
  validé ; proposé = en cours), `production_day_closed` (`n_prod_day_close` : groupes qui ont validé le jour),
  `day_shipped` (`palets_delivery` du jour : statut 1 / 2 = en attente, 3 = expédiée ; jour sans palette = fait),
  `logistics_plan_approved` (`n_logistics_plan_week.approved_at`), `month_closed` (mois précédent : `cost_month` LOCKED ;
  ouvert = étape de `MonthCloseService::status`, 8 étapes), `services_closed` (mois précédent `n_service_month`).
  Lien de la tâche = page de l'assistant. Migration : « Wysyłka dnia » (Logistyka) → `/shipping/wizard` + `day_shipped`,
  suivi de production (Kierownik produkcji) → `production_day_closed`, clôture du mois (Administracja) → `/month-close`,
  `month_closed`, 3e jour ouvré ; nouvelles tâches : plan de production de la semaine (Kierownik produkcji, jeudi 45 min),
  plan logistique 13 semaines (Logistyka, jeudi 30 min), coûts de services du mois (Administracja, 2e jour ouvré 30 min).
- Colonnes ajoutées à `n_position_task` : `due_time` (échéance dans la journée), `month_day` (mois / trimestre : `wd3` =
  3e jour ouvré hors fériés PL, `d5` = 5e jour, vide = dernier jour ; trimestre : dans le 1er mois), `cron_on`, `cron_freq`
  (daily / weekly / monthly / quarterly), `cron_days` (« 1,2,3,4,5 », « 1 », « d1 », « wd1 »), `cron_time`, `cron_action`
  (`TaskCronService::ACTIONS` : `forecast_panel` lecture du panel TF Buddy + recalcul de la prévision, `stock_recalc`
  stocks min. / max. des 6 semaines, `month_prepare` ouverture de la clôture du mois précédent), `cron_then` (`wait` =
  préparé, la personne termine l'assistant ; `done` = validée seule), `remind_time` (défaut 09:00, vide = sans), `escalate_days`
  (défaut 2, vide = jamais). `n_task_occurrence` : `summary`, `reminded_at`, `escalated_at`. Table `n_task_cron_run`
  (`id_task`, `period_key` 2026-11-02 / 2026-W45 / 2026-11 / 2026-Q4, `action`, `started_at`, `finished_at`, `ok`, `summary`,
  `error`) : une exécution réussie par période ; rattrapage plus tard dans la même période si le serveur était arrêté ; échec :
  nouvel essai toutes les 30 min, 3 au plus, cloche « danger » (`task_cron_failed`).
- `Bin/task_cron.php` toutes les 5 min (`/etc/cron.d/production-erp-mail`, heure de Varsovie) : occurrences des périodes en
  cours pour les postes occupés + validation par les assistants, exécutions cron dues, rappel le jour de l'échéance à
  `remind_time` (cloche `task_reminder` « Dziś termin… »), en retard de `escalate_days` jours → cloche `task_late` avec poste,
  personnes et supérieur (`n_position.id_reports_to`, sinon Administrateur) ; une fois par occurrence ; retards de plus de
  `escalate_days` + 7 jours ignorés (anciens retards au premier passage). Cloche seulement (aucun e-mail).
- Dzisiaj « Mój tydzień » (maquette 227-1) : « Na dziś » = tâches du jour, retards de la semaine et d'avant (une ligne par
  tâche, « N× niezrobione »), mois / trimestre en cours ou à échéance sous 3 jours, faits aujourd'hui ; un bouton par ligne :
  Rozpocznij (assistant), Wznów (étape X sur Y), Zobacz (résultat), Wypełnij protokół ; « Zrobione » seulement pour les
  tâches sans assistant ; états : préparé par le système (cron `wait`), exécuté par le système, en retard avec cloche et
  « przełożony powiadomiony ». Semaine en pastilles (assistant, en cours, fait, automatique, en retard).
- Tâches du poste (maquette 227-2, `/organization/week-plan/{id}`, administrateur) : tableau par fréquence, panneau
  d'édition (assistant, fréquence Dzień / Tydzień / Mies. / Kw. + jours ou règle du mois + heure, minutes, « Automatycznie
  (cron) » Tak / Nie → quand, jour, heure, quoi, ensuite, dernières exécutions ; rappels), « Kreatory do przypięcia »
  (libre / épinglé). Enregistrement : mêmes PUT + champs ci-dessus, validés (HH:MM, `wdN` / `dN`, 1–30 jours, cron complet).
- « Kto co zrobił » (maquette 227-3, `/organization/execution`, catalogue d'accès `execution` : administrateur + postes
  supérieurs d'un autre poste — Kierownik produkcji, Logistyka, Brygadzista) : postes × jours (Dziś / Tydzień / Miesiąc), une
  pastille par échéance (fait, validé seul, en cours, en retard, à faire), ligne des groupes de production (jour validé sur
  la tablette), ligne « System » (exécutions cron), tuiles, retards avec « Przypomnij » (POST
  `/ajax/organization/execution/remind/{occurrence}` → cloche, une fois par jour), détail au clic.

### Ménage des tâches par poste (migration 147, 31/10/2026)

- Une tâche = un assistant : les tâches du Kierownik produkcji qui étaient des étapes d'un assistant existant sont fusionnées
  dans la tâche de l'assistant (21 → 13 tâches actives ; tâches fusionnées `active = 0`, historique des occurrences conservé) :
  Zakupy tygodnia (lundi, 85 min) ← offres reçues + articles à moins de 3 fournisseurs ; Faktury tygodnia (mercredi, kreator
  faktur, 60 min) ← import des factures + nouveaux prix matières (factures et listes de prix) ; Koszty i marże (mercredi,
  kreator cen étape 1) ← coût des recettes + marges des catégories (mensuels) ; Nowe ceny i publikacja (jeudi, étapes 2–3,
  40 min) ← vérification de la publication ; trimestre : évaluation des fournisseurs + appel d'offres matières clés = une tâche.
  Correspondance par poste, page et fréquence (pas par id), seulement si la tâche cible est active ; rejouable. Les achats
  restent au Kierownik produkcji (décision du 30/10 : pas d'acheteur). Doublon connu : contrôle des réceptions / NC chez
  Kierownik (quotidien) et Jakość (quotidien) — Jakość n'a personne ; à retirer du Kierownik quand quelqu'un l'occupera.
- `n_position_task.extra_links` (JSON, liste de chemins ERP, 6 au plus) : « Też w tym zadaniu » — pages des étapes fusionnées,
  en liens sous la tâche dans Dzisiaj (« też : Oferty · Dostawcy ») et dans le panneau d'édition des tâches du poste (ajout
  depuis la liste des pages, suppression) ; validées à l'enregistrement (chemin ERP, sans doublon), copiées avec le plan.

### Tâches quotidiennes des tablettes (migration 148, 31/10/2026)

- Une tâche du jour par poste d'atelier (`n_position.kind = 'atelier'`, sans compte ERP), cochée par la tablette :
  Piekarz, Rogal, Cukiernik, Garmażer → `group_day_closed` (journée du groupe validée, `n_prod_day_close` ; groupe du poste :
  `TaskWizards::POSITION_GROUP`, PIEKARZ → PIEKARNIA, ROGAL → ROGAL, CUKIERNIK → CUKIERNIA, GARMAZER → GARMAZERIA ; lignes
  saisies sans validation = en cours), Brygadzista → `shift_day_validated` (tous les groupes ayant des lignes ce jour-là ont
  validé ; « 1 z 2 grup »), Pakowacz → `packing_done` (`n_packing_count` : emballé ≥ prévu pour chaque produit du jour
  d'expédition ; jour sans emballage ni palette = fait), Kierowca → `route_done` (`n_delivery_run.finished_at` pour toutes
  les tournées du jour ; livraisons et problèmes de `n_delivery_stop` dans le résumé ; jour sans tournée ni palette = fait).
  Sans rappel ni supérieur dans la cloche (`remind_time` et `escalate_days` vides) : le responsable suit dans « Kto co zrobił ».
- Occurrences créées par le cron pour les postes d'atelier actifs même sans utilisateur ERP (`ensureCurrent`).
- « Kto co zrobił » : postes d'atelier en deux lignes (maquette 227-3) : « Produkcja — grupy (tablety) » (+ groupes sans poste,
  p. ex. Tarty, depuis les validations de journée) et « Brygadzista · Pakowanie · Kierowca (tablety) » ; un jour passé sans
  occurrence (tâche créée après, liste jamais ouverte) affiche l'état de l'assistant ce jour-là (lecture seule).

### Trois assistants de tâches : réceptions, paiements, évaluation des fournisseurs (migration 149, 31/10/2026)

- **Kontrola przyjęć dnia** — saisie sur la tablette d'emballage (`/tablet/packing`, troisième choix de l'interrupteur
  Pakowanie / Paletyzacja / **Przyjęcia**, API `GET /kiosk/receiving`, `GET|POST /kiosk/receiving/orders/{id}`, toute tablette
  appairée, opérateur = personne pointée). Livraisons du jour = commandes fournisseurs (`n_po`) envoyées / reçues avec la date
  de livraison du jour, ou reçues ce jour-là (`ReceivingWizardRepository::DUE`) ; commandes ouvertes en retard (14 jours)
  affichées à part. Par ligne : quantité (unité affichée kg / l / szt), lot, DLUO, température (matières froides : catégorie ou
  nom — nabiał, mięso, masło, drożdże…), OK / Niezgodność. Lot et DLUO : « 📷 Etykieta » = photo de l'étiquette → OCR
  sur la tablette (`label-ocr.js`, module commun avec la tablette de groupe, Tesseract.js) → champs vides remplis + candidats. Chaque ligne contrôlée enregistre la réception dans la commande
  (`SupplyService::receive`, `n_po_receipt.temperature_c` nouveau) ; une non-conformité (types : quantité, qualité,
  température, emballage, DLUO, document ; description ; décision : acceptée, sous réserve, retour, décision du responsable ;
  4 photos JPEG / PNG / WebP ≤ 3 Mo, `n_nc_photo`) crée une NC fournisseur (`n_nc`, quantité concernée, décision dans
  `immediate_action`) ; « retour » et « décision du responsable » → cloche (`receiving_nc`). « Livraison non arrivée » =
  contrôle sans réception. `n_receiving_check` (commande × jour : lignes OK / NC, arrivée, NC, utilisateur ou opérateur).
  ERP : `/workshop/receiving` (Zakupy › Kontrola przyjęć) = résumé du jour, photos (`/purchasing/nc/photo/{id}`), brouillon
  d'e-mail au fournisseur dans sa langue (PL / FR, « Kopiuj », « Otwórz w poczcie » — rien n'est envoyé par l'ERP).
  Tâche Jakość (quotidienne, 14:00) et Kierownik (quotidienne) → `receiving_checked` : faite quand toutes les livraisons du
  jour sont contrôlées (« 4 dostawy · 10 OK · 1 NC »), jour sans livraison = fait.
- **Płatności dnia** (`/finances/payments-day`, Sprzedaż) — 1. proformas impayées (jours depuis l'émission, rappels envoyés),
  « opłacone » avec date et montant du relevé (`ProformaService::markPaid` + `n_proforma_payment`) ; 2. rappels dus (niveaux
  1–5 après 3 / 7 / 14 / 21 / 30 jours ; niveaux couverts par l'envoi automatique = automatiques, autres : aperçu et
  « Wyślij » dans le composeur) ; 3. écarts de livraison ouverts (dopłaty, zwroty, problèmes ; décisions sur la page des
  écarts). « Zakończ » → `n_wizard_done` (`payments_reviewed`, « 2 opłacone · 4 przypomnienia · należności 62 410 € »).
  Tâche Administracja « Kontrola wysłanych faktur i płatności » (quotidienne, 11:00).
- **Ocena dostawców** (`/purchasing/supplier-review`, Zakupy › Oferty i zapytania, trimestre précédent) — par fournisseur :
  achats (réceptions ou factures KSeF, le plus grand), livraisons, à temps (première réception ≤ date de livraison de la
  commande), NC de livraison, variation de prix (dernier prix de `raw_material_price_changelog` en début et fin de
  trimestre, pondérée par les achats). Note = à temps 40 % · NC 30 % (1 − 2 × NC / livraisons) · prix 30 % (0 % à +10 %) ;
  parts sans données ignorées ; A ≥ 85, B ≥ 65, C ; décision par défaut A zostaje, B obserwuj, C zmień dostawcę (corrigeable,
  note) → `n_supplier_review`. Matières proposées : +5 % sur le trimestre, fournisseur actuel « zmień », ou matière clé
  (≥ 5 % des achats) à moins de 3 fournisseurs. Brouillon d'appel d'offres (`TenderService::create`, fournisseurs actuels
  + mêmes catégories avec e-mail, réponse +14 jours) — envoi seulement depuis Zapytania ofertowe. « Zatwierdź ocenę » →
  `n_wizard_done` (`suppliers_reviewed`, « III kwartał 2026 : 8 A · 4 B · 2 C · zapytanie ZO-… »).
- Accès : nouvelles pages `receiving` (depuis `supply` / `production`), `payments_day` (depuis `reminders` / `invoices`),
  `supplier_review` (depuis `purchasing`). Tâches reliées par poste, page et fréquence (pas par id), seulement sans
  assistant ; rejouable.

### Zadania zespołu : tableau du responsable de production et rappels (migration 150, 31/10/2026)

- **Tableau** `/organization/team-tasks` (rail « Zadania zespołu », même droit que « Kto co zrobił » : clé `execution`) —
  toutes les échéances de la période (aujourd'hui, semaine des jours ouvrés, mois ; ‹ › pour décaler) des postes ERP occupés
  et des postes d'atelier (`PositionTaskService::itemsBetween`, même état que « Kto co zrobił » : en retard, à faire, en cours
  avec étape, prévu, fait par une personne, validé seul), regroupées par **section** : groupe de production (tablette du
  groupe, aussi les groupes sans poste comme Tarty : jour validé sur la tablette, jour passé sans production ignoré),
  pakowanie / kierowca / brygadzista (tablettes), postes ERP (personnes). Filtres : groupe / poste, personne (compte ERP →
  son poste, salarié → son groupe / poste ; tâches faites par la personne), état (Wszystkie / Niezrobione / Spóźnione /
  Zrobione, compteurs), fréquence, catégorie, recherche. Tuiles : fait X sur Y (objectif 90 %), à faire (jusqu'à aujourd'hui /
  plus tard), en retard (dont > 2 jours), en cours, rappels envoyés aujourd'hui et lus.
- **Przypomnij** (une ligne, un groupe, la sélection) — fenêtre : destinataires, message prérempli depuis la tâche (modifiable ;
  sans modification chaque destinataire reçoit le texte de sa tâche), urgence (normale / rouge), canaux. Personne avec compte
  ERP → **cloche à elle seule** (`app_notification.id_user`, nouveau : `NULL` = tout le monde comme avant ; la cloche n'affiche
  et n'acquitte que les notifications communes et les siennes) + **e-mail** à l'adresse du compte (un e-mail par personne avec
  ses tâches, file `communication_message`, envoi par le worker selon `MAIL_DELIVERY_MODE`). Groupe sans comptes →
  **message sur sa tablette** (`audience` : `group:<id>`, `packing`, `driver`, `shift` = tablettes des groupes pour le chef
  d'équipe). Tâche déjà faite : ignorée. Historique `n_task_reminder` (lot d'envoi, tâche + échéance, message, urgence,
  destinataire, canaux, notification, e-mail, envoyé par / à, lu à / par / comment : PIN, toucher, cloche, fermé).
- **Tablettes** (`task-messages.js`, commun aux tablettes de groupe, d'emballage et chauffeur ; API `GET /kiosk/messages?for=`,
  `POST /kiosk/messages/{id}/read`) : bandeau « Od kierownika produkcji · HH:MM » au-dessus de l'écran (urgents en rouge,
  2 visibles + « Pokaż wszystkie »), « Przeczytane (PIN) » → personne (présents d'abord) → PIN vérifié comme à la connexion
  (`KioskAuthService::verifyPin`, blocage après erreurs) ; sans PIN : seulement une personne présente qui n'en a pas. Message
  non lu de moins de 7 jours ; tâche faite entre-temps (jour validé, assistant terminé) → fermé, disparaît. Au tableau :
  « przeczytane 10:51 (J. K., PIN) ».

### Wpłata z e-maila : preuve de paiement du client → OCR → validation (migration 153, 31/10/2026)

- Maquette 9. Une preuve de virement (PDF, photo) envoyée par un client dans Poczta. **Poczta** : bouton « Zweryfikuj wpłatę »
  sur un message d'un client qui a des proformas impayées (droit `payments_day`), « Odczytaj » sur chaque pièce PDF / image.
  **Płatności dnia › krok 1** : bloc « Z poczty : N potwierdzeń do sprawdzenia » — messages de la boîte de l'utilisateur
  (45 jours, INBOX, pièce jointe, client avec proforma impayée, mot-clé FP-…, proforma, przelew, wpłata, paiement, virement,
  preuve, payment, betaling, overschrijving…), montant lu, proforma trouvée, « kwota zgodna / różnica / do sprawdzenia ».
- Lecture : PDF texte → `pdftotext` côté serveur ; scan / photo → OCR dans le navigateur (Tesseract.js `pol`, pdf.js pour les
  scans) puis texte envoyé. `PaymentProofParser` (sans base) : numéros FP-00001/28/07/2026 (bruit OCR O→0, l→1), montant
  (ligne « Kwota / Montant / Amount / Bedrag / Betrag », devise, 1 234,56 · 1.234,56 · 1,234.56), date d'opération (libellés
  forts « Data operacji, Date d'exécution, Uitvoeringsdatum… » avant « Data », pas dans le futur, ≤ 400 jours), comptes
  (IBAN, NRB), donneur d'ordre, « zrealizowany ». Notre compte = `erp_company_data.*bank*` (chiffres égaux ou suffixe).
- Rapprochement : numéros lus parmi les proformas impayées du client, sinon une proforma au même montant, sinon toutes si la
  somme est égale ; les autres proformas du client à cocher (virement pour plusieurs). Contrôles : montant = proforma(s),
  date, numéro (trouvé / déjà payée / autre client / absent), compte (le nôtre / autre), donneur d'ordre ≈ client du mail.
- « Zatwierdź wpłatę » → `PaymentsDayService::markPaid` (statut `paid`, `n_proforma_payment` date + montant : une proforma =
  montant du virement, plusieurs = montant de chacune) ; lien `n_payment_proof_proforma` ; les rappels s'arrêtent (proforma
  payée exclue des listes) ; fiche client (`/clients/{id}/space`) : « opłacona 30.10.2026 · potwierdzenie przelewu z e-maila ».
  « To nie jest wpłata » → `rejected`, le message sort de la liste.
- `n_payment_proof` : fichier (`content`), texte lu (`text_enc`) et donneur d'ordre (`payer_enc`) **chiffrés** AES-256-GCM
  (`PaymentProofCipher`, préfixe `p1:`, clé dérivée de `PRODUCTION_JWT_SECRET`) ; montant, date, numéros, compte « nôtre »
  en clair ; un seul enregistrement par pièce jointe (`id_mail`, `attachment_index`). Seul le propriétaire de la boîte voit
  ses preuves. Fichier : `GET /finances/payment-proofs/{id}/file` (inline, protégé comme Płatności dnia).
- API web : `GET /ajax/finances/payment-proofs`, `GET …/mail/{id}`, `POST …/mail/{id}/reject`, `POST …/read`
  `{mail_id, index, ocr_text?}` (→ `need_ocr` si scan / photo), `GET …/{id}`, `POST …/{id}/confirm` `{proformas, paid_on,
  amount}`, `POST …/{id}/reject`.
 et production encore possible (migration 151, 31/10/2026)

- Page : Produkcja › « Moce produkcyjne » (`/production/capacity`, onglet de la page Produkcja, maquettes 10 et 11 ;
  catalogue d'accès `production`). `CapacityService`, `CapacityRepository`, `CapacityController`, `capacity.js`. Semaine du
  plan (`WeekService::quantities`, ‹ › pour changer), filtre groupe, « co jeśli » : + zmiana popołudniowa / + nocna.
- **Charge** d'une machine = Σ des étapes des cartes produit (`StandardTime::step` : travail + nettoyage) × quantités planifiées
  jour par jour. Machines « attente » (komory rozrostu, chłodnia, szokowe mrożenie) : occupation = passages × délai après
  l'étape, comparés à la capacité d'un passage. **Disponible** = shifts où le groupe a une équipe active (`n_prod_workstation_shift` peut
  retirer ou ajouter un shift à une machine ; la fenêtre n'enregistre que les shifts basculés ; un shift du « co jeśli »
  s'ouvre pour toutes les machines) × jours de production
  (`week.production_days` ∩ jours du shift) × **TRS** (`efficiency_pct`). Contrainte **ludzie** par groupe : obsada planowana
  × heures des shifts × jours, comparée au travail manuel du plan.
- Onglets : **Wykorzystanie** (tuiles : wąskie gardło, mroźnia zapasu, szokowe mrożenie, moyenne, meilleure marge ; tableau
  par type de machine avec % semaine, jour de pointe, produit principal, heures libres ; lignes des zespoły ; avertissement
  pour les produits du plan sans étapes), **Co jeszcze zmieszczę** (par produit : kartony encore possibles = min sur ses étapes
  du temps libre ÷ temps par unité et du temps libre des personnes ; ce qui limite ; valeur au prix standard ; simulation
  « + ilość » → chaque étape avant / après), **Ilość na zmianę** (maximum par shift et produit, étape limitante, cycle),
  **Ustawienia maszyn** (capacité d'un passage + unité, TRS 10–100 %, cycle typique quand la machine n'a pas encore d'étapes,
  shifts de la machine, visible / masquée ; mroźnia zapasu : places palettes `stock.freezer_pallet_places` et seuil
  `stock.freezer_alert_pct`). Les fours à sole et autres machines absentes se créent dans « Stanowiska robocze ».
- Migration 151 : `n_prod_workstation.efficiency_pct` (100 par défaut), `cycle_s`, `capacity_view` (tables manuelles masquées
  par défaut : leur limite, ce sont les personnes) + paramètre `stock.freezer_alert_pct` (90 %). Mroźnia : palettes en stock
  (`StockPeriodRepository::stockPallets`) + production MTS de la semaine en palettes, comparées aux places. Rejouable.
- **Qui voit quoi** : version complète (4 onglets, semaines, « co jeśli », valeurs en €, simulation, réglages) = administrateur
  et postes ayant le droit `capacity_full` (« Moce produkcyjne — pełna wersja », à donner au directeur de site dans
  Uprawnienia funkcji ; migration 152 : postes « Dyrektor… » s'il en existe, sinon personne). Production (droit `production`) : seulement le tableau de l'état actuel —
  semaine en cours, shifts des équipes, machines, équipes et congélateur, jour du jour encadré, lecture seule
  (`CapacityService::state` : sans produits ni € ; simulation et enregistrements refusés côté serveur, 403).
- API web : `GET /ajax/production/capacity`, `GET /ajax/production/capacity/simulate?item=&qty=`,
  `PUT /ajax/production/capacity/workstations/{id}`, `PUT /ajax/production/capacity/freezer`.


### Prévision 13 semaines glissantes (migration 141, 30/10/2026)

- Page : Produkcja › « Prognoza 13 tygodni » (`/production/forecast`, onglet de la page Produkcja, maquettes 220-1 à 3 ;
  catalogue d'accès `production`). `ForecastService`, `PanelSeasonService`, `SeasonMatcher`, `ForecastRepository`,
  `forecast.js`. Chaque semaine les mêmes 13 semaines à partir du lundi en cours (`forecast.weeks`), en cartons par produit :
  - semaine en cours : les commandes (`customer_order` statuts 1, 2, 3, 5, sans gratuits) ; ensuite la plus grande valeur
    entre les commandes déjà passées et la prévision ;
  - produits de toute l'année : moyenne des `forecast.trend_weeks` (8) dernières semaines × forme N-1 (cartons du réseau
    hors saisonniers la même semaine ISO un an plus tôt, ±1 semaine, ÷ niveau N-1 de ces 8 semaines ; une semaine N-1 à
    moins de 30 % du niveau = trou de données, pas une baisse → moyenne seule) ;
  - produits saisonniers (seulement nos produits : catalogue SL Food du panel) : ventes en magasin de la même gamme un an
    plus tôt (semaine k de la gamme N-1 → semaine k de la gamme cette année ; pièces ÷ pièces par carton ; semaine non lue →
    nos expéditions ERP) × tendance du réseau ; hors gamme : seulement les commandes. Un produit d'une gamme saisonnière
    vendu hors de sa gamme dans les dernières semaines (≥ ½ carton / semaine) compte comme produit de toute l'année ;
  - tendance du réseau = ventes de nos produits dans tous les magasins (panel) des 8 dernières semaines ÷ mêmes semaines un
    an plus tôt (semaines N-1 avec données), bornée par `forecast.trend_min` / `forecast.trend_max` ; sans panel : expéditions ERP.
  Palettes (cartons par palette, fiche « 1 / 1 / 1 » = inconnu), heures de production (lot de la feuille : pièces, personnes ×
  minutes, sinon gamme opératoire ; produits sans temps : moyenne pondérée des autres, affichée « szacunek »), capacité
  (`forecast.capacity_hours`, 0 = salariés de production actifs × `week.fte_hours`, réduite les jours fériés PL ; pic sans la
  semaine en cours). Achats 13 semaines : recette par carton × cartons, stock (inventaire + réceptions − consommation, comme
  Zaopatrzenie), en route, semaine de manque, « commander avant » = lundi de la semaine de manque − délai (dernière offre du
  fournisseur actuel `n_mp_offer.lead_time_days`, sinon `forecast.lead_days`), à acheter en emballages entiers (mode
  « na stanie » : jusqu'au max), valeur ; matière utilisée seulement par des saisonniers = « sezon ».
- Argent nécessaire pour produire (migrations 142 et 143, 30/10/2026) : seulement les sorties de caisse, par semaine :
  cartons × (matières et emballages de la recette `pre_calculated_raw_price_per_carton` + salaires et médias du département de
  production `final_product.id_section` par carton du département + départements partagés de production, p. ex.
  Pakowanie / wysyłka, et médias non répartis, par carton de l'entreprise), moyennés sur les `forecast.cost_months` (3) derniers
  mois de coûts (clôturés d'abord, `SectionUnitCostService`). Sans investissements (amortissement des départements + frais
  fixes « kredyt / leasing / rata / inwestycja / pożyczka / środki trwałe » ou de catégorie INVESTMENT, LEASING, CREDIT,
  LOAN, CAPEX — `ForecastService::isInvestment`, 30/10/2026 ; migration 144 : les voitures des frais fixes « Auto - 1 »,
  « Drugi Auto », « Passat », « Toyata » (catégorie LOGISTICS) sont des leasings, « (leasing) » ajouté à leur nom dans tous
  les mois, montants inchangés), sans les autres frais fixes (Finanse) et sans le département
  Administracja (nom « Administ… ») : montrés pour information au survol ; logistique à part. Ligne
  « Pieniądze na produkcję » et sa part matières, tuile du total 13 semaines (matières, salaires, médias, départements
  partagés) ; `n_forecast_run.production_cost` : total enregistré à la validation.
- Qui prend combien (31/10/2026, sans migration) : clic sur « Razem kartony » (une semaine ou toutes), sur un produit ou
  « Klienci × tygodnie » → fenêtre clients × 13 semaines (cartons). Commandes déjà passées : chez le client qui a commandé ;
  prévision au-delà des commandes : répartie selon la part du client dans le produit (8 dernières semaines ; produits
  saisonniers : même gamme un an plus tôt ; sans historique : commandes de la fenêtre, sinon « Bez klienta »). Cartons entiers
  (plus grands restes), somme = prévision du produit et de la semaine (`ForecastService::clientSplit`,
  `ForecastRepository::cartonsByClient`). Clic sur un client : ses produits. Pas enregistré à la validation.
  Palettes par client et semaine (31/10/2026) : cartons de chaque produit ÷ cartons par palette de la fiche produit (produit sans
  ce nombre : sans palettes, signalé). Client dans la prévision ou exclu (migration 145, `n_forecast_client_excluded` :
  `client_id` (0 = « Bez klienta »), `created_by`, `created_at` ; une ligne = client exclu) : bouton « w prognozie » / « poza »
  dans la fenêtre, POST `/ajax/forecast/clients/{id}` `{included}`. Les cartons d'un client exclu (commandes et prévision
  selon sa part) sont retirés des produits avant toutes les sommes : cartons, palettes, heures, argent pour produire, achats ;
  pour tout le monde jusqu'à réinclusion. Tuile « Kartony 13 tygodni » : « bez N klientów (X kart.) » ; liste des exclus dans
  l'enregistrement de la validation (`payload.clients_excluded`).
- API du panel TF Buddy, lecture seule (GET) : `/product-availability-periods` (gammes, `from_md` → `to_md`, aussi à cheval
  sur le Nouvel An ; gamme saisonnière = active et ≤ 200 jours, gamme de plus de 200 jours = toute l'année, 1 jour = ignorée),
  `/product-availability-periods/{id}/products` (produits des magasins), `/material-suppliers` → SL Food →
  `/material-suppliers/{id}/catalog/products` (nos SKU = `final_product.id`),
  `/material-suppliers/{id}/analytics/raw-material-sales-all-shops?from=…&to=…` (pièces vendues dans tous les magasins,
  semaine par semaine : semaines de la tendance et semaines des gammes un an plus tôt). Une semaine terminée déjà lue n'est
  plus demandée (cache dans le dernier relevé) ; 40 semaines et 120 s au plus par lecture, le reste à la suivante.
  `Bin/forecast_panel_read.php` (cron 06:10 tous les jours, lit seulement si le dernier relevé date d'avant lundi 6:00 ;
  aussi au déploiement), bouton « Odśwież z panelu » ; la page relit aussi le lundi si un relevé existe déjà.
- `n_forecast_panel_read` : relevés (`read_at`, `read_by`, `ok`, `error`, `payload` JSON : gammes, produits, catalogue,
  ventes par semaine, semaines en échec) ; 20 derniers gardés.
- `n_forecast_panel_link` : produit de magasin d'une gamme (`panel_product_id`) → notre produit (`id_final_product`) ou
  « nie od nas » (`ignored`). Sans ligne : suggestion d'après le nom (mots sans accents, nombres ni mots outils, mots presque
  égaux « frangipane » / « frangipanne » ; égalité → produit actif), utilisée si ≥ 60 % (« auto », à confirmer).
- `n_forecast_run` : validation de la semaine (une par lundi `week_start`, revalidation = remplacement) : tendance, cartons,
  dont saisonniers, heures, valeur des achats, `payload` JSON (semaines, produits × semaines et source, gammes, achats),
  note, `approved_at`, `approved_by`. Tâche du lundi « Zatwierdź prognozę 13 tygodni » (KIEROWNIK_PRODUKCJI, 30 min, lien
  `/production/forecast`, `auto_code` `forecast_approved` = validation dans la semaine) ; « Cofnij zatwierdzenie » supprime la ligne.
- Paramètres (`n_prod_param`) : `forecast.weeks` 13, `forecast.trend_weeks` 8, `forecast.trend_min` 0,5,
  `forecast.trend_max` 2, `forecast.capacity_hours` 0, `forecast.lead_days` 7, `forecast.cost_months` 3 ; `logistics.plan_weeks` 12 → 13 (plan
  logistique sur les mêmes 13 semaines, seulement s'il valait encore 12).

### Assistant prix « Kreator cen » (migration 138, 30/10/2026)

- Page : Analiza › Marże i koszty › « Kreator cen » (`/finances/price-wizard`, maquette 218 validée ; aussi depuis Marża
  kategorii et à la fin du kreator faktur, après « Zatwierdź faktury »). `PriceWizardService`, `PricePublicationService`,
  `price-wizard.js`. Trois étapes :
  1. « Koszty i marże » : factures ouvertes et lignes à vérifier (lien vers le kreator faktur), « Przelicz koszty i marże »
     (coût matières de toutes les recettes aux prix actuels, le même calcul que « Przelicz wszystkie marże »), marge par
     catégorie avant → après, écart à la marge de base, effet € / mois, produits dont le coût a changé avec leurs causes
     (matières de la recette dont le prix a changé : `raw_material_price_changelog`), matières qui ont changé ;
  2. « Nowe ceny » : par catégorie la marge de base (`category_product.target_margin_percentage`) et le coefficient matières
     (`n_category_price_rule.material_coef`, vide = `prices.material_coef_default`). Prix cible du carton = le plus haut de
     coût complet ÷ (1 − marge) et coût matières × coefficient ; jamais plus bas que le prix actuel, hausse au plus
     `prices.client_cap_pct` % (8 %) d'un coup, arrondi vers le haut à `prices.client_round_eur` (0,05 €) ; prix modifiable
     à la main dans ces limites. Produit déjà dans une publication prévue : non sélectionnable ; relevé par l'assistant il y a
     moins de `prices.client_interval_days` jours : non coché. Alerte Belgique (magasin ÷ usine sous × 2,5 après la hausse) ;
  3. « Publikacja » : date d'effet (aujourd'hui au plus tôt, au plus un an), prix clients calculés : prix égal au standard →
     nouveau standard ; prix négocié → même % (remise gardée), même plafond ; client décoché = ses prix ne bougent pas.
     Canaux : site franchise (client avec `franchisee_uid`, tarif par le webhook existant `PricelistPusher`), e-mail avec le
     tarif PDF à la date (adresse du document « Cennik » de la Dystrybucja dokumentów, langue du client).
- Tables : `n_category_price_rule` (id_category, material_coef) ; `n_price_publication` (effective_date, status planned |
  applied | cancelled, notify_email, notice_days, products, client_prices, avg_pct, month_eur, created_by, applied_at,
  cancelled_by/at) ; `n_price_publication_item` (id_product, id_customer 0 = prix standard, old_price, new_price, manual,
  status planned | applied | skipped) ; `n_price_publication_client` (site, email, email_status queued | skipped | failed,
  id_message, site_status sent | retry | failed | no_site, site_attempts, site_version).
- Exécution (`Bin/mail_dispatch.php`, toutes les 5 minutes) : e-mail « Nouveaux prix à partir du » (modèle
  `price_change_notice` fr / nl / pl / en, PDF `ClientPriceListService::generateFuturePDF` avec « Valable à partir du »)
  `prices.notice_days` (7) jours avant ; le jour J, prix standard et prix clients changés seulement si la base a encore le
  prix du plan (sinon ligne « skipped »), historique `n_product_price_change` (source `price_wizard`, auteur de la
  publication) ; un client qui a déjà reçu l'e-mail avec ce tarif ne reçoit pas en plus « Votre tarif mis à jour »
  (`n_price_list_state` noté) ; puis tarif vers le site franchise, une tentative par passage, au plus 12 (`failed`), sans clé
  de webhook → `no_site` (copie de test : clés vidées, rien ne part). Publication prévue annulable jusqu'à la veille.
- Paramètres : `prices.material_coef_default` (2,5), `prices.notice_days` (7) ; `prices.client_cap_pct` et
  `prices.client_round_eur` valent pour les prix standard et les prix clients (réglables par l'administrateur dans l'assistant).
- Ajax (session ERP, accès « Marże i koszty ») : `GET /ajax/price-wizard/costs`, `POST …/recalculate`, `GET …/prices`,
  `PUT …/categories` (rows id, margin, coef), `PUT …/params`, `POST …/preview` (items product_id, price, manual),
  `POST …/preview-pdf`, `GET|POST …/publications` (date, items, exclude, notify), `GET …/publications/{id}`,
  `POST …/publications/{id}/cancel` ; PDF `GET /finances/price-wizard/publications/{id}/pdf?customer=`.

### Factures d'achat — assistant « toutes les lignes » (migration 137, 30/10/2026)

- Page : Zaopatrzenie › Faktury › « Kreator : wszystkie pozycje » (`/workshop/supply#tab=invoices&inv=wizard`, maquettes 217
  1–3, `invoice-wizard.js`). Toutes les factures ouvertes de matières et emballages (KSeF, fichiers) :
  1. « Pobierz z KSeF » : un tableau des factures (lignes vérifiées n / m, remarques : nouveaux noms, prix, quantité /
     commande, litigieuses, fournisseur ERP manquant) ; le bouton KSeF ne fait que lire (aucun envoi) ;
  2. « Sprawdź pozycje » : les lignes de toutes les factures dans un seul tableau, facture par facture ; la ligne en cours
     est ouverte (matière : suggestions, « nom connu » si le même nom est déjà relié chez un autre fournisseur ; unité ;
     prix comparé au prix de base et à la commande) ; Entrée valide et passe à la suivante, K = prix de base inchangé, →
     passer ; « Oznacz jako sporną » (note). Une correspondance mémorisée s'applique tout de suite aux lignes sans matière
     des autres factures ouvertes du même vendeur (NIP ou fournisseur ERP). Une hausse révélée par une nouvelle
     correspondance garde la correspondance et demande « nouveau prix / inchangé ». « Zatwierdź wszystkie bez ryzyka » :
     lignes reliées dont la seule remarque est un prix plus bas ;
  « Usługa → Finanse » (étapes 1 et 2) : une facture pas encore traitée (sans prix importés ni commande) part dans Finances
  avec la fenêtre de classement habituelle (catégorie du calculateur de coûts, mois, règle fournisseur) ; celles qui
  ressemblent à un service (vendeur Orlen → carburant, lignes d'énergie…) sont signalées « wygląda na usługę ».
  3. « Zatwierdź faktury » : factures dont toutes les lignes sont vérifiées (auto ou à la main), sans ligne litigieuse,
     fournisseur ERP connu — comme « Zatwierdź fakturę » (prix vers les fiches, réception depuis la commande ou la facture,
     acceptation ; avertissements confirmés par la sélection).
- État d'une ligne : auto (reliée, sans remarque), checked, disputed, todo (`reason` new | price | qty | other). Colonnes
  `n_inv_line.check_status` (checked | disputed), `check_note`, `checked_by`, `checked_at` ; une nouvelle correspondance (matière
  ou facteur) les remet à zéro avec la décision de prix.
- API : `GET /supply/invoices/wizard`, `POST /supply/invoices/{id}/lines/{lineId}/check` (action check | dispute | reset,
  raw_material_id, factor, remember, price accept | keep, note), `POST /supply/invoices/wizard/check-safe`,
  `POST /supply/invoices/wizard/process` (ids).

### Stock nécessaire selon le plan logistique et périodes de niveaux d'exception (migration 136, 30/10/2026)

- Page : Magazyn › « Prognoza zapasu i okresy » (`/workshop/stock/forecast`, maquettes 216 1–3). Prévision sur les 12
  semaines du plan logistique (`LogisticsPlanService::plan`), produits MTS seulement :
  - par semaine : départ (calendrier = `n_logistics_plan_week.ship_date`, même écriture que Plan 12 tygodni › Święta i
    kierowcy), retour du camion (fin du trajet du Kalendarz tras, sinon livraison + jours de route ; + 1 jour par jour
    de blocage sur la route : férié d'un pays de transit ou du pays de départ, dimanche dans un pays de transit), jours
    de travail (lun.–ven. moins les fériés et fermetures du pays de départ), palettes au départ (plan) ;
  - cartons du produit au départ : semaine avec commandes → cartons commandés, sinon palettes du client au plan × ses
    cartons par palette × part du produit (fenêtre de moyenne du plan, `logistics.plan_avg_weeks`) ;
  - stock nécessaire avant le départ = cartons × (1 + `stock.plan_safety_pct`) + départs des semaines sans production ;
  - projection depuis le stock d'aujourd'hui (`carton_stock`, libres + sur palettes) : production de la semaine =
    stock nécessaire − stock après le départ précédent (0 si négatif ou semaine sans production), stock avant départ
    = stock + production ; en palettes (cartons ÷ cartons par palette) avec les palettes des autres produits, comparé
    aux places du congélateur (`stock.freezer_pallet_places`, 0 = non renseigné). Bascules « Zapas potrzebny / Do
    produkcji / Zapas przed wyjazdem » et « Kartony / Palety » (mémorisées dans le navigateur).
- Périodes : `n_stock_period` (nom, du / au, `status` draft | approved, créé / validé par / le) et `n_stock_period_item`
  (période, `n_prod_item`, `stock_min` / `stock_max` en unité de base comme `n_prod_product_settings`, niveaux normaux
  et prévision moyenne / pic au moment du calcul). Niveaux proposés par la prévision : min. = cartons moyens par départ
  dans la période × `stock.suggest_min_weeks`, max. = pic du stock nécessaire dans la période (≥ min.) ; modifiables
  tant que la période est en projet. Validation refusée si elle chevauche une autre période validée ou si elle est
  passée.
- Effet (`App\Domain\Stock\StockLevels`) : entre `date_from` et `date_to` d'une période validée, les niveaux de la
  période remplacent ceux de la fiche produit dans Stan magazynu (badge « Okres wyjątkowy », valeur normale en
  dessous), le plan de production, le récapitulatif de production et les tablettes ; le lendemain de la fin, retour
  automatique aux niveaux normaux. La fiche produit garde toujours les niveaux normaux.
- Paramètres (`n_prod_param`, Ustawienia › Pozostałe › Stany magazynowe) : `stock.plan_safety_pct` (25 %),
  `stock.freezer_pallet_places` (0).

### Palette mixte — couches complètes de plusieurs produits empilées (migration 135, 30/10/2026)

- Page : Kartony i palety › « Paleta mieszana » (`/products/cartons/mixed?date=…&customer=…`, maquette 214). Pour un
  client et un jour d'expédition : les produits de la palette (format du carton, cartons par couche, kg / carton,
  cartons / commandés, hauteur, poids, kg par couche, couches − / +, au plus les cartons commandés), « + Dodaj produkt
  klienta », tuiles (hauteur, poids, cartons, places camion « było n »), contrôles, vue de côté (ligne de hauteur max.) et
  vue de dessus de chaque couche ; « Etykieta palety (PDF) », « Zatwierdź paletę mieszaną ». En dessous, les
  propositions du jour : clients dont les restes (cartons hors palettes pleines) tiennent sur une palette et font gagner
  au moins une place (palettes du client maintenant → après).
- Calcul (`App\Domain\Logistics\MixedPallet`) : ordre automatique du bas vers le haut — couche incomplète seulement tout
  en haut, plus grand plancher en bas (petits cartons toujours au-dessus), même plancher (± 15 mm, même nombre par
  couche) : couche la plus lourde en bas. Contrôles : hauteur ≤ la limite la plus basse des produits (produit → carton →
  `logistics.pallet_max_height_mm`) et intérieur du camion ; poids ≤ limite ; charge sur le carton du bas de chaque
  produit comparée à sa palette mono-produit ((couches ERP − 1) × kg) ; plancher différent au-dessus seulement sur des
  couches pleines ≥ 90 % de la palette. Propositions : restes du client, les plus hauts d'abord, sur la première palette
  où tous les contrôles passent.
- Tables : `n_mixed_pallet` (jour, client, palette, hauteur, poids, validé par / le) et `n_mixed_pallet_item`
  (position 1 = en bas, produit, cartons, couches, cartons par couche).
- Une palette validée compte pour une place dans Dzień wysyłki (`LogisticsService::palletize` : ses cartons, au plus les
  commandés, sortent des palettes normales ; type « stack » en tête de la carte client, lien vers la page), dans
  l'écran de palettisation et la tablette d'emballage (« commande complète » + ordre d'empilement des couches). Le plan
  12 semaines (historique) n'en tient pas compte.
- Étiquette PDF : `/products/cartons/mixed/label?pallet=…` (ou brouillon `date`, `customer`, `items=produit:cartons,…`),
  aussi dans « Wzory dokumentów ».
- Plan 12 semaines › Święta i kierowcy : le jour de départ de la semaine se choisit avec un calendrier (n'importe quel
  jour de la semaine), bouton « auto » pour revenir au jour par défaut.

### Poids et hauteur des palettes — logistique et emballage (30/10/2026, sans migration)

- Calcul (`App\Domain\Logistics\PalletLoad`) : poids = tare palette (EUR 25 kg, industrielle 30 kg) + cartons × (pièces ×
  g + poids du carton) ; hauteur = base 144 mm + couches × hauteur du carton ; cartons du même format posés ensemble
  (couches = cartons ÷ cartons par couche, arrondi au-dessus), formats différents empilés (maquette 214). Limites : la plus
  basse des produits de la palette (produit → sorte de carton → `logistics.pallet_max_*` ; la limite d'un produit ou d'un
  carton remplace le paramètre, même plus haute) et `logistics.truck_inner_height_mm`.
- Données (`CartonService::palletData`) : carton du produit (fiche, PPWR ou recette — catalogue `n_carton` : hauteur,
  poids `raw_material.packaging_unit_weight_g`). Cartons par couche : ERP si cohérent (`cpl × lpp = cpp` et tient sur la
  palette), sinon calculé avec les dimensions du carton sur une palette EUR (`PalletLayout`) — l'ERP a souvent 1/1/1 ou 10/10/10.
- Où : Dzień wysyłki (carte client : chaque palette planifiée en kg · m, la plus lourde / la plus haute, « ⚠ n ponad
  limit ») ; Ekran paletyzacji (palettes scannées P… avec kg · m, « teraz » et « po zamówieniu » par client, une alerte
  par client si une palette scannée dépasse une limite) ; tablette d'emballage, mode Paletyzacja (hauteur du carton par
  produit, palettes du client maintenant et à la commande complète, 6 au plus + résumé).

### Prix clients par la marge — client par client (migration 134, 30/10/2026)

- Page : Marża kategorii › onglet « Ceny klientów wg marży » (`/finances/category-margin?view=clients&client=…`,
  maquette 2). À gauche les clients actifs avec cennik (effet € / mois, prix à monter) ; à droite le cennik du client :
  prix actuel, nouveau prix, différence € et %, effet / mois, statut ; bascule « Obecna · nowa · różnica / Tylko
  zmiany / Wykres ».
- Règle (`ClientPriceService::rule`) : prix cible du produit = coût complet ÷ (1 − marge cible de la catégorie) (le
  même que « cena wg celu » de la page) ; cible du client = cible × min(1, prix client ÷ prix standard) si la remise
  est conservée ; nouveau prix = max(actuel, min(cible client, actuel × (1 + plafond))) arrondi vers le haut.
  Jamais plus bas que le prix actuel. Statuts : `up` (cible atteinte), `limited` (plafond, reste à la prochaine hausse),
  `locked` (cible ≤ actuel : prix gardé), `none` (catégorie sans cible), `recent` (monté il y a moins de
  `prices.client_interval_days` jours : pas de nouvelle hausse).
- Effet / mois = différence × cartons moyens du client par mois (WDT, 12 derniers mois).
- « Zastosuj u klienta » (`POST /ajax/category-margin/client-prices`) : le serveur recalcule, ne monte que les produits
  cochés, seulement si le prix en base est toujours l'actuel ; chaque hausse dans `n_product_price_change`
  (`source = 'margin_clients'`, auteur, date). Accès : page marges (`margins`).
- Paramètres `n_prod_param` (administrateur, bouton « Parametry » ou Ustawienia) : `prices.client_cap_pct` (8 %),
  `prices.client_round_eur` (0,05 €), `prices.client_keep_discount` (1), `prices.client_interval_days` (90 j).

### Kartony i palety : limites de palette par carton, édition des produits dans la fenêtre (migration 133, 30/10/2026)

- `n_carton.pallet_max_height_mm` / `pallet_max_kg` (NULL = `logistics.pallet_max_height_mm` / `logistics.pallet_max_kg`).
  Ordre : limites propres du produit (étape Paleta, `n_prod_item_logistics`) → sorte de carton → paramètres. Le nombre
  de couches de chaque produit est calculé avec son poids (pièces × g + carton) : produit plus lourd = moins de couches
  (`best.by_weight`).
- Fenêtre du carton (`/products/cartons?id=…`) : pour chaque produit, pièces / carton, g / pièce, cartons par couche ×
  couches (cartons par palette = produit) modifiables dans le tableau ; enregistrement par l'API de la fiche produit
  (`PUT /produits/items/{id}/logistics`, mêmes contrôles et journal) ; « zastosuj » = plan qui tient (hauteur et poids
  de palette) ; « Dopasuj układ palety » applique ce plan à tous les produits en alerte.

### Factures d'achat : fenêtre « nouveau prix de base » (30/10/2026, sans migration)

- Prix d'une matière sur la facture au-dessus du prix de base (calcul des recettes) : au lieu du seul message
  `INVOICE_PRICE_UP_PENDING`, une fenêtre (`public/assets/js/workshop/invoice-price-up.js`) montre par matière prix de
  base → nouveau prix, hausse en %, surcoût sur la facture, nombre de recettes, avec « Nowa cena bazowa » (coché) ou
  « Bez zmian » ; enregistrement `POST /supply/invoices/{id}/price-decisions`, puis l'action reprend (valider la
  facture, importer les prix).
- Ouverte depuis : liste des factures (bouton « podwyżka ceny »), fiche facture (bouton + ligne « Nowa cena
  bazowa »), « Do obsłużenia › Zatwierdź ceny » (`#tab=invoices&inv=ID&pu=1`), Finanse › faktury i koszty.

### Produits test : nettoyage des anciennes propositions (migration 132, 30/10/2026)

- 22 produits test « Propozycja » de 2023–2024 retirés à la demande (Boulangerie, Cake au yaourt, Cookie's, Fond à
  garnir, Plats Preparés, Quiches, Viennoiseries — liste dans `migrations/tfb/132_test_products_cleanup.sql`),
  avec leur historique (`test_product_changelog`). Correspondance catégorie + nom ; seulement le statut 1 et sans
  carte / essai / photo du CRM (`n_dev_*`).
- Copie avant suppression : `n_test_product_removed` et `n_test_product_changelog_removed` (même structure que les
  tables d'origine) ; pour remettre un produit : `INSERT INTO test_product SELECT * FROM n_test_product_removed
  WHERE id = …` puis la même chose pour l'historique.

### Planning logistique : nombre de tournées par semaine (migration 131, 29/10/2026)

- `n_vehicle.trips_per_week` (1 à 4, défaut 1) : nombre de tournées (shifts) que le chauffeur fait avec le camion
  dans la semaine — standard : une tournée par chauffeur par semaine. Réglable dans **Pojazdy** (colonne « Tras /
  tydz. » et étape Pojemność de l'assistant) et dans le **Kalendarz tras** (boutons 1 2 3 4 sous le camion,
  `PUT /ajax/logistics/plan/vehicles/{id}/trips`).
- `n_logistics_plan_vehicle.trips` : exception pour une semaine (⚙ de la case camion × semaine dans la Tablica) ;
  NULL = valeur du camion.
- Capacité de la semaine dans la Tablica = places du camion × tournées (`places`, `places_per_trip`, `trips` de la
  case) ; la répartition automatique remplit jusqu'à cette capacité.
- `n_logistics_trip.trip_no` (clé semaine + camion + n°) : un trajet par tournée. Clients répartis dans l'ordre des
  arrêts jusqu'aux places du camion, la dernière tournée prend le reste ; tournée suivante = fin de la précédente
  (repos journalier 11 h au lieu du repos hebdomadaire), arrondie au quart d'heure ; repos hebdomadaire après la
  dernière. Contrôle supplémentaire : conduite de la semaine ≤ 56 h (`WEEK_DRIVE`) ; avertissement si une tournée
  modifiée commence avant la fin de la précédente (`OVERLAP`). Routes : `PUT|DELETE
  /ajax/logistics/plan/trips/{semaine}/{camion}/{n°}`.
- Validation de la semaine : jour d'expédition du client = jour de départ de la tournée qui le décharge.

### Planning logistique : calendrier des trajets (migration 130, 29/10/2026)

- Onglet **« Kalendarz tras »** de `/logistics/plan` (à côté de Prognoza, `#trasy`) : une ligne par camion, frise
  Dzień / 4 dni / Tydzień / Miesiąc, phases du trajet : chargement, conduite, pause 45 min, repos journalier 11 h,
  déchargement chez chaque client (dans l'ordre des arrêts des tournées, `n_tour_customer.stop_order`), retour, repos
  hebdomadaire. Clients = clients sur le camion dans la Tablica ; départ = jour de départ de la semaine + heure du
  camion (`n_vehicle.departure_time`) sinon `logistics.trip_departure_time`.
- Trajet par défaut : modèle (`logistics.trip_load_min`, `trip_drive_out_min`, `trip_between_min`, `trip_unload_min`
  + `trip_unload_pallet_min` par palette, `trip_drive_back_min`, `trip_weekly_rest_min`) ; pauses et repos insérés
  selon le règlement UE 561/2006 (`App\Domain\Logistics\TripPlan` : conduite ≤ 4 h 30 sans pause de 45 min — ou
  15 + 30 —, conduite journalière ≤ 9 h, 10 h deux fois par semaine, repos journalier 11 h, réduit 9 h). Contrôle
  affiché sous chaque camion.
- Tout est modifiable : glisser un bloc (la phase précédente s'allonge / raccourcit ; premier bloc = heure de départ),
  glisser le bord droit (durée), toucher un bloc (type, client, durée, ordre, ajouter, supprimer).
  `n_logistics_trip` (clé semaine + camion) garde le trajet modifié (`start_at`, `phases` en JSON) ; « Przywróć
  szablon » le supprime ; « Wstaw przerwy i odpoczynki » recalcule pauses et repos des trajets modifiés de la semaine.
  Avertissements si le trajet modifié ne correspond plus au plan (client sans déchargement, client qui n'est plus sur
  le camion, jour de départ changé).

### Kartony i palety : catalogue des cartons et étape « Paleta » de la fiche produit (migration 129, 29/10/2026)

- **Rail Produkt › Kartony i palety** (`/products/cartons`) : un carton = un article d'emballage de la liste des matières
  (`raw_material` : nom, prix, fournisseur, poids du carton `packaging_unit_weight_g`, le même que dans BDO). `n_carton`
  (clé `id_raw_material`) ajoute les dimensions extérieures (mm), la **charge maximale** (kg de contenu), le type
  (klapowy, fasonowy, klejony, inny), une note et actif / archivé. Catalogue de départ : articles « karton… », cartons de
  `carton_dimension` et cartons de vente PPWR ; dimensions reprises de `carton_dimension` (table ERP), sinon des
  dimensions PPWR. À l'enregistrement, les dimensions sont aussi écrites dans `carton_dimension` (id = article).
- Pour chaque carton : disposition sur palette EUR 1200 × 800 ou industrielle 1200 × 1000 (`App\Domain\Logistics\PalletLayout` :
  couche uniforme ou en deux blocs, débord autorisé, couches jusqu'à la hauteur max. palette comprise et au poids brut
  max.), produits dans ce carton (carton choisi dans l'étape « Paleta », sinon carton de vente PPWR, sinon le carton de la
  recette) avec contrôle : contenu (pièces × poids) ≤ charge max., cartons par couche / hauteur / poids de la palette
  enregistrés dans l'ERP (`final_product.cpl`, `lpp`, `cpp`) possibles avec ce carton.
- **Fiche produit, nouvelle étape 3 « Paleta »** (maquette 1) : carton du catalogue ou dimensions libres, contraintes
  (hauteur max., poids max., débord), trois propositions (le plus par palette, gerbage de 2 palettes dans le camion si
  plus de cartons par place, palettisation actuelle de l'ERP avec ses erreurs), vue de dessus de la couche, vue de côté
  avec règle, chiffres (cartons, hauteur, poids, volume, camion de 33 places, ventes par semaine, palettes par mois).
  Enregistrement : `cpl`, `lpp`, `cpp` dans l'ERP ; `n_prod_item_logistics.id_carton`, dimensions et tare copiées,
  `pallet_max_height_mm`, `pallet_max_kg`, `pallet_overhang_mm` (NULL = paramètres). L'étape 2 s'appelle « Opakowanie »
  (pièce → emballage → carton, conservation) ; l'assistant passe de 8 à 9 étapes : les étapes enregistrées ≥ 3 sont
  décalées de 1 et `ck_tfb_settings_step` accepte 1 à 9.
- Paramètres (`n_prod_param`) : `logistics.pallet_max_height_mm` (1800), `logistics.pallet_max_kg` (1000),
  `logistics.pallet_overhang_mm` (0), `logistics.truck_inner_height_mm` (2550 ; gerbage quand 2 palettes + 100 mm tiennent).

### Planning logistique : Allemagne en transit, « Wszystko do rozplanowania » (migration 128, 29/10/2026)

- **Allemagne (DE) = pays traversé** : le camion PL → BE passe par l'Allemagne, où les camions n'ont pas le droit de
  rouler les jours fériés. Paramètre `logistics.plan_transit_countries` (défaut `DE`, liste séparée par des virgules,
  vide = aucun) : un férié DE entre le jour de départ et le jour de livraison décale le départ comme un férié PL / BE
  (motif « przejazd 18.11 — … (DE, zakaz ruchu ciężarówek) »). Fériés DE calculés : seulement les fériés nationaux
  (1.01, Vendredi saint, lundi de Pâques, 1.05, Ascension, lundi de Pentecôte, 3.10, 25.12, 26.12) ; les fériés
  régionaux (Fête-Dieu, Toussaint en NRW…) s'ajoutent à la main (onglet « Święta i kierowcy », pays « DE (przejazd) »),
  « Jedziemy » ignore un férié DE. `n_logistics_holiday.country` accepte PL, BE, DE.
- **« Wszystko do rozplanowania »** (onglet Tablica, à côté de « Rozdziel automatycznie ») : tous les clients enlevés des
  camions dans les semaines dont le départ est aujourd'hui ou plus tard (`n_logistics_plan_load.id_carrier_vehicle` →
  NULL, palettes saisies à la main conservées) ; semaines validées → à revalider.
- `n_shipping_assignment.plan_set` : 1 = véhicule du jour écrit par la validation du plan. Une nouvelle validation peut
  le changer et « Wszystko do rozplanowania » l'enlève (le jour revient à la tournée du client) ; un choix fait à la
  main dans Dzień wysyłki ou Załadunek remet `plan_set` à 0 et n'est jamais touché par le plan.

### Recette d'un produit = 1 carton, retours du testeur (migration 127, 29/10/2026)

- **Recette d'un produit fini = 1 carton** (le produit vendu entier) : le calcul de recette et les marges la lisent
  ainsi (`precalculated_material_cost` → `final_product.pre_calculated_raw_price_per_carton`). L'assistant « Nowy
  produkt z recepturą » demandait la recette « pour 1 pièce » : il demande maintenant le carton, avec un interrupteur
  « je saisis pour 1 pièce » qui multiplie les quantités par les pièces par carton à l'enregistrement.
- `n_recipe_unit_check` : décisions sur les recettes déjà enregistrées qui ressemblent à 1 pièce (masse des matières en
  g / ml de la recette aplatie ≈ poids d'une pièce, < 35 % du carton ; ou produit créé pendant une visite de
  l'assistant d'après `n_activity_view` avec une masse < 50 % du carton). Rien n'est modifié sans décision : `scaled`
  (quantités × `factor` = pièces par carton, nouvelle version de recette, aplatissement, étiquette, coût et marges
  recalculés) ou `kept` (déjà au carton) ; `cost_before` garde le coût d'avant. Recette partagée par plusieurs produits
  ou utilisée dans une autre recette : correction manuelle seulement.
- **Produit test lancé** : `test_product.id_recipe` = recette du produit ERP (au lancement, et rattrapage des tests
  déjà lancés sans recette par la migration). Avant le lancement, la recette du test se choisit sur la fiche du test
  (liste avec recherche) ; sans recette, l'assistant produit reprend les ingrédients de l'étape « Koszt i cena » de la
  fiche technique (quantités du lot de la fiche ramenées à 1 carton).
- Prix standard (fiche produit, étape « Ceny i zatwierdzenie ») : suggestions coût matières / carton × 2,5 · 3 · 4
  (`new_product.factory_coefs`, défaut × 3) ÷ `exchange_rate_euro`, arrondies à 0,10 €, et prix usine de la fiche du
  nouveau produit (`n_dev_card.cost_pc_pln` × coefficient ÷ cours × pièces par carton) ; rien n'est enregistré sans
  « Zapisz ».

### Planning logistique sur 12 semaines (migration 126, 29/10/2026)

Page Logistyka i pakowanie › Ciężarówki : plan i tydzień › **Plan 12 tygodni** (`/logistics/plan`), trois onglets :
tableau camions × semaines (clients glissés sur un camion de la même semaine), prévision (clients × semaines), fériés
et chauffeurs.

- **Palettes d'un client par semaine** (comptées comme la journée d'expédition : `PalletCalculator` sur chaque envoi
  client + jour, puis somme de la semaine) : commandes déjà passées pour la semaine ; sinon palettes saisies à la main ;
  sinon palettes de la même semaine ISO l'an dernier (N-1) × coefficient du client ; sans N-1 cette semaine-là :
  moyenne des 13 dernières semaines (`logistics.plan_avg_weeks`, 3 mois). Coefficient = saisi
  (`n_logistics_customer_coef`) ou tendance (moyenne par semaine avec envoi sur les 3 derniers mois ÷ mêmes semaines
  l'an dernier, bornée 0,3–5) ou `logistics.plan_coef_default`. × palettes supplémentaires de la semaine (événement en %,
  éventuellement répété chaque année sur la même semaine ISO). Arrondi au-dessus avec une tolérance de 0,2 palette.
  Clients du plan : envois dans les 3 derniers mois, ou commande / saisie dans les semaines du plan.
- **Départ** : jour habituel (`logistics.plan_ship_weekday`, mardi), décalé automatiquement (d'abord plus tôt dans la
  semaine) quand c'est férié au pays de départ (`logistics.plan_departure_country`, PL) ou quand la livraison
  (départ + `logistics.plan_delivery_days`) tombe un férié au pays de livraison (BE) ou un dimanche. Fériés légaux PL
  (dont la Wigilia depuis 2025) et BE calculés (`App\Domain\Logistics\Holidays`, Pâques comprise).
- **Tables** : `n_logistics_plan_week` (départ choisi à la main, validation), `n_logistics_plan_load` (client × semaine :
  palettes saisies, camion), `n_logistics_plan_vehicle` (camion × semaine : chauffeur de remplacement, indisponible),
  `n_logistics_event` (palettes supplémentaires %, `yearly`), `n_logistics_customer_coef`, `n_logistics_absence`
  (congés des chauffeurs `n_prod_employee`), `n_logistics_holiday` (jour chômé ajouté ou férié légal ignoré).
- **Validation d'une semaine** : le camion de chaque client est écrit dans `n_shipping_assignment` pour le jour de
  départ (Dzień wysyłki, Załadunek) — sauf si un camion y a déjà été choisi à la main. Toute modification d'une semaine
  validée la remet « à valider ».

### Nouveau produit : nos recettes dans « Nasz surowiec » (migration 125, 29/10/2026)

- Étape « Koszt i cena » : un ingrédient de la fiche peut être rapproché d'une matière **ou d'une de nos recettes**
  (`product_recipe` de type 1 Kompozycja, en g, et 2 Półprodukt, en pièces ; les produits finis — carton avec
  emballage — sont exclus, ainsi que les recettes sans rendement). Coût de ligne = quantité ×
  `precalculated_material_cost` ÷ `yield_quantity` (coût matières de la recette, recalculé par l'ERP à chaque
  changement de prix).
- Proposition automatique d'une recette seulement si tout le nom correspond et mieux qu'une matière (statut `check`, à
  confirmer) ; sinon elle figure dans les « Podpowiedzi ». Bouton « 🔍 Szukaj » : recherche dans toutes les matières et
  recettes (filtre Wszystko / Surowce / Nasze receptury).
- `n_dev_card.cost_json` : chaque ligne porte `id_raw_material` **ou** `id_recipe`.
- `n_dev_ingredient_map` : `id_raw_material` devient facultatif, nouvelle colonne `id_recipe` (le choix mémorisé est une
  matière ou une recette).

### Un seul assistant « Nowy produkt testowy » (migration 123, 29/10/2026)

- `/test-products/new` réunit l'ancien formulaire de produit test et l'assistant « Z filmu » : source = lien Facebook /
  Pinterest, fichier vidéo, fiche .docx ou **« Sam pomysł »** (produit créé tout de suite, sans fiche, étape Pomysł,
  tablette du groupe — remplace aussi le formulaire rapide de la page Etapy). `/test-products/from-video[/{id}]` redirige.
- `n_dev_card` reçoit `id_category` (`category_product`), `label_type` (0 petite, 1 grande étiquette) et `id_type`
  (`test_product_type`, NULL = Produkt) : champs de l'ancien formulaire, optionnels, recopiés dans `test_product` à
  l'enregistrement (et après correction). Statut du produit test : toujours « Propozycja » à la création (les étapes suivent).

### Nouveau produit depuis un film : karta technologiczna (migration 122, 29/10/2026)

- `n_dev_card` : une carte par produit test (`id_test_product` UNIQUE, CASCADE ; vide tant que le brouillon n'est pas
  enregistré). `status` (`draft` → `ready` → `saved`), source (`source_kind` : facebook, pinterest, file, text, docx ;
  `source_url`, auteur, titre, description du film `source_text`, `source_info` JSON : vidéo, couverture, remarques),
  `video_url` (lien du film, recopié sur le produit), nom, groupe (`id_group` → `n_prod_group`), ventes `weekly_sales`
  (pièces / semaine), `season_quarters` (« 1,4 » = Q1 et Q4 ; vide = toute l'année), carte `card_json` (titre, origine,
  portions, cuisson, temps, ingrédients par groupe, étapes, photos {frame_id, légende}, remarques), `model`, `id_prompt`,
  `usage_json` (jetons), `generated_at`, `generation_ms`. Brouillons non enregistrés supprimés après 30 jours.
- `n_dev_card_frame` : images de la carte (CASCADE) — `kind` `frame` (image extraite du film dans le navigateur, avec
  `at_ms`), `cover` (couverture du lien), `docx` (photo d'une carte .docx importée) ; JPEG / PNG en `MEDIUMBLOB`. À
  l'enregistrement, les images non choisies (`frame`) sont supprimées.
- `n_dev_product` reçoit `weekly_sales` et `season_quarters` (prévision par trimestre : 13 semaines × ventes / semaine).
- `n_ai_prompt` : versions du prompt (`code` = `dev_card`, texte, auteur) ; la dernière est utilisée, sinon le prompt par défaut.
- Paramètre `ai.model` (`claude-opus-5-5` par défaut, ou `claude-sonnet-5-5`). La clé Anthropic n'est jamais dans le dépôt :
  `n_app_secret` `anthropic_api_key`, chiffrée (comme les mots de passe des boîtes mail), saisie par un administrateur dans
  Ustawienia › Asystent AI (`/settings/ai`), ou variable d'environnement `ANTHROPIC_API_KEY` du serveur.
- Pages : `/test-products/from-video` (assistant), `/test-products/cards/{id}/card.docx` et `card.pdf` ; la carte apparaît
  sur la fiche du produit en développement (bureau) et sur la tablette du groupe. À l'enregistrement, l'opérateur choisit :
  « Wyślij na tablet » (`n_dev_product.on_tablet` = 1) ou « Drukuj kartę » (`on_tablet` = 0, PDF A4 ouvert pour l'impression ;
  « Wyślij też na tablet » ensuite, ou l'interrupteur de la fiche produit).

### Tablette d'emballage (migration 121, 28/10/2026)

- L'emballage n'est plus une page de l'ERP : tablette autonome `/tablet/packing` (appairée comme les autres tablettes,
  toute tablette appairée, sans PIN) ; l'ancienne adresse `/logistics/packing` y redirige (jour gardé).
- `n_packing_count.updated_device` : tablette qui a saisi le compteur (`updated_by` = utilisateur ERP, vide depuis la tablette).
- API : `GET /kiosk/packing?date=`, `PUT /kiosk/packing/{produit}` (`{date, packed}` 0–9999).
- Mode « Paletyzacja » de la même tablette (interrupteur discret dans l'en-tête, mémorisé sur la tablette) : opérateur
  (personnes pointées), transport (véhicule du jour ou tous les clients), clients, produits commandés — commandé / sur palette /
  reste ; cartons posés saisis au pavé numérique → `n_pallet_entry` (`source = tablet`, `id_employee` = opérateur,
  `id_device`) ; annuler la dernière saisie de la tablette (les cartons scannés ne s'annulent pas ici). API :
  `GET /kiosk/pallets?date=&vehicle_id=&customer_id=`, `POST /kiosk/pallets/entries`, `DELETE /kiosk/pallets/entries/{id}`.

### Logistique et emballage : palettisation, emballage, chargement du camion (migration 120, 28/10/2026)

- Lecture seule des tables d'origine : cartons commandés (`customer_order_product.quantity_carton`, commandes à charger),
  cartons scannés sur les palettes du jour (`palets_delivery` → `carton_pallet_connection` → `carton_stock`), stock libre
  (`carton_stock.status = 1`). Rien n'est modifié dans ces tables.
- `n_pallet_entry` : cartons posés sur palette saisis sur une tablette (jour, client, produit, nombre, source `tablet` / `erp`,
  personne, appareil) ; comptés avec les cartons scannés dans « sur palette ».
- `n_packing_count` : compteur d'emballage du jour par produit (`packed`) — **informatif**, ne crée aucun carton en stock ;
  `base_count` = cartons du produit en stock ou sur palette à la première saisie : les cartons étiquetés ensuite sont retirés
  du compteur (pas de double comptage).
- `n_load_plan` : plan de la remorque par jour et véhicule — `layout` (brouillon, JSON : place, client, n° de palette ;
  NULL = automatique), `sent_layout` + `version` + `sent_at` / `sent_by` (version envoyée à la tablette chauffeur).
- `n_load_check` : place chargée confirmée par le chauffeur (jour, véhicule, place) ; effacée pour les places modifiées
  quand le bureau envoie une nouvelle version.
- Le client → véhicule et l'ordre des arrêts du chargement écrivent le même `n_shipping_assignment` que « Plan dnia ».
- Fiche produit 1 / 1 / 1 (carton par couche, couches, cartons par palette = valeur par défaut) : traitée comme donnée
  palette **manquante** (signalée à corriger), et non comme 1 carton par palette.
- Places de la remorque : `n_vehicle.pallet_places`, sinon paramètre `logistics.default_pallet_places` (33) ; groupe
  d'emballage : paramètre `logistics.packing_group` (défaut `PAKOWANIE`) ; norme d'emballage : étape « Pakowanie » de la
  fiche (`n_prod_routing_step`), sinon moyenne des normes.
- API tablette chauffeur : `GET /driver/loading?date=&vehicle_id=`, `POST /driver/loading/checks`
  (`{date, vehicle_id, slot, loaded}`) ; `GET /driver/day` → `loading` (version, chargées / total).

### Tablette de groupe : réclamations clients (migration 119, 28/10/2026)

- Lecture seule de `complaint` (origine) : réclamations **acceptées par le bureau** (`acceptance_status = ACCEPTED`),
  `complaint_type = PRODUCT`, sur les produits du groupe de la tablette (groupe principal de la fiche
  `n_prod_product_settings.id_primary_group`, sinon premier groupe actif du rayon du produit). Rien n'est modifié.
- `n_complaint_translation` : traduction (DeepL) du commentaire du client et de la réponse du bureau dans la langue de la
  tablette (`lang` pl / fr), une fois par réclamation et par langue ; `source_hash` (sha1 des deux textes) → nouvelle
  traduction quand le texte change ; colonnes vides quand le texte est déjà dans la langue de la tablette.
- `n_translation_log.context` reçoit `complaint`.
- Photos : lien temporaire demandé au système franchise seulement quand l'équipe touche « Zdjęcia » (6 au plus).
  API : `GET /kiosk/complaints/summary`, `GET /kiosk/complaints?days=30|90|365`, `GET /kiosk/complaints/{id}/photos`.

### Achats de la semaine : demandes d'offres, offres avec minimum, gains (migration 118, 27/10/2026)

- `n_material_supplier` : fournisseurs d'un article (matière ou emballage), **1 à 3** (`sort`, `source` : `history` = rempli
  d'après le fournisseur actuel, les factures, commandes et offres des 24 derniers mois ; `manual` ; `round` = fournisseur
  demandé dans un achat). Moins de 3 fournisseurs sur un article consommé ces `buy.supplier_weeks` semaines = tâche
  « trouver des fournisseurs » (Zakupy, Do obsłużenia).
- `n_buy_round` : achats d'une semaine de production (code `ZT-AAAA-SS`, statut `draft` → `sent` → `ordered` → `closed`,
  réserve 0 / 5 / 10 %, paiement 7 / 14 / 30 jours ou proforma, livraison ou enlèvement, livraison au plus tard, réponse
  avant, prix pour le double, opérateur). `n_buy_line` : article à acheter (besoin, stock, en commande, quantité en
  emballages entiers, dernier prix le moins cher et son fournisseur), en unité de base.
- `n_buy_request` / `n_buy_request_line` : demande à un fournisseur avec seulement ses articles, langue, e-mail (file de la
  Communication, modèle `buy_request`), lien sans compte (`token`, 48 caractères hexadécimaux, `/offer/{token}`, valable
  jusqu'à la date de réponse + `buy.link_days` jours), ouverture, réponse, relances.
- `n_mp_offer` reçoit `source = weekly`, `id_buy_line`, `price_double_base` (prix pour le double), `min_value` (minimum en
  zł) et `min_note` (minimum en texte, ex. « 1 paleta ») à côté de `min_order_qty` (minimum en unité affichée), et
  `delivery_date`.
- `n_buy_order` : décision par article (offre ou dernier fournisseur, quantité, prix, dernier prix, **gain** = (dernier prix −
  prix) × quantité, commande fournisseur `n_po`, opérateur) — source des résultats (mois / trimestre / année) : achats
  réussis, gain, marge (gain ÷ ventes WDT en zł), temps dans l'assistant (`n_activity_view`, route `/workshop/buy`) × coût
  horaire (`n_user_cost`) → retour.
- Tâches du poste ZAKUPY : achats de la semaine (lundi) et fournisseurs par article (mercredi). Paramètres
  `buy.reply_hours`, `buy.link_days`, `buy.supplier_weeks`. Pages : Zakupy › Zakupy tygodnia (`/workshop/buy`) ; page
  fournisseur sans compte `/offer/{token}`.

### Poczta : traducteur DeepL et pieds de mail (migration 117, 27/10/2026)

- `n_mail_footer` : pied de page de l'entreprise par langue (`pl`, `fr`, `nl`, `en`), texte sans HTML. Ajouté sous la
  signature dans Poczta (paramètre `mail.footer_user_mail`) et sous les e-mails automatiques de Komunikacja (paramètre
  `mail.footer_system`, langue du modèle puis de la fiche client) à la place de la ligne « SL Food · L'Atelier By ».
  Langue absente → anglais → polonais. Page : Ustawienia › Stopki e-maili i tłumacz (`/settings/mail-footers`).
- `n_user_mail.tr_lang`, `tr_source`, `tr_subject`, `tr_text`, `tr_at` : dernière traduction d'un e-mail reçu (rouvrir ne
  coûte rien). `n_user_mail_sent.lang` (langue du message envoyé) et `original_text` (texte avant traduction).
- `n_translation_log` : caractères envoyés à DeepL (qui, où : `mail_in`, `mail_out`, `footer`, `communication`, `complaint`) ; limite
  par personne et par jour : paramètre `translate.daily_chars_per_user`.
- `n_app_secret` : clé DeepL saisie dans les paramètres quand elle n'est pas dans `.env` (`DEEPL_API_KEY`), chiffrée
  AES-256-GCM avec le secret du serveur ; seuls les 4 derniers caractères (`hint`) sont affichés.

### Factures d'achat : matières, services (Finances) et rejetées (migration 116, 27/10/2026)

- `n_inv.kind` : `goods` (matières et emballages, Zaopatrzenie) ou `service` (coût de l'entreprise : carburant, téléphone,
  loyer, énergie…, Finances) ; `n_inv.status` reçoit `rejected` (ne concerne pas l'entreprise : ni achats, ni coûts).
  Service : `cost_category` (catégorie du calculateur de coûts : `fixed:<catégorie de coût fixe>`, `media:<code média>`,
  `log:<code logistique>`), `service_month` (AAAA-MM, défaut mois d'émission), `cost_amount` (net PLN compté, NULL = total
  net de la facture si en PLN), `id_rule` (règle qui l'a classée). Les lignes d'un service ou d'une facture rejetée n'ont
  pas de matière (ni prix, ni stock, ni historique des prix) ; une facture traitée (prix importés, commande liée,
  décision) reste aux achats.
- `n_inv_supplier_rule` : NIP du vendeur → toujours `service` (avec catégorie) ou toujours `reject`, appliquée à l'import
  KSeF / fichier et, à l'enregistrement, aux autres factures ouvertes de ce NIP.
- `n_service_month` : mois des coûts de services clôturé (total, qui, quand). À la clôture, les totaux des factures validées
  vont dans le calculateur de coûts du mois (créé s'il manque) : `cost_month_fixed_cost` (une ligne par fournisseur),
  `cost_media_month` (montant du média), `cost_month_logistics` (une ligne par type) ; les lignes manuelles de ces
  catégories sont remplacées. `n_service_month_push` garde chaque ligne écrite et les lignes manuelles remplacées (JSON) :
  la réouverture retire les premières et rétablit les secondes. Mois du calculateur `LOCKED` : clôture et réouverture refusées.
- `n_service_month_mark` : « pas ce mois-ci » pour un coût attendu (catégorie + fournisseur présent le mois précédent).
- Paramètres `finance.small_price_up_pct` (5 % : petites hausses acceptées en un clic) et `finance.service_due_day` (10 : à
  partir de ce jour, le mois précédent des services non clôturé apparaît dans Do obsłużenia / Dzisiaj pour l'Administration).
- Permissions `prod.finance.read` / `prod.finance.write` : rôles `prod_admin` et `direction` (pas le responsable de production).
- Pages : Zakupy › Zaopatrzenie › Faktury (bascule À valider / Validées, type Matières / Services → Finances / Rejetées,
  règles fournisseurs), Zakupy › Finanse : faktury i koszty (`/finances/invoices` : relier, valider, hausses de prix ;
  `/finances/costs` : coûts de services du mois et clôture).

### Noms des produits retravaillés (migration 115, 27/10/2026)

- 395 noms de `final_product` corrigés (majuscules et orthographe, variante A : typographie française ; « – » avant le
  format, « 75 g », « ⌀ 22 cm ») d'après la simulation validée ; les 42 noms « à vérifier » sont inchangés. Mise à jour
  par identifiant, seulement si le nom actuel est encore celui de la simulation.
- `n_product_name_history` : ancien et nouveau nom par produit et par origine (`source`), `applied_at` NULL quand le nom
  avait changé entre-temps (non appliqué). Retour arrière possible depuis cette table.
- Les documents émis (WDT, WZ, proformas, factures, KSeF) et les instantanés franchisés gardent l'ancien nom.

### Compositions faites par un autre groupe (migration 114, 27/10/2026)

- `n_recipe_producer.lead_days` : délai de commande de la composition (jours avant le besoin, 0 = le jour même ; NULL = sans
  délai). Le groupe qui fait la composition et ce délai se règlent au même endroit partout (une seule règle,
  `CompositionProducerService`) : formulaire de recette (onglet Skład, colonne « Robi grupa »), Kalkulacja › Kompozycje i
  półprodukty, kreator produktu (étape Receptura), Karta produktu (étape Receptura : `PUT /produits/items/{id}/bom-losses`
  avec `producer_group_id` / `lead_days`). Choisir un groupe crée la fiche « półprodukt » de ce groupe (`n_prod_item` semi,
  groupe principal = groupe qui fait) ; retirer le groupe supprime cette fiche si elle n'a encore aucun historique.
- `n_group_order` : commande d'une composition par un groupe au groupe qui la fait (quantité, jour et heure) — `ordered`,
  `accepted`, `in_production` (partie du groupe qui fait : `id_output`), `ready`, `picked` (partie du produit qui l'a
  utilisée : `id_picked_output`, `picked_quantity`), `cancelled`. Tablette : onglet « Zamówienia grup » (`/kiosk/group-orders`) ;
  ERP : Tablety › Zamówienia między grupami (`GET /execution/group-orders`).
- `n_prod_output_component` : partie d'un produit ↔ partie de la composition faite par l'autre groupe (une ligne au lieu des
  matières de la composition, entièrement utilisée ; `PUT /kiosk/production/{id}/components/{recipe}`).
- Fiche « półprodukt » créée pour chaque composition déjà liée à un groupe. Paramètres `group_orders.days_ahead` (5) et
  `group_orders.hours` (heures proposées sur la tablette).

### Rendement du jour d'un groupe (migration 113, 27/10/2026)

- `n_prod_day_cleaning` (`work_date`, `id_group`) : nettoyage du jour choisi par le responsable dans Tablety › Zatwierdzenia
  produkcji — 0, 15 ou 30 minutes par personne présente, retirées du temps de travail (`PUT /execution/days/{date}/{group}/cleaning`).
- Paramètres `efficiency.break_min_per_person` (15 : pause retirée du temps pointé, par personne présente et par jour, sans
  contrôle) et `efficiency.target_pct` (85 : objectif).
- Rendement = temps standard des pièces faites (fiche produit : personnes × minutes du lot ÷ pièces du lot, sinon gamme
  `StandardTime`) ÷ (pointages du jour − pause − nettoyage). Pointage sans sortie : aujourd'hui jusqu'à maintenant, jours
  passés non compté. Répartition du temps par poste (`n_prod_employee.id_position`). `GET /execution/days/{date}/{group}/efficiency` ;
  la liste `GET /execution/days` porte `efficiency_pct`.

### Premie zespołów : vignette (i) de l'onglet « Premia » (migration 112, 27/10/2026)

- Paramètres `bonus.info_pl` et `bonus.info_fr` : texte de la vignette (i) de la tablette (par défaut : les cobots ne comptent
  pas comme des personnes du groupe), modifiable dans Zespół › Premie zespołów ; vide = pas de vignette.
- Même mois un an avant (N-1) : trait de couleur sur la jauge de la tablette et colonne de la page ERP ; sans personnes payées
  ce mois-là, ventes N-1 ÷ personnes du mois en cours (`people_estimated`).

### Premie zespołów : onglet « Premia » par groupe (migration 111, 27/10/2026)

- `n_bonus_tier` : paliers de prime — à partir de `from_per_person_pln` de ventes par personne dans le mois, `amount_pln`
  par personne (30 000 / 35 000 / 40 000 zł → 300 / 600 / 1 000 zł, à régler dans Zespół › Premie zespołów, `/staff/team-bonus`).
- `n_prod_group.bonus_visible` (0) : onglet « Premia » de la tablette de production pour ce groupe (`GET /kiosk/bonus`,
  personne identifiée ; `GET /kiosk/groups` renvoie `bonus`).
- Paramètres `bonus.min_per_person_pln` (30 000 zł : minimum pour avoir une prime), `bonus.presence_prorata` (1 : prime de
  chacun × jours de présence ÷ jours travaillés du groupe, d'après les pointages) et `bonus.show_people` (1 : la tablette montre la
  prime de chacun ; 0 : seulement la personne connectée).
- Ventes par personne = la colonne « Sprzedaż na osobę » de Rentowność (WDT du département du groupe ÷ personnes payées dans le
  mois), en zł au taux de la société ; mois en cours : à ce jour et prévision au rythme actuel.

### Tablette de production : recette sur commande avec perte (migration 110, 27/10/2026)

- Paramètre `kiosk.recipe_loss_pct` (2) : perte de production proposée dans « Receptura na zamówienie » (commande en
  cartons × pièces par carton + perte → pièces à faire et ingrédients ; emballages sans perte). Renvoyé par
  `GET /kiosk/production` (`recipe_loss_pct`). « Zatwierdź » crée la partie (`POST /kiosk/production`) puis les numéros de
  lot de chaque ingrédient (`PUT /kiosk/production/{id}/lots/{material}` : à la main, photo + OCR).

### Tablette de production : un toucher pour les présents (migration 109, 27/10/2026)

- Paramètre `kiosk.production_tap_present` (1) : sur la tablette de production (`/tablet/production?team=N`), une personne
  du groupe dont l'entrée est en cours (faite sur la tablette de bureau) touche son nom et arrive au menu sans PIN
  (`POST /kiosk/identify` avec `employee_id` seul ; absente : `EMPLOYEE_NOT_PRESENT`). 0 = PIN comme avant.
- Chaque entrée sans PIN est écrite au journal (`n_prod_audit_log`, action `tap_present`).

### Factures d'achat de correction (migration 108, 27/10/2026)

`n_inv.is_correction` (0/1) : facture de correction — KSeF `Fa/RodzajFaktury` KOR, KOR_ZAL ou KOR_ROZ, PDF « faktura korygująca », ou total net négatif ; les factures déjà importées sont marquées depuis le fichier conservé (`n_inv_file`). Ses lignes « avant correction » (`StanPrzed`) ne sont pas des achats : une facture de correction ne propose aucune hausse à valider, n'est jamais traitée automatiquement (avertissement `correction`, acceptation après confirmation), ne change ni le prix de base (409 `INVOICE_CORRECTION` pour l'import des prix), ni le stock (409 pour la réception), et n'entre ni dans l'historique des prix ni dans les prix validés. Une correction de stock ou de prix se fait à la main.

### Prix de revient au plus haut prix validé (migration 107, 27/10/2026)

Paramètre `prices.cost_max_months` (0) seulement. Le prix de base d'une matière (`raw_material.price_per_unit`, celui du coût des recettes et des marges) est le plus haut prix validé, pas le plus récent. Un prix validé qui arrive (prix d'une facture d'achat, hausse décidée « Nouvelle base » comprise ; offre acceptée) passe par `MaterialPriceService::applyValidated` : plus haut ou égal, il devient le prix de base (historique, recalcul des recettes) ; plus bas, il ne change rien — la facture indique « prix de base conservé » (`prices_held`). Avec `prices.cost_max_months` = N > 0, un prix de base de plus de N mois (dernière ligne de `raw_material_price_changelog`) descend au plus haut prix validé de la période (factures acceptées ou dont les prix ont été importés, sans les hausses décidées « Bez zmian » ; offres acceptées), sans jamais monter par ce chemin. La saisie dans Ceny surowców fixe le prix de base directement. Zaopatrzenie › Ceny i dostawcy montre la colonne « Cena bazowa (koszt) » à côté du dernier prix.

### Factures d'achat : toute hausse au-dessus du prix de base est validée (migration 106, 27/10/2026)

`n_inv_line` reçoit `price_decision` (`accept` : le prix de la facture devient le prix de base ; `keep` : le prix de base ne change pas), `price_ok_price` (prix validé par unité de base), `price_ok_by` (utilisateur ERP) et `price_ok_at`. Paramètre `supply.invoice_price_up_pct` (0 = toute hausse). Le prix de base est `raw_material.price_per_unit`, celui du calcul des recettes. Pour chaque matière d'une facture, le prix pondéré des lignes, ramené comme à l'import (prix du colis arrondi ÷ colis), est comparé au prix de base : au-dessus (au-delà de la tolérance), la facture est bloquée (`price_up`) — ni l'automatique à l'import KSeF / XML / PDF, ni « Zatwierdź fakturę » (même confirmée), ni l'import des prix (409 `INVOICE_PRICE_UP_PENDING`) tant que la hausse n'est pas décidée (POST `…/price-decisions`). La décision vaut pour toutes les lignes de la matière ; une nouvelle correspondance, un autre facteur ou un prix plus haut que celui validé l'annulent. Les baisses passent comme avant. L'écran de la facture montre, par matière : prix de base, nouveau prix, écart %, surcoût sur la facture, nombre de recettes concernées (`flattened_recipe_ingredient`), qui a décidé et quand ; les factures en attente apparaissent dans « Do obsłużenia » (`invoice_price_up`) et dans Dzisiaj. Chaque décision est journalisée (`price_decision`).

### Tablette de bureau : PIN et signature sans téléphone, type de tablette modifiable (migration 105, 27/10/2026)

Paramètre `kiosk.office_pin_all` (0) seulement. La tablette de bureau (fixe, `/office`) couvre tous les groupes et services actifs ; après le nom, le QR personnel (personne + jour) reste la voie normale. Une personne sans téléphone enregistré (`n_prod_phone` non révoqué) et avec un PIN voit « Nie mam telefonu — PIN i podpis » : PIN sur la tablette (POST `/kiosk/identify`), puis questions et signature au doigt, et le pointage est enregistré dans `n_prod_clock` avec la tablette de bureau comme lieu et la signature dans `n_prod_signature` — comme sur une tablette de groupe. Une personne avec téléphone est renvoyée au QR (409 `OFFICE_USE_PHONE`), sauf si `kiosk.office_pin_all` = 1. Dans Tablety › Zarządzanie, la colonne « Rodzaj » change le type d'une tablette déjà appairée (groupe, bureau, chauffeur) sans nouvel appairage — la tablette ouvre la bonne page au retour à l'accueil — et la colonne « Grupa » change le groupe d'une tablette de production. Aucune table.

### Tablette de production : produits commandés en boutons (migration 104, 27/10/2026)

Paramètre `kiosk.ordered_days` (7) seulement. « Zakoduj produkcję » sur la tablette du groupe : les boutons sont les produits du groupe présents dans les commandes clients ouvertes (`customer_order` / `customer_order_product`, sans gratuits) à expédier depuis 7 jours de retard jusqu'au jour + `kiosk.ordered_days` — cartons commandés et prochaine expédition, la plus proche d'abord ; un produit absent se trouve par la barre de recherche (tous les produits du groupe, nom ou catégorie, sans accents). L'API du jour de production renvoie `ordered` et `ordered_to`.

### Factures d'achat en un clic, historique des prix au kg (migration 103, 27/10/2026)

Paramètres seulement (aucune table) : `supply.invoice_auto_prices` (1) — à l'import (KSeF, XML, PDF), une facture entièrement reconnue (fournisseur connu par le NIP ou le nom, toutes les lignes depuis les correspondances mémorisées, sans écart avec la commande, sans hausse de prix, totaux cohérents) met à jour les prix des fiches matières ; `supply.invoice_auto_full` (0) — en plus, quantités en stock et acceptation ; `supply.invoice_price_alert_pct` (15) — écart du prix au kg de la ligne vs la fiche matière au-delà duquel la ligne porte le drapeau `price_jump` (pas d'automatique, confirmation). Parcours de Zaopatrzenie › Faktury en boutons : « Pobierz nowe z KSeF » (depuis la dernière facture KSeF, 89 jours max.), « Przypisz wszystkie » (seulement les lignes inconnues, facteur lu dans le nom : « WOREK 25KG » = 25 000 g ; noms comparés sans accents), « Zatwierdź fakturę » (prix + stock + acceptation, tout ou rien), « Zatwierdź gotowe » (toutes les factures prêtes). Onglet « Ceny i dostawcy » et fenêtre d'historique (aussi dans Ceny surowców) : prix au kg par matière et par fournisseur depuis les lignes de factures (`n_inv_line`, hors réclamations), les réceptions avec prix des commandes sans facture (`n_po_receipt`) et les changements de la fiche (`raw_material_price_changelog`, une hausse importée d'une facture n'est pas comptée deux fois).

### Pointage signé sur la tablette ou le téléphone (migration 102, 27/10/2026)

`n_prod_signature.purpose` accepte `clock_in` (signature de l'entrée, ref = id du pointage) en plus de `clock_out`, `timesheet_month` et `operation`. La signature au doigt fait partie de l'écran de validation de l'entrée et de la sortie (avec les questions), sur la tablette du groupe comme sur le téléphone (QR de la tablette de bureau ou d'un groupe) ; elle est envoyée avec le pointage (`signature`, PNG) et enregistrée dans la même transaction : sans elle, pas de pointage (422 `SIGNATURE_REQUIRED`). Paramètres `kiosk.signature_on_clock_in` (1, liste de présence) et `kiosk.signature_on_clock_out` (1, confirmation des heures). La carte de temps de travail imprimée montre les deux signatures de chaque jour. Le menu de l'ERP regroupe les tablettes dans la section « Tablety » (gestion avec le plan : 1 tablette de bureau, 1 tablette chauffeur, 1 par groupe de production, création des manquantes en un clic ; ouverture des trois tablettes ; validations de production).

### Tablette de bureau : QR personnel (migration 101, 27/10/2026)

`n_prod_device.mode` accepte `office` (tablette de bureau : tous les services, sans groupe ni véhicule ; page `/office`). La personne touche son service (`production_sections`) puis son nom ; la tablette affiche son QR personnel (jeton signé : tablette, personne `emp`, jour `day`, valable `kiosk.office_qr_ttl_s` = 120 s, renouvelé avant). Seul le téléphone enregistré de cette personne valide l'entrée ou la sortie (`n_prod_phone`, refus `QR_OTHER_PERSON` sinon), et seulement le jour du QR ; au premier scan, le téléphone ne propose que cette personne et demande son PIN. Le pointage est enregistré dans `n_prod_clock` avec la tablette de bureau comme lieu, et la tablette le confirme (GET `/kiosk/office/status`). Les salariés sans profil de production (ex. Administracja) reçoivent leur ligne `n_prod_employee` (active) pour pouvoir recevoir un PIN ; le premier QR crée aussi le profil d'un salarié ajouté plus tard. Aucune nouvelle table.

### Performance : index des colonnes filtrées (migration 100, 26/10/2026)

Audit de performance sur la copie de production (journal de toutes les requêtes pendant un passage sur les 191 pages) : la base est saine (250 tables InnoDB, une collation, toutes avec clé primaire, 131 Mo) ; la lenteur venait d'index manquants et de quelques requêtes. Index ajoutés (ajout seul, conditionnel) : `carton_stock` (`status, id_product`), (`date_add, id_product, status`), (`id_product, date_add, status, date_pallet` — couvre le registre des mouvements), (`status, date_pallet`) ; `palets_delivery` (`delivery_date, id_customer`), (`status, delivery_date`) ; `customer_order` (`status, expected_shipping_date`), (`expected_shipping_date`) ; `document_wz` (`established_date`), (`id_customer, established_date`) ; `document_proforma` (`established_date`) ; `document_order_shipping_difference` (`established_date`) ; `shipment_log` (`shipment_date`), (`id_diff`), (`id_wz`), (`id_cmr`) ; `final_product` (`id_recipe`), (`id_section`) ; `product_recipe_preparation_connection` (`id_recipe`) ; `raw_material_price_changelog` (`id_raw_material, timestamp`). Requêtes réécrites dans le code (même résultat) : cartons et commandes agrégés par produit avant la jointure recette (consommation matières, besoin des commandes), nom de ligne WDT en VARCHAR dans la définition commune des ventes (une colonne TEXT dans `MAX()` forçait une table temporaire sur disque), liste des écarts limitée avant le calcul des sommes. Mesures locales (191 pages) : temps SQL total 10,5 s → 3,1 s, temps serveur cumulé 9,8 s → 4,6 s ; Gantt (vue jour) 2,9 s → 17 ms ; Achats 220 → 40 ms ; rappels de paiement 98 → 49 ms. Le déploiement affiche un relevé de performance du serveur (CPU, mémoire et swap, mémoire par programme, OPcache, réglages MySQL, temps SQL pur de trois agrégats, temps de génération de pages lourdes). Relevé du 26/10/2026 : 1 vCPU, 1 Go de RAM, 1 Go de swap utilisé, MySQL 8.0 avec 128 Mo de cache InnoDB pour 771 Mo de bases ; le même agrégat de ventes prend 335 ms sur le serveur contre 35 ms sur la copie locale — le serveur est sous-dimensionné (recommandation : 2 vCPU et 4 Go de RAM, puis `innodb_buffer_pool_size` ≈ 1 Go).

### Prix des matières : source de chaque changement ; lot → clients (migration 099, 26/10/2026)

`raw_material_price_changelog` reçoit `source` (`manual` : Zakupy › Ceny surowców, `offer` : offre acceptée, `invoice` : facture d'achat importée, `new` : prix saisi à la création de la matière ; NULL pour l'historique antérieur) et `id_user` (utilisateur ERP, NULL pour les imports automatiques). Ceny surowców affiche l'historique par matière (date, fournisseur, prix du colis et pour 1000 unités, source, qui). Paramètre `prices.manual_edit` (1 par défaut : « Nowa cena » reste possible ; 0 : page en lecture seule, le prix ne change que par offre, facture ou appel d'offres — refus 403 du PATCH). Paramètre `trace.label_days` (1) : Partie i identyfikowalność rattache à un lot de la tablette les cartons du même produit étiquetés le jour de la production ou jusqu'à N jours après (`carton_stock.date_add`), puis palettes (`carton_pallet_connection`, `palets_delivery`) → clients, date de livraison et WZ ; la recherche d'un lot de matière liste les clients à prévenir. Lien probable : l'étiquette du carton ne porte pas encore le numéro de lot de la tablette. Aucune table.

### Tablette : validation de la production ligne par ligne, photos des pertes (migration 098, 26/10/2026)

`n_prod_day_line` : une ligne validée par jour, groupe et produit (`work_date`, `id_group` → `n_prod_group`, `id_item` → `n_prod_item`, unique) — `planned` (plan du jour au moment de la validation), `produced` (quantité faite, unité de la fiche), `waste` (pertes du jour), `note` (raison d'un manque au plan, obligatoire), `id_employee` / `id_device` (SET NULL), `validated_at` ; correction du responsable de production dans l'ERP : `corrected_by` (utilisateur ERP), `corrected_at`. La quantité faite validée ajuste les lots du jour (`n_prod_output` : manque → nouveau lot, excédent → retiré des lots les plus récents). `n_prod_waste_photo` (`id_waste` → `n_prod_waste`, `id_photo` → `n_prod_lot_photo`, CASCADE ; une photo ne sert qu'une fois) : photos d'une perte, stockées en base comme les étiquettes de lot. Paramètres `kiosk.waste_photo_required` (1 : photo obligatoire pour toute perte saisie à la validation) et `kiosk.waste_photo_max` (4). Une journée validée (`n_prod_day_close`) n'est plus modifiable sur la tablette (409 `DAY_CLOSED`, lots matières et contrôle qualité toujours possibles) ; Tablet produkcji › Zatwierdzenia produkcji (`/workshop/production-days`) corrige la quantité faite et annule une perte, et recalcule le résumé de la validation.

### Écarts d'expédition : domówienie et NC liées (migration 097, 26/10/2026)

`n_shipping_issue` reçoit `id_order` (commande de domówienie ou de nouvelle livraison créée depuis l'écart : `/client-orders/new?issue=ID` pré-remplit les produits manquants, gratis, commande d'origine en parent ; l'écart passe à `done` quand cette commande a sa WDT) et `id_nc` (non-conformité client `n_nc` ouverte depuis l'écart, une seule par écart, échéance +7 jours). Les deux colonnes sont NULL par défaut, sans clé étrangère (la commande ou la NC supprimée laisse l'écart lisible). Les remplacements de réclamation (`complaint` décision « produit de remplacement ») passent à « Wysłane » quand la WZ du client est émise (Zamówienia › Zwroty › Do wysłania).

### Rappels de paiement automatiques (migration 096, 26/10/2026)

Paramètre `reminders.auto_levels` (0 par défaut = désactivé ; 1 = premier rappel, 2 = premier et deuxième) : `Bin/mail_dispatch.php` met en file les rappels des proformas non payées après leur échéance (au plus 90 jours, 25 par passage), dans la langue du client, auteur `auto-reminder` dans `communication_message` (un rappel par proforma et par niveau). Aucune table.

### Assistant « Zamknięcie miesiąca », cartons WDT (migration 095, 26/10/2026)

`cost_month_section.source` accepte `WDT` en plus de `AUTO` (cartons des commandes) et `MANUAL` : les cartons d'un département repris des ventes WDT du mois gardent leur origine (avant, l'enum les enregistrait en `AUTO`). L'assistant Analiza › Marże i koszty › Zamknięcie miesiąca (`/month-close/AAAA-MM`) lit l'état des 8 étapes sans rien créer (cartons, personnel face au budget et à M-1 avec les heures des tablettes, médias, coûts fixes et logistique, inventaire matières de fin de mois, recalcul des marges et des stocks min./max., saisies du kierownik, verrouillage et rapport mensuel) ; ses deux actions propres démarrent le mois (personnel et coûts du budget) et copient une catégorie de coûts depuis le dernier mois qui en a (jamais dans une catégorie déjà remplie ni dans un mois fermé ; coûts fixes datés du mois cible). Aucune nouvelle table.

### Une seule fiche salaire, mois de coûts figé (migration 094, 26/10/2026)

`cost_month` reçoit `locked_at` et `locked_by` (quand et par qui le mois a été fermé ; remis à NULL à la réouverture). Le coût mensuel employeur d'un salarié (`production_employee.cost`) ne se saisit plus que dans Budżet personelu (avec le minimum `n_staff_pay.base_monthly` et la prime) : la fiche salarié l'affiche en lecture seule. Le calculateur de coûts (`cost_month_employee.monthly_cost`) est pré-rempli depuis le budget ; un changement dans le budget est reporté aux mois ouverts à partir du mois courant, sauf si une correction du mois a été saisie ; « Pobierz z budżetu » réaligne un mois ouvert. Un mois LOCKED est figé : ni ajout, ni retrait, ni copie, ni coût fixe, ni nouveau salarié à l'affichage.

### Réception des livraisons fournisseurs : lot, DLC, non-conformité (migration 093, 26/10/2026)

`n_po_receipt` reçoit `lot_number` (numéro de lot du fournisseur, en majuscules), `best_before` (date limite), `id_nc` (`n_nc`, non-conformité créée à la réception, source `supplier_delivery`) et `id_employee` (`n_prod_employee`, SET NULL : salarié identifié par son PIN quand la réception est faite sur la tablette de groupe, sans les prix). Saisis dans l'assistant « Przyjęcie dostawy » (Zaopatrzenie › Zamówienia, « Przyjmij dostawę » dans Dostawy surowców, ou la carte « Dostawy surowców » de la tablette). La tablette de groupe propose les lots reçus d'une matière (120 derniers jours, non périmés, ordre FEFO, 3 au plus) lors de la saisie des lots d'un batch.

### Véhicule : chauffeur parmi les salariés (migration 092, 26/10/2026)

`n_vehicle` reçoit `id_driver` (`n_prod_employee`, SET NULL) : chauffeur habituel choisi dans l'assistant « Nowy pojazd i kierowca » parmi les salariés (liste et PIN de la tablette chauffeur) ; son nom est aussi recopié dans `carrier_vehicle` pour le CMR. NULL = chauffeur du transporteur saisi à la main.

### Fournisseurs : NIP, jours de livraison, langue des commandes (migration 091, 26/10/2026)

`supplier` reçoit `nip` (NIP / TVA, recopié dans `n_inv_supplier` pour rapprocher les factures KSeF), `delivery_weekdays` (masque : lundi = 1 … dimanche = 64, NULL = inconnus) et `order_lang` (langue des commandes envoyées par e-mail, NULL = polonais). Saisis dans l'assistant « Nowy dostawca » et sur la fiche fournisseur.

### Stan wysyłek — journal des actions sur les envois (migration 089, 26/10/2026)

`n_send_issue_action` : une ligne par action d'un utilisateur sur une position d'une file d'envoi — `source` `email` (`communication_message`), `franchise` (`outbox` : proforma.upload / proforma.notify / complaint.update), `shipment` (`webhook_outbox_shipments`), `monitoring` (`cron_notification_outbox`), `automation` (`n_mail_dispatch`) ; `id_item` = identifiant dans cette file (pas de clé étrangère : cinq tables) ; `action` `retry` (« Ponów » : la position revient en file avec le nombre de tentatives remis au maximum du worker − 1, donc une seule tentative de plus ; pour `automation` le cas est oublié et la règle le reprend) ou `resolve` (« Rozwiązane » : erreur classée, `reason` obligatoire) ; `id_user` (`user`, SET NULL). Une erreur est « rozwiązana » tant que sa dernière action est `resolve`. Aucune donnée des files n'est copiée, et les payloads (secrets des boutiques) ne sont jamais affichés. Paramètres : `send_status.stuck_minutes` (30 : en file au-delà de cette durée après son heure → « czeka ») et `send_status.lookback_days` (30 : fenêtre par défaut de la page).

`n_order_collect_issue` : échecs des commandes des boutiques visibles dans Stan wysyłek (file « shops ») — `kind` `config` (adresse ou clé de la boutique absente), `pull` (la boutique ne répond pas), `import` (commande rejetée), `unknown_product` (ligne au produit inconnu, ignorée de la commande : `id_order` = la commande créée). `id_customer` (SET NULL), `shop_uid`, `order_key`, `dedup_key` (unique : un cas par nature / boutique / commande / SKU), `message`, `occurrences` (le collecteur tourne chaque minute), `first_at` / `last_at`, `closed_at` (fermé seul quand la boutique répond de nouveau ou que la commande est importée ; un cas fermé qui revient repart à 1). `n_send_issue_action.source` accepte aussi `shops` et `supplement` (domówienia remises en file : `customer_order.supplement_push_status` = `pending`, envoyées une fois par `Bin/mail_dispatch.php`).

### File des écarts d'expédition (migration 088, 26/10/2026)

`n_shipping_issue` : un écart à régler avec le client — `source` `difference` (`id_difference` → `document_order_shipping_difference`, CASCADE, unique : différence proforma payée ↔ WDT d'au moins `shipping_issue.min_amount` €, avec au moins une ligne payée) ou `delivery` (`id_delivery_stop` → `n_delivery_stop` au statut `problem`, CASCADE, unique). `id_customer` (SET NULL), `opened_on` (date du document / de la livraison), `due_on` (entrée dans la file + `shipping_issue.decision_days`), `id_owner` (utilisateur ERP, SET NULL ; par défaut le premier utilisateur au poste ADMINISTRACJA pour les différences, LOGISTYKA pour les livraisons), `status` (`open` → `decided` → `done`), `decision` (`refund`, `credit_next`, `collect`, `resend`, `redeliver`, `complaint`, `correction`, `waive`), `note`, `decided_by` / `decided_at`, `closed_by` / `closed_at`. Le montant n'est pas copié : il est relu dans les lignes du document de différences. Alimentée par `Bin/mail_dispatch.php` et à l'ouverture de la page (`shipping_issue.lookback_days` derniers jours). Paramètres : `shipping_issue.decision_days` (3), `shipping_issue.min_amount` (1 €), `shipping_issue.lookback_days` (45). Tablette chauffeur : paramètre `driver.session_hours` (12 par défaut, sans ligne en base) pour la durée de la session après le PIN.

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
| `n_position_task` | 14 | 24 | — |
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
