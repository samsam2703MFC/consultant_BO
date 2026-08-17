# Audit — quelles API pour ne plus rien lire dans les tables ?

> Objet : recenser **tous** les accès directs à la base faits par le Cockpit CEO,
> isoler ceux qui portent sur des tables appartenant à **une autre application**,
> et spécifier les API qui doivent exister pour les remplacer.
> Périmètre audité : la totalité du dépôt (`src/`, `public/`, `sql/`, `bin/`, `config/`, `docs/`).

---

## 1. Résumé exécutif

Le cockpit lit **29 tables**. 25 lui appartiennent (préfixe `ceo_`), **4 appartiennent
à d'autres applications** et sont attaquées en SQL direct :

| Table | Propriétaire | Accès du cockpit |
|---|---|---|
| `of_tag` | Manuel Opératoire (`franchise_buddy_db`) | 1 lecture + 6 ids en dur dans le code **et stockés en base** |
| `kpi` | Manuel Opératoire | 2 lectures + **1 écriture** |
| `mac_report_share` | Panel consultant (`pwa_consultant`) | 1 lecture |
| `position` | Manuel Opératoire | 0 lecture — couplage déclaré mais dormant |

**Conclusion : 5 API de lecture + 1 API d'écriture suffisent à supprimer tout accès
direct aux tables d'autrui.** C'est le lot 1, chiffré à 3–5 jours côté cockpit
(hors développement des API elles-mêmes, à la charge du Manuel Opératoire et du panel).

