# CLAUDE.md — consultant/ (domaine Pilotage consultant · tables `con_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations).

- **Périmètre** : le cockpit du siège pour piloter le réseau — visites et PWA du consultant, suivi des franchisés et
  fil du franchisé, scoring, plans de développement, audits fournisseurs, rapports, KPI, budgets et perfs des
  magasins, vérification (recettes, matières, CA), production et catalogue, assortiment, planogramme, stocks et
  invendus, centrale d'achat, benchmark, contrôles des tâches et leur assistant IA, helpdesk des franchisés.
- **Tables** : `con_*` (dont `con_pla_*` pour le planogramme, `con_hdk_*` pour le helpdesk). Registre historique des
  visites : `consultant/src/con_tables.php`.
- **Code** : `src/` (63 modules), écrans `public/` (`visites/`, `suivi/`, `controle/`, `task/`, `planogramme/`,
  `caisse/`, `audit/`, `audits/` et les scripts du cockpit dans `assets/js/`). La SPA du cockpit et son routeur sont
  dans `shared/`.
- **Crons** : `consultant/bin/rapports_cron.sh`, `consultant/bin/visites_cron.sh`, `consultant/bin/stock_cron.sh` ; `consultant/bin/push_stock.php`.
- **Lit en direct** des tables d'autres applications (ERP TF Buddy, PWA consultant `mac_*`) : écart connu, à passer par
  leurs API (REORG_PLAN.md §8).
- **Tests** : `php consultant/bin/tests_*.php` (suivi, visites, vérification, audits, benchmark…) ; les primes et le
  pointage sont dans `panel/`.
