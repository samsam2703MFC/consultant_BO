# Module « Gestion de production atelier » — ProdAtelier (Wrocław)

Module de l'ERP de production (`apps/production`). Le système calcule et propose, l'humain valide.
Ce document suit les phases P1 → P7. **Phases livrées : P1 → P7** (référentiels, fiche produit, besoin et plan, ordonnancement, Gantt, optimiseur de shifts, tablettes, pointage et indicateurs), en ligne sur le serveur de test.

## Décisions (19/10/2026)

| Sujet | Décision |
|---|---|
| Où vit le module | Dans l'ERP de production : produits, recettes, commandes et stock y sont déjà. |
| Employés, postes, niveaux | Le panel TFB est maître (identité, postes, niveaux) ; le personnel de Wrocław y est. L'ERP garde `production_employee`, relié au panel par `n_prod_employee.panel_employee_id`. Le niveau (`level_order`, `level_name`) est une copie du panel, saisissable tant que la synchronisation n'existe pas. |
| Produits congelés | Les produits partent congelés : `never_frozen = 0` par défaut. Le contrôle « jamais congelé ⇒ jamais MTS » existe quand même, en base, en API et (P2) en UI. |
| Jetons | L'ERP émet ses propres JWT (HS256, accès 15 min, renouvellement 30 jours à usage unique). *Par défaut, en attente de confirmation.* |
| Groupes | Les 6 groupes du cahier, chacun relié si besoin à une section de coûts de l'ERP (`production_sections`). *Par défaut.* |
| Photos | Lien direct Cloudflare R2 ; si `R2_PUBLIC_BASE_URL` est renseigné, le lien doit être sous cette adresse. *Bucket à fournir.* |

## Ce qui est réutilisé, ce qui est créé

**Réutilisé, jamais recopié :**
- produits finis : `final_product` ;
- semi-finis : recettes `product_recipe` de type composition / semi-produit ;
- nomenclature : `product_recipe_connection` et ses tables d'explosion ;
- employés : `production_employee` ;
- sections : `production_sections` ;
- commandes : `customer_order` ;
- comptes : la table `user` de l'ERP.

**Créé (préfixe `n_`, « nouveau » : tables ajoutées à la base `erp` pendant le développement ; préfixe `tfb_` jusqu’à la migration 069)**

| Domaine | Tables |
|---|---|
| Socle | `n_prod_param` (toutes les valeurs [PARAM]), `n_prod_role`, `n_prod_role_permission`, `n_prod_user_role`, `n_prod_refresh_token`, `n_prod_login_attempt`, `n_prod_audit_log`, `n_schema_migration` |
| Référentiels | `n_prod_group`, `n_prod_workstation`, `n_prod_workstation_shift`, `n_prod_shift_template`, `n_prod_team`, `n_prod_team_member`, `n_prod_employee` |
| Produit | `n_prod_item` (article planifiable = produit ou semi-fini existant), `n_prod_product_settings`, `n_prod_bom_loss` (pertes par ligne de nomenclature), `n_prod_routing_step`, `n_prod_routing_alternative` |
| Planification | `n_prod_plan`, `n_prod_plan_line`, `n_prod_order` (OF), `n_prod_order_demand`, `n_prod_operation`, `n_prod_time_log` |

**Contrôles en base (CHECK) :**
- jamais congelé ⇒ pas de MTS ;
- stock max ≥ stock min ;
- un robot a un mode, et un autre poste n'en a pas ;
- capacités > 0 ;
- un shift ne commence et ne finit pas à la même heure.

## Migrations

```
php Bin/tfb_migrate.php status   # état de chaque fichier
php Bin/tfb_migrate.php plan     # ce qui serait appliqué
php Bin/tfb_migrate.php apply    # applique, une fois chacune, sous verrou
```

- Les fichiers sont dans `migrations/tfb/NNN_nom.sql` et ne se modifient plus une fois appliqués : un fichier modifié bloque `apply` (code de sortie 2). On écrit alors une nouvelle migration.
- Les migrations historiques de l'ERP (`migrations/001…055`) ne sont pas touchées.
- `deploy/server.sh` lance `apply` à chaque déploiement.

