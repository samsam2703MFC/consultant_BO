/* Semaine et Mois (?vue=semaine, ?vue=mois) : les lectures du détail sur la période (`du` et `au`), pour le magasin de
 * démo. Données fictives.
 *   GET /exploitation/invendus?du=&au=          la carte « Invendus et poubelle » : pièces, coût, motifs, par produit
 *                                               (consultant/src/invendus.php, invBilan) ;
 *   GET /exploitation/invendus/detail?du=&au=   la fenêtre « Invendus et poubelle » : chaque saisie de la caisse (heure,
 *                                               opérateur, produit, quantité, motif), par opérateur (ep_exploitation_invendus_detail) ;
 *   GET /exploitation/canaux?du=&au=            « Commandes et canaux » sur la période (marketing/src/canaux_offres.php,
 *                                               coCanauxPeriode) ;
 *   GET /pwa/tasks/nc?du=&au=                   les non-conformités de la période (les retours de 04_taches.js).
 * 10_ops.js sert ces routes pour une journée (`date=`) et passe avant ce fichier (ordre des noms) : 07_mois.js, qui passe
 * avant lui, lui renvoie les lectures sur une période. Les totaux des invendus sont ceux de la ligne du magasin dans
 * /exploitation/periode (15_chiffres.js) : la puce, la carte, la fenêtre et le P&L disent les mêmes pièces et le même coût.
 * Les saisies Qualité et Casse (un éclair, des croissants) sont celles que 11_barre_fiche.js sait traiter (réclamer au
 * fournisseur, remarque à l'opérateur). */
const D = require('./lib/demo');
const J = require('./lib/jour');
const chiffres = require('./15_chiffres');
const { r2, r1, AUJ, MAINTENANT, SHOP } = D;

const prod = nom => D.PRODUITS.find(p => p.nom === nom);
const OPS = { 'Camille R.': 4, 'Julie M.': 2, 'Lucas V.': 6, 'Thomas D.': 1, 'Inès B.': 9, 'Sarah K.': 10 };
const MOTIF = { expiration: 'Fin de journée', carryover: 'Report de la veille', tasting: 'Dégustation', quality: 'Qualité', damage: 'Casse' };
// Les saisies Qualité et Casse de novembre (le reste : reports du matin, dégustations, fin de journée).
const QUALITE = {
  '2026-11-06': [['14:35', 'Thomas D.', 'Éclair au chocolat', 2, 'quality']],
  '2026-11-12': [['07:25', 'Julie M.', 'Croissant au beurre', 3, 'damage']],
  '2026-11-17': [['13:05', 'Thomas D.', 'Éclair au chocolat', 3, 'quality']],
  '2026-11-18': [['07:40', 'Julie M.', 'Croissant au beurre', 4, 'damage']],
};
const SOIR = ['Baguette tradition', 'Croissant au beurre', 'Pain au chocolat', 'Sandwich jambon-beurre', 'Sandwich poulet crudités', 'Tarte aux pommes (part)', 'Flan pâtissier (part)', 'Éclair au chocolat', 'Salade César', 'Pain de campagne', 'Millefeuille'];
const MATIN = ['Pain aux raisins', 'Chausson aux pommes', 'Brioche au sucre', 'Suisse pépites', 'Croissant aux amandes'];
const DEGU = ['Cookie chocolat', 'Madeleines (sachet)', 'Sablés au beurre (sachet)'];

