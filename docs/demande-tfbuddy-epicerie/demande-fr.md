# Demande d’adaptation du Swagger TFBuddy — écritures pour encoder l’épicerie (v3)

Destinataire : équipe de développement de `atelierby.tfbuddy.com` · Demandeur : L’Atelier by (cockpit CEO) · 27/10/2026, version 3  
Base d’URL : `https://atelierby.tfbuddy.com/api/v1` · Versions : français, [English](demande-en.md), [polski](demande-pl.md)

## Le fichier à intégrer

**`openapi-ecritures-epicerie.yaml`** (dans ce dossier) décrit tout ce qui est demandé, au format **OpenAPI 3.0.3, validé** : 14 opérations, rangées dans **vos** sections et **vos** contrôleurs, avec leurs schémas, exemples et réponses d’erreur. Il s’ouvre tel quel dans <https://editor.swagger.io> et se fusionne dans votre `/swagger/openapi.json`. Les champs nouveaux y portent `x-nouveau: true`. Aperçu : `apercu-swagger-1-sections.png` et `apercu-swagger-2-products-resale.png`.

## Le besoin

Le réseau revend en **épicerie** des articles achetés tels quels. Le cockpit scanne le PDF du fournisseur ; un assistant encode et valide chaque ligne — prix, code-barres, TVA, périodes de vente, nom, ingrédients et allergènes en français et en néerlandais — puis la crée dans TFBuddy **en une chaîne** :

```
fournisseur ──► article du catalogue fournisseur (réf., EAN, colis, prix) ──► matière (allergènes) ──► recette (1 × la matière) ──► produit de vente (catégorie, prix max., périodes, FR/NL)
```

**Le Swagger actuel (constaté le 27/10/2026) ne permet que de lire** ces ressources : `GET /material-categories`, `GET /material-suppliers`, `GET /materials`, `GET /product-categories`, `GET /product-category-groups`, `GET /product-availability-periods`, `GET /products`, `GET /shops/{id}/products/available`. La seule écriture produit existante, `POST /product-recipe/{id}/photo`, suppose un produit déjà créé.

## Ce qu’il faut ajouter, section par section de votre Swagger

### Products — ProductController

| Méthode | Chemin | Rôle |
|---|---|---|
| POST | `/products/resale` ★ | **Prioritaire** — crée toute la chaîne en un appel atomique (tout ou rien). C’est la route que l’assistant appelle, ligne par ligne. |
| POST | `/products` | Crée un produit de vente. |
| PATCH | `/products/{id}` | Modifie un produit (partiel). |

### Material suppliers — MaterialController supplier

| Méthode | Chemin | Rôle |
|---|---|---|
| POST | `/material-suppliers` | Crée un fournisseur. |
| PATCH | `/material-suppliers/{id}` | Modifie un fournisseur (partiel). |
| POST | `/material-suppliers/{id}/catalog/products` | Ajoute un article au catalogue du fournisseur. |
| PATCH | `/material-suppliers/{id}/catalog/products/{productId}` | Modifie un article du catalogue (partiel). |
| POST | `/material-suppliers/{id}/price-lists` | Fixe le prix daté d’un colis, **une fois pour tout le réseau**. |
| POST | `/material-suppliers/{id}/catalog-mappings` | Relie un article du catalogue à une matière. |

### Materials — MaterialController

| Méthode | Chemin | Rôle |
|---|---|---|
| POST | `/materials` | Crée une matière, avec ses allergènes. |
| PATCH | `/materials/{id}` | Modifie une matière (partiel). |

### Product recipes — ProductController recipe

| Méthode | Chemin | Rôle |
|---|---|---|
| PUT | `/products/{id}/recipe` | Fixe (remplace) la recette d’un produit — pour l’épicerie, 1 × sa matière. |

### Product availability periods — ProductController availability periods

| Méthode | Chemin | Rôle |
|---|---|---|
| POST | `/product-availability-periods/{id}/products` | Rend un produit disponible pendant une période (activation). |
| DELETE | `/product-availability-periods/{id}/products/{productId}` | Retire un produit d’une période. |

★ = à livrer en premier : avec `POST /products/resale` et son `OPTIONS`, l’assistant du cockpit fonctionne de bout en bout. Les autres opérations servent aux corrections ensuite (prix qui change, période de vente, article retiré).

## Les champs nouveaux (`x-nouveau: true`)

| Section | Champ | Type | Sens |
|---|---|---|---|
| Products | `name_nl` | `string` | Nom en néerlandais. |
| Products | `max_sale_price` | `number` | Prix de vente TTC **maximum, le même pour tous les magasins** : un magasin peut vendre en dessous, jamais au-dessus. |
| Products | `ean` | `string` | Code-barres du produit, scanné en caisse (clé EAN contrôlée par le cockpit). |
| Products | `ingredients_fr` | `string` | Liste des ingrédients en français. |
| Products | `ingredients_nl` | `string` | Liste des ingrédients en néerlandais. |
| Products | `availability_period_ids` | `integer[]` | Périodes de disponibilité (ids de `GET /product-availability-periods`) : le produit est actif pendant ces périodes. |
| Materials | `allergens` | `AllergenCode[]` | Allergènes que la matière **contient** (codes de `GET /allergens`) ; le produit en hérite par sa recette. |
| Materials | `may_contain` | `AllergenCode[]` | Traces éventuelles (« peut contenir »). |

## Décisions de L’Atelier by

1. **Prix** : un seul tarif fournisseur pour **tout le réseau** ; le prix de vente est un **maximum commun à tous les magasins** (`max_sale_price`).
2. **Code-barres** sur le produit (`ean`) et sur l’article du fournisseur.
3. **TVA de vente** au produit (`tax_value_percent`), la même partout.
4. **Activation** par les périodes de disponibilité (`availability_period_ids`).
5. **Bilingue** : nom et ingrédients en français et en néerlandais.
6. **Allergènes** sur la matière (`allergens`, `may_contain`), dont le produit hérite. Une matière **existante** n’est jamais modifiée par le cockpit : d’autres recettes l’utilisent.

## Règles communes à toutes les écritures

- Réalm **admin** (`POST /admin/auth/login`), `Authorization: Bearer <token>` ; un compte consultant peut rester en lecture.
- `201` avec l’objet complet (`id` compris) ; `422` qui nomme le champ en cause, dans une enveloppe unique `{ "error": { "code", "message", "field" } }`.
- `409` sur un doublon (même `sku` chez le même fournisseur, même `ean`, même `name` dans la même catégorie) **avec l’id existant** (`error.existing_id`) : l’assistant le réutilise au lieu d’échouer.
- `client_ref` sur chaque POST : un second POST portant le même `client_ref` ne crée rien et rend l’objet déjà créé (`200`).
- `OPTIONS` sur chaque chemin annonce ses verbes dans l’en-tête `Allow` : c’est ce que lit le bouton « Vérifier l’API » du cockpit, sans rien écrire.
- `PATCH` partiel : seuls les champs envoyés changent.

## L’appel prioritaire, en exemple

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

## Ce qu’il nous faut en retour

1. Les noms retenus pour les champs nouveaux, s’ils diffèrent : le cockpit s’aligne.
2. Le comportement en caisse au-dessus de `max_sale_price` : refus ou alerte ?
3. Une date pour `POST /products/resale`, même sur un environnement de test : le cockpit est prêt et l’essaiera aussitôt.
