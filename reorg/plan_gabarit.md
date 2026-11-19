# REORG_PLAN — Réorganisation du bac à sable en quatre domaines

**Phase 2 : plan. Rien n'a été exécuté.** J'attends « GO » avant toute modification de code ou de base.

| Domaine | Dossier | Préfixe des tables |
|---|---|---|
| Marketing | `marketing/` | `mar_` |
| Formation | `formation/` | `for_` |
| Pilotage (consultant) | `consultant/` | `con_` |
| Dashboard / day panel franchisé | `panel/` | `panel_` |
| Partagé | `shared/` | noms actuels conservés |

Pièces jointes, toutes générées par `python3 -I reorg/audit_statique.py` (lecture seule) :

- `REORG_AUDIT.md` : l'audit de la phase 1 (arborescence par domaine, inventaire des tables, où chaque table est citée,
  clés étrangères, vues, fichiers morts) ;
- `reorg/tables.tsv` : une ligne par table (domaine, nouveau nom, FK, nombre de fichiers par nature, **liste de tous les
  fichiers qui la citent**) ;
- `reorg/fichiers.tsv` : **les 6 994 fichiers du dépôt**, chacun avec son domaine et son nouveau chemin ;
- `reorg/cles.tsv` : les noms de clés, réglages secrets, variables d'environnement et URL d'intégration, par fichier
  (aucune valeur).

---

## 0. À lire d'abord : cinq constats qui changent le plan

1. **Aucun accès à la base depuis cet environnement.** Le conteneur n'a ni client MySQL ni identifiants, et la base
   du bac à sable (`atelierby_db`, serveur de test 185.180.206.46) n'est joignable que depuis ce serveur. Les
   **nombres de lignes**, les tables **vides**, les FK, vues, déclencheurs et fonctions **réellement présents** sont
   donc « à mesurer ». Je propose de les relever à l'étape 0 de la phase 3 (ligne de base en lecture seule), avant la
   moindre modification, puis de mettre à jour ce plan avec les vrais chiffres (question B1).

2. **Le dépôt a déjà une réorganisation documentée, différente de celle-ci.** `CLAUDE.md`, `README.md` et
   `docs/PORTAILS.md` (décisions des 6 et 7 nov. 2026) prévoient quatre portails **`rec_` / `mar_` / `pil_` / `frm_`**
   dans les dossiers `recrutement-developpement/`, `marketing/`, `pilotage-reseau/`, `formations/`, et une table des
   clés **`erp_cles`**. La mission remplace `pil_` par `con_` + `panel_`, `frm_` par `for_`, et **retire le
   Recrutement** des quatre domaines (question A1). Le code a d'ailleurs déjà commencé à créer des tables `pil_`
   (`pil_fil`, `pil_boite_*`, `pil_tresorerie_plan`…) : elles sont reprises ci-dessous.

3. **`erp_keys` n'existe pas : la table s'appelle `erp_cles`.** Toutes les mentions d'« erp keys » dans l'historique
   (« récupère-la dans la table erp keys », 12/11) désignent `erp_cles`. Je la traite comme la `erp_keys` de la
   mission : pas de préfixe, pas de renommage, jamais supprimée (question A2).

4. **La base n'est pas à nous seuls.** `atelierby_db` est la base du panel consultant (`pwa_consultant`) : le cockpit
   y lit **en SQL direct** les tables du panel TF Buddy (`transaction`, `shops`, `product`, `client`… 45 tables), de
   la PWA consultant (`mac_*`), du CRM de recrutement (`crm_*`, « même base en production ») et du signage (`sig_*`).
   La copie de l'ERP (`apps/production`) a sa **propre base, `erp`**. La phase 4 telle qu'écrite (« toutes les autres
   tables sont effacées ») **effacerait les données de ces applications** et casserait les quatre domaines eux-mêmes,
   qui lisent ces tables. Je recommande de limiter la purge aux tables **créées par ce dépôt** et non conservées
   (question A3).

5. **Le contrôle bloquant de la phase 4 échouerait tel quel** pour `ceo_scouting_*` (citées par `endpoints.php`,
   `installer.php`, `sql/schema.sql`, `bin/deploy.sh`, qui vont dans `shared/`) et pour les anciens noms gardés dans
   les registres de migration. Le code qui les cite doit être retiré **avant** le contrôle, pas après (§9, étape 7).

---

## 1. Correspondance des tables : ancien nom → nouveau nom → domaine

{{N_PROPRES}} tables créées par ce dépôt, plus {{N_EXT}} tables d'autres applications que le code lit. **{{N_REN}}
renommages** : Formation {{N_REN_FOR}}, Marketing {{N_REN_MAR}}, Dashboard franchisé {{N_REN_PANEL}}, Pilotage
consultant {{N_REN_CON}}. Règle appliquée : `{préfixe}_{entité}`, l'ancien préfixe remplacé, le reste du nom inchangé
(qui connaît `ceo_sop_suivi` retrouve `for_sop_suivi`). Aucune collision : aucun nouveau nom n'est déjà pris dans
l'inventaire (à revérifier en base à l'étape 0, en particulier qu'aucune table `panel_*` ou `for_*` n'existe déjà).

Le nombre de lignes de chaque table sera relevé à l'étape 0 ; la colonne « fichiers » compte les fichiers de code,
écrans, scripts, tests et SQL qui citent la table (la liste est dans `reorg/tables.tsv`).