Un second niveau, facultatif, concerne les données **détenues ailleurs mais recopiées**
dans les tables `ceo_*` (magasins, réel mensuel, ventes produits, personnes, identité de
l'utilisateur) : aujourd'hui elles viennent du seed de démonstration ou d'une saisie
manuelle. 6 API supplémentaires les alimenteraient — c'est le lot 3.

Le front-end est **hors de cause** : `public/assets/js/` ne fait que du `fetch()` vers
`/api/cockpit/*` (18 endpoints, `api.js:20-38`). Aucune donnée métier dans le HTML.
Le problème est entièrement côté serveur PHP.

---

## 2. Cartographie des accès

### 2.1 Front-end → serveur : conforme

| Couche | Accès | Verdict |
|---|---|---|
| `public/index.html` | aucune donnée métier | conforme |
| `public/assets/js/api.js` | 18 `GET` + `POST/PATCH/PUT` vers `API_BASE` | conforme |
| `public/assets/js/app.js` | consomme la forme normalisée par `api.js` | conforme |
| `public/assets/js/data.js` | jeu de démonstration local, repli hors ligne | voir anomalie **F6** |

### 2.2 Tables propres au cockpit (`ceo_*`) — 25 tables, aucun changement requis

`ceo_shop`, `ceo_shop_month_perf`, `ceo_shop_budget`, `ceo_shop_budget_month`,
`ceo_shop_budget_line`, `ceo_network_target`, `ceo_project`, `ceo_project_levid`,
`ceo_project_milestone`, `ceo_project_cost`, `ceo_project_task`, `ceo_task_issue`,
`ceo_project_crm`, `ceo_project_template`, `ceo_consultant`, `ceo_consultant_visit`,
`ceo_supplier`, `ceo_person`, `ceo_report_schedule`, `ceo_alert_rule`,
`ceo_email_template`, `ceo_journal_entry`, `ceo_app_setting`, `ceo_product`,
`ceo_product_month_sales`.

Ces tables sont la base *propre* du cockpit : les lire en SQL est normal, c'est ce que
fait toute application avec ses propres données. Elles sont déjà exposées par les
19 endpoints de `public/api/index.php`. **Rien à faire ici** (sauf le lot 3, si l'on
décide que certaines de ces données ne doivent plus être détenues par le cockpit).

### 2.3 Tables d'autres applications — les 6 points à supprimer

| # | Table | Où | Endpoint / route | Ce qui est lu ou écrit |
|---|---|---|---|---|
| **C1** | `kpi` | `src/endpoints.php:36` | `GET /meta` | `code`, `seuil_bas`, `seuil_haut` → seuils food / labour / overhead / royalties / financières / CA-ETP |
| **C2** | `kpi` | `src/endpoints.php:82` | `GET /referentiels/kpis` | `name` où `code IS NULL` → liste des KPI des assistants |
| **C3** | `kpi` | `src/writes.php:306` | `PUT /parametres/seuil-{code}` | **`UPDATE kpi SET seuil_haut`** — écriture dans la table d'une autre application |
| **C4** | `of_tag` | `src/endpoints.php:73` | `GET /referentiels/leviers` | `id`, `color` → couleur des 6 leviers de gestion |
| **C5** | `of_tag` (par valeur) | `src/endpoints.php:11-18`, `src/writes.php:44-46`, `src/writes.php:247-252` | toutes | ids `2..7` **codés en dur** et **stockés** dans `ceo_project_levid.levid` et `ceo_shop_budget_line.levid` |
| **C6** | `mac_report_share` | `src/endpoints.php:135` | `GET /pwa/reports` | `token, id_shop, ym, label, consultant_name, created_at, expires_at, revoked_at, opens, last_opened_at` |

Deux couplages annexes :

- **C7** — `position` (Manuel Opératoire) : citée dans `sql/schema.sql:35`,
  `sql/grants.sql:43`, `docs/contrat-api.md` §2, et référencée par
  `ceo_consultant.position_id` / `ceo_shop_budget.validated_by` — **jamais requêtée**.
  Couplage déclaré, dormant.
- **C8** — `information_schema.columns` (`src/installer.php:53`) : lecture des
  métadonnées MySQL pour les migrations idempotentes. Interne à la base du cockpit,
  légitime, sans objet pour cet audit.

---

## 3. Les API nécessaires — lot 1 (obligatoire)

Six contrats. Ils suppriment **100 %** des accès aux tables d'autrui.

### API-1 · Référentiel des leviers `of_tag` — remplace C4 + C5

Fournisseur : **Manuel Opératoire**

```http
GET {mo}/api/referentiels/tags
Authorization: Bearer <jeton service>
```
```json
[
  { "id": 2, "slug": "xp",            "nom": "Expérience Client", "color": "#0ea5e9" },
  { "id": 3, "slug": "recurrence",    "nom": "Récurrence",        "color": "#22c55e" },
  { "id": 4, "slug": "trafic",        "nom": "Trafic",            "color": "#f59e0b" },
  { "id": 5, "slug": "food-cost",     "nom": "Food Cost",         "color": "#ef4444" },
  { "id": 6, "slug": "labour-cost",   "nom": "Labour Cost",       "color": "#8b5cf6" },
  { "id": 7, "slug": "overhead-cost", "nom": "Overhead Cost",     "color": "#8b5cf6" }
]
```

Le champ **`slug` est le point important** : c'est lui qui permet au cockpit d'arrêter de
coder les ids `2..7` en dur (`LEVIER_DEFS`, `endpoints.php:11-18`) et de les stocker dans
ses propres tables. Si le Manuel Opératoire ne peut pas porter le slug, le cockpit garde
la correspondance `slug → id` en local, mais le couplage par valeur subsiste (**F4**).

Consommé par : `GET /referentiels/leviers`. Cache conseillé : 15 min.

### API-2 · Seuils et liste des KPI — remplace C1 + C2

Fournisseur : **Manuel Opératoire**

```http
GET {mo}/api/referentiels/kpis
```
```json
[
  { "id": 12, "code": "food",   "nom": "Food cost",      "unite": "%", "seuilBas": 30, "seuilHaut": 32,    "levierId": 5 },
  { "id": 13, "code": "labour", "nom": "Labour cost",    "unite": "%", "seuilBas": 31, "seuilHaut": 33,    "levierId": 6 },
  { "id": 20, "code": null,     "nom": "Panier moyen",   "unite": "€", "seuilBas": null, "seuilHaut": null, "levierId": null }
]
```

Un seul appel sert les deux usages actuels : les lignes **avec `code`** alimentent
`meta.seuils` (codes attendus : `food`, `labour`, `overhead`, `royalties`,
`financieres`, `ca_etp`), les lignes **sans `code`** alimentent
`GET /referentiels/kpis`. Cache conseillé : 15 min.

### API-3 · Modification d'un seuil — remplace C3

Fournisseur : **Manuel Opératoire**

```http
PATCH {mo}/api/referentiels/kpis/{code}
Content-Type: application/json

{ "seuilHaut": 31.5, "acteur": "Cockpit CEO — G. Baert" }
```
```json
{ "ok": true, "code": "food", "seuilHaut": 31.5 }
```

C'est le point le plus sensible de l'audit : aujourd'hui l'écran Paramètres du cockpit
**écrit dans le référentiel d'une autre application** sans qu'elle le sache
(`writes.php:306`). Cette API rend la modification traçable côté propriétaire, et permet
au Manuel Opératoire de la refuser (droits, verrou d'exercice…). Le cockpit doit gérer un
`403` proprement — champ en lecture seule à l'écran plutôt qu'une erreur 500.

### API-4 · Liens de partage des rapports — remplace C6

Fournisseur : **Panel consultant (`pwa_consultant`)**

```http
GET {panel}/api/report-shares?limit=100
```
```json
{
  "base": "http://185.180.206.46/pwa_consultant",
  "partages": [
    { "token": "…", "shopId": 14, "shopNom": "Bruxelles — Châtelain",
      "ym": "2026-06", "label": "Rapport mensuel — juin 2026",
      "consultant": "Marc Janssens",
      "url": "http://185.180.206.46/pwa_consultant/r/…",
      "cree": "2026-07-03", "expire": "2026-07-17",
      "etat": "Expiré", "opens": 7, "derniereOuverture": "2026-07-15" }
  ]
}
```

Deux améliorations à demander au passage : que le panel calcule lui-même **`etat`**
(Actif / Expiré / Révoqué — aujourd'hui recalculé par le cockpit, `endpoints.php:142`,
avec sa propre horloge) et renvoie l'**`url` complète** (le cockpit n'a plus à connaître
la forme `/r/{token}`). Le champ `html` (MEDIUMBLOB) ne doit **jamais** sortir.
Cache conseillé : 1 min. Pas de cache si l'écran doit afficher les ouvertures en direct.

### API-5 · Boutiques du panel — fiabilise C6 et la correspondance `pwa_shop_id`

Fournisseur : **Panel consultant**

```http
GET {panel}/api/shops
```
```json
[{ "id": 14, "nom": "Bruxelles — Châtelain", "code": "BXL-CHA", "actif": true }]
```

Aujourd'hui `ceo_shop.pwa_shop_id` est saisi à la main (les valeurs du seed sont des ids
de démonstration, cf. README) et la jointure « id panel → nom de magasin » se fait dans
le cockpit (`endpoints.php:145`). Cette API permet de vérifier la correspondance et de
signaler les magasins non appariés au lieu d'afficher « Boutique #17 ».

