/* Mon équipe › Primes (vue=primes ; dans Vendre jusqu'au 24/11/2026) : « Où en sont tes vendeuses », lu par panel/public/dashboard/dashboard.js (rendPrimes),
 * aux formes de panel/src/primes.php : GET /ventes/primes-equipe?shop=&m= (primesEquipe : les colonnes de
 * primesEquipeColonne, les cases de primesEquipeCase) et GET /ventes/primes-annee?y=&shop= (primesAnneeVue).
 * Six personnes de vente fictives (celles de Mon équipe, mocks/20_equipe.js), le mois en cours (novembre 2026, au
 * 19, en projection), un mois clos (octobre) et l'année 2026. Les récompenses affichées sont celles par défaut du code
 * (50 € une prime de l'app, 25 € la note Google) ou des montants de démonstration ; rien n'y est réel. */
'use strict';
const D = require('./lib/demo');
const { r2, r1 } = D;

const VENDEUSES = [
  { id: 205, nom: 'Emma Garnier' }, { id: 201, nom: 'Camille Roussel' }, { id: 206, nom: 'Fanny Leroy' },
  { id: 210, nom: 'Lucie Bernard' }, { id: 204, nom: 'David Lemaire' }, { id: 208, nom: 'Inès Fontaine' },
];
// Les primes du magasin de démo, dans l'ordre de « Mes primes » (primesCartes) : objectif et Google sont les primes
// d'équipe (la carte « Le magasin, ensemble ») ; les autres font les colonnes du tableau.
const COLS = [
  { cle: 'croisees', lib: 'Ventes croisées', type: 'target', base: 'croisees', emo: '🛍️', montant: 50, unite: 'pct', payeur: 'marque' },
  { cle: 'record', lib: 'Bats ton record', type: 'target', base: 'record', emo: '🚀', montant: 30, max: true, unite: 'lpt', payeur: 'marque' },
  { cle: 'objectif', lib: 'Objectif magasin', type: 'target', base: 'objectif', emo: '🏪', montant: 50, unite: 'pct', payeur: 'marque' },
  { cle: 'google', lib: 'Note Google', type: 'target', base: 'google', emo: '⭐', montant: 25, unite: 'note', payeur: 'marque' },
  { cle: 'cible:12', lib: 'Défi du magasin · ventes croisées', type: 'target', base: 'croisees', emo: '🛍️', montant: 20, unite: 'pct', payeur: 'magasin' },
  { cle: 'meilleure', lib: 'Meilleure vendeuse', type: 'classement', base: 'meilleure', emo: '🏆', montant: 50, unite: 'score', payeur: 'marque' },
  { cle: 'concours:tartes', lib: 'Queen of Tartes', type: 'categorie', base: 'concours:tartes', emo: '👑', montant: 50, unite: 'pcs', regle: 'premiere', payeur: 'marque' },
  { cle: 'b2b', lib: 'Clients pro', type: 'b2b', base: 'b2b', emo: '🤝', montant: 0, unite: 'eur', payeur: 'marque', mode: 'taux', taux: 5, fixe: 0, duree: 3 },
];
function colonne(c) {
  return { cle: c.cle, lib: c.lib, type: c.type, base: c.base, emo: c.emo, montant: c.montant, max: !!c.max, cadeau: null, unite: c.unite, regle: c.regle || null,
    payeur: c.payeur, sponsor: null, mode: c.mode || null, taux: c.taux != null ? c.taux : null, fixe: c.fixe != null ? c.fixe : null, duree: c.duree != null ? c.duree : null, vendeuses: 0, gagnees: 0 };
}
/** Une case (primesEquipeCase). */
const K = (etat, o = {}) => Object.assign({ etat, gain: 0, val: null, cible: null, record: null, rang: null, sur: null, ecart: null, pour1: null, avance1: null, motif: null, niveau: null, clients: null, nouveaux: null }, o);
const niv = (v, c) => Math.round(Math.min(100, 100 * v / c));

