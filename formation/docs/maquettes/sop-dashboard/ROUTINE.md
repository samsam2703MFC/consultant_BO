# Routine « SOP du dashboard magasin » — le prompt

À coller tel quel comme prompt d'une tâche planifiée Claude Code (une **nouvelle session à chaque passage**,
dépôt `samsam2703MFC/consultant_BO`). Proposition de rythme : **chaque lundi à 6 h 40** (avant la lecture du
lundi que décrit la SOP). Une seule ligne à régler en tête : `MODE`.

- `MODE = proposer` : la routine met à jour le contenu et les captures, pousse sur sa branche et s'arrête —
  on relit, puis on encode à la main (`tools/formation/encoder_sop.py --ecrire`).
- `MODE = encoder` : la routine encode elle-même dans le module Formation une nouvelle version **« précision »**.
  Un changement qui modifie un geste de la SOP (une étape ajoutée, retirée ou dont l'action change) n'est
  jamais encodé seul : il est proposé, quel que soit le mode.

---

```text
MODE = encoder

Tu tiens à jour la formation au dashboard magasin du réseau L'Atelier By. Deux documents du module Formation
en dépendent, tous deux rattachés à la formation FO-05 (Pilotage Commercial) et à la sous-catégorie D7.1 :
  - ART-PIL-01, l'article du manuel « Comprendre le dashboard magasin » (pré-ouverture : la compréhension) ;
  - SOP-PIL-01, la SOP « Analyser le dashboard magasin : la routine » (formation continue : l'utilisation).
Leur source est dans le dépôt consultant_BO, dossier pilotage-reseau/ :
  data/formation/dashboard/art-pil-01.json et sop-pil-01.json  (le contenu FR et NL, le classement)
  data/formation/dashboard/photos/dash-*.jpg                    (les captures, 1600 × 1200, cadre 4:3)
  data/formation/dashboard/photos.json                          (empreinte → nom de la photo au serveur)
  data/formation/dashboard/ETAT.json                            (le dernier commit du dashboard pris en compte)
  tools/formation/captures_dashboard.js                         (refait les captures)
  tools/formation/encoder_sop.py                                (encode par l'API du cockpit, simulation par défaut)
  docs/maquettes/sop-dashboard/maquette.js                      (rend la vraie tablette avec le contenu, sans écrire)
Lis d'abord pilotage-reseau/CLAUDE.md, docs/formation.md (§ Standards, Manuel du réseau, Articles) et
docs/maquettes/sop-dashboard/PROPOSITION.md.

0. Le dépôt. Si samsam2703MFC/consultant_BO n'est pas dans la session, ajoute-le (add_repo, accès push) et
   clone-le. Les fichiers de la routine sont sur la branche claude/loving-turing-n6j40s : travaille sur elle
   (git fetch origin claude/loving-turing-n6j40s, git checkout -B claude/loving-turing-n6j40s
   origin/claude/loving-turing-n6j40s). Ne pousse sur aucune autre branche.

1. Trouver le code en ligne. Récupère les branches distantes. La branche en ligne est celle dont le dernier
   commit s'intitule « Mise en ligne : … » et qui est la plus récente (git for-each-ref --sort=-committerdate
   refs/remotes/origin). Fusionne-la dans claude/loving-turing-n6j40s (git merge, sans réécrire l'historique)
   pour lire le code actuel du dashboard ; un conflit hors data/formation/dashboard/ et docs/maquettes/sop-dashboard/
   se résout en faveur de la branche en ligne ; un conflit que tu ne sais pas trancher : arrête-toi et rapporte-le.

2. Ce qui a changé. Liste les commits qui touchent pilotage-reseau/public/dashboard/ (et la section
   « Dashboard magasin » du README) depuis ETAT.json › dashboardCommit. Aucun : écris « rien à encoder depuis
   <commit> du <date> » et arrête-toi là, sans commit.
   Sinon, lis les messages et les diffs, et dresse la liste des changements VISIBLES par un responsable de
   magasin : onglet, bloc ou carte ajouté, retiré ou renommé ; chiffre, formule, seuil, couleur ou légende qui
   change ; geste nouveau (bouton, tiroir, filtre). Un changement interne (performance, refactoring, API sans
   effet à l'écran) ne touche pas les documents : note-le comme tel.

3. Vérifier sur le dashboard en ligne. Ouvre la page (http tant que le https du serveur est coupé) :
   …/consulant_bo/dashboard/?shop=4&vue=<onglet>&date=<aujourd'hui>, avec Playwright. Ce qui n'est pas
   visible en ligne n'est pas encore en production : ne l'écris pas.

4. Mettre à jour le contenu, dans les deux JSON, en FR ET en NL (le serveur refuse une version sans NL) :
   - garde la structure : sections, une partie ou une étape par bloc, une action ou un fait par ligne (\n) ;
     même ton, phrases courtes, vouvoiement absent (on décrit), pas de chiffre du magasin du jour dans le texte ;
   - aucun chiffre, seuil ou formule qui ne se lise pas dans le code ou à l'écran : cite-les tels quels ;
   - un bloc nouveau → une partie dans l'article, et une étape dans la SOP seulement s'il change la routine ;
   - garde le quiz juste (une question qui porte sur une chose retirée se réécrit) ; la bonne réponse ne doit
     pas être toujours à la même place (la tablette ne mélange pas) ;
   - le classement (FO-05, D7.1, postes, compétences) ne change pas sans demande.
   Tiens une liste « changement → parties/étapes touchées ».

5. Refaire les captures touchées : node tools/formation/captures_dashboard.js <adresse du dashboard>
   data/formation/dashboard/photos <noms…> (SHOP=4 ; tout refaire sans noms). Un bloc nouveau → ajoute son
   plan dans PLANS (vue, sélecteur CSS du bloc). Regarde chaque capture refaite : le bloc doit être entier,
   entouré, lisible. Une capture qui n'a pas changé de sens n'a pas besoin d'être refaite.

6. Contrôler sur la vraie tablette : node docs/maquettes/sop-dashboard/maquette.js <adresse du module /formation/>
   puis regarde les écrans de docs/maquettes/sop-dashboard/ecrans/ touchés (rien n'est écrit en production).

7. Simuler l'encodage : python3 tools/formation/encoder_sop.py --api <…/formation/api/cockpit>
   data/formation/dashboard/art-pil-01.json data/formation/dashboard/sop-pil-01.json
   Il doit annoncer « nouvelle version » pour les documents touchés et rien d'autre.

8. Encoder (seulement si MODE = encoder ET qu'aucune étape de la SOP n'a été ajoutée, retirée ou changée
   d'action) : relance avec --ecrire --par "Routine dashboard (Claude)" --note "<les changements en une ligne>".
   Ne passe jamais --revalider seul. Relis ensuite GET /formation/sop/fiche/art-pil-01 et sop-pil-01 : la
   version doit avoir avancé. Une erreur du serveur : arrête-toi et rapporte-la telle quelle.
   Sinon (MODE = proposer, ou geste changé) : n'encode pas.

9. Consigner : ETAT.json (dashboardCommit = le dernier commit lu, sa date, capturesLe, encode true/false,
   note). photos.json est réécrit par l'encodeur, garde-le. Une ligne datée dans docs/formation.md,
   § « SOP du dashboard magasin », qui dit ce qui a changé et la version. Commits atomiques en français
   (contenu ; captures ; état), push de claude/loving-turing-n6j40s. Ne crée pas de pull request.

10. Rapport final, court : les changements du dashboard pris en compte (commit, date), les parties et étapes
   modifiées, les captures refaites, encodé ou non (et la version), ce qui attend une décision (geste changé,
   ambiguïté, écran introuvable en ligne).

Interdits : écrire dans une base, appeler une route d'écriture autre que celles de encoder_sop.py, modifier
le code du dashboard ou du module Formation, inventer un chiffre, publier un brouillon sans NL, toucher aux
autres SOP.
```

---

## Mise en place

1. Valider la proposition, puis le premier encodage (fait à la main ou par moi à votre demande) — sans lui, la
   routine n'a rien à mettre à jour (`encoder_sop.py` créerait les documents en v1.0 « à valider »).
2. Créer la tâche planifiée : Claude Code › Tâches planifiées, nouvelle session à chaque passage, dépôt
   `samsam2703MFC/consultant_BO`, l'environnement doit joindre `185.180.206.46` en http.
3. Démarrer en `MODE = proposer` quelques semaines, puis passer en `encoder` une fois la qualité vérifiée.

**État au 07/11/2026** : tâche planifiée « Routine SOP du dashboard magasin » (`trig_012YAG9ejFyZGd5A1EHTFVfD`), chaque
lundi à 6 h 40 (Bruxelles), nouvelle session à chaque passage, notification sur le téléphone ; **`MODE = encoder`**
(demande du client). Le prompt enregistré porte en plus les adresses du serveur et deux notes (seuil matière déjà
traité en v1.1 ; documents réservés au responsable).
