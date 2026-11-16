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
