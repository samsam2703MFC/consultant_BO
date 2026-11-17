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

- `d-radar.png` — **la structure des ventes en radar, face au réseau** (Sam, 17/11/2026 : « montrer la structure des
  ventes en spider (catégorie) par rapport au réseau »). La part de chaque famille dans le CA d'octobre 2026 : Sombreffe
  en plein, la moyenne des trois autres magasins en pointillé, la bande grise du plus bas au plus haut ; à côté, les
  écarts au réseau du plus fort au plus faible. Chiffres **réels** (`/ventes/stats?vue=mois&date=2026-10-31`, 31 jours
  lus sur 31 dans chaque magasin, catégories regroupées par famille du catalogue) : boulangerie 22,0 % contre 17,5 %,
  bundles et promotions 21,6 % (seul magasin), tartes 11,4 % contre 21,1 %, traiteur 12,3 % contre 19,7 %,
  viennoiserie 13,6 % contre 20,1 %. Les autres magasins ne sont pas nommés (règle de l'assistant).

## Les graphiques soignés (17/11/2026, « soigner les graphiques »)

Repris avec la méthode dataviz, à suivre aussi pour le code :
- **Couleurs validées** (`scripts/validate_palette.js`, clair et sombre) : la série du magasin en Ruby un cran plus clair
  `#A82E40` (le `#8D1D2C` de la marque sort de la bande de clarté d'une série), le pôle froid `#1B6FA8` ; la référence
  (l'an passé, le réseau) en gris, en retrait. Un écart au réseau n'est ni bon ni mauvais : deux teintes opposées
  (Ruby au-dessus, bleu en dessous), pas de rouge/vert.
- **Colonnes** de 24 px au plus, arrondies en haut et carrées à la base, d'une seule couleur ; **l'an passé** en ligne
  grise pleine de 2 px (plus de pointillé), points de 8 px cerclés de blanc.
- **Une seule échelle**, graduations rondes (0, 500, 1 000, 1 500), grille en filets pleins et discrets.
- **Étiquettes choisies** : la dernière semaine et le creux, sur un halo blanc ; le reste à l'axe et au survol (chaque
  colonne et chaque point portent leur valeur au survol). Le texte reste à l'encre, jamais dans la couleur des séries.
- **Radar** : bande du réseau en aplat léger, moyenne en ligne grise, le magasin en ligne de 2 px et voile de 10 % ;
  libellés « 22,0 % · réseau 17,5 % » ; graduations sur la bissectrice, à l'écart des axes ; légende complète.

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
