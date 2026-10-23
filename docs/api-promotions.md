# L'API des promotions du panel — la documentation du cockpit

*Tenue par le cockpit (23/10/2026, demande de Sam : « fais une sonde et crée ta documentation »). Deux parties : ce que dit
la documentation publique du panel (relue le 23/10/2026 à 16:57, `https://atelierby.tfbuddy.com/api/documentation/api.php`,
OpenAPI 3.0, « TFBuddy API » 1.0.0, empreinte `5b57f42594f47bea53aefeb63ecd1bfd`), puis ce que le panel rend vraiment,
que la sonde du cockpit relit en direct.*

## Ce qu'il faut savoir d'abord

- **La documentation publique ne connaît ni seuil de ticket ni destination** (comptoir / webshop) sur aucune sorte de
  promotion. Le seul `minimum_order_value` qu'elle cite est l'exemple d'un *paramètre global*
  (`GET /api/v1/admin/global-params/{key}`, sans doute le minimum de commande du webshop), pas une condition de promotion.
- **Le Swagger officiel est cassé** : `https://atelierby.tfbuddy.com/api/documentation/` charge
  `https://atelier.tfbuddy.com/documentation/api.php`, qui répond une erreur PHP (« Undefined constant API_DIR_NAME »).
  Le fichier lisible est `…/api/documentation/api.php` sur atelierby.
- **Ce que le panel rend vraiment** se lit sur la page de la sonde du cockpit (ci-dessous) : chaque champ relu sur les
  fiches, documenté ou non, et un essai d'écriture qui dit si le panel GARDE un seuil et une destination envoyés.

## La sonde du cockpit (en direct)

| Adresse | Ce qu'elle fait |
|---|---|
| `GET /api/cockpit/ventes/promos/documentation?shop=4` | la page : pour chaque sorte, chaque champ relu (type, fréquence, exemple, documenté ou non, ressemble à un seuil ou un canal), ce que le cockpit y comprend, et les noms appris |
| `…&format=json` | les mêmes données en JSON |
| `…&essai=1` | en plus, l'essai d'écriture : une promotion « X + Y » de test (brouillon `DRAFT`, `VOUCHER_ONLY`, datée du 01/01/2020, nommée « SONDE COCKPIT — à supprimer ») est créée avec les noms de seuil et de destination plausibles, relue, puis supprimée. Chaque nom de seuil porte sa valeur (11,01 €, 11,02 €…) : la relecture dit lequel le panel garde. Quatre créations au plus (tout ensemble ; si refus : seuil seul, canal seul, témoin) |
| `GET /api/cockpit/ventes/promos/sonde-champs?shop=4` | l'ancienne sonde (lecture seule) : les champs nouveaux et ceux qui ressemblent à une condition |

Noms essayés pour le seuil : `minimum_basket_value`, `min_basket_value`, `min_order_amount`, `minimum_order_value`,
`min_ticket_amount`, `min_transaction_amount`, `trigger_amount`, `threshold_amount`, `min_amount`, `minimum_amount`,
`min_spend`, `threshold`. Pour la destination : `destination`, `destinations`, `sales_channels`, `channels`,
`sales_channel`, `channel`, `order_source`, `sale_destination`, `is_webshop`, `webshop_only`, `is_pos`,
`available_on_pos`, `available_on_webshop`.

## Comment le cockpit s'en sert

- **Lecture** (`pvConditions`, `src/promos_ventes.php`) : un seuil sous un nom plausible ; un canal en liste
  (`sales_channels: ["POS"]`), en booléens (`is_pos`, `webshop_only`…) ou en une valeur — la *destination* (`SHOP` =
  comptoir, `WEBSHOP` = webshop, `BOTH` = les deux).
- **Écriture** (`pmConditionsCorps`, `src/promos_magasin.php`) : seulement sous les noms **relus sur une promotion du
  panel** qui en porte (`pmChampsAppris`), avec ses jetons et sa forme (une valeur ou une liste). Aucun nom deviné : tant
  qu'aucune promotion du panel n'en porte, l'assistant grise « Comptoir seulement », « Webshop seulement » et « À partir
  d'un montant », et le serveur refuse une demande qui en porte (422) avant toute écriture.
