/* Bingo de saison — les données et les illustrations de la maquette (12/11/2026, demande de Sam :
 * « type de carte de fidélité : bingo, une par saison, intégrer les fêtes spécifiques avec les illustrations ;
 * le but est que ça se remplisse tout seul ; 10 € (variable) le point, un par semaine »).
 *
 * Une carte = une saison de 12 semaines, une case par semaine. La case de la semaine se coche toute seule dès que
 * le client a dépensé 10 € (réglable) dans la semaine, en une ou plusieurs fois, ticket rattaché à sa carte (app
 * ou carte QR scannée à la caisse). Chaque case a son thème : une fête de la saison, ou le produit de la semaine
 * (l'idée de Natan : « un produit / une promo qu'on met en avant chaque semaine »). Toutes les x cases cochées (x réglable,
 * 3 par défaut), un bon se débloque (12/11/2026, Sam : « toutes les x semaines, avec illustration, débloque un
 * voucher ») : sa case illustrée ferme la ligne de la grille — x semaines, puis le bon. Une 13e semaine fait la
 * jointure avec la carte suivante : quatre saisons de 13 semaines font l'année.
 *
 * Les illustrations sont celles du design system (12/11/2026, Sam : « use the illustration from the design
 * system ») : les dessins d'un seul trait rubis de la marque, rangés avec la tablette des vendeuses —
 * public/tablette/img/s (gammes et fêtes), p (produits), d (boissons). La Chandeleur n'y a pas de crêpes : la brioche
 * roulée (p/rolls.png) la tient en attendant son dessin.
 *
 * Dates vérifiées (jour de la semaine) : 1er dimanche de l'Avent dim. 29/11/2026, Saint-Nicolas dim. 06/12, Noël
 * ven. 25/12, Nouvel An ven. 01/01/2027, Épiphanie mer. 06/01, Blue Monday lun. 18/01, Chandeleur mar. 02/02,
 * Mardi gras mar. 09/02, Saint-Valentin dim. 14/02, Pâques dim. 28/03, fête des mères (Belgique) dim. 09/05, fête
 * des pères dim. 13/06. Le seuil, x, la valeur et la validité du bon : des valeurs de départ, réglables. */
