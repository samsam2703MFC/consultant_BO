# Cockpit CEO — L'Atelier by

Back-office de pilotage du CEO du réseau de franchises **L'Atelier by** :
tableau des magasins, heatmap mensuelle, objectifs de CA 1/3/5 ans, suivi
budget par magasin (théorique / budget / réel), marge & maîtrise des coûts,
encodage du budget, scoring produits, projets (kanban par famille, fiche,
rétroplanning), tâches consultants, contrôle des posts Facebook des magasins,
reporting automatisé, journal et paramètres.

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
    js/templates.js     gabarits HTML des 13 écrans + modales + assistants
    js/api.js           couche d'accès (19 endpoints, repli démo)
    js/data.js          jeu de démonstration (génération déterministe)
    vendor/leaflet/     Leaflet 1.9.4 (carte), embarqué
  dashboard/            dashboard magasin — page à part, hors SPA
    index.html          coquille
    dashboard.js        état S, rendre()/brancher(), lectures lireAux()
    dashboard.css       styles de la page
  tablette/             tablette des vendeuses « Book vendeuses » — application
                        compilée (dépôt pwa_sales_tablet), copiée telle quelle
src/
  Db.php                connexion PDO
  endpoints.php         lectures (GET) — un endpoint par écran
  writes.php            écritures (POST/PATCH/PUT) + journalisation
  fbcontrole.php        agent de contrôle des posts Facebook (moteur de règles)
  installer.php         auto-installation du schéma au premier appel
  auth.php              authentification intégrée (optionnelle)
sql/
  schema.sql            tables ceo_* + compat of_tag / kpi / position
  seed.sql              seed généré (php bin/seed.php --sql)
bin/
  seed.php              seed de démonstration (PRNG mulberry32, parité JS)
  mix1000.php           le mix par 1 000 € vendus (base de calcul production/commandes)
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
`/pwa/reports`, `/pwa/tasks/nc`, `/referentiels/facebook-regles`, `/facebook/posts`.

Écritures : `POST /projects`, `PATCH /projects/{id}`,
`POST /projects/{id}/tasks`, `PATCH /projects/{id}/tasks/{taskId}`,
`PATCH /projects/{id}/milestones/{index}`, `POST /tasks/{id}/reminder`,
`PUT /stores/{id}/budget`, `PATCH /reporting/reports/{id}`,
`POST /reporting/reports/{id}/send`, `PATCH /reporting/alerts/{id}`,
`PUT /parametres/{key}`, `POST /journal`, `POST /facebook/posts`,
`POST /facebook/posts/{id}/controle`, `PATCH /facebook/posts/{id}`,
`PATCH /facebook/posts/{id}/ecarts/{ecartId}`.

Chaque écriture produit une ligne dans `ceo_journal_entry` (écran Journal).
Détail complet, exemples JSON et DDL : `docs/contrat-api.md`.

## Contrôle des posts Facebook

L'écran **Contrôle posts Facebook** encadre ce que les magasins publient, avant
publication. Un franchisé soumet un post ; l'**agent de contrôle**
(`src/fbcontrole.php`) le relit et propose une note ; le CEO valide ou refuse.

- **21 règles** en 5 familles — charte de marque, mentions légales, rédaction,
  visuel, diffusion — chacune avec sa gravité (mineur / majeur / critique) :
  marque et magasin cités, hashtag réseau, promotion sans période de validité,
  jeu-concours sans règlement, allégation santé, superlatif non prouvé,
  longueur, capitales, ponctuation, hashtags, lexique de fautes, visuel absent
  ou sans texte alternatif, résolution, créneau de publication, lien non
  sécurisé ou hors domaines autorisés, et texte dupliqué d'un autre magasin.
- **Moteur déterministe et local** : aucun appel réseau, aucun modèle de
  langue. Un refus se justifie règle par règle au franchisé, et le même texte
  donne toujours le même verdict.
- **Les règles sont un réglage**, pas des constantes : `ceo_app_setting`
  (clé `fbControle`), éditable par `PUT /parametres/fbControle` — l'écran
  active/désactive chaque règle. Tant que le réglage n'existe pas, la charte
  livrée (`fbReglesDefaut()`) s'applique.
- **Une seule échelle de conformité** : les cinq niveaux des tâches
  consultants (réglage `signalement`). Note = 5 sans écart ; 4 avec un ou deux
  écarts mineurs ; 3 à partir de trois mineurs ; 2 dès un majeur ; 1 dès un
  critique. Sous le seuil (4 par défaut), valider est une **dérogation
  motivée** — famille et type de problème obligatoires, comme pour un refus.