/* ── novembre 2026, au 19 (en cours) : [heures, tickets, cases] ── */
function novembre() {
  const tartes = { 201: 64, 205: 56, 206: 41, 210: 30, 208: 12, 204: 9 }, t1 = 64;
  const score = { 205: 87.4, 206: 84.1, 201: 79.6, 210: 71.2, 204: 63.5, 208: 58.0 };
  const rangDe = (o, id) => 1 + Object.values(o).filter(v => v > o[id]).length;
  const cro = { 205: 53.2, 201: 51.0, 206: 47.8, 210: 44.1, 204: 31.0, 208: 38.5 };
  const rec = { 205: [2.41, 2.18], 201: [2.22, 2.15], 206: [2.30, 2.06], 210: [1.98, 2.01], 204: [2.10, 2.12], 208: [2.05, null] };
  const H = { 205: [74.5, 612], 201: [68, 548], 206: [81, 590], 210: [52.5, 401], 204: [41, 236], 208: [17.5, 158] };
  return VENDEUSES.map(v => {
    const id = v.id, sous = H[id][0] < 20, c = {};
    c.croisees = cro[id] >= 50 ? K('gagne', { gain: 50, val: cro[id], cible: 50, niveau: 100 }) : K('att', { val: cro[id], cible: 50, ecart: r1(50 - cro[id]), niveau: niv(cro[id], 50) });
    const [lt, r] = rec[id];
    if (r == null) { c.record = K('vide', { val: lt, motif: 'pas_record' }); }
    else { const cb = r2(r + 0.2); c.record = lt >= cb ? K('gagne', { gain: id === 206 ? 10 : 10, val: lt, cible: cb, record: r, niveau: 100 }) : K('att', { val: lt, cible: cb, record: r, ecart: r2(cb - lt), niveau: niv(lt, cb) }); }
    c.objectif = sous ? K('vide', { val: 96.8, cible: 100, motif: 'heures', niveau: 97 }) : K('att', { val: 96.8, cible: 100, ecart: 3.2, niveau: 97 });
    c.google = sous ? K('vide', { val: 4.6, cible: 4.5, motif: 'heures', niveau: 100 }) : K('gagne', { gain: 25, val: 4.6, cible: 4.5, niveau: 100 });
    c['cible:12'] = cro[id] >= 52 ? K('gagne', { gain: 20, val: cro[id], cible: 52, niveau: 100 }) : K('att', { val: cro[id], cible: 52, ecart: r1(52 - cro[id]), niveau: niv(cro[id], 52) });
    const rm = rangDe(score, id);
    c.meilleure = K(rm === 1 ? 'tete' : 'rang', { val: score[id], rang: rm, sur: 6 });
    const rt = rangDe(tartes, id);
    c['concours:tartes'] = K(rt === 1 ? 'tete' : 'rang', { val: tartes[id], cible: 1, rang: rt, sur: 6, pour1: rt === 1 ? null : t1 - tartes[id] + 1, avance1: rt === 1 ? 8 : null, niveau: niv(tartes[id], t1) });
    c.b2b = id === 206 ? K('att', { gain: 18.4, val: 18.4, clients: 1, nouveaux: 1 }) : K('att', { gain: 0, val: 0, clients: 0 });
    const acquis = r2(Object.values(c).reduce((a, k) => a + (k.gain || 0), 0));
    return { id, nom: v.nom, heures: H[id][0], tickets: H[id][1], acquis, enJeu: 0, cases: c };
  });
}

