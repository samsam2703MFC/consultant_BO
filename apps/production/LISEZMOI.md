# ERP production (SLFood)

Copie du dépôt `samsam2703MFC/production` (état de `main` au commit `59bf94b`),
sans son historique Git — l'ancien historique contient un mot de passe de base.

- `app/` — l'application (point d'entrée `app/public/index.php`, API interne `app/public/api.php`)
- `dev/start.sh` — lance l'ERP sans Docker sur `http://slfood.local` (voir `dev/README.md`)
- `compose/`, `docker/` — environnement Docker local

Secrets et identifiants : uniquement dans `app/.env` (modèle : `app/.env.example`), jamais dans Git.
Les logs (`*.log`, `cron_log.txt`) ne sont pas suivis : ils contiennent des données de commandes.

## Copie en ligne : http://185.180.206.46/production

Livrée par le workflow du cockpit (`.github/workflows/deploy.yml`, étape « Déployer l'ERP »)
avec `deploy/server.sh`, sur le même serveur que le cockpit :

- code dans `/var/www/production/app`, seul `app/public` est exposé (Alias Apache `/production`) ;
- base séparée `erp_production`, identifiants dans `/var/www/production/app/.env` (créé au
  premier passage, jamais écrasé) ;
- **données** : déposer le dump (`.zip`, `.sql` ou `.sql.gz`) dans `/root/erp_import/` sur le
  serveur. Il est importé au déploiement suivant, une seule fois (base vide), puis rangé dans
  `/root/erp_import/importe/`. Pour réimporter : `DROP DATABASE erp_production;` puis redéployer ;
- la copie est **coupée de l'extérieur** juste après l'import : compte SMTP désactivé, KSeF en
  `test` sans jeton, accès aux boutiques des franchisés effacés, API des franchisés, S3 et
  Discord vides dans le `.env` ;
- les comptes de la copie reçoivent des mots de passe aléatoires, écrits dans
  `/root/erp_import/MOTS_DE_PASSE_ERP.txt` (lisible par root seulement, jamais dans les logs) ;
- PHP 8.1 suffit : `composer.lock` est figé sur `config.platform.php = 8.1.2`.
