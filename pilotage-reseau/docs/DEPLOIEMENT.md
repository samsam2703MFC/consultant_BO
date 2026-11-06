# Mise en ligne — Cockpit CEO

Cible : `http://185.180.206.46/consulant_bo/` — même serveur que le panel
consultant (`/pwa_consultant`), même base MySQL (`atelierby_db`).

L'application fonctionne **en sous-répertoire sans configuration** : tous les
chemins (assets, API) sont relatifs à l'URL de la page.

---

## 0. Les branches — d'où part une livraison

`claude/deploiement-application-serveur-lmz1bm` est **la** branche livrée : le
workflow `deploy.yml` ne se déclenche que sur un push qui touche
`deploy.trigger` **sur elle** (ou par « Run workflow »). `claude/new-session-deedox`
est la branche par défaut, celle où les PR sont fusionnées.

Les deux ont partagé une histoire à partir du 07/09/2026 seulement. Avant, elles
n'avaient **aucun ancêtre commun** — et c'était un piège, parce qu'aucun écran
ne le montrait :

- fusionner une PR ne mettait rien en ligne ; le changement dormait sur la
  branche par défaut, et on le croyait livré ;
- livrer la branche par défaut aurait **retiré du serveur** ce qu'elle ne
  portait pas — la fiche catégorie d'`analyse_produits.php`, le scouting, les
  rapports. Une livraison lancée par erreur depuis cette branche a été annulée
  48 secondes après son départ, le transfert de fichiers déjà passé.

Depuis la réunion des deux lignes, une PR va en ligne ainsi :

```bash
git checkout claude/deploiement-application-serveur-lmz1bm
git merge claude/new-session-deedox          # plus de report à la main
echo "deploy $(date +%s) ce que la livraison apporte" >> deploy.trigger
git commit -am "…" && git push               # le push déclenche la livraison
```

Avant de pousser sur la branche livrée, vérifiez toujours que rien de ce qui
est en ligne ne disparaît :

```bash
git diff --stat claude/deploiement-application-serveur-lmz1bm HEAD
```

Deux mots-clés de `deploy.trigger` commandent la base et ne se posent jamais
par distraction : `reset` réinstalle les tables avec le jeu de démonstration,
`wipe` remet la base à zéro. Sans eux, la livraison ne touche pas aux données.

---

## 1. Variables & secrets nécessaires

**Un seul jeu de secrets : les identifiants MySQL déjà utilisés par le panel**
(mêmes valeurs que son `config/db.local.php`). Ni accès DBA, ni htpasswd, ni
mot de passe applicatif : l'application crée ses tables elle-même au premier
appel (comme le panel avec `mac_report_share`) et l'accès est **ouvert** —
les utilisateurs arrivent **redirigés depuis l'ERP**, qui porte
l'authentification. Rien à distribuer.

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

Un secret facultatif depuis le 06/11/2026 : `TABLETTES_CODE`, le **code de
gestion des tablettes des magasins** (Formation › Manuel du réseau ›
Tablettes) — sans lui, cette gestion est fermée. Où et comment le poser :
§ « Formation › Standards (SOP) » plus bas.

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

L'écran Scouting commercial a quitté le cockpit le 31/10/2026 (CRM,
Développement → Étude de faisabilité, même base), avec la relecture
hebdomadaire de son cache OpenStreetMap : le déploiement **efface**
l'ancien `/etc/cron.d/cockpit-scouting` s'il est encore là (le CRM relit le
cache par son workflow « Scouting — relecture OpenStreetMap »). Le cockpit
garde Leaflet (`assets/vendor/leaflet/`, pour la carte de Prospection), la
création des tables `ceo_scouting_*` (cf. § 3) et le relevé des lieux à
démarcher autour des magasins (`GET /scouting/demarchage`) : le serveur doit
donc toujours atteindre les miroirs Overpass (`overpass.kumi.systems`,
`overpass-api.de`, `overpass.private.coffee`).

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

### Moteur PDF des rapports

Le déploiement installe `wkhtmltopdf` **corrigé** (paquet officiel
`wkhtmltox`, dans `/usr/local/bin`) à côté de celui des dépôts. Le build
Ubuntu tourne sur un Qt non corrigé : il annonce lui-même ignorer
`--print-media-type` et tous les `--footer-*`, si bien que les PDF sortaient
sans pied de page ni pagination. Aucune astuce HTML n'y supplée — un élément
en position fixe n'est peint que sur la dernière page, un `<thead>` que sur la
première (mesuré sur les deux builds).

