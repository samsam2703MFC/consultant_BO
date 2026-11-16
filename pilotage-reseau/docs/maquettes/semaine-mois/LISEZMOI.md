# Dashboard magasin › Semaine et Mois : plus lisible, moins de données

**Statut : maquettes du 16/11/2026, à choisir. Rien n'est codé.**

Demande (16/11/2026, sur `dashboard/?shop=5&vue=semaine`) : « proposer pour semaine et mois quelque chose de plus
lisible, moins de données (car beaucoup de redondance), 3 ou 4 maquettes en screenshots ».

## Ce qui se répète aujourd'hui

Relevé sur les vues en ligne de Sombreffe (Semaine : 4 086 px de haut, 38 titres ; Mois : 4 569 px, 40 titres) :

| Chiffre | Où il apparaît (Semaine) |
|---|---|
| Chiffre d'affaires | la frise, « Ta place dans le réseau », la tuile CA, « Comptoir » (100 % du CA), l'en-tête du calendrier, le P&L « CA encaissé » — 6 fois |
| Atteinte de l'objectif | « Ta place », la tuile Atteinte, l'en-tête du calendrier — 3 fois |
| Clients | « Ta place », la tuile Par jour ouvert, l'en-tête du calendrier, les douze semaines, les heures — 5 fois |
| Marge brute | la tuile, le P&L, la tuile « Marge brute » des heures — 3 fois |
| Invendus | la carte Invendus, la ligne du P&L — 2 fois |
| Les heures | 6 tuiles, la journée type, la bande des marges, le tableau heure par heure, le détail de l'heure — 5 façons de dire la même chose |

Dans le Mois s'ajoutent : les six leviers (food cost, labour, overhead : déjà dans le P&L), le profil des jours (déjà
dans le calendrier), « Rentabilité jour par jour » (vide : 0 % partout).

## Les quatre directions

Chacune existe en Semaine (`?p=semaine`) et en Mois (`?p=mois`) ; la frise des semaines ou des mois reste en tête
(c'est la navigation). Chaque chiffre n'y est qu'**une** fois.

- **A · Quatre blocs** (`a-quatre-blocs-*.png`) : le chiffre (une jauge CA / objectif et quatre tuiles : clients,
  panier, marge brute, résultat), les jours (CA face à l'objectif, couleur de l'atteinte, clients dessous), le résultat
  (du CA hors TVA au résultat, chaque poste face à son seuil), les heures (une bande) et ce qui rapporte. Le reste est
  replié en bas : réseau, tâches, canaux et commandes, invendus, promotions, tendance, détail heure par heure.
- **B · Tuiles et onglets** (`b-tuiles-onglets-*.png`) : six tuiles identiques, chacune face à la période d'avant et à
  N-1 ; dessous, **un seul onglet ouvert** à la fois (les jours, le résultat, les heures, canaux, invendus, réseau,
  tâches). La page la plus courte.
- **C · Le récit** (`c-recit-*.png`) : la période en une phrase (« au-dessus de l'objectif, mais à perte : les frais
  généraux pèsent 35 % pour un seuil de 13,5 % »), puis **ce qui a marché, ce qui coince, quoi faire** (trois points
  chacun, calculés sur les seuils, l'objectif du jour, le panier du réseau), et deux graphiques seulement.
- **D · Le tableau** (`d-tableau-*.png`) : un seul tableau — chaque indicateur (CA, atteinte, clients, panier, marge,
  main-d'œuvre, frais, invendus, résultat) face à la période d'avant, à N-1, à l'objectif ou au seuil, et au réseau,
  l'écart en vert ou rouge sous chaque comparaison ; puis les jours.

## Les données

Réelles, lues dans l'API du cockpit le 16/11/2026 pour Atelier by Harmonie - Sombreffe (shop 5) : la semaine S46 (09 →
15/11) et octobre 2026 — CA, objectif, jour par jour (CA, objectif, clients), clients, panier, marge brute, matière,
invendus, main-d'œuvre, frais, résultat, part pro, la frise. Réelles aussi : les clients des douze semaines et de N-1,
le CA et l'atteinte de S45 et de septembre, le panier, la marge et le résultat du réseau. **D'exemple** : les autres
comparaisons (N-1, période d'avant, réseau), les heures (la forme de la journée type), les produits, les actions de C.
La semaine choisie est S46 (complète) : la semaine en cours n'a qu'un jour.

## Fichiers

`donnees.js` (les chiffres), `blocs.js` (les blocs partagés), `sm.css`, `a-quatre-blocs.html`, `b-tuiles-onglets.html`,
`c-recit.html`, `d-tableau.html` (avec `../parametres-mails/coque.js` et `mails.css`, le rail). Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/semaine-mois/capturer.cjs
```
