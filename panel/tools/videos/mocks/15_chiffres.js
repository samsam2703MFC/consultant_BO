/* Les chiffres sur une période (frise des jours, vues Semaine et Mois) pour le magasin de démo. */
const D = require('./lib/demo');
const { r2, r1, AUJ, MAINTENANT, SHOP, NOM } = D;
const finMois = ym => D.iso(new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0));
const COURTS = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
const bornesDe = (vue, date) => {
  if (vue === 'semaine') { const j = (D.dow(date) + 6) % 7, du = D.plus(date, -j); return [du, D.plus(du, 6)]; }
  return [date.slice(0, 8) + '01', finMois(date.slice(0, 7))];
};
/** Les jours d'une période (la journée en cours arrêtée à 15 h 40). */
function jours(du, au) {
  const L = [];
  for (let d = du; d <= au; d = D.plus(d, 1)) {
    const wd = D.dow(d) || 7, passe = d <= AUJ;
    const x = { date: d, jour: wd, court: COURTS[wd] + ' ' + (+d.slice(8, 10)), objectif: D.objectifJour(d), ferme: false, passe, aujourdhui: d === AUJ, ca: null, caHt: null, tickets: null };
    if (passe) { const J = D.journee(d, d === AUJ ? MAINTENANT : null); Object.assign(x, { ca: J.ca, caHt: r2(J.ca / 1.06), tickets: J.tickets, _J: J }); }
    L.push(x);
  }
  return L;
}
/** La ligne du magasin pour une période : réalisé, objectif, attendu, P&L hors TVA, split pro. */
function lignePeriode(vue, date, id, nom, k) {
  const [du, au] = bornesDe(vue, date), J = jours(du, au);
  const P = J.filter(j => j.passe);
  const kr = String(id) === SHOP ? 1 : D.bruit('re' + id + du, 0.07);   // un voisin plus ou moins en avance sur son objectif
  const realise = r2(P.reduce((a, j) => a + j.ca, 0) * k * kr), tickets = Math.round(P.reduce((a, j) => a + j.tickets, 0) * k * kr);
  const objectif = r2(J.reduce((a, j) => a + j.objectif, 0) * k);
  // L'attendu : les jours passés, la journée en cours à sa part normalement écoulée (79 %).
  const attendu = r2(P.reduce((a, j) => a + j.objectif * (j.aujourdhui ? 0.79 : 1), 0) * k);
  const caPro = r2(P.reduce((a, j) => a + j._J.caPro, 0) * k), tkPro = P.reduce((a, j) => a + j._J.ticketsPro, 0);
  const caHt = realise / 1.06, rr = D.alea('per' + du + id);
  const mat = caHt * (0.322 + rr() * 0.02), inv = caHt * 0.017, lab = caHt * (0.172 + rr() * 0.02), oh = caHt * 0.141;
  const mb = caHt - mat, net = mb - inv - lab - oh, pc = v => r1(100 * v / caHt);
  const caC = r2(realise - caPro), tkC = tickets - tkPro, panC = tkC ? r2(caC / tkC) : null;
  const ecart = r2(realise - attendu);
  return {
    shopId: String(id), magasin: nom, ouvert: true, objectif, objectifSource: 'budget', sansBudget: [], attendu, prevu: r2(objectif - attendu), realise,
    reste: r2(objectif - realise), ecart, atteinte: attendu ? Math.round(10000 * realise / attendu) / 10000 : null, tickets, panier: tickets ? r2(realise / tickets) : null,
    clientsManquants: panC ? Math.round(-ecart / panC) : null,
    caHt: r2(caHt), realiseHt: r2(caHt), tva: r2(realise - caHt), tvaPart: r1(100 * (realise - caHt) / realise), tvaSource: 'taux des produits vendus', pnlBase: 'ht',
    coutMatiere: r2(mat), coutMatierePct: pc(mat), coutMatiereSource: 'panel', margeBrute: r2(mb), margeBrutePct: pc(mb),
    invendus: r2(inv), invendusPct: pc(inv), invendusPieces: Math.round(inv / 0.9), invendusDeclare: true, invendusSource: 'panel',
    labour: r2(lab), labourPct: pc(lab), labourSource: 'planning du panel, jour par jour', overhead: r2(oh), overheadPct: pc(oh),
    overheadSaisi: r2(oh * 1.12), overheadTva: r2(oh * 0.12), fraisAIndiquer: 0, fraisSource: 'frais du mois',
    net: r2(net), netPct: pc(net), motifNet: null, joursOuverts: [1, 2, 3, 4, 5, 6, 7],
    jours: J.map(j => { const o = Object.assign({}, j); delete o._J; if (k !== 1 && o.ca != null) { o.ca = r2(o.ca * k); } return o; }),
    caPro, ticketsPro: tkPro, caComptoir: caC, ticketsComptoir: tkC, partPro: realise ? r1(100 * caPro / realise) : null,
    clientsBase: 'comptoir', ecartComptoir: r2(caC - attendu * 0.935), panierComptoir: panC, panierBase: 'comptoir', panierRetenu: panC,
  };
}
const VOISINS = [[1, 1.32], [2, 0.86], [3, 1.12], [5, 0.88], [6, 1.48], [7, 0.78], [8, 0.95], [9, 0.82]];
function periode(vue, date) {
  const [du, au] = bornesDe(vue, date);
  const moi = lignePeriode(vue, date, SHOP, NOM, 1);
  const L = [moi].concat(VOISINS.map(([id, k]) => { const l = lignePeriode(vue, date, String(100 + id), 'Magasin ' + id, k); l.netPct = r1(l.netPct + (D.alea('n' + id)() * 8 - 4)); return l; }));
  const tot = L.reduce((a, l) => ({ ca: a.ca + l.realise, tk: a.tk + l.tickets, caC: a.caC + l.caComptoir, tkC: a.tkC + l.ticketsComptoir, net: a.net + l.netPct }), { ca: 0, tk: 0, caC: 0, tkC: 0, net: 0 });
  return { vue, date, du, au, aujourdhui: AUJ, seuils: { food: 32, labour: 17, overhead: 13.5 },
    magasins: L.sort((a, b) => b.realise - a.realise),
    reseau: { magasins: L.length, ca: r2(tot.ca), tickets: tot.tk, panier: r2(tot.ca / tot.tk), panierComptoir: r2(tot.caC / tot.tkC), netPct: r1(tot.net / L.length) } };
}
/** /ventes/stats sur une semaine ou un mois (panel/src/stats_ventes.php) : les heures (total et moyenne par jour ouvert),
 * matière, rémunération et marge nette de chaque heure, son top 5 produits et son top 3 catégories, les totaux, la
 * meilleure et la pire heure, les périodes jour × matin, midi, après-midi (svPeriodes), les catégories. */
