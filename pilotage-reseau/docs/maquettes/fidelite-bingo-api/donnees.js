// Données de la maquette « bingo par l'API ». Charge après ../fidelite-bingo-erp/donnees.js (icônes, v(), SHOPS,
// apercuPoints). Règles et saisons : copiées de src/data/fidelite_bingo.json (le fichier que lit src/fidelite_bingo.php).
// Clients et chiffres : fictifs. La maquette est datée du jeudi 14/01/2027, semaine 8 de l'hiver.
'use strict';

const CONF = { carte: 'points-atelier', seuilEuros: 10, tousLes: 3, validite: 30, bons: [2, 2, 2, 2], superBon: 10, multiplicateurCase: 2, palier: { points: 100, valeur: 5 } };
const H27 = [
  ['Le cougnou est là', '1er dimanche de l’Avent', '29/11', 'bread-2'], ['Saint-Nicolas', 'spéculoos et massepain', '06/12', 'saint-nicolas'],
  ['Le chocolat chaud', 'produit de la semaine', '', 'chocolat'], ['Le pain d’épices', 'produit de la semaine', '', 'cookies'],
  ['Noël', 'la bûche', '25/12', 'noel'], ['Nouvel An', 'les petits fours', '01/01', 'artboard-4'], ['Épiphanie', 'la galette des rois', '06/01', 'galette'],
  ['Le bon pain', 'produit de la semaine', '', 'bread-1'], ['Blue Monday', 'un éclair pour le moral', '', 'cake-slice'],
  ['Le croissant', 'produit de la semaine', '', 'croissant'], ['Chandeleur', 'les crêpes', '02/02', 'crepes'],
  ['Saint-Valentin', 'beignets de Mardi gras', '14/02', 'valentin'], ['La tarte du dimanche', 'produit de la semaine', '', 'tarte'],
].map(([titre, sous, fete, ill], i) => {
  const d = new Date(Date.UTC(2026, 10, 23 + 7 * i)), f = new Date(Date.UTC(2026, 10, 29 + 7 * i));
  const jm = (x) => String(x.getUTCDate()).padStart(2, '0') + '/' + String(x.getUTCMonth() + 1).padStart(2, '0');
  return { n: i + 1, du: jm(d), au: jm(f), titre, sous, fete, ill };
});
const BONS_H27 = [['Bon de l’Avent', 'cake'], ['Bon des Rois', 'tartelette'], ['Bon de l’hiver', 'hiver'], ['Bon carte pleine', 'cupcake']];
const SAISONS = [
  { id: 'H27', nom: 'Hiver 2026 – 2027', du: '23/11/2026', au: '21/02/2027', camp: 'Bingo hiver', ill: 'hiver', etat: 'en cours · S8 / 13', valider: false },
  { id: 'P27', nom: 'Printemps 2027', du: '22/02/2027', au: '23/05/2027', camp: 'Bingo printemps', ill: 'printemps', etat: 'thèmes à valider', valider: true },
  { id: 'E27', nom: 'Été 2027', du: '24/05/2027', au: '22/08/2027', camp: 'Bingo été', ill: 'ete', etat: 'thèmes à valider', valider: true },
  { id: 'A27', nom: 'Automne 2027', du: '23/08/2027', au: '21/11/2027', camp: 'Bingo automne', ill: 'automne', etat: 'thèmes à valider', valider: true },
];
const SEMAINE = 8; // jeudi 14/01/2027

// La carte à points de l'ERP (structure existante uniquement : points_config).
const CARTE_PTS = {
  name: 'Carte points L’Atelier', code: 'points-atelier', starts_at: '2026-11-22 23:00:00', ends_at: '',
  points_config: { stamps_enabled: false, points_enabled: true, points_per_slice: 1, slice_cents: 100, voucher_validity_days: 30, points_validity_days: 365,
    shop_coefficients: { 2: '1', 3: '1', 4: '1', 5: '1' }, bonuses: [{ name: 'Après-midi calme', days: [2], from: '14:00', to: '17:00', coefficient: '2' }], tiers: [{ points: 100, voucher_cents: 500 }] },
};

