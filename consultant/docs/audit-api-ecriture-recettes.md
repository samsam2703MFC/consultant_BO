# Audit : ce que le cockpit peut écrire au panel (recettes, matières, référentiels)

**Question posée le 26/11/2026** : depuis Vérification › Recettes (`#/verification/recettes`), peut-on
**créer** (POST) des recettes, des matières premières et le reste au panel TFBuddy ?

**Sources**

- Le swagger `https://test.tfbuddy.com/docs/openapi.json`, lu le 26/11/2026 : **1 306 chemins et
  1 705 opérations**, tous préfixés `/api/v1`. **Deux seulement** restent « incomplete ». Le 09/11, la
  plupart des écritures n'avaient pas de contrat (`shared/docs/audit-api-tfbuddy.md`). Aujourd'hui, elles en
  ont un : corps, champs obligatoires, codes de réponse.
- La production (`atelierby.tfbuddy.com`), en lecture seulement : `GET /recipes/{id}` (481 recettes, du n° 1 au
  n° 613) et le coût d'une recette dans un magasin (`/shops/4/recipes/216/cost`).
- Le code : `consultant/src/verification_correction.php` et sa doublure
  `consultant/bin/tests_verification_correction.php`.

**Limites** : aucune écriture n'a été faite. Les routes protégées n'ont pas pu être lues d'ici : le jeton
consultant enregistré pour la session avait expiré (401 `INVALID_TOKEN`, les jetons vivent 20 h). La production ne publie pas son swagger (`/docs/openapi.json` →
404), mais les lectures faites y correspondent au swagger de test.

---

## Verdict en bref

1. **Oui, tout se crée par l'API**, et le contrat est documenté : les recettes de produit, les sous-recettes,
   les matières, les catégories de matière et de sous-recette, les lieux de stockage, les types de
   préparation, les produits et leurs portions, le prix d'une matière par magasin, la TVA et l'inventaire
   matière (§ 1).
2. **Droits** : chaque écriture ne demande qu'un jeton valide (`checkAuth`), sans rôle, permission ni contrôle
   du magasin. Le compte consultant du cockpit suffit donc, sur le papier.
3. **Non modifiables** : les unités et les allergènes sont en lecture seule. On prend leurs identifiants
   existants (`GET /units`, `GET /allergens`).
4. **Trois gestes du cockpit ne suivaient pas le contrat** (§ 3) : la correction des lignes d'une recette,
   celle d'une sous-recette, et la portion. Le plus risqué était la sous-recette. **Corrigé le 26/11/2026.**
5. **Le prix d'une matière et les food costs** (§ 4) : le cockpit relit maintenant le food cost des recettes
   qui portent la matière, avant et après le prix. Le test en production reste à faire (jeton expiré).

---

## 1. Créer (POST)

Toutes les réponses de création sont `201 { status, message, inserted_id }`, sauf indication contraire.

| Objet | Route | Obligatoire | Facultatif utile |
|---|---|---|---|
| **Recette de produit** | `POST /recipes` | `id_brand`, `id_product`, `materials[]` **et** `subrecipes[]` (les deux listes, au moins une non vide) | `name`, `preparation_types[{id, duration_second}]`, `preparation_steps[texte]`. Le rendement est posé à 1, unité n° 2 : il se change ensuite par un PATCH sans liste (§ 2). La recette est rattachée au produit `id_product`. |
| Ligne de recette | (dans `materials[]` / `subrecipes[]`) | `id` (la matière ou la sous-recette), `quantity` (0 à 99 999 999,99) | `waste_amount_perc` (0 ≤ x < 100 pour une matière, **0** pour une sous-recette) ; `connection_id` pour garder une ligne existante, absent pour une ligne neuve |
| **Sous-recette** | `POST /subrecipes` | `id_brand`, `name`, `id_unit`, `yield_quantity`, `id_category` (catégorie de sous-recette), `id_storage` | `materials[]`, `subrecipes[]` (vides acceptés), préparations |
| **Matière première** | `POST /materials` | `name`, `id_category`, `id_unit` (**3 = g, 4 = ml, 5 = pièce**), `source_type` (`CENTRAL` ou `OPEN`) | `material_type` (`RAW` ou `PACKAGING`), `waste_amount_perc` (entier), `allergens[ids]`, `vat_rates[{country_code, vat_rate}]`, `is_part_of_package`, `can_be_product_addon`. **Prix de référence** : soit `reference_unit_price_net` (prix par unité de base), soit le trio `reference_purchase_price_net` + `reference_purchase_quantity` + `reference_purchase_unit` (g, kg, ml, l, szt., pcs), **jamais les deux**. `id_brand` est forcé à 1. |
| Catégorie de matière | `POST /material-categories` | `id_brand`, `name` | |
| Catégorie de sous-recette | `POST /subrecipe-categories` | rien n'est déclaré obligatoire | `id_brand`, `name`, `description` |
| Lieu de stockage | `POST /product-storage-locations` | `id_brand`, `name`, `description` (vide admis) | |
| Type de préparation | `POST /preparation-types` | `id_brand`, `name` | |
| Produit | `POST /products` | 16 champs : `id_brand`, `id_category`, `id_storage`, `id_positioning`, `tax_val_perc`, `single_weight`, `quantity_per_label`, `shelf_life_minutes`, `storage_temperature`, `is_divisible`, `is_vegetarian`, `is_prepared_before_sales`, `name`, `suggested_sale_price`, `label_size` (`small`, `large`, `none`), `shelf_life_category` (`SSL`, `MSL`, `LSL`) | `id_recipe`, `expected_margin`, `is_active`… |
| Portion d'un produit | `POST /products/{id}/portions` | `portion_type` : `ONE_EIGHTH`, `ONE_SIXTH`, `ONE_QUARTER`, `ONE_THIRD`, `ONE_HALF`, `TWO_THIRDS`, `THREE_QUARTERS` | `is_active`, `display_order`, `id_package`. Rend `{ data }` avec le coût de fabrication. 409 si le type existe déjà, actif. |
| Prix d'une matière par magasin | `POST /materials/{id}/manual-price` | `shop_ids[]`, `pack_price`, `package_size` | Rend `200 { success, updated_shops }`. Recalcule les recettes concernées. |
| TVA d'une matière | `POST /materials/{id}/vat-rates` | `{country_code, vat_rate}` ou une liste | Ferme le taux précédent, ouvre celui du jour. |
| Inventaire matière | `POST /shops/{id}/materials/stocktakings` | `type` (`SCANNER` ou `MANUAL`) | `elements[]`. **Attention** : un inventaire complet remplace tous les soldes et **met à zéro les matières non comptées**. |

