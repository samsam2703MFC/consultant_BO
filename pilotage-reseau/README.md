# Cockpit CEO — L'Atelier by

Back-office de pilotage du CEO du réseau de franchises **L'Atelier by** :
tableau des magasins, heatmap mensuelle, objectifs de CA 1/3/5 ans, suivi
budget par magasin (théorique / budget / réel), marge & maîtrise des coûts,
encodage du budget, scoring produits, projets (kanban par famille, fiche,
rétroplanning), tâches consultants, contrôle des posts Facebook des magasins,
reporting automatisé, journal et paramètres ; MétaDonnée — les formulaires de prospects Facebook et
Instagram jusqu'à l'ERP du franchisé (par la connexion Meta du CRM, dont le flux est recopié), le suivi de
leur traitement et les SMS au gérant (`src/metadonnee.php`, `docs/DEPLOIEMENT.md`).

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
  marketing/            module Marketing — campagnes, réseaux sociaux, emailing
    index.html          coquille (monte aussi assets/js/newsletter.js)
    marketing.js        état S, rendre(), le rail et le flux en cinq étapes, gestes d'écriture
    marketing.css       styles de la page
    index.html          coquille
    dashboard.js        état S, rendre()/brancher(), lectures lireAux()
    dashboard.css       styles de la page
  tablette/             tablette des vendeuses « Book vendeuses » — application
                        compilée (dépôt pwa_sales_tablet), copiée telle quelle
  task/                 cadre des tâches du panel — page à part : vue Document
                        (une section par checklist, réglages en vignettes qu'un
                        clic change, pour une tâche ou toute la checklist),
                        tableau, planning, assistant (checklist et tâches en 4 étapes),
                        duplication (jours, heure, photo), aperçu des appels
                        avant tout envoi ; serveur : src/task_cadre.php
  formation/            module Formation — référentiel RH du panel (postes,
                        sous-postes, compétences, formations, procédures),
                        manuel opératoire en ligne (tablette), doc des routes
                        API ; design du module marketing (docs/formation.md)
                        formation-sop.js + sop/ : les SOP du réseau (FR/NL) et la tablette
                        du poste (sop/tablette.html)