- **Relecture** (`pmConditionsEcarts`) : après chaque écriture, la fiche relue doit porter le seuil et le canal demandés ;
  sinon la promotion reste inactive au panel (brouillon) et l'écart se dit.
- **Création** : `POST` en `INACTIVE`, relecture, écarts, puis `PATCH {status: ACTIVE}`.

## Ce que dit la documentation publique, sorte par sorte

### X achetés + Y (buy-x-get-y)

Routes : `GET /admin/promotions/buy-x-get-y` · `POST /admin/promotions/buy-x-get-y` · `GET /admin/promotions/buy-x-get-y/{id}` · `PATCH /admin/promotions/buy-x-get-y/{id}` · `DELETE /admin/promotions/buy-x-get-y/{id}`

| Champ (création) | Type | Requis |
|---|---|---|
| `name` | string |  |
| `description` | string, null possible |  |
| `promotion_type` | string (BUY_X_GET_Y) |  |
| `status` | string (DRAFT · ACTIVE · INACTIVE) |  |
| `priority` | integer |  |
| `is_exclusive` | boolean |  |
| `valid_from` | string, null possible, motif `^\d{4}-\d{2}-\d{2}(?: \|T)\d{2}:\d{2}:\d{2}$` |  |
| `valid_to` | string, null possible, motif `^\d{4}-\d{2}-\d{2}(?: \|T)\d{2}:\d{2}:\d{2}$` |  |
| `is_repeatable` | boolean |  |
| `max_applications_per_transaction` | integer, null possible |  |
| `shop_scope_type` | string (ALL_SHOPS · SELECTED_SHOPS) |  |
| `activation_mode` | string (AUTOMATIC · VOUCHER_ONLY), null possible |  |
| `shop_ids` | liste de integer |  |
| `schedule` | liste de objet |  |
| `schedule[].day_of_week` | integer | oui |
| `schedule[].time_from` | string, motif `^([01]\d\|2[0-3]):[0-5]\d(?::[0-5]\d)?$` | oui |
| `schedule[].time_to` | string, motif `^([01]\d\|2[0-3]):[0-5]\d(?::[0-5]\d)?$` | oui |
| `trigger` | objet |  |
| `trigger.quantity` | integer, null possible |  |
| `trigger.product_ids` | liste de integer ou objet |  |
| `trigger.product_ids[].id_product` | integer |  |
| `trigger.product_ids[].product_id` | integer |  |
| `trigger.product_ids[].id` | integer |  |
| `trigger.product_ids[].id_product_portion` | integer, null possible |  |
| `trigger.product_ids[].product_portion_id` | integer, null possible |  |
| `trigger.category_ids` | liste de integer |  |
| `trigger.excluded_product_ids` | liste de integer ou objet |  |
| `reward` | objet |  |
| `reward.quantity` | integer, null possible |  |
| `reward.product_ids` | liste de integer ou objet |  |
| `reward.category_ids` | liste de integer |  |
| `reward.excluded_product_ids` | liste de integer ou objet |  |
| `reward.type` | string (PERCENTAGE_DISCOUNT · FIXED_AMOUNT_DISCOUNT · FIXED_PRICE · FREE), null possible |  |
| `reward.value` | number, null possible |  |
| `reward.selection_strategy` | string (CHEAPEST), null possible |  |
| `voucher_campaign_id` | integer, null possible |  |

### Bundle à prix fixe (bundles)

Routes : `GET /admin/promotions/bundles` · `POST /admin/promotions/bundles` · `GET /admin/promotions/bundles/{id}` · `PATCH /admin/promotions/bundles/{id}` · `DELETE /admin/promotions/bundles/{id}` · `/admin/promotions/bundles/calculate`

| Champ (création) | Type | Requis |
|---|---|---|
| `name` | string | oui |
| `description` | string, null possible |  |
| `promotion_type` | string (BUNDLE_PROMOTION) |  |
| `status` | string (DRAFT · ACTIVE · INACTIVE) |  |
| `priority` | integer |  |
| `shop_scope_type` | string (ALL_SHOPS · SELECTED_SHOPS) |  |
| `activation_mode` | string (AUTOMATIC · VOUCHER_ONLY) |  |
| `calculation_shop_id` | integer | oui |
| `bundle_promotion` | objet | oui |
| `bundle_promotion.bundle_price` | number | oui |
| `items` | liste de objet | oui |
| `items[].id_product` | integer | oui |
| `items[].id_product_portion` | integer, null possible |  |
| `items[].quantity` | number | oui |
| `items[].sort_order` | integer |  |
| `shop_ids` | liste de integer |  |
| `schedule` | liste de objet |  |
| `schedule[].day_of_week` | integer | oui |
| `schedule[].time_from` | string | oui |
| `schedule[].time_to` | string | oui |