### API-6 · Postes / consultants — solde C7

Fournisseur : **Manuel Opératoire**

```http
GET {mo}/api/positions?appType=CONSULTANT
```
```json
[{ "id": 7, "nom": "Consultant réseau", "appType": "CONSULTANT" }]
```

Alternative si le rôle des consultants reste géré dans le cockpit : **supprimer** la
colonne `ceo_consultant.position_id`, `ceo_shop_budget.validated_by`, la ligne
`position` de `sql/schema.sql` et de `sql/grants.sql`. Un couplage documenté mais mort
finit toujours par être branché « puisqu'il est déjà là ». Trancher dans un sens ou
dans l'autre.

### Récapitulatif lot 1

| API | Fournisseur | Remplace | Endpoints impactés | Cache |
|---|---|---|---|---|
| API-1 `GET /referentiels/tags` | Manuel Opératoire | C4, C5 | `/referentiels/leviers` | 15 min |
| API-2 `GET /referentiels/kpis` | Manuel Opératoire | C1, C2 | `/meta`, `/referentiels/kpis` | 15 min |
| API-3 `PATCH /referentiels/kpis/{code}` | Manuel Opératoire | C3 | `PUT /parametres/seuil-*` | — |
| API-4 `GET /report-shares` | Panel consultant | C6 | `/pwa/reports` | 1 min |
| API-5 `GET /shops` | Panel consultant | (C6) | `/pwa/reports` | 1 h |
| API-6 `GET /positions` | Manuel Opératoire | C7 | `/consultants` | 1 h |

Après ce lot, le cockpit peut vivre sur **sa propre base MySQL**, avec un compte n'ayant
de droits que sur `ceo_*`.

---

## 4. Lot 3 — API optionnelles : les données détenues ailleurs

Les tables suivantes appartiennent au cockpit *par défaut*, faute de source branchée.
Si l'objectif est « aucune donnée métier qui ne vienne d'une API », ce sont elles qu'il
faut alimenter. Sans ces API, les données sont saisies à la main ou issues du seed.

