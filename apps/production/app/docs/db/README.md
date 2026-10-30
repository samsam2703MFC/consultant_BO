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
