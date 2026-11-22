# Mon coffre-fort — v2 de l'interface (maquettes, 22/11/2026)

Demande de Sam (capture du coffre de Halle, vide) : « refaire l'UI/UX, fais des maquettes en screenshots ». Rien n'est
codé : ces écrans montrent ce que la page deviendrait.

## Ce qui ne va pas aujourd'hui
- Un coffre vide n'aide pas : quatre tuiles à zéro, un tableau vide, un aperçu vide à droite. On ne sait pas ce qui
  doit s'y trouver, ni qui doit le déposer.
- L'aperçu prend une colonne fixe même sans document choisi ; le tableau à onglets est serré.
- Les grandes dates sont cachées derrière un petit bouton ; l'urgence (une fenêtre de renouvellement qui se ferme) ne
  se voit pas en entrant.

## Ce que proposent les maquettes
| Écran | Fichier | L'idée |
|---|---|---|
| 01 | `01-coffre-vide.png` | Halle aujourd'hui : l'état du coffre (0 pièce sur 16) et **ce que le coffre doit contenir**, par dossier : ce que vous déposez (« Déposer » sur chaque ligne), ce que le consultant dépose (« attendu »), ce qui arrive tout seul |
| 02 | `02-coffre-rempli.png` | L'état du coffre (complétude, **À traiter**, **prochaine grande date** en grand), la **frise des 12 mois**, les **dossiers à gauche** (qui les remplit, complétude), la liste avec filtres |
| 03 | `03-legal-apercu.png` | Un dossier : ses **pièces attendues** en cartes (reçue, à renouveler, manquante) ; l'**aperçu en tiroir**, seulement quand un document est choisi (Ouvrir, Télécharger, Joindre à un e-mail, Remplacer, ses grandes dates, qui l'a ouvert) |
| 04 | `04-depot-assistant.png` | Déposer : une fenêtre ; « C'est quelle pièce ? » (les pièces manquantes d'abord, celle reconnue par l'assistant choisie) ; l'assistant à côté ; un bouton range tout |
| 05 | `05-telephone.png` | Au téléphone : Déposer et Grandes dates en tête, l'état en une carte, les dossiers en tuiles, les récents |

## Ce qui est nouveau côté serveur (si on code)
- **La liste des pièces attendues** par dossier (16 dans les maquettes : 7 légales à déposer par le franchisé, 4 du
  contrat et 3 des finances par le consultant, 2 de formation automatiques). À valider par Sam : la liste, et si elle est
  la même pour toutes les boutiques. Une pièce « pas concernée » s'écarte (le permis d'environnement, par exemple).
- Chaque document déposé se rattache à une pièce attendue (champ `piece` de `con_doc`) ; la complétude se calcule.
- Le reste existe déjà : documents, dépôt, ouvertures, grandes dates, assistant, pièce jointe des mails.

Captures : `node consultant/docs/maquettes/coffre-fort-v2/capturer.cjs` (serveur `php -S 127.0.0.1:8099 -t .` à la racine).
