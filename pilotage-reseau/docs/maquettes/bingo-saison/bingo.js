/* Bingo de saison — les données et les illustrations de la maquette (12/11/2026, demande de Sam :
 * « type de carte de fidélité : bingo, une par saison, intégrer les fêtes spécifiques avec les illustrations ;
 * le but est que ça se remplisse tout seul ; 10 € (variable) le point, un par semaine »).
 *
 * Version de référence : celle que Sam a reprise dans Claude Design (claude-design.html, à côté) — 13 semaines par
 * carte, un trimestre, la 13e semaine « La tarte du dimanche » ; le super bon cadeau de la carte pleine ; ses
 * illustrations (ill/, exportées de son design system : les dessins d'un seul trait rubis de la marque, crêpes et
 * étincelle du Nouvel An compris).
 *
 * Une carte = une saison de 13 semaines, une case par semaine. La case de la semaine se coche toute seule dès que
 * le client a dépensé 10 € (réglable) dans la semaine, en une ou plusieurs fois, ticket rattaché à sa carte (app ou
 * carte QR scannée à la caisse). Chaque case a son thème : une fête de la saison, ou le produit de la semaine (l'idée
 * de Natan). Toutes les x cases cochées (x réglable, 3 par défaut), un bon se débloque : sa case illustrée ferme la
 * ligne de la grille. La carte pleine (13 / 13) débloque le super bon cadeau et entre au tirage au sort d'1 an de
 * pain (Sam : « + un tirage au sort de tous les gens qui ont complété la carte, 1 an de pain à 1,5 € » ; puis « les
 * deux, super bon et tirage »).
 *
 * Dates vérifiées (jour de la semaine) : 1er dimanche de l'Avent dim. 29/11/2026, Saint-Nicolas dim. 06/12, Noël
 * ven. 25/12, Nouvel An ven. 01/01/2027, Épiphanie mer. 06/01, Blue Monday lun. 18/01, Chandeleur mar. 02/02,
 * Mardi gras mar. 09/02, Saint-Valentin dim. 14/02, Pâques dim. 28/03, fête des mères (Belgique) dim. 09/05, fête
 * des pères dim. 13/06. Le seuil, x, les bons et le tirage : des valeurs de départ, réglables. */
