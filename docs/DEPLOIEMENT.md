# Mise en ligne — Cockpit CEO

Cible : `http://185.180.206.46/consulant_bo/` — même serveur que le panel
consultant (`/pwa_consultant`), même base MySQL (`atelierby_db`).

L'application fonctionne **en sous-répertoire sans configuration** : tous les
chemins (assets, API) sont relatifs à l'URL de la page.

---

## 1. Variables & secrets nécessaires

**Un seul jeu de secrets : les identifiants MySQL déjà utilisés par le panel**
(mêmes valeurs que son `config/db.local.php`). Ni accès DBA, ni htpasswd :
l'application crée ses tables elle-même au premier appel (comme le panel avec
`mac_report_share`) et embarque sa propre authentification, dont le mot de
passe est **défini dans l'écran de premier lancement** — rien à distribuer.

| Variable | Valeur pour ce serveur | Rôle |
|---|---|---|
| `COCKPIT_DB_HOST` | `127.0.0.1` | identiques au `config/db.local.php` du panel |
| `COCKPIT_DB_PORT` | `3306` | — |
| `COCKPIT_DB_NAME` | `atelierby_db` | base commune panel + cockpit |
| `COCKPIT_DB_USER` | celui du panel | doit avoir le privilège `CREATE` (c'est le cas : le panel crée déjà ses tables) |
| `COCKPIT_DB_PASSWORD` | celui du panel | secret — jamais dans Git |
| `COCKPIT_PWA_BASE` | `http://185.180.206.46/pwa_consultant` | non secret — base d'URL du panel (rapports) ; prime sur le paramètre `pwaBase` en base |

Aucun autre secret : pas de JWT, pas de clé d'API, pas de SMTP (l'envoi
d'email des rapports est simulé ; le jour où il sera branché, il faudra une
clé Resend/SendGrid ou des identifiants SMTP).

Les variables se posent soit en environnement (vhost Apache : `SetEnv …`,
pool PHP-FPM : `env[…]=…`), soit — plus simple, aligné sur le panel — dans
**`config/config.php`** créé sur le serveur, hors Git (copie de
`config/config.example.php`, `chmod 640`).

Option durcissement (plus tard, avec un DBA) : un compte MySQL dédié à
moindre privilège — modèle fourni dans `sql/grants.sql`, facultatif.

---

## 2. Fichiers à déployer

```
/var/www/html/consulant_bo/          ← contenu de public/ (index.html, api/, assets/, .htaccess)
/var/www/consulant_bo_app/           ← src/, config/, sql/, bin/  (HORS webroot)
```

Puis dans `/var/www/html/consulant_bo/api/index.php`, les `require` pointent
sur `../../src/...` : si vous séparez le webroot comme ci-dessus, ajustez les
trois `require` vers `/var/www/consulant_bo_app/src/...` — ou plus simple,
déployez le dépôt entier dans un dossier non servi et faites de `public/` un
lien/alias vers `/consulant_bo` :

```apache
Alias /consulant_bo /var/www/consulant_bo/public
<Directory /var/www/consulant_bo/public>
    AllowOverride All
    Require all granted
</Directory>
```

Prérequis : Apache `mod_rewrite`, PHP ≥ 8.1 avec `pdo_mysql`.

---

## 3. Base de données — création AUTOMATIQUE des tables

**Rien à exécuter à la main.** Au premier appel de l'API, l'application :

1. crée ses tables si elles manquent (`sql/schema.sql`, `CREATE TABLE IF NOT
   EXISTS` — ne touche pas aux tables existantes du panel) ;
2. charge le jeu de démonstration (`sql/seed.sql`, réseau belge) **si la base
   est vide** — recommandé tant que les vraies données ne sont pas branchées ;
3. génère le secret de session (`ceo_app_setting.authSecret`).

Seule exigence : le compte MySQL configuré doit avoir le privilège `CREATE`
sur la base — c'est déjà le cas du compte du panel, qui crée lui-même
`mac_report_share`. (Alternative manuelle, si souhaitée un jour :
`mysql … < sql/schema.sql` puis `php bin/seed.php`.)