## API

Le préfixe est `{API_BASE_URL}/api/v1/production`. La description OpenAPI est servie à `GET /openapi.json` et copiée dans `docs/api/production-v1.openapi.json` (regénérée par `php Bin/tfb_openapi.php`).

| Domaine | Endpoints |
|---|---|
| Connexion | `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me` |
| Référentiels | `GET/POST /referentiels/groups`, `GET/PATCH /referentiels/groups/{id}`, idem pour `workstations`, `shift-templates` et `teams` ; `GET/PUT /referentiels/teams/{id}/members` ; `GET /referentiels/employees`, `GET/PATCH /referentiels/employees/{id}` (id ERP) ; `GET /referentiels/params`, `GET/PATCH /referentiels/params/{code}` |

**Jeton d'accès.** Il passe dans `Authorization: Bearer …`, ou dans `X-Access-Token` quand `Authorization` est déjà pris par le mot de passe navigateur (serveur de test).

**Langue.** Elle se choisit avec `Accept-Language` ou `?lang=pl|fr`. Le polonais est la langue par défaut (paramètre `api.default_language`). Les textes sont dans `public/resources/lang/api/{pl,fr}/production.json`.

**Réponses.**
- Succès : `{"status":"ok","data":…,"meta":{"total":…}}`.
- Erreur : `{"status":"error","description":"CODE","message":"texte traduit","errors":[{"field","code","message"}],"request_id":"…"}`.
- Les champs inconnus sont refusés. `PATCH` est partiel. Il n'y a pas de suppression : on désactive (`active: false`) pour garder l'historique.

**Droits.** Le refus est la règle par défaut ; les permissions sont relues à chaque requête.