/** La ligne du magasin dans /exploitation/periode pour la semaine ou le mois de `du`. */
function ligne(du, au) {
  const vue = au <= D.plus(du, 6) && D.dow(du) === 1 ? 'semaine' : 'mois';
  const per = chiffres('GET', '/exploitation/periode', new URLSearchParams({ vue, date: du }), null);
  return ((per && per.magasins) || []).find(x => String(x.shopId) === SHOP);
}
/** Les saisies brutes d'un jour (la journée en cours arrêtée à 15 h 40). */
function saisiesDu(d) {
  const r = D.alea('inv' + d), L = [];
  const pick = (T, i) => T[Math.floor(r() * T.length) % T.length];
  const n = 1 + Math.floor(r() * 2);
  for (let i = 0; i < n; i++) { L.push([(i ? '07:1' : '07:0') + Math.floor(r() * 9), r() < 0.5 ? 'Camille R.' : 'Julie M.', pick(MATIN, i), 2 + Math.floor(r() * 4), 'carryover']); }
  L.push(['11:' + (20 + Math.floor(r() * 30)), r() < 0.5 ? 'Lucas V.' : 'Inès B.', pick(DEGU), 1 + Math.floor(r() * 2), 'tasting']);
  (QUALITE[d] || []).forEach(x => L.push(x));
  if (d < AUJ) {
    const vus = new Set(), k = 5 + Math.floor(r() * 3);
    for (let i = 0; vus.size < k && i < 40; i++) { vus.add(pick(SOIR)); }
    [...vus].forEach((nom, i) => L.push(['19:' + String(2 + i * 2).padStart(2, '0'), i % 2 ? 'Sarah K.' : 'Inès B.', nom, 2 + Math.floor(r() * 5), 'expiration']));
  }
  return L.filter(x => D.toMin(x[0]) < (d === AUJ ? MAINTENANT : 24 * 60)).sort((a, b) => a[0].localeCompare(b[0])).map(x => ({ le: d, heure: x[0], operateur: x[1], produit: x[2], pieces: x[3], motif: x[4] }));
}
/** Le bilan d'une période : les saisies (ajustées au total de pièces du P&L), le total par produit, par motif, par opérateur. */
const CACHE = {};
function bilan(du, au) {
  const k = du + '|' + au; if (CACHE[k]) { return CACHE[k]; }
  const L = ligne(du, au) || {}, fin = au < AUJ ? au : AUJ;
  let S = [];
  for (let d = du; d <= fin; d = D.plus(d, 1)) { S = S.concat(saisiesDu(d)); }
  // Les pièces de fin de journée s'ajustent pour tomber sur le total du P&L (`invendusPieces`).
  const cible = L.invendusPieces || S.reduce((a, x) => a + x.pieces, 0), soir = S.filter(x => x.motif === 'expiration');
  let ecart = cible - S.reduce((a, x) => a + x.pieces, 0), i = 0;
  while (ecart !== 0 && soir.length && i < 20000) { const x = soir[i % soir.length]; if (ecart > 0) { x.pieces++; ecart--; } else if (x.pieces > 1) { x.pieces--; ecart++; } i++; }
  S.forEach((x, n) => { const p = prod(x.produit) || {}; Object.assign(x, { id: 4100 + n, pid: p.pid, categorie: p.cat || '', motifLib: MOTIF[x.motif], operateurId: OPS[x.operateur] || null }); });
  // Le total par produit : le coût de production (recette) ramené au coût du P&L (`invendus`), la valeur de vente HT.
  const P = {};
  S.forEach(x => {
    const p = prod(x.produit) || { prix: 2, taux: 0.6 };
    const o = P[x.produit] || (P[x.produit] = { pid: x.pid, nom: x.produit, categorie: x.categorie, pieces: 0, brut: 0, caPerdu: 0, motifs: {} });
    o.pieces += x.pieces; o.brut += x.pieces * p.prix / 1.06 * (1 - p.taux) * 1.35; o.caPerdu += x.pieces * p.prix / 1.06; o.motifs[x.motif] = (o.motifs[x.motif] || 0) + x.pieces;
  });
  const brut = Object.values(P).reduce((a, o) => a + o.brut, 0), kC = L.invendus && brut ? L.invendus / brut : 1;
  const produits = Object.values(P).map(o => {
    const m = Object.keys(o.motifs).sort((a, b) => o.motifs[b] - o.motifs[a])[0];
    return { pid: o.pid, nom: o.nom, categorie: o.categorie, pieces: o.pieces, cout: r2(o.brut * kC), coutSource: 'panel', caPerdu: r2(o.caPerdu), caPerduSource: 'panel', motif: m, motifLib: MOTIF[m], motifPieces: o.motifs[m] };
  }).sort((a, b) => b.cout - a.cout || b.pieces - a.pieces);
  const cout = r2(produits.reduce((a, x) => a + x.cout, 0));
  if (L.invendus && produits.length) { produits[0].cout = r2(produits[0].cout + L.invendus - cout); }
  const M = {};
  produits.forEach(p => { S.filter(x => x.produit === p.nom).forEach(x => { const o = M[x.motif] || (M[x.motif] = { motif: x.motif, lib: MOTIF[x.motif], pieces: 0, cout: 0 }); o.pieces += x.pieces; o.cout += p.cout * x.pieces / p.pieces; }); });
  const parMotif = Object.values(M).map(o => Object.assign(o, { cout: r2(o.cout) })).sort((a, b) => b.cout - a.cout);
  const ops = {}; S.forEach(x => { const o = ops[x.operateur] || (ops[x.operateur] = { operateur: x.operateur, pieces: 0, saisies: 0 }); o.pieces += x.pieces; o.saisies++; });
  const pieces = S.reduce((a, x) => a + x.pieces, 0);
  return (CACHE[k] = { du, au: fin, lu: true, declare: pieces > 0, pieces, cout: L.invendus != null ? L.invendus : cout, caPerdu: r2(produits.reduce((a, x) => a + x.caPerdu, 0)), references: produits.length,
    produits, parMotif, saisies: S, saisiesPieces: pieces, parOperateur: Object.values(ops).sort((a, b) => b.pieces - a.pieces) });
}
function invendus(du, au) {
  const B = bilan(du, au);
  return { shop: SHOP, du: B.du, au: B.au, lu: true, declare: B.declare, pieces: B.pieces, cout: B.cout, caPerdu: B.caPerdu, references: B.references, produits: B.produits, parMotif: B.parMotif,
    coutSource: 'panel', source: '/shops/{id}/products/waste du panel (fausse API des vidéos)' };
}
function invendusDetail(du, au) {
  const B = bilan(du, au);
  return { shop: SHOP, du: B.du, au: B.au, lu: true, declare: B.declare, pieces: B.pieces, cout: B.cout, caPerdu: B.caPerdu, references: B.references, produits: B.produits, parMotif: B.parMotif,
    saisies: B.saisies, saisiesPieces: B.saisiesPieces, parOperateur: B.parOperateur, journal: { dispo: true, couvre: true, motif: '' }, remarques: [], motifsQualite: ['quality', 'damage'],
    source: 'total par produit : le panel · saisies : le journal de la caisse (heure, opérateur, quantité, motif) · fausse API des vidéos' };
}

