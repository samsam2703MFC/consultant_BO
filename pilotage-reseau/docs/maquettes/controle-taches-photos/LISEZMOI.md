# Contrôle des tâches : les photos en carrousel — maquette

Dans le cockpit, page **Tâches › Contrôle** (`#/controle-taches`), chaque
boutique n'est plus un tableau de seize lignes mais le carrousel « Les
contrôles en photo » du dashboard magasin : la photo, l'heure, l'état, qui l'a
rendue, les écarts d'abord, les non rendues en fin de piste. Un clic sur une
carte (ou son bouton « Noter ») ouvre le volet de notation tel qu'il existe.

| Fichier | Ce que c'est |
|---|---|
| `a.jpg` | La page depuis la ligne des filtres : un carrousel par boutique |
| `a-full.jpg` | La page entière (chiffres en tête, répartition, filtres, boutiques) |
| `a-gosselies.png` | La carte de Gosselies seule |
| `b.jpg` | Un clic sur « Photo du comptoir - Viennoiseries » : le volet de notation, avec la photo |
| `planche-a.jpg` | La planche : les deux écrans, ce que ça apporte, les limites |

Données réelles du jeudi 01/10/2026 : les 64 tâches des quatre boutiques
(`donnees.json`, lues par `/pwa/tasks?date=`), les 19 photos rendues
(`photos/`, nommées `{shop}-{taskId}.jpg`, lues par
`/pwa/tasks/photos?shop=&date=`) — Gosselies 11, Halle 8, Corbais et Sombreffe
aucune. Le reste de la page est le DOM du cockpit en ligne (`contexte.html`,
et `contexte-volet.html` avec le volet ouvert), scripts retirés.

Ce que la maquette ajoute : une bascule **Photos / Liste** au bout de la ligne
des filtres (le tableau reste à un clic) ; dans l'en-tête de chaque boutique,
les filtres Tout / Écarts / À contrôler / Conformes / Non rendues avec leurs
effectifs, et le résumé (photos rendues de … à … par …, notées) ; une
boutique sans photo rendue n'a qu'une ligne de texte. Les cartes sont celles
du dashboard (`.db-cqc`), plus un bouton « Noter » (« Renoter » une fois
notée). Rien de nouveau au serveur : la lecture groupée par boutique existe.

Régénérer : serveur statique sur 8099 depuis la racine du dépôt, puis
`node docs/maquettes/controle-taches-photos/generer.js`. Le CSS du carrousel
est repris de `dashboard.css` (`.db-cq*`, `.cqbd`, `.db-cql`) ; `ct.css`
n'ajoute que le bouton, la bascule et l'alignement des cartes.