L'installation est idempotente et non bloquante : si le téléchargement ou
l'installation échoue, le déploiement continue et les PDF sortent sans pied de
page. `src/rapports.php` appelle `/usr/local/bin/wkhtmltopdf` en chemin
complet — le PATH d'Apache ne contient pas toujours `/usr/local/bin`.

### Une livraison ne coupe pas les requêtes en cours

Après la copie, `bin/deploy.sh` **recharge** Apache et PHP-FPM en douceur
(`systemctl reload`) : les requêtes en cours finissent sur l'ancienne
version. Il ne les **redémarre** que s'il a dû tuer des rendus PDF suspendus
(`wkhtmltopdf`, `xvfb-run`, `Xvfb`), qui retiendraient sinon des workers.
Avant le 26/10/2026, chaque livraison redémarrait Apache : qui enregistrait à
cet instant (le carnet d'adresses d'un rapport, ce soir-là, avec une livraison
toutes les vingt minutes) voyait « Failed to fetch ». Côté cockpit
(`assets/js/api.js`), une modification (PUT, DELETE) coupée en route ou
refusée en 502/503/504 se rejoue une fois, deux secondes plus tard ; une
création (POST) ne se rejoue jamais.

## Horloge des rapports (posée par `bin/deploy.sh`)

Un rapport porte sa planification en base — « tous les lundis à 10 h » est un
réglage de l'écran Reporting. Quelqu'un doit néanmoins réveiller le cockpit :
le script de déploiement écrit `/etc/cron.d/cockpit-rapports`, qui appelle
l'horloge **toutes les heures à :05** en loopback. À chaque passage, le
cockpit décide seul quels rapports sont dus, et n'envoie rien deux fois le
même jour.

```bash
cat /etc/cron.d/cockpit-rapports        # root, 0600 — le jeton y figure
# 5 * * * * root curl -fsS … /api/cockpit/rapports/cron?jeton=…
```

Le jeton (`ceo_app_setting.rapportsJeton`) naît au premier chargement ; le
script le relit sur l'API et ne l'imprime jamais dans le journal de livraison.

Le même fichier cron porte l'**horloge des newsletters** : toutes les 5 minutes,
`bin/newsletter_cron.sh` appelle `/api/cockpit/newsletter/cron?jeton=…` (jeton
`ceo_app_setting.nlJeton`, né tout seul). L'horloge fait partir les campagnes
dues par lots de 100 par minute et évalue les automatisations une fois par jour.
Tant que la marque n'a pas **dispatché** (Newsletter → Paramètres → Envois), elle
ne fait rien partir et répond ce qui attend.
Un rapport GÉNÉRÉ à la main dans la journée n'est plus envoyé automatiquement
le même jour — c'est la garde qui évite les doublons.

---

## 5. Accès — redirection depuis l'ERP (auth désactivée par défaut)

L'accès au cockpit est **ouvert** : les utilisateurs y arrivent redirigés
depuis l'ERP, qui porte l'authentification. Il suffit à l'ERP de pointer un
lien/bouton vers `http://185.180.206.46/consulant_bo/`.

À savoir : la redirection ne bloque pas un accès direct par l'URL. Si cela
devient un sujet, l'écran de connexion intégré est déjà là — un booléen à
activer :

```php
// config/config.php
'auth' => true,   // ou variable d'env COCKPIT_AUTH=1
```

→ au prochain chargement, écran « Premier lancement » (mot de passe à
définir, 8 caractères min.), session 30 jours (cookie HttpOnly signé), API
fermée sans session, bouton « Quitter ». Mot de passe perdu :
`DELETE FROM ceo_app_setting WHERE `` `key` `` = 'authPasswordHash';`

HTTPS recommandé dès qu'un nom de domaine existe.

---

## 6. Recette (3 minutes)

```bash
BASE=http://185.180.206.46/consulant_bo
# 1er appel : déclenche l'auto-installation (tables + seed) puis répond
curl -s $BASE/api/cockpit/meta | head -c 200          # → JSON meta (pas d'erreur "base de données")
curl -s $BASE/api/cockpit/stores | head -c 200        # → les magasins
curl -s $BASE/api/cockpit/pwa/reports | head -c 300   # → base panel + partages mac_report_share
```

