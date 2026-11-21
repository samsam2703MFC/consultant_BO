# CLAUDE.md — marketing/ (domaine Marketing · tables `mar_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations).

- **Périmètre** : campagnes (mesure, objectifs produits, annexes, agences, note de campagne), contrôle des posts
  (Brand Guard), publication sur les réseaux, newsletter clients, avis et réputation (Google), MétaDonnée (leads Meta),
  fidélité, promotions des jours creux, visibilité Google. La prospection B2B locale est passée dans le portail
  Commercial le 19/11/2026 (`commercial/`, page `/commercial/prospection/`, table `sm_prospect`).
- **Pas ici** : l'application Marketing elle-même (campagnes du module, catalogues imprimés, Social, signage) est dans
  le dépôt `marketing`, qui reste séparé (décision du 21/11/2026, `docs/PORTAILS.md`). On lui parle par son API
  d'intégration (`mar_api_url`), comme `panel/src/catalogues_magasin.php` ; on ne lit pas ses tables.
- **Tables** : `mar_*` uniquement. Les tables du module Marketing autonome (`mar_campaign`, `mar_lever`, `mar_shop`…)
  appartiennent aussi au dépôt `marketing` (migration 045) : n'en changer ni le nom ni la structure ici.
- **Code** : `src/` (registre historique `marketing_tables.php`), écrans `public/` (`marketing/`, `newsletter/`,
  `fidelite-app/`, `fidelite-test/`, `assistant/` — un simple renvoi vers l'assistant du Marketing), sondes et essais
  dans `tools/`. Retirées le 21/11/2026 : les sources de l'ancien assistant (`tools/assistant-src`) et la copie de
  l'API du module (`public/api/marketing/`, avec `sql/mar-referentiels.sql` et `POST /admin/marketing-restaure`) —
  elles vivent dans le dépôt `marketing`.
- **Crons** : `marketing/bin/metadonnee_cron.sh`, `marketing/bin/newsletter_cron.sh`, `marketing/bin/social_cron.sh` (posés par `shared/bin/deploy.sh`).
- **Clés** : Meta, SMSAPI, LinkedIn… par `shared/src/erp_cles.php` (portail `mar` ou `commun`), jamais en SQL direct.
- **Fonds et types de campagne** (21/11/2026) : le cockpit n'écrit plus `mar_fund_*` ni `mar_campaign_type` ; ses
  écritures (`shared/src/writes.php`) passent par l'API du module (`marketingAppel`, jeton de service). Ne pas
  revenir à des écritures SQL directes : `marketing/bin/tests_fonds_relais.php` le vérifie. Le grand livre se LIT aussi
  au module (`src/fonds.php`, GET /fonds) : ne pas y remettre de requête sur `mar_fund_*`, `mar_shop`, `mar_lever`, `mar_campaign`.
  Les campagnes, les types et les statuts aussi (`src/campagnes.php` : GET /marketing, budget des campagnes), et la
  mesure des campagnes (`src/mesure.php`, par `campagnesDatees`, `campagneFiche`, `campagneBoutiques`) et la note de
  campagne (`src/mkt_brief.php`, par `campagneFiche`).
- **Tests** : `php marketing/bin/tests_fonds_relais.php`, `tests_campagnes_lecture.php`, `tests_metadonnee.php`, `tests_newsletter.php`, `tests_promos*.php`, `tests_bons.php`,
  `tests_canaux.php`, `tests_catalogue.php` ; `php marketing/tests/visibilite/run.php`.
