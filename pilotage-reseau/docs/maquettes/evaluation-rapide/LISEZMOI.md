# Contrôle des franchisés : l'évaluation rapide en radar — maquette

Demande du 05/11/2026 : « Dans la tablette contrôle franchisés, rajouter à la fin un spider graphique provenant de
l'évaluation rapide : propreté, capacité métier, management, développement commercial, contact client, respect des
normes. Rajouter des champs avec note. Ceci doit être dans le rapport. »

## Sur la tablette

Une nouvelle étape, **Évaluation rapide**, dans le dernier temps de la visite (6 · Météo et fin), entre la météo du
franchisé et la fin de visite avec les signatures.

| Élément | Ce qu'il fait |
|---|---|
| Six axes | Propreté, Capacité métier, Management, Développement commercial, Contact client, Respect des normes. Chacun a une aide courte (ce qu'on regarde) |
| La note | cinq boutons de 1 à 5 : Insuffisant, À améliorer, Correct, Bien, Très bien. La couleur suit la note : rouge (1-2), orange (3), vert (4-5) |
| Le commentaire | un champ par axe, facultatif. Il part dans le rapport. Le texte d'aide rappelle la note de la dernière fois |
| Le radar | il se dessine à chaque note. Cette visite est en rouge plein ; la dernière évaluation du magasin est en pointillé bleu (tous types de visite confondus : les six axes sont les mêmes partout) |
| La moyenne | sur 5, face à la précédente, avec l'écart |
| L'étape | elle est remplie quand les six notes sont données. Elle bloque par défaut : « Suivant » refuse tant qu'il manque une note. Ce réglage se change dans Réglages › Contrôle guidé, ligne « Évaluation rapide (six notes) » |

En paysage (tablette couchée), le radar reste à gauche pendant qu'on note à droite. En portrait, il passe au-dessus.
Halle ouvre la tablette en néerlandais : les libellés sont traduits (`visites-nl.js`).

## Dans le rapport

| Où | Ce qu'on voit |
|---|---|
| Le PDF et « Voir le rapport » (`suRapportHtml`) | le bloc « Évaluation rapide » après le score de la visite : la moyenne, l'écart à la précédente, le radar, puis chaque axe avec ses cases de 1 à 5, sa note, l'écart et le commentaire. Dans la langue du magasin |
| L'écran du bureau (Visites › rapport à adapter) | la carte « L'évaluation rapide », avec le même radar, rendu par le serveur |
| La page du franchisé | dans chaque rapport envoyé : la moyenne, le radar, les notes et les commentaires |

Tant que le rapport est un brouillon, le franchisé ne voit pas l'évaluation, comme le reste du rapport.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-tablette-vide.png` | l'étape à l'ouverture, rien de noté : seule la précédente est dessinée |
| `b-tablette-notee.png` | les six notes données, des commentaires (`-page.png` : l'étape entière) |
| `c-tablette-nl.png` | la même en néerlandais |
| `d-tablette-portrait.png` | la tablette à la verticale : le radar au-dessus des axes |
| `e-rapport-fr.png` / `.pdf`, `e-rapport-nl.png` / `.pdf` | la première page du rapport PDF, en français et en néerlandais |
| `f-bureau-carte.png` | la carte de l'écran du bureau |

**Comment elles sont faites.**

- Tablette et bureau : l'application en ligne, ouverte en lecture seule (tout appel autre que GET est intercepté). Les
  fichiers de ce dépôt remplacent les fichiers servis : les captures montrent donc le code de la branche.
- Visite utilisée : n° 6, Halle, régulière du 26/10/2026, Loic V.
- Pour aller directement à la fin, les étapes bloquantes sont coupées le temps de la capture.
- Rapport : l'aperçu en ligne de la visite, avec le bloc de l'évaluation produit par le PHP de la branche
  (`outils.php`), dans l'enveloppe du PDF. La visite n° 6 est une visite d'essai à la checklist vide, d'où « 0 / 0 points
  conformes ».

**Les notes sont des exemples** (`notes.json`), données pour la capture. L'évaluation « précédente » du 24/10 est
fictive : la fonction n'existait pas encore. Rien de cela n'est écrit en base.

Régénérer : `node docs/maquettes/evaluation-rapide/generer.js` (php et pdftoppm requis).

## Statut

**Codée et mise en ligne le 05/11/2026.**

- Tablette : l'étape `evaluation` dans `public/assets/js/visites.js` (`evalHtml`, `evalRadar`, `evalPrecedente`,
  `evalCorps`). L'évaluation part avec « Terminer », dans le `PUT /visites/{id}`.
- Serveur : la colonne `con_visite.evaluation` (JSON, ajoutée au premier appel) et le contrôle de ce qu'envoie la
  tablette (`src/visites_evaluation.php`, `viEvaluation`).
- Rapport : le radar et le bloc dans `src/suivi.php` (`suEvalRadarSvg`, `suEvalHtml`, `suEvalVue`), la carte du bureau
  (`suivi-tpl.js`, `suivi-vals.js`, `app.css`) et la page du franchisé (`public/suivi/`).
- Tests : `php bin/tests_suivi.php`, section « Évaluation rapide ».
