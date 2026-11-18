# Distinguer le comptoir et le webshop dans la jauge du jour — trois maquettes

**Statut : proposées le 18/11/2026, à choisir.** Rien n'est codé.

Demande du 18/11/2026, sur la capture de Halle à 17:43 (onglet Opérationnel, au téléphone) : « rajouter une façon
de différencier les ventes comptoir et les ventes webshop, fais 2-3 maquettes en screenshots ».

## Ce qui est en ligne

La jauge « Objectif comptoir » compte **toute la caisse moins les tickets pro** (`opDuelSplit`, `dashboard.js`). Les
commandes encaissées au magasin, prises au comptoir comme sur le webshop, y sont donc fondues. Seule la ligne
« Commandes du jour » (comptoir 1 · webshop 1 · 15,60 €) dit qu'il y en a. Un webshop **payé en ligne**, sans ticket de
caisse, n'y entre pas du tout. À Halle, toutes les commandes webshop lues depuis le 20/10 sont encaissées en caisse.

## Les trois

| | Écran | Ce qu'elle coûte |
|---|---|---|
| 0 | `ecran-0.png` : ce qui est en ligne, redessiné d'après la capture | — |
| **A** | `ecran-a.png` : **la barre en deux matières**. La même jauge. La couleur dit le jour (or aujourd'hui, bleu mercredi dernier), la hachure au bout de la barre dit le webshop. Le partage s'écrit sous chaque piste, une légende sous l'axe. | Rien de plus à lire. Mais un webshop de 5,80 € sur 2 124 € n'est qu'un trait : la maquette lui garde 5 px de large au moins. Elle vaut pour un magasin dont le webshop pèse quelques pour cent. |
| **B** | `ecran-b.png` : **une jauge par canal**. Le webshop a sa carte, comme le B2B, à son échelle, face à sa part habituelle. L'objectif du jour se partage en trois : comptoir 1 824 €, webshop 36 €, B2B 34 €. Chaque carte a sa ligne de commandes. | La plus lisible, et le webshop se juge face à lui-même. Il faut faire défiler un écran de plus, et l'objectif comptoir change (1 860 € → 1 824 €). |
| **C** | `ecran-c.png` : **le ruban des canaux**. La jauge reste celle d'aujourd'hui. Dessous, une barre de la part de chaque canal, puis trois colonnes : comptoir (vente directe), commandes prises au comptoir, webshop, chacune face à mercredi dernier. Elle remplace la ligne « Commandes du jour » ; toucher une colonne ouvre la feuille des commandes sur ce canal. | Lisible même pour un webshop de 0,3 %. Il faut lire des chiffres, pas une forme. |
| | `planche.jpg` : les quatre côte à côte | |

**Recommandation : C.** Elle dit le partage chiffré, même quand le webshop est minuscule, et ne touche ni à la jauge
ni à l'objectif. C'est aussi elle que reprend la refonte du téléphone (`../dashboard-mobile-v3/`), avec une quatrième
case pour le B2B.

## Réel, calculé

**Réel** : les jauges de la capture du mercredi 18/11 à 17:43 (comptoir 2 124 € pour 1 860 € d'objectif ; mercredi
11/11 à 17:42 3 473 €, la journée 3 493 € ; B2B 116 € pour 34 €). Les commandes ont été lues au panel
(`/shops/4/client-orders`, seuls les montants et les heures sont gardés) :
- le 18/11 : une commande prise au comptoir (9,80 €, retrait 08:30) et un click & collect (5,80 €, 13:30), tous
  deux encaissés en caisse ;
- le 11/11 : cinq commandes comptoir (271,80 €) et deux click & collect (76,18 €), toutes avant 17:42.

**Calculé par la maquette** :
- la vente directe, soit la caisse hors pro moins ces commandes : 2 108,40 € aujourd'hui, 3 125,02 € le 11/11 ;
- l'objectif webshop de B (36 €), la moyenne du webshop des quatre derniers mercredis lus au panel (0 €, 57,90 €,
  9,70 €, 76,18 €).

Le 11/11 était férié en Belgique : c'est ce qui explique ses 188 %.

## Ce qu'il faudra pour coder

- **Le partage à l'heure** : `/exploitation/canaux` (`src/canaux_offres.php`, `coMagasin`) donne le comptoir, le click
  & collect et la livraison **sur la journée**. La jauge compare à J−7 **à la même heure** : il faut couper les
  commandes à l'heure du retrait (`pick_up_datetime`), aujourd'hui comme à J−7. Le panel la donne.
- **Le webshop payé en ligne** (sans `id_transaction`) n'est pas dans la caisse : A et C doivent l'ajouter au total
  de la jauge, sinon il manque. `coMagasin` sait déjà le séparer (`encaissee`).
- **B seulement** : la part habituelle du webshop, comme `partProHabituelle` pour le B2B (les quatre derniers mêmes
  jours lus).
- Couleurs des canaux : celles de la jauge « Comment le client achète » de la fiche client (`FD_CANAUX`) : comptoir or
  `#B0792A`, commande comptoir bordeaux `#8D1D2C`, webshop bleu `#1B6FA8`.

## Régénérer

```
node pilotage-reseau/docs/maquettes/comptoir-webshop/capturer.js
```

Aucun serveur à lancer : le navigateur lit `pilotage-reseau/` sur le disque. `index.html?v=0`, `?v=a`, `?v=b`,
`?v=c`, `?v=planche`. `mq.css` reprend les règles du dashboard déployé (en-tête du téléphone, jauges, onglets) et
ajoute les trois façons.
