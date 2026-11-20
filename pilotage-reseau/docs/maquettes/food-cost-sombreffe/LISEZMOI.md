# Sombreffe : le food cost, les prix, les volumes, les marges — maquette

Demande du 20/11/2026 : « Faire un HTML pour Sombreffe, sur une page complètement indépendante sur le serveur, pour
calculer comment améliorer son food cost par rapport aux volumes et aux marges (faire une maquette, des captures). »

`index.html` est **un seul fichier** : les données et les deux polices Gotham sont dedans, il ne fait aucun appel au
réseau. Il s'ouvre tel quel dans un navigateur et se pose tel quel sur le serveur.

## Ce que la page montre et calcule

| Bloc | Ce qu'il fait |
|---|---|
| Les leviers | l'objectif de food cost (40 % par défaut, le budget du réseau) ; les produits qu'on ne touche pas (aucun, top 10, top 20, top 50 de Sombreffe en volume) ; **1 · aligner sur le prix du réseau** (la médiane des quatre magasins, +15 % au plus) ; **2 · remonter les petites marges** (jusqu'à 50 %, +10 % au plus) ; **3 · une hausse sur le reste** (0 à 30 %) ; **l'effet sur les volumes** (les ventes d'un produit baissent de x % quand son prix monte de 10 %) |
| Atteindre l'objectif | cherche la plus petite hausse sur le reste qui amène le food cost à l'objectif, les autres leviers tels quels, et le dit (« Il faut +22 % sur le reste de la carte ») |
| Les chiffres clés | food cost, marge brute par mois (et par an), CA HTVA par mois, pièces par mois : simulé, face à aujourd'hui |
| La jauge | le food cost d'aujourd'hui, le simulé, l'objectif |
| La matrice volume × marge | chaque produit : ses pièces par mois (échelle log) et sa marge ; la taille du point dit sa marge brute par mois. Quatre quarts : à garder, à surveiller, à pousser, à remonter. Bordeaux : le top protégé ; or : un prix qui change (un trait part d'aujourd'hui). Le survol donne le détail |
| Le food cost par catégorie | aujourd'hui et simulé, face à l'objectif, avec le CA de la catégorie |
| Produit par produit | rang, pièces par mois (avant et après l'effet volume), prix TVAC (modifiable à la main : il prime sur les leviers), prix d'achat, food cost, marge par mois, gain, ce qu'on fait. Recherche, catégorie, « prix changés », « à vérifier ». Tri par colonne |

## Les règles du calcul

- Food cost = le prix d'achat des pièces vendues ÷ le CA hors TVA, sur les produits qui ont un prix et un prix
  d'achat (220 sur 314 vendus).
- Prix d'achat : le coût de recette HT du panel (matière et emballage) pour une pièce, à Sombreffe.
- Volumes : la moyenne des 11 mois clos, décembre 2025 → octobre 2026.
- Un nouveau prix TVAC s'arrondit aux 10 centimes au-dessus.
- Un produit dont le prix d'achat dépasse le prix de vente (Melocake, Rocher Coco, deux soupes, deux mignardises)
  est mis à part, « À vérifier » : une erreur de prix en caisse ou de recette au panel.

## Ce que la maquette fait déjà voir

| Produits protégés | Hausse sur le reste pour 40 % (avec les leviers 1 et 2, −3 % de ventes pour +10 % de prix) |
|---|---|
| Top 50 | impossible : 40,5 % au mieux, même à +30 % |
| Top 20 | +22 % |
| Top 10 | +17 % |
| Aucun | +6 % |

Aujourd'hui : 43,5 % de food cost. Le top 20 porte le food cost : la baguette Tradition est à 68 %, les quiches à 63 %.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-ouverture.png` | à l'ouverture : top 20 protégé, leviers 1 et 2, −3 % de ventes pour +10 % de prix |
| `b-objectif-atteint.png` | après « Atteindre l'objectif » : +22 % sur le reste, food cost 40 % |
| `c-tableau-quiches.png` | le tableau filtré sur les quiches, trié par gain |
| `d-telephone.png`, `e-telephone-chiffres.png` | au téléphone (iPhone 13) : les leviers, puis les chiffres et la matrice |

## Refaire

```bash
python3 docs/maquettes/food-cost-sombreffe/generer.py
node docs/maquettes/food-cost-sombreffe/captures.js
```

`donnees.json` vient du cockpit (lu le 20/11/2026) : `/analyse/produits?mois=12` (les pièces de Sombreffe, mois par
mois) et `/exploitation/promos-magasin/produits?shop=…` (tarifs TVAC, TVA, coût de recette des quatre magasins).

## Pour la mettre sur le serveur

La page n'a besoin de rien d'autre qu'elle-même. Une fois validée : la poser dans `public/` (par exemple
`public/food-cost/index.html`) et l'ajouter aux alias Apache de `bin/deploy.sh`. Pour qu'elle reste à jour sans être
refaite, elle pourrait lire ses données aux deux routes du cockpit, en chemin relatif, au lieu de les porter.