- **L'agent ne publie ni ne refuse seul** : il note et liste. Un écart peut
  être écarté au cas par cas (`PATCH …/ecarts/{id}` avec `statut: ignore`) —
  il reste affiché, sort du calcul de la note, et la dérogation survit aux
  contrôles suivants. Chaque étape produit une ligne de journal.

Cycle d'un post : `brouillon` → `a_controler` → `a_valider` → `valide` |
`refuse` → `publie`. La **publication n'est pas automatisée** : `published_at`
et `fb_post_id` sont renseignés à la main (bouton « Marquer publié »). Ces
colonnes resteront les mêmes le jour où l'API Meta Graph sera branchée — cela
demandera une app Meta, un Business Manager et des Page Access Tokens, hors
périmètre actuel.

Tables : `ceo_fb_post`, `ceo_fb_finding` (DDL dans `sql/schema.sql`, créées
aussi au démarrage par `ensureFacebook()` pour les bases déjà en service).

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

## Diagnostic API — connecteurs et carte des sources

L'onglet **Diagnostic API** de l'écran Journal porte deux choses :

- **Les connecteurs**, avec un bouton **Tester** par ligne. Un vrai appel, pas
  une relecture de réglage : une clé peut être présente et refusée. Le panel
  s'authentifie *puis* lit `/shops` — un jeton valide ne prouve pas que les
  données suivent. L'IA n'est **pas** appelée : une proposition de note se
  facture, et l'écran le dit au lieu de faire semblant. Le résultat s'écrit
  dans `ceo_connecteur` comme n'importe quel geste.
- **La carte des sources** : les 152 lectures classées par ce qu'elles
  interrogent vraiment — **65 la base seule**, 45 les deux, 20 une API seule,
  22 un calcul pur. Puis les plus grosses lectures qui passent par une API,
  triées par longueur : c'est là que se joue ce qu'on gagnerait à passer en
  base. Établie par `bin/carte_sources.php`, **lue dans le code** et non dans
  une liste tenue à la main, en cron chaque nuit à 3 h 40.

Piège rencontré : la méthode ne pouvait pas s'appeler `caCharge()` — la
Centrale d'achat en a déjà une, et la **dernière** définition d'une classe
écrase la première, sans erreur. D'où `carteCharge()`.

## Dashboard magasin

Une page à part, `public/dashboard/?shop=4&vue=mois&date=…`, hors SPA : cinq
vues (jour, semaine, mois, trimestre, année), un objet d'état `S`, un couple
`rendre()` / `brancher()`, et des lectures asynchrones par clé (`lireAux`).

- **Au téléphone** : la même page et les mêmes lectures, un autre rendu sous
  **560 px** (`estMobile()`, forçable par `?mobile=1` ou `?mobile=0`). Deux
  vues seulement, **le jour et la semaine** — le mois, le trimestre et l'année
  ne se lisent pas au pouce ; une URL qui les demande retombe sur le jour. La
  forme : le chiffre de la période en grand avec sa barre et son écart, l'alerte
  des tâches bloquantes juste dessous, puis des cartes ouvertes qu'un seul
  défilement parcourt — non-conformités, tâches, compte de la période en
  cascade, valeur du magasin — et deux onglets en bas. Tourner l'appareil
  redessine sans recharger. Le bandeau des non-conformités du bureau porte trois
  pastilles sur une ligne : il ne tient pas dans 390 px, le téléphone en montre
  la même donnée en carte (`mobNC()`).

- **Valeur du magasin** : sur la barre du haut, en un mot et un chiffre ; tout
  le détail attend dans le tiroir qu'on déplie. Le calcul : le CA mensuel moyen
  des **18 derniers mois clos**, ramené à l'année (× 12) puis divisé par **6**
  — soit deux mois de chiffre d'affaires. Le **mois en cours est écarté** :
  incomplet, il tirerait la moyenne vers le bas jusqu'à son dernier jour. Un
  mois sans vente dans la fenêtre ne compte pas dans la moyenne, et le bloc dit
  combien il y en a. Source : `GET /ventes/mensuel?shop=…&mois=24`, les
  **ventes de caisse** et non le P&L mensuel du panel — celui-ci n'a aucune
  ligne en 2025, et la fenêtre de 18 mois s'y réduisait à sept. Vingt-quatre
  mois sont demandés pour deux usages : les 18 derniers pour la valeur, six
  trimestres entiers pour le tiroir (le premier commence avant le 18e mois).
  Lu à partir d'aujourd'hui et non de la période regardée — la valeur du
  magasin est un fait présent. Constantes `VALO_MOIS` et `VALO_DIV`.
  Le tiroir montre en plus **les six derniers trimestres clos** : la valeur que
  le magasin aurait eue au rythme de chacun, en courbe et en chiffres. Le
  trimestre en cours est écarté comme le mois en cours — à mi-parcours il
  vaudrait la moitié de lui-même. Un trimestre sans CA relevé coupe le trait
  au lieu d'être relié : le trou se voit.
