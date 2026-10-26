# Maquettes « Quelle promotion, dans quel magasin, quel % du CA général » (26/10/2026)

Les quatre formes de `docs/maquettes/promotions-analyse` sont écartées (« aucun, recommence »). La demande : « je veux
savoir quelle promotion est utilisée dans quel magasin et quel est le % sur le CA général ».

Les quatre gardent la coque du cockpit, le menu Promotions du rail et la même barre : **Qui utilise quoi** (la nouvelle
vue) ou **Toutes les promotions (liste)** (le tableau en ligne aujourd'hui, inchangé) ; ce qu'on lit (% du CA du
magasin · % du CA général · € · fois) ; 7 ou 30 jours ; comptoir et webshop ; marque et magasins. Les mêmes quatre
chiffres en tête : les promotions utilisées, ce qu'elles pèsent dans le CA général, celle qui pèse le plus, les
promotions en caisse sans vente (et où).

**Deux pourcentages**, toujours nommés :
- **% du CA général** : ce que la promotion a vendu ÷ tout ce que les neuf magasins ont vendu sur la période, comptoir et
  webshop (677 500 € sur 30 jours dans l'exemple) ;
- **% du CA du magasin** : ce qu'elle a vendu dans un magasin ÷ le CA de ce magasin.

**Trois états** pour une promotion dans un magasin : utilisée (son %), en caisse mais pas utilisée (0, en ambre), pas
dans ce magasin (·).

## Les quatre formes

| | Forme | Ce qu'on voit | Pour | Contre |
|---|---|---|---|---|
| A | La matrice (`a.png`) | une ligne par promotion, une colonne par magasin ; la case = % du CA du magasin (plus foncée = pèse plus) ; à droite ventes et % du CA général ; en bas, par magasin, ce que toutes ses promotions pèsent dans son CA | les deux réponses dans le même tableau ; les cases vides et les promotions sans vente se voient | les petits pourcentages se lisent dans la case |
| B | Promotion par promotion (`b.png`) | une barre par promotion à l'échelle du CA général, découpée par magasin (du plus gros au plus petit) ; où elle est utilisée, où elle ne vend pas ; ventes, fois | le % du CA général se voit en longueur ; trié de la plus forte à la plus faible | le poids dans le CA de chaque magasin n'y est pas |
| C | Magasin par magasin (`c.png`) | une carte par magasin : la part de son CA en promotion, puis chacune de ses promotions (les siennes et celles de la marque), barres à la même échelle | ce qui marche chez chaque franchisé | une promotion de la marque se lit en neuf endroits |
| D | Une promotion à la fois (`d.png`) | à gauche la liste avec le % du CA général ; à droite la promotion choisie : % du CA général, fois, part du webshop, magasins, remise, puis magasin par magasin | le plus de détail ; les magasins où elle n'est pas sont nommés | une seule promotion à la fois |

`planche.png` : les quatre côte à côte. `index.html` : une page par forme (`#a` … `#d`, `#planche`).

## Réel et illustré

**Illustré** : `docs/maquettes/promotions-cockpit/donnees.cjs` (les promotions du réseau, leurs ventes et leurs fois
sur 30 jours, magasin par magasin pour celles de la marque, le CA des magasins), plus un bon de la marque (AUTOMNE) en
caisse dans cinq magasins et sans vente dans deux, pour montrer cet état.

**Ce que le serveur sait déjà** : `GET /promotions/reseau` (`src/promos_reseau.php`) lit, magasin par magasin, les ventes
reconnues dans les tickets et le CA lu, et rend par promotion ses fois et ses ventes. Pour ces vues il faut aussi, par
promotion, le détail par magasin (fois, ventes) et, par magasin, son CA : `prMagasin` les calcule déjà, `prReseau` les
additionne.

## À trancher

1. **La forme** : A, B, C, D, ou un mélange (par exemple la matrice de A, un clic sur une ligne ouvrant le détail de D).
2. **La vue par défaut** : « Qui utilise quoi » ou la liste d'aujourd'hui.

## Régénérer

```
php -S 127.0.0.1:8099 -t .                                 # à la racine du dépôt
node docs/maquettes/promotions-utilisation/generer.cjs     # → index.html, a.png … d.png, v-*.png, planche.png
```
