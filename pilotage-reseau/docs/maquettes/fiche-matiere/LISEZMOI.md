# Recettes à corriger › Matières premières › la fiche d'une matière, modifiable (maquette)

**Statut : maquette du 14/11/2026, à valider.** Rien n'est codé.

Demande du 14/11/2026 : « ça doit être un outil pour encoder les prix, tous les paramètres de la matière (material,
voir swagger) ; les matières sont reliées à des fournisseurs ou à un prix général : faire la comparaison ». Décision :
**tout modifiable**, pour tout le réseau.

## L'écran

La fiche s'ouvre au clic sur une matière de la bascule « Matières premières » (en ligne depuis le 14/11), en mode
*Lire* ou *Modifier*.

- **Paramètres** (pour tout le réseau) : nom, catégorie, unité de base, type, source (centrale / libre), perte, TVA,
  « fait partie d'un emballage », « peut être un supplément d'un produit », « intégrée chez le fournisseur », les
  allergènes, et les recettes qui l'emploient. Un changement d'unité prévient : les recettes qui l'emploient s'ouvrent
  ensuite en correction pour y reposer la quantité.
- **Prix — la comparaison** : le **prix général** (prix de référence du panel) et le prix conseillé, chaque
  **fournisseur lié** (sa liste de prix en vigueur : colis, date) et chaque **magasin**, avec l'écart de chacun au prix
  général, avant → après. « Prendre comme prix général » sur un fournisseur ou un magasin. Une synthèse dit ce qui
  cloche.
- **L'effet sur les recettes** : le coût de la pièce avant / après.
- **La barre** : les changements, les routes qui les écriront, « Vérifier ce qui sera écrit » — le même chemin que le
  reste (écrit au panel, relu, au registre, « Annuler »).

`a.png` : **Sucre (Blanc)** — le prix général et le prix conseillé du panel valent 1,00 €/kg, les trois magasins qui
chiffrent le sucre paient 1,89 €/kg (+89 %), Halle n'a pas de prix ; Bruyerre S.A. est lié mais sans prix en vigueur.
`b.png` : **Poche Sauce Tikka** — l'unité (g) ne colle pas au colis de Berdiff s.a. (4 poches à 10,83 €) : passée en
pièces, le prix se compare (2,71 €/poche) et devient le prix général, puis celui des quatre magasins.

## Réel, illustratif

Lu en ligne le 14/11/2026 (lecture seule) : les deux matières, leur catégorie, source, unité, les prix des magasins,
les prix conseillé et de référence du panel, les fournisseurs et la liste de Berdiff, les recettes qui les emploient et
leurs quantités (Flan Breton : 181,8 g de sucre ; Poulet Tikka Masala & Riz : 180 g de sauce), le coût du Flan Breton à
Halle (3,46 €). Les champs de la fiche sont ceux d'une ligne réelle de `/shops/{id}/materials` (`id_category`,
`id_unit`, `material_type`, `source_type`, `waste_amount_perc`, `vat_rate`, `is_part_of_package`,
`can_be_product_addon`, `integrated_supplier`, `allergens`, `recipe_names`, `subrecipe_names`,
`reference_unit_price_net`, `suggested_base_unit_price_net`, `base_unit_price_net`). **Illustratifs** : les saisies
(les nouveaux prix, l'unité en pièces), le poids d'une poche (500 g), les allergènes de la sauce.

## Avant de coder

Le swagger liste `GET` / `PATCH /materials/{id}`, `POST /materials/{id}/manual-price`, `PATCH /shops/{id}/materials/avg-price`,
`/materials/{id}/vat-rates`, `/packagings`, `/open-params`, sans contrat. Une sonde en lecture seule mesurera la forme de
`GET /materials/{id}` et des routes de prix ; l'écriture suivra la même règle que les recettes : lire, ne changer que les
clés visées, écrire, relire et constater.

## Régénérer

```
php -S 127.0.0.1:8099 -t .        # depuis pilotage-reseau/
node docs/maquettes/fiche-matiere/generer.js
node docs/maquettes/fiche-matiere/capturer.js
```