(function () {
  /* Les dessins du design system, par clé : le chemin sous public/tablette/img/. La page autonome
   * (bingo-maquette.html) les reçoit déjà intégrés dans window.BINGO_IMG ; sinon, ils se lisent dans le dépôt. */
  const DS = {
    hiver: 's/winter-range.png', nicolas: 's/saint-nicholas-range.png', noel: 's/christmas-new-year-range.png',
    rois: 's/epiphany-galette-des-rois.png', valentin: 's/valentines-day-range.png',
    chocolat: 'd/hot-drink.png', biscuits: 'p/cookies.png', petitsfours: 'p/cupcake.png', pain: 'p/bread-1.png',
    gateau: 'p/cake-slice.png', croissant: 'p/croissant.png', roule: 'p/rolls.png',
  };
  const BASE = '../../../public/tablette/img/';
  const src = f => (window.BINGO_IMG && window.BINGO_IMG[f]) || BASE + f;
  const img = (k, alt) => `<img src="${src(DS[k])}" alt="${alt || ''}" draggable="false">`;
  const ILL = Object.fromEntries(Object.keys(DS).map(k => [k, img(k)]));

  /* La carte d'hiver 2026-2027 : 12 semaines, du lundi 23/11/2026 au dimanche 14/02/2027. */
  const SEMAINES = [
    { n: 1, du: '23/11', au: '29/11', titre: 'L’hiver commence', sous: '1er dimanche de l’Avent', fete: 'Avent', jour: '29/11', ill: 'hiver' },
    { n: 2, du: '30/11', au: '06/12', titre: 'Saint-Nicolas', sous: 'spéculoos et massepain', fete: 'Saint-Nicolas', jour: '06/12', ill: 'nicolas' },
    { n: 3, du: '07/12', au: '13/12', titre: 'Le chocolat chaud', sous: 'produit de la semaine', ill: 'chocolat' },
    { n: 4, du: '14/12', au: '20/12', titre: 'Les biscuits de Noël', sous: 'produit de la semaine', ill: 'biscuits' },
    { n: 5, du: '21/12', au: '27/12', titre: 'Noël', sous: 'la bûche', fete: 'Noël', jour: '25/12', ill: 'noel' },
    { n: 6, du: '28/12', au: '03/01', titre: 'Nouvel An', sous: 'les petits fours', fete: 'Nouvel An', jour: '01/01', ill: 'petitsfours' },
    { n: 7, du: '04/01', au: '10/01', titre: 'Épiphanie', sous: 'la galette des rois', fete: 'Épiphanie', jour: '06/01', ill: 'rois' },
    { n: 8, du: '11/01', au: '17/01', titre: 'Le bon pain', sous: 'produit de la semaine', ill: 'pain' },
    { n: 9, du: '18/01', au: '24/01', titre: 'Blue Monday', sous: 'une douceur pour le moral', ill: 'gateau' },
    { n: 10, du: '25/01', au: '31/01', titre: 'Le croissant', sous: 'produit de la semaine', ill: 'croissant' },
    { n: 11, du: '01/02', au: '07/02', titre: 'Chandeleur', sous: 'les crêpes', fete: 'Chandeleur', jour: '02/02', ill: 'roule' },
    { n: 12, du: '08/02', au: '14/02', titre: 'Saint-Valentin', sous: 'beignets de Mardi gras', fete: 'Mardi gras · Saint-Valentin', jour: '09 et 14/02', ill: 'valentin' },
  ];

  /* Les réglages (valeurs de départ, réglables au cockpit). */
  const REGLES = {
    seuil: 10,                    // euros dépensés dans la semaine pour cocher sa case
    parSemaine: 1,                // une case par semaine au plus
    tousLes: 3,                   // x : toutes les x cases cochées, un bon se débloque
    bon: { valeur: 2, validite: 30 },   // le bon : 2 €, valable 30 jours dès le lendemain
  };
  /* Les bons : leur nom et leur dessin suivent le moment de la saison où un client fidèle les atteint (la semaine
   * x, 2x, 3x… : avec x = 3, à l'Avent, aux Rois, en plein hiver, à la carte pleine). Valable pour tout x. */
  const BONS = [
    { jusqua: 4, nom: 'Bon de l’Avent', motif: 'noel' },
    { jusqua: 7, nom: 'Bon des Rois', motif: 'rois' },
    { jusqua: 11, nom: 'Bon de l’hiver', motif: 'hiver' },
    { jusqua: 12, nom: 'Bon carte pleine', motif: 'valentin' },
  ];
  const bonDe = semaine => BONS.find(b => semaine <= b.jusqua) || BONS[BONS.length - 1];

  /* L'année : quatre cartes de 12 semaines, plus une 13e semaine entre deux cartes (le dernier bon, la carte suivante). */
  const SAISONS = [
    { nom: 'Hiver', du: '23/11/2026', au: '14/02/2027', cadeaux: '15 – 21/02', ill: 'hiver', fetes: ['Avent', 'Saint-Nicolas', 'Noël', 'Nouvel An', 'Épiphanie', 'Chandeleur', 'Mardi gras', 'Saint-Valentin'], etat: 'en cours' },
    { nom: 'Printemps', du: '22/02/2027', au: '16/05/2027', cadeaux: '17 – 23/05', ill: 's/easter-range.png', fetes: ['Pâques 28/03', '1er avril', 'Fête du travail', 'Ascension', 'Fête des mères 09/05'], etat: 'à préparer' },
    { nom: 'Été', du: '24/05/2027', au: '15/08/2027', cadeaux: '16 – 22/08', ill: 's/ice-cream-range.png', fetes: ['Fête des pères 13/06', 'Fête de la musique', 'Fête nationale 21/07', '15 août'], etat: '' },
    { nom: 'Automne', du: '23/08/2027', au: '14/11/2027', cadeaux: '15 – 21/11', ill: 's/autumn-range.png', fetes: ['La rentrée', 'Fête de la FWB 27/09', 'Halloween', 'Toussaint', 'Saint-Martin 11/11'], etat: '' },
  ];
  SAISONS.forEach(s => { s.img = s.ill.includes('/') ? `<img src="${src(s.ill)}" alt="" draggable="false">` : ILL[s.ill]; });

  /* Une cliente, le jeudi 14 janvier 2027 (semaine 8) : six cases cochées, la semaine du Nouvel An manquée ; le 1er
   * bon (3e case, le 09/12) utilisé le 17/12, le 2e (6e case, le 06/01) dans son portefeuille. */
  const DEBUT = '2026-11-23';      // le lundi de la semaine 1
  const CLIENTE = {
    prenom: 'Julie', semaine: 8, depense: 6.4,
    cochees: { 1: '2026-11-27', 2: '2026-12-05', 3: '2026-12-09', 4: '2026-12-19', 5: '2026-12-24', 7: '2027-01-06' },
    utilises: { 1: '2026-12-17' },
    codes: { 1: 'FIDR4WQ8NZC', 2: 'FID7K2QM9PA' },
  };

  /* Les fichiers du design system dont la maquette a besoin (generer.js les intègre à la page autonome). */
  const FICHIERS = [...new Set(Object.values(DS).concat(SAISONS.filter(s => s.ill.includes('/')).map(s => s.ill)))];

  window.BINGO = { ILL, BONS_ILL: ILL, SEMAINES, REGLES, BONS, bonDe, SAISONS, DEBUT, CLIENTE, FICHIERS };
})();
