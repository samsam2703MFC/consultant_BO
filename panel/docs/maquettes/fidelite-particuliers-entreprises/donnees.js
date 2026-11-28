/* Données FICTIVES des maquettes « Fidélité : points et bingo, particuliers et entreprises » (28/11/2026).
 * Noms de clients et de sociétés inventés. La forme suit l'écran réel (Fidélité › Cartes de fidélité, Halle) et les
 * règles du bingo (shared/src/data/fidelite_bingo.json) : la case de la semaine se coche dès 10 € d'achats du lundi au
 * dimanche, tous magasins ; toutes les 3 cases, un bon de 2 € ; 13 / 13, le super bon de 10 €. La carte à points :
 * 1 point par euro, 100 points = un bon de 5 €. Samedi 28/11/2026 : semaine 1 sur 13, il reste dimanche. */
window.FE = {
  magasin: 'Atelier by - Halle',
  lu: '10:14',
  seuil: 10, tousLes: 3, bonBingo: 2, superBon: 10, palier: 100, bonPoints: 5,
  carte: { nom: 'Carte L’Atelier', couleur: '#8D1D2C', sous: '1 point par euro · 100 points = un bon de 5 €' },
  bingo: { nom: 'Bingo Hiver 2026 – 2027', couleur: '#2E6385', sous: 'la case de la semaine dès 10 € · un bon de 2 € toutes les 3 cases',
    du: '23/11/2026', au: '21/02/2027', tirage: '22/02/2027', sem: 1, reste: 'il reste dimanche',
    semaines: [
      ['23/11', 'Le cougnou est là'], ['30/11', 'Saint-Nicolas'], ['07/12', 'Le chocolat chaud'], ['14/12', 'Le pain d’épices'],
      ['21/12', 'Noël'], ['28/12', 'Nouvel An'], ['04/01', 'Épiphanie'], ['11/01', 'Le bon pain'], ['18/01', 'Blue Monday'],
      ['25/01', 'Le croissant'], ['01/02', 'Chandeleur'], ['08/02', 'Saint-Valentin'], ['15/02', 'La tarte du dimanche']] },
  reconnus90: 202,

  /* Les particuliers : pts = solde de la carte (tous magasins), sem = achats de la semaine en cours (tous magasins),
   * achats / montant = dans ce magasin, ce mois. 12 montrés, les 14 autres résumés dans `autres`. */
  particuliers: [
    { n: 'Annelies V.', pts: 142, sem: 12.80, achats: 6, montant: 142.30, dernier: 'aujourd’hui' },
    { n: 'Sofie P.', pts: 118, sem: 23.10, achats: 5, montant: 118.40, dernier: 'hier' },
    { n: 'Nadia L.', pts: 105, sem: 31.00, achats: 4, montant: 105.20, dernier: 'il y a 2 j' },
    { n: 'Marc D.', pts: 96, sem: 7.40, achats: 7, montant: 96.10, dernier: 'il y a 3 j' },
    { n: 'Els J.', pts: 88, sem: 15.60, achats: 5, montant: 88.70, dernier: 'aujourd’hui' },
    { n: 'Julie R.', pts: 74, sem: 10.40, achats: 3, montant: 74.90, dernier: 'il y a 4 j' },
    { n: 'Karim B.', pts: 64, sem: 0, achats: 4, montant: 64.20, dernier: 'il y a 9 j' },
    { n: 'Gert H.', pts: 47, sem: 9.10, achats: 3, montant: 47.60, dernier: 'il y a 2 j' },
    { n: 'Thomas W.', pts: 31, sem: 8.20, achats: 2, montant: 31.50, dernier: 'hier' },
    { n: 'Lotte C.', pts: 28, sem: 11.90, achats: 2, montant: 28.30, dernier: 'aujourd’hui' },
    { n: 'Pieter M.', pts: 12, sem: 4.50, achats: 1, montant: 12.40, dernier: 'il y a 5 j' },
    { n: 'Hanne S.', pts: 6, sem: 0, achats: 1, montant: 6.20, dernier: 'il y a 21 j' },
  ],
  autres: { n: 14, cochees: 8, presque: 1, pas: 5, tranches: [3, 3, 4, 4, 0] },
  gagnesPart: 2140,

  /* Les entreprises : soc = le compte de la société (null : la société n'a pas de carte), gens = ses personnes inscrites. */
  entreprises: [
    { n: 'Brouwerij Zennevallei', bur: 'bureau Zennevallei · siège, Halle', mode: 'facturé à la société', pers: 23,
      soc: { pts: 340, sem: 186.00 }, achats: 38, montant: 1486.20, dernier: 'aujourd’hui',
      gens: [{ n: 'Bart V.', pts: 104, sem: 14.20 }, { n: 'Ilse D.', pts: 86, sem: 11.00 }, { n: 'Wim C.', pts: 73, sem: 22.60 },
        { n: 'Katrien M.', pts: 61, sem: 10.80 }, { n: 'Jonas P.', pts: 58, sem: 12.40 }, { n: 'Eva L.', pts: 49, sem: 7.90 },
        { n: 'Dries B.', pts: 44, sem: 16.00 }, { n: 'Sarah G.', pts: 38, sem: 0 }, { n: 'Tom H.', pts: 29, sem: 4.20 }] },
    { n: 'Fiduciaire Vandamme', bur: 'bureau Basiliekstraat, Halle', mode: 'chaque personne paie', pers: 11,
      soc: null, achats: 22, montant: 412.60, dernier: 'hier',
      gens: [{ n: 'Lien V.', pts: 97, sem: 13.40 }, { n: 'Koen A.', pts: 81, sem: 8.60 }, { n: 'Marie T.', pts: 66, sem: 10.20 },
        { n: 'Yves R.', pts: 44, sem: 18.00 }, { n: 'Ann B.', pts: 30, sem: 0 }] },
    { n: 'Garage Lembeek SRL', bur: 'bureau de l’atelier, Lembeek', mode: 'facturé à la société', pers: 7,
      soc: { pts: 128, sem: 64.00 }, achats: 9, montant: 128.40, dernier: 'il y a 2 j', gens: [] },
    { n: 'Thermotech Halle BV', bur: 'bureau zoning Zuid, Halle', mode: 'chaque personne paie', pers: 15,
      soc: null, achats: 14, montant: 236.90, dernier: 'aujourd’hui',
      gens: [{ n: 'Michiel D.', pts: 71, sem: 12.10 }, { n: 'Sven K.', pts: 63, sem: 7.80 }, { n: 'Ruben F.', pts: 48, sem: 10.60 },
        { n: 'Laura N.', pts: 30, sem: 8.50 }] },
  ],
  gagnesEnt: 1540,

  /* Le réseau, même période (fictif) : particuliers, sociétés, personnes d'entreprise, points gagnés, cases cochées. */
  reseau: [
    { nom: 'Atelier by Berlo - Corbais', p: 71, s: 9, g: 30, pts: 9120, cases: 58 },
    { nom: 'Atelier by Max & Sandra - Gosselies', p: 0, s: 0, g: 0, pts: 0, cases: 0 },
    { nom: 'Atelier by Harmonie - Sombreffe', p: 24, s: 1, g: 4, pts: 1310, cases: 17 },
    { nom: 'Atelier by - Halle', p: 26, s: 4, g: 18, pts: 3680, cases: 27, moi: true },
  ],
};
