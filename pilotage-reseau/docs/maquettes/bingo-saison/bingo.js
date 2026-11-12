/* Bingo de saison — les données et les illustrations de la maquette (12/11/2026, demande de Sam :
 * « type de carte de fidélité : bingo, une par saison, intégrer les fêtes spécifiques avec les illustrations ;
 * le but est que ça se remplisse tout seul ; 10 € (variable) le point, un par semaine »).
 *
 * Une carte = une saison de 12 semaines, une case par semaine. La case de la semaine se coche toute seule dès que
 * le client a dépensé 10 € (réglable) dans la semaine, en une ou plusieurs fois, ticket rattaché à sa carte (app
 * ou carte QR scannée à la caisse). Chaque case a son thème : une fête de la saison, ou le produit de la semaine
 * (l'idée de Natan : « un produit / une promo qu'on met en avant chaque semaine »). Une 13e semaine, la semaine des
 * cadeaux, fait la jointure avec la carte suivante : quatre saisons de 13 semaines font l'année.
 *
 * Dates vérifiées (jour de la semaine) : 1er dimanche de l'Avent dim. 29/11/2026, Saint-Nicolas dim. 06/12, Noël
 * ven. 25/12, Nouvel An ven. 01/01/2027, Épiphanie mer. 06/01, Blue Monday lun. 18/01, Chandeleur mar. 02/02,
 * Mardi gras mar. 09/02, Saint-Valentin dim. 14/02, Pâques dim. 28/03, fête des mères (Belgique) dim. 09/05, fête
 * des pères dim. 13/06. Montants, paliers et cadeaux : des valeurs de départ, réglables. */
