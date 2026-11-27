/* Vendre › Analyse (analyse.js, serveur panel/src/dashboard_analyse.php) : le magasin de démo sur la période choisie,
 * face au réseau à taille égale, mois par mois, jour par jour. Données fictives (lib/ventes.js).
 *
 * Ce module passe AVANT 00_commun.js et 10_ops.js (ordre des noms) pour quatre lectures que l'Analyse fait autrement que
 * les autres pages, et ne répond qu'à celles de l'Analyse (analyse.js ajoute `_cache=900` ou `rafraichir=1`) :
 *   /ventes/mensuel?mois=16                 les mois de 00_commun, avec leurs tickets (le panier moyen) ;
 *   /exploitation/invendus/detail?du=&au=   les produits jetés de la période (Semaine et Mois gardent 10_ops) ;
 *   /analyse/categories/journee             une famille heure par heure (la fenêtre d'une catégorie d'Opérationnel n'y passe pas) ;
 *   /analyse/produits/magasin sans v=2       le prix encaissé TTC face au réseau (la fiche du dashboard garde 10_ops).
 */
const D = require('./lib/demo');
const V = require('./lib/ventes');
const { r2, r1 } = D;
const commun = require('./00_commun');

/* --- la période : les mêmes règles que le serveur (daDecoupe, daEvoPeriode) ------------------------------------------ */
const nbJours = (du, au) => Math.round((D.jourDe(au) - D.jourDe(du)) / 864e5) + 1;
function decoupe(du, au) {
  const n = nbJours(du, au), L = [];
  if (n <= 31) { for (let d = du; d <= au; d = D.plus(d, 1)) { L.push([d, d]); } return ['jour', L]; }
  if (n <= 190) {
    let d = du;
    while (d <= au) { const dim = D.plus(d, (7 - D.dow(d)) % 7), f = dim < au ? dim : au; L.push([d, f]); d = D.plus(f, 1); }
    return ['semaine', L];
  }
  let d = du;
  while (d <= au) { const fm = D.iso(new Date(+d.slice(0, 4), +d.slice(5, 7), 0)), f = fm < au ? fm : au; L.push([d, f]); d = D.plus(f, 1); }
  return ['mois', L];
}
function bornes(q) {
  let du = q.get('du'), au = q.get('au'), prec = q.get('prec');
  if (!du || !au) { au = D.AUJ; du = D.plus(D.AUJ, -((D.dow(D.AUJ) + 6) % 7) - 84); }
  if (au > D.AUJ) { au = D.AUJ; }
  const len = nbJours(du, au);
  if (!prec || prec >= du) { prec = D.plus(du, -len); }
  let pAu = D.plus(prec, len - 1); if (pAu >= du) { pAu = D.plus(du, -1); }
  return [du, au, prec, pAu];
}
function evo(v, q, j, vp, qp, jp, vt) {
  const r = j > 0 ? v / j : 0, rp = jp > 0 ? vp / jp : 0, pct = rp > 0 ? r1(100 * (r / rp - 1)) : null;
  let verdict, depuisI = null;
  if (r > 0 && rp < 0.15 * r) { verdict = 'nouveau'; const moy = v / Math.max(1, vt.length); depuisI = vt.findIndex(x => x >= 0.25 * moy); if (depuisI < 0) { depuisI = null; } }
  else if (rp > 0 && r < 0.15 * rp) { verdict = 'arrete'; }
  else if (q + qp < Math.max(4, 2 * (j + jp) / 7)) { verdict = 'peu'; }
  else if (pct != null && pct >= 8) { verdict = 'monte'; }
  else if (pct != null && pct <= -8) { verdict = 'baisse'; }
  else { verdict = 'stable'; }
  return { pct, verdict, depuisI };
}

/** Les ventes de chaque produit, tranche par tranche, et sur la période d'avant. */
function lirePeriode(q) {
  const [du, au, pDu, pAu] = bornes(q), [pas, T] = decoupe(du, au), n = T.length;
  const parT = T.map(([a, b]) => V.periode(a, b)), prec = V.periode(pDu, pAu);
  return { du, au, pDu, pAu, pas, T, n, parT, prec, j: nbJours(du, au), jp: nbJours(pDu, pAu) };
}
const arr = (L, f) => L.map(v => f(v));

