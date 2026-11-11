# L'onglet Produits, en simple : est-ce que ça se développe ?

**Statut : à choisir.** 3e version (11/11/2026, « ça doit être plus simple »). Remplace E, F, G
(`../onglet-produits-developpement/`) et A à D (`../onglet-produits/`).

Une question, une comparaison, un graphique :

- **La question** : est-ce que le magasin, la catégorie ou le produit se développe ?
- **La comparaison** : les 6 dernières semaines (28 sept. → 8 nov.) face aux 6 d'avant (17 août → 27 sept.).
  ▲ au-delà de + 8 %, ▼ en dessous de − 8 %, « stable » entre les deux.
- **Le graphique** : le chiffre de chaque semaine. Les 6 semaines d'avant sont en clair, les 6
  dernières en foncé, et la semaine en cours est hachurée.

Ni réseau, ni indice, ni carte : seulement des euros, des pièces et une flèche.

## Les trois

| | les catégories | |
|---|---|---|
| **H — la liste** (`h.html`) | une ligne par catégorie : chiffre par semaine, flèche, 12 semaines | la plus sobre, proche du reste du dashboard |
| **I — les tuiles** (`i.html`) | une tuile par catégorie, verte si elle monte, ocre si elle baisse | se lit d'un coup d'œil |
| **J — ça monte, ça baisse** (`j.html`) | deux colonnes, les stables sur une seule ligne | va droit au but |

La suite est **commune aux trois** (`modale.html`) :

1. Un clic sur une catégorie ouvre sa fiche : sa flèche, son graphique et ses produits (prix, pièces par
   semaine, flèche, 12 semaines).
2. Un clic sur un produit le déplie et montre trois chiffres :
   - le **prix de vente** ;
   - la **quantité** par semaine (avant → maintenant) ;
   - la **marge** (% et € par semaine) ;
   avec les pièces de chaque semaine et le lien vers la fiche complète.

## Les chiffres sont réels

Atelier by - Halle, lus le 11 novembre 2026. Le magasin : ▲ + 12 %, soit 13 397 € par semaine contre
11 971 €. Catégories en hausse : Viennoiserie + 15 %, Boulangerie + 9 %, Tartes + 8 %, Quiches + 16 %,
Traiteur + 22 %, Biscuiterie + 39 %. Stables : Pâtisserie, Boissons. En baisse : Épicerie − 15 %.
Fêtes & Occasions est nouvelle. Les « catégories » sont celles de « Ventes par catégorie » (les groupes
du catalogue).

Les données et les calculs viennent de la 2e version : le changement de caisse de S40 est rattaché
(voir `../onglet-produits-developpement/LISEZMOI.md`), et le coût de recette actuel est appliqué à
toutes les semaines (voir `../onglet-produits/LISEZMOI.md`).

## Régénérer (depuis `pilotage-reseau/`)

```bash
node docs/maquettes/onglet-produits-simple/generer.js
npx http-server . -p 8099 -c-1                              # depuis pilotage-reseau/
node docs/maquettes/onglet-produits-simple/capturer.js
# → http://127.0.0.1:8099/docs/maquettes/onglet-produits-simple/planche.html
```
