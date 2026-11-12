# Bingo de saison — maquette

Demande du 12/11/2026 (Sam, après l'échange avec Natan et le bingo de Mix Brussels) : « type de carte de fidélité :
bingo, une par saison, intégrer les fêtes spécifiques avec les illustrations ; le but est que ça se remplisse tout
seul ; 10 € (variable) le point, un par semaine ». Compléments du même jour : « toutes les x (variable) semaines,
avec illustration, débloque un voucher », « faire un mock up HTML », « use the illustration from the design
system », « + un tirage au sort de tous les gens qui ont complété la carte, 1 an de pain à 1,5 € ».

**Version de référence : celle que Sam a reprise dans Claude Design**, gardée telle quelle dans `claude-design.html`
(la page exportée, qui s'ouvre seule). Elle a fixé : 13 semaines par carte (un trimestre), la 13e « La tarte du
dimanche » ; le **super bon cadeau** de la carte pleine (10 €) ; les thèmes d'origine (cougnou, pain d'épices,
éclair) ; ses illustrations, celles de son design system (dossier `ill/`, 220 px : les dessins d'un seul trait
rubis de la marque, dont les crêpes de la Chandeleur, l'étincelle du Nouvel An, le gâteau sous cloche, la fleur du
printemps et le soleil de l'été, absents de `public/tablette/img`).

**La maquette du dépôt suit cette version** et garde en plus le tirage au sort d'1 an de pain (interrupteur dans la
barre). Elle corrige deux détails de l'export : le portefeuille de l'app était coupé par la barre du bas, et les
dessins, très fins à cette taille, ont le trait légèrement épaissi.

- `bingo-maquette.html` : la maquette interactive, un seul fichier (polices, logo, QR, dessins intégrés). Réglages :
  x (2, 3 ou 4), le seuil, la valeur et la validité du bon, le super bon, le tirage (prix du pain, gagnants) ;
  présentation : carte inclinée, fêtes teintées. Simulation : « + ticket » coche la case au seuil, « Semaine
  suivante » avance, une semaine passée se coche ou se décoche d'un clic, « Revenir au 14 janvier ».
- Captures : `planche.jpg` (le 14 janvier), `carte.jpg`, `app.jpg`, `app-case-cochee.jpg` (un ticket coche la
  case), `app-bon-debloque.jpg` (la 9e case débloque le 3e bon), `app-tirage.jpg` (13 / 13 : le super bon et le
  tirage).
- Régénérer depuis `pilotage-reseau/` : `node docs/maquettes/bingo-saison/generer.js` — il refait le fichier
  autonome, joue ces parcours (et coupe le tirage pour vérifier qu'il disparaît) et échoue si la page lève une
  erreur ou si une image manque. Sources : `planche.html` (mise en page, interaction), `bingo.js` (données, choix
  des dessins), `ill/` (les dessins).

## Le principe

- **Une carte par saison, 13 semaines, une case par semaine.** Hiver : du lundi 23/11/2026 au dimanche 21/02/2027 ;
  la carte suivante arrive le lundi d'après. Quatre cartes font l'année : Printemps 22/02 → 23/05/2027 (Pâques
  28/03, fête des mères 09/05), Été 24/05 → 22/08 (fête des pères 13/06, fête nationale 21/07), Automne 23/08 →
  21/11 (Halloween, Toussaint, Saint-Martin).
- **La case se coche toute seule** : dès **10 €** d'achats dans la semaine (réglable), en une ou plusieurs fois,
  ticket rattaché à la carte du client (l'app, ou la carte QR scannée à la caisse). Rien à tamponner.
- **Chaque semaine a son thème, illustré** : une fête de la saison (Avent, Saint-Nicolas, Noël, Nouvel An,
  Épiphanie, Chandeleur, Mardi gras et Saint-Valentin) ou, entre deux fêtes, le produit « à l'honneur » (l'idée de
  Natan : un produit mis en avant chaque semaine, il faut revenir chaque semaine).
- **Toutes les x cases cochées, un bon** (x réglable, 3 par défaut) : 2 €, valable 30 jours dès le lendemain. Sa case
  illustrée ferme la ligne de la grille (avec x = 3 : 4 bons, à la 3e, 6e, 9e et 12e case). Le compte est celui des
  cases cochées, pas des semaines d'affilée : une semaine manquée retarde le bon, elle ne le perd pas. Le code arrive
  dans le portefeuille de l'app : un vrai bon du panel, créé comme celui du « déjà venu » de la caisse
  (`src/fidelite_caisse.php`).
- **Carte pleine (13 / 13) : le super bon cadeau**, 10 € (réglable), valable 30 jours, en plus des bons — dans la
  case large qui termine la grille. Il faut ne manquer aucune semaine.
- **Carte pleine : le tirage au sort** (interrupteur). Toutes les cartes pleines du réseau participent au tirage du
  lundi 22/02/2027, le jour où la carte du printemps arrive. À gagner : 1 an de pain, un pain par jour à 1,50 €,
  soit 365 pains et 547,50 € au prix de vente ; le prix du pain et le nombre de gagnants (1) sont réglables. Le
  gagnant reçoit dans l'app une carte « 1 an de pain » : un pain par jour à la caisse, pendant 12 mois.
- **Branché sur ce qui existe** : la carte QR donnée en caisse (Fidélité › En caisse) devient la carte de la saison,
  imprimée en A6 ; le client « déjà venu » reçoit en plus son bon.

## Réel et illustré

Réel : les dates et les jours de la semaine des fêtes (vérifiés), le logo, les polices, les couleurs et les
illustrations de la marque. Illustré : la cliente (Julie, 6 cases sur 13 le jeudi 14/01/2027, la semaine du Nouvel
An manquée, deux bons débloqués dont un utilisé), les montants, les codes de bon, le numéro de carte. Le QR ne
pointe vers rien.

## Ce qu'il faudrait pour le coder

- **Le client sur le ticket** : la liste des tickets du jour (`/shops/{id}/transactions?date=`) porte `id_client`
  quand le client est identifié ; le cockpit la relève déjà chaque jour (`src/recurrence_pwa.php`). La somme par
  client et par semaine coche la case.
- **La carte QR rattachée au compte** : le numéro de la carte donnée en caisse (`ceo_fid_carte.carte`) relié au
  compte de l'app à l'activation (PWA, dépôt WebShop).
- **L'app** : l'onglet Bingo (la grille, la semaine en cours, le portefeuille, le tirage) et la notification après
  chaque ticket.
- **Les bons et le super bon** : à la (k·x)-e case cochée, puis à la 13e, un code créé au panel dans la campagne du
  magasin (une utilisation), comme le bon du « déjà venu » de la caisse.
- **Le tirage** : la liste des cartes pleines au lundi de fin de saison, le tirage, la carte « 1 an de pain » du
  gagnant (un bon par jour, ou un code réutilisable une fois par jour).
- **Le cockpit** : la saison (dates, thème et illustration de chaque semaine), le seuil, x, les bons, le super bon,
  le tirage, et le suivi (cases cochées par semaine, bons débloqués et utilisés, cartes pleines, coût).
- **Les illustrations** : les dessins de `ill/` qui manquent à `public/tablette/img` (crêpes, étincelle, gâteau sous
  cloche, fleur, soleil) sont à verser au design system du dépôt, en 560 px comme les autres.

## À trancher

1. Le seuil (10 €), x (3) et le bon (2 €, 30 jours) : au plus 4 bons de 2 € pour 13 semaines à 10 € au moins.
2. La carte pleine : le super bon (10 €) **et** le tirage, ou l'un des deux ? Ensemble, un client qui ne manque
   aucune semaine reçoit 18 € de bons pour 130 € d'achats au moins (13,8 % du minimum), plus sa chance au tirage.
3. Le tirage : un gagnant pour tout le réseau ou un par magasin ; le règlement du jeu (participation sans
   obligation d'achat ? publication du gagnant) — à faire valider ; un pain par jour d'ouverture ou par jour
   calendaire.
4. Les semaines de fête : 10 € suffisent, ou il faut le produit de la fête (la galette à l'Épiphanie) ?
5. La variante de Natan « x € dans chaque catégorie » : des cases bonus en plus des semaines ?
6. Le verso de la carte imprimée (les règles en néerlandais, le QR en grand).
