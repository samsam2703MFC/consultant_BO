# La modale produit du dashboard : recette, split des coûts, marge et jauge du coefficient

**Statut : codée le 08/10/2026** (`src/recette_produit.php`, `GET /analyse/produits/recette` ; `public/dashboard/dashboard.js`, `ficheRecette` ; styles `fi-r*` dans `dashboard.css`). La maquette reste ici pour mémoire.

Demande du 08/10/2026 : « dans le dashboard day du magasin, on clique sur le produit, on a une modale ; dans
cette modale rajouter la recette avec le split des coûts, le détail de la marge et la jauge pour voir si on est
bon avec le coefficient. Est-ce possible ? Fais une maquette pour décider. »

## Ce que montre la maquette

Un troisième onglet « Recette & marge » dans la modale existante (onglets « Ventes · 12 semaines » et « Prix face
au réseau »), sur un produit réel de Halle le 08/10/2026 : **FlipFlap - Thon**, 3 vendus, 5,40 € la pièce,
3,91 € de matière, marge brute 27,6 %, coefficient × 1,38.

1. **La jauge du coefficient** (prix encaissé ÷ coût de recette), de × 1 à × 4, avec les zones de l'échelle des
   marges du dashboard (rouge sous × 1,67 soit 40 % de marge, orange jusqu'à × 2,5 soit 60 %, vert au-delà), le
   curseur du produit, les repères de sa catégorie (× 2,03), du magasin aujourd'hui (× 2,58) et de l'objectif du
   réseau (× 3,1 = matière à 32 %, le seuil food du P&L). Le verdict dit ce qu'il faudrait pour entrer dans le
   vert : le prix à 9,78 € ou la matière à 2,16 €, et l'ingrédient qui pèse le plus.
2. **La recette, pièce par pièce** : ingrédient, quantité, coût, part du coût en barre, l'ingrédient le plus lourd
   en couleur, le total = le coût de recette du panel.
3. **La marge, pièce par pièce** : prix encaissé − matière = marge brute, puis main-d'œuvre et frais généraux aux
   seuils du réseau (33 % et 13,5 %), = résultat par pièce (ici − 1,02 €). Dessous, le produit face à sa
   catégorie, au magasin du jour, au prix réseau (5,55 €, 4 magasins) et à l'objectif.

Captures : `a.png` (bureau, 1440 px) et `a-tel.png` (téléphone, 390 px). Données : `donnees.json`.

## Ce qui est réel, ce qui est illustratif

- **Réel, et déjà lu par le dashboard** : le prix encaissé, le coût de recette net de la pièce (`recipe_cost_net`
  gravé avec chaque ticket), la marge brute et le coefficient, la catégorie et le magasin du jour, le prix réseau
  (`/analyse/produits/magasin`), les seuils du P&L. Le coefficient se calcule aujourd'hui dans « Ventes par
  catégorie » (× 2,43…) : la jauge ne demande aucune lecture de plus.
- **Illustratif** : la répartition du coût entre ingrédients (pain, thon, mayonnaise…). Elle se lira chez le
  panel : `GET /recipes/{id}` (matières et sous-recettes de la recette) puis `GET /materials/{id}` ou les prix
  fournisseurs (`/material-suppliers/{id}/materials`). Le cockpit parcourt déjà cette chaîne pour les allergènes
  de la tablette et pour le fournisseur de chaque référence ; il reste à vérifier, par une sonde, que les quantités
  et les prix unitaires y sont. S'ils manquent, la recette se montre sans montants (matières et quantités) et le
  split des coûts reste au niveau de la pièce.

## Est-ce possible ?

Oui pour la marge et la jauge, tout de suite, avec ce que le dashboard lit déjà. Oui pour la recette ligne par
ligne, à condition que le panel rende les quantités et les prix des matières : une lecture de plus par produit
ouvert, gardée 24 h comme les recettes de la tablette, sans appel au panel à chaque clic.

## Régénérer

    node docs/maquettes/modale-recette/generer.js
    php -S 127.0.0.1:8099 -t .   &&   node docs/maquettes/modale-recette/capturer.js