(function () {
  /* Les dessins : ill/<nom>.png. La page autonome (bingo-maquette.html) les reçoit déjà intégrés (window.BINGO_IMG). */
  const NOMS = ['artboard-4', 'automne', 'bread-1', 'bread-2', 'cake-slice', 'cake', 'chocolat', 'cookies', 'crepes', 'croissant',
    'cupcake', 'ete', 'galette', 'hiver', 'noel', 'printemps', 'saint-nicolas', 'tarte', 'tartelette', 'valentin'];
  const src = n => (window.BINGO_IMG && window.BINGO_IMG[n]) || 'ill/' + n + '.png';
  const ILL = Object.fromEntries(NOMS.map(n => [n, `<img src="${src(n)}" alt="" draggable="false">`]));

  /* La carte d'hiver 2026-2027 : 13 semaines, du lundi 23/11/2026 au dimanche 21/02/2027. */
  const SEMAINES = [
    { n: 1, du: '23/11', au: '29/11', titre: 'Le cougnou est là', sous: '1er dimanche de l’Avent', fete: 'Avent', jour: '29/11', ill: 'bread-2' },
    { n: 2, du: '30/11', au: '06/12', titre: 'Saint-Nicolas', sous: 'spéculoos et massepain', fete: 'Saint-Nicolas', jour: '06/12', ill: 'saint-nicolas' },
    { n: 3, du: '07/12', au: '13/12', titre: 'Le chocolat chaud', sous: 'produit de la semaine', ill: 'chocolat' },
    { n: 4, du: '14/12', au: '20/12', titre: 'Le pain d’épices', sous: 'produit de la semaine', ill: 'cookies' },
    { n: 5, du: '21/12', au: '27/12', titre: 'Noël', sous: 'la bûche', fete: 'Noël', jour: '25/12', ill: 'noel' },
    { n: 6, du: '28/12', au: '03/01', titre: 'Nouvel An', sous: 'les petits fours', fete: 'Nouvel An', jour: '01/01', ill: 'artboard-4' },
    { n: 7, du: '04/01', au: '10/01', titre: 'Épiphanie', sous: 'la galette des rois', fete: 'Épiphanie', jour: '06/01', ill: 'galette' },
    { n: 8, du: '11/01', au: '17/01', titre: 'Le bon pain', sous: 'produit de la semaine', ill: 'bread-1' },
    { n: 9, du: '18/01', au: '24/01', titre: 'Blue Monday', sous: 'un éclair pour le moral', ill: 'cake-slice' },
    { n: 10, du: '25/01', au: '31/01', titre: 'Le croissant', sous: 'produit de la semaine', ill: 'croissant' },
    { n: 11, du: '01/02', au: '07/02', titre: 'Chandeleur', sous: 'les crêpes', fete: 'Chandeleur', jour: '02/02', ill: 'crepes' },
    { n: 12, du: '08/02', au: '14/02', titre: 'Saint-Valentin', sous: 'beignets de Mardi gras', fete: 'Mardi gras · Saint-Valentin', jour: '09 et 14/02', ill: 'valentin' },
    { n: 13, du: '15/02', au: '21/02', titre: 'La tarte du dimanche', sous: 'produit de la semaine', ill: 'tarte' },
  ];

  /* Les réglages (valeurs de départ, réglables au cockpit). */
  const REGLES = {
    seuil: 10,                    // euros dépensés dans la semaine pour cocher sa case
    parSemaine: 1,                // une case par semaine au plus
    tousLes: 3,                   // x : toutes les x cases cochées, un bon se débloque
    // Chaque cadeau plus gros que le précédent (Sam, 12/11/2026 : « il faut que chaque cadeau soit de plus en plus
    // gros », puis « on commence les cadeaux à 4.9 - 9.9 - 19.9 - 29.9 ») : les bons, dans l'ordre, valables 30 jours
    // dès le lendemain. Au-delà de la liste (x = 2 : 6 bons), la dernière marche se répète (39,90, 49,90 €).
    bon: { valeurs: [4.9, 9.9, 19.9, 29.9], validite: 30 },
    // La carte pleine (13 / 13) : le super bon cadeau, en plus des bons, plus gros que le dernier (29,90 €). 49,90 € :
    // la marche suivante de l'échelle, en attendant le montant de Sam. Le tirage, 1 an de pain, reste le plus gros.
    superBon: { valeur: 49.9 },
    // Le coût réel d'un cadeau : sa valeur de vente × le food cost (Sam : « à 40 % de food cost, quelle est la vraie
    // valeur du cadeau ? »). Un bon s'échange contre des produits : il coûte ce que coûtent leurs matières.
    foodCost: 40,
    // Le tirage au sort des cartes pleines, le lundi où la carte suivante arrive : un pain par jour pendant un an.
    tirage: { actif: true, prix: 1.5, jours: 365, gagnants: 1, le: '2027-02-22' },
  };
  /* Les bons : leur nom et leur dessin suivent le moment de la saison où un client fidèle les atteint (la semaine
   * x, 2x, 3x… : avec x = 3, à l'Avent, aux Rois, en plein hiver, à la carte pleine). Valable pour tout x. */
  const BONS = [
    { jusqua: 4, nom: 'Bon de l’Avent', motif: 'cake' },
    { jusqua: 7, nom: 'Bon des Rois', motif: 'tartelette' },
    { jusqua: 11, nom: 'Bon de l’hiver', motif: 'hiver' },
    { jusqua: 13, nom: 'Bon carte pleine', motif: 'cupcake' },
  ];
  const bonDe = semaine => BONS.find(b => semaine <= b.jusqua) || BONS[BONS.length - 1];

  /* L'année : quatre cartes de 13 semaines, un trimestre chacune ; la suivante arrive le lundi d'après. */
  const SAISONS = [
    { nom: 'Hiver', du: '23/11/2026', au: '21/02/2027', ill: 'hiver', fetes: ['Avent', 'Saint-Nicolas', 'Noël', 'Nouvel An', 'Épiphanie', 'Chandeleur', 'Mardi gras', 'Saint-Valentin'], etat: 'en cours' },
    { nom: 'Printemps', du: '22/02/2027', au: '23/05/2027', ill: 'printemps', fetes: ['Pâques 28/03', '1er avril', 'Fête du travail', 'Ascension', 'Fête des mères 09/05'], etat: 'à préparer' },
    { nom: 'Été', du: '24/05/2027', au: '22/08/2027', ill: 'ete', fetes: ['Fête des pères 13/06', 'Fête de la musique', 'Fête nationale 21/07', '15 août'], etat: '' },
    { nom: 'Automne', du: '23/08/2027', au: '21/11/2027', ill: 'automne', fetes: ['La rentrée', 'Fête de la FWB 27/09', 'Halloween', 'Toussaint', 'Saint-Martin 11/11'], etat: '' },
  ];

  /* Une cliente, le jeudi 14 janvier 2027 (semaine 8) : six cases cochées, la semaine du Nouvel An manquée ; le 1er
   * bon (3e case, le 09/12) utilisé le 17/12, le 2e (6e case, le 06/01) dans son portefeuille. */
  const DEBUT = '2026-11-23';      // le lundi de la semaine 1
  const CLIENTE = {
    prenom: 'Julie', semaine: 8, depense: 6.4,
    cochees: { 1: '2026-11-27', 2: '2026-12-05', 3: '2026-12-09', 4: '2026-12-19', 5: '2026-12-24', 7: '2027-01-06' },
    utilises: { 1: '2026-12-17' },
    codes: { 1: 'FIDR4WQ8NZC', 2: 'FID7K2QM9PA', 3: 'FIDX5HT3LWE', 4: 'FIDB9MV2KQS', 5: 'FIDN6CJ8RTD', 6: 'FIDH3PZ7WVU', super: 'FIDK8SB4MGQ' },
  };

  /* Les fichiers de dessins dont la maquette a besoin (generer.js les intègre à la page autonome). */
  const FICHIERS = NOMS.map(n => n + '.png');

  window.BINGO = { ILL, BONS_ILL: ILL, SEMAINES, REGLES, BONS, bonDe, SAISONS, DEBUT, CLIENTE, FICHIERS };
})();