/* ── octobre 2026 (clos) ── */
function octobre() {
  const H = { 205: [118, 1004], 201: [112.5, 951], 206: [131, 1012], 210: [86, 672], 204: [64, 388], 208: [31.5, 260] };
  const cro = { 205: 52.1, 201: 48.9, 206: 50.4, 210: 45.0, 204: 33.6, 208: 41.2 };
  const tartes = { 205: 101, 201: 96, 206: 70, 210: 52, 208: 27, 204: 18 };
  const score = { 206: 88.0, 205: 85.3, 201: 81.9, 210: 70.4, 204: 62.2, 208: 59.1 };
  const rangDe = (o, id) => 1 + Object.values(o).filter(v => v > o[id]).length;
  const rec = { 205: [2.38, 2.16, 20], 201: [2.35, 2.12, 20], 206: [2.06, 2.11, 0], 210: [2.01, 1.95, 0], 204: [2.12, 2.04, 0], 208: [2.05, null, 0] };
  return VENDEUSES.map(v => {
    const id = v.id, c = {};
    c.croisees = cro[id] >= 50 ? K('gagne', { gain: 50, val: cro[id], cible: 50, niveau: 100 }) : K('att', { val: cro[id], cible: 50, ecart: r1(50 - cro[id]), niveau: niv(cro[id], 50) });
    const [lt, r, g] = rec[id];
    c.record = r == null ? K('vide', { val: lt, motif: 'pas_record' }) : (g > 0 ? K('gagne', { gain: g, val: lt, cible: r2(r + 0.2), record: r, niveau: 100 }) : K('att', { val: lt, cible: r2(r + 0.2), record: r, niveau: niv(lt, r + 0.2) }));
    c.objectif = K('gagne', { gain: 50, val: 102.4, cible: 100, niveau: 100 });
    c.google = K('gagne', { gain: 25, val: 4.6, cible: 4.5, niveau: 100 });
    c['cible:12'] = cro[id] >= 52 ? K('gagne', { gain: 20, val: cro[id], cible: 52, niveau: 100 }) : K('att', { val: cro[id], cible: 52, niveau: niv(cro[id], 52) });
    const rm = rangDe(score, id);
    c.meilleure = rm === 1 ? K('gagne', { gain: 50, val: score[id], rang: 1, sur: 6 }) : K('rang', { val: score[id], rang: rm, sur: 6 });
    const rt = rangDe(tartes, id);
    c['concours:tartes'] = rt === 1 ? K('gagne', { gain: 50, val: tartes[id], cible: 1, rang: 1, sur: 6 }) : K('rang', { val: tartes[id], cible: 1, rang: rt, sur: 6, pour1: 101 - tartes[id] + 1, niveau: niv(tartes[id], 101) });
    c.b2b = id === 206 ? K('gagne', { gain: 12.6, val: 12.6, clients: 1 }) : K('vide', { gain: 0, val: 0, clients: 0 });
    const acquis = r2(Object.values(c).reduce((a, k) => a + (k.gain || 0), 0));
    return { id, nom: v.nom, heures: H[id][0], tickets: H[id][1], acquis, enJeu: 0, cases: c };
  });
}

function equipe(m) {
  const enCours = m === '2026-11';
  const vendeuses = m === '2026-11' ? novembre() : (m === '2026-10' ? octobre() : null);
  if (!vendeuses) { return { v: 13, m, lib: m, enCours: false, quand: 1795100000, magasin: { id: '4', nom: D.NOM }, primes: [], vendeuses: [] }; }
  const primes = COLS.map(colonne);
  primes.forEach(p => vendeuses.forEach(v => { const k = v.cases[p.cle]; if (!k) { return; } p.vendeuses++; if (k.etat === 'gagne' || (p.type === 'b2b' && k.gain > 0)) { p.gagnees++; } }));
  vendeuses.sort((a, b) => b.acquis - a.acquis || b.tickets - a.tickets || a.nom.localeCompare(b.nom, 'fr'));
  // quand : jeudi 19/11/2026, 15 h 32 (heure de Bruxelles)
  return { v: 13, m, lib: enCours ? 'novembre 2026' : 'octobre 2026', enCours, quand: Math.round(new Date('2026-11-19T15:32:00+01:00') / 1000),
    magasin: { id: '4', nom: D.NOM }, primes, vendeuses };
}

