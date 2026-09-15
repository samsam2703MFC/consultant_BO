# Scouting — assistant « où puis-je ouvrir, et pour combien »

L'écran Scouting sait déjà tout faire, mais il demande de manipuler six
réglages dispersés pour obtenir une réponse. L'assistant pose les quatre
questions dans l'ordre, et rend la carte.

- `1-ou.html` — étapes 1 et 2 : les provinces, puis l'arrondissement.
- `2-concurrence-ca.html` — étapes 3 et 4 : la concurrence, puis le CA visé.
- `3-points-chauds.html` — le résultat : les points chauds sur la carte.

Le fond des trois pages est une **capture réelle** de l'écran en production
(`fond-scouting.png`, 15/09/2026) ; l'assistant est dessiné par-dessus. Les
tuiles OpenStreetMap ne se chargent pas depuis l'environnement de capture — le
fond de carte est gris, la couche de données (communes, concurrents) est bien
là. Les chiffres de l'assistant sont d'illustration.

## Les quatre questions, et ce qu'elles pilotent

| Étape | Ce qu'on demande | Le réglage existant |
| --- | --- | --- |
| 1 | Les provinces | `prov` (cases du panneau gauche) |
| 2 | L'arrondissement | `arr` (liste déroulante) |
| 3 | « sous X ★, pas un concurrent » | **nouveau** — aujourd'hui figé à 3 ★ dans `strength()` |
| 3 | « à partir de Y ★, concurrent fort » | `thresh` (curseur « seuil concurrent fort », défaut 4,5) |
| 3 | Rayon d'exclusion | `radius` |
| 4 | CA annuel visé | **nouveau** — filtre à ajouter à côté de `minScore` |
| 4 | Dépense/ménage, passage, emprise max, surface | `spend`, `passage`, `empriseMax`, `surface` |

Deux seulement sont neufs :

1. **Le seuil bas.** La force d'un concurrent vaut aujourd'hui `(note − 3) ÷ 2`
   — le 3 est écrit dans le code. Le sortir en réglage, c'est exactement la
   question « à partir de quand est-ce un vrai concurrent ». Un commerce sous
   le seuil tombe à une force nulle, donc disparaît de la pression.
2. **Le CA visé.** L'écran filtre les zones par `minScore` (score composite, 0
   à 100). Un exploitant raisonne en euros, pas en score. Le filtre par CA se
   pose au même endroit dans `scanPrio()`, sur la valeur déjà calculée.

Le reste de l'assistant ne fait que **rassembler des réglages qui existent** et
les présenter dans l'ordre où la question se pose.

## Ce que l'assistant ajoute vraiment

- **L'étape 2 trie les arrondissements par ménages par point de vente.** Le
  chiffre existe déjà (onglet `ceo_arrondissements`) mais il faut aller le
  chercher ; c'est pourtant lui qui dit où il reste de la place.
- **L'étape 3 montre où les deux barres coupent** la distribution des notes de
  l'arrondissement, avec le compte des ignorés, des comptés et des forts.
  Aujourd'hui, on déplace un curseur et on lit un total.
- **L'étape 4 renverse le calcul** : au lieu de lire le CA d'une zone, on donne
  le CA voulu et l'assistant dit combien de ménages il faut.
- **Le résultat se referme en un bandeau** qui rappelle les quatre réponses et
  se rouvre d'un clic — les conditions restent lisibles pendant qu'on lit la
  carte.

Rien n'est branché : pages statiques, `generer.js` les régénère,
`wizard.css` porte les styles candidats.
