# L'assistant IA fait des graphiques, et les garde comme modèles

**Statut : maquette du 17/11/2026, à valider. Rien n'est codé.**

Demande (17/11/2026) : « est-ce que l'assistant IA peut faire des graphiques ? les télécharger en tant que modèle ?
fais-moi une maquette ».

## Aujourd'hui

Non. L'assistant du dashboard (Pierre ou le Coach, `src/assistant_magasin.php`, `public/dashboard/dashboard.js` › ia*)
répond par un schéma fixe : un résumé, des chiffres en tuiles, trois priorités avec l'écran où agir, les questions de
suite. Il lit la fiche de faits du magasin et trois outils en lecture seule sur 18 mois de ventes
(`src/historique_ventes.php`). Il ne dessine rien et n'exporte rien.

## La proposition

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

## D'autres graphiques, et tous ceux du dashboard (17/11/2026)

Demande : « proposer d'autres graphiques : vente par heure, rentabilité de l'heure, toutes les graphiques disponibles
dans le dashboard (comme ça on allège les pages), évolution d'un produit ou catégorie, trafic magasin, évolution de la
note Google, taux de poubelle par catégorie ».

- `e-catalogue.png` — **l'onglet Graphiques** : tous les graphiques du dashboard, rangés par famille (les ventes ; le
  magasin et ses clients ; le résultat ; les tâches et les contrôles ; les pertes). Chaque vignette dit **où il vit
  aujourd'hui** (« Semaine, Mois › Les heures ») : c'est la liste de ce que les pages peuvent rendre à l'assistant. Les
  nouveaux sont marqués (structure face au réseau, note Google mois par mois, taux de poubelle par catégorie).
- `f-heures.png` — **les ventes et la rentabilité de l'heure**, octobre en moyenne par jour : les ventes en colonnes
  (1 848 € par jour, 12–13 h fait 378 €, 20 %), puis la marge nette de l'heure (ventes − matière − rémunération du
  planning) de part et d'autre de zéro : 753 € par jour, 12–13 h + 188 €, seule 6–7 h perd (− 5 €). Bleu = l'heure
  rapporte, Ruby = elle perd. Remplacerait le bloc « Les heures » de Semaine et Mois.
- `g-produit.png` — **l'évolution d'un produit ou d'une catégorie** sur 12 mois : la Baguette Tradition 500 g, en
  pièces (moyenne 1 523 sur 11 mois clos, plus bas février 1 250, plus haut décembre 1 767, novembre en cours 822 en
  pointillé). Le sélecteur prend un produit ou une catégorie, en pièces ou en euros.
- `h-poubelle.png` — **le taux de poubelle par catégorie** : la part jetée = ventes perdues ÷ (ventes + ventes
  perdues), au prix de vente. Octobre : 370 pièces, 543 € de coût, 1 341 € de ventes perdues ; Salades 15,3 % (une
  sur sept), mais en euros ce sont les tartes Ø 28 cm (404 €). Barres rangées, les montants à droite.
- `i-google-trafic.png` — **la note Google et le trafic**, deux graphiques côte à côte, chacun son échelle (jamais deux
  axes sur un même graphique) : la note 4,8 → 4,7 face à la cible du réseau 4,6, le nombre d'avis dessous ; le trafic
  en clients par jour sur 12 mois, l'an passé en gris d'août à novembre (novembre 152 contre 168, − 9,5 %).

Chiffres **réels** de Sombreffe (`donnees2.js`, lus le 17/11/2026) : `/ventes/stats?vue=mois` et le planning (heures),
`/exploitation/invendus/detail` (poubelle, catégories vendues pour plus de 150 €), `/dashboard/analyse/mois` (Baguette
Tradition, pid 1300003), `/leviers/magasins` (trafic, leviers, note Google relevée chaque mois depuis août 2026 : rien
avant). **D'exemple** : les vignettes « Les invendus jour par jour », « Les tâches faites », « Les photos validées »,
« Le panier moyen » et la liste « Les 10 produits » de l'écran C.

Ce que ça change pour les pages : Semaine et Mois gardent leurs quatre blocs et les douze semaines ; « Les heures »,
« Les invendus », « Les leviers » et « Ma place dans le réseau », déjà repliés dans « Plus », peuvent sortir de la page
et ne vivre que dans l'onglet Graphiques (à décider bloc par bloc).

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
- **Étiquettes placées pour ne rien toucher** : le premier point à droite (loin des graduations), le dernier à droite
  du point, le mois en cours dessous (la ligne pointillée arrive par-dessus) ; une légende en tirets pour lui.
- **Barres horizontales** pour les catégories (libellés longs) ; **colonnes divergentes** pour une marge qui peut être
  négative (zéro marqué, bleu au-dessus, Ruby dessous).
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

`gr.css`, `gr.js` (écrans A à D), `graphes2.js` et `donnees2.js` (écrans E à I), un fichier HTML par écran (avec
`../parametres-mails/coque.js`). Une seule capture : `SEUL=f-heures node …/capturer.cjs`.
Captures : `php -S 127.0.0.1:8099 -t pilotage-reseau` puis `node pilotage-reseau/docs/maquettes/assistant-graphiques/capturer.cjs`.
