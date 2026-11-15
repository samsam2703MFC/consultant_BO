# Contrôle qualité : l'IA pré-note, le consultant valide — maquette

Dans le cockpit, page **Tâches › Contrôle** (`#/controle-taches`, en carrousels :
voir `../controle-taches-photos`), l'IA a déjà lu chaque photo rendue quand le
consultant arrive. Chaque carte porte sa proposition : niveau du barème
`signalement`, confiance, premier constat. Rien ne part au panel sans le geste
du consultant.

| Fichier | Ce que c'est |
|---|---|
| `a.jpg` | La page : la bande « Pré-notation IA », puis les boutiques, celles qui ont des écarts proposés en tête |
| `b.jpg` | « Photo du comptoir - Tartes » de Gosselies ouverte : la proposition, le repère sur la photo, le niveau et le commentaire pré-remplis |
| `planche.jpg` | La planche : les deux écrans, ce que ça apporte, les limites, ce qu'il faut construire |

Ce que la maquette ajoute à la page existante :

- **La bande** : combien de photos lues et à quelle heure, les écarts proposés, les
  photos à revoir (confiance faible), les conformes proposées dont les « sûres »
  (confiance haute) ; le bouton « Valider les 7 conformes sûrs » ; l'interrupteur
  « Noter seul les conformes sûrs », éteint ; le taux d'accord avec les notes des
  consultants.
- **Les cartes** : la pastille prend la couleur du niveau proposé (« IA · mineur »,
  « IA · à revoir »…), le constat et la confiance sous le nom ; « Valider » /
  « Corriger » pour une conforme, « Vérifier l'écart » pour un écart, « Noter » pour
  une photo à revoir. Tri : écarts proposés, à revoir, conformes, non rendues.
- **Le volet** : le bloc « Assistance à la notation » devient « Proposition de
  l'IA » (niveau, confiance, constats) ; le niveau proposé est coché, le
  commentaire au magasin pré-rempli ; « Valider et envoyer ». Le journal garde
  « proposée par l'IA, validée par vous ».

**Données.** Les tâches, les photos et le DOM du cockpit sont ceux du jeudi
01/10/2026, repris de `../controle-taches-photos` (aucune copie). Les
propositions (`propositions.json`) sont **rédigées pour la maquette** en regardant
les photos : ce ne sont pas des réponses du modèle. Le taux d'accord (86 %) est un
chiffre d'exemple.

**À construire** : une tâche planifiée horaire qui fait passer les photos « à
contrôler » par la notation existante (`Anthropic::noterPhoto`, `src/anthropic.php`) ;
une table `pil_cq_ia` (niveau, confiance, constats, commentaire, repère, modèle) ;
la zone de l'écart demandée au modèle (le repère) ; la bande et les pastilles dans
la page ; la mesure de l'accord sur l'historique (`mac_task_review`).

Régénérer : serveur statique sur 8099 depuis `pilotage-reseau/`, puis
`node docs/maquettes/controle-qualite-ia/generer.js`.
