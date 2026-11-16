# ERP sur un serveur local (réseau de l'atelier)

Le serveur local n'est pas joignable depuis Internet : c'est lui qui **va chercher** les mises à jour sur GitHub,
toutes les 5 minutes (sortie HTTPS vers github.com seulement). Il suit la branche de déploiement — la même que le
serveur en ligne : une « Mise en ligne » arrive sur les deux.

## Installation (une fois, en root)

Serveur Debian / Ubuntu avec PHP et MariaDB.

```bash
git clone --depth 1 -b claude/deploiement-application-serveur-lmz1bm <URL du dépôt GitHub> /tmp/erp-install
sudo bash /tmp/erp-install/pilotage-reseau/apps/production/deploy/install_pull_local.sh \
  --repo <URL du dépôt GitHub> \
  --url  http://<adresse IP du serveur local>/production
```

L'installateur :
- installe ce qui manque (git, Apache, composer, rsync, unzip) ;
- écrit `/etc/erp-pull.conf` (root seul) — adresse, branche, options ;
- pose la commande `/usr/local/sbin/erp-pull` et le cron `/etc/cron.d/erp-pull` ;
- lance une première livraison : la même installation que le serveur en ligne (`deploy/server.sh` : code dans
  `/var/www/production`, `.env`, base `erp`, migrations, Apache sous `/production`).

Pour garder l'ERP d'origine en service sur la même base : voir « Cohabitation » plus bas (`--cohabitation`).

## Données

À la première livraison, la base `erp` est vide. Déposer le dump de l'ERP (`.zip`, `.sql` ou `.sql.gz`) dans
`/root/erp_import/`, puis :

```bash
sudo erp-pull --force
```

Comme sur le serveur en ligne, la base importée est **coupée de l'extérieur** (e-mails désactivés, KSeF en test,
accès des boutiques effacés) et les comptes reçoivent de nouveaux mots de passe, listés dans
`/root/erp_import/MOTS_DE_PASSE_ERP.txt` (root seul).

## Au quotidien

| Besoin | Commande |
|---|---|
| Voir ce qui a été livré | `tail -50 /var/log/erp-pull.log` |
| Relivrer tout de suite | `sudo erp-pull --force` |
| Changer l'adresse, la fréquence | modifier `/etc/erp-pull.conf` / `/etc/cron.d/erp-pull` |
| Arrêter les mises à jour | `sudo rm /etc/cron.d/erp-pull` |

- Rien de nouveau sur la branche : rien ne se passe (aucune ligne dans le journal).
- Un commit qui ne touche pas `pilotage-reseau/apps/production` n'est pas réinstallé.
- Une livraison en échec n'est retentée qu'après 60 minutes (`RETRY_MIN`), ou avec `--force`.
- `erp-pull` se met à jour lui-même quand `deploy/pull_local.sh` change dans le dépôt.

## Cohabitation avec l'ERP d'origine (même base)

Pour faire tourner cette version **à côté** de l'ERP d'origine, sur **sa** base (les deux voient les mêmes clients,
commandes, stocks…), installer avec `--cohabitation --db <nom de la base de l'ERP d'origine>` (nom visible dans
phpMyAdmin, colonne de gauche) :

```bash
sudo bash /tmp/erp-install/pilotage-reseau/apps/production/deploy/install_pull_local.sh \
  --repo <URL du dépôt GitHub> \
  --url  http://<adresse IP du serveur local>/production \
  --cohabitation --db <base de l'ERP d'origine>
```

Ce qui change par rapport à une installation normale (`COHABITATION=1` dans le `.env`) :

| Sujet | En cohabitation |
|---|---|
| Données | Aucun import, aucune donnée coupée ni remplacée. Une sauvegarde complète de la base est faite avant chaque lot de migrations (`/root/erp_import/sauvegardes/`, 5 dernières). |
| Tables | Cette version **ajoute** ses tables (`n_*`), des index et des colonnes avec valeur par défaut. Les 14 migrations qui changent la forme, le sens ou les données des tables d'origine sont mises de côté (`app/migrations/cohabitation_skip.txt`, état `skipped` dans `php Bin/tfb_migrate.php status`). |
| Perte des matières | L'ERP d'origine garde `raw_material.waste_perc` (« wykorzystanie », 100 = sans perte). La perte de cette version est dans `n_raw_material_loss` ; une fiche enregistrée ici écrit les deux (perte 35 % → 65 dans `waste_perc`). |
| Mots de passe | Mêmes mots de passe dans les deux ERP : rien n'est chiffré ni rechiffré (`Bin/hash_passwords.php` ne fait rien). |
| Compte MySQL | Compte propre `erp_v2` (droits sur la base de l'ERP d'origine). Le compte de l'ERP d'origine n'est jamais modifié : si `erp_v2` existe déjà, l'installation s'arrête (autre nom : `ERP_COHAB_DB_USER` dans `/etc/erp-pull.conf`). |
| Envois automatiques | Coupés à l'installation (paramètre « system.auto_send » = 0, Ustawienia › Parametry) : relances, rapports, e-mails automatiques, tâches cron. À rallumer dans Ustawienia quand cette version devient la seule à envoyer. Les e-mails restent en `log_only` ; `ERP_MAIL_MODE="live"` dans `/etc/erp-pull.conf` pour un envoi réel. |
| Boutiques, KSeF | Rien n'est repris ni modifié : l'ERP d'origine les garde. |

**Bascule** (quand l'ERP d'origine est arrêté) : mettre `COHABITATION=0` dans `/var/www/production/app/.env`,
puis `cd /var/www/production/app && sudo php Bin/tfb_migrate.php apply` (les 14 migrations mises de côté passent,
après sauvegarde) et rallumer les envois dans Ustawienia › Parametry.

## Serveur au PHP trop ancien : PHP 8.3 en conteneur Docker

L'ERP demande PHP 8.1 ou plus. Sur Ubuntu 20.04 (PHP 7.4), il n'existe plus de paquet PHP 8 (le dépôt ondrej/php a vidé
focal en juin 2025). Si Docker est présent, `install_pull_local.sh` le détecte et écrit `ERP_PHP_DOCKER="1"` dans
`/etc/erp-pull.conf` :

- `deploy/php_docker.sh` (lancé à chaque livraison) construit l'image `erp-production-php` (PHP 8.3 FPM, pdo_mysql,
  mbstring, curl, gd, zip, intl, opcache, pdftotext, composer 2 — `deploy/php-docker/`) et démarre le conteneur
  `erp-production-php` : réseau de l'hôte (MariaDB en 127.0.0.1), php-fpm sur `127.0.0.1:9083`, mêmes chemins que
  l'hôte (`/var/www/production`, `/opt/erp_pull`), redémarrage automatique ;
- Apache garde son PHP pour les autres sites (phpMyAdmin…) et passe seulement les `.php` de `/production` au conteneur
  (`proxy_fcgi`) ;
- `php-erp` et `composer-erp` (`/usr/local/bin`) : `docker exec` dans ce conteneur — composer, migrations, tâches cron
  (lancées par root, exécutées en `www-data`).

Les autres conteneurs du serveur (ancien ERP…) ne sont pas touchés.

| Besoin | Commande |
|---|---|
| État du conteneur | `sudo docker ps --filter name=erp-production-php` |
| Journal PHP | `sudo docker logs --tail 50 erp-production-php` |
| Lancer un script de l'ERP | `cd /var/www/production/app && sudo php-erp Bin/tfb_migrate.php status` |
