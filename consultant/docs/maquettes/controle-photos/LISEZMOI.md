# Les contrôles en photo dans le contrôle guidé — maquette

Pendant la visite, le consultant voit dans son contrôle guidé (application
visites, au téléphone) ce que l'équipe a rendu depuis l'ouverture : le même
carrousel que « Les contrôles en photo » du dashboard magasin, en deuxième
étape, juste après la photo du jour.

| Fichier | Ce que c'est |
|---|---|
| `a-ecran.jpg` | L'étape ouverte, au téléphone (390 × 844) |
| `a.jpg` | La même page, toute la hauteur |
| `a-etape.png` | La carte de l'étape seule |
| `a-loupe.jpg` | La photo en grand : fiche, ‹ › |
| `planche-a.jpg` | La planche : les deux écrans, ce que ça apporte, les limites |

Données réelles de Gosselies (shop 3), jeudi 01/10/2026 : les 16 tâches du
panel (`donnees.json`, lues par `/pwa/tasks?date=`), les 11 photos rendues
(`photos/`, lues par `/pwa/tasks/photos?shop=3&date=`). La visite du
consultant est une illustration : il n'y en a pas ce jour-là. Les noms des
étapes sont ceux de la checklist en ligne.

Ce que l'étape montre : une ligne de résumé (combien de photos, de quelle
heure à quelle heure, par qui, combien non rendues), les filtres Tout / À
contrôler / Non rendues, les cartes (photo 3:4, heure, pastille d'état, nom,
heure et auteur, constat), les écarts d'abord et les non rendues en fin de
piste. Un toucher ouvre la photo en grand avec sa fiche (rendue, notée,
tenue), ‹ › pour passer à la suivante. Lecture seule : la note se pose
toujours dans le panel.

Régénérer : serveur statique sur 8099 depuis la racine du dépôt, puis
`node docs/maquettes/controle-photos/generer.js`. Le CSS du carrousel est
repris de `dashboard.css` (`.db-cq*`, `.cqbd`, `.db-cql`), celui du module
de `visites.js` ; `cp.css` n'ajoute que l'ajustement au téléphone.
