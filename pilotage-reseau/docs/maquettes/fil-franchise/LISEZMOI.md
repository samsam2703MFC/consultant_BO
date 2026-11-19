# Fil du franchisé : trois maquettes (19/11/2026)

**Statut : retenu le 19/11/2026 — « A on toggle B » : le fil vertical (A), avec le calendrier (B) en bascule ; codé** (écran `#/franchises/fil`, `src/fil_franchise.php`, `public/assets/js/fil-vals.js`, `fil-tpl.js`, `public/assets/css/fil.css`). Demande du 19/11/2026 : créer dans le rail, pour le suivi des franchisés, un endroit pour les
notes, les captures d'écran et les photos, avec des étiquettes (« Problème de gestion », « Bonne action »…), pour suivre
un fil sur la semaine ou sur l'année. Quand on ouvre la carte d'un franchisé, on voit ses visites, ses rapports et ses
notes sur un calendrier ou sur un fil vertical.

Nouvelle entrée du rail : **Franchisés › Suivi des franchisés › Fil du franchisé**, juste sous Visites.

| | Maquette | Fichiers |
|---|---|---|
| A | Le fil vertical : les semaines ouvertes, les mois repliés | `a.html`, `a.png` |
| B | Le calendrier : le mois jour par jour, la semaine à droite, l'année en carrés | `b.html`, `b.png` |
| C | La frise et ses fils : l'année en lignes par étiquette, chaque problème relié à sa solution | `c.html`, `c.png` |

Les trois maquettes lisent **les mêmes données** (`donnees.js`), avec Sombreffe ouvert le jeudi 19/11 : seule la
mise en page change. Le rail est celui du cockpit, relevé le 19/11 (`rail.html`).

