/* Vendre › Promotions (dashboard.js, rendPromos ; bundles.js) : les promotions qui touchent le magasin de démo, où elles
 * sont utilisées (comptoir, click & collect, livraison), et les bundles du Coach Vente — le constat, les couples, le prix,
 * la fiche — calculés comme le serveur (panel/src/bundles.php : bndProduits, bndBase, bndCouples) sur les ventes fictives
 * de lib/ventes.js. Les écritures (lancer, renvoyer, la fiche PDF) ne sont pas servies : les vidéos s'arrêtent à la fiche
 * pré-encodée. Données fictives.
 */
const D = require('./lib/demo');
const V = require('./lib/ventes');
const { r2, r1 } = D;

/* --- les produits du magasin : prix, catégorie, coût de recette --------------------------------------------------------- */
const CATS = [...new Set(V.P.map(p => p.cat))];
const catId = cat => 10 + CATS.indexOf(cat);
function produits() {
  const L = V.P.map(p => ({ id: p.pid, nom: p.nom, catId: catId(p.cat), cat: p.cat, prixTtc: p.prix, prixHt: V.prixHt(p), tva: V.TVA, cout: V.cout(p), source: V.cout(p) != null ? 'magasin' : null }));
  return { shop: D.SHOP, produits: L, nMagasin: L.filter(p => p.source === 'magasin').length, nReseau: 0, nSansCout: L.filter(p => p.cout == null).length, seuils: { ko: 1.67, att: 2.5, food: 32 } };
}

