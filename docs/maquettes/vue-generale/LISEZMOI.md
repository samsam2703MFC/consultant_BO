# Le dashboard magasin : une vue générale, le détail en liste déroulante

**Statut : maquettes à choisir, rien n'est codé dans le dashboard.**

Demande du 06/10/2026 : réadapter le dashboard magasin avec une vue générale et le détail en
liste déroulante, en maquettes d'abord.

Aujourd'hui, la vue Jour suit déjà cette forme (maquette A du 03/10, codée). Les vues Semaine et
Mois restent des murs de blocs : **3 770 px** et **3 750 px** de haut à 1 440 px de large. Le
téléphone garde ses trois onglets (codés le 04/10) ; ces maquettes concernent le bureau.

## Les trois

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **A** | `a.html` : **une ligne par section, partout**. La forme de la vue Jour reprise pour la semaine et le mois : 4 chapitres, 16 lignes. Ouverte sur « Les jours ». | même lecture que la vue Jour ; la semaine tient en 978 px repliée | une période à la fois ; 16 lignes de texte à lire |
| **B** | `b.html` : **le tableau croisé**. Un onglet « Vue générale » : 17 mesures en lignes, le jour, la semaine et le mois en colonnes, une tendance. Ouverte sur « Objectif atteint » : ses trois périodes côte à côte. | une page répond à « comment va le magasin ? » ; les écarts entre périodes se voient | un onglet de plus ; des cases vides là où le serveur ne chiffre pas |
| **C** | `c.html` : **six domaines en cartes**. Ventes, marge, clients, contrôles, produits, canaux : une carte, un graphique, une couleur. Ouverte sur « Contrôles et tâches » : un damier jour par jour. | le plus visuel ; tient sur un écran | moins de chiffres d'un coup ; des sections regroupées |

`planche.html` / `planche.jpg` : les trois côte à côte, repliées et ouvertes. Chaque écran seul :
`a.png`, `a-replie.png`, `b.png`, `b-replie.png`, `c.png`, `c-replie.png`.

## Mesures (`mesures.json`, refaites par capturer.js)

| Maquette | Repliée | Une section ouverte |
|---|---|---|
| A | 978 px | 1 318 px |
| B | 950 px | 1 200 px |
| C | 950 px | 1 042 px |

Aucune ne déborde en largeur à 1 440 px.

## Données

**Réel** : Atelier by - Halle (shop 4), lu en lecture seule le 06/10/2026 vers 07 h 20
(`reel-halle.json`) : `/exploitation/jour`, `/exploitation/periode` (semaine et mois),
`/exploitation/semaine-jours`, `/ventes/stats` (jour, semaine, mois), `/exploitation/canaux`,
`/exploitation/invendus`, `/ventes/semaines`, `/ventes/stock`, `/ventes/record`,
`/pwa/tasks/heatmap/mois`, `/pwa/tasks/nc`. Le jour : dimanche 4 octobre. La semaine : 40, du 28/09
au 04/10. Le mois : septembre. Le réseau reste anonyme ; aucun nom de client ni de membre de
l'équipe.

| | Dimanche 4/10 | Semaine 40 | Septembre |
|---|---|---|---|
| Chiffre | 2 709 € | 12 864 € | 50 850 € |
| Objectif atteint | 114 % | 92 % | 83 % |
| Matière | 39,3 % | 42 % | 43,5 % |
| Poubelle | 0,7 % | 1,5 % | 2,6 % |
| Résultat net | 569 € | pas chiffré | pas chiffré |

## Un manque relevé dans les données

Le serveur ne chiffre le **résultat net** d'une semaine ou d'un mois que pour le mois courant :
la main-d'œuvre et les frais généraux ne sont connus que pour lui (`motifNet`). Une vue qui met
le jour, la semaine et le mois côte à côte (B) montre ce trou. Le combler demande de garder la
main-d'œuvre et les frais généraux des mois clos.

## Régénérer

```
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node docs/maquettes/vue-generale/generer.js
node docs/maquettes/vue-generale/capturer.js
# → http://127.0.0.1:8099/docs/maquettes/vue-generale/planche.html
```