| Donnée | Table actuelle | Source réelle | API à demander |
|---|---|---|---|
| Référentiel magasins | `ceo_shop` | ERP / panel | `GET {erp}/api/shops` → code, nom, franchisé, zone, statut, ouverture |
| Réel mensuel (CA, tickets, marge, food/labour/overhead) | `ceo_shop_month_perf` | ERP / caisse | `GET {erp}/api/shops/{id}/perf?annees=2025,2026` |
| Ventes par référence | `ceo_product`, `ceo_product_month_sales` | ERP / caisse | `GET {erp}/api/products/sales?periode=2026-07` |
| Consultants, fournisseurs, personnes | `ceo_consultant`, `ceo_supplier`, `ceo_person` | ERP / RH | `GET {erp}/api/people?type=consultant\|fournisseur\|interne` |
| Identité de l'utilisateur connecté | `ceo_app_setting.utilisateur` (valeur statique) | ERP (qui porte l'auth) | `GET {erp}/api/me` — voir **F7** |
| Envoi réel des rapports | simulé (`writes.php:283`) | SMTP / Resend | à brancher |

Note : l'écran « Encodage franchisé » n'est pas implémenté (README) ; tant que l'API de
perf n'existe pas, `ceo_shop_month_perf` n'a **aucune source** en production hors le seed
de démonstration. C'est le trou de données le plus large du projet.

---

## 5. Ce qu'il faut construire côté cockpit

1. **`src/Http.php`** — client HTTP unique (cURL) : `baseUrl` + jeton par fournisseur,
   timeout **court** (1,5 s connect / 3 s total), 1 retry, journalisation des échecs.
2. **Cache + snapshot de repli** — table `ceo_ref_cache (source, payload JSON, fetched_at)`.
   Sans cela, un Manuel Opératoire indisponible rend le cockpit **inutilisable** : les
   leviers et les seuils sont dans `/meta` et `/referentiels/leviers`, appelés à chaque
   chargement d'écran. Règle : servir le cache tant qu'il a moins de 15 min ; au-delà,
   tenter l'appel et, en cas d'échec, resservir le dernier snapshot en marquant
   `"perime": true` pour que l'écran l'affiche.
3. **Configuration** — `config/config.example.php` : `mo.baseUrl`, `mo.token`,
   `panel.baseUrl`, `panel.token`. Un secret de plus à distribuer, ce que le déploiement
   actuel revendique de ne pas avoir (`docs/DEPLOIEMENT.md` §1) : à assumer explicitement.
4. **Modifications de code** — `ep_leviers` (`endpoints.php:70`), `ep_meta`
   (`endpoints.php:33`), `ep_kpis` (`endpoints.php:80`), `ep_pwa_reports`
   (`endpoints.php:124`), `wr_param_put` (`writes.php:301`).
5. **Migration `levid` → `slug`** — `ceo_project_levid.levid` et
   `ceo_shop_budget_line.levid` stockent des ids `of_tag`. Les convertir en slugs
   (`VARCHAR(20)`) supprime la dernière trace d'une clé étrangère d'autrui dans les
   données du cockpit. Migration idempotente à ajouter dans `ensureValidation()`.
6. **Nettoyage de `sql/schema.sql`** — sortir les `CREATE TABLE IF NOT EXISTS of_tag`
   (l.18), `kpi` (l.24), `position` (l.35) et `mac_report_share` (l.316) vers un fichier
   `sql/schema-dev.sql` chargé uniquement en développement (voir **F2**).
7. **Nettoyage de `sql/grants.sql`** — supprimer les quatre `GRANT SELECT` sur les tables
   d'autrui (l.41-44) et le `GRANT UPDATE (seuil_haut)` commenté (l.48).

---

## 6. Anomalies relevées pendant l'audit

