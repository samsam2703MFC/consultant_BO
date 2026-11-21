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
- **Tests** : `php marketing/bin/tests_metadonnee.php`, `tests_newsletter.php`, `tests_promos*.php`, `tests_bons.php`,
  `tests_canaux.php`, `tests_catalogue.php` ; `php marketing/tests/visibilite/run.php`.
