# Mise en ligne — Cockpit CEO

Cible : `http://185.180.206.46/consulant_bo/` — même serveur que le panel
consultant (`/pwa_consultant`), même base MySQL (`atelierby_db`).

L'application fonctionne **en sous-répertoire sans configuration** : tous les
chemins (assets, API) sont relatifs à l'URL de la page.

---

## 1. Variables & secrets nécessaires

### À créer / générer (secrets — jamais dans Git)

| Variable | Exemple / format | Rôle | Qui la fournit |
|---|---|---|---|
| `COCKPIT_DB_HOST` | `127.0.0.1` | hôte MySQL | DBA (même valeur que `config/db.local.php` du panel) |
| `COCKPIT_DB_PORT` | `3306` | port MySQL | DBA |
| `COCKPIT_DB_NAME` | `atelierby_db` | base commune panel + cockpit | DBA |
| `COCKPIT_DB_USER` | `cockpit_app` | compte applicatif dédié (moindre privilège) | DBA — créé par `sql/grants.sql` |
| `COCKPIT_DB_PASSWORD` | 32+ caractères aléatoires | mot de passe du compte | **à générer** : `openssl rand -base64 24` |
| Auth HTTP (htpasswd) | utilisateur + mot de passe | protéger l'accès public (voir §5 — le cockpit n'a pas d'authentification propre) | **à générer** : `htpasswd -c` |
| Accès DBA (ponctuel) | root/admin MySQL | exécuter `schema.sql` + `grants.sql` une fois | DBA — non stocké |

### Non secrètes (configuration)

| Variable | Valeur pour ce serveur | Rôle |
|---|---|---|
| `COCKPIT_PWA_BASE` | `http://185.180.206.46/pwa_consultant` | base d'URL du panel (rapports générés + liens `/r/{token}`) ; prime sur le paramètre `pwaBase` en base |
| `window.COCKPIT_API_BASE` (client, optionnel) | — | surcharge de la base API ; inutile ici, elle est déduite de l'URL |

Il n'y a **aucun autre secret aujourd'hui** : pas de JWT, pas de clé d'API,
pas de SMTP (l'envoi d'email des rapports est simulé ; le jour où il sera
branché, il faudra une clé Resend/SendGrid ou des identifiants SMTP).

Les variables peuvent être posées soit en **environnement** (vhost Apache :
`SetEnv COCKPIT_DB_PASSWORD …`, ou pool PHP-FPM : `env[COCKPIT_DB_PASSWORD]=…`),
soit — plus simple et aligné sur le panel — dans **`config/config.php`** créé
sur le serveur, hors Git (copie de `config/config.example.php`).

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

## 3. Base de données — création des tables

```bash
# 1. Tables (idempotent : CREATE TABLE IF NOT EXISTS ; ne touche pas
#    of_tag / kpi / position / mac_report_share s'ils existent déjà)
mysql -u root -p atelierby_db < sql/schema.sql

# 2. Compte applicatif à moindre privilège (éditez le mot de passe avant)
mysql -u root -p < sql/grants.sql

# 3. Données de démonstration (recommandé pour la v1 en ligne : le réseau
#    belge de démo, tant que les vraies données ne sont pas branchées)
COCKPIT_DB_NAME=atelierby_db COCKPIT_DB_USER=cockpit_app \
COCKPIT_DB_PASSWORD='…' php bin/seed.php
```

Après le seed, deux réglages **de production** à corriger en base :

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

## 5. Protéger l'accès (obligatoire avant la mise en ligne)

Le cockpit n'a **pas d'authentification propre** : en ligne, il expose les
chiffres du réseau et accepte les écritures. À minima, une auth HTTP Basic :

```bash
htpasswd -c /etc/apache2/cockpit.htpasswd ceo        # mot de passe à générer
```

```apache
# dans le vhost ou en tête du .htaccess du cockpit
AuthType Basic
AuthName "Cockpit CEO"
AuthUserFile /etc/apache2/cockpit.htpasswd
Require valid-user
```

(À terme : reprendre l'auth JWT du panel — même philosophie que
`pwa_consultant` — plutôt que Basic.)

---

## 6. Recette (3 minutes)

```bash
BASE=http://185.180.206.46/consulant_bo
curl -su ceo:… $BASE/api/cockpit/meta | head -c 200      # JSON meta (pas d'erreur "base de données")
curl -su ceo:… $BASE/api/cockpit/stores | head -c 200    # les magasins
curl -su ceo:… $BASE/api/cockpit/pwa/reports | head -c 300  # base panel + partages mac_report_share
```

Puis dans le navigateur : `$BASE/` →
- l'écran **Tâches consultants** se charge avec les données (la console NE doit
  PAS afficher « jeu de démonstration chargé » — sinon l'API ne répond pas et
  vous voyez le repli démo embarqué, pas la base) ;
- **Reporting** → « Panel consultant » : « Générer le rapport → » ouvre le
  panel, la carte de droite liste les partages de `mac_report_share` ;
- cocher une tâche puis **Journal** : la ligne est tracée (écriture OK).

---

## 7. Dépannage

| Symptôme | Cause probable |
|---|---|
| Écrans en données de démo (console : « API indisponible ») | `config/config.php` absent/faux, ou l'API renvoie 503 → tester `curl $BASE/api/cockpit/meta` |
| `/api/cockpit/*` → 404 | `mod_rewrite` inactif ou `AllowOverride` ≠ `All` sur le répertoire |
| Partages panel vides | `mac_report_share` inexistante (aucun partage encore créé côté panel) — normal, volet vide |
| Boutique « #n » au lieu du nom dans les partages | `ceo_shop.pwa_shop_id` pas encore mappé aux vrais ids du panel |