// Ce qui se vend à chaque heure : le poids de chaque groupe du catalogue (le matin la viennoiserie et le café, le midi le
// snacking, l'après-midi la pâtisserie et la biscuiterie).
const GROUPES_H = h => h < 10 ? { Viennoiserie: 4, Pains: 2.2, Boissons: 2.2, Pâtisserie: 0.4, Snacking: 0.2, Biscuiterie: 0.3 }
  : (h < 14 ? { Viennoiserie: 0.6, Pains: 1.6, Boissons: 1, Pâtisserie: 1, Snacking: 4, Biscuiterie: 0.4 }
    : { Viennoiserie: 0.8, Pains: 1.4, Boissons: 1, Pâtisserie: 3, Snacking: 0.6, Biscuiterie: 1.4 });
function produitsHeure(h, ca, graine) {
  const G = GROUPES_H(h), W = D.PRODUITS.map(p => (G[p.groupe] || 0.3) * p.q * p.prix * D.bruit(graine + h + p.pid, 0.25)), tw = W.reduce((a, x) => a + x, 0);
  const L = D.PRODUITS.map((p, i) => {
    const v = ca * W[i] / tw, q = Math.max(0, Math.round(v / p.prix)), vv = r2(q * p.prix), taux = p.taux * D.bruit('t' + p.pid, 0.04), c = r2(vv / 1.06 * (1 - taux)), m = r2(vv - c);
    return { id: p.pid, nom: p.nom, cat: p.cat, q, v: vv, c, m, taux: vv > 0 ? r1(100 * m / vv) : null };
  }).filter(x => x.q > 0);
  const top = L.slice().sort((a, b) => b.m - a.m).slice(0, 5).map(x => ({ id: x.id, nom: x.nom, q: x.q, v: x.v, c: x.c, m: x.m, taux: x.taux }));
  const C = {};
  L.forEach(x => { const o = C[x.cat] || (C[x.cat] = { nom: x.cat, q: 0, v: 0, c: 0, refs: 0 }); o.q += x.q; o.v += x.v; o.c += x.c; o.refs++; });
  const vH = L.reduce((a, x) => a + x.v, 0);
  const cats = Object.values(C).map(o => { const m = r2(o.v - o.c); return { nom: o.nom, q: o.q, v: r2(o.v), c: r2(o.c), m, taux: o.v > 0 ? r1(100 * m / o.v) : null, part: vH > 0 ? r1(100 * o.v / vH) : null, refs: o.refs }; })
    .sort((a, b) => b.m - a.m);
  return { top, cats: cats.slice(0, 3), categories: cats.length, references: L.length };
}
// La part de la matière dans les ventes de chaque heure (le snacking du midi coûte plus que le café du matin).
const MAT_H = h => 0.33 * (h >= 11 && h <= 13 ? 1.07 : (h < 10 ? 0.95 : 1)) * D.bruit('mh' + h, 0.03);
function statsPeriode(vue, date) {
  const [du, au] = bornesDe(vue, date), P = jours(du, au).filter(j => j.passe && !j.aujourdhui);
  const n = Math.max(1, P.length), H = {}, PER = {};
  const plan = D.planningDe(date);
  const enPoste = (pl, h) => pl.filter(p => D.toMin(p.debut) < (h + 1) * 60 && D.toMin(p.fin) > h * 60 && !p.franchise);
  const BORNES = [['matin', 'Matin', 6, 10], ['midi', 'Midi', 11, 14], ['aprem', 'Après-midi', 15, 19]];
  const perDe = h => (BORNES.find(b => h >= b[2] && h <= b[3]) || (h < 6 ? BORNES[0] : BORNES[2]))[0];
  P.forEach(j => {
    const pl = D.planningDe(j.date), J = PER[j.date] = {};
    BORNES.forEach(b => { J[b[0]] = { ca: 0, tickets: 0, mb: 0, res: 0 }; });
    j._J.heures.forEach(x => {
      const o = H[x.h] || (H[x.h] = { h: x.h, ca: 0, tickets: 0 }); o.ca += x.ca; o.tickets += x.tickets;
      const mat = x.ca / 1.06 * MAT_H(x.h), trav = enPoste(pl, x.h).reduce((a, p) => a + p.tauxH, 0), k = J[perDe(x.h)];
      k.ca += x.ca; k.tickets += x.tickets; k.mb += x.ca / 1.06 - mat; k.res += x.ca / 1.06 - mat - trav;
    });
    Object.values(J).forEach(k => { k.ca = r2(k.ca); k.mb = r2(k.mb); k.res = r2(k.res); });
  });
  const heures = Object.values(H).sort((a, b) => a.h - b.h).map(x => {
    const en = enPoste(plan, x.h);
    const trav = en.reduce((a, p) => a + p.tauxH, 0) * n, mat = x.ca / 1.06 * MAT_H(x.h), mb = x.ca / 1.06 - mat, res = mb - trav;
    return Object.assign({ h: x.h, ca: r2(x.ca), tickets: x.tickets, mat: r2(mat), mb: r2(mb), mbPct: r1(100 * mb / x.ca), trav: r2(trav), poste: en.length, res: r2(res), resPct: r1(100 * res / x.ca),
      panier: r2(x.ca / x.tickets), panierBase: null,
      moy: { ca: r2(x.ca / n), tickets: r1(x.tickets / n), mat: r2(mat / n), trav: r2(trav / n), res: r2(res / n), mb: r2(mb / n) }, topSur: P.length }, produitsHeure(x.h, x.ca, vue + du));
  });
  const tot = heures.reduce((a, x) => ({ tickets: a.tickets + x.tickets, ca: a.ca + x.ca, mat: a.mat + x.mat, trav: a.trav + x.trav, res: a.res + x.res }), { tickets: 0, ca: 0, mat: 0, trav: 0, res: 0 });
  tot.mb = r2(tot.ca / 1.06 - tot.mat); tot.panier = tot.tickets ? r2(tot.ca / tot.tickets) : null;
  tot.mbPct = tot.ca ? r1(100 * tot.mb / tot.ca) : null; tot.resPct = tot.ca ? r1(100 * tot.res / tot.ca) : null;
  ['ca', 'mat', 'trav', 'res'].forEach(k => { tot[k] = r2(tot[k]); });
  let best = null, pire = null;
  heures.forEach(l => { if (!best || l.res > best.res) { best = l; } if (!pire || l.res < pire.res) { pire = l; } });
  return { shop: SHOP, vue, date, du, au, nJoursOuverts: n, joursServis: P.map(j => j.date), joursOuverts: P.map(j => j.date), totaux: tot, heures, categories: D.categoriesDe(du + vue, tot.ca),
    periodes: { bornes: BORNES.map(b => ({ cle: b[0], nom: b[1], de: b[2], a: b[3] })), jours: PER },
    meilleure: best ? { h: best.h, res: best.res, moy: best.moy.res } : null, pire: pire ? { h: pire.h, res: pire.res, moy: pire.moy.res } : null,
    produits: { jours: P.map(j => j.date), total: P.length, complet: true, aSuivre: false }, aCompleter: null };
}
/** /ventes/semaines : les 13 dernières semaines (ou mois) face au N-1. */
const LAB_M = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
function isoSem(s) { const d = D.jourDe(s); const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())); const j = t.getUTCDay() || 7; t.setUTCDate(t.getUTCDate() + 4 - j); const y0 = new Date(Date.UTC(t.getUTCFullYear(), 0, 1)); return Math.ceil(((t - y0) / 86400000 + 1) / 7); }
function somme(du, au) {
  let ca = 0, tk = 0, caP = 0, tkP = 0;
  for (let d = du; d <= au && d <= AUJ; d = D.plus(d, 1)) { const J = D.journee(d, d === AUJ ? MAINTENANT : null); ca += J.ca; tk += J.tickets; caP += J.caPro; tkP += J.ticketsPro; }
  return { source: 'panel', ca: r2(ca), tickets: tk, articles: Math.round(tk * 2.3 * D.bruit('ar' + du, 0.04)), caComptoir: r2(ca - caP), ticketsComptoir: tk - tkP };
}
function semaines(vue, date, n) {
  const L = [];
  if (vue === 'mois') {
    for (let i = n - 1; i >= 0; i--) {
      const d = new Date(+date.slice(0, 4), +date.slice(5, 7) - 1 - i, 1), du = D.iso(d), au = finMois(du.slice(0, 7));
      const a1 = new Date(d.getFullYear() - 1, d.getMonth(), 1), du1 = D.iso(a1), au1 = du.slice(0, 7) === AUJ.slice(0, 7) ? D.plus(AUJ, -365) : finMois(du1.slice(0, 7));
      L.push(Object.assign({ iso: d.getMonth() + 1, du, au, lab: LAB_M[d.getMonth()], enCours: du.slice(0, 7) === AUJ.slice(0, 7), joursServis: du.slice(0, 7) === AUJ.slice(0, 7) ? +AUJ.slice(8, 10) - 1 : null }, somme(du, au), { n1: du1 < '2024-06-14' ? null : somme(du1, au1) }));
    }
  } else {
    const j = (D.dow(date) + 6) % 7, lun = D.plus(date, -j);
    for (let i = n - 1; i >= 0; i--) {
      // La semaine en cours : celle d'aujourd'hui (une semaine passée ouverte depuis la frise est close).
      const du = D.plus(lun, -7 * i), au = D.plus(du, 6), cours = du <= AUJ && AUJ <= au, du1 = D.plus(du, -364), au1 = cours ? D.plus(AUJ, -364) : D.plus(au, -364);
      L.push(Object.assign({ iso: isoSem(du), du, au, enCours: cours, joursServis: cours ? (D.dow(AUJ) + 6) % 7 : null }, somme(du, au), { n1: somme(du1, au1) }));
    }
  }
  return { shop: SHOP, vue: vue === 'mois' ? 'mois' : 'semaine', semaines: L, n1Motif: null };
}
/** /stores/perf : les mois des deux années (la frise des mois). */
function perf(annees) {
  const L = [];
  annees.forEach(a => { for (let mo = 1; mo <= 12; mo++) {
    const ym = a + '-' + String(mo).padStart(2, '0'); if (ym > AUJ.slice(0, 7) || ym < '2024-06') { continue; }
    const s = somme(ym + '-01', finMois(ym));
    let bud = 0; for (let d = ym + '-01'; d <= finMois(ym); d = D.plus(d, 1)) { bud += D.objectifJour(d); }
    L.push({ storeId: +SHOP, annee: +a, mois: mo, ca: s.ca, tickets: s.tickets, caBudget: r2(bud), caComptoir: s.caComptoir, ticketsComptoir: s.ticketsComptoir });
  } });
  return L;
}
/** /pwa/tasks/heatmap/mois : chaque jour relevé de la période, ses tâches obligatoires faites et pas faites (huit par
 * jour ; l'alarme oubliée le mardi 17, une journée plus lâche le 4). La journée en cours se compte en direct (/pwa/tasks). */
