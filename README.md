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
`/pwa/reports`, `/referentiels/facebook-regles`, `/facebook/posts`.

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
