# Paramètres dans une roue (maquettes du 22/11/2026)

Sam : « Mails et automatisations sont des paramètres. Pour gagner de la place dans le rail, une roue Paramètres dans une
icône carrée à gauche du bouton Coffre-fort ; au clic, tous les paramètres dans une modale type liste qui monte vers le
haut. Fais 2-3 propositions, maquettes en captures, puis on décide. »

Posées sur le vrai dashboard (magasin 5, Sombreffe, données en ligne lues le 22/11/2026, rien d'écrit) : `capturer.cjs`
charge la page, pose `maquette.css` et `maquette.js` par-dessus et prend les captures.

## Ce qui ne change pas d'une proposition à l'autre

- Le groupe « Paramètres » quitte le rail : « Formations et suivi » est la dernière entrée.
- En bas du rail, la roue (un carré de 40 px, le style du bouton Coffre-fort) à gauche de « Mon coffre-fort ». Ouverte,
  elle prend le rubis clair du lien actif (`00-rail-avec-la-roue.png`).
- La fenêtre monte au-dessus de la roue ; Échap ou un clic à côté la ferment.
- Elle rassemble ce qui se règle aujourd'hui à quatre endroits :
  - Boîte mail : la boîte du magasin (la relier), les messages automatiques (accusé de réception, relance d'une offre),
    les modèles d'e-mails ;
  - Automatisations : le rappel la veille du retrait, l'équipe (qui reçoit les e-mails du magasin) ;
  - Cet appareil : les alertes de stock (le bouton « M'avertir » d'aujourd'hui) ;
  - Assistant IA : le ton qui répond par défaut (Mr. Pierre, Le Coach, Coach Vente).
- L'état de chaque réglage est un rectangle aux coins arrondis (« à relier » en abricot, « après la boîte » en gris).
  Sombreffe n'a pas encore relié sa boîte : c'est ce que les maquettes montrent. L'équipe et le ton sont illustratifs.

## Les propositions

| | Capture | L'idée |
|---|---|---|
| A — Le menu | `01-A-menu.png` | À la largeur du rail, une ligne par réglage (nom, état), une flèche vers la roue. Un clic ouvre la page du réglage. Le plus léger. |
| B — La feuille | `02-B-feuille.png` | Plus large que le rail, la page voilée. Une recherche en tête, la description de chaque réglage, son état à droite, et ce qui se règle ailleurs (au cockpit) en pied. |
| C — Deux niveaux | `03-C-deux-niveaux.png`, `04-C-reglage-ouvert.png` | La liste avec les commandes rapides sur place (l'interrupteur des alertes, le choix du ton) ; un réglage s'ouvre dans la même fenêtre (« ‹ Paramètres »), avec ses commandes et « Ouvrir la page complète ». |

## Retenu et codé (22/11/2026)

Sam : « photo 3 et 4, faire la 4 avec la searchbar » : la proposition C (deux niveaux), avec la recherche de la B.
Codé dans `panel/public/dashboard/dashboard.js` (la roue, la fenêtre, `PAR`), `parametres.css`, et `boite.js` (la boîte
s'ouvre directement sur ses messages automatiques ou ses modèles).

- La recherche a la main à l'ouverture ; Entrée ouvre le premier réglage trouvé ; Échap ou un clic à côté ferment.
- Les états sont lus à l'ouverture (`GET /dashboard/boite`, puis `/dashboard/boite/automatisations` si la boîte est
  ouverte sur l'appareil) ; « lecture… » en attendant.
- Sur place : l'interrupteur des alertes de stock (le même abonnement que « M'avertir ») et le ton de l'assistant.
- Le rappel la veille du retrait s'ouvre dans la fenêtre : allumé, canal, heure, envoyés un par un ; sans boîte reliée
  ou ouverte, les commandes sont grisées et la fenêtre dit quoi faire.
- Les autres réglages ouvrent leur page (Boîte mail, Mails et automatisations).

Captures du code (`code-*.png`) : Sombreffe en ligne (boîte pas reliée), puis une boîte ouverte simulée pour le rappel.