| Rôle | Permissions |
|---|---|
| `prod_admin`, `prod_manager` (responsable production) | tout |
| `team_lead` (chef d'équipe, rôle éventuellement limité à un groupe) | lecture, déplacement Gantt, exécution |
| `direction` | lecture et indicateurs |
| `operator` | lecture des référentiels et exécution |

Attribuer un rôle :

```
php Bin/tfb_role.php grant <login ERP> prod_manager
php Bin/tfb_role.php grant <login ERP> team_lead VIENNOISERIE
php Bin/tfb_role.php list
```

**Connexion.**
- Elle se fait avec les comptes de l'ERP. Les mots de passe sont hachés (bcrypt) ; ceux encore en clair sont hachés au déploiement (`Bin/hash_passwords.php`).
- Après `auth.max_failed_logins` échecs en `auth.lockout_window_min` minutes, le compte est bloqué depuis toutes les adresses.

**Configuration (`.env`, jamais dans le dépôt).** `PRODUCTION_JWT_SECRET`, `API_BASE_URL`, `PRODUCTION_API_CORS_ORIGINS`, `R2_PUBLIC_BASE_URL` : voir `.env.example`.

## Données de démonstration

`php Bin/tfb_seed_demo.php [--grant-admin=<login>]` est rejouable et n'écrase rien. Le serveur de test l'exécute à chaque déploiement (`ERP_TFB_DEMO=1`). Il crée :

**Postes (25).**
- 4 robots :
  - ligne de laminage, autonome ;
  - enrouleur de croissants, avec opérateur ;
  - boudineuse, autonome ;
  - palettiseur, autonome.
- 3 fours.
- 2 chambres de pousse.
- 1 cellule de surgélation et 1 chambre froide.
- Pétrins, façonneuse, batteur, cuiseur, ensacheuse, tables.

**Disponibilité la nuit.** Seuls les robots autonomes et les ressources passives sont disponibles (pousse, fermenteur, froid, surgélation).

**Équipes.**
- Une équipe de jour par groupe.
- Les équipes après-midi (Q2 2027) et nuit (Q3 2027) existent mais sont inactives.

**Employés.** Les employés de l'atelier sont rattachés à leur groupe d'après leur section. L'administration n'a pas d'équipe.

**Articles (30).** Ce sont des produits et semi-finis **existants**, avec leurs réglages et leur gamme :
- baguettes, pains (dont Fermier Seigle & Levain), brioche ;
- croissant, pain au chocolat, croissant amandes, suisse, minis ;
- sablés en boudin (Damier, Leopard, Swirl, Amandes & Canelle) et cookie ;
- quiches, soupe ;
- granolas (épicerie) ;
- semi-finis :
  - pâte feuilletée (Ciasto Rogal + masło klepane) ;
  - pâte sablée ;
  - appareil à quiche ;
  - garniture bœuf ;
  - pâte tradition.

**Levain.** Il n'existe pas dans l'ERP, ni comme recette ni comme matière : il n'a pas été inventé. Le levain du pain Fermier est une étape « rafraîchi » sur le fermenteur. À créer dans l'ERP si on veut le planifier comme semi-fini.

## Tests

```
php tests/tfb_unit_test.php   # JWT, validation, shifts, découpage SQL, traductions, OpenAPI à jour
php tests/tfb_api_test.php    # bout en bout sur une base jetable erp_tfb_test
```

`tfb_api_test` lit la base dans `TFB_TEST_DSN`, `TFB_TEST_USER` et `TFB_TEST_PASS`, et copie la structure des tables ERP depuis `TFB_TEST_SOURCE_DB`. Il couvre :
- les migrations : rejeu, empreinte modifiée ;
- les contrôles en base, dont « jamais congelé ⇒ jamais MTS » ;
- la connexion, la rotation et la réutilisation des jetons, la limitation des essais ;
- les droits ;
- toute l'API référentiels, avec les messages en polonais et en français ;
- le journal des écritures.

## Tablette chauffeur et fiches de poste (26/10/2026)

- **Tablette chauffeur** (`/driver`, PWA) : une tablette appairée comme celle de l'atelier, en mode « Tablet kierowcy » avec son véhicule (Produkcja › Tablet produkcji › Zarządzanie › Tablety). Le chauffeur voit la tournée du jour de son véhicule (clients dans l'ordre de déchargement du chargement du jour, adresse, téléphone, palettes, cartons, documents WZ / CMR / WDT, itinéraire), démarre et termine la tournée, confirme chaque livraison (réceptionnaire, signature, photo, palettes livrées et rendues) ou signale un problème (fermé, refus, casse, manquant, retard) — notification dans la cloche. Sans réseau, l'enregistrement attend sur la tablette et part tout seul. ERP : Logistyka › Dostawy (tournées, arrêts, signatures, photos, PDF de preuve PL / FR) et pastille livré / problème sur le chargement du jour. API : `/driver/day`, `/driver/run`, `/driver/stops`, `/driver/documents`.
- **Fiches de poste** : Administracja › Stanowiska i zadania › « Karta stanowiska » pour chaque poste, bureau et atelier (mission, responsabilités, compétences, exigences, conditions, indicateurs, hygiène et sécurité), PL / FR, version à valider, salariés rattachés, PDF.

## Tablette chauffeur : photos, palettes, consignes (26/10/2026)