| # | Gravité | Constat |
|---|---|---|
| **F1** | Élevée | **Écriture dans la table d'une autre application.** `PUT /parametres/seuil-*` fait `UPDATE kpi SET seuil_haut` (`writes.php:306`). Aucun `GRANT` correspondant n'est accordé (`grants.sql:48`, commenté) : sur un compte MySQL dédié, la route échoue en 503 « base de données indisponible ». Sur le compte partagé, elle réussit — et modifie un référentiel du Manuel Opératoire sans trace de son côté. Corrigé par **API-3**. |
| **F2** | Élevée | **L'installeur crée des tables d'autrui en production.** `ensureInstalled()` (`installer.php:19`) exécute `sql/schema.sql` sur la base partagée ; ce fichier contient `CREATE TABLE IF NOT EXISTS of_tag / kpi / position / mac_report_share`. Si l'une manque au premier appel (panel pas encore initialisé, base neuve), le cockpit crée une **souche vide avec sa propre définition de colonnes** ; l'application propriétaire trouvera ensuite une table du mauvais format que son propre `CREATE TABLE IF NOT EXISTS` ne corrigera pas. |
| **F3** | Moyenne | **Repli silencieux sur `mac_report_share`.** Le `catch (PDOException)` (`endpoints.php:137`) renvoie un volet vide, indistinguable de « aucun partage ». Table absente, droits manquants, panel sur une autre base : trois pannes qui ne se voient pas. Ajouter un champ `indisponible: true` dans la réponse. |
| **F4** | Moyenne | **Ids `of_tag` en dur et persistés.** `LEVIER_DEFS` (`endpoints.php:11-18`) fige `tag => 2..7`, et ces entiers partent en base (`ceo_project_levid`, `ceo_shop_budget_line`). Une renumérotation ou une insertion côté Manuel Opératoire repeint silencieusement les leviers de tout le cockpit. Symptôme déjà visible : Labour Cost et Overhead Cost partagent la même couleur (README). |
| **F5** | Moyenne | **Base et compte MySQL partagés.** `config.example.php` pointe sur `atelierby_db` avec les identifiants du panel, et `DEPLOIEMENT.md` §1 en fait la configuration recommandée ; `grants.sql` (compte à moindre privilège) est explicitement facultatif. Le cockpit peut donc lire *et écrire* toute la base du panel. Le lot 1 est le préalable à un vrai cloisonnement. |
| **F6** | Moyenne | **Mode démo indiscernable.** Si l'API ne répond pas en 4 s, `api.js:89` charge `data.js` et se contente d'un `console.info`. Aucun bandeau à l'écran (`app.js` n'utilise `source` que pour masquer le bouton Déconnexion, l.224). Un CEO peut lire des chiffres inventés en les croyant réels. Un bandeau permanent « Données de démonstration » s'impose. |
| **F7** | Moyenne | **Aucune identité.** `auth` est à `false` par défaut (`config.example.php`) : l'API est ouverte, la redirection depuis l'ERP n'est jamais vérifiée, et `meta.utilisateur` est une valeur statique de `ceo_app_setting`. Le mot de passe intégré (`src/auth.php`) est un palliatif mono-utilisateur : le journal attribue toutes les actions à « CEO ». `GET {erp}/api/me` (lot 3) est la vraie réponse. |
| **F8** | Faible | **Couplage `position` mort.** Déclaré partout, utilisé nulle part (C7). Brancher **API-6** ou supprimer. |
| **F9** | Faible | **`docs/contrat-api.md` désynchronisé.** Annonce « 14 endpoints » là où `api.js` en appelle 18 ; cite une table de référence `ceo_project_famille` qui n'existe pas ; documente `PUT /stores/{id}/perf/{annee}-{mois}` et `POST /stores/{id}/budget/annexe`, absents du routeur (`public/api/index.php`). |
| **F10** | Moyenne (perf) | **N+1 massif sur `/projects`.** `ep_projects` fait 4 requêtes par projet **plus une par tâche** (`tacheSignalement`, `endpoints.php:264`) : 40 projets × 10 tâches ≈ 600 requêtes par chargement d'écran. Même schéma sur `/stores/budgets` (2 par magasin) et `/consultants` (1 par consultant). À corriger **avant** d'introduire des appels HTTP, sous peine de les mettre dans la boucle. |

---

## 7. Plan de migration

