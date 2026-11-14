# Aligner les prix des magasins sur les listes des fournisseurs (maquette réelle)

**Statut : maquette réelle du 14/11/2026, à valider. Rien n'est écrit au panel.**

Demande du 14/11/2026 : « est-ce que tu peux mettre à jour les produits — avant de le faire, fais une maquette réelle en
screenshot, ensuite on valide ».

`a.png` : l'écran entier ; `b.png` : le haut, de près. **Toutes les données sont réelles**, lues en ligne le 14/11/2026
(lecture seule) : la liste « Recettes à corriger » (règle « prix en retard sur le fournisseur », seuil 10 %) et le
tableau des matières (prix de chaque magasin, listes de prix en vigueur de chaque fournisseur, recettes, coûts).

## Ce que la mise à jour écrirait

- **55 matières, 141 prix** (un par matière et par magasin) : Corbais 34, Gosselies 24, Sombreffe 52, Halle 31 ;
  97 hausses (le magasin payait moins que la liste), 44 baisses.
- **63 produits** touchés (25 411 € de CA sur 30 jours), avec le coût de la pièce avant → après.
- **8 matières mises de côté** : un écart de plus de ×2 (Boîte Lunch ×88, Hotdog et Hamburger ×9,9, Opéra Ind. ÷11,
  soupes ×2,7 à ×3,3…) n'est pas un retard de prix mais un colis ou une unité mal décrits ; rien ne part pour elles.

Chaque prix part par le geste `prix` des corrections (route du registre, par défaut `POST /materials/{mid}/manual-price`),
est relu, noté au registre ; un prix remplacé s'annule.

## Ce que la maquette a corrigé avant d'être montrée

La première version annonçait « Quiche saumon & épinard : 8,865 € → 8,865 € » : SLFood lie deux colis à la même matière
(70,92 € et 102,86 € les 8) et la règle n'en lisait qu'un. Corrigé et mis en ligne le 14/11 (chaque référence compte ;
un magasin n'est en retard que s'il s'écarte de toutes) : 77 → 73 produits signalés, les quiches n'y sont plus.

## Régénérer

Les données (`donnees.json`) se tirent de `GET /verification/recettes` et `GET /verification/recettes/matieres` ; puis :

```
php -S 127.0.0.1:8099 -t .        # depuis pilotage-reseau/
node docs/maquettes/alignement-fournisseurs/generer.js
node docs/maquettes/alignement-fournisseurs/capturer.js
```
