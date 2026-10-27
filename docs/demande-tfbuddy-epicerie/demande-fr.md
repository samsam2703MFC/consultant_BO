# Demande à TFBuddy — écritures API pour encoder l’épicerie (v2)

Destinataire : équipe de développement de `atelierby.tfbuddy.com` · Demandeur : L’Atelier by (cockpit CEO) · 27/10/2026, version 2 (décisions intégrées)  
Base d’URL : `https://atelierby.tfbuddy.com/api/v1` · Versions : français, [English](demande-en.md), [polski](demande-pl.md)

## Le besoin

Le réseau revend en **épicerie** des articles achetés tels quels (café, confitures, chocolats…). Le processus du cockpit : on scanne le PDF du fournisseur (catalogue, tarif), un assistant lit chaque ligne, on l’encode et on la valide étape par étape — prix, code-barres, TVA, périodes de vente, nom, ingrédients et allergènes en français et en néerlandais — puis tout doit être créé dans TFBuddy **en une chaîne** :

```
fournisseur ──► article du catalogue fournisseur (réf., EAN, colis, prix) ──► matière (allergènes) ──► recette (1 × la matière) ──► produit de vente (catégorie, prix max., périodes, FR/NL)
```

Constaté dans le Swagger le 27/10/2026 : `material-categories`, `material-suppliers`, `materials`, `product-categories`, `product-availability-periods` et `products` sont **en lecture seule** (GET). Aucun maillon ne peut être créé. Les écritures ci-dessous sont le minimum pour que l’assistant crée la chaîne sans ressaisie. Les noms de champs sont ceux que vos GET rendent déjà ; **les champs en gras sont nouveaux** — si vous les nommez autrement, dites-le-nous, le cockpit s’alignera.

## Décisions de L’Atelier by

1. **Prix** : un seul tarif fournisseur, écrit une fois pour **tout le réseau** (plus de liste par magasin pour l’épicerie). Le prix de vente est un **prix maximum, le même pour tous les magasins** (`max_sale_price`, TTC) : un magasin peut vendre en dessous, jamais au-dessus.
2. **Code-barres** : l’EAN est sur le **produit** (`ean`, scanné en caisse) et sur l’article du fournisseur. Il est saisi et contrôlé (clé EAN-13 / EAN-8) dans l’assistant.
3. **TVA de vente** : au **produit** (`tax_value_percent`), la même dans tous les magasins.
4. **Activation** : par les **périodes de disponibilité** (`availability_period_ids`, identifiants de `GET /product-availability-periods`). Le produit se vend pendant ses périodes — « Permanent » pour toute l’année. La route 8 les modifie ensuite.
5. **Bilingue** : nom (`name` / `name_nl`) et ingrédients (`ingredients_fr` / `ingredients_nl`) en français et en néerlandais, sur le produit.
6. **Allergènes** : cochés un par un dans l’assistant, envoyés en codes de `GET /allergens` sur la **matière** — `allergens` (contient) et `may_contain` (traces éventuelles). Le produit en hérite par sa recette, comme vos autres produits.

## Règles communes

- Compte **admin** (réalm `/admin/auth/login`) ; un compte consultant peut rester en lecture.
- `201` avec l’objet complet (`id` compris) ; `422` avec le champ en cause, dans une enveloppe unique : `{ "error": { "code": "…", "message": "…", "field": "…" } }` ; `409` sur un doublon (même `sku` chez le même fournisseur, même `ean`, même `name` dans la même catégorie) **en rendant l’id existant**, pour que l’assistant le réutilise au lieu d’échouer.
- `client_ref` sur chaque POST (chaîne libre, 64 caractères au plus) : un second POST portant le même `client_ref` ne crée rien et rend l’objet déjà créé (`200`). On peut réémettre sans risque après un réseau coupé.
- `OPTIONS` sur chaque route doit annoncer ses verbes dans l’en-tête `Allow` : c’est ce que lit le bouton « Vérifier l’API » du cockpit, sans rien écrire.
- `PATCH` partiel : seuls les champs envoyés changent.

## Les routes

