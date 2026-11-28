# « Formations et suivi » v2 — l'accueil de la formation et la méga-barre (maquettes, 22/11/2026)

Demande de Sam (capture de la page « Formations et suivi » de Halle) : « j'aurais préféré qu'on tombe là-dessus
(formation/#/accueil), avec ses données mais pas le rail ; et qu'il ait accès ensuite, via une search bar, à tous les
renseignements nécessaires : une méga search bar qui reprend tout le catalogue (formation/catalogue/) et son contenu —
un badge rectangle aux coins arrondis qui reprend le nom et le code couleur du catalogue, ce qui est marqué, avec le
lien vers où il mène, et une photo si nécessaire ». Rien n'est codé.

| Écran | Fichier | L'idée |
|---|---|---|
| 01 | `01-accueil.png` | La page s'ouvre sur l'accueil du module Formation (la phrase et le tableau Formation · Manuel · SOP · Checklist, contenu réel de `portail.js`), sans son rail ; la méga-barre en tête et les étiquettes des catalogues ; le dossier de formation (formation continue) et le Professeur en haut à droite |
| 02 | `02-recherche.png` | « cuisson » : les catalogues à gauche (avec leur nombre), les résultats au centre (étiquette du catalogue, code, titre, extrait, où mène le lien, photo), l'aperçu à droite (photo, premières étapes, Ouvrir · Feuilleter · Professeur) |
| 03 | `03-telephone.png` | La recherche et l'accueil au téléphone (les quatre colonnes en cartes) |

Les étiquettes (rectangles aux coins arrondis, 6 px) prennent la couleur de la couverture de chaque manuel du
catalogue (`catalogue/catalogue.js`) : Manuel opératoire Ruby #8D1D2C, Standards Noir #222222, Checklists #78554B,
Manuel du réseau Abricot #F2C9A0 ; Formations (FO-xx) et Formation continue, hors du catalogue, en contour.

Titres et codes : ceux du module en ligne (lus le 22/11/2026 : 59 articles, 31 SOP, 11 checklists, 3 fiches, 1 mémo,
54 formations, 8 domaines). Les extraits et les étapes de l'aperçu illustrent l'endroit trouvé.

## Si on code
- Un index de recherche servi par le module Formation : titres, codes, objectifs, étapes des SOP, parties des articles,
  tâches des checklists, formations, domaines et sous-catégories, formation continue — lu une fois, filtré dans la page.
- La page « Formations et suivi » du dashboard monte l'accueil du module (sans rail) sous la méga-barre ; un résultat
  ouvre la SOP (tablette), la formation, ou le feuilletage du catalogue au bon endroit.
- À décider : la recherche cherche-t-elle aussi en néerlandais ? un résultat réservé (cadenas or) se montre-t-il ?

## Où on en est (22/11/2026) — codé sur la branche

Sam : « code la formation ». Choix par défaut, en attendant sa réponse : la recherche est en français (les textes
néerlandais n'y sont pas) ; les documents « responsable » y figurent (ils s'ouvrent sans PIN depuis le 12/11/2026),
marqués d'un cadenas.

| Pièce | Fichiers |
|---|---|
| Serveur : l'index du catalogue | `formation/src/formation_recherche.php` (`GET /formation/recherche`), `shared/src/acces.php`, `shared/public/api/index.php` |
| Dashboard : l'accueil, la méga-barre, l'aperçu, le lecteur | `panel/public/dashboard/seformer.js`, `seformer.css` (nouveaux), `formation.js` (l'accueil par défaut, le dossier à un clic), `formation.css`, `index.html` |
| Tests | `php formation/bin/tests_formation_recherche.php` (14) |
| Les couleurs des catalogues sur l'accueil | `seformer.js` et, dans le module, `formation/public/formation/portail.js`, `portail.css` |

Essayé dans le vrai dashboard avec l'index bâti sur le catalogue en ligne (105 documents, 54 formations, 8 domaines,
lus le 22/11/2026) et ses vraies fiches : l'accueil, « cuisson » (25 résultats), « creme patissiere » sans accents,
le lecteur (33 étapes), le filtre SOP, le raccourci « / », le téléphone. Captures : `code-*.png`.

Sam : « gardons les codes couleur des catalogues ». Le tableau de l'accueil (dashboard et module) et la phrase
« la formation apprend… » prennent les couvertures du catalogue : Manuel opératoire Ruby, SOP Noir, Checklists
Brun #78554B ; Formations, hors catalogue, en blanc au contour Ruby, le trait du tableau en pointillés.
