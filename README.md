# Cockpit CEO — L'Atelier by

Back-office de pilotage du CEO du réseau de franchises **L'Atelier by** :
tableau des magasins, heatmap mensuelle, objectifs de CA 1/3/5 ans, suivi
budget par magasin (théorique / budget / réel), marge & maîtrise des coûts,
encodage du budget, scoring produits, projets (kanban par famille, fiche,
rétroplanning), tâches consultants, reporting automatisé, journal et paramètres.

Implémentation du design **Claude Design « Cockpit CEO cliquable complet »**
(bundle de handoff) — design system **L'Atelier by** (Gotham / Vank, couleurs
`of_tag`), interface en français, desktop d'abord.

## Stack

- **Frontend** : HTML/CSS/JS vanilla (ES modules, zéro dépendance, zéro build).
  Une SPA (`public/index.html` + `public/assets/js/`) qui reproduit le
  prototype à l'identique. Aucune donnée métier dans le HTML : tout vient de
  l'API (contrat dans `docs/contrat-api.md`).
- **Backend** : PHP ≥ 8.1 (PDO MySQL), API REST `/api/cockpit/*`, un endpoint
  par écran + routes d'écriture. Sans framework — un contrôleur frontal
  (`public/api/index.php`) et deux modules (`src/endpoints.php`,
  `src/writes.php`).
- **Base de données** : MySQL/MariaDB. Tables préfixées `ceo_`
  (`sql/schema.sql`). Les tables `of_tag`, `kpi`, `position` du Manuel
  Opératoire (`franchise_buddy_db`) sont **réutilisées en lecture** — en
  production, pointez la connexion sur cette base.

## Installation

```bash
# 1. Configuration
cp config/config.example.php config/config.php
#    → éditez hôte / base / identifiants, ou définissez :
#    COCKPIT_DB_HOST, COCKPIT_DB_PORT, COCKPIT_DB_NAME,
#    COCKPIT_DB_USER, COCKPIT_DB_PASSWORD

# 2. Schéma (CREATE TABLE IF NOT EXISTS — sans danger sur une base existante)
mysql -u <user> -p <base> < sql/schema.sql

# 3. Données de démonstration (réseau belge, 9 magasins + Knokke en ouverture)
php bin/seed.php
#    ou, pour générer le SQL sans exécuter : php bin/seed.php --sql → sql/seed.sql

# 4. Serveur de développement
PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8080 -t public public/router.php
#    → http://localhost:8080
```

**Sans base de données**, l'interface reste consultable : si l'API ne répond
pas sous 4 s, le jeu de démonstration embarqué (`public/assets/js/data.js`,
génération déterministe identique au seed) prend le relais — la source est
indiquée en console (`[cockpit] API indisponible … jeu de démonstration chargé`).

## Déploiement (Apache)

Guide complet — variables, secrets, DDL, droits MySQL, auth HTTP, recette :
**`docs/DEPLOIEMENT.md`**.

