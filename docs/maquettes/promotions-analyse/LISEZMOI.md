# Maquettes « Tableau d'analyse des promotions, plus visuel » (26/10/2026)

Demande (sur `#/promotions` en ligne) : « ce n'est pas très visuel, je ne sais pas quel magasin en vend combien par
jour, quel est le volume par rapport au CA ». Trois ou quatre propositions en captures, on décide ensuite.

Les quatre gardent la coque du cockpit, le menu Promotions du rail et la même barre : **Par magasin** (la nouvelle
vue) ou **Toutes les promotions (liste)** (le tableau en ligne aujourd'hui, inchangé) ; € · Pièces · % du CA ; 7 ou
30 jours ; une promotion ; marque et magasins. Les mêmes quatre chiffres en tête : ce que le réseau vend en promotion
par jour (et en pièces), la part du CA, un magasin en moyenne, celui qui en vend le plus.

## Les quatre formes

| | Forme | Ce qu'on voit | Pour | Contre |
|---|---|---|---|---|
| A | La carte des jours (`a.png`) | une ligne par magasin, une case par jour (plus foncée = plus vendu en promotion ce jour-là), une ligne Réseau ; à droite €/jour, part du CA, pièces/jour ; l'infobulle d'une case donne le jour, le CA du jour et chaque promotion | les deux questions sur un écran ; les jours forts et les trous sautent aux yeux | la valeur exacte d'une case est dans l'infobulle |
| B | La promotion dans le CA (`b.png`) | le réseau jour par jour (colonnes, Halloween marqué) ; puis par magasin la barre du CA d'un jour moyen avec la part vendue en promotion, « 153 € sur 3 257 € », la jauge part du CA, la courbe des 30 jours à la même échelle | le volume face au CA en vraie proportion ; trié par part du CA | la part est petite (2 à 5 %) : il faut la jauge pour la lire |
| C | Un magasin, une carte (`c.png`) | neuf petits graphiques à la même échelle : les 30 jours en colonnes face à la moyenne d'un magasin, €/jour, part du CA, pièces, et les trois promotions qui vendent le plus | le plus visuel magasin par magasin ; on voit quelle promotion fait le chiffre | plus long : il faut faire défiler |
| D | Où ça marche (`d.png`) | chaque magasin placé selon son CA par jour (x) et ses ventes en promotion par jour (y), les diagonales 2, 4, 6 % du CA, la moyenne du réseau ; à côté le classement (barre, part du CA, courbe) | compare d'un coup d'œil des magasins de tailles différentes | pas le détail jour par jour, seulement la courbe du classement |

`planche.png` : les quatre côte à côte. `index.html` : une page par forme (`#a` … `#d`, `#planche`).

Une seule teinte (le rubis du cockpit, du clair au foncé) pour les quantités ; le gris chaud pour le CA et le contexte ;
un seul axe par graphique ; les petits graphiques de B, C et D à la même échelle.

## Réel et illustré

**Illustré** : les chiffres viennent de `docs/maquettes/promotions-cockpit/donnees.cjs` (les promotions du réseau et
leurs ventes sur 30 jours, le CA des magasins), déroulés jour par jour au rythme de la semaine.

**Ce que le serveur sait déjà** : `GET /promotions/reseau` (`src/promos_reseau.php`) lit, magasin par magasin et jour par
jour, les ventes reconnues dans les tickets (`svP{shop}:{date}`) et le CA lu ; il les additionne aujourd'hui sur la
période. Quelle que soit la forme, il faudra qu'il rende aussi la série du jour par magasin (ventes en promotion,
pièces, CA du jour, et par promotion pour l'infobulle de A et le top 3 de C) : `prMagasin` parcourt déjà ces jours.

## À trancher

1. **La forme** : A, B, C, D, ou un mélange (par exemple la carte des jours de A avec le nuage de D).
2. **La vue par défaut** : « Par magasin » (la nouvelle) ou la liste d'aujourd'hui.

## Régénérer

```
php -S 127.0.0.1:8099 -t .                              # à la racine du dépôt
node docs/maquettes/promotions-analyse/generer.cjs      # → index.html, a.png … d.png, v-*.png, planche.png
```