Puis dans le navigateur : `$BASE/` →
- le cockpit s'ouvre **directement** sur les données (la console NE doit PAS
  afficher « jeu de démonstration chargé » — sinon l'API ne répond pas et
  vous voyez le repli démo embarqué, pas la base) ;
- **Reporting** → « Panel consultant » : « Générer le rapport → » ouvre le
  panel, la carte de droite liste les partages de `mac_report_share` ;
- cocher une tâche puis **Journal** : la ligne est tracée (écriture OK) ;
- depuis l'ERP : le lien de redirection arrive sur la même URL, rien d'autre
  à configurer.

---

## 7. Dépannage

| Symptôme | Cause probable |
|---|---|
| Écrans en données de démo (console : « API indisponible ») | `config/config.php` absent/faux, ou l'API renvoie 503 → tester `curl $BASE/api/cockpit/meta` |
| `/api/cockpit/*` → 404 | `mod_rewrite` inactif ou `AllowOverride` ≠ `All` sur le répertoire |
| Partages panel vides | `mac_report_share` inexistante (aucun partage encore créé côté panel) — normal, volet vide |
| Boutique « #n » au lieu du nom dans les partages | `ceo_shop.pwa_shop_id` pas encore mappé aux vrais ids du panel |
| `/api/cockpit/*` → 503 « Too many connections », livraison bloquée sur `/api/cockpit/meta` | **disque plein** : MySQL n'écrit plus, ses connexions s'empilent → voir « Disque plein » ci-dessous |

### Disque plein (24/10/2026)

Le 24/10/2026, le disque de 25 Go était plein à 100 % : MySQL n'écrivait plus, l'API répondait 503
« Too many connections » et chaque livraison restait bloquée sur `/api/cockpit/meta`. Depuis :