{{RENOMMAGES}}

#### Tables qui gardent leur nom

- **Partagées** (`shared/`, nom actuel conservé) : {{INCH_SHARED}}.
- **Marketing, déjà au bon préfixe** : {{INCH_MAR}}.
- **Pilotage consultant, déjà au bon préfixe** : {{INCH_CON}}. Les cinq `ceo_verif_commande / geste / matiere /
  produit / stock_jour` ne sont décrites que dans `docs/plan-verification.md` : jamais créées par le code, rien à
  renommer (à confirmer en base).
- **Tables d'autres applications lues par les quatre domaines** (lecture seule, ni renommées ni supprimées) :

{{EXTERNES}}

---

## 2. Correspondance des fichiers : ancien chemin → nouveau chemin

Proposition (question A8) : un dossier par domaine **à la racine du dépôt**, avec la même organisation interne que
`pilotage-reseau/` aujourd'hui (`src/`, `public/`, `bin/`, `tests/`, `sql/`, `docs/`, `tools/`), plus `shared/` pour
ce qui sert à plusieurs domaines. Ce qui est hors périmètre **reste dans `pilotage-reseau/`** jusqu'à la phase 4.

```
consultant_BO/
├── marketing/     src/ public/ bin/ tests/ sql/ docs/ tools/
├── formation/     …
├── consultant/    …
├── panel/         … (src/franchise/* aplati dans panel/src/, apps/employee/ dans panel/apps/employee/)
├── shared/        Db, accès, clés, clients ERP/panel/IA, routeur de l'API, monolithes, déploiement, config
├── pilotage-reseau/   hors périmètre jusqu'à la phase 4 : apps/production (ERP), module RH (src/hr, public/hr…)
├── docs/          registre et design system communs (inchangé)
└── .github/workflows/
```

Les déplacements se font par `git mv` (historique gardé). **Les URL publiques ne changent pas** : le déploiement reste
unique (un workflow, un `deploy.sh`), et l'Apache du serveur sert chaque page à son adresse actuelle depuis son nouveau
dossier (`/consulant_bo/dashboard/` depuis `panel/public/dashboard/`, `/formation/` depuis `formation/public/formation/`,
`/consulant_bo/tablette/` — identité des tablettes installées — depuis `panel/public/tablette/`, etc.).