- **Stock vivant** : l'inventaire matière du magasin, relu avec la page — donc
  toutes les dix minutes en vue Jour sur aujourd'hui. Une référence est en
  alerte quand son stock est **négatif** (écart de caisse ou de comptage) ou
  **sous le minimum journalier**. Le bandeau dit le nombre, le tiroir dit
  lesquelles et de combien il manque. Source : `GET /ventes/stock?shop=`.
  **Zéro alerte n'est pas forcément une bonne nouvelle** : sur un inventaire
  non recompté depuis plus de sept jours (`STOCK_VIEUX`), le bandeau ne dit pas
  « stock au complet » mais « inventaire non recompté depuis N jours », en
  orange. Mesuré : Corbais affichait un stock parfait avec un comptage vieux de
  trois semaines.
  Un bouton **« M'avertir »** abonne l'appareil aux **notifications push** :
  service worker (`public/dashboard/sw.js`), clés VAPID, abonnement déposé au
  serveur. L'alerte part alors **même application fermée et téléphone rangé** —
  `bin/push_stock.php` surveille le stock toutes les quinze minutes de 5 h à
  20 h et ne signale que les références qui **viennent** de passer sous leur
  minimum. Un bouton « Essai » envoie un message de vérification tout de suite.
  Le bouton dit toujours où l'on en est : indisponible sans HTTPS, refusé si la
  permission est bloquée, « averti sur cet appareil » une fois abonné.
- **Non-conformités** : les tâches notées sous le seuil, la veille en vue Jour,
  la période en Semaine et en Mois. **Lecture seule** — le dashboard n'écrit
  rien sur les tâches.

## Tablette des vendeuses (Book vendeuses)

L'application de comptoir des vendeuses — gamme, photos, prix, saisons,
allergènes, conseils de vente — est servie par le BO depuis `public/tablette/` :
**https://185.180.206.46/consulant_bo/tablette/?shop=4** (`&lang=nl`, `&prices=0`).
C'est la compilation du dépôt `pwa_sales_tablet` (Vite, `base: './'`), copiée
ici comme l'assistant de campagne : on ne la modifie pas dans ce dépôt, on la
recompile là-bas et on recopie le dossier `dist/` (avec le sha source dans
`public/tablette/VERSION`). Le dossier est l'identité de l'application
installée sur les tablettes : il ne change plus de nom.

- **Données** : `GET /api/cockpit/tablette/book?shop=…` (`src/tablette.php`)
  rend le catalogue du réseau dans les champs de la tablette — produits du
  comptoir (planogramme standard, gammes ouvertes ou proches, obligatoires),
  groupes, gammes saisonnières, prix réseau, top 8 des ventes du magasin sur
  28 jours, photos de recette du panel en vignettes 640 px. Calculé pour six
  heures ; contrat complet dans `docs/contrat-api.md`. Sans réponse valable, la
  tablette garde ses données d'exemple.
- **Photos** : `POST /api/cockpit/tablette/photos {shop}` les télécharge au
  panel, 24 par appel, et fait les vignettes sous `public/uploads/tablette/`.
  C'est l'écran BO qui l'appelle ; la tablette, jamais.
- **Allergènes** : `product.allergene` est vide dans la base (mesuré le
  02/10/2026). La tablette n'affiche donc jamais un produit « sans » un
  allergène : `alKnown: false` → « à vérifier sur l'étiquette ».
- **http / https** : le service worker, le mode hors ligne et l'installation
  sur l'écran d'accueil n'existent que sur une page sécurisée. En http la
  tablette s'ouvre et affiche ses données, sans plus. Le lien à installer sur
  les tablettes est donc le lien **https** — or, au 02/10/2026, le serveur
  répond à https://185.180.206.46/ par une connexion réinitialisée
  (*connection reset*) : à rétablir avant d'équiper les magasins.
- **Serveur de dev** : `public/router.php` sert l'`index.html` d'un dossier et
  rend 404 pour un fichier absent sous `tablette/` ou `uploads/`, comme le
  `.htaccess` (qui fait de même en production : sans cela le shell du cockpit
  partait en 200 à la place d'un morceau de script ou d'une image).

## Scouting commercial → CRM