- chaque livraison commence par `bin/menage_disque.sh` (relevé, ménage sûr dès 90 %, relance de MySQL
  s'il refuse les connexions) et **s'arrête avant de copier** s'il reste moins de 300 Mo libres ;
- `/etc/cron.d/cockpit-disque` relance ce ménage toutes les 6 heures dès 85 % et notifie les abonnés
  « admin » du cockpit si le disque reste à 90 % ou plus (journal : `/var/log/cockpit-disque.log`) ;
- `/etc/logrotate.d/cockpit` fait tourner les journaux `/var/log/cockpit-*.log` chaque semaine.

Le 24/10/2026, les binlogs de MySQL pesaient 4 Go (30 jours gardés par défaut) : le ménage règle leur
rétention à 3 jours. MySQL s'administre avec le compte de maintenance d'Ubuntu (`/etc/mysql/debian.cnf`),
root y ayant un mot de passe. Le ménage ne retire que ce qui se recrée : cache apt, journaux systemd au-delà de 200 Mo, journaux tournés
de plus de 3 jours, journaux du cockpit au-delà de 50 Mo (les 5 000 dernières lignes restent), caches pip,
composer, npm, yarn et node-gyp de root, images et cache de construction Docker inutilisés depuis 7 jours,
anciennes révisions des snaps, binlogs MySQL de plus de 3 jours (sans réplica), fichiers de `/tmp` de plus
de 2 jours. Jamais une base, un téléversement, un conteneur ni un volume.

**À la main, en root (PuTTY), quand le serveur est déjà plein :**

```bash
df -h /                                                     # 1. combien il reste
du -xh --max-depth=1 /var/lib /var/www /srv /root 2>/dev/null | sort -rh | head -20
ls -lh /var/lib/mysql | grep -i bin | tail                  #    binlogs MySQL, s'il y en a
apt-get clean                                               # 2. le ménage sûr
journalctl --vacuum-size=100M
find /var/log -xdev -type f \( -name '*.gz' -o -name '*.[0-9]' \) -mtime +7 -delete
rm -rf /root/.cache/pip /root/.cache/composer /root/.npm/_cacache
docker image prune -af --filter until=168h 2>/dev/null     #    seulement si Docker est installé
df -h /                                                     # 3. il faut au moins 1 Go libre
systemctl restart mysql                                     # 4. libère les connexions bloquées
mysql --defaults-file=/etc/mysql/debian.cnf -e "PURGE BINARY LOGS BEFORE NOW() - INTERVAL 3 DAY"   # 5. binlogs
mysql --defaults-file=/etc/mysql/debian.cnf -e "SET PERSIST binlog_expire_logs_seconds = 259200"   #    3 jours
df -h / && curl -s http://127.0.0.1/consulant_bo/api/cockpit/meta | head -c 120; echo
```

Une fois le cockpit livré, la même chose s'écrit `bash /var/www/consulant_bo/bin/menage_disque.sh --force --mysql`.
Si le disque reste plein après le ménage, ce sont des données : le relevé dit où (base MySQL dans
`/var/lib/mysql`, sites dans `/var/www` et `/srv`, sauvegardes dans `/root/backups`) — à trier à la main.
Puis relancer la livraison (GitHub › Actions › « Deploy — Cockpit CEO » › Run workflow).

## Application terrain (visites)

Le même fichier cron porte l'**horloge des visites** : toutes les 5 minutes,
`bin/visites_cron.sh` appelle `/api/cockpit/visites/cron?jeton=…` (jeton
`visitesJeton` lu en base). L'horloge escalade les P0 dont l'échéance est
dépassée, envoie les rappels J-1 (18 h) et jour J (7 h) et la synthèse du
matin (7 h), une fois par jour chacun. Les photos de visite vivent sous
`public/uploads/visites/` (exclu du rsync, créé et donné à www-data par le
déploiement, hors git). Les mails ne partent que si le réglage `mails` est
activé dans Réglages visites ; les notifications push partent toujours vers
les abonnements existants.

## Tablette des vendeuses (Book vendeuses)

Adresse : **`https://185.180.206.46/consulant_bo/tablette/?shop=<id>`** (barre
finale comprise ; `&lang=nl`, `&prices=0`). Le dossier `tablette/` est
l'identité de l'application installée sur les tablettes : le renommer ferait
apparaître une seconde application sur chacune.

**Ce qui est livré.** `public/tablette/` est la compilation du dépôt
`pwa_sales_tablet`, commitée ici comme `public/assistant/` (rien n'est compilé
au déploiement). La mettre à jour :

```bash
cd ../pwa_sales_tablet
npm ci && npm run typecheck && npm run lint && npm test && npm run build
rm -rf ../consultant_bo/public/tablette && cp -r dist ../consultant_bo/public/tablette
git rev-parse HEAD > ../consultant_bo/public/tablette/VERSION
```

Ne rien poser à la main dans `public/tablette/` : la mise à jour remplace le
dossier entier (le `rsync --delete` du déploiement retire aussi les anciens
morceaux de script). Les règles propres à la tablette vivent donc dans
`public/.htaccess` : un fichier absent sous `tablette/` rend **404** (jamais le
shell du cockpit en 200, qui casserait l'application et empoisonnerait son
cache hors ligne), et `.webmanifest` est servi en `application/manifest+json`.

**Données et photos.** `GET /api/cockpit/tablette/book` (gardé six heures en
base, clé `tabletteBook:…`) et `POST /api/cockpit/tablette/photos` — voir
`docs/contrat-api.md`. Les vignettes carrées 640 px (`<ref>-640c.jpg`) vivent sous
`public/uploads/tablette/` : créé et donné à www-data par `bin/deploy.sh`, hors
git, et protégé du `rsync --delete` par l'exclusion de `public/uploads`. Les
photos d'origine restent sous `public/uploads/plano/panel/`, partagées avec le
planogramme.

**http / https.** Le service worker, le mode hors ligne et l'installation sur
l'écran d'accueil n'existent que sur une page sécurisée : en http, la tablette
s'ouvre et lit ses données, mais ne fonctionne pas sans réseau et ne
s'installe pas. Le lien donné aux magasins est donc le lien **https**. Au
02/10/2026, `https://185.180.206.46/` répond par une **connexion
réinitialisée** (*connection reset*), alors que l'étape 5a de `bin/deploy.sh`
suppose un https déjà servi par le certbot du serveur : à rétablir (et son
renouvellement automatique à vérifier) avant d'équiper les tablettes.

**Recette** (ajoutée à `deploy.yml`) : `tablette/` contient « Book vendeuses »,
`tablette/sw.js` et `tablette/manifest.webmanifest` répondent 200,
`tablette/img/__absent__.png` répond 404, `api/cockpit/tablette/book` porte
`"products":[`. Le contrôle de syntaxe `node --check` ignore `public/tablette/`
(bundle Vite, comme `public/assistant/`).

## MétaDonnée — formulaires Meta et Instagram vers l'ERP du franchisé

Rail → ERP franchisé → MétaDonnée (1 · Formulaires, 2 · Mapping ERP, 3 · Suivi des leads, 4 · Alertes SMS).
Contrat : `docs/contrat-api.md`, « MétaDonnée ».

- **Meta** : rien à régler, ni chez Meta ni dans le Cockpit — ce sont les clés et l'accès du CRM
  (atelier-espace-candidat), dont le flux est recopié ici (`src/crm/meta_graph.php` = copie identique de
  `app/crm/api/lib/meta_graph.php`). Le Cockpit les LIT dans la base partagée à chaque appel (`crm_settings`,
  complété par `meta_connexion`) et n'y écrit jamais : un jeton régénéré dans le CRM (Paramètres → Intégrations →
  Formulaires Facebook / Instagram) vaut aussitôt ici. Le compte MySQL du Cockpit doit pouvoir lire ces deux tables
  (`sql/grants.sql`). À l'étape 1, carte « Connexion Meta » (« Clés du CRM ») : « 🔌 Connecter et tester » (jeton
  vérifié — celui de l'utilisateur système échangé contre celui de la Page, gardé côté Cockpit —, Page, formulaires,
  abonnement `leadgen`). L'application étant celle du CRM, son adresse de rappel reste au CRM : le Cockpit ne la
  prend jamais et relève ses formulaires toutes les 2 minutes. Pour un webhook à la seconde, une application Meta
  dédiée au Cockpit (« Clés propres », adresse `…/api/cockpit/metadonnee/meta/leads`, en https sur un nom de
  domaine) : le même bouton la branche. Côté CRM, les formulaires suivis ici restent « non validés » (pas de candidats) ; l'équipe
  reçoit une fois par formulaire l'alerte « formulaire non validé ».
  Le CRM a vu son application bloquée par Meta en oct. 2026 (« API access blocked ») : tant que c'est le cas,
  la sonde le dit et aucun lead ne passe — par ici comme par le CRM.
