# Audit : ce que l'API TFBuddy peut remplacer

**Question posée** : parmi les lectures que le cockpit fait lui-même en base,
lesquelles peuvent passer par l'API TFBuddy documentée sur
https://test.tfbuddy.com/docs/ ?

**Sources de l'audit, le 09/11/2026**

- Le swagger : `https://test.tfbuddy.com/docs/openapi.json`, soit 942 chemins
  et 1 255 opérations. **Seules 274 opérations décrivent leur réponse.** Les
  autres portent la mention *« Runtime route registered from a static route
  source. Request, authorization and response contracts are not inferred »* :
  la route existe, mais ni ses paramètres ni ce qu'elle rend ne sont décrits.
- Le code du cockpit, dont la carte des sources (`php bin/carte_sources.php --dire`) :
  242 lectures, dont **93 en base seule**, 71 mixtes (API d'abord, base en
  repli), 23 en API seule et 55 en calcul pur.
- Le code de `pwa_kitchen`.

**Limite** : aucun appel n'a été fait à l'API depuis cet audit (pas d'identifiants
dans la session). Une route marquée « à sonder » existe dans la documentation,
mais il reste à vérifier ce qu'elle rend avec le compte consultant du cockpit.

---

## Verdict en bref

1. **`pwa_kitchen` n'a rien à remplacer.** Il ne lit aucune base : ses 25 appels
   passent tous par l'API, et tous figurent dans le swagger.
2. **Le cockpit lit encore des copies figées.** La table `transaction` s'arrête
   au **14/07/2026** et la synchro du planning s'est tue le **19/07**. Tout écran
   qui les lit montre donc un état vieux de quatre mois. La règle actée le
   03/10/2026 (« plus de copie locale, seulement les endpoints ») n'est
   appliquée qu'en partie.
3. **Remplaçables dès maintenant** : le P&L mensuel, le CA et les tickets par
   jour, le planning, le catalogue restant, les noms des tâches et les magasins.
   Les routes sont documentées, avec leurs paramètres.
4. **Probablement remplaçables, à sonder** : les revues de tâches, les commandes
   et les référentiels du Manuel opératoire.
5. **Sans équivalent dans l'API** : les ventes **par produit et par magasin**,
   les lignes de ticket (paniers) et le journal des pertes ligne à ligne. C'est le
   plus gros bloc qui reste sur la copie : une quinzaine de lectures, dont le
   scoring.
6. **Deux écritures partent dans la copie au lieu de TFBuddy.** Elles sont à
   corriger en premier (§ 2).

---

## 1. Ce qui passe déjà par l'API — vérifié contre le swagger

Le cockpit appelle plus de 80 routes distinctes, et toutes existent dans le
swagger, à cinq alias près. Ces cinq alias sont des replis que
`PanelApi::premierNonVide()` / `premierObjet()` essaie l'un après l'autre :

| Alias absent du swagger | Route documentée | Position de l'alias |
|---|---|---|
| `/product/category-groups` | `/product-category-groups` | **essayé en premier** : un 404 à chaque lecture |
| `/consultant/shops/{id}/statistics/sales/kpis` | `/shops/{id}/statistics/sales/kpis` | **essayé en premier** : un 404 à chaque lecture |
| `/consultant/shops/{id}/category-sales` | `/consultant/shops/category-sales` | second, sans effet |
| `/consultant/products` | `/products` | second, sans effet |
| `/product/availability-periods` | `/product-availability-periods` | second, sans effet |

**Noms de paramètres.** Le swagger les fixe, et le code ne les suit pas
partout :

| Route | Paramètres documentés | Ce que le code envoie |
|---|---|---|
| `/consultant/shops/{id}/pnl/monthly` | `from`, `to` | `date_from`/`date_to` (en `YYYY-MM` puis en dates complètes), puis `year`/`month` — **aucun des trois essais ne correspond** (`PanelApi::pnl()`). C'est une origine possible du « Invalid monthly P&L range » noté dans le code. |
| `/consultant/shops/{id}/pnl/daily` | `from`, `to` | `date_from`/`date_to` dans `pnl()` ; les deux jeux dans `pnlQuotidien()` |
| `/consultant/shops/pnl/monthly` | `shop_ids`, `from`, `to` | pas appelée |
| `/consultant/shops/{id}/pnl` | `period`, `date_from`, `date_to` | conforme |
| `/consultant/shops/sales-kpis`, `/category-sales` | `date_from`, `date_to` | conforme (`shop_id` en plus, non documenté) |

