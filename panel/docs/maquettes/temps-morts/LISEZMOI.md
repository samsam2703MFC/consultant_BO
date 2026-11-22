# Les temps morts au comptoir

**Statut : maquette du 07/11/2026, calculée sur les ventes réelles.** Le même jour, l'onglet
**« Temps morts » du dashboard magasin** est codé (`public/dashboard/?vue=morts`) : le jour et sa semaine
pour un magasin, **mesurés sur l'heure de chaque ticket** — la liste des tickets du panel la porte, ce que
cette maquette ignorait. La vue réseau ci-dessous, à plusieurs magasins, reste une maquette.

Demande du 07/11/2026 : « calcule-moi le nombre de temps morts par jour et en % par heure dans chaque
magasin, en moyenne, tous les jours de la semaine, et fais-en une maquette ».

Place proposée : **Cockpit › Magasins › Temps morts**, à côté de *Jours creux*. Les jours creux disent
où le magasin vend peu ; les temps morts disent combien de temps le comptoir attend un client. La liste
« Les heures les plus mortes » renvoie vers le générateur de promotions des jours creux.

## Ce qu'est un temps mort

**Un trou d'au moins 10 minutes entre deux tickets qui se suivent**, entre le premier et le dernier ticket
de la journée. Le seuil se règle à 5, 10 ou 15 minutes en haut de l'écran.

- **Nombre par jour** : combien de trous de 10 minutes ou plus dans une journée.
- **Temps sans client** : la durée totale de ces trous (toute leur durée compte, pas seulement ce qui
  dépasse 10 minutes).
- **% par heure** : la part des minutes ouvertes de l'heure passée dans un de ces trous.

## Les chiffres — seuil 10 minutes

8 semaines pleines, du lundi 07/09 au dimanche 01/11/2026 : 56 journées par magasin, chaque jour de
semaine compté 8 fois. Les quatre magasins ouverts (Gembloux est fermé).

**Temps morts par jour · temps sans client**

| Magasin | Lun | Mar | Mer | Jeu | Ven | Sam | Dim | Moyenne |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Corbais | 14,6 · 3 h 59 | 12,4 · 3 h 11 | 14,1 · 3 h 48 | 14,1 · 3 h 51 | 10,8 · 2 h 45 | 10,3 · 2 h 41 | 6,6 · 1 h 32 | **11,8 · 3 h 07** |
| Gosselies | 20,4 · 5 h 50 | 18,7 · 5 h 02 | 18,1 · 4 h 52 | 18,5 · 5 h 02 | 18,7 · 5 h 08 | 17,6 · 4 h 46 | 12,0 · 3 h 15 | **17,7 · 4 h 51** |
| Halle | 20,3 · 5 h 41 | 20,8 · 5 h 57 | 19,2 · 5 h 08 | 21,3 · 6 h 02 | 17,7 · 4 h 45 | 14,7 · 4 h 00 | 14,1 · 3 h 54 | **18,3 · 5 h 04** |
| Sombreffe | 20,6 · 5 h 40 | 20,8 · 5 h 34 | 20,0 · 5 h 37 | 21,6 · 6 h 05 | 21,4 · 6 h 06 | 13,0 · 3 h 27 | 14,0 · 4 h 11 | **18,8 · 5 h 14** |

| Magasin | Part de l'ouverture sans client | dont ouverture et fermeture | Plus long trou du jour | Tickets / jour |
|---|---:|---:|---:|---:|
| Corbais | 25 % | 54 min | 31 min, vers 17 h | 291 |
| Gosselies | 45 % | 57 min | 35 min, vers 6 h | 131 |
| Halle | 43 % | 1 h 01 | 36 min, vers 16 h | 147 |
| Sombreffe | 45 % | 57 min | 37 min, vers 17 h | 144 |

**Part de chaque heure passée sans client, moyenne des sept jours**

| Magasin | 6 h | 7 h | 8 h | 9 h | 10 h | 11 h | 12 h | 13 h | 14 h | 15 h | 16 h | 17 h | 18 h |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Corbais | 35 %* | 15 % | 10 % | 15 % | 18 % | 2 % | 1 % | 8 % | 32 % | 41 % | 43 % | 54 % | 83 %* |
| Gosselies | 61 %* | 42 % | 31 % | 38 % | 43 % | 28 % | 19 % | 44 % | 55 % | 61 % | 64 % | 74 %* | — |
| Halle | 49 %* | 29 % | 24 % | 28 % | 29 % | 25 % | 23 % | 53 % | 58 % | 64 % | 73 % | 75 %* | — |
| Sombreffe | 62 %* | 35 % | 27 % | 37 % | 37 % | 24 % | 15 % | 47 % | 66 % | 63 % | 57 % | 60 % | 86 %* |

\* heure d'ouverture ou de fermeture, surestimée si le magasin ouvre ou ferme à la demi-heure (voir
plus bas). Le détail jour de semaine × heure est dans la maquette, un magasin par onglet.