Après le premier lancement, deux réglages **de production** à corriger en base :

```sql
-- base d'URL réelle du panel (si non fournie par COCKPIT_PWA_BASE)
UPDATE ceo_app_setting SET value = '"http://185.180.206.46/pwa_consultant"' WHERE `key` = 'pwaBase';

-- mapping magasins cockpit ↔ boutiques du panel : les pwa_shop_id 1..10 du
-- seed sont des ids DE DÉMO. Mettez les vrais ids d'atelierby_db, ex. :
--   UPDATE ceo_shop SET pwa_shop_id = 42 WHERE id = 'cha';
```

> Le seed est **destructif à rejouer** (INSERT sans nettoyage) : pour repartir
> de zéro, DROP les tables `ceo_*` puis rejouez schema + seed.

---

## 4. Configuration applicative

```bash
cp config/config.example.php config/config.php
vi config/config.php     # host / name / user / password + pwaBase
chmod 640 config/config.php && chown www-data:www-data config/config.php
```

`config/config.php` n'est pas suivi par Git (`.gitignore`), comme le
`config/db.local.php` du panel.

---

## 5. Authentification intégrée — premier lancement

Le cockpit embarque sa propre authentification :

- **Première visite** : l'écran « Premier lancement » demande de **définir le
  mot de passe** (8 caractères minimum). Faites-le **immédiatement après la
  mise en ligne** — tant qu'aucun mot de passe n'est défini, le premier
  visiteur venu peut le poser.
- Ensuite : mot de passe demandé à chaque connexion ; session de 30 jours
  (cookie HttpOnly signé, secret généré à l'installation, stocké en base).
  Toutes les routes de l'API sont fermées sans session valide.
- « Quitter » (bas de la barre latérale) ferme la session ; changement de mot
  de passe : `POST /api/cockpit/auth/password` `{ancien, password}`.
- Mot de passe perdu : `DELETE FROM ceo_app_setting WHERE `` `key` `` =
  'authPasswordHash';` → l'écran de premier lancement revient.

HTTPS fortement recommandé dès qu'un nom de domaine existe (le cookie passe
en `Secure` automatiquement derrière HTTPS).

---

## 6. Recette (3 minutes)

```bash
BASE=http://185.180.206.46/consulant_bo
# 1er appel : déclenche l'auto-installation (tables + seed) puis répond
curl -s $BASE/api/cockpit/auth/status          # → {"setup":false,"authed":false} au tout premier appel
curl -s $BASE/api/cockpit/meta                 # → 401 {"error":"auth",...} : l'API est bien fermée
```

Puis dans le navigateur : `$BASE/` →
- écran **« Premier lancement »** → définir le mot de passe → le cockpit
  s'ouvre sur les données (la console NE doit PAS afficher « jeu de
  démonstration chargé » — sinon l'API ne répond pas et vous voyez le repli
  démo embarqué, pas la base) ;
- **Reporting** → « Panel consultant » : « Générer le rapport → » ouvre le
  panel, la carte de droite liste les partages de `mac_report_share` ;
- cocher une tâche puis **Journal** : la ligne est tracée (écriture OK) ;
- navigation privée sans cookie : l'app doit re-demander le mot de passe.

---

## 7. Dépannage

| Symptôme | Cause probable |
|---|---|
| Écrans en données de démo (console : « API indisponible ») | `config/config.php` absent/faux, ou l'API renvoie 503 → tester `curl $BASE/api/cockpit/meta` |
| `/api/cockpit/*` → 404 | `mod_rewrite` inactif ou `AllowOverride` ≠ `All` sur le répertoire |
| Partages panel vides | `mac_report_share` inexistante (aucun partage encore créé côté panel) — normal, volet vide |
| Boutique « #n » au lieu du nom dans les partages | `ceo_shop.pwa_shop_id` pas encore mappé aux vrais ids du panel |