**L'écran codé** (19/11/2026), capturé sur un banc d'essai — le module serveur réel sur SQLite, rempli avec l'année
d'exemple de Sombreffe — : `code-fil.png` (le fil), `code-calendrier.png` (le mois et sa semaine), `code-annee.png`
(l'année en carrés).

## Ce qui est commun aux trois

- **La carte du franchisé** : un magasin, son consultant, les problèmes de gestion et les bonnes actions du mois, les
  12 dernières semaines en carrés de couleur (rouge, or, vert ; un point sous la semaine quand il y a eu une visite),
  les fils ouverts, la prochaine visite. On clique sur la carte et le fil du franchisé s'ouvre.
- **Ce que montre le fil.** Ce qui existe déjà : les visites et leur score, les rapports (envoyés, lus), les points
  décidés, le client mystère, les demandes du helpdesk. Ce qui est nouveau : les **notes**, **photos**, **captures
  d'écran** (on les colle avec Ctrl+V) et **appels**, posés par le bureau ou un consultant.
- **Les étiquettes.** Trois donnent le *sens* et ont une couleur : **Problème de gestion** (rouge), **À surveiller**
  (or), **Bonne action** (vert). Les autres donnent le *thème* et restent grises : Équipe, Produit, Hygiène, Finances,
  Client. La liste se règle dans Qui fait quoi.
- **Les fils.** Une note peut être la *suite* d'un problème. Le fil reste ouvert jusqu'à ce qu'une bonne action le
  ferme. Exemple : « Prix écrits à la main en vitrine », ouvert à la visite du 26/10 et fermé le 19/11, en 24 jours.
- **Épingler pour la prochaine visite** : l'entrée part dans le dossier de la visite (étape 3 de Visites).
- **Interne par défaut.** Une entrée n'apparaît sur la page du franchisé que si l'option « Le franchisé le voit » est
  cochée, par exemple pour lui montrer une bonne action.

## A — Le fil vertical

**L'idée.** La carte du franchisé ouvre son fil, du plus récent au plus ancien. Les quatre dernières semaines sont
ouvertes ; avant, chaque mois tient sur une ligne avec ses compteurs. Le formulaire d'ajout est en tête du fil :
texte, captures, photos, étiquettes, suite d'un fil, épingle. Sur la droite : Semaine / Mois / Année, les filtres
d'étiquettes et de types, les fils ouverts et la prochaine visite.

- **Forces** : c'est la lecture la plus naturelle, celle d'un fil de discussion. L'ajout se fait au même endroit que
  la lecture. Les photos et les captures se voient en grand. C'est la plus simple à coder et elle passe telle quelle
  sur téléphone et tablette.
- **Limites** : on lit l'année en faisant défiler, pas d'un seul coup d'œil. Un mois chargé allonge vite la page.

## B — Le calendrier

**L'idée.** La carte du franchisé ouvre son mois. Chaque jour affiche ses entrées en étiquettes de couleur, avec les
vignettes des photos ; la visite prévue est en pointillé. La colonne de droite déroule la semaine choisie en petit
fil, le jour ouvert encadré. La vue **Année** montre les 365 jours en carrés de couleur, avec le nombre de problèmes,
de sujets à surveiller et de bonnes actions mois par mois.

- **Forces** : on voit d'un coup d'œil *quand* les choses arrivent, par exemple des problèmes groupés autour d'une
  visite ou un été sans rien. La vue Année est la plus parlante pour un bilan ou pour préparer une visite.
- **Limites** : dans une case de calendrier, le texte est coupé ; il faut la colonne de droite pour lire. Les liens
  entre un problème et sa solution ne se voient pas. Elle est moins à l'aise sur téléphone.

## C — La frise et ses fils

**L'idée.** La carte du franchisé est en tête, avec ses chiffres de l'année. Dessous, une frise de l'année : une ligne
par étiquette (visites et score, problèmes, à surveiller, bonnes actions, notes, demandes) et une colonne par semaine.
Chaque problème est relié à ce qui l'a suivi : un trait vert quand il est résolu, un trait rouge pointillé jusqu'à
aujourd'hui tant qu'il est ouvert. Le cadre bordeaux, qu'on fait glisser sur la frise, choisit les semaines détaillées
dessous, une colonne par semaine. Sur le côté, la liste des fils du franchisé (ouverts d'abord, puis résolus avec leur
durée).

- **Forces** : c'est la seule maquette qui montre si les problèmes *se ferment*, ce qui est la vraie question du suivi
  d'un franchisé. L'année entière tient sur une ligne. Elle est très parlante pour un bilan avec le franchisé.
- **Limites** : c'est la plus chargée et la plus longue à coder (la frise est dessinée en SVG). Il faut relier les
  entrées entre elles (« suite de ») pour qu'elle donne tout. Elle ne passe pas sur téléphone.

## Recommandation

**A** comme écran principal, en reprenant deux idées : la **vue Année de B** (les douze mois en carrés) derrière le
bouton « Année », et **la liste des fils de C** (ouvert ou résolu, avec sa durée) dans la colonne de droite. A se code
vite, sert aussi depuis la tablette en visite et depuis le téléphone, et garde ce qui compte : ajouter une note en un
geste et retrouver un problème jusqu'à sa solution. La frise complète de C pourra venir ensuite comme vue « Bilan ».

## Données et stockage (à confirmer après le choix)

- Nouvelle table du portail, **`pil_fil_entree`** : magasin, date et heure, type (note, photo, capture, appel), auteur,
  texte, étiquettes, fil (« suite de »), épinglée pour la visite, visible par le franchisé. Pièces jointes dans
  **`pil_fil_piece`** ; les fichiers vont dans le dossier privé, réduits à 1600 px comme les photos de correction.
- Lus sans copie : les visites, les rapports et les points (tables `con_` des Visites), le client mystère
  (`con_scoring_msp`), les demandes (helpdesk du panel, `GET /shops/{id}/cases`).
- Les étiquettes et leur sens se règlent dans Qui fait quoi (`pil_variable`).

## Données de la maquette

Magasins, consultants, dates des visites de Sombreffe (26/10 et 10/11) et demandes du helpdesk : réels. Notes,
étiquettes, scores et chiffres : inventés pour l'exemple. Photos : celles des contrôles photo du dashboard magasin
(`data/formation/dashboard/photos/dash-vitrine.jpg`) et de la SOP d'ouverture de caisse
(`public/formation/sop/photos/ouv-mag-7.jpg`). Captures : `dash-invendus.jpg` et `dash-vitrine.jpg`, recadrées dans `img/`.

Générer : `node docs/maquettes/fil-franchise/generer.js`. Capturer (serveur `php -S 127.0.0.1:8099 -t .` lancé
depuis `pilotage-reseau/`) : `node docs/maquettes/fil-franchise/capturer.js`, 1440 px de large, pleine page, ×2.