/* ── l'année 2026 : mois par mois, ce que chacune a gagné (montants de démonstration, tirés au sort une fois) ── */
function annee() {
  const mois = [];
  for (let i = 1; i <= 11; i++) { const m = '2026-' + String(i).padStart(2, '0'); mois.push({ m, lib: m, enCours: i === 11, motif: null }); }
  const pers = {}, R = D.alea('primes-annee-2026');
  const gagne = (id, cle, montant) => { const p = pers[id]; const c = p.cases[cle] || (p.cases[cle] = { montant: 0, fois: 0 }); c.montant = r2(c.montant + montant); c.fois++; p.total = r2(p.total + montant); p._m.add(p._mois); };
  VENDEUSES.forEach(v => { pers[v.id] = { id: v.id, nom: v.nom, shopId: '4', magasinNom: D.NOM, total: 0, mois: 0, cases: {}, _m: new Set() }; });
  const debut = { 208: 6 };   // Inès est arrivée en juin
  const objectifAtteint = [1, 2, 4, 5, 6, 9, 10], googleAtteint = [1, 2, 3, 4, 6, 7, 8, 9, 10];
  const pCro = { 205: 0.8, 201: 0.5, 206: 0.6, 210: 0.3, 204: 0.05, 208: 0.15 };
  const meilleure = [206, 205, 205, 201, 206, 205, 206, 205, 201, 206], tartes = [205, 201, 205, 205, 201, 201, 206, 201, 205, 205];
  for (let i = 1; i <= 11; i++) {
    VENDEUSES.forEach(v => { pers[v.id]._mois = i; });
    const actifs = VENDEUSES.filter(v => i >= (debut[v.id] || 1));
    if (i <= 10) {
      actifs.forEach(v => {
        const sous = v.id === 208 && i % 2 === 0;   // sous le minimum d'heures un mois sur deux
        if (!sous && objectifAtteint.includes(i)) { gagne(v.id, 'objectif', 50); }
        if (!sous && googleAtteint.includes(i)) { gagne(v.id, 'google', 25); }
        if (R() < pCro[v.id]) { gagne(v.id, 'croisees', 50); }
        if (R() < 0.35 && v.id !== 208) { gagne(v.id, 'record', 10 * (1 + Math.floor(R() * 2))); }
        if (v.id === 205 && (i === 9 || i === 10)) { gagne(v.id, 'cible:12', 20); }   // le défi du magasin court depuis septembre
        if (v.id === 206 && [3, 7, 10].includes(i)) { gagne(v.id, 'b2b', [9.8, 15.2, 12.6][[3, 7, 10].indexOf(i)]); }
      });
      gagne(meilleure[i - 1], 'meilleure', 50);
      gagne(tartes[i - 1], 'concours:tartes', 50);
    } else {
      // novembre, à ce jour : ce que les cases du mois disent déjà gagné
      // (les primes d'équipe du mois en cours restent hors de l'année : l'anneau compte les mois clos)
      novembre().forEach(v => Object.entries(v.cases).forEach(([k, c]) => { if (c.gain > 0 && !['b2b', 'objectif', 'google'].includes(k)) { gagne(v.id, k, c.gain); } }));
    }
  }
  const personnes = Object.values(pers).map(p => { const o = Object.assign({}, p, { mois: p._m.size }); delete o._m; delete o._mois; return o; })
    .sort((a, b) => b.total - a.total || a.nom.localeCompare(b.nom, 'fr'));
  const ordre = ['croisees', 'record', 'objectif', 'google', 'meilleure', 'cible:12', 'concours:tartes', 'b2b'];
  const primes = ordre.filter(k => personnes.some(p => p.cases[k])).map(k => {
    const c = COLS.find(x => x.cle === k), L = personnes.filter(p => p.cases[k]);
    return { cle: k, lib: c.lib, payeur: c.payeur, sponsor: null, total: r2(L.reduce((a, p) => a + p.cases[k].montant, 0)), fois: L.reduce((a, p) => a + p.cases[k].fois, 0), gagnantes: L.length };
  });
  return { y: 2026, enCours: true, quand: Math.round(new Date('2026-11-19T15:10:00+01:00') / 1000), mois, shop: '4', primes, personnes, magasins: [],
    total: r2(personnes.reduce((a, p) => a + p.total, 0)) };
}

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/ventes/primes-equipe') { return equipe(q.get('m') || '2026-11'); }
  if (p === '/ventes/primes-annee') { return annee(); }
  return undefined;
};