- **Arrivée chez le client** : bouton « 📷 Przyjazd : zdjęcie przed » sur chaque arrêt — photo avant déchargement (date, heure et client incrustés sur l'image), l'arrêt passe « sur place » avec l'heure d'arrivée. Dans « Dostarczono » : liste de contrôle photos avant / après (ajout de photos après déchargement), réceptionnaire, signature ; sans photo, confirmation demandée. Chaque photo part seule (petit envoi) ; hors réseau elle attend dans la file et part toute seule.
- **Rapport PDF** : « Raport PDF » (tournée) et « PDF » (un client) sur la tablette ; Logistyka › Dostawy : « Raport trasy » par camion (PL / FR) et PDF par livraison — arrivée, livraison, durée du déchargement, palettes et numéros, photos avant / après avec l'heure, signature.
- **Palettes** : nombre et numéros des palettes scannées au dépôt pour chaque client (sinon estimation d'après les cartons, signalée).
- **Consignes de livraison par client** (fiche client › « Dostawa : instrukcje dla kierowcy ») : procédure de déchargement, alarme, clé, congélateur, contact sur place. Chiffrées en base, affichées sur la tablette à l'arrêt du client (alarme et clé seulement après un toucher, 60 s), jamais dans les PDF destinés au client.

## Produkty : une seule entrée (26/10/2026)

- Rail « Produkt › Produkty » : une page à onglets (PageTabs « products ») — Karty produktów, Receptury, Półprodukty, Kompozycje, Produkty w rozwoju (produits test) ; remplace « Karty produktów » et le sous-menu « Produkty ». Droits inchangés (product_cards, products).

## Rapport mensuel (26/10/2026)

- **Analiza › Raport miesięczny** (`/dashboard/monthly`) : pour un mois (par défaut le dernier mois clos, ou le mois en cours au prorata), comparé à M-1 et N-1 — ventes, cartons, clients actifs, marge brute après matières, personnel / ventes, résultat de production et marge nette (mêmes calculs que Rentowność), réclamations (déclarées, acceptées, refusées, en cours, valeur acceptée = quantité acceptée × prix d'achat, % des ventes, délai de décision, motifs, produits et clients les plus réclamés, réclamations pour 1000 cartons), non-conformités, livraisons de la tablette chauffeur. Groupes (marge vs objectif), 10 meilleures ventes et marges les plus faibles, catégories, 10 premiers clients vs N-1. « L'essentiel » en quelques phrases.
- **Nouveaux produits** : produits lancés dans les 12 derniers mois (date de lancement du module Kierownik produkcji, sinon date de création) — CA depuis le lancement, par mois, dans le mois du rapport (et part du CA), cartons, clients, marge après matières ; produits en développement (produits test de type « Produkt », statut, dernière étape). **Tableau de l'année** : janvier → mois du rapport, CA et N-1 (mois en cours : N-1 au même jour), cartons, marge brute, personnel, résultat, marge nette, réclamations, cumul depuis le début de l'année.
- **PDF PL / FR** (`/dashboard/monthly/pdf?month=AAAA-MM&lang=fr`), **envoi par e-mail** (PDF joint, modèle « management_report » modifiable dans Komunikacja) et **envoi automatique** chaque mois pour le mois précédent (adresses, jour et langue réglés sur la page ; lancé par `Bin/mail_dispatch.php`, une seule fois par mois). Accès : même droit que Rentowność.

## Points ouverts (données réelles à fournir)

Les écrans existent ; ce qui manque, ce sont les données réelles de l'atelier (la copie de test tourne sur une démo réaliste).
Les points 1 à 4 se remplissent en une fois avec le **tableau à remplir** : Produkcja › Tabela do wypełnienia
(`/workshop/data-sheet`). On télécharge un Excel pré-rempli (feuilles Stanowiska, Zmiany, Zespoły, Trasy, Parametry, avec
listes de choix et mode d'emploi PL / FR), on le complète hors ERP, puis on le renvoie : aperçu ligne par ligne (nouveau,
modifié, inchangé, erreur), puis « Zastosuj » enregistre tout ou rien par les services habituels (mêmes contrôles, journal).
Une ligne retirée du fichier n'est jamais supprimée. API : `GET /referentiels/data-sheet`, `POST /referentiels/data-sheet/preview`
et `/apply` (`DataSheetService`).

1. Liste réelle des machines, robots, fours, chambres de pousse et froid, avec leurs capacités : Produkcja › Stanowiska i coboty (25 postes de démonstration aujourd'hui).
2. Heures de départ des camions par tournée : Logistyka › Trasy (champ heure de départ).
3. Shifts réellement ouverts et effectifs par groupe : Produkcja › Zespół.
4. Capacité du surgélateur : paramètre `cold.freezer_capacity_cartons` (0 = non renseignée aujourd'hui, donc non limitée).
5. Bucket Cloudflare R2 pour les photos (`R2_PUBLIC_BASE_URL`).

Réglés depuis : le pointage (tablette fixe, QR au téléphone, questions de validation) et la frontière MTO / MTS (choisie produit par produit dans Karty produktów).
