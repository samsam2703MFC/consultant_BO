# Bingo de saison — maquette

Demande du 12/11/2026 (Sam, après l'échange avec Natan et le bingo de Mix Brussels) : « type de carte de fidélité :
bingo, une par saison, intégrer les fêtes spécifiques avec les illustrations ; le but est que ça se remplisse tout
seul ; 10 € (variable) le point, un par semaine ».

Complément du même jour : « toutes les x (variable) semaines, avec illustration, débloque un voucher », puis « faire
un mock up HTML ».

**La maquette HTML** : `bingo-maquette.html`, un seul fichier (polices, logo, QR intégrés) qui s'ouvre partout.
Interactive : x (2, 3 ou 4), le seuil, la valeur et la validité du bon se règlent dans la barre du haut ; « + ticket »
fait monter la dépense de la semaine et coche la case au seuil, « Semaine suivante » avance, une semaine passée se
coche ou se décoche d'un clic, « Revenir au 14 janvier » remet l'état de départ.

Captures : `planche.jpg` (la planche, le 14 janvier), `carte.jpg` (la carte imprimée), `app.jpg` (l'app),
`app-case-cochee.jpg` (un ticket coche la case de la semaine) et `app-bon-debloque.jpg` (la 9e case débloque le 3e
bon). Régénérer depuis `pilotage-reseau/` : `node docs/maquettes/bingo-saison/generer.js` — il refait le fichier
autonome, joue ce parcours et échoue si la page lève une erreur. Sources : `planche.html` (mise en page et
interaction), `bingo.js` (données et illustrations).

## Le principe

- **Une carte par saison, une case par semaine.** Hiver : 12 semaines, du lundi 23/11/2026 au dimanche 14/02/2027.
- **La case se coche toute seule** : dès **10 €** d'achats dans la semaine (réglable), en une ou plusieurs fois,
  ticket rattaché à la carte du client (l'app, ou la carte QR scannée à la caisse). Rien à tamponner.
- **Chaque semaine a son thème, illustré** : une fête de la saison (Avent, Saint-Nicolas, Noël, Nouvel An,
  Épiphanie, Chandeleur, Mardi gras et Saint-Valentin) ou, entre deux fêtes, le produit « à l'honneur » (l'idée de
  Natan : un produit mis en avant chaque semaine, il faut revenir chaque semaine).
- **Toutes les x cases cochées, un bon** (x réglable, 3 par défaut) : un bon de 2 €, valable 30 jours dès le
  lendemain (réglables). Chaque bon a sa case illustrée, qui ferme la ligne de la grille : x semaines, puis le bon
  (avec x = 3, une grille de 4 × 4 et 4 bons à la 3e, 6e, 9e et 12e case ; x = 2 donne 6 bons, x = 4 en donne 3).
  Le compte est celui des cases cochées, pas des semaines d'affilée : une semaine manquée retarde le bon, elle ne
  le perd pas. Le nom et le motif du bon suivent le moment de la saison où un client fidèle l'atteint : bon de
  l'Avent (étoile), des Rois (couronne), de l'hiver (flocon), de la carte pleine (nœud). Il arrive dans le
  portefeuille de l'app avec son code : un vrai bon du panel, créé comme celui du « déjà venu » de la caisse
  (`src/fidelite_caisse.php`).
- **La 13e semaine** entre deux cartes : le dernier bon se prend, la carte suivante arrive. Quatre cartes de 13
  semaines font l'année : Hiver, Printemps (Pâques 28/03, fête des mères 09/05), Été (fête des pères 13/06,
  fête nationale 21/07), Automne (Halloween, Toussaint, Saint-Martin).
- **Branché sur ce qui existe** : la carte QR donnée en caisse (Fidélité › En caisse) devient la carte de la saison,
  imprimée en A6 ; le client « déjà venu » reçoit en plus son bon.

## Réel et illustré

Réel : les dates et les jours de la semaine des fêtes (vérifiés), le logo, les polices et les couleurs de la marque.
Illustré : la cliente (Julie, 6 cases sur 12 le jeudi 14/01/2027, la semaine du Nouvel An manquée, deux bons
débloqués dont un utilisé), les montants, les codes de bon, le numéro de carte. Le QR ne pointe vers rien.

## Ce qu'il faudrait pour le coder

- **Le client sur le ticket** : la liste des tickets du jour (`/shops/{id}/transactions?date=`) porte `id_client`
  quand le client est identifié ; le cockpit la relève déjà chaque jour (`src/recurrence_pwa.php`). La somme par
  client et par semaine coche la case.
- **La carte QR rattachée au compte** : le numéro de la carte donnée en caisse (`ceo_fid_carte.carte`) relié au
  compte de l'app à l'activation (PWA, dépôt WebShop).
- **L'app** : l'onglet Bingo (la grille, la semaine en cours, les cadeaux) et la notification après chaque ticket.
- **Les bons** : à la (k·x)-e case cochée, un code créé au panel dans la campagne du magasin (une utilisation),
  comme le bon du « déjà venu » de la caisse.
- **Le cockpit** : la saison (dates, thème et illustration de chaque semaine), le seuil, x, la valeur et la
  validité du bon, et le suivi (cases cochées par semaine, bons débloqués et utilisés, coût).

## À trancher

1. Le seuil (10 €), x (3) et le bon (2 €, 30 jours) : au plus 4 bons de 2 € pour 12 semaines à 10 € au moins, soit
   au plus 6,7 % du minimum dépensé.
2. Un bon fixe, ou qui grandit au fil de la saison (2 €, 2 €, 3 €, puis 5 € à la carte pleine) ?
3. Les semaines de fête : 10 € suffisent, ou il faut le produit de la fête (la galette à l'Épiphanie) ?
4. La variante de Natan « x € dans chaque catégorie » : des cases bonus en plus des semaines ?
5. Le verso de la carte imprimée (les règles en néerlandais, le QR en grand).
