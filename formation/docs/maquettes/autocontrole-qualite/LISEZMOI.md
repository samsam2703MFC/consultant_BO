# Autocontrôle qualité par produit — app Cuisine, tablette (maquettes du 24/11/2026)

Sam, sur la fiche produit de l'app Cuisine en tablette (capture du Cougnou Nature 400 g) : « rajouter un bouton
autocontrôle qualité, avec 1. cuisson, 2. structure, 3. organoleptique, avec des photos (oui / non), des corrections ;
un système d'autocontrôle par produit, avec des boutons à variables (mou, très mou, dur, grosseur de la croûte,
retombé après cuisson) ; ça doit être rapide, intuitif et complet ». Puis : « rajouter : est-ce que le produit est
semblable à la photo de référence ou au planogramme ».

La fiche est la **vraie page** de l'app Cuisine (dépôt `ThaiZu/TFB-Kitchen`, `knowledge/product/detail.twig`), relevée
sur une instance locale avec un bouchon qui sert les valeurs de la capture (`contexte/`). L'autocontrôle y est greffé
par `autocontrole.js`, qui se clique de bout en bout.

![planche](planche.jpg)

## Les écrans

| # | Écran | Ce qu'il montre |
|---|---|---|
| 01 | `01-fiche-bouton.jpg` | Le bouton « Autocontrôle qualité » sous le nom du produit, au-dessus des onglets : la fournée à contrôler, les quatre étapes, les 7 derniers jours en pastilles. |
| 02 | `02-cuisson.jpg` | Étape 1. Photo obligatoire à côté de la référence ; coloration du dessus et du dessous en vignettes (pâle → brûlé) ; dorure ; cuisson à cœur ; « sonne creux » en oui / non. Un écart ouvre sa correction sur place. |
| 03 | `03-structure.jpg` | Étape 2. Volume dessiné contre la cible (pointillés), « Retombé après cuisson » en oui / non, mie au toucher sur cinq crans (très mou, mou, souple, ferme, dur). |
| 04 | `04-structure-suite.jpg` | Épaisseur de la croûte (fine, moyenne, épaisse) en coupe, forme vue de dessus, poids d'une pièce avec sa plage (facultatif). |
| 05 | `05-organoleptique.jpg` | Étape 3. Odeur, goût, mie en bouche, « je le mettrais en vitrine ». |
| 06 | `06-presentation.jpg` | Étape 4. « Semblable à la photo de référence » : la photo de la fiche et celle prise à l'étape Cuisson côte à côte, en grand (identique, proche, différent). « À sa place dans la vitrine » : le module du planogramme vu de face, la case du produit en bordeaux, le nombre de pièces en façade, la photo de la vitrine (facultative). |
| 07 | `07-bilan.jpg` | Le bilan : décision suggérée pour le lot (vente, déclasser, jeter), réglages pour la prochaine fournée sans doublon (« +1 min de cuisson » vient de deux critères), écarts, photos, prévenir le responsable, réclamation à la production centrale. |
| 08 | `08-bilan-conforme.jpg` | Le chemin rapide : tout à la cible, le lot part en vitrine. |
| 09 | `09-fiche-apres.jpg` | Sur la fiche, l'état du jour remplace le bouton ; un défaut qui revient 3 fois en 7 jours est signalé, avec sa correction non reportée dans les Paramètres de cuisson. |
| 10 | `10-parametrage.jpg` | Côté réseau : une grille par catégorie et des critères communs à tous les produits (photo de référence, planogramme), photo obligatoire / facultative / non par étape, corrections par réponse, surcharges par produit. |

## Au design system