| Lot | Contenu | Dépendances | Effort cockpit |
|---|---|---|---|
| **0 — Assainissement** | F2 (sortir les DDL d'autrui), F3 (repli explicite), F6 (bandeau démo), F9 (doc), F10 (N+1) | aucune | 1–2 j |
| **1 — Découplage** | `Http.php` + cache, API-1, API-2, API-3, API-4, API-5, API-6 ; migration `levid` → `slug` ; nettoyage `grants.sql` | API livrées par le Manuel Opératoire et le panel | 3–5 j |
| **2 — Cloisonnement** | Base MySQL dédiée au cockpit, compte n'ayant de droits que sur `ceo_*`, `grants.sql` remis en vigueur | lot 1 terminé | 0,5 j + DBA |
| **3 — Alimentation** | API magasins, perf mensuelle, ventes produits, personnes, `GET /me` (F7) ; écran Encodage franchisé | ERP | à chiffrer avec l'ERP |

Le lot 0 est indépendant et peut démarrer immédiatement. Le lot 1 est **bloqué par les
fournisseurs** : tant que les six contrats ne sont pas livrés, le cockpit ne peut pas se
détacher. Le lot 2 est la seule preuve que le découplage est réel — tant que le compte
MySQL voit encore `of_tag`, rien ne garantit que personne ne le relira.

---

## 8. Annexe — inventaire exhaustif des requêtes

### 8.1 Lectures sur tables d'autrui (à supprimer)

| Fichier:ligne | Requête | Endpoint |
|---|---|---|
| `endpoints.php:36` | `SELECT code, seuil_bas, seuil_haut FROM kpi WHERE code IS NOT NULL` | `GET /meta` |
| `endpoints.php:73` | `SELECT id, color FROM of_tag` | `GET /referentiels/leviers` |
| `endpoints.php:82` | `SELECT name FROM kpi WHERE code IS NULL ORDER BY id` | `GET /referentiels/kpis` |
| `endpoints.php:135` | `SELECT token, id_shop, ym, label, consultant_name, created_at, expires_at, revoked_at, opens, last_opened_at FROM mac_report_share ORDER BY created_at DESC LIMIT 100` | `GET /pwa/reports` |

### 8.2 Écriture sur table d'autrui (à supprimer)

| Fichier:ligne | Requête | Route |
|---|---|---|
| `writes.php:306` | `UPDATE kpi SET seuil_haut = ? WHERE code = ?` | `PUT /parametres/seuil-{code}` |

### 8.3 Lectures sur tables propres (légitimes, conservées)

| Endpoint | Tables lues |
|---|---|
| `GET /meta` | `ceo_app_setting` |
| `GET /referentiels/email-templates` | `ceo_email_template` |
| `GET /referentiels/project-templates` | `ceo_project_template` |
| `GET /stores` | `ceo_shop` |
| `GET /stores/perf` | `ceo_shop_month_perf` |
| `GET /stores/budgets` | `ceo_shop_budget`, `ceo_shop_budget_line`, `ceo_shop_month_perf` |
| `GET /targets` | `ceo_network_target`, `ceo_app_setting` |
| `GET /consultants` | `ceo_consultant`, `ceo_consultant_visit` |
| `GET /fournisseurs` | `ceo_supplier` |
| `GET /projects` | `ceo_project`, `ceo_project_levid`, `ceo_project_milestone`, `ceo_project_cost`, `ceo_project_task`, `ceo_task_issue` |
| `GET /projects/crm` | `ceo_project_crm` |
| `GET /people` | `ceo_person` |
| `GET /reporting` | `ceo_report_schedule`, `ceo_alert_rule` |
| `GET /journal` | `ceo_journal_entry` |
| `GET /products/scoring` | `ceo_product`, `ceo_product_month_sales` |
| `GET /pwa/reports` | `ceo_shop`, `ceo_app_setting` (+ `mac_report_share`, cf. 8.1) |
| `GET /auth/status` | `ceo_app_setting` |

### 8.4 Écritures sur tables propres (légitimes, conservées)

`ceo_project`, `ceo_project_levid`, `ceo_project_milestone`, `ceo_project_cost`,
`ceo_project_task`, `ceo_task_issue`, `ceo_shop_budget`, `ceo_shop_budget_month`,
`ceo_shop_budget_line`, `ceo_shop_month_perf`, `ceo_report_schedule`, `ceo_alert_rule`,
`ceo_email_template`, `ceo_project_template`, `ceo_app_setting`, `ceo_journal_entry`.

### 8.5 DDL exécuté au démarrage

| Fichier:ligne | Effet | Verdict |
|---|---|---|
| `installer.php:22` | exécute `sql/schema.sql` si `ceo_app_setting` absente | crée aussi 4 tables d'autrui → **F2** |
| `installer.php:27` | exécute `sql/seed.sql` si `ceo_shop` vide | données de démonstration en production |
| `installer.php:58,61` | `ALTER TABLE ceo_project_task ADD COLUMN note / validated_by` | propre, idempotent |
| `installer.php:64` | `CREATE TABLE IF NOT EXISTS ceo_task_issue` | propre, idempotent |
