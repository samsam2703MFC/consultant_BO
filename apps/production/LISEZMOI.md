# ERP production (SLFood)

Copie du dépôt `samsam2703MFC/production` (état de `main` au commit `59bf94b`),
sans son historique Git — l'ancien historique contient un mot de passe de base.

- `app/` — l'application (point d'entrée `app/public/index.php`, API interne `app/public/api.php`)
- `dev/start.sh` — lance l'ERP sans Docker sur `http://slfood.local` (voir `dev/README.md`)
- `compose/`, `docker/` — environnement Docker local

Secrets et identifiants : uniquement dans `app/.env` (modèle : `app/.env.example`), jamais dans Git.
Les logs (`*.log`, `cron_log.txt`) ne sont pas suivis : ils contiennent des données de commandes.