/* --- Commandes et canaux sur la période : le comptoir (la caisse), le click & collect et la livraison (les commandes
 * webshop de lib/jour.js, les mêmes que l'Opérationnel) --- */
function jourCanaux(d) {
  const lu = d <= AUJ, Jr = lu ? D.journee(d, d === AUJ ? MAINTENANT : null) : null, L = J.canaux(d).liste;
  const w = { cc: [0, 0], liv: [0, 0] };
  L.forEach(c => { if (c.canal !== 'compt' && (d < AUJ || D.toMin(c.heure) < MAINTENANT || !lu)) { w[c.canal][0]++; w[c.canal][1] += c.montant; } });
  // Le webshop est encaissé en caisse : le comptoir, c'est la caisse moins le webshop (le CA de la page reste la caisse).
  return { j: d, lu, caisse: lu ? Jr.ca : null, tickets: lu ? Jr.tickets : null, comptoir: lu ? r2(Jr.ca - w.cc[1] - w.liv[1]) : null, cc: r2(w.cc[1]), liv: r2(w.liv[1]), ccN: w.cc[0], livN: w.liv[0], _L: L, _J: Jr };
}
function canaux(du, au) {
  const jours = []; for (let d = du; d <= au; d = D.plus(d, 1)) { jours.push(d); }
  const serie = jours.map(jourCanaux), lus = serie.filter(s => s.lu);
  const t = lus.reduce((a, s) => ({ caisse: a.caisse + s.caisse, comptoir: a.comptoir + s.comptoir, tickets: a.tickets + s.tickets, cc: a.cc + s.cc, ccN: a.ccN + s.ccN, liv: a.liv + s.liv, livN: a.livN + s.livN }), { caisse: 0, comptoir: 0, tickets: 0, cc: 0, ccN: 0, liv: 0, livN: 0 });
  const web = t.cc + t.liv, total = t.comptoir + web;
  const maint = AUJ + ' ' + D.hm(MAINTENANT);
  const parJour = [], liste = []; let aPreparer = 0, nTot = 0, nWeb = 0; const aVenir = { n: 0, ca: 0 };
  serie.forEach(s => {
    const pj = { j: s.j, lu: s.lu, n: s._L.length, compt: 0, ccN: 0, cc: 0, livN: 0, liv: 0, aPreparer: 0 };
    s._L.forEach(c => {
      nTot++;
      if (c.canal === 'compt') { pj.compt++; } else { nWeb++; pj[c.canal + 'N']++; pj[c.canal] = r2(pj[c.canal] + c.montant); }
      if (c.quand > maint) { aVenir.n++; aVenir.ca += c.montant; } else if (s.j === AUJ && c.statut === 'à préparer') { pj.aPreparer++; aPreparer++; }
      if (jours.length <= 7) { liste.push({ jour: s.j, heure: c.heure, canal: c.canal, articles: null, montant: c.montant, statut: c.quand > maint ? 'à préparer' : 'remise' }); }
    });
    parJour.push(pj);
  });
  const parSemaine = [];
  if (jours.length > 15) {
    const W = {};
    serie.forEach(x => {
      const lundi = D.plus(x.j, -((D.dow(x.j) + 6) % 7));
      const o = W[lundi] || (W[lundi] = { du: x.j, au: x.j, joursLus: 0, caisse: 0, comptoir: 0, ccN: 0, cc: 0, livN: 0, liv: 0 });
      o.au = x.j; if (x.lu) { o.joursLus++; o.caisse += x.caisse; o.comptoir += x.comptoir; }
      o.ccN += x.ccN; o.cc += x.cc; o.livN += x.livN; o.liv += x.liv;
    });
    Object.values(W).forEach(o => { const wb = o.cc + o.liv; parSemaine.push(Object.assign(o, { webshop: r2(wb), part: o.joursLus ? r1(100 * wb / (o.comptoir + wb)) : null, caisse: r2(o.caisse), comptoir: r2(o.comptoir), cc: r2(o.cc), liv: r2(o.liv) })); });
  }
  const periode = { joursLus: lus.length, jours: jours.length, caisse: r2(t.caisse), tickets: t.tickets, comptoir: r2(t.comptoir), cc: { n: t.ccN, ca: r2(t.cc) }, liv: { n: t.livN, ca: r2(t.liv) },
    webshop: r2(web), encaisse: r2(web), total: r2(total), part: total ? r1(100 * web / total) : null, indispo: false, du, au, joursEcoules: lus.length };
  return { shop: SHOP, du, au, periode, serie: serie.map(s => { const o = Object.assign({}, s); delete o._L; delete o._J; return o; }), parJour, parSemaine, liste, nCommandes: nTot, nWebshop: nWeb,
    aPreparer, aVenir: { n: aVenir.n, ca: r2(aVenir.ca) }, indispo: false, source: 'commandes du panel + tickets caisse (fausse API des vidéos)' };
}

