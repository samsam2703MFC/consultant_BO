# TFBuddy Swagger change request — write endpoints to encode grocery (resale) items (v3)

To: development team of `atelierby.tfbuddy.com` · From: L’Atelier by (CEO cockpit) · 27/10/2026, version 3  
Base URL: `https://atelierby.tfbuddy.com/api/v1` · Versions: [français](demande-fr.md), English, [polski](demande-pl.md)

## The file to integrate

**`openapi-ecritures-epicerie.yaml`** (in this folder) describes everything requested, as **OpenAPI 3.0.3, validated**: 14 operations, filed under **your** sections and **your** controllers, with their schemas, examples and error responses. It opens as is in <https://editor.swagger.io> and merges into your `/swagger/openapi.json`. New fields carry `x-nouveau: true`. Preview: `apercu-swagger-1-sections.png` and `apercu-swagger-2-products-resale.png`.

## The need

The network resells **grocery items** bought as-is. The cockpit scans the supplier’s PDF; a wizard encodes and validates each line — price, barcode, VAT, sales periods, name, ingredients and allergens in French and Dutch — then creates it in TFBuddy **as one chain**:

```
supplier ──► supplier catalogue item (SKU, EAN, pack, price) ──► material (allergens) ──► recipe (1 × the material) ──► sales product (category, max. price, periods, FR/NL)
```

**Today’s Swagger (observed on 27/10/2026) only reads** these resources: `GET /material-categories`, `GET /material-suppliers`, `GET /materials`, `GET /product-categories`, `GET /product-category-groups`, `GET /product-availability-periods`, `GET /products`, `GET /shops/{id}/products/available`. The only existing product write, `POST /product-recipe/{id}/photo`, needs a product that already exists.

## What to add, section by section of your Swagger

### Products — ProductController

| Method | Path | Purpose |
|---|---|---|
| POST | `/products/resale` ★ | **Priority** — creates the whole chain in one atomic call (all or nothing). This is the endpoint the wizard calls, line by line. |
| POST | `/products` | Creates a sales product. |
| PATCH | `/products/{id}` | Updates a product (partial). |

### Material suppliers — MaterialController supplier

| Method | Path | Purpose |
|---|---|---|
| POST | `/material-suppliers` | Creates a supplier. |
| PATCH | `/material-suppliers/{id}` | Updates a supplier (partial). |
| POST | `/material-suppliers/{id}/catalog/products` | Adds an item to the supplier’s catalogue. |
| PATCH | `/material-suppliers/{id}/catalog/products/{productId}` | Updates a catalogue item (partial). |
| POST | `/material-suppliers/{id}/price-lists` | Sets the dated pack price, **once for the whole network**. |
| POST | `/material-suppliers/{id}/catalog-mappings` | Links a catalogue item to a material. |

### Materials — MaterialController

| Method | Path | Purpose |
|---|---|---|
| POST | `/materials` | Creates a material, with its allergens. |
| PATCH | `/materials/{id}` | Updates a material (partial). |

### Product recipes — ProductController recipe

| Method | Path | Purpose |
|---|---|---|
| PUT | `/products/{id}/recipe` | Sets (replaces) a product’s recipe — for grocery items, 1 × its material. |

### Product availability periods — ProductController availability periods

| Method | Path | Purpose |
|---|---|---|
| POST | `/product-availability-periods/{id}/products` | Makes a product available during a period (activation). |
| DELETE | `/product-availability-periods/{id}/products/{productId}` | Removes a product from a period. |

★ = to deliver first: with `POST /products/resale` and its `OPTIONS`, the cockpit wizard works end to end. The other operations serve later corrections (price change, sales period, discontinued item).

## New fields (`x-nouveau: true`)

| Section | Field | Type | Meaning |
|---|---|---|---|
| Products | `name_nl` | `string` | Dutch name. |
| Products | `max_sale_price` | `number` | **Maximum** sales price incl. VAT, **the same for every shop**: a shop may sell below it, never above. |
| Products | `ean` | `string` | Product barcode, scanned at the till (EAN check digit verified by the cockpit). |
| Products | `ingredients_fr` | `string` | Ingredient list in French. |
| Products | `ingredients_nl` | `string` | Ingredient list in Dutch. |
| Products | `availability_period_ids` | `integer[]` | Availability periods (ids from `GET /product-availability-periods`): the product is active during these periods. |
| Materials | `allergens` | `AllergenCode[]` | Allergens the material **contains** (`GET /allergens` codes); the product inherits them through its recipe. |
| Materials | `may_contain` | `AllergenCode[]` | Possible traces (“may contain”). |

## Decisions by L’Atelier by

1. **Price**: one supplier price list for **the whole network**; the sales price is a **maximum common to every shop** (`max_sale_price`).
2. **Barcode** on the product (`ean`) and on the supplier’s item.
3. **Sales VAT** on the product (`tax_value_percent`), the same everywhere.
4. **Activation** through availability periods (`availability_period_ids`).
5. **Bilingual**: name and ingredients in French and Dutch.
6. **Allergens** on the material (`allergens`, `may_contain`), inherited by the product. An **existing** material is never modified by the cockpit: other recipes use it.

## Common rules for every write

- **Admin** realm (`POST /admin/auth/login`), `Authorization: Bearer <token>`; a consultant account can remain read-only.
- `201` with the full object (including its `id`); `422` naming the offending field, in a single envelope `{ "error": { "code", "message", "field" } }`.
- `409` on a duplicate (same `sku` at the same supplier, same `ean`, same `name` in the same category) **with the existing id** (`error.existing_id`): the wizard reuses it instead of failing.
- `client_ref` on every POST: a second POST with the same `client_ref` creates nothing and returns the object already created (`200`).
- `OPTIONS` on each path lists its methods in the `Allow` header: this is what the cockpit’s “Check API” button reads, without writing anything.
- Partial `PATCH`: only the fields sent are changed.

## The priority call, as an example

```
POST /products/resale
{
  "client_ref": "ep14-l7",
  "supplier":  { "id": 6 },
  "catalog_product": { "sku": "CS-CHOC-72", "name": "Chocolat noir 72 % 100 g", "ean": "5412345000075",
                       "package_size": 20, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 34.00, "valid_from": "2026-11-01" },
  "material": { "name": "Chocolat noir 72 % 100 g", "material_category_id": 11, "unit_name": "pcs", "vat_rate": 6,
                "allergens": ["milk", "soybeans"], "may_contain": ["nuts"] },
  "units_per_pack": 20,
  "product": { "name": "Chocolat noir 72 % - 100 g", "name_nl": "Pure chocolade 72 % - 100 g",
               "id_category": 61, "max_sale_price": 3.20, "tax_value_percent": 6, "ean": "5412345000075",
               "single_weight": 100,
               "ingredients_fr": "Pâte de cacao, sucre, beurre de cacao, lait entier en poudre, émulsifiant : lécithine de soja.",
               "ingredients_nl": "Cacaomassa, suiker, cacaoboter, volle melkpoeder, emulgator: sojalecithine.",
               "availability_period_ids": [1] },
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

## What we need back

1. The names you choose for the new fields, if they differ: the cockpit will follow.
2. Till behaviour above `max_sale_price`: block, or warning?
3. A date for `POST /products/resale`, even on a test environment: the cockpit is ready and will try it right away.
