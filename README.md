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
  dashboard/            dashboard magasin — page à part, hors SPA
    index.html          coquille
    dashboard.js        état S, rendre()/brancher(), lectures lireAux()
    dashboard.css       styles de la page
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
`/pwa/reports`, `/pwa/tasks/nc`.

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

## Dashboard magasin

Une page à part, `public/dashboard/?shop=4&vue=mois&date=…`, hors SPA : cinq
vues (jour, semaine, mois, trimestre, année), un objet d'état `S`, un couple
`rendre()` / `brancher()`, et des lectures asynchrones par clé (`lireAux`).

- **Valeur du magasin** : sur la barre du haut, en un mot et un chiffre ; tout
  le détail attend dans le tiroir qu'on déplie. Le calcul : le CA mensuel moyen des **18 derniers mois clos**,
  ramené à l'année (× 12) puis divisé par **6** — soit deux mois de chiffre
  d'affaires. Le **mois en cours est écarté** : incomplet, il tirerait la
  moyenne vers le bas jusqu'à son dernier jour. Quand l'historique est plus
  court, le calcul se fait sur les mois disponibles et le bloc le dit : un
  chiffre daté vaut mieux qu'un tiret. La mini-courbe montre le CA mensuel qui
  porte la valeur. Source : `GET /stores/perf?granularite=mois&annees=…` sur
  trois exercices (18 mois débordent sur trois années civiles en début
  d'année), lu à partir d'aujourd'hui et non de la période regardée — la valeur
  du magasin est un fait présent. Constantes `VALO_MOIS` et `VALO_DIV`.
  Le tiroir montre en plus **les six derniers trimestres clos** : la valeur que
  le magasin aurait eue au rythme de chacun, en courbe et en chiffres. Le
  trimestre en cours est écarté comme le mois en cours — à mi-parcours il
  vaudrait la moitié de lui-même. Un trimestre sans CA relevé coupe le trait
  au lieu d'être relié : le trou se voit.
- **Non-conformités** : les tâches notées sous le seuil, la veille en vue Jour,
  la période en Semaine et en Mois. **Lecture seule** — le dashboard n'écrit
  rien sur les tâches.

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
- **Assistant « où puis-je ouvrir, et pour combien »** : quatre questions dans
  l'ordre — les provinces, l'arrondissement (classé par ménages par point de
  vente), les deux bornes de la concurrence, le chiffre d'affaires visé — puis
  la carte cadrée sur la zone choisie, avec les seuls emplacements qui tiennent
  les conditions et un bandeau qui rappelle les réponses. Il n'a pas de calcul
  à lui : il écrit dans les réglages de l'écran. Deux d'entre eux sont nés avec
  lui, `weak` (en dessous de cette note, ce n'est pas un concurrent — la force
  valait `(note − 3) ÷ 2`, le 3 était en dur) et `caVise` (plancher de CA, à
  côté du score minimum), plus deux conditions de terrain : `nMax` (concurrents
  au plus dans le rayon, 0 = aucune boulangerie) et `hhMin` (ménages minimum
  dans le rayon, la densité là où elle compte) et `zoneMax` (à tant de km d'un
  **zoning d'activité**, distance mesurée au bord de la zone). Quand rien ne
  sort, l'écran dit lequel des filtres vide la liste et combien d'emplacements
  il retient.
- **Zoning d'activité** : les surfaces `landuse=industrial|commercial|retail`
  d'OpenStreetMap, d'au moins 260 m de rayon. Elles ont leur **propre requête
  Overpass et leurs propres secteurs** (100 à 108 de `ceo_scouting_tile`) :
  un échec du zoning ne peut pas empêcher le relevé des commerces, qui est la
  donnée vitale de l'écran. Cron du dimanche 6 h 15,
  `bin/scouting_refresh.php --zoning`. Tant que le cache est vide, le filtre le
  dit et reste éteint. Les zones sont aussi une **couche de la carte**, cochée
  d'origine : au-delà de 1 200 dans la vue, seules les plus grandes sont
  peintes, sans quoi la Belgique entière devient illisible.
  Son balayage est par **arrondissement**, jamais par la vue : à l'échelle du
  pays, la maille de la carte s'élargit et ne rendait que deux points.
- **Chercher une ville** : en haut du panneau de gauche, et à l'étape 2 de
  l'assistant — là elle répond à la question posée : choisir une ville, c'est
  choisir son arrondissement (on connaît sa ville, rarement son
  arrondissement), et la ligne choisie est ramenée sous les yeux dans le
  tableau. La saisie se compare
  sans casse ni accents, et **sur les deux noms** que le relevé garde de chaque
  commune (`name:fr` et `name`) : « Ieper » trouve Ypres, « Brugge » trouve
  Bruges. Elle porte sur toutes les communes relevées, pas seulement celles des
  provinces cochées — on ne peut pas chercher ce qu'on a masqué ; aller à une
  ville hors sélection recoche sa province (sinon la fiche s'ouvrirait sans un
  seul concurrent, la carte ne les chargeant pas) et resserre l'arrondissement
  s'il en était fixé un autre. Le clic, ou Entrée sur la première trouvée, cadre
  la carte et ouvre **la fiche de scoring complète** de l'endroit : score,
  ménages du rayon, zone primaire, marché, concurrents, pression, emprise,
  rendement au m², CA hebdomadaire et CA annuel estimé.
- **Chaînes** : une enseigne est reconnue à la **marque relevée par
  OpenStreetMap** (`brand`, 392 commerces sur le relevé) — de la donnée, pas une
  liste écrite à la main — et à défaut au nom, pour les quelques enseignes
  connues que la marque ne porte pas (`CHAINS`). `estChaine()` et `marqueDe()`
  en sont l'unique définition ; `strength()` s'en sert aussi. Le compte paraît
  en colonne « dont chaînes » du tableau de l'assistant (avec les marques en
  toutes lettres), dans le résumé de l'étape, en ligne « Chaînes dans le rayon »
  de la fiche de scoring, et en marque sur chaque concurrent de la liste. Une
  chaîne déjà installée ne s'est pas installée au hasard : sa présence valide la
  zone de chalandise autant qu'elle la dispute.
- **Points chauds** : les zones prioritaires de la carte sont aussi **listées
  dans le panneau de droite**, dans le même ordre — rang, commune, ménages,
  concurrents, CA estimé, score. Cliquer une ligne cadre la carte et ouvre la
  fiche ; cliquer un repère surligne la ligne et titre la fiche « Point chaud
  nº N ». Une seule sélection des deux côtés, un seul balayage (`scanPrio()`)
  pour la carte, le compteur et la liste.
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