/** /exploitation/canaux-mois : chaque canal sur six mois, jour par jour le mois choisi et celui d'avant. */
const finMois = ym => D.iso(new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0));
function canauxMois(mois, n) {
  const ym0 = mois && mois <= AUJ.slice(0, 7) ? mois : AUJ.slice(0, 7), liste = [];
  for (let i = n - 1; i >= 0; i--) { const d = new Date(+ym0.slice(0, 4), +ym0.slice(5, 7) - 1 - i, 1); liste.push(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')); }
  const parJour = {}, historique = liste.map(ym => {
    const L = [];
    for (let d = ym + '-01'; d <= finMois(ym) && d <= AUJ; d = D.plus(d, 1)) {
      const s = jourCanaux(d), pro = d >= '2026-08-01' ? s._J.caPro : null;
      L.push({ j: d, lu: s.lu, comptoir: s.comptoir, tickets: s.tickets, pro, ticketsPro: pro == null ? null : s._J.ticketsPro, cc: s.cc, ccN: s.ccN, liv: s.liv, livN: s.livN });
    }
    parJour[ym] = L;
    const sm = k => L.reduce((a, x) => a + (x[k] || 0), 0), proJ = L.filter(x => x.pro != null).length;
    const h = { m: ym, jours: +finMois(ym).slice(8, 10), enCours: ym === AUJ.slice(0, 7), comptoir: r2(sm('comptoir')), tickets: sm('tickets'), joursLus: L.length, joursEcoules: L.length,
      pro: proJ ? r2(sm('pro')) : null, proJours: proJ, ticketsPro: proJ ? sm('ticketsPro') : null, cc: r2(sm('cc')), ccN: sm('ccN'), liv: r2(sm('liv')), livN: sm('livN') };
    h.total = r2(h.comptoir + h.cc + h.liv);
    return h;
  });
  const prec = liste[liste.length - 2];
  return { shop: SHOP, mois: ym0, precedent: prec, historique, jours: { courant: parJour[ym0], precedent: parJour[prec] }, proDepuis: '2026-08-01', indispo: false,
    source: 'commandes du panel, caisse jour par jour, tickets pro (fausse API des vidéos)' };
}

/** Les réponses sur une période ; undefined pour tout le reste (une journée : 10_ops.js). */
function periode(m, p, q) {
  if (m !== 'GET') { return undefined; }
  const du = q.get('du'), au = q.get('au');
  if (p === '/exploitation/canaux-mois') { return canauxMois(q.get('mois'), +(q.get('n') || 6)); }
  if (!du || !au) { return undefined; }
  if (p === '/exploitation/invendus') { return invendus(du, au); }
  if (p === '/exploitation/invendus/detail') { return invendusDetail(du, au); }
  if (p === '/exploitation/canaux') { return canaux(du, au); }
  if (p === '/pwa/tasks/nc' && !q.get('retours')) { const Q = new URLSearchParams(q); Q.set('retours', '1'); return require('./04_taches')(m, p, Q); }
  return undefined;
}
module.exports = periode;
module.exports.periode = periode;
