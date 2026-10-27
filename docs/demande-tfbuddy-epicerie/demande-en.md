# Request to TFBuddy — API write endpoints to encode grocery (resale) items

To: development team of `atelierby.tfbuddy.com` · From: L’Atelier by (CEO cockpit) · 27/10/2026  
Base URL: `https://atelierby.tfbuddy.com/api/v1` · Versions: [français](demande-fr.md), English, [polski](demande-pl.md)

## The need

The network resells **grocery items** bought and sold as-is (coffee, jams, chocolates…). The cockpit process: we scan the supplier’s PDF (catalogue, price list), a wizard reads each line, we encode and validate it step by step, and then everything must be created in TFBuddy **as one chain**:

```
supplier ──► supplier catalogue item (SKU, pack, price) ──► material ──► recipe (1 × the material) ──► sales product (Grocery category)
```

Observed in the Swagger on 27/10/2026: `material-categories`, `material-suppliers`, `materials`, `product-categories` and `products` are **read-only** (GET). None of the links in the chain can be created. The write endpoints below are the minimum the wizard needs to create the chain without re-typing anything. Field names are **the ones your GET endpoints already return** — nothing is invented.

## Common rules

- **Admin** account (realm `/admin/auth/login`); a consultant account can remain read-only.
- `201` with the full object (including its `id`); `422` naming the offending field, in a single envelope: `{ "error": { "code": "…", "message": "…", "field": "…" } }`; `409` on a duplicate (same `sku` at the same supplier, same `ean`, same `name` in the same category) **returning the existing id**, so the wizard can reuse it instead of failing.
- `client_ref` on every POST (free string, 64 characters max): a second POST with the same `client_ref` creates nothing and returns the object already created (`200`). This makes retries safe after a network failure.
- `OPTIONS` on each route must list its methods in the `Allow` header: this is what the cockpit’s “Check API” button reads, without writing anything.
- Partial `PATCH`: only the fields sent are changed.

## Endpoints

| # | Endpoint | Creates / updates | Fields (names from your GET endpoints) |
|---|---|---|---|
| 1 | `POST /material-suppliers` · `PATCH /material-suppliers/{id}` | the supplier | `name`, `type`, `email`, `phone`, `address`, `city`, `country_code`, `tax_number`, `website_url`, `currency`, `notes` |
| 2 | `POST /material-suppliers/{id}/catalog/products` · `PATCH …/catalog/products/{productId}` | the supplier’s catalogue item | `sku`, `name`, `ean`, `package_size`, `package_unit`, `portion_size`, `portion_unit`, `weight_grams`, `shelf_life_days`, `vat_rate`, `is_active` |
| 3 | `POST /material-suppliers/{id}/price-lists` | the pack price, dated | `sku` (or `catalog_product_id`), `price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from`, `shop_ids` (empty = whole network) |
| 4 | `POST /materials` · `PATCH /materials/{id}` | the material | `name`, `code`, `material_category_id`, `unit_name` (base unit : `pcs`, `g`, `kg`, `l`), `vat_rate`, `material_type`, `waste_amount_perc` |
| 5 | `POST /material-suppliers/{id}/catalog-mappings` | the item ↔ material link | `material_id`, `catalog_product_id`, `units_per_pack` |
| 6 | `POST /products` · `PATCH /products/{id}` | the sales product | `name`, `id_category`, `suggested_sale_price`, `tax_value_percent`, `single_weight`, `is_divisible`, `portion_size`, `allergene`, `shelf_life_minutes`, `is_active` |
| 7 | `PUT /products/{id}/recipe` | the product’s recipe | `yield_quantity`, `elements: [{ material_id, required_quantity, unit_name }]` |

**Recommended shortcut — one atomic endpoint:**

```
POST /products/resale
{
  "client_ref": "imp14-l3",
  "supplier":  { "id": 6 },                                   // or { "name": …, "tax_number": … } to create it
  "catalog_product": { "sku": "CF-250-ARA", "name": "Café Fiesta Arabica 250 g", "ean": "5412345678901",
                       "package_size": 12, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 41.40, "valid_from": "2026-11-01", "shop_ids": [] },
  "material": { "id": null, "name": "Café Fiesta Arabica 250 g", "material_category_id": 9,
                "unit_name": "pcs", "vat_rate": 6 },                                    // id given = existing material
  "units_per_pack": 12,
  "product": { "name": "Fiesta Arabica - 250 g.", "id_category": 61, "suggested_sale_price": 6.90,
               "tax_value_percent": 6, "single_weight": 250, "is_active": 0 },
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

All or nothing: no orphan material is left behind if the product is rejected. This is the endpoint the cockpit wizard will call line by line; endpoints 1 to 7 remain useful for later corrections (price change, discontinued item).

## To be decided

1. **Price**: price lists are currently per shop (`/material-suppliers/{fid}/shops/{sid}/price-lists`). Should a network-wide supplier price be written once (empty `shop_ids`) or shop by shop?
2. **EAN**: on the supplier’s item, on the material, or on the product? The till needs it to scan the grocery item.
3. **Sales VAT**: on the product (`tax_value_percent` currently only appears on `/shops/{id}/products/available`) or per shop?
4. **Activation**: is a product created with `is_active: 0` activated in the panel, or should the cockpit call `PATCH /products/{id}` `{ "is_active": 1 }` after validation?
5. **Dutch name**: where should it be stored (name alias, as for the availability periods)?