L'écran « Scouting — où ouvrir » a **quitté le cockpit le 31/10/2026**. Il vit
dans le CRM (dépôt `atelier-espace-candidat`), menu *Développement* →
**Étude de faisabilité** : le même code (écran, gabarits, styles, routes
`/scouting/*`, dossier d'implantation et plan d'expansion en PDF), recopié tel
quel sous l'API du CRM, sur la **même base** — tables `ceo_scouting_*` et
réglages `scoutingParams`, `scoutingReferences`, `scoutingReseau`,
`scoutingMagasins` de `ceo_app_setting`. La documentation de l'écran est dans
le `CLAUDE.md` du CRM (« Portail Recrutement & Développement »). Une ancienne
adresse `#/scouting` ouvre l'accueil et dit où l'écran est parti.

Restent dans le cockpit, parce que d'autres écrans en vivent :

- `GET /scouting/demarchage` — les lieux à démarcher autour d'un magasin
  (OpenStreetMap, gardés 45 jours dans `ceo_scouting_etude`), pour
  *Développement commercial*, *Prospection* et *Prospection mobile* ;
- `GET /scouting/reseau` — les magasins du réseau et leur position (fiche
  Google raccordée, ou pointée sur la carte de l'Étude de faisabilité du CRM) ;
- `src/scouting_osm.php` (les relevés Overpass) et le cron hebdomadaire
  `bin/scouting_refresh.php`, posé par `bin/deploy.sh` (dimanche 4 h 15, zoning
  6 h 15) : il tient à jour `ceo_scouting_tile`, que l'Étude de faisabilité du
  CRM lit et qu'elle ne relit que sur demande ;
- `bin/scouting_grille.py`, qui reconstruit la grille de population du
  recensement — servie désormais par le CRM (`app/crm/web/public/geo/`) ;
- la création des tables `ceo_scouting_*` (`ensureScouting`, `schema.sql`) :
  la base est commune, rien n'est supprimé.

## Mix par 1 000 € vendus

La base de calcul du réseau : pour 1 000 € encaissés, une ligne par produit —
**volume** en unités, **prix de vente**, **euros** pesés et **part du CA**. La
colonne des parts somme à 100 %, celle des euros à 1 000 € : si le total tombe
à côté, le lot compté n'est pas celui qu'on croit. Toutes les références, puis
un récapitulatif par catégorie.

```bash
php bin/mix1000.php                      # dernier mois clos
php bin/mix1000.php --fenetre=annee      # ou --fenetre=trimestre
php bin/mix1000.php --ca=12000           # les mêmes unités ramenées à 12 000 € de CA
php bin/mix1000.php --top=40             # n'en détailler que 40
php bin/mix1000.php --csv > mix.csv
```

C'est le mix du **réseau** : les ventes des magasins actifs additionnées, puis
ramenées à 1 000 €. L'en-tête nomme les magasins comptés (`Mix de 4 magasins :
…`, quand la source sert la ventilation par magasin), pour qu'on voie sur quoi
la base repose au lieu de la croire.

Le rapport est **sans échelle** : il vaut pour un magasin à 8 000 €/semaine comme
pour un à 25 000 €. Dimensionner une production, une commande ou le prévisionnel
d'une ouverture revient à le multiplier par le CA visé ÷ 1 000 — c'est ce que
fait `--ca`.

Le chiffre vient de `ep_products()`, la source de l'écran **Scoring des
références** : le panel d'abord, la caisse locale en repli, `ceo_product_month_sales`
en dernier. Le CA d'une référence est `volume × prix moyen`, comme le fait
l'écran — deux écrans ne calculent pas la même chose de deux façons.

Le dénominateur est le **CA des références vendues**, pas le CA du P&L : les
euros par catégorie somment donc exactement à 1 000 €. Un magasin dont le P&L
dépasse ce total (B2B, prestations hors caisse) applique la base à sa seule part
caisse.

## Notes

- Les couleurs des 6 leviers de gestion viennent d'`of_tag.color` (source de
  vérité). Point connu, hérité de la source : **Labour Cost et Overhead Cost
  partagent la même couleur** (`#8b5cf6`) — à corriger dans `of_tag` si voulu.
- La page « Encodage franchisé » (saisie mensuelle côté franchisé) fait partie
  du bundle de design mais n'est pas encore implémentée ici — itération suivante.
- Le PDF des rapports est simulé (aperçu + générateur HTML/CSS copiables) ;
  la génération réelle (Chromium headless) et l'envoi d'emails restent à brancher.
- Le contrôle des posts ne parle pas encore à Facebook : les posts sont soumis
  dans le cockpit (ou par une intégration à écrire), et la publication est
  déclarée à la main. La relecture, elle, est réelle et tourne côté serveur.
- La règle « Fautes du lexique » est une **liste de fautes fréquentes**, pas un
  correcteur orthographique : elle ne trouve que ce qu'on y met. Elle
  s'enrichit au fil des relectures, dans le réglage `fbControle`.
