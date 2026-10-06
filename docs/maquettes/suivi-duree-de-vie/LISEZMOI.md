# Suivi de production : short life, medium life, long life

**Statut : maquettes à choisir, rien n'est codé.**

Demande du 06/10/2026 : garder seulement la vue Suivi de la production
(`/production/?shop=4&date=2026-10-06&page=suivi`) et y ajouter trois interrupteurs, short life,
medium life et long life, qui viennent d'un paramètre produit nouveau : la durée de vie.

Dans les trois maquettes, la page Suivi est seule : les quatre autres étapes (Paramètres, Plan de
production, Clôture, Fours) ne sont plus dans le menu. Le tableau de surveillance est celui
d'aujourd'hui, avec ses heures, ses verdicts et ses conseils.

## La durée de vie, un paramètre produit

| | Short life | Medium life | Long life |
|---|---|---|---|
| Ce que c'est | vendu le jour même | se garde 2 à 3 jours | se garde une semaine et plus |
| Produits suivis à Halle | 29 | 29 | 11 |
| Ce qui reste le soir | part à la poubelle | se garde pour demain | reste en stock |
| Suivi à l'heure | oui | oui | non, un stock à tenir |
| Le verdict « trop produit » devient | jeté ce soir | se garde demain | en stock |

Elle se règle par catégorie, avec des exceptions par produit. Le classement de départ est une
proposition :

- **Short life** : viennoiserie, viennoiserie réduction, petite boulangerie, sandwichs garnis,
  wraps, pâtisserie individuelle, entremets individuels.
- **Medium life** : pains, pains tradition, tartes, tartissières, quiches, entremets à partager,
  salades féculentes, plats préparés.
- **Long life** : cookies et cakes, les deux catégories que Halle a déjà réglées « se garde au
  lendemain » ; et deux exceptions produit, Brownies et Brookie, sortis des tartes.

Le réglage existant « se garde au lendemain » (Paramètres, par catégorie) devient la durée de vie :
coché, il part en long life ; décoché, en short ou medium selon le rayon.

## Les trois

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **A** | `a.html` : **trois interrupteurs** au-dessus du tableau, short et medium allumés, long éteint ; une pastille S, M ou L devant chaque produit ; le réglage dans un panneau (`a-reglage.html`) | le tableau d'aujourd'hui, à peine changé ; on combine librement | les classes se mélangent dans un seul tableau |
| **B** | `b.html` : **trois blocs** dans le tableau, chacun avec son interrupteur, sa règle et ses compteurs ; un bloc fermé garde sa ligne de résumé (`b-long.html` : long life ouvert) | chaque famille se lit à part | plus long quand tout est ouvert ; le réglage reste ailleurs |
| **C** | `c.html` : **trois onglets** ; le long life a son propre tableau, un stock en jours de vente, sans les heures (`c-long.html`) ; un S · M · L sous chaque produit change sa durée de vie d'un clic | chaque classe a la lecture qui lui convient ; le réglage sur la ligne | une classe à la fois |

`planche.html` / `planche.jpg` : les trois côte à côte.

## Données

Atelier by - Halle (shop 4), le suivi de production du mardi 6 octobre 2026 à 10:45, lu en lecture
seule (`/production/flux/suivi`, `reel-suivi-halle-0610.json`) : 69 produits, 412 pièces sorties,
273 vendues. Les paramètres actuels lus sur `/production/flux/params`. Aucun nom de personne.

## Régénérer

```
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node docs/maquettes/suivi-duree-de-vie/generer.js
node docs/maquettes/suivi-duree-de-vie/capturer.js
```