function periode(q) {
  const L = lirePeriode(q), { n, parT, prec, j, jp } = L;
  const produits = [], G = {}, C = {}, tot = { v: Array(n).fill(0), q: Array(n).fill(0), vp: 0, qp: 0 };
  V.P.forEach(p => {
    const v = parT.map(x => (x[p.pid] || {}).v || 0), qq = parT.map(x => (x[p.pid] || {}).q || 0), vp = (prec[p.pid] || {}).v || 0, qp = (prec[p.pid] || {}).q || 0;
    const g = G[p.groupe] || (G[p.groupe] = { nom: p.groupe, n: 0, v: Array(n).fill(0), q: Array(n).fill(0), vp: 0, qp: 0 });
    // Les catégories (daCumuls 'cat' du serveur, 27/11/2026) : la même chose, avec leur famille.
    const c = C[p.cat] || (C[p.cat] = { nom: p.cat, groupe: p.groupe, n: 0, v: Array(n).fill(0), q: Array(n).fill(0), vp: 0, qp: 0 });
    const sv = v.reduce((a, x) => a + x, 0), sq = qq.reduce((a, x) => a + x, 0);
    if (sv > 0 || sq > 0) { g.n++; c.n++; }
    for (let i = 0; i < n; i++) { g.v[i] += v[i]; g.q[i] += qq[i]; c.v[i] += v[i]; c.q[i] += qq[i]; tot.v[i] += v[i]; tot.q[i] += qq[i]; }
    g.vp += vp; g.qp += qp; c.vp += vp; c.qp += qp; tot.vp += vp; tot.qp += qp;
    if (sv <= 0 && sq <= 0) { return; }
    produits.push({ id: p.pid, nom: p.nom, cat: p.cat, groupe: p.groupe, v: arr(v, r2), q: arr(qq, r1), vp: r2(vp), qp: r1(qp), evo: evo(sv, sq, j, vp, qp, jp, v),
      prix: p.prix, prixHt: V.prixHt(p), cout: V.cout(p) });
  });
  const somme = a => a.reduce((s, x) => s + x, 0);
  produits.sort((a, b) => somme(b.v) - somme(a.v));
  const groupes = Object.values(G).filter(g => somme(g.v) > 0).map(g => ({ nom: g.nom, n: g.n, v: arr(g.v, r2), q: arr(g.q, r1), vp: r2(g.vp), qp: r1(g.qp),
    evo: evo(somme(g.v), somme(g.q), j, g.vp, g.qp, jp, g.v) })).sort((a, b) => somme(b.v) - somme(a.v));
  const categories = Object.values(C).filter(c => somme(c.v) > 0).map(c => ({ nom: c.nom, groupe: c.groupe, n: c.n, v: arr(c.v, r2), q: arr(c.q, r1), vp: r2(c.vp), qp: r1(c.qp),
    evo: evo(somme(c.v), somme(c.q), j, c.vp, c.qp, jp, c.v) })).sort((a, b) => somme(b.v) - somme(a.v));
  return { shop: +D.SHOP, du: L.du, au: L.au, jours: j, pas: L.pas, enCours: L.au === D.AUJ, tranches: L.T, muettes: [],
    precedent: { du: L.pDu, au: L.pAu, jours: jp, lu: true },
    total: { v: arr(tot.v, r2), q: arr(tot.q, r1), vp: r2(tot.vp), qp: r1(tot.qp), evo: evo(somme(tot.v), somme(tot.q), j, tot.vp, tot.qp, jp, tot.v) },
    groupes, categories, produits,
    regle: 'évolution : la période face à la période d’avant, au jour ; en hausse à + 8 % et plus, en baisse à − 8 % et moins',
    source: 'ventes du panel (product-category-groups) du magasin, tranche par tranche ; prix et recette : products/available' };
}

