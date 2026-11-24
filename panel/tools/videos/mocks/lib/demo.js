/* Le magasin de démo : un générateur déterministe (graine = date) des journées, des produits et de l'équipe.
 * Tout est fictif. Utilisé par mocks/00_commun.js, 10_ops.js, 15_chiffres.js. */
const AUJ = '2026-11-19', MAINTENANT = 15 * 60 + 40, SHOP = '4', NOM = 'Magasin démo';
const r2 = v => Math.round(v * 100) / 100, r1 = v => Math.round(v * 10) / 10;
function graine(s) { let h = 2166136261; for (const c of String(s)) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; }
function alea(s) { let a = graine(s); return () => { a |= 0; a = a + 0x6D2B79F5 | 0; let t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
const bruit = (s, amp) => { const r = alea(s); return 1 + (r() * 2 - 1) * amp; };
const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const jourDe = s => new Date(s + 'T12:00:00');
const plus = (s, n) => { const d = jourDe(s); d.setDate(d.getDate() + n); return iso(d); };
const dow = s => jourDe(s).getDay();
const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const HEURES = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18];
// La journée type, par jour de la semaine (dimanche = 0) : CA TTC, panier, poids des heures 7 → 18.
const BASE = [2350, 1450, 1520, 1640, 1800, 2080, 2650];
const PANIER = [14.6, 12.1, 12.2, 12.4, 12.5, 12.9, 14.2];
const POIDS_SEM = [0.06, 0.10, 0.11, 0.09, 0.09, 0.12, 0.11, 0.07, 0.06, 0.07, 0.07, 0.05];
const POIDS_WE = [0.05, 0.09, 0.12, 0.13, 0.12, 0.12, 0.09, 0.06, 0.06, 0.06, 0.06, 0.04];
const poids = s => (dow(s) === 0 || dow(s) === 6) ? POIDS_WE : POIDS_SEM;
// La tendance : novembre un peu au-dessus de l'an passé, une saison (été plus calme).
const SAISON = [0.92, 0.9, 0.96, 1.0, 1.02, 0.98, 0.9, 0.86, 0.98, 1.0, 1.03, 1.12];
function caJourPlein(s) {
  const d = jourDe(s), base = BASE[d.getDay()] * SAISON[d.getMonth()] * (d.getFullYear() === 2025 ? 0.94 : 1);
  return base * bruit('ca' + s, 0.09);
}
const objectifJour = s => Math.round(BASE[dow(s)] * SAISON[jourDe(s).getMonth()] * 1.03 / 10) * 10;
// Le B2B : deux clients pro les jours de semaine (une crèche le matin, un bureau à midi).
function proDe(s) {
  const j = dow(s); if (j === 0 || j === 6) { return []; }
  const r = alea('pro' + s);
  const L = [{ h: 8, ca: r2(62 + r() * 30), n: 1 }];
  if (r() > 0.25) { L.push({ h: 11, ca: r2(48 + r() * 40), n: 1 }); }
  return L;
}
/** Une journée : heures (h, ca, tickets, pro, proN), totaux ; `jusqua` (minutes) arrête la journée en cours. */
function journee(s, jusqua) {
  const plein = caJourPlein(s), P = poids(s), pan = PANIER[dow(s)], pros = proDe(s);
  const fac = s === AUJ ? 1.045 : 1;   // aujourd'hui, un peu mieux que d'habitude
  const heures = [];
  HEURES.forEach((h, i) => {
    let part = 1;
    if (jusqua != null) { if (h * 60 >= jusqua) { return; } part = Math.min(1, (jusqua - h * 60) / 60); }
    const pr = pros.find(x => x.h === h);
    const caC = plein * fac * P[i] * bruit('h' + s + h, 0.12) * part;
    const tk = Math.max(1, Math.round(caC / (pan * bruit('p' + s + h, 0.08))));
    heures.push({ h, ca: r2(caC + (pr ? pr.ca * (part >= 1 ? 1 : 0) : 0)), tickets: tk + (pr && part >= 1 ? pr.n : 0), pro: pr && part >= 1 ? pr.ca : 0, proN: pr && part >= 1 ? pr.n : 0 });
  });
  const ca = r2(heures.reduce((a, x) => a + x.ca, 0)), tickets = heures.reduce((a, x) => a + x.tickets, 0);
  const caPro = r2(heures.reduce((a, x) => a + x.pro, 0)), ticketsPro = heures.reduce((a, x) => a + x.proN, 0);
  return { date: s, heures, ca, tickets, caPro, ticketsPro, caComptoir: r2(ca - caPro), ticketsComptoir: tickets - ticketsPro };
}
// Les taux du P&L (HT) d'une journée, autour des cibles, avec un peu de bruit.
function pnl(s, ca) {
  const caHt = ca / 1.06, r = alea('pnl' + s);
  const mat = caHt * (0.315 + r() * 0.03), inv = caHt * (0.01 + r() * 0.012), lab = caHt * (0.165 + r() * 0.04), oh = caHt * 0.142;
  const mb = caHt - mat, net = mb - inv - lab - oh;
  return { caHt: r2(caHt), tva: r2(ca - caHt), coutMatiere: r2(mat), margeBrute: r2(mb), invendus: r2(inv), labour: r2(lab), overhead: r2(oh), net: r2(net) };
}

/* Les groupes, catégories et produits du magasin : prix TTC, taux de marge brute, part du CA, durée de vie, quantité par jour. */
const CATALOGUE = [
  ['Viennoiserie', 'Croissants', 0.70, [['Croissant au beurre', 1.40, 'S', 95], ['Croissant aux amandes', 2.40, 'S', 18], ['Mini-croissant', 0.80, 'S', 30]]],
  ['Viennoiserie', 'Pains au chocolat', 0.69, [['Pain au chocolat', 1.60, 'S', 80], ['Pain aux raisins', 1.90, 'S', 22], ['Chausson aux pommes', 2.10, 'S', 20]]],
  ['Viennoiserie', 'Viennoiseries garnies', 0.66, [['Brioche au sucre', 2.20, 'S', 14], ['Suisse pépites', 2.30, 'S', 16]]],
  ['Pains', 'Baguettes', 0.78, [['Baguette tradition', 1.50, 'S', 70], ['Baguette céréales', 1.80, 'S', 22]]],
  ['Pains', 'Pains spéciaux', 0.74, [['Pain de campagne', 3.80, 'M', 18], ['Pain aux noix', 4.20, 'M', 9], ['Pain complet', 3.60, 'M', 12]]],
  ['Pâtisserie', 'Pâtisseries individuelles', 0.62, [['Éclair au chocolat', 3.20, 'S', 22], ['Millefeuille', 3.80, 'S', 14], ['Tartelette aux fruits', 3.90, 'S', 15], ['Paris-brest', 4.20, 'S', 9]]],
  ['Pâtisserie', 'Tartes', 0.64, [['Tarte aux pommes (part)', 3.20, 'M', 16], ['Tarte au citron meringuée', 3.60, 'M', 12], ['Flan pâtissier (part)', 2.90, 'M', 18]]],
  ['Pâtisserie', 'Entremets', 0.60, [['Entremets trois chocolats', 24.0, 'M', 2], ['Fraisier 6 parts', 26.0, 'M', 1.5]]],
  ['Snacking', 'Sandwichs', 0.55, [['Sandwich jambon-beurre', 4.90, 'S', 26], ['Sandwich poulet crudités', 5.50, 'S', 22], ['Wrap végétarien', 5.20, 'S', 12]]],
  ['Snacking', 'Salades et bowls', 0.52, [['Salade César', 7.90, 'S', 8], ['Bowl quinoa-légumes', 8.50, 'S', 6]]],
  ['Boissons', 'Boissons chaudes', 0.80, [['Café', 2.20, 'L', 40], ['Cappuccino', 3.20, 'L', 22], ['Thé', 2.40, 'L', 10]]],
  ['Boissons', 'Boissons fraîches', 0.68, [['Jus d’orange pressé', 3.50, 'L', 12], ['Eau plate 50 cl', 1.60, 'L', 14]]],
  ['Biscuiterie', 'Biscuits et cookies', 0.58, [['Cookie chocolat', 2.20, 'L', 24], ['Sablés au beurre (sachet)', 4.50, 'L', 7], ['Madeleines (sachet)', 4.20, 'L', 6]]],
  ['Biscuiterie', 'Confiserie', 0.55, [['Pâte à tartiner maison', 7.90, 'L', 2], ['Nougat (barre)', 3.90, 'L', 4]]],
];
let _pid = 100;
const PRODUITS = [];
CATALOGUE.forEach(([groupe, cat, taux, L]) => L.forEach(([nom, prix, vie, q]) => PRODUITS.push({ pid: _pid++, groupe, cat, taux, nom, prix, vie, q })));
/** Les catégories d'une journée vendue (CA TTC) : chaque produit au prorata de sa quantité × prix. */
function categoriesDe(s, ca) {
  const tot = PRODUITS.reduce((a, p) => a + p.q * p.prix, 0);
  const C = {};
  PRODUITS.forEach(p => {
    const k = bruit('cat' + s + p.pid, 0.18), v = ca * p.q * p.prix / tot * k;
    const c = C[p.cat] || (C[p.cat] = { nom: p.cat, groupe: p.groupe, v: 0, c: 0, q: 0, refs: 0, produits: [] });
    const q = Math.max(1, Math.round(v / p.prix)), vv = r2(q * p.prix), taux = p.taux * bruit('t' + p.pid, 0.04);
    c.v += vv; c.c += vv / 1.06 * (1 - taux); c.q += q; c.refs++;
    c.produits.push({ id: String(p.pid), nom: p.nom, q, v: vv, c: r2(vv / 1.06 * (1 - taux)), m: r2(vv / 1.06 * taux), taux: r1(100 * taux), vHt: r2(vv / 1.06), mHt: r2(vv / 1.06 * taux), tauxHt: r1(100 * taux) });
  });
  const somme = Object.values(C).reduce((a, c) => a + c.v, 0);
  return Object.values(C).map(c => {
    const vHt = c.v / 1.06, m = vHt - c.c;
    c.produits.sort((a, b) => b.v - a.v).forEach(p => { p.part = r1(100 * p.v / c.v); });
    return { nom: c.nom, groupe: c.groupe, v: r2(c.v), part: r1(100 * c.v / somme), c: r2(c.c), m: r2(m), taux: r1(100 * m / vHt), refs: c.refs, q: c.q, vChiffre: r2(c.v), nSans: 0, vSans: 0,
      vHt: r2(vHt), mHt: r2(m), tauxHt: r1(100 * m / vHt), vChiffreHt: r2(vHt), produits: c.produits };
  }).sort((a, b) => b.v - a.v);
}

/* L'équipe (prénoms fictifs) et le planning d'un jour. */
const EQUIPE = [
  { id: 11, nom: 'Camille', initiale: 'Camille R.', taux: 0, franchise: true, postes: ['Responsable'] },
  { id: 12, nom: 'Julie', initiale: 'Julie M.', taux: 16.2, postes: ['Comptoir'] },
  { id: 13, nom: 'Thomas', initiale: 'Thomas D.', taux: 17.1, postes: ['Fournil'] },
  { id: 14, nom: 'Inès', initiale: 'Inès B.', taux: 15.4, postes: ['Comptoir'] },
  { id: 15, nom: 'Lucas', initiale: 'Lucas V.', taux: 15.8, postes: ['Snacking'] },
  { id: 16, nom: 'Sarah', initiale: 'Sarah K.', taux: 14.9, postes: ['Comptoir'] },
  { id: 17, nom: 'Nora', initiale: 'Nora L.', taux: 15.1, postes: ['Comptoir'] },
];
function planningDe(s) {
  const we = dow(s) === 0 || dow(s) === 6;
  const L = [['Thomas', '05:30', '12:30'], ['Julie', '06:45', '14:15'], ['Camille', '07:00', '15:00'], ['Lucas', '09:00', '15:30'], ['Inès', '13:30', '19:15'], ['Sarah', '14:00', '19:15']];
  if (we) { L.push(['Nora', '08:00', '14:00']); }
  return L.map(([n, d, f]) => {
    const e = EQUIPE.find(x => x.nom === n), h = (toMin(f) - toMin(d)) / 60;
    return { debut: d, fin: f, nom: e.initiale, h: r2(h), postes: e.postes, tauxH: e.taux, franchise: !!e.franchise, horsPnl: !!e.franchise, cout: r2(h * e.taux) };
  });
}
const toMin = hm => { const p = String(hm).split(':'); return +p[0] * 60 + +p[1]; };
const hm = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(Math.round(m % 60)).padStart(2, '0');

module.exports = { AUJ, MAINTENANT, SHOP, NOM, r2, r1, alea, bruit, iso, jourDe, plus, dow, JOURS, HEURES, BASE, PANIER, poids, caJourPlein, objectifJour,
  proDe, journee, pnl, CATALOGUE, PRODUITS, categoriesDe, EQUIPE, planningDe, toMin, hm };