Tableau par dossier ci-dessous : 2 552 fichiers déplacés ; 4 442 restent en place (4 241 de la copie de l'ERP, 183 du module RH et des maquettes du scouting, 18 fichiers communs de la racine du dépôt).
Le détail fichier par fichier est dans `reorg/fichiers.tsv`.

{{FICHIERS}}

Les fichiers qui servent plusieurs domaines et ne peuvent pas être découpés sans refonte vont dans `shared/` :
`src/endpoints.php` (9 175 lignes, toutes les lectures de tous les écrans), `src/writes.php` (toutes les écritures),
`src/installer.php` (toutes les créations de tables), `sql/schema.sql` et `sql/seed.sql`, `public/index.html`,
`public/assets/js/app.js` et `templates.js` (la SPA du cockpit, tous domaines). Les découper est un chantier à part
(question A13) : la règle « un fichier = un seul domaine » n'est donc tenue que pour les autres fichiers.

---

## 3. Clés et intégrations

### 3.1 `erp_cles` (= `erp_keys`) : en clair ou chiffrée ?

**En clair.** La colonne `valeur` (MEDIUMTEXT) porte la valeur telle quelle ; `secret = 1` ne fait que la masquer à
l'écran et `indice` garde les 4 derniers caractères. `docs/PORTAILS.md` prévoit qu'un portail chiffre « au besoin »
ses propres lignes (Marketing : `mar.{module}.{nom}`), les lignes `commun` restant en clair. **Il n'existe donc pas de
clé de chiffrement d'`erp_cles`** dans ce dépôt. Migrer d'autres secrets vers `erp_cles` les met en clair dans une
table lue par plusieurs applications : à garder en tête (question A11).

Accès aujourd'hui : un helper, `src/erp_cles.php` (`erpCle()`, `erpCleEcrire()`), qui ne lit que les portails `commun`
et `pil`. **Deux lectures directes en SQL** à remplacer par ce helper (exigé par la mission) : `src/metadonnee.php:1005`
(clés Meta) et `tools/sonde_assistant_halle.php` (sonde, lit aussi la colonne `valeur`). Un test simule la table
(`bin/tests_metadonnee.php:26`).

### 3.2 Tableau des clés

Noms et emplacements seulement. « Domaines » : qui les utilise. Action : **déjà** dans `erp_keys`, **à migrer** vers
`erp_keys`, **doublon** à supprimer (une fois la valeur reprise), **reste** (connexion à la base, secret interne,
cache, ou hors périmètre).

| Clé | Où aujourd'hui | Domaines | Action proposée |
|---|---|---|---|
| `meta_app_id`, `meta_app_secret`, `meta_page_id`, `meta_page_token`, `meta_system_token` | `erp_cles` (commun) ; lues en SQL direct par `src/metadonnee.php` | Marketing | **déjà** ; lecture à passer par le helper |
| `smtp_host`, `smtp_port`, `smtp_secure`, `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_from_name` | `erp_cles` (commun), `src/smtp.php` | tous | **déjà** |
| `anthropic_api_key`, `anthropic_workspace_id` | `erp_cles` (commun), `src/anthropic.php` ; lue en SQL direct (colonne `valeur` comprise) par `tools/sonde_assistant_halle.php` | Dashboard, Pilotage | **déjà** |
| `tfbuddy_base`, `tfbuddy_phone`, `tfbuddy_password` | `erp_cles` (commun), écrites par `src/writes.php`, lues par `src/panel_api.php` | tous | **déjà** |
| `erp_jeton_service` | `erp_cles` (commun) | partagé (API d'intégration), Dashboard | **déjà** |
| `rec_api_url`, `pil_api_url`, `mar_api_url` | `erp_cles` (commun) | partagé, Dashboard | **déjà** (noms gardés : lus par les autres dépôts, question A12) |
| `pil_boite_{magasin}_adresse`, `_utilisateur`, `_mot_de_passe`, `_imap`, `_nom` | `erp_cles` (portail `pil`) | Dashboard | **déjà** (noms et portail gardés, question A12) |
| réglage `anthropic` (clé, espace) | `ceo_app_setting`, `src/anthropic.php` (prime sur `erp_cles`) | Pilotage, Dashboard | **doublon** de `anthropic_api_key` |
| réglage `panelApi` (adresse, téléphone, mot de passe) ; `PANEL_API_BASE`, `PANEL_API_PHONE`, `PANEL_API_PASSWORD` ; `config.php › panelApi` | `ceo_app_setting`, environnement, `config.php` | tous | **doublon** de `tfbuddy_*` |
| réglage `erpAdmin` (adresse, téléphone, mot de passe du compte admin ERP) ; `ERP_ADMIN_BASE/PHONE/PASSWORD` ; `config.php › erpAdmin` | `ceo_app_setting`, environnement, `config.php` ; `src/erp_api.php`, `src/writes.php` | Dashboard, Pilotage | **à migrer** (nouvelles clés communes `erp_admin_*`) |
| réglage `google` ; `GOOGLE_API_KEY` ; `config.php › googleApiKey` | `ceo_app_setting`, environnement, `config.php` ; `src/google_api.php`, `src/writes.php` | Marketing (réputation) | **à migrer** (une clé commune, ex. `google_places_api_key`) ; les deux autres = doublons |
| réglage `smtp` | `ceo_app_setting`, `src/smtp.php` (prime s'il est complet) | tous | **doublon** de `smtp_*` |
| réglages `mdMeta` (jeton de vérification du webhook), `mdMetaSecret`, `mdMetaJeton`, `mdMetaJetonCrm`, `mdJeton`, `mdSmsJeton` | `ceo_app_setting`, `src/metadonnee.php` | Marketing | **à migrer** (portail `mar`) ; `mdMetaSecret` / `mdMetaJeton` probablement doublons de `meta_app_secret` / `meta_page_token` (à comparer à l'étape 0, sans afficher) |
| `META_SYSTEM_TOKEN`, `META_APP_SECRET`, `META_VERIFY_TOKEN` ; `config.php › meta.*` | environnement, `config.php` | Marketing | **doublons** de `meta_*` |
| réglage `brandGuard` (accès Meta) | `ceo_app_setting`, `src/meta_graph.php:38` | Marketing | **à migrer** ou doublon de `meta_system_token` |
| réglage `socialPublication` ; `LINKEDIN_ACCESS_TOKEN` | `ceo_app_setting`, environnement, `src/linkedin_api.php` | Marketing | **à migrer** (`linkedin_access_token`, portail `mar`) |
| `SMSAPI_TOKEN`, `SMSAPI_BASE` ; `config.php › smsapi` | environnement, `config.php` (MétaDonnée) | Marketing | **à migrer** |
| `TWILIO_SID`, `TWILIO_TOKEN`, `SMS_EXPEDITEUR` ; `config.php › sms` | environnement, `config.php` (SMS des primes) | Dashboard | **à migrer** |
| réglage `marketingApi` ; `config.php › marketingApi` | `ceo_app_setting`, `config.php` | Dashboard | **doublon** de `mar_api_url` |
| réglage `marketingServiceTokenFile` (chemin d'un fichier de jeton, défaut en dur `/var/www/private/marketing/service-token`) | `src/endpoints.php:4822` | Marketing, Dashboard | **à migrer** (jeton dans `erp_cles`) ou doublon de `erp_jeton_service` |
| réglage `pushVapid` (paire de clés Web Push) | `ceo_app_setting`, `src/push.php` | partagé | **à migrer** (question A11) |
| `MAR_ERP_API_BASE`, `MAR_ERP_API_TOKEN` (copie du module Marketing, fichier `MAR_ENV_FILE`) | fichier d'environnement du serveur, `public/api/marketing` | Marketing | **à migrer** ; probable doublon de `tfbuddy_*` |
| réglages `pwaBase`, `erpUrl`, `espaceBoutiqueUrl` ; `COCKPIT_PWA_BASE`, `COCKPIT_ERP_URL`, `COCKPIT_ESPACE_BOUTIQUE_URL` | `ceo_app_setting`, environnement, `config.php` | Pilotage, Dashboard | **à migrer** (adresses d'autres applications, comme `rec_api_url`) — question A11 |
| réglages `authSecret`, `authPasswordHash`, `boiteSecret`, `accesCodes`, `rapportsJeton`, `visitesJeton`, `nlJeton`, `socialJeton`, `auditsJeton`, `boiteJeton` ; `aia_reglage › sel` | `ceo_app_setting`, `aia_reglage` | tous | **reste** : secrets internes (signature de sessions et de liens, mot de passe haché, codes d'accès), pas des clés d'intégration — question A11 |
| réglages `panelApiToken`, `erpAdminToken`, `foodCostApi` | `ceo_app_setting` | partagé | **reste** : caches (jeton de session obtenu, chiffres), pas des clés |
| réglages `cockpitBase`, `urlPublique`, `formationUrl`, `cqMailBase` ; `COCKPIT_URL`, `FORMATION_URL` | `ceo_app_setting`, environnement | tous | **reste** : adresses du cockpit lui-même (configuration) |
| `TABLETTES_CODE` (fichier privé `tablettes_code`) | environnement, `config.php`, `/var/lib/consulant_bo/prive` | Formation | **reste** : code de gestion interne (question A11) |
| `CB_SEAL_KEY_FILE`, `CONSOLE_BO_ACCOUNTS` ; `config/console_bo_accounts.sealed` (commis, scellé) | environnement, secret GitHub, dépôt | partagé | **reste** : comptes de la console, chiffrés hors dépôt |
| `HR_*`, `hr.masterKey`, `hr_dimona.key`, secret GitHub `HR_DIMONA_MASTER_KEY` | environnement, dossier privé | RH | **reste** (hors périmètre) |
| secrets de la copie de l'ERP (`deepl_api_key`, `smsapi_token`, `anthropic_api_key` dans son propre magasin de secrets ; `app/.env`) | base `erp`, serveur | ERP | **reste** (hors périmètre) — doublons possibles de `erp_cles`, signalés |

**Restent dans l'environnement, sans bouger** (listés seulement) : `COCKPIT_DB_HOST`, `COCKPIT_DB_PORT`,
`COCKPIT_DB_NAME`, `COCKPIT_DB_USER`, `COCKPIT_DB_PASSWORD` (aussi secrets GitHub `COCKPIT_DB_USER` /
`COCKPIT_DB_PASSWORD`) ; `MAR_DB_HOST/PORT/NAME/USER/PASSWORD/SOCKET` (copie Marketing) ; `HR_DB_DSN/USER/PASSWORD` ;
`DEPLOY_SSH_PASSWORD` (secret GitHub du déploiement). Clé de chiffrement d'`erp_cles` : **aucune** (elle est en clair).

### 3.3 URL d'intégration écrites en dur (la mission veut une variable de configuration)

À lister, pas à corriger dans cette mission (sinon changement de comportement) — question A16 :

- `https://atelierby.tfbuddy.com/api/v1` comme **valeur par défaut** : `src/panel_api.php:56`, `src/erp_api.php:47`,
  `src/verification.php:862`, `apps/employee/config/app.php:45` ;
- liens vers l'ERP en dur : `src/ventes.php:1577` (`VENTE_APPLI_URL`), `src/metadonnee.php:198` (lien d'un lead),
  `src/ca_mail.php:67`, `:458`, `:1042` (portail fournisseur, réquisitions, commandes) ;
- `https://185.180.206.46/consulant_bo/employee/` : `src/primes_fiches.php:21` (`PRIMES_FICHES_APP`) ;
- `https://webshop.atelierby.be` : `public/assets/js/newsletter.js:32` ; `https://atelierby.be` : `src/benchmark.php:658` ;
- sondes et outils : `tools/sonde_vouchers.php` (`atelierby.tfbuddy.com`, `test.tfbuddy.com`),
  `tools/assistant-src` (`test.tfbuddy.com`), `bin/deploy.sh` (adresse du serveur par défaut), `.github/workflows/*.yml`
  (adresse du serveur : configuration du déploiement, normal).

Aucun **vrai** secret n'est commis : la seule « clé » Anthropic trouvée (`apps/production/deploy/server.sh:700`) est une
fausse clé volontaire d'un diagnostic ; les autres correspondances sont des jeux d'essai ou des noms de clés.

---

## 4. Liste « à conserver »

Après la phase 3, la base ne doit plus contenir que :

1. **Les tables des quatre domaines** : toutes les `mar_*` (y compris celles du module Marketing du dépôt `marketing`,
   lues et écrites ici par sa copie `public/api/marketing`), `for_*`, `con_*`, `panel_*` ;
2. **Les tables partagées** : {{INCH_SHARED}} ;
3. **Les tables d'autres applications lues par les quatre domaines** (liste au §1) : sans elles, les écrans des quatre
   domaines tombent, et le contrôle bloquant de la phase 4 échoue ;
4. **Tables techniques** : ce dépôt n'en crée aucune (pas d'outil de migration ; l'empreinte du schéma vit dans
   `ceo_app_setting`). Le module Marketing a `mar_schema_migration` (préfixe `mar_`, donc déjà conservée). Je propose
   de garder l'historique des nouvelles migrations dans `ceo_app_setting` aussi, sans nouvelle table (question A10).
5. **Recommandé (question A3)** : toutes les tables des autres applications de la même base, même non lues ici
   (`crm_*`, reste du panel TF Buddy, `mac_*`, `sig_*`, `lp_*`, `mar_sa_*` de Social…) : elles ne sont pas à nous.

---

## 5. Liste « à supprimer en phase 4 » (proposition)

Uniquement des tables **créées par ce dépôt**. Les nombres de lignes seront relevés à l'étape 0 puis remis à jour au
moment de la purge. Aucune n'a de clé étrangère venant d'une table conservée **dans le code** (à confirmer en base).

{{A_SUPPRIMER}}

À ajouter s'ils sont encore en base (l'étape 0 le dira) : les **anciens noms** que les registres de migration
gardent en mémoire (`ceo_fb_post`, `ceo_fb_finding`, `ceo_nl_*`, `ceo_campagne_*`, `ceo_mkt_annexe*`, `ceo_visite*`,
`ceo_consultant*`, `ceo_msp`, `ceo_scoring_*`, `ceo_tache_jour*`, `ceo_rayon_photo`, `ceo_franchise_meteo`,
`ceo_equipe_releve`, `ceo_shop_review`, `ceo_shop_reputation`, `ceo_plano_meuble/niveau/note/slot/zone`) : ils n'existent
que si une migration a trouvé « les deux pleines » et s'est arrêtée — dans ce cas c'est un doublon à trancher avec
vous, pas à effacer d'office.

Le module RH (`hr_dimona_*`) et le scouting (`ceo_scouting_*`) n'y sont que si vous le confirmez (questions A4 et A5).

---

## 6. Cas ambigus : recommandation et question

| # | Cas | Ma recommandation | La question |
|---|---|---|---|
| A1 | La mission contredit le registre du dépôt (06–07/11 : `rec_` / `mar_` / `pil_` / `frm_`, dossiers `recrutement-developpement/`, `pilotage-reseau/`, `formations/`). | La mission prévaut. À l'étape 8, je réécris `CLAUDE.md`, `README.md` et `docs/PORTAILS.md` pour qu'ils décrivent les quatre domaines de la mission. | Confirmez-vous que cette mission **remplace** la décision du 06/11 (y compris : le Recrutement n'est plus un des quatre domaines) ? |
| A2 | `erp_keys` n'existe pas ; la table est `erp_cles`. | `erp_keys` = `erp_cles`, **non renommée** : le CRM, le Marketing et Social la lisent sous ce nom. | D'accord pour garder le nom `erp_cles` ? |
| A3 | `atelierby_db` est partagée avec d'autres applications ; la base `erp` (copie de l'ERP) est à part. | Purge limitée aux tables créées par ce dépôt (§5). Jamais les tables d'une autre application ; la base `erp` hors champ. | Quelle base purger, et confirmez-vous qu'on ne touche pas aux tables des autres applications ? |
| A4 | Recrutement : `ceo_scouting_*` (5 tables, créées ici) ; `crm_*` (CRM). | `crm_*` : jamais. `ceo_scouting_*` : à supprimer **seulement** si le Recrutement en a une copie ; sinon conservées, hors périmètre. | Que fait-on des données du scouting ? |
| A5 | Module RH / Dimona (`hr_dimona_*`, `src/hr/`, `public/hr/`, déclarations ONSS, certificats). | Le garder en place, hors périmètre, **non purgé** : ce sont des déclarations légales et des certificats. | Le module RH reste-t-il, ou est-il à supprimer (tables + code) en phase 4 ? |
| A6 | App employés, primes, pointage, grands prix (`mot_*`, `apps/employee`, `public/employee`, `public/borne`). | **Dashboard franchisé** (`panel_mot_*`) : l'équipe du magasin, au quotidien. | Dashboard franchisé ou Pilotage consultant ? |
| A7 | Le préfixe `panel_` : dans ce code, « panel » désigne déjà le panel TF Buddy (`src/panel_api.php`, réglage `panelApi`, colonnes `panel_id`, `panel_note`), et `atelierby_db` est « la base du panel ». | Garder `panel_` comme demandé, en vérifiant à l'étape 0 qu'aucune table `panel_*` n'existe déjà. | `panel_` confirmé, ou un autre préfixe (ex. `fra_`, `dash_`) pour éviter la confusion ? |
| A8 | Dossiers à la racine et déploiement. | Disposition du §2 ; **un seul déploiement**, Apache sert chaque page à son adresse actuelle. Les fichiers déposés (`public/uploads/` : photos de visites, réclamations, documents, SOP, planogramme…) restent **au même endroit sur le serveur**, désigné par une seule constante partagée. | D'accord pour cette disposition, et pour garder un seul déploiement (un déploiement par domaine serait une étape ultérieure) ? |
| A9 | Vérifier les nombres de lignes demande d'exécuter du code **sur le serveur du bac à sable** (étape 0, puis après chaque domaine). Les branches : la mission demande `reorg/structure-domaines`, la session travaille sur `claude/sandbox-reorganize-domains-6ewtpn`, et le serveur ne se met à jour que par un push de `deploy.trigger` sur `claude/deploiement-application-serveur-lmz1bm`. | Une sonde en lecture seule (workflow dédié, comme les `sonde-*` existants) pour l'étape 0 ; puis une livraison sur le serveur de test après chaque domaine, migrations comprises. | M'autorisez-vous (1) à pousser sur `reorg/structure-domaines`, (2) à livrer chaque étape sur le serveur de test (185.180.206.46), ou préférez-vous lancer vous-même les scripts ? |
| A10 | Aucun outil de migration. Le code crée ses tables au premier appel (`CREATE TABLE IF NOT EXISTS`) : si le nouveau code tourne avant le renommage, il crée des tables **vides** au nouveau nom, et le `RENAME` échoue. Le mécanisme existant (`marMigrer`) supprime alors la table vide : c'est un `DROP`. | Migrations `shared/db/migrations/NNN_*.php` avec `up()` / `down()` (un seul `RENAME TABLE … TO …` atomique, données, index et FK suivent), lancées par `shared/bin/migrer.php up|down|statut` **et** en tête de `ensureInstalled()`, avant toute création. Si le nouveau nom existe déjà : **arrêt et rapport, jamais de DROP**. Historique dans `ceo_app_setting` (pas de nouvelle table). | D'accord ? Faut-il des **vues de compatibilité** `ancien_nom → nouveau_nom` pour d'autres applications qui liraient des tables `ceo_` en direct (impossible à vérifier d'ici : leur code n'est pas dans ce dépôt) ? |
| A11 | Quoi migrer vers `erp_cles`, qui est **en clair** ? | Migrer les clés et adresses d'**intégration** marquées « à migrer » au §3.2 ; laisser les secrets **internes** (signature, mot de passe haché, codes d'accès, sel, code des tablettes) et les caches où ils sont. Après copie : la lecture passe par le helper, l'ancien emplacement est vidé. Le chiffrement d'`erp_cles` est un sujet à part. | Validez-vous la colonne « Action » du §3.2, ligne par ligne ? |
| A12 | Valeurs de `portail` dans `erp_cles` (`pil`, `frm`) et noms de clés `pil_api_url`, `pil_boite_*`. | **Inchangés** : d'autres dépôts les lisent ; les renommer est une donnée partagée, pas un rangement. | D'accord ? |
| A13 | Monolithes multi-domaines (`endpoints.php`, `writes.php`, `installer.php`, `schema.sql`, `seed.sql`, `index.html`, `app.js`, `templates.js`). | Dans `shared/` pour cette mission ; leur découpage par domaine plus tard. | D'accord ? |
| A14 | Tables à cheval : helpdesk `hdk_*` (cockpit **et** dashboard), benchmark `ceo_bench*`, KPI `ceo_kpi_*` / `ceo_magasin_fiche`, récurrence PWA `ceo_pwa_*`, prospection `ceo_prospect`, `ceo_sop_tache` (écrite par le cadre des tâches), `ceo_matiere_code` (scan de cartons, créée par `mesure.php`), `ceo_tablette_remarque`, tablette des vendeuses (`public/tablette`). | Helpdesk, benchmark, KPI, récurrence → `con_` ; prospection → `mar_` ; `ceo_sop_tache` → `for_` ; `ceo_matiere_code`, `ceo_tablette_remarque` et la tablette des vendeuses → `panel_`. | Validez-vous ces affectations (notées « ? » au §1) ? |
| A15 | 45 tables du panel TF Buddy, 7 `mac_`, 4 `crm_mo_`, 2 `sig_`, 36 `mar_` du module Marketing, lues ici. | À conserver telles quelles, en lecture seule (§4.3). | D'accord ? |
| A16 | Écarts d'architecture trouvés (§8) : accès directs, URL en dur, routes hors format `/api/v1/{app}/{domaine}/{ressource}`. | **Listés, pas corrigés** dans cette mission (comme demandé) ; aucune URL publique ne change. | D'accord ? |
| A17 | Maquettes (`docs/maquettes/*`, 101 dossiers) : elles citent parfois d'anciens noms de tables. | Déplacées par domaine, **contenu non réécrit** (archives de conception) ; la documentation vivante (`docs/*.md`, `LISEZMOI.md`, `CLAUDE.md`) est mise à jour. | D'accord ? |

Les affectations « ? » de fichiers (même logique) : `benchmark*.php`, `helpdesk.php`, `prospection.php`,
`scouting_osm.php`, `kpi*.php`, `recurrence_pwa.php`, modules primes / pointage / planning de l'app employés,
`tablette*.php`, `promos_magasin.php` (rangé avec les promotions au Marketing, bien que la page soit dans le dashboard),
`stats_ventes.php` (cœur du dashboard), `checklists_codes.php` (référentiel commun → `shared/`).

---

## 7. Lectures croisées qui resteront après le renommage

Le renommage ne change rien aux lectures entre domaines : le code d'un domaine continuera de lire en SQL des tables
d'un autre (même base, même API). Les faire passer par une API serait une nouvelle fonctionnalité, hors mission. À
trancher avant le codage final :

{{LUS_PAR_AUTRE}}

Et les clés étrangères entre domaines (déclarées dans le code) : `mar_avis`, `mar_avis_fiche`, `mar_social_post` →
`ceo_shop` (partagée) ; `con_project_task`, `con_shop_budget`, `con_shop_month_perf` → `ceo_shop` ; `con_task_issue` →
`con_project_task` ; `con_product_month_sales` → `con_product`. Un `RENAME TABLE` les garde (InnoDB suit les
références) ; aucune ne pointe vers une table à supprimer.

---

## 8. Écarts d'architecture relevés (listés, non corrigés)

**Accès directs à des tables qui ne sont pas les siennes** (la règle : passer par une API) :

- le cockpit lit **en SQL** 45 tables du panel TF Buddy (`transaction`, `transaction_product`, `shops`, `product*`,
  `client*`, `material*`, `stocktaking*`, `user_membership`, `user_profile`, `franchisee_employee_schedule`…) dans une
  98 fichiers (code, scripts et tests : `src/ventes.php`, `src/endpoints.php`, `src/inventaires.php`, `src/commandes.php`,
  `src/primes.php`…) ;
- il lit les tables de la PWA consultant (`mac_task_review` : 21 requêtes, `mac_shop_monthly_pnl`,
  `mac_google_rating_snapshot`…), du CRM (`crm_mo_*` : `src/tablettes.php`, `src/referentiel.php`) et du signage
  (`sig_products` : `src/endpoints.php`) ;
- `public/api/marketing/` est une **copie** de l'API du module Marketing qui écrit les tables `mar_campaign*`,
  `mar_fund*`, `mar_lever`… dont le dépôt `marketing` est aussi propriétaire (deux propriétaires, deux définitions) ;
- **scripts et crons en accès direct à la base** : `bin/seed.php`, `bin/push_stock.php`, `bin/console_bo.php`,
  `bin/db_admin.php`, `bin/carte_sources.php`, `bin/disque_alerte.php`, les crons `bin/*_cron.sh` (via PHP) ; les
  sondes `tools/sonde_*.php` (dont la lecture directe d'`erp_cles`) ;
- `erp_cles` lue en SQL direct par `src/metadonnee.php` (corrigé à l'étape 1, exigé par la mission).

**Routes** : l'API du cockpit est servie à `/consulant_bo/api/cockpit/{ressource}` (et `/formation/api/cockpit/…`),
pas au format `{API_BASE_URL}/api/v1/{app}/{domaine}/{ressource}`. Conformes : `/hr/api/v1/erp/dimona/*`,
`/api/v1/marketing/*` (copie Marketing). **Aucune URL publique ne change dans ce plan.**

---

## 9. Ordre d'exécution (après « GO »)

Chaque étape : un commit (ou une courte série) sur la branche de travail, puis **vérification** : `php -l` sur tous
les PHP ; `bin/tests_noms_globaux.php` et `bin/test_chargement.php` (bloquants en CI) ; tous les `bin/tests_*.php`
(ligne de base aujourd'hui : **48 verts, 4 en échec déjà connus et ignorés par la CI** — `tests_ca_net`,
`tests_catalogue`, `tests_cq_mail`, `tests_documents`) ; `tests/hr/run.php --unit` (vert) ; une recherche qui prouve
qu'aucun ancien nom de table ne reste dans le code hors registres de migration ; puis, sur le serveur de test, **le
nombre de lignes de chaque table renommée identique à celui de l'étape 0**. Si quelque chose casse, je corrige avant
de passer à l'étape suivante.

0. **Ligne de base (lecture seule, sur le serveur de test).** Hôte et nom de la base (sans mot de passe) ; toutes les
   tables d'`atelierby_db` avec `COUNT(*)` exact ; FK réelles (`information_schema.KEY_COLUMN_USAGE`), vues et leurs
   tables (`VIEW_TABLE_USAGE`), déclencheurs, fonctions, événements ; droits du compte (`SHOW GRANTS` : un `RENAME`
   demande `ALTER`, `DROP`, `CREATE`, `INSERT`) ; présence de tables `panel_*` / `for_*` ; anciens noms en double.
   Résultat gardé dans le dossier privé du serveur et résumé dans ce plan (noms et nombres, jamais de valeurs).
1. **`erp_keys`** : un seul accès aux clés, `shared/src/cles.php` (le helper actuel, déplacé, avec la lecture par
   portail), appelé par les quatre domaines ; plus aucune requête SQL directe sur `erp_cles` ; migration des clés
   validées au §3.2 (copie sans affichage, ancien emplacement vidé ensuite).
2. **Socle `shared/`** : création des dossiers, `git mv` des fichiers partagés, mise à jour des `require`, constante
   unique du dossier `uploads` (le dossier du serveur ne bouge pas), coureur de migrations, `deploy.sh` (alias Apache
   par page, mêmes URL, mêmes exclusions de `rsync`), workflows (chemins). Aucune table renommée.
3. **Formation** ({{N_REN_FOR}} tables, peu de liens avec le reste, alias `/formation/` à garder).
4. **Marketing** ({{N_REN_MAR}} tables + déplacement des modules `mar_`, de la copie `public/api/marketing`).
5. **Dashboard franchisé** ({{N_REN_PANEL}} tables : `aia_`, `pil_` du dashboard, `mot_` selon A6, historique,
   revue du soir…).
6. **Pilotage consultant** ({{N_REN_CON}} tables : le plus gros lot, en dernier quand les autres sont stables).
7. **Préparer la purge sans rien supprimer** : retirer du code partagé les seules références aux tables de la liste
   « à supprimer » validée (sinon le contrôle bloquant de la phase 4 échoue) — ou, si vous préférez que tout retrait
   de code attende la phase 4, déplacer ce retrait juste avant le contrôle (question A4/A5).
8. **Documentation** : `CLAUDE.md` (racine et un par domaine), `README.md`, `docs/PORTAILS.md`, `LISEZMOI.md`,
   `docs/*.md` ; `REORG_REPORT.md` amorcé ; liste définitive « à supprimer » remise à jour avec les nombres de lignes.
   **STOP** : j'attends « PURGE ».

Phase 4 ensuite, comme vous l'avez écrite : vérification hôte/base, dump complet vérifié et restaurable (emplacement
et taille donnés), contrôle bloquant, suppression des seules tables validées et de leurs objets dépendants, code mort
dans un commit séparé, tests et nombres de lignes, puis export.

---

## 10. Risques identifiés

| Risque | Gravité | Parade |
|---|---|---|
| Purge appliquée à une base partagée : perte des données du CRM, de la PWA consultant, du panel TF Buddy, du signage | très haute | Purge limitée aux tables de ce dépôt (A3) ; contrôle à l'étape 0 et au moment de la purge |
| Nouveau code lancé avant le renommage → tables vides créées au nouveau nom, `RENAME` impossible | haute | Migrations en tête de `ensureInstalled()` et en ligne de commande au déploiement ; arrêt sans `DROP` si le nouveau nom existe |
| Fichiers déposés sur le serveur (`public/uploads/*`) « perdus » par le changement de chemins ou effacés par `rsync --delete` | haute | Le dossier du serveur ne bouge pas ; une constante partagée le désigne ; exclusion `rsync` gardée ; vérifié à la livraison de l'étape 2 |
| URL changées : tablettes installées (`/tablette/`), service workers (`visites/`, `controle/`, `employee/`), liens envoyés par e-mail, alias `/formation/` et `/hr/` | haute | Mêmes URL servies depuis les nouveaux dossiers (alias Apache) ; test de chaque adresse après livraison |
| Autres applications lisant des tables `ceo_` en direct (Social, `pwa_consultant`, CRM : leur code n'est pas ici) | moyenne | Ligne de base : vues existantes ; vues de compatibilité si vous le souhaitez (A10) |
| Droits du compte MySQL insuffisants pour `RENAME` | moyenne | `SHOW GRANTS` à l'étape 0 (le compte est celui du panel, a priori complet ; `sql/grants.sql` est facultatif) |
| Noms de tables écrits en clair dans les chaînes SQL, construits dans des boucles (`motivation.php`, `formation_postes.php`), listés en dur dans `bin/deploy.sh` (remise à zéro, inspection) et `sql/grants.sql` | moyenne | Remplacement outillé et revu, recherche finale « aucun ancien nom hors registres », tests |
| Monolithes partagés (endpoints/writes/installer) touchés à chaque étape → conflits avec le travail en cours d'autres sessions sur la branche de déploiement | moyenne | Étapes courtes, synchronisation avec la branche par défaut avant chacune |
| Secrets migrés vers une table en clair lue par plusieurs applications | moyenne | Migrer seulement les clés d'intégration (A11) ; chiffrement à décider à part |
| Deux propriétaires des tables `mar_campaign*` (copie ici, dépôt `marketing`) | basse | Inchangé dans cette mission ; signalé |
| `ceo_verif_*` (5 noms) décrits dans la doc mais jamais créés | basse | Vérifié à l'étape 0 |

---

## 11. Audit : fichiers morts, doublons, tables vides ou orphelines

- **Fichiers sans aucun appelant** (candidats, rien n'est supprimé avant la phase 4) : `bin/db_admin.php` et
  `bin/mix1000.php` — outils en ligne de commande lancés à la main (documentés dans leur en-tête), pas du code mort au
  sens strict.
- **Doublons de code** : `src/crm/meta_graph.php` (copie déclarée du CRM) ; `public/api/marketing/` (copie du module
  Marketing) ; `public/fidelite-test/` et `public/fidelite-app/` (page de test et app en direct de la fidélité).
- **Doublons de définition** : plusieurs tables sont définies deux fois (`sql/schema.sql` et un `ensure…()` PHP, par ex.
  `ceo_scouting_*`, `mar_avis*`, `pla_*`, `ceo_prod_product`) ; les tables `mar_` des référentiels sont définies ici
  (`sql/mar-referentiels.sql`) **et** par la migration 045 du dépôt `marketing`.
- **Doublons de clés** : voir §3.2 (réglages qui priment sur `erp_cles` : `anthropic`, `smtp`, `panelApi`…).
- **Tables obsolètes** : `ceo_parcours`, `ceo_parcours_etape` (effacées au déploiement par le code), `ceo_assistant_question`
  (reprise dans `aia_question`), `ceo_prod_planogram` (reprise dans `pla_placement`), `ceo_x` (test).
- **Tables vides, orphelines en base** : à mesurer à l'étape 0 (aucun accès base ici).

---

## 12. La routine du matin

Demandée en cours de tâche : « en faire une routine tous les matins ». Mise en place à la fin de cette phase :
chaque matin, une session neuve relance cet audit **en lecture seule** sur le dernier état de la branche par défaut
(`python3 -I reorg/audit_statique.py --ecart`) et les tests hors ligne, puis signale ce qui a bougé depuis cet
inventaire : nouvelles tables, nouveaux fichiers (et leur domaine proposé), nouvelles clés ou URL hors `erp_cles`,
collisions de noms, tests passés au rouge. Elle ne touche ni à la base, ni au serveur, ni aux branches, et n'exécute
jamais les phases 3 ou 4 : elles restent soumises à « GO » et « PURGE ».