**Selon le seuil** (temps morts par jour · temps sans client · part de l'ouverture)

| Magasin | 5 min | 10 min | 15 min |
|---|---:|---:|---:|
| Corbais | 41,2 · 6 h 29 · 52 % | 11,8 · 3 h 07 · 25 % | 4,4 · 1 h 38 · 13 % |
| Gosselies | 44,8 · 8 h 03 · 74 % | 17,7 · 4 h 51 · 45 % | 7,7 · 2 h 50 · 26 % |
| Halle | 47,2 · 8 h 27 · 72 % | 18,3 · 5 h 04 · 43 % | 8,1 · 3 h 00 · 26 % |
| Sombreffe | 47,2 · 8 h 35 · 73 % | 18,8 · 5 h 14 · 45 % | 8,4 · 3 h 09 · 27 % |

## Ce qui se lit

- **L'après-midi, de 14 h à 18 h, est le grand creux** des quatre magasins : 42 % du temps sans client
  à Corbais, 62 à 67 % ailleurs. Un tiers (Gosselies) à plus de la moitié (Corbais) des temps morts de
  la journée y commencent.
- **Le midi est plein** : de 11 h à 13 h, 1 % à Corbais, 20 à 24 % ailleurs. Le matin, de 7 h à 11 h,
  est entre les deux : 15 % à Corbais, 27 à 38 % ailleurs.
- **Le week-end remplit le comptoir**, surtout à Sombreffe : 13 à 14 temps morts le samedi et le
  dimanche contre 20 à 22 en semaine. Gosselies garde ses temps morts le samedi (17,6).
- **Corbais vend deux fois plus que les autres** (291 tickets par jour contre 131 à 147) et a
  presque deux fois moins de temps mort : 25 % de l'ouverture contre 43 à 45 %.
- **Repère** : sous 10 tickets dans l'heure, plus de la moitié de l'heure se passe sans client
  (seuil 10 min). C'est le nombre de clients à viser sur les heures creuses.

## Comment c'est calculé — et ses limites

Le panel donne le **nombre de tickets de chaque heure, jour par jour** (`hourly-distribution`, lu par
`/ventes/periodes`), mais aucune lecture du BO ne rend la **minute** de chaque ticket. Le calcul
(`calculer.js`) répartit donc les tickets de chaque heure au hasard dans cette heure, 500 fois par
journée, et garde la moyenne. À nombre de tickets connu, c'est la répartition la plus neutre. Le
générateur est celui du seed (mulberry32) : le même `heures.json` donne toujours le même `data.json`.

- **Juste en moyenne, approché sur un jour** : vérifié le 07/11/2026 sur les tickets réels (l'onglet du
  dashboard), la semaine du 26/10 au 01/11, les quatre magasins, mêmes jours : **423 temps morts mesurés
  pour 422 estimés**, et 3 % de minutes mortes en moins mesurées qu'estimées. Sur un jour donné, l'écart
  va jusqu'à ±20 % : les clients de Halle arrivent plus groupés que le tirage (temps morts réels plus
  nombreux), ceux de Sombreffe plus réguliers (moins nombreux).
- **Bornes de la journée** : du premier au dernier ticket. L'attente avant le premier client du matin ne
  compte pas, ce qui suit le dernier ticket non plus. Un magasin qui ferme plus tôt ne compte donc pas de
  temps mort après sa fermeture : Gosselies ferme à 16 h depuis le 19/10, et Gosselies a fermé à 13 h le
  01/11 (Toussaint).
- **Heures retenues** : une heure compte si le magasin y vend plus d'un jour sur deux ce jour de semaine,
  et 2 tickets au moins en moyenne. Sont écartés : les tickets isolés à 5 h (Corbais, 1,5 ticket le
  vendredi), après 19 h, et la dernière heure trop maigre (Corbais le mardi à 18 h, Sombreffe le lundi à
  18 h).
- **Heures d'ouverture et de fermeture surestimées** : si le magasin ouvre à 6 h 30 ou ferme à 18 h 30,
  le tirage étale les tickets de l'heure sur 60 minutes au lieu de 30, et allonge les trous. Ces heures
  portent 18 à 29 % des minutes mortes (seuil 10 min) : leur part est donnée à côté de chaque total, elles
  sont marquées d'un astérisque, et elles sont écartées de « Les heures les plus mortes ».

**Mesurer au lieu d'estimer** : c'est ce que fait l'onglet « Temps morts » du dashboard magasin. La liste des
tickets d'un jour (`/shops/{id}/transactions?date=`, un appel) porte l'heure de chaque ticket ; le BO la grave sous
`tmT{shop}:{date}` (`src/temps_morts.php`). Les horaires d'ouverture de chaque magasin, s'ils étaient saisis,
corrigeraient encore le début et la fin de journée.

## Les fichiers

| Fichier | Rôle |
|---|---|
| `heures.json` | tickets et CA par heure, jour par jour, 4 magasins × 56 jours, lus le 07/11/2026 sur le BO en production |
| `calculer.js` | le calcul : `heures.json` → `data.json`, et le résumé ci-dessus dans la console |
| `data.json` | par magasin, jour de semaine et heure, pour les seuils 5, 10 et 15 min |
| `index.html` | la maquette : `?v=reseau` (défaut) ou `?v=2`, `3`, `4`, `5` (un magasin), `&seuil=5\|10\|15` |
| `reseau.png`, `corbais.png`, `gosselies.png`, `halle.png`, `sombreffe.png` | captures à 1440 px, seuil 10 min |

## Régénérer

```
node pilotage-reseau/docs/maquettes/temps-morts/calculer.js
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node pilotage-reseau/docs/maquettes/temps-morts/capturer.js
```

Pour relire les ventes (8 nouvelles semaines), une lecture par magasin et par jour :
`GET /api/cockpit/ventes/periodes?shop={2|3|4|5}&vue=jour&date=AAAA-MM-JJ`, la clé `heures` de chaque
réponse rangée dans `heures.json` sous `jours.{shop}.{date}.{heure} = [tickets, ca]`.
