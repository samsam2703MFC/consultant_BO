# CLAUDE.md — consultant_BO (Cockpit CEO · L'Atelier By)

## Ce dépôt
Le back-office du réseau L'Atelier By : le cockpit du CEO (`public/index.html`, SPA), ses pages autonomes
(`public/dashboard`, `public/formation`, `public/tablette`, `public/hr`…) et leurs API PHP.

Ce n'est **pas** l'ERP Franchise Buddy. L'ERP est un hôte tiers (`https://atelierby.tfbuddy.com/api/v1`), qu'on
lit par son API : `src/panel_api.php` et `src/erp_api.php`, avec des identifiants côté serveur seulement.

## Stack
- **Backend** : PHP ≥ 8.1, sans framework et sans Composer à la racine. PDO MySQL via `src/Db.php`.
  Extensions utilisées : openssl, curl, sodium.
- **Frontend** : HTML, CSS et JavaScript vanilla, sans compilation (sauf `public/tablette`, compilé ailleurs ;
  `public/assistant` n'est plus qu'un renvoi vers le Marketing).
- **Base** : celle du panel (`atelierby_db`). Les tables du cockpit sont en `ceo_*` et `mot_*`, celles du
  module RH en `hr_dimona_*`. Pas d'outil de migration : `CREATE TABLE IF NOT EXISTS` au premier appel.
- **Secrets** : variable d'environnement, puis `config/config.php` (hors Git, réécrit par `bin/deploy.sh`),
  puis réglage en base. Le dossier privé est `/var/lib/consulant_bo/prive` (hors web, hors livraison).
- **Déploiement** : `.github/workflows/deploy.yml`, déclenché par un push de `deploy.trigger` sur la branche de
  déploiement. Il lance `bin/deploy.sh` en root sur le serveur : rsync, `config.php`, alias Apache, crons dans
  `/etc/cron.d`.

## Règles
- Aucune URL, clé, scope ou identifiant écrit en dur dans le code : environnement, `config.php` ou source
  officielle générée.
- Ne jamais journaliser une clé privée, un jeton, une assertion JWT ou un NISS en clair.
- On écrit en français : code commenté, messages de commit, interface. Les commits sont atomiques.
- Une page autonome appelle son API à côté d'elle, en chemin relatif, et l'alias Apache la sert à la racine.

## Mise en ligne : toujours depuis la branche par défaut (19/11/2026)
La branche par défaut (`claude/new-session-deedox`) fait référence ; la branche de déploiement
(`claude/deploiement-application-serveur-lmz1bm`) la suit et pointe sur le même commit. Pour mettre en ligne :
1. le travail, avec sa ligne ajoutée à `deploy.trigger`, entre dans la branche par défaut par une PR fusionnée ;
2. la branche de déploiement avance en avance rapide jusqu'au commit de fusion :
   `git push origin <sha>:refs/heads/claude/deploiement-application-serveur-lmz1bm`. Le changement de
   `deploy.trigger` lance la livraison ; un saut qui ne touche pas `deploy.trigger` ne livre rien.

Jamais de commit, de fusion, de cherry-pick ni de push forcé directement sur la branche de déploiement. Si elle a des
commits absents de la branche par défaut (l'avance rapide est refusée), les ramener d'abord dans la branche par
défaut par une PR, puis avancer.

## Le cockpit franchisé (`/dashboard`)
Le dashboard magasin : l'écran dans `public/dashboard/`, les modules serveur qui ne servent qu'à lui dans `src/franchise/`
(lire `src/franchise/LISEZMOI.md`), le reste partagé avec le siège dans `src/`. L'index de son API :
`public/dashboard/api-index.json`, régénéré par `python3 tools/dashboard-api-index.py` après chaque route ajoutée.

## Module RH · Dimona (`/hr`)
Lire `docs/dimona.md`. En bref :
- **Code** : `src/hr/` (namespace `Hr\`, autoload `src/hr/autoload.php`, assemblage `src/hr/bootstrap.php`).
- **Page** : `public/hr/` (design system extrait du dépôt marketing dans `ds.css`).
- **API** : `public/api/hr/index.php` → `Hr\Http\Api`, routes `/api/v1/erp/dimona/*`.
- **Types ONSS** : générés depuis `docs/dimona/openapi_dimona_v2.yaml` par `tools/hr-dimona/generate.py`. Ne
  pas modifier `src/hr/Dimona/Dto` ni `Generated` à la main.
- **Identité** : la session de la console franchisé (dépôt WebShop, `/bo/{franchisee|franchisor}/me`). Jamais
  le jeton admin réseau.
- **Tests** : `php tests/hr/run.php`. Aucun appel à l'ONSS réel. Faux serveurs dans `tests/hr/mock/`.
- **Cron** : `bin/hr_dimona_cron.sh`, chaque minute, sous www-data (relecture des déclarations, alerte des
  certificats).
- **Environnements** : simulation seule tant que `HR_DIMONA_PROD_ENABLED` ne vaut pas `1`. Un certificat par
  société franchisée.
- Chaque règle métier ONSS cite sa source (ligne du YAML, page du PDF, instruction ONSS). Ce qui n'est pas
  cité n'est pas supposé.
