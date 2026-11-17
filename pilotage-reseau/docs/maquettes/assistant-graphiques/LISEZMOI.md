# L'assistant IA fait des graphiques, et les garde comme modèles

**Statut : maquette du 17/11/2026, à valider. Rien n'est codé.**

Demande (17/11/2026) : « est-ce que l'assistant IA peut faire des graphiques ? les télécharger en tant que modèle ?
fais-moi une maquette ».

## Aujourd'hui

Non. L'assistant du dashboard (Pierre ou le Coach, `src/assistant_magasin.php`, `public/dashboard/dashboard.js` › ia*)
répond par un schéma fixe : un résumé, des chiffres en tuiles, trois priorités avec l'écran où agir, les questions de
suite. Il lit la fiche de faits du magasin et trois outils en lecture seule sur 18 mois de ventes
(`src/historique_ventes.php`). Il ne dessine rien et n'exporte rien.

## La proposition (trois écrans)

- `a-graphique.png` — **le graphique dans la réponse**. On demande en mots (« mes clients par semaine depuis septembre,
  face à l'an passé ») ou on choisit dans la liste « Graphiques ». La réponse porte le graphique (barres, courbe ou
  tableau, la mesure au choix), trois chiffres, une phrase et sa source. Dessous : **Télécharger** (image PNG, PDF A4
  avec les chiffres et le commentaire, Excel des données), **Enregistrer comme modèle**, **Épingler au dashboard**.
- `b-modele.png` — **enregistrer comme modèle**. Le modèle garde la DEMANDE, pas les chiffres : la question, le type de
  graphique, la mesure, la période glissante (« les 12 dernières semaines »), le magasin. Pour qui : moi, mon équipe
  (et « tout le réseau » pour le siège seulement). En option : me l'envoyer chaque lundi en PDF (par la boîte mail du
  magasin, comme les automatisations) ; l'épingler en bas de la vue Semaine.
- `c-mes-modeles.png` — **Mes modèles**, nouvel onglet de l'assistant : mes modèles et les modèles du réseau (proposés
  par le siège), chacun avec sa vignette ; Ouvrir le refait avec les ventes du jour ; Télécharger ; Programmer.

## Comment le coder sans que l'IA invente

- Le modèle ne dessine pas : il choisit dans le schéma de réponse un **graphique** (`type`, `mesure`, `periode`,
  `comparaison`) parmi une liste fermée ; les **séries viennent des outils** (historique des ventes, tickets) et le
  navigateur trace. Ainsi chaque barre est un chiffre lu, jamais un chiffre écrit par le modèle (règle 2 de
  l'assistant : « il ne calcule rien qu'il ne cite »).
- Un modèle enregistré est cette même définition (table `pil_` du magasin) : le rouvrir relit les outils, sans repasser
  par l'IA — gratuit et instantané.
- PNG et PDF : depuis le graphique de l'écran (le PDF par le générateur de PDF du cockpit, `src/doc.php`) ; Excel :
  les séries.

## Les données

Écran A : clients par semaine de Sombreffe, S35 → S46, et N-1, réels (`/ventes/semaines`, lus le 17/11/2026 :
12 426 clients contre 12 645, − 1,7 %). Écran C : vignettes d'exemple, sauf la première.

`gr.css`, `gr.js`, `a-graphique.html`, `b-modele.html`, `c-mes-modeles.html` (avec `../parametres-mails/coque.js`).
Captures : `php -S 127.0.0.1:8099 -t pilotage-reseau` puis `node pilotage-reseau/docs/maquettes/assistant-graphiques/capturer.cjs`.