/* --- les bundles du Coach Vente -------------------------------------------------------------------------------------- */
const B = { jours: 30, margeHaute: 78, prixX: 8, remise: 0.1, remiseConseil: [8, 12], remiseTrop: 15, prise: 0.1, couples: 6, yMaxFois: 2 };
const CRENEAUX = { matin: 'le matin', midi: 'le midi', apres: 'l’après-midi' };
const AU = D.plus(D.AUJ, -1), DU = D.plus(AU, -(B.jours - 1));
// Le moment de la journée d'un produit qui ne suit pas son groupe (le cappuccino et le thé se prennent aussi au goûter).
const MOMENT_PRODUIT = { Cappuccino: { matin: 0.4, midi: 0.25, apres: 0.35 }, Thé: { matin: 0.3, midi: 0.2, apres: 0.5 } };
function bndProduits() {
  const S = V.periode(DU, AU), P = [];
  V.P.forEach(p => {
    const o = S[p.pid], c = V.cout(p);
    if (!o || c == null) { return; }
    const ht = V.prixHt(p), m = r1(100 * (ht - c) / ht), parts = {};
    // Le moment de la journée : celui du groupe, un peu différent d'un produit à l'autre.
    Object.entries(MOMENT_PRODUIT[p.nom] || V.MOMENTS[p.groupe]).forEach(([k, x]) => { parts[k] = x * D.bruit('mo' + p.pid + k, 0.15); });
    const t = Object.values(parts).reduce((a, x) => a + x, 0); Object.keys(parts).forEach(k => { parts[k] /= t; });
    P.push({ id: p.pid, nom: p.nom, cat: p.cat, q: r1(o.q), v: o.v, ttc: p.prix, ht, cout: c, m, me: Math.round((ht - c) * 10000) / 10000, vht: o.v * ht / p.prix, parts });
  });
  return P.sort((a, b) => b.q - a.q);
}
function bndBase(P) {
  let ht = 0, mg = 0, q = 0; P.forEach(p => { ht += p.vht; mg += p.vht * p.m / 100; q += p.q; });
  const base = ht > 0 ? 100 * mg / ht : 0;
  let sous = 0, haut = 0; P.forEach(p => { if (p.m < base) { sous += p.q; } if (p.m >= B.margeHaute) { haut += p.q; } });
  return { base: r1(base), ventesHt: r2(ht), marge: r2(mg), pieces: Math.round(q), produits: P.length, sousPct: q > 0 ? r1(100 * sous / q) : 0, hautPct: q > 0 ? r1(100 * haut / q) : 0 };
}
const prixConseille = sep => r2(Math.round(sep * (1 - B.remise) * 10) / 10);
function bndCouple(X, Y, prix) {
  const sep = r2(X.ttc + Y.ttc), htB = prix * (X.ht + Y.ht) / (X.ttc + Y.ttc), mB = htB - X.cout - Y.cout, gain = mB - X.me;
  return { x: X.id, y: Y.id, separes: sep, prix: r2(prix), remise: r2(sep - prix), remisePct: sep > 0 ? r1(100 * (sep - prix) / sep) : 0, prixY: r2(prix - X.ttc),
    margeBundle: r2(mB), margeBundlePct: htB > 0 ? r1(100 * mB / htB) : 0, margeXSeul: r2(X.me), margeXSeulPct: X.m, gainTicket: r2(gain),
    bundlesMois: Math.round(B.prise * X.q * 30 / B.jours), gainMois: r2(B.prise * X.q * 30 / B.jours * gain) };
}
const famille = cat => (String(cat).trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').split(/[^a-z0-9]+/).filter(Boolean)[0] || '');
function moment(X, Y) {
  let ov = 0, meilleur = null, max = -1;
  Object.keys(CRENEAUX).forEach(k => { const a = X.parts[k] || 0, b = Y.parts[k] || 0; ov += Math.min(a, b); if (a + b > max) { max = a + b; meilleur = k; } });
  return [ov, meilleur];
}
function bndCouples(P, base) {
  const q = P.reduce((a, p) => a + p.q, 0), qMin = Math.max(20, 0.01 * q);
  const X = P.filter(p => p.m < base && p.q >= qMin && p.ttc <= B.prixX).slice(0, 10);
  const Y = P.filter(p => p.m >= B.margeHaute && p.q >= 1 && p.ttc >= 1);
  const cands = [];
  X.forEach(x => Y.forEach(y => {
    if (x.id === y.id || y.ttc > Math.max(3.5, 2 * x.ttc) || famille(x.cat) === famille(y.cat)) { return; }
    const c = bndCouple(x, y, prixConseille(x.ttc + y.ttc));
    if (c.gainTicket <= 0 || c.margeBundlePct <= x.m) { return; }
    const [ov, mo] = moment(x, y);
    c.moment = mo ? CRENEAUX[mo] : null; c.score = c.gainMois * (0.5 + ov);
    cands.push(c);
  }));
  cands.sort((a, b) => b.score - a.score);
  const out = [], xs = {}, ys = {};
  for (const c of cands) {
    if (xs[c.x] || (ys[c.y] || 0) >= B.yMaxFois) { continue; }
    xs[c.x] = true; ys[c.y] = (ys[c.y] || 0) + 1; delete c.score; out.push(c);
    if (out.length >= B.couples) { break; }
  }
  return out.sort((a, b) => b.gainMois - a.gainMois);
}
// Le bundle déjà lancé avec le Coach Vente, en caisse depuis le lundi 9 novembre (sa fiche est partie à l'équipe) :
// un produit qui attire (sous la marge de base) et un produit qui rapporte (78 % et plus), comme le Coach les choisit.
const EQUIPE = [['Julie', 'julie'], ['Thomas', 'thomas'], ['Inès', 'ines'], ['Lucas', 'lucas'], ['Sarah', 'sarah'], ['Nora', 'nora']];
function lances(P) {
  const X = P.find(p => p.nom === 'Sandwich jambon-beurre'), Y = P.find(p => p.nom === 'Café');
  if (!X || !Y) { return []; }
  const c = bndCouple(X, Y, prixConseille(X.ttc + Y.ttc));
  return [{ id: 3, nom: 'Duo du midi', le: '2026-11-05 09:42:00', x: X.id, xNom: X.nom, xTtc: X.ttc, yNom: Y.nom, prix: c.prix, separes: c.separes, du: '2026-11-09', au: '2026-12-08', canal: 'tous',
    panelId: 2117, statut: 'en_caisse', margeBundle: c.margeBundle, gainTicket: c.gainTicket,
    envoi: { le: '2026-11-05 09:42:31', a: EQUIPE.map(([n, a]) => ({ nom: n, adresse: a[0] + '•••@exemple.be' })), erreur: null, avertissement: null } }];
}
function bundles() {
  const P = bndProduits(), base = bndBase(P), couples = bndCouples(P, base.base), nom = id => (P.find(p => p.id === id) || {}).nom || ('Produit ' + id);
  couples.forEach(c => { c.xNom = nom(c.x); c.yNom = nom(c.y); });
  return { shop: +D.SHOP, magasin: D.NOM, periode: { du: DU, au: AU, jours: B.jours }, base,
    produits: P.map(p => ({ id: p.id, nom: p.nom, cat: p.cat, q: p.q, ttc: p.ttc, ht: Math.round(p.ht * 10000) / 10000, cout: Math.round(p.cout * 10000) / 10000, m: p.m, me: p.me })),
    couples, lances: lances(P),
    regles: { margeHaute: B.margeHaute, remise: B.remise * 100, remiseConseil: B.remiseConseil, remiseTrop: B.remiseTrop, prise: B.prise,
      durees: { '2s': '2 semaines', '1m': '1 mois', '2m': '2 mois' }, canaux: { tous: 'au comptoir et sur le webshop', comptoir: 'au comptoir', webshop: 'sur le webshop' },
      lundi: '2026-11-23', finMois: '2026-12-22' },
    equipe: { n: EQUIPE.length, boite: true } };
}

/* --- les promotions du panel qui touchent le magasin ---------------------------------------------------------------- */
const cat = nom => ({ [catId(nom)]: nom });
const prod = nom => { const p = V.P.find(x => x.nom === nom); return { [p.pid]: p.nom }; };
function liste() {
  const P = bndProduits(), duo = lances(P)[0];
  const panel = [
    { id: 2101, nom: '3 baguettes, la 4e offerte', type: 'xy', texte: '3 achetés (Baguettes), 1 Baguettes offerte', statut: 'active', etat: 'en_caisse', du: '2026-11-01 00:00', au: '2026-11-30 23:59',
      reseau: true, magasins: null, x: { q: 3, c: cat('Baguettes') }, y: { q: 1, c: cat('Baguettes') }, recompense: { type: 'FREE' } },
    { id: 2102, nom: 'Boisson chaude à −50 % avec une pâtisserie', type: 'xy', texte: '1 acheté (Pâtisseries individuelles), 1 Boissons chaudes à −50 %', statut: 'active', etat: 'en_caisse',
      du: '2026-10-12 00:00', au: '2026-12-20 23:59', reseau: true, magasins: null, x: { q: 1, c: cat('Pâtisseries individuelles') }, y: { q: 1, c: cat('Boissons chaudes') },
      recompense: { type: 'PERCENTAGE_DISCOUNT', valeur: 50 } },
    { id: 2103, nom: 'Le 6e cookie offert', type: 'xy', texte: '5 achetés, le 6e offert', statut: 'active', etat: 'en_caisse', du: '2026-10-01 00:00', au: '2026-12-31 23:59',
      reseau: true, magasins: null, x: { q: 5, c: cat('Biscuits et cookies') }, y: { q: 1, c: cat('Biscuits et cookies') }, recompense: { type: 'FREE' } },
  ];
  if (duo) {
    panel.push({ id: duo.panelId, nom: duo.nom, type: 'xy', texte: '1 ' + duo.xNom + ' + 1 ' + duo.yNom, statut: 'active', etat: 'en_caisse', du: duo.du + ' 00:00', au: duo.au + ' 23:59',
      reseau: false, magasins: 1, x: { q: 1, p: prod(duo.xNom) }, y: { q: 1, p: prod(duo.yNom) }, recompense: { type: 'FIXED_PRICE', valeur: r2(duo.prix - duo.xTtc) } });
  }
  panel.forEach(r => Object.assign(r, { bundle: null, creneaux: [], repetable: true, maxParTicket: null, canaux: null, seuil: null }));
  return { shop: D.SHOP, demandes: [], panel, erreurPanel: null, source: 'panel', lu: '15:38', luTs: Math.floor(new Date('2026-11-19T15:38:00+01:00') / 1000), luJour: D.AUJ,
    regles: { coefMin: 2, conditions: { seuil: true, canal: true, comptoir: true, webshop: true, noms: { seuil: 'min_order_amount', canal: 'channel' } } } };
}

/* --- où les promotions sont utilisées : 7 ou 30 jours ---------------------------------------------------------------- */
// Par jour ouvré : combien de fois, la remise d'une fois, la part du webshop (click & collect, livraison).
const USAGES = [[2101, '3 baguettes, la 4e offerte', 4.2, 1.5, 0.12, 0.06, null], [2102, 'Boisson chaude à −50 % avec une pâtisserie', 3.4, 1.4, 0.05, 0.03, null],
  [2103, 'Le 6e cookie offert', 1.1, 2.2, 0.18, 0.1, null], [2117, 'Duo du midi', 3.1, 0.7, 0.1, 0.04, '2026-11-09']];
function canaux(q) {
  const n = +q.get('jours') === 7 ? 7 : 30, jours = []; for (let i = n - 1; i >= 0; i--) { jours.push(D.plus(D.AUJ, -i)); }
  const z = () => ({ n: 0, r: 0, t: 0 }), agg = {}, parJour = [];
  jours.forEach(j => {
    const J = { j, compt: { n: 0, r: 0 }, web: { n: 0, r: 0 }, lu: true }, k = j === D.AUJ ? 0.79 : (D.dow(j) === 0 || D.dow(j) === 6 ? 1.3 : 1);
    USAGES.forEach(([id, nom, parJ, rem, cc, liv, des]) => {
      if (des && j < des) { return; }
      const a = D.alea('us' + id + j), tot = Math.max(0, Math.round(parJ * k * (0.55 + a() * 0.9))), w = a();
      const nCc = w < cc ? 1 : 0, nLiv = w > 1 - liv ? 1 : 0, nC = Math.max(0, tot - nCc - nLiv);
      const o = agg[id] || (agg[id] = { id, nom, type: 'xy', texte: '', compt: z(), cc: z(), liv: z() });
      [['compt', nC], ['cc', nCc], ['liv', nLiv]].forEach(([c, x]) => { o[c].n += x; o[c].r = r2(o[c].r + x * rem); o[c].t += x; });
      J.compt.n += nC; J.compt.r = r2(J.compt.r + nC * rem); J.web.n += nCc + nLiv; J.web.r = r2(J.web.r + (nCc + nLiv) * rem);
    });
    parJour.push(J);
  });
  const promos = Object.values(agg).sort((a, b) => (b.compt.n + b.cc.n + b.liv.n) - (a.compt.n + a.cc.n + a.liv.n));
  const total = { compt: z(), cc: z(), liv: z() };
  promos.forEach(p => ['compt', 'cc', 'liv'].forEach(c => { total[c].n += p[c].n; total[c].r = r2(total[c].r + p[c].r); total[c].t += p[c].t; }));
  return { shop: D.SHOP, jours: n, du: jours[0], au: jours[n - 1], promos, parJour, total, joursSans: [], commandes: true, enLigne: { cc: 0, liv: 0 }, erreur: null };
}

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/exploitation/promos-magasin') { return liste(); }
  if (p === '/exploitation/promos-magasin/produits') { return produits(); }
  if (p === '/exploitation/promos-magasin/canaux') { return canaux(q); }
  if (p === '/assistant/magasin/bundles') { return bundles(); }
  return undefined;
};