| # | Route | Crée / modifie | Champs (en gras : nouveaux) |
|---|---|---|---|
| 1 | `POST /material-suppliers` · `PATCH /material-suppliers/{id}` | le fournisseur | `name`, `type`, `email`, `phone`, `address`, `city`, `country_code`, `tax_number`, `website_url`, `currency`, `notes` |
| 2 | `POST /material-suppliers/{id}/catalog/products` · `PATCH …/catalog/products/{productId}` | l’article du fournisseur | `sku`, `name`, `ean`, `package_size`, `package_unit`, `portion_size`, `portion_unit`, `weight_grams`, `shelf_life_days`, `vat_rate`, `is_active` |
| 3 | `POST /material-suppliers/{id}/price-lists` | le prix du colis, daté | `sku` (ou `catalog_product_id`), `price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from` — valable pour **tous les magasins** |
| 4 | `POST /materials` · `PATCH /materials/{id}` | la matière et ses allergènes | `name`, `code`, `material_category_id`, `unit_name` (unité de base : `pcs`, `g`, `kg`, `l`), `vat_rate`, `material_type`, `waste_amount_perc`, **`allergens`**, **`may_contain`** (codes de `GET /allergens`) |
| 5 | `POST /material-suppliers/{id}/catalog-mappings` | le lien article ↔ matière | `material_id`, `catalog_product_id`, `units_per_pack` |
| 6 | `POST /products` · `PATCH /products/{id}` | le produit de vente | `name`, **`name_nl`**, `id_category`, **`max_sale_price`**, `tax_value_percent`, **`ean`**, `single_weight`, `is_divisible`, `portion_size`, **`ingredients_fr`**, **`ingredients_nl`**, `shelf_life_minutes`, **`availability_period_ids`** |
| 7 | `PUT /products/{id}/recipe` | la recette du produit | `yield_quantity`, `elements: [{ material_id, required_quantity, unit_name }]` |
| 8 | `POST /product-availability-periods/{id}/products` · `DELETE …/products/{productId}` | les périodes de vente d’un produit | `product_id` |

**Raccourci recommandé — une seule route, atomique** (c’est celle que l’assistant appellera, ligne par ligne) :

```
POST /products/resale
{
  "client_ref": "ep14-l7",
  "supplier":  { "id": 6 },                                   // ou { "name": …, "tax_number": … } pour le créer
  "catalog_product": { "sku": "CS-CHOC-72", "name": "Chocolat noir 72 % 100 g", "ean": "5412345000075",
                       "package_size": 20, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 34.00, "valid_from": "2026-11-01" },                   // tarif réseau : tous les magasins
  "material": { "id": null, "name": "Chocolat noir 72 % 100 g", "material_category_id": 11,
                "unit_name": "pcs", "vat_rate": 6,
                "allergens": ["milk", "soybeans"], "may_contain": ["nuts"] },   // id renseigné = matière existante (allergènes ignorés)
  "units_per_pack": 20,
  "product": { "name": "Chocolat noir 72 % - 100 g", "name_nl": "Pure chocolade 72 % - 100 g",
               "id_category": 61, "max_sale_price": 3.20, "tax_value_percent": 6,
               "ean": "5412345000075", "single_weight": 100,
               "ingredients_fr": "Pâte de cacao, sucre, beurre de cacao, émulsifiant : lécithine de soja.",
               "ingredients_nl": "Cacaomassa, suiker, cacaoboter, emulgator: sojalecithine.",
               "availability_period_ids": [1] },                                 // 1 = « Permanent »
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

Tout passe ou rien ne passe : pas de matière orpheline si le produit est refusé. Les routes 1 à 8 restent utiles pour corriger après coup (prix qui change, période de vente, article retiré).

## Ce qu’il nous faut en retour

1. Les noms que vous retenez pour les champs nouveaux, s’ils diffèrent (`max_sale_price`, `name_nl`, `ingredients_fr`, `ingredients_nl`, `ean` du produit, `allergens` / `may_contain` de la matière, `availability_period_ids`).
2. Le comportement en caisse au-dessus de `max_sale_price` : refus, ou alerte ?
3. Une date de mise à disposition de `POST /products/resale` (même sur un environnement de test) : le cockpit est prêt et l’essaiera tout de suite.
