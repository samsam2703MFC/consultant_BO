# La modale d'un produit : ventes sur 12 semaines, prix face au réseau

**Statut : B retenue le 06/10/2026, avec seulement le magasin actif, et codée** (`GET /analyse/produits/magasin`, la modale du dashboard ; voir `docs/contrat-api.md`). Le rang et les points des autres magasins ne sont pas repris : le réseau n'y est qu'un repère anonyme.

Demande du 06/10/2026 : dans le dashboard magasin, un clic sur un produit de la liste des
catégories (groupe › catégorie › produit) ouvre une modale à deux onglets.

1. **Ventes · 12 semaines** : les ventes du produit dans le magasin, semaine par semaine.
2. **Prix face au réseau** : la position du prix encaissé face aux autres magasins, en tableau
   croisé volume × prix.

Les deux maquettes partent du même exemple réel : Cookie Chocolat Lait, Atelier by - Halle, lu
le 6 octobre 2026 en lecture seule.

| | Halle |
|---|---|
| Pièces sur 12 semaines (S29 à S40) | 321 |
| Par semaine | 26,8 |
| 6 dernières semaines face aux 6 d'avant | +23 % |
| Face à la moyenne du réseau par magasin | −19 % |
| Prix encaissé en septembre | 2,50 € |
| Prix réseau (médiane des magasins) | 2,71 € |
| Volume à taille égale face aux autres | +10 % |
| Au prix réseau, à volume égal | +24 € par mois |

## Les deux

| | onglet 1, ventes | onglet 2, prix | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|---|
| **A** | colonnes pour Halle, lignes pour la moyenne du réseau et le réseau l'an dernier ; 4 chiffres clés ; le tableau semaine par semaine (`a-semaines`) | tableau croisé 3 × 3 : prix sous, au, au-dessus du réseau × volume à taille égale moins, dans la moyenne, plus ; chaque magasin dans sa case ; la lecture et le détail (`a-prix`) | se lit d'un coup d'œil ; les chiffres de chaque semaine à l'écran | les seuils (2 % de prix, 15 % de volume) rangent les magasins en cases |
| **B** | courbe de Halle face à la bande des trois autres magasins et à leur moyenne ; le rang sur 12 semaines, en pièces et à taille égale (`b-semaines`) | nuage prix encaissé × volume à taille égale, coupé en quatre par le prix réseau et le volume moyen ; Halle en chiffres à côté ; le détail (`b-prix`) | la place exacte de chaque magasin ; le rang à taille égale | un peu plus de lecture ; les chiffres semaine par semaine au survol seulement |

Au téléphone, la modale devient une feuille plein écran (`*-tel.png`).
`planche.html` / `planche.jpg` : les deux côte à côte.

## Les données, déjà en ligne

- Ventes par semaine et par magasin : `GET /analyse/produits?mois=3` (la grille « Par
  référence » du cockpit) ; le réseau l'an dernier : `GET /analyse/produits?mois=3&pid=`.
- Prix encaissé, volume à taille égale, prix réseau, gain au prix réseau :
  `GET /analyse/prix-volume?mois=1|3|12` (l'écran « Prix × volume » du cockpit, mois clos).
- Les couleurs des séries viennent de la palette validée (bleu pour le magasin, orange pour le
  réseau, vert pour l'an dernier) ; aucune couleur ne porte seule une information.

## Régénérer

```
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node docs/maquettes/modale-produit/generer.js
node docs/maquettes/modale-produit/capturer.js
```
