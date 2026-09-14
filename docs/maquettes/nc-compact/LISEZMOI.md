# Le bloc des non-conformités, replié au plus court

Trois façons de rendre le bloc aussi compact que possible **sans retirer une
seule donnée** : tout ce que l'écran sait reste atteignable, seul change le
nombre de clics pour y arriver.

Même jeu d'essai partout : la vue **Mois**, septembre, huit écarts — c'est là
que la place se paie. Données fictives.

| | Replié | Ouvert | Un écart lu | Niveaux |
| --- | --- | --- | --- | --- |
| **A** une ligne par écart | 53 px | 284 px | + 120 px | 2 |
| **B** replié par gravité | 53 px | **136 px** | + 90 px puis + 120 px | 3 |
| **C** le tableau nu | 53 px | 381 px | 0, tout est lu | 0 |

- `a-ligne-detail.html` — **A.** Une ligne dense par écart : gravité, jour,
  tâche, début du constat, ce qu'il est devenu. Le clic déplie la photo et ses
  repères, le constat entier, la checklist, la récidive, la suite.
- `b-repli-gravite.html` — **B.** Le plus compact à l'ouverture : trois lignes,
  une par gravité, avec le compte et des pastilles d'état. On ouvre la gravité
  qui inquiète, puis l'écart qui inquiète.
- `c-tableau-nu.html` — **C.** L'inverse : rien ne se replie, tout tient en
  colonnes serrées. Plus haut, mais c'est le seul où l'œil compare les huit
  écarts d'un coup, sans mémoire ni aller-retour.

## Ce qu'aucune des trois ne perd

gravité et note · jour et heure du relevé · qui a relevé · nom de la tâche ·
checklist · constat écrit · photo et ses repères · récidive · ce que la tâche
est devenue depuis · lien vers Contrôle des tâches.

## Le compromis, en une phrase

**B** gagne sur un mois chargé, **A** sur une semaine ordinaire (trois ou
quatre écarts : les grouper par gravité coûterait un clic pour rien), **C**
gagne quand on cherche une tendance plutôt qu'un écart.

Rien n'est encore branché : ce sont des pages statiques, comme les maquettes de
`../nc-veille/`. `generer.js` les régénère, `compact.css` porte les styles
candidats.