// Halle, semaine 8 : la part des membres qui ont coché la case, semaine par semaine (lu : points gagnés par semaine).
const PARTS = [41, 46, 38, 44, 52, 31, 43, 28];
const MEMBRES = [
  { nom: 'Julie D.', cases: 'cccccmce', pts: 78, sem: 6.4, bons: '1 utilisé · 1 à utiliser', der: 'jeu. 14/01' },
  { nom: 'Marc V.', cases: 'cccccccc', pts: 142, sem: 18.2, bons: '2 à utiliser', der: 'mer. 13/01' },
  { nom: 'Sophie L.', cases: 'ccmccmce', pts: 51, sem: 3.1, bons: '1 à utiliser', der: 'lun. 11/01' },
  { nom: 'Nadia B.', cases: 'mccccccc', pts: 96, sem: 12.6, bons: '2 à utiliser', der: 'mar. 12/01' },
  { nom: 'Thomas R.', cases: 'ccmmmcme', pts: 23, sem: 0, bons: '—', der: 'jeu. 07/01' },
  { nom: 'Élise M.', cases: 'ccccmmce', pts: 64, sem: 8.9, bons: '1 à utiliser', der: 'jeu. 14/01' },
];
const RELANCES = [
  { nom: 'Élise M.', txt: '8,90 € cette semaine : encore 1,10 € d’ici dimanche', tag: 'case' },
  { nom: 'Julie D.', txt: '6,40 € cette semaine : encore 3,60 € d’ici dimanche', tag: 'case' },
  { nom: 'Sophie L.', txt: '3,10 € cette semaine · la case de dimanche lui donne son 2e bon', tag: 'bon' },
  { nom: 'Thomas R.', txt: 'pas revenu depuis le 07/01 · S7 manquée', tag: 'absent' },
];

// La carte de Julie D. (le scénario du handoff) : S6 manquée, S8 en cours à 6,40 €.
const JULIE = { coche: { 1: '27/11', 2: '05/12', 3: '09/12', 4: '18/12', 5: '24/12', 7: '06/01' }, manque: [6], cours: 6.4, points: 78,
  bons: [{ i: 1, k: 3, etat: 'utilise', le: '09/12', fin: '08/01', util: '17/12', code: 'FIDR4WQ8NZC' }, { i: 2, k: 6, etat: 'pris', le: '06/01', fin: '05/02', code: 'FID7K2QM9PA' }] };

// ── La carte imprimée de la saison (A6), d'après le fichier de saison ─────────────
function carteSaison(x = CONF.tousLes) {
  let cells = '', i = 0;
  H27.forEach((s, idx) => {
    const lab = s.fete ? `<span class="lab full">Fête · ${s.fete}</span>` : '<span class="lab">À l’honneur</span>';
    const sous = s.sous !== 'produit de la semaine' ? `<small>${s.sous}</small>` : '';
    cells += `<div class="w ${s.fete ? 'fete' : ''}"><div class="w-top"><b>S${s.n}</b><span>${s.du} – ${s.au}</span></div>${lab}<img src="${ILL}${s.ill}.png" alt=""><strong>${s.titre}</strong>${sous}</div>`;
    if ((idx + 1) % x === 0) {
      const [nom, ill] = BONS_H27[i]; i++;
      cells += `<div class="w b"><div class="w-top"><span>Bon ${i}</span><span>${i * x}e case</span></div><span class="lab">${CONF.bons[i - 1]} €</span><img src="${ILL}${ill}.png" alt=""><strong>${nom}</strong><small>${CONF.validite} jours</small></div>`;
    }
  });
  cells += `<div class="sup" style="grid-column:span ${x + 1 - (H27.length % x)}"><div class="fr"><img src="${ILL}cake.png" alt=""><div><span class="lab">Carte pleine · 13 / 13</span><h5>SUPER BON CADEAU</h5>Les 13 cases cochées : ${CONF.superBon} € offerts, valables ${CONF.validite} jours.</div></div></div>`;
  return `<div class="bc a6"><div class="bc-head"><img src="${LOGO}" alt=""><div class="bc-kick">Carte fidélité<br>Hiver 2026 – 2027</div></div>
    <div class="bc-title"><span class="bc-bingo">BINGO</span><span class="bc-script">d’hiver</span></div>
    <p class="bc-lead">Du 23/11 au 21/02 · <em>une case par semaine, et toutes les ${x} cases, un bon.</em></p>
    <div class="bc-grid" style="grid-template-columns:repeat(${x + 1},1fr)">${cells}</div>
    <div class="bc-foot"><div><h4>Comment ça marche</h4>Chaque semaine où vous dépensez ${CONF.seuilEuros} € ou plus chez nous, la case de la semaine se coche toute seule. Toutes les ${x} cases, un bon de 2 € arrive à votre nom.<br><em>Et vos points :</em> 1 point par euro, ${CONF.palier.points} points = ${CONF.palier.valeur} € offerts.</div>
    <div class="bc-merci">Merci de votre fidélité ♡</div></div></div>`;
}