- **ERP** : le compte API du panel (Paramètres → compte consultant). Les routes `POST/GET /consultant/leads`
  sont à créer côté panel ; elles se règlent à l'étape 2. L'envoi automatique est **éteint** à l'installation.
- **SMS** : SMSAPI, jeton OAuth avec la permission « sms » — `config/config.php` (`smsapi.token`), variable
  `SMSAPI_TOKEN`, ou saisi à l'étape 4. Les SMS sont **éteints** à l'installation ; la Belgique doit être autorisée
  sur le compte SMSAPI (code 112 sinon).
- **Horloge** : `bin/metadonnee_cron.sh`, chaque minute (un passage en cours bloque le suivant), ligne posée par `bin/deploy.sh` dans
  `/etc/cron.d/cockpit-rapports` (jeton `mdJeton` lu en base, né à la première ouverture du module).
- **Vérifier** : « 🔍 Tester les connexions » (lecture seule, sur chaque écran du module) et, à l'étape 2,
  « Envoyer un lead d'essai » (un lead TEST réellement envoyé puis relu : à supprimer dans l'ERP).
  Kit d'essai hors ligne : `tools/metadonnee-essai/LISEZMOI.md`.

## Formation › Standards (SOP)

Une section du module Formation : **`…/consulant_bo/formation/#/sop`** (et à la racine, `/formation/#/sop`), et la
tablette du poste **`…/formation/sop/tablette.html?shop=<id>&poste=cuisson`** (l’onglet **SOP** de l’app Cuisine y
mène avec le magasin et la langue de l’appareil).

**Rien à installer à la main.** Au premier appel de l’API, `ensureSop()` (`src/sop.php`) crée `ceo_sop`,
`ceo_sop_version`, `ceo_sop_suivi`, `ceo_sop_personne` et range les 25 SOP de `data/formation/sops/*.json` (FR et NL).
La reprise n’écrase jamais une SOP déjà en base. `data/formation/` et `public/formation/sop/photos/` partent avec le
reste du dépôt.

