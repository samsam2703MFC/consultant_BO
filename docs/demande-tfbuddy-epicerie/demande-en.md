# Request to TFBuddy — API write endpoints to encode grocery (resale) items (v2)

To: development team of `atelierby.tfbuddy.com` · From: L’Atelier by (CEO cockpit) · 27/10/2026, version 2 (decisions included)  
Base URL: `https://atelierby.tfbuddy.com/api/v1` · Versions: [français](demande-fr.md), English, [polski](demande-pl.md)

## The need

The network resells **grocery items** bought and sold as-is (coffee, jams, chocolates…). The cockpit process: we scan the supplier’s PDF (catalogue, price list), a wizard reads each line, we encode and validate it step by step — price, barcode, VAT, sales periods, name, ingredients and allergens in French and Dutch — and then everything must be created in TFBuddy **as one chain**:

```
supplier ──► supplier catalogue item (SKU, EAN, pack, price) ──► material (allergens) ──► recipe (1 × the material) ──► sales product (category, max. price, periods, FR/NL)
```

Observed in the Swagger on 27/10/2026: `material-categories`, `material-suppliers`, `materials`, `product-categories`, `product-availability-periods` and `products` are **read-only** (GET). None of the links in the chain can be created. The write endpoints below are the minimum the wizard needs to create the chain without re-typing anything. Field names are the ones your GET endpoints already return; **fields in bold are new** — if you name them differently, tell us and the cockpit will follow.

## Decisions by L’Atelier by

1. **Price**: one supplier price list, written once for **the whole network** (no per-shop list for grocery items). The sales price is a **maximum price, the same for every shop** (`max_sale_price`, VAT included): a shop may sell below it, never above.
2. **Barcode**: the EAN is on the **product** (`ean`, scanned at the till) and on the supplier’s item. It is entered and checked (EAN-13 / EAN-8 check digit) in the wizard.
3. **Sales VAT**: on the **product** (`tax_value_percent`), the same in every shop.
4. **Activation**: through **availability periods** (`availability_period_ids`, ids from `GET /product-availability-periods`). The product is sold during its periods — “Permanent” for all year round. Endpoint 8 changes them afterwards.
5. **Bilingual**: name (`name` / `name_nl`) and ingredients (`ingredients_fr` / `ingredients_nl`) in French and Dutch, on the product.
6. **Allergens**: ticked one by one in the wizard, sent as `GET /allergens` codes on the **material** — `allergens` (contains) and `may_contain` (possible traces). The product inherits them through its recipe, like your other products.

## Common rules

- **Admin** account (realm `/admin/auth/login`); a consultant account can remain read-only.
- `201` with the full object (including its `id`); `422` naming the offending field, in a single envelope: `{ "error": { "code": "…", "message": "…", "field": "…" } }`; `409` on a duplicate (same `sku` at the same supplier, same `ean`, same `name` in the same category) **returning the existing id**, so the wizard can reuse it instead of failing.
- `client_ref` on every POST (free string, 64 characters max): a second POST with the same `client_ref` creates nothing and returns the object already created (`200`). This makes retries safe after a network failure.
- `OPTIONS` on each route must list its methods in the `Allow` header: this is what the cockpit’s “Check API” button reads, without writing anything.
- Partial `PATCH`: only the fields sent are changed.

## Endpoints

| # | Endpoint | Creates / updates | Fields (bold: new) |
|---|---|---|---|
| 1 | `POST /material-suppliers` · `PATCH /material-suppliers/{id}` | the supplier | `name`, `type`, `email`, `phone`, `address`, `city`, `country_code`, `tax_number`, `website_url`, `currency`, `notes` |
| 2 | `POST /material-suppliers/{id}/catalog/products` · `PATCH …/catalog/products/{productId}` | the supplier’s catalogue item | `sku`, `name`, `ean`, `package_size`, `package_unit`, `portion_size`, `portion_unit`, `weight_grams`, `shelf_life_days`, `vat_rate`, `is_active` |
| 3 | `POST /material-suppliers/{id}/price-lists` | the pack price, dated | `sku` (or `catalog_product_id`), `price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from` — valid for **all shops** |
| 4 | `POST /materials` · `PATCH /materials/{id}` | the material and its allergens | `name`, `code`, `material_category_id`, `unit_name` (base unit : `pcs`, `g`, `kg`, `l`), `vat_rate`, `material_type`, `waste_amount_perc`, **`allergens`**, **`may_contain`** (`GET /allergens` codes) |
| 5 | `POST /material-suppliers/{id}/catalog-mappings` | the item ↔ material link | `material_id`, `catalog_product_id`, `units_per_pack` |
| 6 | `POST /products` · `PATCH /products/{id}` | the sales product | `name`, **`name_nl`**, `id_category`, **`max_sale_price`**, `tax_value_percent`, **`ean`**, `single_weight`, `is_divisible`, `portion_size`, **`ingredients_fr`**, **`ingredients_nl`**, `shelf_life_minutes`, **`availability_period_ids`** |
| 7 | `PUT /products/{id}/recipe` | the product’s recipe | `yield_quantity`, `elements: [{ material_id, required_quantity, unit_name }]` |
| 8 | `POST /product-availability-periods/{id}/products` · `DELETE …/products/{productId}` | a product’s sales periods | `product_id` |

**Recommended shortcut — one atomic endpoint** (the one the wizard will call, line by line):

```
POST /products/resale
{
  "client_ref": "ep14-l7",
  "supplier":  { "id": 6 },                                   // or { "name": …, "tax_number": … } to create it
  "catalog_product": { "sku": "CS-CHOC-72", "name": "Chocolat noir 72 % 100 g", "ean": "5412345000075",
                       "package_size": 20, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 34.00, "valid_from": "2026-11-01" },                   // network price list: all shops
  "material": { "id": null, "name": "Chocolat noir 72 % 100 g", "material_category_id": 11,
                "unit_name": "pcs", "vat_rate": 6,
                "allergens": ["milk", "soybeans"], "may_contain": ["nuts"] },   // id given = existing material (allergens ignored)
  "units_per_pack": 20,
  "product": { "name": "Chocolat noir 72 % - 100 g", "name_nl": "Pure chocolade 72 % - 100 g",
               "id_category": 61, "max_sale_price": 3.20, "tax_value_percent": 6,
               "ean": "5412345000075", "single_weight": 100,
               "ingredients_fr": "Pâte de cacao, sucre, beurre de cacao, émulsifiant : lécithine de soja.",
               "ingredients_nl": "Cacaomassa, suiker, cacaoboter, emulgator: sojalecithine.",
               "availability_period_ids": [1] },                                 // 1 = “Permanent”
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

All or nothing: no orphan material is left behind if the product is rejected. Endpoints 1 to 8 remain useful for later corrections (price change, sales period, discontinued item).

## What we need back

1. The names you choose for the new fields, if they differ (`max_sale_price`, `name_nl`, `ingredients_fr`, `ingredients_nl`, product `ean`, material `allergens` / `may_contain`, `availability_period_ids`).
2. Till behaviour above `max_sale_price`: block, or warning?
3. A date for `POST /products/resale` (even on a test environment): the cockpit is ready and will try it right away.
