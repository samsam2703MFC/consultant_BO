# Environnement de dev

Lance l'ERP en local (session cloud Claude ou machine Linux), sans Docker :
MariaDB, dépendances Composer, `.env` local et serveur PHP sur `http://slfood.local`.

```bash
dev/start.sh chemin/vers/erp.zip   # première fois : importe le dump (.zip, .sql ou .sql.gz)
dev/start.sh                       # les fois suivantes
```

- La base s'appelle `erp` (utilisateur `erp` / `erp_local`, local uniquement).
  Un dump n'est importé que si la base est vide.
- `app/.env` est créé à partir de `app/.env.example` s'il n'existe pas. S3, Discord
  et VIESAPI y restent vides : rien n'est envoyé vers les services de production.
- `dev/router.php` remplace le `.htaccess` d'Apache pour le serveur intégré de PHP.
- Le dump contient des données clients : il ne doit jamais être commité.