function heatmap(du, au) {
  const jours = [];
  for (let d = du; d <= au && d < AUJ; d = D.plus(d, 1)) {
    const r = D.alea('tj' + d)(), pas = d === '2026-11-17' ? 1 : (d === '2026-11-04' ? 4 : (r < 0.7 ? 0 : 1));
    jours.push({ j: d, releve: true, faites: 8 - pas, pasFaites: pas, part: Math.round(100 * (8 - pas) / 8) });
  }
  return { lignes: [{ shopId: SHOP, faites: jours.reduce((a, j) => a + j.faites, 0), pasFaites: jours.reduce((a, j) => a + j.pasFaites, 0), jours }] };
}
module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/exploitation/periode') { return periode(q.get('vue') || 'mois', q.get('date') || AUJ); }
  if (p === '/ventes/stats' && (q.get('vue') === 'semaine' || q.get('vue') === 'mois')) { return statsPeriode(q.get('vue'), q.get('date') || AUJ); }
  if (p === '/ventes/semaines') { return semaines(q.get('vue') || 'semaine', q.get('date') || AUJ, +(q.get('n') || 13)); }
  if (p === '/stores/perf') { return perf((q.get('annees') || '2025,2026').split(',')); }
  if (p === '/ventes/stock') { return require('./lib/stock').stock(); }
  if (p === '/pwa/tasks/heatmap/mois') { return heatmap(q.get('du') || AUJ, q.get('au') || AUJ); }
  if (p === '/exploitation/offres') { return { offres: [], liste: [] }; }
  // /exploitation/canaux-mois, et le détail sur une période (invendus, canaux, non-conformités) : 15_chiffres_periode.js.
  if (p === '/exploitation/rentabilite') {
    const du = AUJ.slice(0, 8) + '01', J = [];
    let net = 0, ca = 0;
    for (let d = du; d <= finMois(AUJ.slice(0, 7)); d = D.plus(d, 1)) {
      if (d >= AUJ) { J.push({ date: d, ouvert: false }); continue; }
      const x = D.journee(d, null), P = D.pnl(d + SHOP, x.ca); net += P.net; ca += P.caHt;
      J.push({ date: d, ouvert: true, ca: x.ca, net: P.net, netPct: r1(100 * P.net / P.caHt) });
    }
    return { du, au: D.plus(AUJ, -1), magasins: [{ id: SHOP, jours: J, total: { net: r2(net), netPct: r1(100 * net / ca) }, sourceJour: 'P&L quotidien du panel' }] };
  }
  if (p === '/leviers/magasins') { return { magasins: [] }; }
  return undefined;
};