/* --- le réseau à taille égale : 8 autres magasins, sans nom ni volume --------------------------------------------------- */
const AUTRES = 8;
// Ce que les autres vendent d'une famille et que le magasin de démo n'a pas : la catégorie, le nombre de magasins, ce que
// leur rythme donnerait chez lui, en pièces par semaine.
const ABSENTS = {
  Viennoiserie: [['Croissant jambon-fromage', 'Viennoiseries garnies', 5, 44], ['Pain suisse au chocolat blanc', 'Viennoiseries garnies', 2, 12]],
  Pâtisserie: [['Tarte au chocolat (part)', 'Tartes', 6, 38], ['Mousse au chocolat (pot)', 'Pâtisseries individuelles', 3, 16]],
  Snacking: [['Quiche lorraine (part)', 'Salades et bowls', 7, 52], ['Croque-monsieur', 'Sandwichs', 4, 30]],
  Pains: [['Pain de seigle', 'Pains spéciaux', 4, 18]], Boissons: [['Chocolat chaud', 'Boissons chaudes', 6, 40]], Biscuiterie: [['Financier', 'Biscuits et cookies', 5, 26]],
};
function reseau(q) {
  const L = lirePeriode(q), { n, parT, prec, j, jp } = L, pid = q.get('pid'), groupe = q.get('groupe');
  const taille = parT.reduce((a, x) => a + Object.values(x).reduce((s, o) => s + o.v, 0), 0);
  const choisis = V.P.filter(p => pid ? String(p.pid) === String(pid) : p.groupe === groupe);
  const evoP = (qq, qp) => qp > 0 ? r1(100 * ((qq / j) / (qp / jp) - 1)) : null;
  const produits = [], rq = Array(n).fill(0), rv = Array(n).fill(0);
  let qA = 0, qAp = 0;
  choisis.forEach(p => {
    const qq = parT.map(x => (x[p.pid] || {}).q || 0), vv = parT.map(x => (x[p.pid] || {}).v || 0), sq = qq.reduce((a, x) => a + x, 0), qp = (prec[p.pid] || {}).q || 0;
    if (sq <= 0) { return; }
    const p10k = r1(sq / taille * 10000), e = evoP(sq, qp);
    // Les autres magasins qui le vendent : leur rythme pour 10 k€, autour du sien ; le nouveau est encore rare ailleurs.
    const nA = V.NOUVEAUX[p.nom] ? 3 : AUTRES - Math.floor(D.alea('na' + p.pid)() * 3);
    const autres = [];
    for (let k = 0; k < nA; k++) {
      const a = D.alea('au' + p.pid + k);
      autres.push({ p10k: r1(p10k * (0.62 + a() * 0.7)), evo: e == null ? null : r1(e * 0.4 + (a() * 16 - 8)) });
    }
    autres.sort((a, b) => b.p10k - a.p10k);
    produits.push({ pid: p.pid, nom: p.nom, cat: p.cat, q: arr(qq, r1), p10k, evo: e, autres });
    // La sélection chez les autres, ramenée à la taille du magasin : un rythme un peu différent, une tendance plus calme.
    const kr = autres.length ? autres.reduce((a, x) => a + x.p10k, 0) / autres.length / p10k : 1;
    qq.forEach((x, i) => { const b = D.bruit('rs' + p.pid + i, 0.06) * (1 - (V.TENDANCE[p.nom] || 0) * 0.3 * (n - 1 - i) / Math.max(1, n - 1)); rq[i] += x * kr * b; rv[i] += vv[i] * kr * b; });
    qA += sq * kr; qAp += qp * kr * (1 + (V.TENDANCE[p.nom] || 0) * 0.25);
  });
  produits.sort((a, b) => b.q.reduce((s, x) => s + x, 0) - a.q.reduce((s, x) => s + x, 0));
  const absents = pid ? [] : (ABSENTS[groupe] || []).map(([nom, cat, mag, sem], i) => ({ pid: 900 + i, nom, cat, magasins: mag, aVotreTaille: r1(sem * D.bruit('ab' + nom + L.du, 0.08)) }));
  return { shop: +D.SHOP, pid: pid ? +pid : null, groupe: pid ? null : groupe, du: L.du, au: L.au, jours: j, precedent: { du: L.pDu, au: L.pAu, jours: jp }, tranches: L.T,
    taille: r2(taille), autresMagasins: AUTRES, produits, absents,
    reseau: { q: arr(rq, r1), v: arr(rv, r2), evo: evoP(qA, qAp) },
    regle: { evolution: 'la période face à la période d’avant, au jour', p10k: 'pièces vendues pour 10 000 € de chiffre TTC du magasin, sur la période', aVotreTaille: 'par semaine' },
    source: 'ventes du panel (product-category-groups), chaque magasin actif du réseau ; les autres magasins sans nom ni volume' };
}