**Pour monter une recette de zéro**, dans cet ordre :

1. les matières manquantes (`POST /materials`) ;
2. les sous-recettes (`POST /subrecipes`) ;
3. la recette du produit (`POST /recipes` avec `id_product`) ;
4. son rendement, par un PATCH sans liste (§ 2) ;
5. `POST /shops/{id}/recipes/recalculate`, puis relire `GET /recipes/{id}/cost`.

---

## 2. Modifier (PATCH) : les pièges du contrat

**`PATCH /recipes/{id}`** (recette de produit, réponse 204 sans corps) a deux branches :

- **La branche « lignes »** : elle s'applique dès que `materials`, `subrecipes`, `preparation_types` ou
  `preparation_steps` est présent. Elle **exige les deux listes** `materials` et `subrecipes`. Elle
  **remplace les lignes et les préparations**, et **ignore les champs simples** (rendement, nom).
- **La branche « champs simples »** : aucune liste dans le corps. Elle met à jour le rendement, l'unité et le
  type, avec recalcul.

**`PATCH /subrecipes/{id}`** : la branche « lignes » exige en plus `name`, `yield_quantity`, `id_unit`,
`id_storage` et `id_category`. Une liste omise est **remise à vide**, et les préparations sont
**réinitialisées**.

**Les préparations** : `GET` les rend en objets `{step_number, step_description}`, mais `PATCH` les attend
en **liste de textes**. Il faut donc les convertir avant de les renvoyer.

**Les photos** : le swagger documente un bug. Si le corps contient un champ photo, les anciens fichiers sont
effacés avec l'`id` du corps, puis les données sont écrites avec l'`id` du chemin. **Ne jamais envoyer de
champ photo** dans un PATCH de recette.

**`PATCH /materials/{id}`** (204) : `vat_rates` y est refusé (passer par la route de TVA). Les allergènes ne
sont remplacés que s'ils sont envoyés. L'unité ne se change pas (409).

**`PATCH /product-portions/{id}`** : seuls `portion_type`, `is_active`, `display_order` et `id_package`
existent. **Il n'y a pas de fraction libre.** Une fois la portion utilisée, son `portion_type` ne change plus
(409).

**`PATCH /products/{id}`** (204) : les champs omis sont conservés. `id_recipe` rattache une recette. 409 en
cas de dépendance de personnalisation.

---

## 3. Ce que le cockpit envoie aujourd'hui, face au contrat

`verification_correction.php` avait été écrit quand ces routes n'avaient pas de contrat. Il n'envoyait donc
**que les clés changées**. Or le contrat du 26/11 ne fusionne pas : il remplace. Le tableau dit ce qui partait
avant la correction du 26/11/2026.

