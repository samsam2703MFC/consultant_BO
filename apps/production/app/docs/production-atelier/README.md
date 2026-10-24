# Module « Gestion de production atelier » — ProdAtelier (Wrocław)

Module de l'ERP de production (`apps/production`). Le système calcule et propose, l'humain valide.
Ce document suit les phases P1 → P7. **Phase livrée : P1** (audit, schéma, migrations, API référentiels).

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
| Planification | `n_prod_forecast`, `n_prod_plan`, `n_prod_plan_line`, `n_prod_order` (OF), `n_prod_order_demand`, `n_prod_operation`, `n_prod_time_log` |

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
- Elle se fait avec les comptes de l'ERP. Les mots de passe hachés (bcrypt) et les anciens mots de passe en clair de l'ERP sont acceptés : la migration des mots de passe en clair reste à faire (paquet STB-002 du plan ERP).
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

## Points ouverts (avant P3)

1. Liste réelle des machines, robots, fours, chambres de pousse et froid, avec leurs capacités. Les données actuelles sont une démo réaliste.
2. Jours et heures réels de clôture des commandes et de départ des camions par tournée. Aucun calendrier logistique n'existe côté atelier.
3. Frontière MTO / MTS, sachant que tout part congelé.
4. Shifts actuellement ouverts et effectifs par groupe.
5. Système de pointage : aucun n'existe.
6. Capacité de stockage froid et surgelé.
7. Confirmations encore attendues sur les jetons (ERP ou panel), les groupes et le bucket R2.