Tout ce que la maquette ajoute suit `docs/DESIGN_SYSTEM.md` : jetons du §3 (papier, filets `#e6e0d8`, bordeaux réservé
à l'action principale et à la sélection), Gotham en trait normal partout (le seul 700 est la valeur des chiffres clés
du bilan), tailles de l'échelle du §4.2, aucune pilule (pastilles et badges à 6 px, étiquettes à 4 px, boutons à 8 px,
cartes à 12 px), aucune ombre hors le curseur de l'interrupteur, icônes linéaires du §7.12 (`icones.js`, tracés du
catalogue du §8.4 quand ils existent). Une réponse choisie est une **pastille de choix** (liseré bordeaux) ; son état se
dit par un **mot** : « Conforme » (vert), « À surveiller » (étiquette or), « Non conforme » (étiquette d'arrêt). Le
paramétrage (`parametrage.html`) charge la feuille commune (`shared/public/assets/ds/global.css`) avec la coquille du
portail de formation : rail, barre supérieure, onglets de page, cartes, sélecteurs segmentés, tableau.

Écarts signalés (le design system est écrit pour le poste de travail) :

- **La tablette** : comme le comptoir du planogramme (`consultant/public/planogramme/plano.css`), les cibles tactiles
  font 44 à 52 px et ce qui se touche est en 14–15 px (tailles de l'échelle).
- **Dans l'app Cuisine**, `global.css` n'est pas chargée (elle restylerait l'app) : ses jetons et ses composants sont
  repris sous le préfixe `aq-`, et Gotham y est déclarée sous le nom `Gotham DS` avec les deux fichiers du §4.1, parce
  que l'app déclare déjà « Gotham » avec d'autres fichiers.
- **L'app Cuisine elle-même** (dépôt `ThaiZu/TFB-Kitchen`) n'est pas au design system : titres en Vank, bouton
  « Retour » en pilule, onglets bordeaux pleins, ombres. La maquette la montre telle qu'elle est ; l'aligner est un
  travail à part.
- **Les dessins** (teintes, volume, croûte, souplesse, forme, coupe) représentent le produit : ils gardent ses couleurs
  (§10.5).
- **L'entrée de rail « Autocontrôle qualité »** (groupe Opérationnel du portail de formation) est une proposition.

## Rapide, intuitif, complet

- **Rapide** : une réponse = un toucher ; une réponse conforme fait défiler au critère suivant ; « Le reste est
  conforme » remplit d'un coup ce qui reste à la cible ; la fournée (module Cuisson) et la personne en poste sont
  préremplies. Un produit conforme se contrôle en quatre écrans et un bilan.
- **Intuitif** : la cible est marquée sur chaque critère (✓ et « Attendu : … ») ; les réponses sont des images
  (teintes de la photo du produit, dessins du volume, de la croûte, de la souplesse, de la forme) ; vert = conforme,
  orange = à surveiller, rouge = non conforme.
- **Complet** : photos par étape, oui / non, mesures, échelles ; chaque réponse hors cible porte sa **cause probable**,
  ce qu'on fait **maintenant** du lot et ce qu'on change à la **prochaine fournée** ; le bilan en déduit la décision,
  les suites (responsable, réclamation, pertes) et l'historique repère les défauts récurrents.

## La grille (`grilles.json`)

Une grille par **catégorie** (Brioches & cougnous, Pains, Viennoiseries, Pâtisseries & tartes, Traiteur chaud &
snacking), surchargée au besoin par **produit** (`produits.6700139.surcharges.poids` : 400 g, 380 à 420 g).

- `photos` : par étape, `obligatoire`, `facultative` ou `non` (les « photos oui / non » de la demande) ;
- `criteres[]` : `etape` (`cuisson`, `structure`, `organoleptique`, `presentation`), `type` (`echelle`, `oui_non`,
  `mesure`), `visuel` (`teinte`, `volume`, `retombe`, `croute`, `souplesse`, `forme`, `comparaison`, `planogramme`) ;
- `criteres_communs[]` : les critères posés à **tous** les produits, quelle que soit la catégorie : « Semblable à la
  photo de référence » et « À sa place dans la vitrine (planogramme) » ;
- `produits.{id}.planogramme` : l'emplacement du produit (zone, module, étage, pièces en façade, voisins) ;
- chaque réponse : `niveau` (`ok`, `mineur`, `majeur`), `cible`, et hors cible `cause`, `maintenant`, `prochaine`,
  `decision` (`vente`, `declasser`, `jeter`), `sop` (le geste à revoir), `reclamation` (défaut du produit cru).

Les critères, niveaux, corrections et l'emplacement au planogramme sont **des exemples à valider par la
production** (les voisins de vitrine sont de vrais produits du catalogue, placés pour l'exemple) ; aucune valeur de four
(température, programme) n'est écrite : les corrections restent relatives (« +1 min », « −10 °C »).

## Construit dans le cockpit consultant (24/11/2026)

Sam : « construire dans cockpit consultant, avec un lien test tablette ».

- **Le module** `consultant/src/autocontrole.php` : les grilles (brouillon du siège et version publiée, réglages
  `autocontroleGrilles` / `autocontroleGrillesPubliees` ; tant que rien n'est publié, les tablettes lisent les grilles
  d'exemple `consultant/src/data/autocontrole_grilles.json`, marquées « à valider ») ; la grille d'un produit (catalogue
  du cockpit, catégorie par mots-clés ou donnée par le siège, surcharges) ; sa place au **planogramme standard** du jour
  et sa **photo de référence** ; les contrôles (`con_aq_controle`, bilan recalculé au serveur) et leurs photos
  (`con_aq_photo`, dossier privé, servies au seul magasin du contrôle et au siège). Tests :
  `php consultant/bin/tests_autocontrole.php`.
- **La tablette** `/consulant_bo/autocontrole/?shop=4` : la liste des produits rangés par grille, puis le parcours en
  quatre étapes de ces maquettes ; `&produit=6700139` ouvre directement le Cougnou Nature 400 g, `&essai=1` enregistre
  à part (ni historique, ni résultats, ni journal), `&qui=` préremplit la personne.
- **Le siège** `/consulant_bo/autocontrole/grilles.html` (entrée « Autocontrôle qualité ↗ » des Applications du
  cockpit) : les résultats des magasins et les défauts qui reviennent ; l'éditeur des grilles, la publication.
- **Reste à faire** : le bouton de la fiche dans l'app Cuisine (dépôt `ThaiZu/TFB-Kitchen`), un simple lien vers la
  tablette avec `&produit=` ; la validation des grilles par la production ; de vraies photos de défauts à la place des
  teintes et des dessins.

## Régénérer

```bash
node formation/docs/maquettes/autocontrole-qualite/generer.js
```

Le script sert lui-même la racine du dépôt (port 8099) et refait les dix captures (820 × 1180 en tablette, 1440 ×
1240 au bureau) et la planche. Pour voir les écrans à la main, servir la racine du dépôt et ouvrir
`formation/docs/maquettes/autocontrole-qualite/fiche.html` (tout se clique) ou `fiche.html#ecran=bilan&demo=ecarts`
(`ecran` : fiche, cuisson, structure, organoleptique, presentation, bilan, fait ; `demo` : ecarts, conforme ; `defiler` : un critère).

Pour relever à nouveau la fiche réelle quand l'app change : `figer.js` (mode d'emploi en tête du fichier, avec le
bouchon `contexte/bouchon.php`). Les polices sont celles du design system (`shared/public/assets/ds/fonts/`) : le
trait Gotham Book de l'app, absent du dépôt, y est rendu par Gotham Medium.