src/
  Db.php                connexion PDO
  endpoints.php         lectures (GET) — un endpoint par écran
  writes.php            écritures (POST/PATCH/PUT) + journalisation
  fbcontrole.php        agent de contrôle des posts Facebook (moteur de règles)
  marketing_hub.php     GET /marketing/hub — tous les canaux marketing en une lecture
  marketing_sources.php GET /marketing/sources — API (TFBuddy, locales, extérieures) et tables du module
  marketing_tables.php  registre des tables mar_, migration RENAME, alias des anciennes adresses
  social_publication.php publication Facebook / Instagram / LinkedIn, horloge /marketing/publication/cron
  linkedin_api.php      client LinkedIn (API Posts, dépôt d'images)
  meta_graph.php        client Meta Graph (lecture des pages, publication)
  formation.php         module Formation : lectures du référentiel RH du panel
  sop.php               module Formation › Standards (SOP) : SOP en base (FR/NL), versions, suivi,
                        compétences du panel, tablette du poste (PIN), PDF — docs/formation.md
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
  formation.md          module Formation : écrans, modèle, constats, API
  formation-routes.md   les 237 routes formation du swagger (généré)
```

## API — aperçu

Base : `/api/cockpit`. Lectures (GET) : `/meta`, `/referentiels/leviers`,
`/referentiels/kpis`, `/referentiels/email-templates`,
`/referentiels/project-templates`, `/stores`, `/stores/perf`,
`/stores/budgets`, `/targets`, `/consultants`, `/fournisseurs`, `/projects`,
`/projects/crm`, `/people`, `/reporting`, `/journal`, `/products/scoring`,
`/pwa/reports`, `/pwa/tasks/nc`, `/marketing/social/regles`, `/marketing/social/posts`,
`/marketing/hub`, `/marketing/sources`, `/marketing/publication/etat`.

Écritures : `POST /projects`, `PATCH /projects/{id}`,
`POST /projects/{id}/tasks`, `PATCH /projects/{id}/tasks/{taskId}`,
`PATCH /projects/{id}/milestones/{index}`, `POST /tasks/{id}/reminder`,
`PUT /stores/{id}/budget`, `PATCH /reporting/reports/{id}`,
`POST /reporting/reports/{id}/send`, `PATCH /reporting/alerts/{id}`,
`PUT /parametres/{key}`, `POST /journal`, `POST /marketing/social/posts`,
`POST /marketing/social/posts/{id}/controle`, `PATCH /marketing/social/posts/{id}`,
`PATCH /marketing/social/posts/{id}/ecarts/{ecartId}`,
`POST|DELETE /marketing/social/posts/{id}/publier`, `PUT /marketing/publication/reglages`,
`POST /marketing/publication/tester`, `POST /marketing/publication/media`
(les anciennes adresses `/facebook/posts…` restent servies).

Chaque écriture produit une ligne dans `ceo_journal_entry` (écran Journal).
Détail complet, exemples JSON et DDL : `docs/contrat-api.md`.

Module Formation (lectures, et écritures relues au panel sous `/formation/niveau`,
`/formation/competence(s)`, `/formation/employe`) : `/formation/referentiel`, `/formation/liens`,
`/formation/competence`, `/formation/niveau`, `/formation/procedure`,
`/formation/formation`, `/formation/ressource`, `/formation/equipe`,
`/formation/sonde`, `/formation/pdf` — voir `docs/formation.md`.

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

Tables : `mar_social_post`, `mar_social_ecart` (DDL dans `sql/schema.sql`, créées
aussi au démarrage par `ensureFacebook()` pour les bases déjà en service).

## Module Marketing

> **Retiré le 22/10/2026.** Il ne reste que le Marketing servi à `/marketing/` (dépôt
> `marketing`) : `public/marketing/index.html` n'est plus qu'un renvoi vers lui ; la
> console (Marketing › Aujourd'hui) et le rail (Marque & marketing → « Marketing ↗ ») y
> mènent. Les routes `/api/cockpit/marketing/…` restent
> en service (lecture du hub par la console, Brand Guard, avis). Ce qui suit décrit
> l'ancien portail.

**`public/marketing/`** — `…/marketing/` pour la marque, `…/marketing/?shop=4` pour un
magasin. Les réseaux sociaux, l'emailing, les avis et les campagnes vivaient dans quatre
sections du rail et ne se lisaient jamais ensemble. Le portail les réunit autour d'un
**flux de travail** : la campagne est le pivot, chaque contenu s'y rattache, et chaque
étape dit ce qui l'attend.

**Le rail** (à gauche, comme celui du cockpit) porte le menu : la vue — tout le réseau ou un
magasin —, le tableau de bord, les cinq étapes avec leurs compteurs (rouges quand il y a
urgence) et, dépliées sous l'étape ouverte, ses sous-vues ; les paramètres ; les liens vers
l'assistant de campagne et le cockpit. Sous 900 px de large, il devient un tiroir qu'ouvre
le bouton ☰ de l'en-tête.

| Étape | Ce qu'on y fait |
|---|---|
| **Tableau de bord** | la chaîne des cinq étapes avec leur compteur (rouge s'il y a urgence), « À faire » trié par étape, les 14 prochains jours |
| **1 · Planifier** | les campagnes et, dépliées, leurs contenus à l'étape où ils en sont ; « + Post » et « + Newsletter » les rattachent ; calendrier |
| **2 · Créer** | le rédacteur de post (campagne, réseaux, visuel téléversé, aperçu, « prêt ? » réseau par réseau) et ses brouillons ; la newsletter (le module `newsletter.js`, rattachable à une campagne) |
| **3 · Valider** | le contrôle de l'agent, la décision de la marque, les refusés ; la charte Brand Guard sur 30 jours |
| **4 · Publier** | les posts validés, réseau par réseau (programmé, publié, en échec, annulé), « Publier maintenant », « Programmer », « Annuler », ou « Publié à la main ? » ; tout ce qui est programmé, posts et newsletters, dans l'ordre |
| **5 · Mesurer** | un chiffre par canal, les publiés avec leurs liens, les newsletters envoyées, ce que chaque campagne a dit ; avis Google ; posts sauvages |
| **Paramètres** | publication, API et tables, agent de contrôle, charte, pages Facebook, avis Google (la marque ; un magasin n'y voit que l'inventaire) |

- **Une lecture** : `GET /marketing/hub` (`src/marketing_hub.php`) compile campagnes,
  newsletters, posts (avec leurs publications), charte et avis, avec les calculs de leurs
  écrans. Un canal dont la table manque est déclaré indisponible, les autres restent lisibles.
- **La campagne, pivot** : `mar_social_post.campagne_id` et `mar_nl_campagne.campagne_id`.
  Une campagne en cours sans aucun contenu rattaché (ni daté dans sa période, pour un
  contenu qui n'est rattaché à rien) remonte dans « À faire ».
- **Publication Facebook, Instagram, LinkedIn** (`src/social_publication.php`,
  `src/meta_graph.php`, `src/linkedin_api.php`) : un post choisit ses réseaux ; validé, il
  se programme (réglage « Programmer à la validation ») et l'horloge
  (`bin/social_cron.sh`, toutes les 5 minutes) le publie à son heure — une ligne
  `mar_social_publication` par réseau. Contrôle préalable par réseau (Instagram exige un
  visuel téléversé, LinkedIn ne publie que les posts de la marque…) : un réseau qui ne peut
  pas suivre est annulé avec sa raison, les autres partent. **Éteinte par défaut**
  (Paramètres → Publication) ; les jetons restent sur le serveur. Mise en service :
  [`docs/publication-reseaux.md`](docs/publication-reseaux.md).
- **Tables sous `mar_`** (`src/marketing_tables.php`) : tout ce que le portail possède est
  rangé par famille — `mar_social_*` (posts, écarts, publications), `mar_bg_*` (charte),
  `mar_nl_*` (emailing), `mar_avis*`, `mar_campagne_*` (mesure, objectifs, annexes). Les
  anciennes (`ceo_fb_*`, `brand_guard_*`, `ceo_nl_*`, `ceo_shop_review|reputation`,
  `ceo_campagne_*`, `ceo_mkt_*`) sont **renommées** au premier appel après déploiement, en
  une seule instruction `RENAME TABLE` (données, index et clés suivent). Retour arrière :
  `docs/contrat-api.md`, « Tables du portail Marketing ».
- **API sous `/api/cockpit/marketing/…`** : `/marketing/social`, `/marketing/newsletter`,
  `/marketing/avis`, `/marketing/publication`, à côté de `/marketing/brand-guard`. Les
  anciennes adresses (`/facebook/posts`, `/newsletter`, `/reputation`,
  `/referentiels/facebook-regles`) restent valables — les mails déjà partis portent le
  suivi `/newsletter/o|c|u/…` — réécrites avant le routage (`MAR_ALIAS`).
- **« API et tables »** (Paramètres) : `GET /marketing/sources` suit, dans le CODE, chaque
  route que le portail appelle et dit ce qu'elle touche — API reliées à TFBuddy (panel et
  ERP, `atelierby.tfbuddy.com`), locales, services extérieurs (Meta, LinkedIn, Google,
  Anthropic, SMTP) — puis chaque table avec son origine (portail `mar_`, module marketing
  autonome, cockpit, TFBuddy), lue ou écrite, présente ou non en base, et le registre
  `mar_` avec l'ancien nom de chaque table. Au 13/10/2026 : 34 routes, **aucune n'appelle
  le panel ni l'ERP TFBuddy**, une seule table TFBuddy (`shops`, lue).
- **Règles de l'agent** (correction du 13/10/2026) : activer une règle n'efface plus ses
  paramètres — `fbRegles()` reprend ceux qui manquent du défaut livré.
- **Écritures** : comme la newsletter, la vue magasin n'est pas un droit — l'API ne vérifie
  pas qui parle (auth intégrée ou ERP en amont).
- **Nom** : l'ancien module marketing autonome était servi à `/marketing/` à la **racine
  du domaine** ; celui-ci vit sous le cockpit (`/consulant_bo/marketing/`).

Tests : `php bin/tests.php` (sections « Module Marketing », « Règles de l'agent »,
« Inventaire des sources », « Migration mar_ » et « Publication »).

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

## Module RH · Dimona (/hr)

Les franchisés déclarent les Dimona de leur personnel (entrée, sortie, modification, annulation) à l'ONSS,
par l'API REST Dimona v2, depuis **https://185.180.206.46/hr/** : une page autonome dans le design system
Marketing, tout en assistants (« Connexion ONSS », « Engager »), avec la trace de chaque déclaration et de
son résultat. Identité : le compte de la console franchisé (le siège lit, ne déclare pas). Simulation seule
tant que la production n'est pas ouverte ; un certificat par société. Code `src/hr/`, page `public/hr/`,
API `/api/v1/erp/dimona/*`, tables `hr_dimona_*`, tests `php tests/hr/run.php`. Tout est dans
**`docs/dimona.md`**.

## Paramètres du budget : Répartition des charges = le Compte de résultat réseau du CRM

Décision du 07/11/2026 (« option A, % validé réseau, corrige les fautes, mets en ligne — le CRM fait foi ; dans le
Cockpit, c'est pour voir si le franchisé est dans les clous » ; analyse : `docs/maquettes/budget-pnl-crm/`).
`src/budget_crm.php` lit le Compte de résultat réseau du Recrutement (`impl_pnl`) par sa route d'intégration
(`GET …/v1/integration/recrutement/compte-resultat`, `erpLireRecrutement`, jeton de service — jamais ses tables) et
réécrit `budgetCharges` : rubriques, postes (clé `cle` du CRM), libellés, **% réseau** (`pctTheo`, verrouillé) et
sous-totaux (« Marge brute », « Disponible pour rémunération » = CA − `tout`). Le Cockpit ne garde que le **% validé**
du réseau (`pct`, seul champ que `PUT /parametres/budgetCharges` écrit encore) ; l'écart = validé − réseau, en bordeaux
au-dessus du modèle. Les identifiants des lignes sont gardés (charges encodées mois par mois) ; deux lignes du Cockpit
pour un poste du CRM sont fondues (« Royalties franchise » + « Redevance assistance » → « Royalties franchise &
assistance », % validés additionnés, charges encodées reportées). Relu à la lecture de `/stores/budgets` toutes les
dix minutes au plus, et sur « ↻ Relire le CRM » (`POST /budget/charges/crm`) ; « Modifier dans le CRM ↗ ». CRM
injoignable : la dernière copie reste, le bandeau le dit. Sauvegarde d'avant : `budgetCharges_avant_crm_2026_11` ;
état : `budgetChargesCrm`. La pondération des jours a sa propre carte (elle n'est pas un taux de charge).

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
- **Seuils du P&L court** (07/11/2026) : le **coût matière** se juge face au taux matière du réseau posé au
  cockpit, **Budget › Paramètres** (réglage `budgetCharges`) — la catégorie que le palier de la marge brute
  retranche (« Matière première & Emballage »), somme des taux budget de ses lignes, ou des taux théoriques tant
  que le budget est à 0 (au 07/11/2026 : 38 + 2 = **40 %**). Une seule définition, `seuilsCharges()`
  (`src/endpoints.php`), lue par le jour, la période et le téléphone (qui avait 35 % en dur). Main-d'œuvre et
  frais généraux restent ceux de la table `kpi` ; repli 32 / 33 / 13,5. Les six leviers du Mois, eux, gardent les
  seuils d'alerte de la Table KPI (`ceo_kpi_def`).
- **Non-conformités** : les tâches notées sous le seuil, la veille en vue Jour,
  la période en Semaine et en Mois. **Lecture seule** — le dashboard n'écrit
  rien sur les tâches.
- **Temps morts** (onglet du bureau, `?vue=morts`) : le comptoir sans client,
  **mesuré ticket par ticket**. Un temps mort est un trou d'au moins 10 minutes
  (5 ou 15 au choix, gardé dans le navigateur) entre deux tickets qui se
  suivent, entre le premier et le dernier ticket du jour. Le jour regardé : le
  nombre de temps morts, le temps sans client, sa part de l'ouverture, le plus
  long trou, face à l'habitude (les 4 mêmes jours de semaine d'avant), puis la
  journée minute par minute — un trait par ticket, chaque temps mort en plage
  teintée, la part de chaque heure sans client. Dessous, la semaine du lundi au
  dimanche, jour par jour, sa moyenne et la semaine d'avant ; un clic sur un
  jour le lit au-dessus. Source : la **liste des tickets** du panel
  (`/shops/{id}/transactions?date=`), qui porte l'heure de chaque ticket — une
  liste par jour, complète (159 tickets dans la liste de Sombreffe le 06/11,
  159 dans `hourly-distribution`). Les heures se gravent (`tmT{shop}:{date}`)
  partout où le BO lit déjà cette liste, à la demande et au cron horaire
  (`tmMoisson`, huit semaines en arrière, 40 listes par battement). Un ticket
  isolé à 45 minutes ou plus du suivant en début ou en fin de journée (un pro
  encodé à 5 h, une correction à 21 h) est écarté. Pas au téléphone. Lecture :
  `GET /exploitation/temps-morts` ; tests : `php bin/tests_temps_morts.php` ;
  maquette et calcul réseau : `docs/maquettes/temps-morts/`.

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
- `src/scouting_osm.php`, réduit au relevé Overpass du démarchage ;
- la création des tables `ceo_scouting_*` (`ensureScouting`, `schema.sql`) :
  la base est commune, rien n'est supprimé.

Partis avec l'écran dans le CRM : la relecture hebdomadaire du cache
OpenStreetMap (`ceo_scouting_tile`) — l'ancien cron
`/etc/cron.d/cockpit-scouting` et `bin/scouting_refresh.php`, devenus le
workflow « Scouting — relecture OpenStreetMap » et `tools/scouting-relecture.php`
du CRM ; `bin/deploy.sh` efface l'ancien fichier cron du serveur — et la
reconstruction de la grille de population du recensement
(`bin/scouting_grille.py` → `tools/scouting-grille.py` du CRM).

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
