# CLAUDE.md — consultant_BO (Cockpit CEO · L'Atelier By)

## Ce dépôt
Le back-office du réseau L'Atelier By : le cockpit du CEO (`public/index.html`, SPA), ses pages autonomes
(`public/dashboard`, `public/formation`, `public/tablette`, `public/hr`…) et leurs API PHP.

Ce n'est **pas** l'ERP Franchise Buddy. L'ERP est un hôte tiers (`https://atelierby.tfbuddy.com/api/v1`), qu'on
lit par son API : `src/panel_api.php` et `src/erp_api.php`, avec des identifiants côté serveur seulement.

## Stack
- **Backend** : PHP ≥ 8.1, sans framework et sans Composer à la racine. PDO MySQL via `src/Db.php`.
  Extensions utilisées : openssl, curl, sodium.
- **Frontend** : HTML, CSS et JavaScript vanilla, sans compilation (sauf `public/assistant` et
  `public/tablette`, compilés ailleurs).
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

## Fidélité (`#/fidelity`, `src/fidelite.php`) — décisions du 13/11/2026
- **Structure** : une carte de fidélité à points, permanente, dans l'ERP (`points_config`), et **quatre cartes de
  bingo par an** (une par saison de 13 semaines, `src/data/fidelite_bingo.json`). Plus de cartes à tampons.
- **Un mix bingo + points**, selon le handoff de Sam (`docs/maquettes/fidelite-bingo/handoff`, il fait foi) : le
  bingo fait revenir (une case par semaine dès 10 € d'achats, un bon toutes les x cases, le super bon à 13/13) ; les
  points font consommer (×2 jusqu'au dimanche une fois la case cochée, un bon tous les 100 points).
- **Uniquement par l'API de l'ERP** (`/admin/loyalty/*`, `/shops/{id}/loyalty/*`, `/admin/voucher-campaigns/*`) :
  aucune table du cockpit. Ce que l'API ne sait pas faire (le ×2 par client après la case, l'échange automatique au
  palier, les coefficients personnels de la semaine — jour perso, offre perso, produit de la semaine, le plus fort par
  ligne) se dit à l'écran et se demande à l'ERP (`docs/maquettes/fidelite-bingo/DEMANDE-ERP.md`) ; il ne se simule pas.