**Tablettes des magasins (lot 5, 06/11/2026)** : une seule tablette par magasin, celle-ci. Au premier lancement, elle
demande le **PIN du magasin** (6 chiffres), puis garde la session sur l’appareil. Rien à configurer : au premier appel
de l’API, `ensureTablettes()` (`src/tablettes.php`) crée `ceo_tablette_magasin`, `ceo_tablette_acces`,
`ceo_tablette_essai` et **reprend les magasins du Manuel du CRM** (`crm_mo_magasins`, même base) avec le même PIN
quand il est libre — le bilan (et les éventuels PIN neufs, à communiquer) s’affiche dans Formation › Manuel du réseau
› **Tablettes**, où l’on crée, renomme, relie à une boutique, archive les tablettes et tire un nouveau PIN. L’adresse
de la tablette ne change pas. Ordre de mise en ligne : le Cockpit **avant** le CRM (la page `/crm/manuel/` du CRM
renvoie vers cette tablette et n’ouvre plus de session).

**Code de gestion des tablettes (lot 6, 06/11/2026)** — à poser AVANT de gérer les tablettes. L’API du cockpit
n’exige pas de connexion ici (`auth` => false), or la liste des tablettes porte le PIN de chaque magasin : les routes
de gestion (`/formation/tablettes*`) exigent donc un **code de gestion** (en-tête `X-Code-Gestion`, que l’écran
Tablettes envoie après l’avoir demandé ; gardé dans l’onglet seulement). Tant qu’aucun code n’est posé, la gestion
est **fermée** (403 « code de gestion non configuré ») ; les tablettes des magasins, elles, marchent. Le code est une
chaîne secrète (par exemple 20 caractères tirés au hasard), **jamais dans le dépôt ni dans un journal**. Deux façons
de le poser, au choix — `bin/deploy.sh` l’écrit dans `config/config.php` (`tablettesCode`) à chaque livraison :

1. **Secret GitHub** `TABLETTES_CODE` (Settings → Secrets and variables → Actions → New repository secret) : le
   workflow le passe au serveur par le fichier d’identifiants temporaire (`.deployenv`, effacé après la livraison).
   Il prime sur le fichier ci-dessous.
2. **Fichier privé sur le serveur**, lu par root à chaque livraison (comme la clé du module RH) :
   ```bash
   sudo install -d -m 700 /var/lib/consulant_bo/prive
   sudo sh -c 'umask 077; head -c 15 /dev/urandom | base64 > /var/lib/consulant_bo/prive/tablettes_code'
   sudo cat /var/lib/consulant_bo/prive/tablettes_code     # à transmettre à qui gère les tablettes
   ```

Puis relancer une livraison (ou, sans livraison, ajouter `'tablettesCode' => '…'` à `config/config.php` : la
livraison suivante le réécrit d’après le secret ou le fichier). Le journal de livraison dit seulement « code de
gestion posé (non affiché) » ou « aucun code de gestion — la gestion des tablettes reste fermée », et la recette
vérifie que `GET /formation/tablettes` sans code répond **403**. Changer le code : changer le secret ou le fichier,
puis relivrer. Cinq codes faux depuis un appareil bloquent la gestion 15 minutes ; dix codes faux en une heure (ou
cinquante en un jour), pour tout le réseau, la suspendent une heure.

**Compte consultant du panel** (Paramètres) : il vérifie les PIN des employés (`GET /shops/{id}/employees`), lit les
compétences (`GET /competencies`) et coche au panel une compétence acquise (`PUT /employees/{id}/competencies`). Sans
lui, la tablette lit mais ne compte rien. 5 essais de PIN manqués bloquent la personne 10 minutes.

**PDF** des SOP (FR ou NL, une SOP ou toutes) : le moteur PDF des rapports (§ 4) ; sans moteur, la page s’ouvre prête
à imprimer.

**App Cuisine** (`pwa_kitchen`) : l’adresse de la tablette se règle par la variable `FORMATION_URL` de l’app Cuisine
(par défaut `https://<même serveur>/consulant_bo/formation/sop/tablette.html`).

