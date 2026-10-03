# Ventes par catégorie : pourcentages entiers, coefficient à côté du taux — maquette

Dans le dashboard magasin (vue Jour, carte « Ventes par catégorie », liste),
deux changements :

1. **Les pourcentages en nombres entiers** : la part dans le CA et le taux de
   marge brute se lisent « 23 % », « 59 % » — plus « 23,1 % », « 59,5 % ».
2. **Le coefficient à côté du taux**, nouvelle colonne « Coef » : CA ÷ coût
   matière, au format « × 2,43 », de la couleur du taux (le même palier). Un
   taux de marge de 59 % est un coefficient de 2,43 ; 47 % → 1,88 ; une marge
   négative donne un coefficient sous 1 (Tartes : × 0,58, vendu sous le coût
   matière). Sans coût matière connu : « ? », comme la marge.

Le coefficient est posé à chaque niveau — groupe, catégorie, produit — et sur
le total. La ligne d'aide de la carte le dit : « coef : CA ÷ coût matière ».

| Fichier | Ce que c'est |
|---|---|
| `a.jpg` | La carte, groupes repliés |
| `a-ouvert.jpg` | Viennoiserie ouvert, puis sa première catégorie : le coef à chaque niveau |
| `avant.jpg` | La carte telle qu'elle est aujourd'hui |
| `a.html` | Le DOM de la carte transformée |

Données réelles : Gosselies (shop 3), vendredi 02/10/2026, tickets du jour.
La maquette est construite sur la page en ligne du dashboard, en lecture
seule : le DOM de la carte est transformé dans le navigateur (pourcentages
arrondis, colonne ajoutée, grille élargie d'une colonne de 66 px), puis
photographié. Régénérer : `node docs/maquettes/ventes-coef/generer.js`.

**Codée et déployée le 03/10/2026** : `fP0` (pourcentage entier) pour la part
et le taux, le coefficient (`coef`, `coefTxt`) en huitième colonne de
`ligne()`, de l'en-tête et du pied dans `accordeon()` de `dashboard.js`, la
grille `.db-ent, .db-al` élargie dans `dashboard.css` (bureau et fenêtre
étroite). Le treemap ne change pas.
