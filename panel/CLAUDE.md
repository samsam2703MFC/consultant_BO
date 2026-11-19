# CLAUDE.md — panel/ (domaine Dashboard / day panel franchisé · tables `panel_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations), puis `src/LISEZMOI.md`.

- **Périmètre** : ce que voit et fait un magasin au quotidien — le dashboard magasin (`/dashboard`), l'assistant IA et
  le Coach Vente, la boîte mail et les automatisations, la trésorerie et les frais, la revue du soir, la note du jour,
  la tablette des vendeuses (`/tablette`, identité des tablettes installées : ne pas renommer), la production du
  magasin (`/production`), le résultat au téléphone, l'app employés (`/employee`), les primes et les grands prix. Le
  pointage (l'app, `/borne`, la caisse) est retiré le 19/11/2026 ; ses tables `panel_mot_pointage` et `_borne` restent.
- **Tables** : `panel_*` (`panel_aia_*` pour l'assistant, `panel_mot_*` pour l'app employés et les primes,
  `panel_boite_*` pour la boîte mail).
- **Code** : `src/` (les anciens `panel/src/*` et les modules propres au dashboard), `panel/apps/employee/` (l'app
  employés, dépendances commises dans `vendor/`), écrans `public/`. L'index de l'API du dashboard :
  `panel/public/dashboard/api-index.json`, régénéré par `python3 panel/tools/dashboard-api-index.py` après chaque route.
- **Accès** : une session de magasin n'ouvre que les préfixes de `ACCES_FRANCHISE` (`shared/src/acces.php`) et son
  propre magasin. Les autres portails : par leur API (`mar_api_url`, `erp_jeton_service` d'`erp_cles`).
- **Crons** : `panel/bin/boite_cron.sh` (10 min), `panel/bin/historique_moisson.php`.
- **Tests** : `php panel/bin/tests_*.php` (assistant, bundles, boîte mail, trésorerie, primes, profil…).