En bref : servez `public/` (racine de vhost **ou sous-répertoire** — tous les
chemins sont relatifs), le `.htaccess` route `/api/cockpit/*` vers l'API et le
reste vers la SPA. `config/config.php` (copie de l'exemple, hors Git) porte
les identifiants MySQL et la base d'URL du panel. Pour changer la base d'URL
de l'API côté client : `window.COCKPIT_API_BASE`.

## Structure

```
public/
  index.html            coquille SPA
  router.php            routeur du serveur PHP de dev
  .htaccess             règles Apache (API + SPA)
  api/index.php         contrôleur frontal de l'API REST
  assets/
    ds/                 design system L'Atelier by (global.css + fontes)
    css/app.css         styles applicatifs (scrollbars, animations, hover)
    js/app.js           logique (état, calculs, écrans, écritures API)
    js/templates.js     gabarits HTML des 12 écrans + modales + assistants
    js/api.js           couche d'accès (17 endpoints, repli démo)
    js/data.js          jeu de démonstration (génération déterministe)
    js/scouting.js      écran Scouting commercial (Overpass, carte, modèle CA)
    js/scouting-tpl.js  gabarits HTML de l'écran Scouting
    vendor/leaflet/     Leaflet 1.9.4 (carte), embarqué
src/
  Db.php                connexion PDO
  endpoints.php         lectures (GET) — un endpoint par écran
  writes.php            écritures (POST/PATCH/PUT) + journalisation
sql/
  schema.sql            tables ceo_* + compat of_tag / kpi / position
  seed.sql              seed généré (php bin/seed.php --sql)
bin/
  seed.php              seed de démonstration (PRNG mulberry32, parité JS)
config/
  config.example.php    modèle de configuration
docs/
  contrat-api.md        contrat JSON par endpoint + mapping DB + DDL
```

## API — aperçu

Base : `/api/cockpit`. Lectures (GET) : `/meta`, `/referentiels/leviers`,
`/referentiels/kpis`, `/referentiels/email-templates`,
`/referentiels/project-templates`, `/stores`, `/stores/perf`,
`/stores/budgets`, `/targets`, `/consultants`, `/fournisseurs`, `/projects`,
`/projects/crm`, `/people`, `/reporting`, `/journal`, `/products/scoring`,
`/pwa/reports`.

Écritures : `POST /projects`, `PATCH /projects/{id}`,
`POST /projects/{id}/tasks`, `PATCH /projects/{id}/tasks/{taskId}`,
`PATCH /projects/{id}/milestones/{index}`, `POST /tasks/{id}/reminder`,
`PUT /stores/{id}/budget`, `PATCH /reporting/reports/{id}`,
`POST /reporting/reports/{id}/send`, `PATCH /reporting/alerts/{id}`,
`PUT /parametres/{key}`, `POST /journal`.

Chaque écriture produit une ligne dans `ceo_journal_entry` (écran Journal).
Détail complet, exemples JSON et DDL : `docs/contrat-api.md`.

## Intégration panel consultant (pwa_consultant)

L'écran **Reporting** intègre les rapports du panel consultant :

- **Générer** : liens profonds vers les rapports rendus par le panel —
  gestion hebdo/mensuel (`/reports/view?type=week|month&scope=all|{id}`) et
  checklist tâches par boutique (`/reports/checklist/week|month?scope={id}`).
  Chaque génération est tracée au journal.
- **Récupérer** : les liens de partage figés (`mac_report_share`, pages
  publiques `/r/{token}`) sont lus et listés avec état (Actif / Expiré /
  Révoqué), ouvertures et expiration. Table absente = volet vide, sans erreur.

Configuration : `pwaBase` dans `ceo_app_setting` (base d'URL du panel) et
`ceo_shop.pwa_shop_id` (id de la boutique dans la base du panel — les valeurs
du seed sont des ids de démo à remplacer). Si le panel vit sur une autre base
MySQL que le cockpit, pointez la connexion du cockpit sur la base commune ou
répliquez `mac_report_share`.

## Scouting commercial

Écran « Scouting commercial » (rail, groupe *Développement*) — implémentation
du design Claude Design « Scouting Belgique » : carte Leaflet / OpenStreetMap
centrée sur la Belgique, 11 provinces et régions, 43 arrondissements.

- **Données** : boulangeries et pâtisseries (`shop=bakery|pastry`) et
  communes (`admin_level=8`, population OSM) interrogées **depuis le
  navigateur** sur Overpass, en 9 secteurs avec reprise et bascule
  d'endpoint. Chaque secteur est mis en cache dans `ceo_scouting_tile`
  (partagé entre tous les utilisateurs — le premier chargement seul est long)
  et en `localStorage`. « Recharger les données » force une nouvelle
  interrogation.
- **Population** : valeur OSM de la commune quand elle existe, sinon nœud
  `place` homonyme, sinon densité médiane des communes sourcées voisines
  (signalée « estimée »). L'import d'un CSV StatBel (code NIS ; population)
  écrase ces estimations, table `ceo_scouting_population`.
- **Concurrence** : force d'un concurrent d'après sa note Google, à défaut
  d'après les signaux OSM (enseigne, site, horaires). Les notes Google sont
  chargées à la demande par le serveur (`POST /scouting/notes`), avec la clé
  du connecteur Google de Paramètres — la même que pour la réputation des
  magasins, jamais dans le code ni dans le navigateur ; **une note ou un
  commentaire terrain saisis à la main priment**
  et sont persistés dans `ceo_scouting_competitor`.
- **Population** : grille de 1 km² du recensement 2021 (StatBel, diffusée par
  Eurostat/GISCO — `public/assets/data/population_grid_2021.json`, 24 267
  cellules habitées, 11,54 M d'habitants, chaque cellule rattachée à sa commune
  par les contours LAU 2024 de GISCO). Les ménages d'un rayon sont ceux des
  cellules qu'il couvre, là où les gens habitent ; les totaux par commune en
  découlent, un CSV StatBel importé (code NIS ; population) prime. Sans le
  fichier, repli sur la part du territoire de chaque commune dans le rayon.
- **Modèle CA** (étude GeoConsulting, Halle 08-2024) : ménages du rayon ×
  dépense/ménage × emprise, majoré du passage ; l'emprise décroît avec la
  pression concurrentielle sauf si elle est imposée. Les 7 hypothèses sont
  éditables, enregistrées dans `ceo_app_setting.scoutingParams` et reprises
  dans les exports.
- **Zones** : zones d'exclusion (rayon paramétrable autour des concurrents
  forts), zones prioritaires (balayage de la vue, 30 meilleurs scores hors
  zones rouges), fiche d'implantation au clic, zones candidates retenues
  (`ceo_scouting_candidate`), comparaison de deux arrondissements, modale des
  magasins du réseau. Onglets tabulaires `ceo_zones`, `ceo_concurrents`,
  `ceo_arrondissements` et « Top 5 par province » (les cinq meilleures communes
  de chaque province cochée, balayées sur toute leur emprise), avec export CSV.
- **Calage sur le réseau** : pour chaque magasin ouvert, le CA réel (P&L du
  panel, douze derniers mois clos) face au CA que le modèle prédit à son
  emplacement — position lue sur la fiche Google raccordée, ou pointée sur la
  carte. Un bouton cale la dépense par ménage sur le rapport médian réel ÷
  modèle, pour que le classement colle au réseau.
- Chaque saisie (note, commentaire, zone retenue, import) produit une ligne
  `Scouting` dans le Journal. Sans API (mode démo), tout reste en
  `localStorage` du navigateur.

Les données OpenStreetMap (neuf secteurs : communes, boulangeries, lieux
peuplés) vivent dans `ceo_scouting_tile` et sont relues par le **serveur** :
cron hebdomadaire `bin/scouting_refresh.php` (posé par `bin/deploy.sh`) et
`POST /scouting/refresh/{secteur}` pour « Recharger les données ». L'écran
n'attend donc jamais Overpass : il lit le cache, et affiche la date de
relecture. Dépendances réseau côté serveur : les miroirs Overpass et, pour les
notes Google, `places.googleapis.com` (connecteur Google de Paramètres). Côté
navigateur : `tile.openstreetmap.org` (fond de carte) seulement — Overpass n'est
interrogé depuis le navigateur qu'en repli, hors API.

## Notes

- Les couleurs des 6 leviers de gestion viennent d'`of_tag.color` (source de
  vérité). Point connu, hérité de la source : **Labour Cost et Overhead Cost
  partagent la même couleur** (`#8b5cf6`) — à corriger dans `of_tag` si voulu.
- La page « Encodage franchisé » (saisie mensuelle côté franchisé) fait partie
  du bundle de design mais n'est pas encore implémentée ici — itération suivante.
- Le PDF des rapports est simulé (aperçu + générateur HTML/CSS copiables) ;
  la génération réelle (Chromium headless) et l'envoi d'emails restent à brancher.
