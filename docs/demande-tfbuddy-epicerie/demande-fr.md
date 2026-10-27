# Demande à TFBuddy — écritures API pour encoder l’épicerie

Destinataire : équipe de développement de `atelierby.tfbuddy.com` · Demandeur : L’Atelier by (cockpit CEO) · 27/10/2026  
Base d’URL : `https://atelierby.tfbuddy.com/api/v1` · Versions : français, [English](demande-en.md), [polski](demande-pl.md)

## Le besoin

Le réseau revend en **épicerie** des articles achetés tels quels (café, confitures, chocolats…). Le processus du cockpit : on scanne le PDF du fournisseur (catalogue, tarif), un assistant lit chaque ligne, on l’encode et on la valide étape par étape, puis tout doit être créé dans TFBuddy **en une chaîne** :

```
fournisseur ──► article du catalogue fournisseur (réf., colis, prix) ──► matière ──► recette (1 × la matière) ──► produit de vente (catégorie Épicerie)
```

Constaté dans le Swagger le 27/10/2026 : `material-categories`, `material-suppliers`, `materials`, `product-categories` et `products` sont **en lecture seule** (GET). Aucun maillon ne peut être créé. Les écritures ci-dessous sont le minimum pour que l’assistant crée la chaîne sans ressaisie. Les noms de champs sont **ceux que vos GET rendent déjà** — rien n’est inventé.

## Règles communes

- Compte **admin** (réalm `/admin/auth/login`) ; un compte consultant peut rester en lecture.
- `201` avec l’objet complet (`id` compris) ; `422` avec le champ en cause, dans une enveloppe unique : `{ "error": { "code": "…", "message": "…", "field": "…" } }` ; `409` sur un doublon (même `sku` chez le même fournisseur, même `ean`, même `name` dans la même catégorie) **en rendant l’id existant**, pour que l’assistant le réutilise au lieu d’échouer.
- `client_ref` sur chaque POST (chaîne libre, 64 caractères au plus) : un second POST portant le même `client_ref` ne crée rien et rend l’objet déjà créé (`200`). On peut réémettre sans risque après un réseau coupé.
- `OPTIONS` sur chaque route doit annoncer ses verbes dans l’en-tête `Allow` : c’est ce que lit le bouton « Vérifier l’API » du cockpit, sans rien écrire.
- `PATCH` partiel : seuls les champs envoyés changent.

## Les routes

| # | Route | Crée / modifie | Champs (noms de vos GET) |
|---|---|---|---|
| 1 | `POST /material-suppliers` · `PATCH /material-suppliers/{id}` | le fournisseur | `name`, `type`, `email`, `phone`, `address`, `city`, `country_code`, `tax_number`, `website_url`, `currency`, `notes` |
| 2 | `POST /material-suppliers/{id}/catalog/products` · `PATCH …/catalog/products/{productId}` | l’article du fournisseur | `sku`, `name`, `ean`, `package_size`, `package_unit`, `portion_size`, `portion_unit`, `weight_grams`, `shelf_life_days`, `vat_rate`, `is_active` |
| 3 | `POST /material-suppliers/{id}/price-lists` | le prix du colis, daté | `sku` (ou `catalog_product_id`), `price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from`, `shop_ids` (vide = tout le réseau) |
| 4 | `POST /materials` · `PATCH /materials/{id}` | la matière | `name`, `code`, `material_category_id`, `unit_name` (unité de base : `pcs`, `g`, `kg`, `l`), `vat_rate`, `material_type`, `waste_amount_perc` |
| 5 | `POST /material-suppliers/{id}/catalog-mappings` | le lien article ↔ matière | `material_id`, `catalog_product_id`, `units_per_pack` |
| 6 | `POST /products` · `PATCH /products/{id}` | le produit de vente | `name`, `id_category`, `suggested_sale_price`, `tax_value_percent`, `single_weight`, `is_divisible`, `portion_size`, `allergene`, `shelf_life_minutes`, `is_active` |
| 7 | `PUT /products/{id}/recipe` | la recette du produit | `yield_quantity`, `elements: [{ material_id, required_quantity, unit_name }]` |

**Raccourci recommandé — une seule route, atomique :**

```
POST /products/resale
{
  "client_ref": "imp14-l3",
  "supplier":  { "id": 6 },                                   // ou { "name": …, "tax_number": … } pour le créer
  "catalog_product": { "sku": "CF-250-ARA", "name": "Café Fiesta Arabica 250 g", "ean": "5412345678901",
                       "package_size": 12, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 41.40, "valid_from": "2026-11-01", "shop_ids": [] },
  "material": { "id": null, "name": "Café Fiesta Arabica 250 g", "material_category_id": 9,
                "unit_name": "pcs", "vat_rate": 6 },                                    // id renseigné = matière existante
  "units_per_pack": 12,
  "product": { "name": "Fiesta Arabica - 250 g.", "id_category": 61, "suggested_sale_price": 6.90,
               "tax_value_percent": 6, "single_weight": 250, "is_active": 0 },
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

Tout passe ou rien ne passe : pas de matière orpheline si le produit est refusé. C’est la route que l’assistant du cockpit appellera ligne par ligne ; les routes 1 à 7 restent utiles pour corriger après coup (prix qui change, article retiré).

## À trancher

1. **Prix** : les listes de prix sont aujourd’hui par magasin (`/material-suppliers/{fid}/shops/{sid}/price-lists`). Un tarif fournisseur réseau doit-il s’écrire une fois (`shop_ids` vide) ou magasin par magasin ?
2. **EAN** : sur l’article du fournisseur, sur la matière, ou sur le produit ? La caisse en a besoin pour scanner l’article d’épicerie.
3. **TVA de vente** : au produit (`tax_value_percent` n’apparaît aujourd’hui que sur `/shops/{id}/products/available`) ou au magasin ?
4. **Activation** : un produit créé `is_active: 0` s’active-t-il au panel, ou faut-il que le cockpit appelle `PATCH /products/{id}` `{ "is_active": 1 }` après validation ?
5. **Nom néerlandais** : où l’écrire (alias de nom, comme pour les gammes saisonnières) ?
