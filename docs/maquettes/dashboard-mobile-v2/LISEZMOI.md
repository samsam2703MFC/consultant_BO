# Le dashboard au téléphone — reprise

La version **en ligne** ouvre sur le chiffre du jour, puis empile des cartes de
même poids. Elle répond à « combien ai-je fait ? ». Un écran qu'on ouvre vingt
fois par jour devrait plutôt répondre à « qu'est-ce qui a changé, et qu'est-ce
qui cloche ? ». Les trois propositions s'écartent chacune dans une direction :
par l'**urgence**, par la **densité**, par le **sujet**.

Écran de référence : **390 × 844**. Jour et semaine seulement, comme convenu.

## Les chiffres sont réels

Atelier by Max & Sandra — Gosselies, lus sur le serveur : `/exploitation/jour`,
`/exploitation/periode`, `/pwa/tasks`, `/pwa/tasks/nc`, `/ventes/stock`,
`/ventes/mensuel`. Le cas est parlant par lui-même :

| | |
|---|---|
| Mardi 15 septembre | 1 769,50 € pour 1 764,50 € d'objectif — **100,3 %** |
| … et pourtant | **− 25,56 €** de résultat, matière à 39,5 % |
| La semaine, au 16 | 3 279 € contre 5 196 € attendus — **− 1 917 €**, 164 clients |
| Stock | **92 références à zéro**, 95 sous leur minimum, comptées le 13/09 |
| Tâches | 17 / 21, **1 bloquante** |
| Non-conformités | 2 hier sur 8 tâches notées |
| Valeur | 105 k€ |

Une journée à l'objectif qui perd de l'argent, une semaine à mille neuf cents
de retard, quatre-vingt-douze références à zéro : c'est exactement ce qu'un
écran de téléphone doit savoir dire — et ce que la version actuelle ne dit pas
en premier.

## Les trois

| | forme | ce qu'elle coûte |
|---|---|---|
| **A** | `a-ce-qui-cloche.html` — les ennuis d'abord, classés par ce qu'ils coûtent ; le CA, les clients et la valeur descendent en bas, au calme | il faut que la hiérarchie soit juste : un écran qui crie pour rien se fait ignorer |
| **B** | `b-le-mur.html` — aucune boîte, des filets et des chiffres ; dix mesures sur un écran sans défiler ni toucher | ne hiérarchise pas pour vous : tout a le même poids, c'est à l'œil de trier |
| **C** | `c-une-page.html` — quatre pages plein écran qu'on fait glisser du pouce, un chiffre par page | la plus lisible à bout de bras, la plus lente à parcourir en entier |

`planche.html` met les trois côte à côte.

## Regénérer

```bash
node docs/maquettes/dashboard-mobile-v2/generer.js
npx http-server . -p 8099 -c-1
# → http://127.0.0.1:8099/docs/maquettes/dashboard-mobile-v2/planche.html
```

La série précédente — celle qui a donné la version en ligne — est dans
`docs/maquettes/dashboard-mobile/`.
