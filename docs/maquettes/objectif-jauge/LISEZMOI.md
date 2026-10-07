# L'objectif en jauge pleine largeur, les vignettes face à la semaine passée

**Statut : maquettes à choisir (07/10/2026), rien n'est codé.**

Demande du 07/10/2026 : « dans le dashboard magasin, mettre l'objectif en jauge sur toute la
largeur (faire maquette) et retravailler ces vignettes dans Opérationnel : important de savoir
comment on se positionne par rapport à la semaine passée ».

## Le problème que les maquettes corrigent

Les six vignettes d'aujourd'hui (CA du jour, marge brute, clients, panier moyen, projection,
résultat net) comparent le chiffre du moment à la **journée entière** du dernier même jour de
semaine. À 15 h, un CA de 1 199 € face aux 1 453 € de tout mercredi dernier donne « − 17,5 % »,
ce qui ne dit rien. Seule la vignette Clients compare déjà à J−7 **à la même heure** (+ 30).

Les deux maquettes comparent d'abord **à la même heure de la semaine passée**, puis à la journée
entière, puis à la référence (la moyenne des 6 derniers mêmes jours).

## Maquette A — une jauge, puis six vignettes

`a.html`, captures `a.png` (bureau 1440) et `a-tel.png` (téléphone 390).

- **La jauge pleine largeur** : l'objectif du jour de 0 à 100 %. Le réalisé en rubis, la projection
  au rythme de la journée en hachures, deux repères bleus pour mercredi dernier (à la même heure,
  et sa journée), un trait pointillé pour la part de la journée écoulée. À gauche le chiffre et le
  pourcentage réalisé, le verdict face à J−7 à la même heure en couleur, à droite ce qu'il reste.
- **Les six vignettes** gardent leur place mais changent de sens : sous chaque chiffre, l'écart
  face à J−7 à la même heure en pastille colorée, puis la journée de J−7 et la référence en une
  ligne, et une courbe des 7 derniers jours pour le CA et les clients. La marge brute se compare en
  points, la projection à la journée entière de la semaine passée.

## Maquette B — le duel avec mercredi dernier

`b.html`, captures `b.png` et `b-tel.png`.

- **Deux pistes** sur la même échelle : aujourd'hui (réalisé + projection) et mercredi dernier (à
  la même heure, puis sa journée en hachures bleues), le curseur du temps sur les deux. Une ligne
  de verdict résume les écarts CA, clients, panier, projection.
- **Heure par heure** : le cumul d'aujourd'hui face à celui de mercredi dernier, de l'ouverture
  à la fermeture, jusqu'à la ligne de l'objectif. On voit où la journée a décroché.
- **Le duel, chiffre par chiffre** : une ligne par indicateur avec aujourd'hui, mercredi dernier
  à la même heure, l'écart, la journée de mercredi dernier, la référence. Sur téléphone, la ligne
  devient une carte.

## Les chiffres sont réels

Atelier by - Halle (shop 4), mercredi 7 octobre 2026, lus en lecture seule à 15:12
(`reel-halle-0710.json` : `/exploitation/jour` du jour, `/ventes/stats` du jour et du 30/09, heure
par heure). Aucun nom de client ni de membre de l'équipe. « Mercredi dernier à la même heure » se
calcule depuis les ventes heure par heure du 30/09 : les heures pleines avant 15 h et 12/60 de
l'heure de 15 h, comme le serveur le fait pour les clients à J−7.

| À 15:12 | Aujourd'hui | Mercredi dernier, même heure | Écart | Mercredi dernier, journée |
|---|---|---|---|---|
| CA | 1 199 € | 1 219 € | − 1,7 % | 1 453 € |
| Clients | 122 | 92 | + 32 % | 111 |
| Panier moyen | 9,83 € | 13,20 € | − 25,5 % | 13,09 € |
| Marge brute | 60,8 % | 70,8 % | − 10 pts | 73,3 % |
| Projection | 1 304 € | — | − 10,3 % vs la journée | 1 453 € |

Deux lectures distinctes du jour diffèrent de 3 € (1 199 € dans `/exploitation/jour`, 1 202 €
dans `/ventes/stats` lu quelques secondes plus tard) : la courbe heure par heure porte la seconde.

## Pour coder

- Serveur : `/exploitation/jour` rend déjà `j7 {ca, tickets, moment}` ; il manque, à la même heure,
  la marge brute et le panier de J−7 (lisibles dans `/ventes/stats` du jour J−7, heure par heure)
  et le cumul heure par heure des deux jours pour la courbe de la maquette B.
- Dashboard : `public/dashboard/dashboard.js`, `jourPieces` (les tuiles `tuileTend`, la carte
  objectif `db-bar`) et `opTuiles` ; la comparaison « vs 30/09 » des tuiles passe à J−7 même heure.

## Régénérer

```
node docs/maquettes/objectif-jauge/generer.js
php -S 127.0.0.1:8099 -t .  &&  node docs/maquettes/objectif-jauge/capturer.js
```