**Trois enveloppes écrites mais jamais appelées** : `PanelApi::pnlQuotidien()`
(`/pnl/daily`), `PanelApi::ciblesMagasin()` et `ecrireCibles()`
(`/consultant/shops/{id}/targets`).

---

## 2. À corriger en premier : deux écritures qui n'atteignent pas TFBuddy

| Écriture | Ce qu'elle fait aujourd'hui | Route TFBuddy |
|---|---|---|
| `wr_prod_actif` (`prod_utilisation.php`) : retirer ou remettre une référence au catalogue | `UPDATE product SET is_active = …` **sur la copie locale**. Le catalogue TFBuddy ne change pas. Et le garde-fou (« vendue sur 12 mois ? ») lit `transaction`, figée au 14/07 : une référence vendue depuis juillet peut donc être retirée. | `PATCH /products/{id}`. Le corps n'est pas documenté, à sonder (`is_active` probable). |
| `wr_pwa_task_validate` (`writes.php`) : validation d'une revue par la direction | `UPDATE mac_task_review SET owner_validated_at…` sur le **miroir local**, et `ALTER TABLE` sur cette table partagée pour y ajouter des colonnes. | `POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate` `{ "validated": true }`, documentée. Elle rend `admin_validated_at`, `admin_validated_by_user_id` et `admin_validated_by_name`. **Reste à trouver le `reviewId` TFBuddy** : `POST …/task-reviews` ne rend que `{ "saved": true }`, l'identifiant est donc à lire dans la progression des checklists (§ 3, famille 4). |

`wr_pwa_task_review` est déjà correct : la note part à l'API d'abord, et le
miroir local n'est qu'une copie de confort.

---

## 3. Lectures en base qui ont une route TFBuddy

Légende : **Remplaçable** = route et paramètres documentés · **À sonder** = la route
existe, mais sa réponse n'est pas décrite.

### Famille 1 : CA et tickets par magasin et par jour (`transaction`, agrégats)

- **Fonctions** : `ep_perf` (tickets, panier), `ep_exploitation`,
  `mesSeriesJour`, `vsCaisseJours`, `vsPremiereVente`, `vcDerniereVente`,
  `ep_products_couverture`, et la partie caisse de `ep_ventes_mensuel`.
- **Routes** :
  - `GET /consultant/shops/{id}/pnl/daily?from&to` : le P&L jour par jour.
  - `GET /consultant/shops/{id}/margin-heatmap?from&to` : CA et tickets par jour,
    31 jours au plus ; déjà lue par `mesure.php`.
  - `GET /consultant/shops/sales-kpis?date_from&date_to` : les totaux par magasin.
  - `GET /shops/{id}/statistics/daily-summary`.
- **Verdict : Remplaçable.**

### Famille 2 : P&L mensuel (`mac_shop_monthly_pnl`)

- **Fonctions** : `ep_perf` (CA, marge, labour, overhead), `ep_ventes_mensuel`,
  `ep_audit_fraicheur`.
- **Routes** :
  - `GET /consultant/shops/pnl/monthly?shop_ids&from&to` : tout le réseau en un
    appel.
  - `GET /consultant/shops/{id}/pnl/monthly?from&to`.
  - `GET /consultant/shops/monthly-sales?from&to`, qui gère l'ETag.
- **Verdict : Remplaçable**, en corrigeant les paramètres (`from`/`to`, § 1).

### Famille 3 : planning et heures (`franchisee_employee_schedule`, `franchisee_employee`)

