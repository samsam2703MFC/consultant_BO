# CLAUDE.md — shared/ (le partagé du cockpit · L'Atelier By)

Ce qui sert à plusieurs domaines (règle de la réorganisation du 19/11/2026) : lire aussi le `CLAUDE.md` de la racine.

## Ce dossier
- `src/` : la base (`Db.php`), où est le code (`depot.php`), l'accès (`acces.php`, `auth.php`), les clés (`erp_cles.php`,
  le SEUL accès à `erp_cles`), les clients de l'ERP et du panel (`erp_api.php`, `panel_api.php`, `panel_ventes.php`), de
  l'IA (`anthropic.php`), l'envoi (`smtp.php`, `sms.php`, `push.php`), le gabarit des documents (`doc.php`), les
  migrations (`migrations.php`, registre `reorg_tables.php`), et les monolithes qui servent tous les domaines :
  `endpoints.php` (lectures), `writes.php` (écritures), `installer.php` (création des tables, migrations en tête).
- `public/` : la SPA du cockpit (`index.html`, `assets/js/app.js`, `templates.js`), le routeur de l'API
  (`public/api/index.php`, servi à `/consulant_bo/api/cockpit/…` et `/formation/api/cockpit/…`), le design system.
- `sql/schema.sql`, `sql/seed.sql`, `sql/grants.sql` ; `config/` (`config.php` hors Git) ; `db/migrations/`.
- `bin/` : `deploy.sh`, `migrer.php`, `assembler_public.php`, les tests transverses, la console des comptes.

## Stack
- **Backend** : PHP ≥ 8.1, sans framework et sans Composer à la racine. PDO MySQL via `shared/src/Db.php`.
- **Frontend** : HTML, CSS et JavaScript vanilla, sans compilation (sauf `marketing/public/assistant` et
  `panel/public/tablette`, compilés ailleurs).
- **Base** : celle du panel (`atelierby_db`), partagée avec d'autres applications (panel TF Buddy, PWA consultant,
  CRM) : ne jamais toucher à leurs tables. Les tables se créent au premier appel (`CREATE TABLE IF NOT EXISTS`) ; les
  renommages passent par `shared/db/migrations/`, lancées en tête de `ensureInstalled()` et par `deploy.sh`.
- **Secrets** : les clés d'intégration dans `erp_cles` (`shared/src/erp_cles.php`) ; l'accès à la base dans
  l'environnement ou `shared/config/config.php` (hors Git, réécrit par `shared/bin/deploy.sh`). Le dossier privé est
  `/var/lib/consulant_bo/prive` (hors web, hors livraison).
- **Déploiement** : `.github/workflows/deploy.yml`, déclenché par un push de `shared/deploy.trigger` sur la branche de
  déploiement. Il lance `shared/bin/deploy.sh` en root sur le serveur : rsync de la racine du dépôt, `config.php`,
  migrations, site assemblé (`TARGET_DIR/public`), alias Apache, crons dans `/etc/cron.d`.

## Règles
- Aucune URL, clé, scope ou identifiant écrit en dur dans le code : `erp_cles`, environnement ou `config.php`.
- Ne jamais journaliser une clé privée, un jeton, une assertion JWT ou un NISS en clair.
- On écrit en français : code commenté, messages de commit, interface. Les commits sont atomiques.
- Une page autonome appelle son API à côté d'elle, en chemin relatif, et l'alias Apache la sert à la racine.
- Un module qui ne sert qu'un domaine va dans ce domaine, pas ici.
