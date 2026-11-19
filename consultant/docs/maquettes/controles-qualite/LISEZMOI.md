# Contrôles qualité à distance — quatre propositions

Le franchisé doit voir les contrôles qualité de son magasin quand il n'y est
pas. Quatre formes, sur les données réelles de Gosselies (shop 3), jeudi
24/09/2026 : 16 tâches du panel, 9 photos rendues de 04:09 à 04:11, 8 notées
à 13:55 par le consultant, un écart mineur (Traiteur — « Assortiment »).

| | Proposition | Planche |
|---|---|---|
| A | Carrousel « Les contrôles en photo » sous les tâches du jour, loupe au clic | `planche-a.jpg` |
| B | Deuxième page : bascule Les chiffres / Les contrôles (4e onglet au téléphone) | `planche-b.jpg` |
| C | « Le fil de la journée » déplié en journal horodaté avec les photos | `planche-c.jpg` |
| D | « Pendant votre absence » : résumé depuis la dernière visite + diaporama plein écran | `planche-d.jpg` |

Régénérer : serveur statique sur 8099 depuis la racine du dépôt, puis
`node docs/maquettes/controles-qualite/generer.js`. `contexte.json` est le DOM
du dashboard en ligne (haut de page), `donnees.json` les tâches du jour,
`photos/` les photos rendues ce jour-là.

Aucune nouvelle donnée n'est nécessaire : tout vient de `/pwa/tasks?date=` et
de `/pwa/tasks/detail` (photo signée, repères, avis). Seul point technique :
la photo s'obtient tâche par tâche (URL signée valable 20 min) ; une lecture
groupée `/pwa/tasks/photos?shop=&date=` éviterait 9 à 16 allers-retours.
