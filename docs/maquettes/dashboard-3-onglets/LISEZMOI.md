# Le dashboard magasin au téléphone, en trois onglets

Demande du 04/10/2026 : diviser le dashboard magasin de l'application téléphone en trois onglets,
**Exploitation** (chiffre d'affaires, marges…), **Contrôle** (photos, stock…) et **Semaine** (la
semaine en une vue, pour tout vérifier), les photos en carrousel. Le but : que l'opérateur sache en
deux ou trois coups d'œil dans quel état est son magasin.

Aujourd'hui, le téléphone a trois onglets : Le jour, La semaine et Réclamation. Le jour empile tout
sur un seul mur, environ trente cellules, photos comprises. Dans les trois maquettes, la réclamation
fournisseur devient un bouton de l'onglet Contrôle.

Écran de référence : **390 × 844**. Chaque onglet tient sur l'écran, sans défiler (vérifié à la
capture).

## Les chiffres sont réels

Atelier by - Halle (shop 4), lus sur le serveur le 4 octobre 2026 :

| | |
|---|---|
| Samedi 3 octobre | 2 683 € pour 2 326 € d'objectif, **115 %**, résultat **+ 730 €** |
| … mais | matière **39,6 %**, au-dessus du seuil de 35 % |
| Contrôles | 8 rendus sur 11 : manquent Biscuiterie, Pâtisseries, Traiteur ; 7 photos notées 4 / 5, 1 à contrôler |
| Commandes clients | 4 à retirer, **12 en retard** |
| Stock | 632 références, rien à zéro, compté le 01/10 |
| Semaine, au samedi | 10 156 € pour 11 581 € attendus, **− 1 425 €** ; lundi à mercredi sous 80 %, vendredi et samedi au-dessus |
| Poubelle de la semaine | 168 € de coût, 208 pièces, dont **90 € le vendredi** |

Lectures : `/exploitation/jour`, `/exploitation/periode?vue=semaine`, `/exploitation/invendus` jour
par jour, `/pwa/tasks` jour par jour, `/pwa/tasks/photos`, `/ventes/stock`, `/ventes/commandes`,
`/ventes/semaines`. Les photos de `photos/` sont celles rendues ce samedi-là.

## Les trois

| | forme | ce qu'elle coûte |
|---|---|---|
| **A** | `a.html` — **les feux** : une tuile par mesure, une pastille de couleur par tuile, la couleur de chaque onglet dans la barre du bas ; Semaine en damier jour × mesure | la couleur dépend de seuils : ils doivent être justes, sinon tout est orange et plus rien ne se lit |
| **B** | `b.html` — **le mur** : la suite de la version en ligne, sans cadre, des chiffres et des filets ; une photo en grand avec ‹ › ; Semaine en tableau chiffré | le plus complet, mais c'est à l'œil de trier : il faut lire pour savoir ce qui cloche |
| **C** | `c.html` — **le verdict** : chaque onglet répond d'abord à sa question en une phrase, le détail suit, le reste est replié ; photos en « stories » ; la barre compte ce qui demande un geste | la phrase doit être écrite par des règles ; le détail demande un toucher de plus |

`planche.html` met les neuf écrans côte à côte. Images : `a.jpg`, `b.jpg`, `c.jpg`, `planche.jpg`,
et chaque écran seul (`a-exploitation.png`…).

## Les seuils de couleur

Les mêmes dans les trois directions :

| mesure | vert | orange | rouge |
|---|---|---|---|
| Chiffre face à l'objectif | ≥ 100 % | ≥ 90 % | < 90 % |
| Résultat, en part des ventes | ≥ 15 % | ≥ 5 % | < 5 % |
| Contrôles rendus | tous | ≥ 80 % | < 80 % |
| Poubelle, en part du chiffre | ≤ 1,5 % | ≤ 3 % | > 3 % |
| Matière | ≤ 35 % | > 35 % | |

## Régénérer

```bash
node docs/maquettes/dashboard-3-onglets/generer.js
npx http-server . -p 8099 -c-1
node docs/maquettes/dashboard-3-onglets/capturer.js
# → http://127.0.0.1:8099/docs/maquettes/dashboard-3-onglets/planche.html
```

## Retenue : A, codée le 04/10/2026

`public/dashboard/dashboard.js` (au téléphone), `GET /exploitation/semaine-jours` pour le damier.
Captures de la version codée, Halle, samedi 3 octobre : `code-exploitation.png`,
`code-controle.png`, `code-semaine.png`. Les seuils de couleur sont réunis dans `MA`
(dashboard.js) et décrits dans docs/contrat-api.md. Différences avec la maquette : une journée en
cours reste grise tant qu'elle n'est pas finie ; la ligne Résultat de la semaine répartit la
main-d'œuvre du mois, sauf pour le jour regardé qui la mesure au planning.