- **Fonctions** : `primesHeuresMois`, `primesHeuresSemaines`, `exJourCalcul`
  (main-d'œuvre du jour), `venteMois`, `venteEmployes`. Plus `planningSync`,
  qui **réécrit la copie chaque heure** à partir de l'API.
- **Routes** :
  - `GET /shops/{shopId}/schedule`, qui documente désormais `start_date`,
    `end_date`, `month` et `date`. Sa réponse aussi est décrite : `id_schedule`,
    `id_employee`, `start_hour`, `end_hour`, `work_date`, `display_name`…
  - `GET /shops/{id}/employees`.
- **Verdict : Remplaçable, à confirmer.** `planning_sync.php` note que la route
  « rend TOUT l'historique (aucun filtre de date, mesuré) ». Si les filtres
  annoncés répondent, la synchro et sa copie disparaissent.

### Famille 4 : revues de tâches (`mac_task_review`, miroir local)

- **Fonctions** : `ep_pwa_tasks`, `ep_pwa_tasks_nc`, `ep_pwa_task_detail`,
  `ep_pwa_tasks_photos`, `maitriseEtats`, `frRevues`, `ep_lacunes`,
  `ep_commandes_courbe` et les heatmaps de `taches_suivi.php`.
- **Routes** :
  - `GET /consultant/shops/{id}/checklists/progress?from&to` : **nouvelle**, une
    période entière en un appel. Elle rend `{ shop_id, days: [{ date, checklists: [...] }] }`.
  - `GET /consultant/shops/{id}/checklists/{cid}/progress?date`.
  - `GET /consultant/network/tasks?date&shop_ids`.
  - `GET /consultant/tasks/completions/{id}`.
- **Verdict : À sonder.** Le contenu de `checklists` n'est pas décrit. Il faut
  vérifier que `rating`, `is_accepted`, `comment` et l'identifiant de la revue y
  figurent. Si c'est le cas, le miroir n'a plus de raison d'être, et la revue
  posée depuis l'application consultant (hors cockpit) apparaît enfin.

### Famille 5 : catalogue (`product`, `product_category`, groupes, gammes, `recipe_cost`, `shop_product`, `material_category`, `unit`)

- **Fonctions** : la plupart passent déjà par l'API d'abord (`panelCatalogue()`,
  depuis le 03/10). Restent en base seule : `ep_prod_catalogue_reel`,
  `ep_catalogue_produits`, `ep_promo_recherche`, `tbFiches` (tablette),
  `svCategories`, `rpContexte`, `ep_products_fournisseurs`, `aoGammesBrutes`,
  `aoContenu` et `utilGammes`.
- **Routes** :
  - `/products` et `/products/{id}` ;
  - `/shops/{id}/products/available?include=availability_periods` : prix pratiqué
    et gammes ;
  - `/product-categories`, `/product-category-groups`,
    `/product-availability-periods/{id}/products` ;
  - `/material-categories` et `/units` ;
  - `/shops/{id}/recipes/{rid}/cost` et `/product-recipes/calculation`.
- **Verdict : Remplaçable**, en réutilisant `panelCatalogue()`.

### Famille 6 : commandes (`client_order`, `client_order_product`, `material_order`, `material_order_item`, `ws_orders`, `client`)

- **Fonctions** : `ep_commandes_courbe`, `ep_commandes_sonde`,
  `ep_ventes_commandes`, `ep_ventes_clients_sonde`, ainsi que la reprise des
  clients pro du module marketing (`ErpSyncRepository`).
- **Routes** :
  - `/shops/{id}/client-orders` ;
  - `/client-orders/{id}?include=products` : **piste**, voir ci-dessous ;
  - `/shops/{id}/orders` et `/shops/{id}/orders/materials` ;
  - `/shops/{id}/clients?type=b2b` et `/panel/shops/{id}/b2b/clients`.
- **Verdict : À sonder.** Le 03/10, la liste des articles d'une commande était
  vide. Or `pwa_kitchen` appelle `/client-orders/{id}?include=products` :
  ce paramètre n'a pas été essayé par le cockpit.

### Famille 7 : consultants et personnes (`user_membership`, `user_profile`)

- **Fonctions** : `ep_consultants`, `ep_people`, `ep_roles`,
  `consultantAuthId`, `consultantNom`.
- **Routes** : `/panel/consultants` (déjà lue par `mesure.php`),
  `/consultant/responsibility-areas` et
  `/consultant-memberships/{id}/area-descriptions`.
- **Verdict : Partiel.** Les consultants, oui. Les destinataires de rapports
  avec leur e-mail (`ep_people`) n'ont pas de route claire.

### Famille 8 : référentiels du Manuel opératoire (`of_tag`, `kpi`, `position`)

- **Fonctions** : `ep_leviers`, `ep_kpis`, `kpi_table.php`.
- **Routes** : `/operational-framework/tags`, `/positions`, `/position-levels`
  et `/consultant/metric-definitions`.
- **Verdict : À sonder.** `of_tag` ↔ `tags` est probable. `kpi` n'a pas de route
  évidente.

### Famille 9 : noms des tâches (`todo_task`)

- **Fonction** : `todoTaskNames`.
- **Routes** : `/tasks` (avec `name-aliases`) et
  `/operational-framework/checklists/{id}/tasks`.
- **Verdict : Remplaçable.**

### Famille 10 : magasins (`shops`)

- **Fonctions** : environ 100, presque toujours pour `SELECT id, name` et le
  drapeau `active`.
- **Routes** : `/consultant/shops`, `/shops` et `/shops/{id}`.
- **Verdict : Remplaçable**, avec un seul helper mis en cache. Chaque lecture
  pèse peu, mais une ouverture (Knokke) n'apparaît pas tant que la copie ne
  suit pas.

---

## 4. Lectures sans équivalent dans l'API

Elles restent sur la copie, donc figées au 14/07, tant que TFBuddy n'ouvre pas la
donnée.

| Besoin | Tables | Fonctions | État côté API |
|---|---|---|---|
| **Ventes par produit et par magasin** | `transaction_product` | `ep_products` (scoring), `ep_product_waste`, `ep_product_periodes`, `ep_prod_suivi`, `ep_mag_analyse`, `ep_prod_manque`, `ep_prod_utilisation*`, `utilJamaisVendues`, `vcVendues`, `venteMois`, le garde-fou de `wr_prod_actif`, `sales/quantities` du module marketing | Pas de route documentée. Pistes **non documentées** à sonder : `/shops/{id}/reports/sales/daily` et `/monthly` (et `/new`), `/shops/{id}/reports/sales/marge`, `/shops/{id}/statistics/monthly-summary`, `/sales/{date}`. Déjà mesuré : `/shops/{id}/products/{pid}/waste` rendait un `sold_qty` réseau (17/08), et `/statistics/production-planning` ne rend que la journée entière (03/10). |
| **Lignes de ticket (paniers)** | `transaction` ⨝ `transaction_product` | `croisCalcul`, `ep_croisements_detail`, `ep_ventes_cross`, `venteCroisementFF` | Mesuré : `/transactions/{id}` ne porte pas ses produits, `/receipt` est vide. **Non remplaçable.** |
| **Journal des pertes, ligne à ligne** (qui, quand, motif) | `product_movement` | `invSaisies`, `invJournalDerniere`, `invProducteur`, `ep_exploitation_invendus_sonde` | L'API n'a que des agrégats (`/shops/{id}/products/waste`), des PDF et `POST /product-movements` en écriture. **Non remplaçable.** |
| Liens de rapport partagés | `mac_report_share` | `ep_pwa_reports` | Table de `pwa_consultant`, pas de TFBuddy. Sans objet. |

---

## 5. Données propres au cockpit : pas des copies

`ceo_*` (127 tables), `mar_*` (module marketing), `pla_*` (planogramme) et
`brand_guard_*` sont **la source**, et non une copie de TFBuddy. Il n'y a rien à
remplacer, avec une option à trancher :

- **Budget de CA** (`ceo_shop_budget`, `ceo_shop_month_perf`) ↔
  `GET/PUT /consultant/shops/{id}/targets` : des cibles mensuelles par métrique,
  avec trois seuils. S'y ajoutent `/admin/metric-definitions` et
  `/panel/shops/{id}/targets/evaluation`. S'il existe une métrique de CA, le
  budget pourrait vivre chez TFBuddy, où le franchisé le verrait aussi.
  **Décision métier**, pas une simple bascule.
- **Planogramme** : `/product-display-positions` n'est qu'un référentiel de
  positions de présentation (nom, description, alias). Ni zones, ni meubles,
  ni emplacements, ni placements : il ne remplace pas `pla_*`.
- **Marketing** : `/marketing-campaigns` et `/marketing-materials` sont les
  campagnes historiques de l'ERP. `routes.php` prévoit déjà que les deux familles
  cohabitent. Ce n'est pas un remplaçant.

---

## 6. Suivi de `docs/spec-api-panel.md` (demandes du 17/08)

| Demande | Dans le swagger du 09/11 ? |
|---|---|
| § 1. Pièces jointes d'avis (`POST …/attachments`, `attachment_ids`) | **Non.** Le corps de `POST …/task-reviews` reste `checklist_id, task_id, review_date, completion_id, is_accepted, rating, comment`. |
| § 1.6. CORS ou `GET /attachments/{id}/content` | **Non.** Il n'y a que `/attachments/{id}/presigned-url`. |
| § 2. Calque vectoriel | **Non.** |
| § 3. Planogramme (zones, meubles, emplacements, placements, notes) | **Non.** Seulement `/product-display-positions`. |
| § 3.4. Photos produit (`/products/{id}/photos`) | **Non.** Des photos existent sur les recettes, les gammes et les étapes de préparation. |
| § 5.1. Ventes par magasin et par référence | **Non documenté** (voir § 4). |
| § 5.2. Budget de CA par magasin | **En partie** : cibles par métrique (voir § 5). |
| § 5.3. Food cost | **Probable** via `/pnl/daily` (« chiffre, matière, main-d'œuvre, charges et résultat » selon `pnlQuotidien()`). |
| § 0. `/pnl` qui ignore les bornes | `/pnl/daily` et `/pnl/monthly` prennent `from`/`to`. À revérifier avec les bons noms. |
| **Nouveau, non demandé** | La progression des checklists sur une période, `/consultant/network/tasks`, la validation d'une revue, les filtres de date du planning, les cibles. |

---

## 7. Ordre proposé

1. **Les deux écritures** (§ 2). Ce sont les seules qui font un tort réel
   aujourd'hui.
2. **Familles 1 et 2** (CA par jour, P&L mensuel), en corrigeant les paramètres
   `from`/`to`. Les enveloppes existent déjà, et ces écrans sont figés depuis le
   14/07.
3. **Famille 3** (planning) : si les filtres de date répondent, supprimer
   `planning_sync.php` et sa copie.
4. **Famille 4** (revues), après une sonde de `checklists/progress`.
5. **Familles 5, 6, 9 et 10** : le catalogue restant, les commandes, les tâches
   et les magasins.
6. **Réclamer à TFBuddy** les ventes par produit et par magasin, et les lignes de
   ticket. C'est le dernier gros bloc, et le scoring en dépend.

## Comment sonder une route « à sonder »

Le cockpit a déjà ses sondes. Elles passent par le compte consultant configuré :

- `GET /api/cockpit/diagnostic/panel-consultant?shop=4` : un jeu fixe de routes
  consultant.
- `GET /api/cockpit/exploitation/invendus/sonde?shop=4&q=/shops/4/reports/sales/daily?date=2026-11-02,…`
  : des chemins libres, limités aux produits, au stock et aux rapports.
- `ep_pwa_probe()` (`GET /pwa/probe?paths=…`) existe dans `endpoints.php`,
  mais **n'est pas routée** dans `public/api/index.php`. La brancher ouvrirait un
  relais vers n'importe quelle route de l'API (employés, clients compris) : à
  restreindre comme la sonde des invendus avant de l'exposer.
