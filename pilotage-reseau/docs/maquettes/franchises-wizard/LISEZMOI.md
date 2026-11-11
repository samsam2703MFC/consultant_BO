# « Suivi des franchisés » en assistant : trois maquettes (26/10/2026)

Demande de Sam : refaire tout `#/franchises` pour le rendre plus compréhensible ; tout faire en assistant, avec au
départ « Créer une visite » ; une seule page ; pas de tableau de bord.

Les trois maquettes partagent le même parcours en six étapes, regroupées en trois temps :

- **avant la visite, au bureau** : 1. le magasin, 2. la visite (type, annoncée ou inopinée, date, consultant),
  3. le dossier (ce qui part sur la tablette, les demandes du bureau, un mot au consultant), 4. l'envoi (tablette, et
  courriel au franchisé si la visite est annoncée) ;
- **pendant la visite, sur la tablette** : rien à faire au bureau, le brouillon revient tout seul ;
- **après la visite, au bureau** : 5. le rapport (adapter, choisir ce qui part, envoyer sous 48 h), 6. les corrections.

Chaque maquette est montrée à l'étape 3, avec plus bas d'autres moments de la même page : l'arrivée (étape 1),
l'étape 2 (les quatre types de visite) et le retour d'une visite faite (étape 5, Corbais). Une visite en cours se
reprend sans tableau de bord, chaque maquette à sa façon. Exemple suivi : **Halle, Régulière, annoncée, jeudi 29/10 à
09:30, Pierre B.** ; à reprendre : Corbais (rapport à adapter), Sombreffe (2 corrections), Gosselies (dossier à finir).

Les fichiers HTML sont autonomes (CSS en ligne, logo intégré, aucun appel réseau). Les polices Gotham et Vank se
lisent dans `public/assets/ds/fonts` ; hors du dépôt, la page passe en Helvetica ou Arial. Les captures font 1440 px
de large, en pleine page. Les magasins sont réels ; les chiffres et les consultants servent d'exemple. Toutes les
données existent déjà dans les routes du suivi (`/suivi/preparation`, `PUT /suivi/preparation/{id}`,
`/suivi/rapport`, `POST /visites`).

| | Maquette | Fichiers |
|---|---|---|
| A | Le stepper horizontal, une colonne | `maquette-a.html`, `maquette-a.png` |
| B | Le fil de questions, des cartes qui s'empilent | `maquette-b.html`, `maquette-b.png` |
| C | Les étapes à gauche, la fiche vivante à droite | `maquette-c.html`, `maquette-c.png` |

## A — Le stepper horizontal

**L'idée.** Un assistant classique : en haut, une barre de sept étapes (le passage sur la tablette y figure, en
pointillé), avec sous chaque étape ce qui a été choisi ; dessous, la bande « Votre visite » toujours visible.
L'étape en cours s'affiche sur une seule colonne centrée. Une barre d'actions reste collée en bas, avec un seul bouton
principal. Une petite liste « Reprendre là où vous en étiez » se tient en haut à droite.

- **Forces** : connu de tous, rien à apprendre ; le plus simple à coder, puisqu'il réutilise presque tels quels les
  gabarits actuels (`suivi-tpl.js`) ; tout le circuit se lit d'un coup d'œil dans la barre.
- **Limites** : ce que verra le franchisé n'apparaît que dans un encadré en bas de l'étape ; l'étape 3 est longue à
  faire défiler ; sept étapes en ligne se serrent sur un petit écran.

## B — Le fil de questions

**L'idée.** Une seule question à la fois, posée en clair (« Que doit emporter Pierre sur la tablette ? »). Chaque
réponse se replie en bulle au-dessus, modifiable d'un clic. Les étapes à venir attendent en pointillé sous la carte
en cours. Une pastille collée en haut rappelle la visite et l'avancement. Les visites à reprendre forment une ligne
discrète en tête du fil.

- **Forces** : le plus accueillant, idéal pour un consultant qui prépare une visite de temps en temps ; une décision
  par carte, au sens strict ; les étiquettes « le franchisé le lit à l'avance » ou « entre vous et Pierre » rendent
  la règle évidente.
- **Limites** : plus lent au quotidien (plus de clics) ; le fil s'allonge vite avec le dossier et le rapport ;
  revenir sur une réponse ancienne oblige à remonter.

## C — Les étapes à gauche, la fiche vivante à droite

**L'idée.** À gauche, les étapes en accordéon vertical, groupées en avant, pendant et après la visite : les étapes
faites se replient sur une ligne, l'étape en cours s'ouvre. À droite, la **fiche de la visite** se remplit au fil des
choix : qui reçoit quoi (le consultant, le franchisé, ce qui reste au bureau), l'ordre du jour tel qu'il partira, et
le calendrier (envoi à J−2, rappel la veille, visite, délai du rapport). Chaque visite en attente a son **onglet**,
ce qui permet de reprendre sans tableau de bord.

- **Forces** : répond en direct aux questions qui embrouillent l'écran actuel : qui voit quoi, ce qui reste interne,
  ce que change « inopinée » (l'étape 2 le montre quand on choisit Contrôle flash) ; le résumé ne quitte jamais
  l'écran ; les onglets rendent la reprise naturelle.
- **Limites** : demande un écran large (au moins 1280 px ; plus étroit, la fiche passe dessous ou devient un
  volet) ; un peu plus à coder, car la fiche se recalcule à chaque choix ; trop d'onglets en attente demanderont un
  menu « + 3 autres ».

## Recommandation

**C**, en reprenant deux idées de B : les suggestions en un clic pour les demandes du bureau, et les étiquettes en
langage simple (« le franchisé le lit à l'avance », « reste au bureau »). Le cockpit s'utilise sur ordinateur, et
l'essentiel de la confusion actuelle tient à « qui reçoit quoi, et quand » : la fiche vivante y répond à chaque
choix, sans tableau de bord. Pour livrer plus vite, A est le repli le moins coûteux, mais sans cette fiche.