/* --- les mois depuis l'ouverture (12 au plus), un produit jour par jour ------------------------------------------------ */
function mois(q) {
  const pids = String(q.get('pids') || '').split(',').filter(Boolean);
  const M = [];
  for (let i = 11; i >= 0; i--) {
    const t = new Date(2026, 10 - i, 1, 12), cle = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0');
    const fin = D.iso(new Date(t.getFullYear(), t.getMonth() + 1, 0, 12)), au = fin < D.AUJ ? fin : D.AUJ;
    M.push({ cle, du: cle + '-01', au, enCours: i === 0, jours: nbJours(cle + '-01', au), lu: true });
  }
  const parM = M.map(m => V.periode(m.du, m.au));
  const produits = {};
  pids.forEach(id => {
    const p = V.produit(id), c = p ? V.cout(p) : null;
    produits[id] = parM.map(x => {
      const o = x[id] || { q: 0, v: 0 }, ht = p ? o.v / (1 + V.TVA / 100) : 0;
      return [r1(o.q), r2(o.v), r2(ht), c == null ? null : r2(ht - o.q * c)];
    });
  });
  return { shop: +D.SHOP, mois: M, produits, totalMagasin: parM.map(x => r2(Object.values(x).reduce((a, o) => a + o.v, 0))), colonnes: ['pieces', 'caTtc', 'caHt', 'marge'],
    source: 'ventes du panel (product-category-groups), un relevé par mois' };
}
function joursProduit(q) {
  const pid = q.get('pid'), hier = D.plus(D.AUJ, -1), a = q.get('au'), fin = a && a < hier ? a : hier;
  const dates = []; for (let i = 27; i >= 0; i--) { dates.push(D.plus(fin, -i)); }
  return { shop: +D.SHOP, pid: +pid, dates, ventes: dates.map(d => r1((V.jour(d)[pid] || { q: 0 }).q)), jetes: dates.map(d => r1((V.jour(d)[pid] || { j: 0 }).j)),
    source: 'ventes : product-category-groups du panel, jour par jour · jetés : /shops/{id}/products/waste, jour par jour (au jour de la saisie)' };
}

/* --- une famille heure par heure, un jour, face aux 6 mêmes jours d'avant ---------------------------------------------- */
const PROFIL = {   // la part de chaque heure, de 6 h à 17 h
  Viennoiserie: [0.06, 0.17, 0.18, 0.15, 0.11, 0.07, 0.05, 0.04, 0.04, 0.05, 0.05, 0.03], Pains: [0.04, 0.11, 0.12, 0.1, 0.09, 0.1, 0.08, 0.06, 0.06, 0.08, 0.1, 0.06],
  Pâtisserie: [0.01, 0.02, 0.04, 0.05, 0.07, 0.09, 0.1, 0.11, 0.13, 0.15, 0.14, 0.09], Snacking: [0.0, 0.02, 0.03, 0.04, 0.08, 0.26, 0.28, 0.14, 0.06, 0.04, 0.03, 0.02],
  Boissons: [0.05, 0.14, 0.15, 0.13, 0.1, 0.09, 0.08, 0.06, 0.06, 0.06, 0.05, 0.03], Biscuiterie: [0.01, 0.04, 0.06, 0.07, 0.08, 0.1, 0.11, 0.11, 0.12, 0.12, 0.11, 0.07],
};
function journee(q) {
  const nom = q.get('nom'), date = q.get('date') || D.AUJ, prof = PROFIL[nom] || PROFIL.Viennoiserie;
  const caG = d => { const J = V.jour(d); return V.P.filter(p => p.groupe === nom).reduce((a, p) => { const o = J[p.pid] || { q: 0, v: 0 }; a.v += o.v; a.q += o.q; return a; }, { v: 0, q: 0 }); };
  const jours = []; for (let i = 1; i <= 6; i++) { jours.push(D.plus(date, -7 * i)); }
  const du = caG(date), moy = jours.map(caG).reduce((a, o) => ({ v: a.v + o.v / 6, q: a.q + o.q / 6 }), { v: 0, q: 0 });
  const heures = prof.map((k, i) => {
    const h = 6 + i, b = D.bruit('hj' + nom + date + h, 0.12), m = D.bruit('hm' + nom + date + h, 0.05);
    return { h, ca: r2(du.v * k * b), q: r1(du.q * k * b), moyCa: r2(moy.v * k * m), moyQ: r1(moy.q * k * m) };
  });
  return { shop: +D.SHOP, niveau: q.get('niveau') || 'groupe', nom, date, jourNom: D.JOURS[D.dow(date)], maintenant: date === D.AUJ ? '15:40' : null, lu: true, jours, manquants: [], heures };
}

