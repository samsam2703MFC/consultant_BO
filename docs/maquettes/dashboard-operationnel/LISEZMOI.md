# Le dashboard opérationnel du magasin

**Statut : codée le 06/10/2026, onglet « Opérationnel » du dashboard, premier onglet au bureau.**

Changements demandés pendant le codage : la liste « À faire maintenant » est retirée ; les ventes
par catégorie (liste ou treemap) et le P&L court de la journée, coût du personnel compris, sont
ajoutés, repris de la vue Jour ; la liste du stock du magasin est en liste déroulante. Captures de
la version codée : `code-bureau.png`.

Demande du 06/10/2026 : « je veux un dashboard opérationnel », précisée ainsi : un dashboard centré
sur le terrain, pour mener la journée du magasin. La finance passe au second plan : le chiffre, la
marge, le résultat et le réseau restent dans l'onglet Jour.

## Ce qu'il montre, de haut en bas

| Bloc | Ce qu'il dit | Lu sur |
|---|---|---|
| Maintenant | l'heure, les ventes face à J−7 à la même heure, l'objectif, la vitrine, l'équipe en poste, la prochaine cuisson | `/ventes/stats`, `/exploitation/jour`, `/production/flux/suivi` |
| À faire maintenant | les actions du plus urgent au moins urgent, chacune avec son heure limite | toutes les lectures ci-dessous |
| La journée | une frise de 04:00 à 19:00 : l'équipe, le four, la vitrine, les commandes ; les ventes de chaque heure face aux 6 derniers mardis | `/exploitation/jour` (planning), `/production/flux/suivi`, `/ventes/stats` |
| La vitrine | ce qui est vide, ce qui va manquer, ce qui finira en trop, et ce qu'il faut recuire | `/production/flux/suivi` |
| Les contrôles en photo | ce qui n'est pas rendu d'abord, puis les photos du matin | `/pwa/tasks`, `/pwa/tasks/photos` |
| En un coup d'œil | commandes clients, stock, non-conformités d'hier, poubelle, promotions | `/ventes/commandes`, `/ventes/stock`, `/pwa/tasks/nc`, `/exploitation/invendus`, `/exploitation/promos` |

## Les chiffres sont réels

Atelier by - Halle (shop 4), mardi 6 octobre 2026, lus en lecture seule à 08:14
(`reel-halle-0610.json`). Aucun nom de client ni de membre de l'équipe ; les 8 photos du matin
(`photos/`) montrent des comptoirs.

| Ce matin à 08:14 | |
|---|---|
| Ventes | 253 € pour 28 clients ; mardi dernier à la même heure : 420 € |
| Vitrine | 412 pièces sorties, 102 vendues, 235 en vitrine |
| Vides | 6 cookies jusqu'à la cuisson de 11 h ; 3 références pas produites |
| Contrôles | 8 rendus sur 12 entre 05:47 et 05:55 ; 4 pas rendus |
| Commandes clients | 9 en retard depuis le 29/09, 518,40 € ; 3 à venir |
| Équipe | 2 personnes en poste, relève à 13:00, 19,5 h au planning |

## Deux écarts relevés dans les données

- **Les ventes par heure de `/exploitation/jour` sont décalées de deux heures** : à 08:14, elles
  comptent 87 € à 8 h et 153 € à 9 h, alors que `/ventes/stats` donne 87 € à 6 h et 153 € à 7 h.
  Le dashboard opérationnel lit les heures sur `/ventes/stats`.
- **9 commandes restent ouvertes après leur heure de retrait**, la plus ancienne du 29/09. Soit
  elles ont été retirées sans être clôturées dans la caisse, soit elles n'ont pas été retirées.

## Régénérer

```
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node docs/maquettes/dashboard-operationnel/generer.js
node docs/maquettes/dashboard-operationnel/capturer.js
```
