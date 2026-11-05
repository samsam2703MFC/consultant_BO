# Contrôle des franchisés : des repères sur toutes les photos — maquette

Demande du 05/11/2026 : « Rajouter dans toutes les photos une édition sur photo (comme dans task). Faire une maquette
screenshot, puis on valide. »

L'édition est celle du **Contrôle des tâches** (la modale « Repères sur la photo » du cockpit), reprise sur la
tablette de contrôle. On glisse le doigt pour tracer un cadre. Il reçoit un numéro, une gravité et une remarque.

## Sur la tablette

**Où.** Partout où une photo s'ouvre en grand : les photos du jour (façade, arrière, intérieur), les photos des points
de checklist, la charte de communication, les corrections. Toutes passent par la même visionneuse, qui gagne le bouton
**✎ Annoter la photo**. Il devient **✎ Modifier les repères** quand la photo en porte déjà.

**L'éditeur.**

| Élément | Ce qu'il fait |
|---|---|
| La photo | sur fond sombre. On glisse le doigt pour tracer un cadre ; le numéro s'ajoute tout seul. Un cadre de moins de 1,5 % de la photo est ignoré (un toucher n'en crée pas) |
| Gravité du prochain repère | Exemplaire, Conforme, Mineur, Majeur, Critique. On la choisit avant de tracer, comme un feutre. C'est le barème du Contrôle des tâches (réglage « signalement »), avec ses couleurs |
| Le cadre | sa couleur et son trait suivent la gravité : plus épais quand c'est plus grave, doublé pour un critique, en pointillé pour un constat conforme ou exemplaire. Comme dans le Contrôle des tâches |
| La liste à droite | chaque repère avec son numéro, sa gravité (modifiable d'un toucher) et sa remarque. Un ✕ le retire |
| Le bilan | « 1 conforme · 1 mineur · 1 majeur » |
| Les outils | ↶ Retirer le dernier, Tout effacer |
| Enregistrer / Annuler | Enregistrer met la photo à jour tout de suite. L'envoi passe par la file hors ligne : une photo prise sans réseau s'annote aussi, ses repères partent avec elle |

En portrait, la liste passe sous la photo.

**Après.**
- La photo en grand montre ses cadres et, dessous, chaque remarque numérotée.
- La vignette porte une pastille « ✎ 3 ».
- L'écran est traduit en néerlandais pour les magasins flamands. Les remarques tapées restent telles quelles.

## Dans le rapport

- **PDF et aperçu** :
  - Les photos annotées passent en tête de la section Photos, en plus grand (deux par ligne), pour que les cadres se
    lisent.
  - Dessous, chaque remarque : son numéro, sa gravité dans la langue du magasin, son texte.
  - Une photo annotée est cochée d'office pour le rapport. Le bureau peut toujours la décocher.
- **Écran du bureau** (Visites › rapport à adapter) : une pastille « ✎ n » sur les photos annotées.
- **Page du franchisé** : la photo annotée en grand, avec ses cadres et ses remarques.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-etape-photo.png` | l'étape « L'intérieur en photo », la photo du jour prise |
| `b-photo-en-grand.png` | la photo ouverte : le bouton « Annoter la photo » |
| `c-editeur-vide.png` | l'éditeur à l'ouverture |
| `d-editeur-trois-reperes.png` | trois repères tracés : un majeur, un mineur, un conforme, avec leurs remarques |
| `e-photo-annotee.png` | la photo en grand après l'enregistrement : les cadres et les remarques |
| `f-vignette-pastille.png` | la vignette de l'étape, avec sa pastille « ✎ 3 » |
| `g-editeur-portrait-nl.png` | l'éditeur en portrait, en néerlandais |
| `h-rapport-fr.png` / `.pdf`, `h-rapport-nl.png` / `.pdf` | la première page du rapport PDF : la photo annotée en tête de la section Photos |

**Comment elles sont faites.**

- **Tablette** : l'application en ligne, ouverte en lecture seule (tout appel autre que GET est intercepté), avec
  `visites.js` et `visites-nl.js` de ce dépôt à la place des fichiers servis. Visite n° 4 : Sombreffe, régulière, en
  cours, Loic V.
- **Les cadres** sont tracés au pointeur, comme au doigt, d'après `reperes.json`.
- **La photo** : celle de l'intérieur de Sombreffe en ligne est une image d'essai. Elle est remplacée par une photo de
  comptoir de Gosselies du 06/10/2026 (illustration), et datée du jour, car une photo du jour ne se montre que prise
  aujourd'hui.
- **Pour aller directement à l'étape**, les étapes bloquantes sont coupées le temps de la capture.
- **Rapport** : l'aperçu en ligne de la visite, avec la photo annotée rendue par le PHP de la branche (`outils.php`),
  dans l'enveloppe du PDF. Les autres photos du rapport sont les images d'essai en ligne.

Rien n'est écrit en base.

Régénérer : `node docs/maquettes/reperes-photos/generer.js` (php et pdftoppm requis).

## Statut

**Codée le 05/11/2026 sur la branche, à valider avant la mise en ligne.**

- **Tablette** (`public/assets/js/visites.js`) :
  - la visionneuse : `voirHtml` ;
  - l'éditeur : `edHtml`, `edAction`, le tracé au doigt `ecouterReperes` ;
  - la pastille des vignettes : `repBadge` ;
  - l'écriture `PUT /visites/photos/{id}/reperes`, dans la file hors ligne.
- **Serveur** :
  - la colonne `con_visite_photo.reperes` (JSON, ajoutée au premier appel) ;
  - la route `wr_visites_photo_reperes`. Les cadres sont bornés par `annotationNormalise`, la même fonction que le
    Contrôle des tâches ;
  - le barème envoyé à la tablette (`reperesNiveaux`).
- **Rapport** (`src/suivi.php`, `suPhotoReperesHtml`), écran du bureau (`suivi-tpl.js`), page du franchisé
  (`public/suivi/`).
- **Tests** : `php bin/tests_suivi.php`, section « Repères sur les photos ».
