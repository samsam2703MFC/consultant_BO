# Le contrat du panel pour les produits, les recettes et les matières

Vérification du 15/11/2026, sur le swagger de test (`https://test.tfbuddy.com/docs/openapi.json` : 1 259 routes, 633 avec
leur corps de requête). Le swagger de production lu avant n'en donnait aucun (« contracts are not inferred »). Le
swagger de test peut être en avance sur la production : une route qu'il décrit n'existe pas forcément en ligne.

## Les écritures du cockpit, face au contrat

| Route | Corps du contrat (* = requis) | Ce que le cockpit envoyait | Depuis le 15/11 |
|---|---|---|---|
| `PATCH /products/{id}` | champs optionnels : `name`, `id_category`, `sector_id`, `tax_val_perc`, `id_storage`, `id_positioning`, `id_package`, `id_recipe`, `single_weight`, `quantity_per_label`, `shelf_life_minutes`, `storage_temperature`, `reheating_*`, booléens (`is_active`, `is_divisible`, `is_vegetarian`, `is_prepared_before_sales`, `is_pdm`, `webshop_active`, `click_and_collect`, `office_delivery`, `production_planning_enabled`, `is_piece_based`), `nutriscore`, `allergene`, `suggested_sale_price` (2 décimales), `expected_margin` (% net, 0 à <100), `preparation_lead_time_hours`, `min_coefficient` (texte), `label_size` (small, large, none), `shelf_life_category` (SSL, MSL, LSL) | les champs lus, dont trois hors contrat | `is_mandatory_assortment`, `webshop_bulk_quantity_enabled`, `is_personalization_addon` passent en lecture ; valeurs permises du contrat ; `min_coefficient` en texte |
| `PATCH /products/{id}/availability-periods` | `{*availability_periods: [id]}` | `{availability_period_ids}` (deviné) | `{availability_periods}` |
| `PATCH /shops/{s}/products/{p}/price` | `{*portion_price}` | `{price}` (deviné) | `{portion_price}` |
| `POST /products/{id}/competences` | `{*competences: [id]}` | — | non écrit d'ici |
| `PATCH /recipes/{id}`, `PATCH /subrecipes/{id}` | `materials`/`subrecipes` : `[{*id, *quantity, connection_id, waste_amount_perc}]`, `yield_quantity`, `name`, `id_unit`… | les lignes dans la forme lue (nom, unité… en plus) | les lignes dans la forme du contrat |
| `PATCH /product-portions/{id}` | `portion_type` (ONE_EIGHTH, ONE_QUARTER, ONE_HALF…), `is_active`, `display_order`, `id_package` | — | inchangé |
| `PATCH /materials/{id}` | `name`, `id_category`, `id_unit`, `source_type` (CENTRAL, OPEN), `material_type` (RAW, PACKAGING), `waste_amount_perc` (entier), `is_part_of_package` / `can_be_product_addon` (booléens), `allergens` ([id]), `reference_unit_price_net` (texte)… | allergènes dans la forme lue, booléens 0/1, perte et prix en nombre | allergènes en identifiants, booléens, perte entière, prix général en texte |
| `POST /materials/{id}/vat-rates` | `{*country_code, *vat_rate}` (ou une liste) | la ligne lue (avec ses autres clés) | `{country_code, vat_rate}` seulement |
| `POST /materials/{id}/manual-price` | `{*shop_ids: [id], *pack_price, *package_size}` | `{id_shop, price}` (deviné) → 422 `MISSING_REQUIRED_FIELDS` | `{shop_ids, pack_price, package_size: 1}` |
| `PATCH /shops/{s}/materials/avg-price` | aucun corps : recalcule le prix moyen | `{id_material, avg_price}` | préréglage « recalculer », sans données |
| `PATCH /shops/{s}/material-inventory/{m}` | `{current_quantity, minimum_quantity}` | — | non écrit d'ici |
| `POST /shops/{s}/suppliers/{f}/materials/price` | aucun contrat | essayé : 500 (`newPriceForGroup` absente du code) | retiré du formulaire |

## Les prix d'un fournisseur intégré (SLFood)

Les routes de tarif fournisseur (`POST /material-suppliers/{f}/shops/{s}/products/{p}/price`, `…/price/all-shops`,
`DELETE …/price-lists/scheduled/{date}`) portent la règle `integrated-supplier-manual-write-block` : *seul un jeton du
fournisseur, dans son périmètre, peut modifier le tarif d'un fournisseur intégré*. C'est le 403
`SUPPLIER_MANAGED_PRICE` mesuré le 14/11, avec le compte consultant comme avec le compte admin. Le prix d'une matière
SLFood ne se corrige donc pas depuis le cockpit : SLFood republie son tarif, ou TF Buddy le fait.

## Réceptionner une livraison

`POST /orders/{id}/delivery` attend `{*id_employee, *delivered_on (date), *materials: [{*id_material, *supplier_sku,
*ordered_pack_qty, *delivered_pack_qty, *pack_diff_qty, *pack_size_in_base_unit, *id_unit, *pack_price}],
confirm_exclude_unmapped_products}` : chaque ligne livrée, avec sa quantité commandée et livrée. Le 14/11, la route a
répondu `ORDER_NOT_FOUND` pour la commande SLFood n° 317 (par son numéro comme par sa clé) : les commandes intégrées
passent par le circuit du fournisseur (`/material-suppliers/{f}/orders/{id}/accept`, `/final-items`, `/finalize`). Le
swagger de test décrit aussi `GET /consultant/shops/{s}/material-orders` (et `/{id}`) et `POST
/shops/{s}/kitchen-deliveries/{order}/receive` : à mesurer en production.
