# Bingo de saison — maquette

Demande du 12/11/2026 (Sam, après l'échange avec Natan et le bingo de Mix Brussels) : « type de carte de fidélité :
bingo, une par saison, intégrer les fêtes spécifiques avec les illustrations ; le but est que ça se remplisse tout
seul ; 10 € (variable) le point, un par semaine ».

Captures : `planche.jpg` (la planche entière), `carte.jpg` (la carte imprimée), `app.jpg` (l'app du client).
Régénérer depuis `pilotage-reseau/` : `node docs/maquettes/bingo-saison/generer.js`. Données et illustrations :
`bingo.js` ; mise en page : `planche.html`.

## Le principe

- **Une carte par saison, une case par semaine.** Hiver : 12 semaines, du lundi 23/11/2026 au dimanche 14/02/2027.
- **La case se coche toute seule** : dès **10 €** d'achats dans la semaine (réglable), en une ou plusieurs fois,
  ticket rattaché à la carte du client (l'app, ou la carte QR scannée à la caisse). Rien à tamponner.
- **Chaque semaine a son thème, illustré** : une fête de la saison (Avent, Saint-Nicolas, Noël, Nouvel An,
  Épiphanie, Chandeleur, Mardi gras et Saint-Valentin) ou, entre deux fêtes, le produit « à l'honneur » (l'idée de
  Natan : un produit mis en avant chaque semaine, il faut revenir chaque semaine).
- **Quatre paliers** (valeurs de départ) : 4 cases, une viennoiserie ; 8 cases, un café et sa douceur ; 10 cases,
  une tarte de 4 parts ; carte pleine (12), le tirage au sort d'un brunch pour 4. Le cadeau arrive dans le
  portefeuille de l'app dès que le palier est atteint.
- **La semaine des cadeaux** : une 13e semaine entre deux cartes (cadeaux, tirage, nouvelle carte). Quatre cartes
  de 13 semaines font l'année : Hiver, Printemps (Pâques 28/03, fête des mères 09/05), Été (fête des pères 13/06,
  fête nationale 21/07), Automne (Halloween, Toussaint, Saint-Martin).
- **Branché sur ce qui existe** : la carte QR donnée en caisse (Fidélité › En caisse) devient la carte de la saison,
  imprimée en A6 ; le client « déjà venu » reçoit en plus son bon.

## Réel et illustré

Réel : les dates et les jours de la semaine des fêtes (vérifiés), le logo, les polices et les couleurs de la marque.
Illustré : la cliente (Julie, 6 cases sur 12 le jeudi 14/01/2027, la semaine du Nouvel An manquée), les montants,
les paliers et les cadeaux, le numéro de carte. Le QR ne pointe vers rien (texte de maquette).

## Ce qu'il faudrait pour le coder

- **Le client sur le ticket** : la liste des tickets du jour (`/shops/{id}/transactions?date=`) porte `id_client`
  quand le client est identifié ; le cockpit la relève déjà chaque jour (`src/recurrence_pwa.php`). La somme par
  client et par semaine coche la case.
- **La carte QR rattachée au compte** : le numéro de la carte donnée en caisse (`ceo_fid_carte.carte`) relié au
  compte de l'app à l'activation (PWA, dépôt WebShop).
- **L'app** : l'onglet Bingo (la grille, la semaine en cours, les cadeaux) et la notification après chaque ticket.
- **Le cockpit** : la saison (dates, thème et illustration de chaque semaine), le seuil, les paliers et leurs
  cadeaux, et le suivi (cases cochées par semaine, clients par palier, coût).

## À trancher

1. Le seuil (10 €) et les quatre paliers avec leurs cadeaux : le coût par client fidèle.
2. Une semaine manquée est perdue (la carte pleine demande les 12 semaines) ou une « semaine joker » la rattrape ?
3. Les semaines de fête : 10 € suffisent, ou il faut le produit de la fête (la galette à l'Épiphanie) ?
4. La variante de Natan « x € dans chaque catégorie » : des cases bonus en plus des semaines ?
5. Le verso de la carte imprimée (les règles en néerlandais, le QR en grand).
