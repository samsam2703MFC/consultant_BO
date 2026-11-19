# CLAUDE.md — marketing/ (domaine Marketing · tables `mar_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations).

- **Périmètre** : campagnes (mesure, objectifs produits, annexes, agences, note de campagne), contrôle des posts
  (Brand Guard), publication sur les réseaux, newsletter clients, avis et réputation (Google), MétaDonnée (leads Meta),
  fidélité, promotions des jours creux, prospection B2B locale, visibilité Google.
- **Tables** : `mar_*` uniquement. Les tables du module Marketing autonome (`mar_campaign`, `mar_lever`, `mar_shop`…)
  appartiennent aussi au dépôt `marketing` (migration 045) : n'en changer ni le nom ni la structure ici.
- **Code** : `src/` (registre historique `marketing_tables.php`), écrans `public/` (`marketing/`, `newsletter/`,
  `prospection/`, `fidelite-app/`, `fidelite-test/`, l'assistant de campagne compilé `assistant/` et la copie de l'API
  du module `public/api/marketing/`), scripts `assistant-src` dans `tools/`.
- **Crons** : `bin/metadonnee_cron.sh`, `bin/newsletter_cron.sh`, `bin/social_cron.sh` (posés par `shared/bin/deploy.sh`).
- **Clés** : Meta, SMSAPI, LinkedIn… par `shared/src/erp_cles.php` (portail `mar` ou `commun`), jamais en SQL direct.
- **Tests** : `php marketing/bin/tests_metadonnee.php`, `tests_newsletter.php`, `tests_promos*.php`, `tests_bons.php`,
  `tests_canaux.php`, `tests_catalogue.php` ; `php marketing/tests/visibilite/run.php`.