(function () {
  const R = '#8D1D2C', A = '#F2C9A0', C = '#F5EFE4', P = '#78554B', DORE = '#E0A86F', OR = '#B0821A', VERT = '#3f7a52', CHOCO = '#5C3A2E';
  const svg = (inner, label) => `<svg viewBox="0 0 64 64" role="img" aria-label="${label}"><g fill="none" stroke="${R}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${inner}</g></svg>`;
  const sucre = pts => pts.map(([x, y]) => `<circle cx="${x}" cy="${y}" r="1.1" fill="#fff" stroke="none"/>`).join('');

  /* Les illustrations : un trait rubis, des aplats abricot et doré — celles des cases et de l'app. */
  const ILL = {
    cougnou: svg(`<ellipse cx="38" cy="35" rx="19" ry="11" fill="${DORE}"/><circle cx="17" cy="33" r="8" fill="${DORE}"/>
      <path d="M29 25q4-5 9 0M29 45q4 5 9 0"/><circle cx="39" cy="35" r="4.2" fill="${C}"/><path d="M37.4 35h3.2M39 33.4v3.2" stroke-width="1.4"/>
      ${sucre([[27, 31], [31, 38], [47, 30], [50, 38], [45, 41], [15, 30], [19, 36]])}`, 'cougnou'),
    mitre: svg(`<path d="M19 54V31c0-9 6-16 13-23 7 7 13 14 13 23v23z" fill="${A}"/><path d="M19 45h26"/>
      <path d="M32 20v14M26.5 26h11" stroke="${OR}" stroke-width="2.6"/><path d="M24 54l-3 6M40 54l3 6"/>
      <rect x="47" y="44" width="11" height="14" rx="1.5" fill="${DORE}" stroke="${P}" stroke-width="1.6"/><path d="M50 48h5M50 51h5M50 54h5" stroke="${P}" stroke-width="1.2"/>`, 'mitre de saint Nicolas et spéculoos'),
    chocolat: svg(`<path d="M15 27h30v11a15 15 0 0 1-30 0z" fill="${A}"/><ellipse cx="30" cy="27" rx="15" ry="3" fill="${CHOCO}" stroke="${R}"/>
      <path d="M45 31h3a5.5 5.5 0 0 1 0 11h-4"/><path d="M9 56h42"/><path d="M23 20c-2.5-3 2.5-5 0-9M30 20c-2.5-3 2.5-5 0-9M37 20c-2.5-3 2.5-5 0-9"/>`, 'chocolat chaud'),
    epices: svg(`<path d="M32 9a7 7 0 0 1 4.6 12.3l9.4 2.9a3.8 3.8 0 0 1-1.2 7.4l-7.8-1.1 3.6 14.6a3.8 3.8 0 0 1-6.9 2.9L32 40l-3.7 8.9a3.8 3.8 0 0 1-6.9-2.9L25 31.4l-7.8 1.1a3.8 3.8 0 0 1-1.2-7.4l9.4-2.9A7 7 0 0 1 32 9z" fill="${DORE}"/>
      <circle cx="29.5" cy="15.5" r="1.1" fill="${R}" stroke="none"/><circle cx="34.5" cy="15.5" r="1.1" fill="${R}" stroke="none"/><path d="M29.5 19q2.5 2 5 0" stroke-width="1.4"/>
      <circle cx="32" cy="27" r="1.5" fill="#fff" stroke="none"/><circle cx="32" cy="33" r="1.5" fill="#fff" stroke="none"/>
      <path d="M19 27.5l2 1.5 2-1.5M41 27.5l2 1.5 2-1.5" stroke="#fff" stroke-width="1.3"/>`, 'pain d’épices'),
    buche: svg(`<path d="M14 30h32c5 0 8 4.5 8 10s-3 10-8 10H14" fill="${P}" stroke="${R}"/><ellipse cx="14" cy="40" rx="6" ry="10" fill="${A}"/>
      <path d="M14 36.5a3 3 0 1 1-2.6 4.6 1.8 1.8 0 0 1 2.9-2" stroke-width="1.6"/><path d="M24 37c4 2 10-1 15 1M23 44c5-1 10 2 17 0" stroke="${C}" stroke-width="1.5"/>
      <path d="M33 30c-3-4 1-7-1-11 4 2 6-1 9 2-2 2-1 6-8 9z" fill="${VERT}" stroke="${VERT}" stroke-width="1.4"/>
      <path d="M36 30c4-3 3-7 7-8-1 3 3 4 1 7z" fill="${VERT}" stroke="${VERT}" stroke-width="1.4"/>
      <circle cx="34" cy="29" r="2.2" fill="${R}" stroke="none"/><circle cx="38" cy="30" r="2.2" fill="${R}" stroke="none"/>`, 'bûche de Noël'),
    flutes: svg(`<g transform="rotate(-14 24 32)"><path d="M18 12h12l-1.2 16a4.8 4.8 0 0 1-9.6 0z" fill="${A}"/><path d="M24 33v14M19 48h10"/></g>
      <g transform="rotate(14 40 32)"><path d="M34 12h12l-1.2 16a4.8 4.8 0 0 1-9.6 0z" fill="${A}"/><path d="M40 33v14M35 48h10"/></g>
      <path d="M32 3v6M29 6h6M10 10l2 2M54 10l-2 2M8 22h3M53 22h3" stroke="${OR}"/><circle cx="25" cy="19" r="1" fill="${R}" stroke="none"/><circle cx="39" cy="17" r="1" fill="${R}" stroke="none"/>`, 'deux flûtes de champagne'),
    galette: svg(`<path d="M10 42v4c0 5.5 10 10 22 10s22-4.5 22-10v-4" fill="#D18F55"/><ellipse cx="32" cy="42" rx="22" ry="9" fill="${DORE}"/>
      <path d="M17 41q15-7 30 0M19 45q13-6 26 0M22 38q10-4 20 0" stroke-width="1.5"/>
      <path d="M20 31l2-15 6.5 7.5L32 14l3.5 9.5L42 16l2 15z" fill="#E8B44A" stroke="${OR}"/><circle cx="32" cy="27" r="1.6" fill="${R}" stroke="none"/>`, 'galette des rois et sa couronne'),
    pain: svg(`<path d="M9 43c0-12 10.5-21 23-21s23 9 23 21c0 5-4 8-8 8H17c-4 0-8-3-8-8z" fill="${DORE}"/>
      <path d="M21 31l6 12M32 27v16M43 31l-6 12" stroke-width="2"/>${sucre([[16, 40], [48, 40], [26, 26], [38, 26], [32, 47]])}
      <path d="M8 57h48" stroke-width="1.6"/>`, 'miche de pain'),
    eclair: svg(`<rect x="8" y="28" width="48" height="17" rx="8.5" fill="${DORE}"/><path d="M10 33c0-3 3-5 6-5h32c3 0 6 2 6 5-4 2-7-1-11 1s-7-1-11 1-7-1-11 1-7-1-11 0z" fill="${CHOCO}" stroke="${R}"/>
      <path d="M44 14c0-4 6-4 6 0 0 3-3 5-3 5s-3-2-3-5z" fill="${A}" stroke-width="1.6"/><path d="M14 20h10" stroke="#3B6DB3" stroke-width="2"/>`, 'éclair au chocolat'),
    croissant: svg(`<path d="M7 41c4-14 16-22 25-22s21 8 25 22c-4 2-8 0-10-3-2 6-8 9-15 9s-13-3-15-9c-2 3-6 5-10 3z" fill="${DORE}"/>
      <path d="M21 25c2.5 6 2.5 13 0 19M32 21v26M43 25c-2.5 6-2.5 13 0 19" stroke-width="1.8"/>`, 'croissant'),
    crepes: svg(`<ellipse cx="32" cy="50" rx="25" ry="6" fill="${C}"/><path d="M11 45c0-3 9-5 21-5s21 2 21 5-9 5-21 5-21-2-21-5z" fill="${A}"/>
      <path d="M12 40c0-3 9-5 20-5s20 2 20 5-9 5-20 5-20-2-20-5z" fill="${A}"/><path d="M13 35c0-3 8.5-5 19-5s19 2 19 5-8.5 5-19 5-19-2-19-5z" fill="${DORE}"/>
      <path d="M22 34c3 2 6-1 10 1s7-1 10 0" stroke="${CHOCO}" stroke-width="2.4"/><path d="M42 35v5" stroke="${CHOCO}" stroke-width="2.4"/>
      <path d="M44 14c3 0 6 3 6 7s-3 5-6 5-6-2-6-5 3-7 6-7z" fill="#F4D35E" stroke="${OR}" stroke-width="1.6"/>`, 'pile de crêpes'),
    coeur: svg(`<path d="M27 50C11 39 7 30 11 22.5c3.6-6.4 11.6-6.4 16 0 4.4-6.4 12.4-6.4 16 0 4 7.5 0 16.5-16 27.5z" fill="${A}"/>
      <path d="M27 44C17 37 14 31 16 26.5" stroke-width="1.4"/>${['20,27', '27,31', '34,27', '27,38', '21,34', '33,34'].map(p => `<circle cx="${p.split(',')[0]}" cy="${p.split(',')[1]}" r="2" fill="${R}" stroke="none"/>`).join('')}
      <circle cx="49" cy="47" r="9" fill="${DORE}"/><circle cx="49" cy="47" r="2.6" fill="${C}"/>${sucre([[44, 43], [53, 43], [45, 51], [54, 50], [49, 40]])}`, 'tartelette en cœur et beignet'),
    cadeau: svg(`<rect x="12" y="26" width="40" height="28" rx="2" fill="${A}"/><path d="M10 20h44v8H10z" fill="${DORE}"/><path d="M32 20v34"/>
      <path d="M32 20c-4-8-14-8-12-2 1 3 8 3 12 2zM32 20c4-8 14-8 12-2-1 3-8 3-12 2z"/>`, 'cadeau'),
  };

  /* La carte d'hiver 2026-2027 : 12 semaines, du lundi 23/11/2026 au dimanche 14/02/2027. */
  const SEMAINES = [
    { n: 1, du: '23/11', au: '29/11', titre: 'Le cougnou est là', sous: '1er dimanche de l’Avent', fete: 'Avent', jour: '29/11', ill: 'cougnou' },
    { n: 2, du: '30/11', au: '06/12', titre: 'Saint-Nicolas', sous: 'spéculoos et massepain', fete: 'Saint-Nicolas', jour: '06/12', ill: 'mitre' },
    { n: 3, du: '07/12', au: '13/12', titre: 'Le chocolat chaud', sous: 'produit de la semaine', ill: 'chocolat' },
    { n: 4, du: '14/12', au: '20/12', titre: 'Le pain d’épices', sous: 'produit de la semaine', ill: 'epices' },
    { n: 5, du: '21/12', au: '27/12', titre: 'Noël', sous: 'la bûche', fete: 'Noël', jour: '25/12', ill: 'buche' },
    { n: 6, du: '28/12', au: '03/01', titre: 'Nouvel An', sous: 'les petits fours', fete: 'Nouvel An', jour: '01/01', ill: 'flutes' },
    { n: 7, du: '04/01', au: '10/01', titre: 'Épiphanie', sous: 'la galette des rois', fete: 'Épiphanie', jour: '06/01', ill: 'galette' },
    { n: 8, du: '11/01', au: '17/01', titre: 'Le bon pain', sous: 'produit de la semaine', ill: 'pain' },
    { n: 9, du: '18/01', au: '24/01', titre: 'Blue Monday', sous: 'un éclair pour le moral', ill: 'eclair' },
    { n: 10, du: '25/01', au: '31/01', titre: 'Le croissant', sous: 'produit de la semaine', ill: 'croissant' },
    { n: 11, du: '01/02', au: '07/02', titre: 'Chandeleur', sous: 'les crêpes', fete: 'Chandeleur', jour: '02/02', ill: 'crepes' },
    { n: 12, du: '08/02', au: '14/02', titre: 'Saint-Valentin', sous: 'beignets de Mardi gras', fete: 'Mardi gras · Saint-Valentin', jour: '09 et 14/02', ill: 'coeur' },
  ];

  /* Les réglages (valeurs de départ, réglables au cockpit). */
  const REGLES = {
    seuil: 10,                    // euros dépensés dans la semaine pour cocher sa case
    parSemaine: 1,                // une case par semaine au plus
    paliers: [
      { n: 4, cadeau: 'une viennoiserie offerte' },
      { n: 8, cadeau: 'un café et sa douceur' },
      { n: 10, cadeau: 'une tarte de 4 parts' },
      { n: 12, cadeau: 'le tirage au sort : un brunch pour 4', tirage: true },
    ],
  };

  /* L'année : quatre cartes de 12 semaines, plus la semaine des cadeaux entre deux cartes. */
  const SAISONS = [
    { nom: 'Hiver', du: '23/11/2026', au: '14/02/2027', cadeaux: '15 – 21/02', fetes: ['Avent', 'Saint-Nicolas', 'Noël', 'Nouvel An', 'Épiphanie', 'Chandeleur', 'Mardi gras', 'Saint-Valentin'], etat: 'en cours' },
    { nom: 'Printemps', du: '22/02/2027', au: '16/05/2027', cadeaux: '17 – 23/05', fetes: ['Pâques 28/03', '1er avril', 'Fête du travail', 'Ascension', 'Fête des mères 09/05'], etat: 'à préparer' },
    { nom: 'Été', du: '24/05/2027', au: '15/08/2027', cadeaux: '16 – 22/08', fetes: ['Fête des pères 13/06', 'Fête de la musique', 'Fête nationale 21/07', '15 août'], etat: '' },
    { nom: 'Automne', du: '23/08/2027', au: '14/11/2027', cadeaux: '15 – 21/11', fetes: ['La rentrée', 'Fête de la FWB 27/09', 'Halloween', 'Toussaint', 'Saint-Martin 11/11'], etat: '' },
  ];

  /* Une cliente, le jeudi 14 janvier 2027 (semaine 8) : six cases cochées, la semaine du Nouvel An manquée. */
  const CLIENTE = {
    prenom: 'Julie', aujourdhui: 'jeudi 14 janvier', semaine: 8, depense: 6.4,
    cochees: { 1: '27/11', 2: '05/12', 3: '09/12', 4: '19/12', 5: '24/12', 7: '06/01' },
    manquees: [6],
  };

  window.BINGO = { ILL, SEMAINES, REGLES, SAISONS, CLIENTE };
})();