| Geste | Ce qui part | Ce que dit le contrat | Effet attendu |
|---|---|---|---|
| `quantite`, `recette`, `copier` sur une **recette de produit** | `PATCH /recipes/{id}` avec la seule liste touchée (souvent `materials` seule) | Les deux listes sont exigées. Les préparations sont remplacées. | **422 probable** dès qu'une seule liste part. Si l'appel passe, les préparations non renvoyées risquent d'être effacées. |
| Les mêmes gestes sur une **sous-recette** | `PATCH /subrecipes/{id} { materials }` | `name`, rendement, unité, stockage et catégorie sont exigés. Une liste omise est vidée, et les préparations sont réinitialisées. | **422** au mieux. Au pire : les sous-recettes imbriquées et les étapes de préparation sont effacées, et « Annuler » ne rendrait que les matières. |
| `rendement` | Refusé par le cockpit, car `GET /recipes/{id}` ne rend pas le rendement d'une recette de produit. **Reconfirmé en production le 26/11** : seulement `id`, `name`, `id_product` et les listes. | Le PATCH sans liste `{ yield_quantity }` existe. | **Faisable** : l'ancienne valeur se lit dans `/recipes/{id}/cost` (mesuré le 13/11). |
| `recette` avec rendement **et** lignes | Un seul PATCH | La branche « lignes » ignore le rendement. | La relecture marque le rendement « sans effet ». Il faut deux appels. |
| `portion` | `PATCH /product-portions/{id} { portion_size: 0.25 }` (ou la clé lue) | Ce champ n'existe pas. | **Sans effet.** Passer par `portion_type`. Si la portion est déjà utilisée (409) : créer la nouvelle (POST), puis désactiver l'ancienne (`PATCH …/deactivate`). |
| `rattacher` | `PATCH /products/{id} { id_recipe }` | Conforme. | — |
| `prix` | `POST /materials/{id}/manual-price { shop_ids, pack_price, package_size }` | Conforme. | — |
| `recalculer` | `POST /shops/{id}/recipes/recalculate` | Conforme (201). | — |

**La doublure de test cachait le problème.** Dans `tests_verification_correction.php`, le PATCH d'une
sous-recette fusionnait le corps dans l'objet (`array_merge`), et un PATCH de recette n'était jamais refusé faute
de la seconde liste. Les tests passaient donc, alors que le vrai panel se comporte autrement.

**Corrigé le 26/11/2026** (`vrcCorpsContrat`, `vrcObjet`, le geste `portion`) :

1. **Recette de produit** : `materials` **et** `subrecipes` partent toujours (la liste non touchée telle que
   lue, au format du contrat), avec `preparation_types {id, duration_second}` et `preparation_steps` (les
   textes, dans l'ordre), tels que lus. Jamais de champ photo. Une ligne illisible, ou une recette vidée,
   bloque l'écriture au lieu de perdre une ligne.
2. **Sous-recette** : `name`, `yield_quantity`, `id_unit`, `id_storage` et `id_category` partent aussi, tels que
   lus (le rendement changé y voyage). « Annuler » renvoie un corps aussi complet.
3. **Rendement d'une recette de produit** : un PATCH à part, sans liste. L'avant se lit dans `/recipes/{id}/cost`.
4. **Portion** : `portion_type` (1/8, 1/6, 1/4, 1/3, 1/2, 2/3, 3/4) ; une autre part est refusée par le cockpit,
   et un 409 (portion déjà vendue) dit quoi faire au panel.
5. **Relecture** : seules les clés qui devaient changer sont comparées (les préparations reviennent sous une
   autre forme).
6. **Doublure** : elle suit le contrat (les deux branches, la liste vidée d'une sous-recette, les types de
   portion, le rendement absent de `GET /recipes/{id}`). 152 tests, tous réussis.
7. Reste à faire : un geste **« créer »** (matière, sous-recette, recette), dans l'ordre du § 1.

---

## 4. Le prix d'une matière et les food costs

**Ce que fait le cockpit depuis le 26/11/2026.** Quand un prix de matière part au panel (liste « Recettes à
corriger », onglet des prix matières, tableau des fuites, fiche matière), le cockpit :

1. trouve les recettes qui portent la matière, sous-recettes comprises, d'après les recettes déjà lues (30 au
   plus) ;
2. relit leur food cost dans le magasin (`/shops/{id}/recipes/{rid}/cost`, coût matière d'une pièce) **avant**
   d'écrire ;
3. écrit le prix (`POST /materials/{id}/manual-price {shop_ids, pack_price, package_size}`), qui recalcule les
   recettes du magasin selon le contrat ;
4. relit les food costs **après**, et dit lesquels ont bougé, de combien (en € et en %). Si le prix a pris
   mais qu'aucun food cost n'a bougé, il le signale.

L'aperçu annonce combien de recettes seront relues. « Annuler » remet l'ancien prix et relit de nouveau.
L'effet est calculé dans les trois premiers magasins visés.

**Le test en production, préparé mais pas encore fait** (jeton consultant expiré) :

| | |
|---|---|
| Matière | « Tomates cerises » (n° 282), en approvisionnement libre (`OPEN`), sans fournisseur qui impose son tarif |
| Magasin | n° 4 : prix local de 4,20 €/kg (0,0042 €/g) |
| Recette témoin | « Salade Tagliata » (n° 216) : 60 g de tomates cerises, soit 0,252 € |
| Recettes concernées | 17 recettes contiennent des tomates cerises, 25 des tomates grappe (n° 281), 20 de la salade feuille de chêne (n° 280) |
| Attendu | à 5,00 €/kg : +0,048 € sur la Salade Tagliata (0,860 → 0,908 €) ; puis « Annuler » remet 4,20 €/kg |

**Constat en passant** : au magasin 4, le food cost de la Salade Tagliata est **partiel** (0,86 € HT connus) :
le mesclun, le radicchio, le steak de bœuf et la fourme d'Ambert n'y ont pas de prix. Le food cost réel de
cette salade est donc plus élevé que ce que le panel affiche.