/* --- les invendus de la période, produit par produit ---------------------------------------------------------------- */
function invendus(q) {
  const du = q.get('du'), au = q.get('au'), P = V.periode(du, au);
  const produits = [];
  V.P.forEach(p => {
    const o = P[p.pid]; if (!o || !o.j) { return; }
    const c = V.cout(p) != null ? V.cout(p) : V.prixHt(p) * 0.45, m = Math.round(o.j * (0.8 + D.alea('im' + p.pid)() * 0.15));
    produits.push({ pid: p.pid, nom: p.nom, categorie: p.cat, pieces: r1(o.j), cout: r2(o.j * c), coutSource: 'panel', caPerdu: r2(o.j * V.prixHt(p)), caPerduSource: 'panel',
      motif: 'expiration', motifLib: 'Fin de journée', motifPieces: r1(m), vendus: r1(o.q), taux: r1(100 * o.j / (o.q + o.j)) });
  });
  produits.sort((a, b) => b.cout - a.cout);
  const pc = produits.reduce((a, x) => a + x.pieces, 0), cout = produits.reduce((a, x) => a + x.cout, 0);
  return { du, au, declare: true, pieces: r1(pc), cout: r2(cout), caPerdu: r2(produits.reduce((a, x) => a + x.caPerdu, 0)), references: produits.length, produits, saisies: [], parOperateur: [], remarques: [] };
}

/* --- le prix encaissé (TTC, remises comprises) face au réseau -------------------------------------------------------- */
function prixProduit(q) {
  const p = V.produit(q.get('pid')) || V.P[0], m = +(q.get('mois') || 3);
  const enc = r2(p.prix * V.REMISE), med = r2(p.prix * (1 + (D.alea('md' + p.pid)() * 0.08 - 0.03)));
  const autres = [0.94, 0.97, 1.0, 1.01, 1.03, 1.05, 1.08].map((k, i) => r2(med * k * D.bruit('pa' + p.pid + i, 0.01)));
  const ps = autres.concat([enc]);
  const sem = (V.periode(D.plus(D.AUJ, -91), D.plus(D.AUJ, -1))[p.pid] || { q: 0 }).q;
  return { pid: String(p.pid), nom: p.nom, cat: p.cat,
    prix: { periode: m === 12 ? '12 derniers mois' : (m === 3 ? '3 derniers mois' : 'dernier mois'),
      magasin: { p: enc, brut: p.prix, ec: r1(100 * (enc - med) / med), q: Math.round(sem), v10k: null, rel: null, auMed: r2((med - enc) * sem / 3), peu: false },
      reseau: { med, min: Math.min(...ps), max: Math.max(...ps), magasins: ps.length } } };
}

/* --- les mois avec leurs tickets (le panier moyen) ------------------------------------------------------------------- */
function mensuel(m, p, q) {
  const r = commun(m, p, q);
  if (!r || !Array.isArray(r.mois)) { return r; }
  return Object.assign({}, r, { mois: r.mois.map(x => Object.assign({}, x, { tickets: Math.round(x.ca / (12.75 * D.bruit('tk' + x.mois, 0.03))) })) });
}

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  const analyse = q.get('_cache') === '900' || q.has('rafraichir');
  if (p === '/dashboard/analyse/periode') { return periode(q); }
  if (p === '/dashboard/analyse/reseau') { return reseau(q); }
  if (p === '/dashboard/analyse/mois') { return mois(q); }
  if (p === '/dashboard/analyse/jours') { return joursProduit(q); }
  if (!analyse) { return undefined; }
  if (p === '/analyse/categories/journee' && q.get('niveau') === 'groupe') { return journee(q); }
  if (p === '/ventes/mensuel' && q.get('mois') === '16') { return mensuel(m, p, q); }
  if (p === '/exploitation/invendus/detail' && q.get('du') && q.get('au')) { return invendus(q); }
  if (p === '/analyse/produits/magasin' && !q.get('v')) { return prixProduit(q); }
  return undefined;
};