### Par quantité (quantity)

Routes : `GET /admin/promotions/quantity` · `POST /admin/promotions/quantity` · `GET /admin/promotions/quantity/{id}` · `PATCH /admin/promotions/quantity/{id}` · `DELETE /admin/promotions/quantity/{id}` · `/admin/promotions/quantity/calculate`

| Champ (création) | Type | Requis |
|---|---|---|
| `name` | string |  |
| `quantity_promotion` | objet |  |
| `eligible_product_ids` | liste de integer |  |
| `eligible_category_ids` | liste de integer |  |
| `shop_ids` | liste de integer |  |
| `calculation_shop_id` | integer, null possible |  |

### Remise programmée (scheduled-product-discount)

Routes : `GET /admin/promotions/scheduled-product-discount` · `POST /admin/promotions/scheduled-product-discount` · `GET /admin/promotions/scheduled-product-discount/{id}` · `PATCH /admin/promotions/scheduled-product-discount/{id}` · `DELETE /admin/promotions/scheduled-product-discount/{id}`

| Champ (création) | Type | Requis |
|---|---|---|
| `name` | string | oui |
| `description` | string, null possible |  |
| `promotion_type` | string (SCHEDULED_PRODUCT_DISCOUNT) |  |
| `status` | string (DRAFT · ACTIVE · INACTIVE) |  |
| `priority` | integer |  |
| `is_exclusive` | boolean |  |
| `valid_from` | string, null possible |  |
| `valid_to` | string, null possible |  |
| `is_repeatable` | boolean |  |
| `max_applications_per_transaction` | integer, null possible |  |
| `shop_scope_type` | string (ALL_SHOPS · SELECTED_SHOPS) |  |
| `activation_mode` | string (AUTOMATIC · VOUCHER_ONLY) |  |
| `shop_ids` | liste de integer |  |
| `reward_product_ids` | liste de integer ou objet |  |
| `reward_category_ids` | liste de integer |  |
| `excluded_product_ids` | liste de integer ou objet |  |
| `discount_type` | string (PERCENTAGE_DISCOUNT · FIXED_AMOUNT_DISCOUNT · FIXED_PRICE), null possible |  |
| `discount_value` | number, null possible |  |
| `schedule` | liste de objet |  |
| `schedule[].day_of_week` | integer | oui |
| `schedule[].time_from` | string | oui |
| `schedule[].time_to` | string | oui |
| `voucher_campaign_id` | integer, null possible |  |

### Campagnes de bons (voucher-campaigns)

Routes : `GET /admin/voucher-campaigns` · `POST /admin/voucher-campaigns` · `GET /admin/voucher-campaigns/{id}` · `PATCH /admin/voucher-campaigns/{id}` · `/admin/voucher-campaigns/{id}/status` · `/admin/voucher-campaigns/{id}/promotion` · `/admin/voucher-campaigns/{id}/available-promotions` · `/admin/voucher-campaigns/{id}/codes` · `/admin/voucher-campaigns/{id}/codes/generate`

| Champ (création) | Type | Requis |
|---|---|---|
| `name` | string | oui |
| `description` | string, null possible |  |
| `planned_promotion_type` | string, null possible |  |
| `status` | string (DRAFT · ACTIVE · INACTIVE) |  |
| `code_type` | string (SHARED · UNIQUE) |  |
| `valid_from` | string, null possible |  |
| `valid_to` | string, null possible |  |
| `usage_limit_total` | integer, null possible |  |
| `usage_limit_per_code` | integer, null possible |  |
| `usage_limit_per_customer` | integer, null possible |  |
| `requires_customer` | boolean |  |
| `shared_code` | string, null possible |  |

## L'historique

Les entrées datées de `docs/contrat-api.md` (« Promotions : … ») racontent chaque changement : portions et `PATCH`
(20–21/10/2026), canal et seuil (23/10/2026), plus de noms devinés, la destination, cette documentation.
