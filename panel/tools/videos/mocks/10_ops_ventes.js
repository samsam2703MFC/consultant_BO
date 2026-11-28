/* Opérationnel › Ventes par catégorie : de quoi remplir les onglets Promotions et Portions (promoOnglet, portionOnglet
 * dans dashboard.js), pour la vidéo 01-05-categories. 10_ops.js passe sa réponse de /ventes/stats?vue=jour par
 * `enrichir` ; ce module ne répond lui-même à rien.
 *   promos    les promotions reconnues dans les tickets (la forme de pvPromotions, marketing/src/promos_ventes.php) :
 *             par règle (fois, tickets, pièces, ventes, remise) et par produit ({id: {règle: [q, v, remise, fois]}}) ;
 *   portions  quelques produits vendus aussi en parts (Entremets, Pains spéciaux) : une ligne « Nom — 1/6 » par taille,
 *             comme les lignes « 123:portion » du serveur (svPortionDuNom), le prix de la pièce entière au catalogue.
 * Le CA de chaque produit et de chaque catégorie ne change pas : il se partage entre la pièce entière et ses parts.
 * Données fictives.
 */
const D = require('./lib/demo');
const { r2, r1 } = D;

/* Les règles du panel reconnues dans les tickets de démo. */
const REGLES = [
  { id: 43, nom: 'Petit-déjeuner', type: 'xy', texte: 'un café acheté, le croissant au beurre à 1,00 €', declenche: ['127'], recoit: { '100': 0.4 }, part: 0.3 },
  { id: 42, nom: 'Formule midi', type: 'xy', texte: 'un sandwich et une boisson fraîche : la boisson à −1,00 €', declenche: ['122', '123', '124'], recoit: { '130': 1, '131': 1 }, part: 0.35 },
  { id: 41, nom: 'Cookies : 3 + 1 offert', type: 'xy', texte: '3 cookies achetés, le 4e offert', declenche: [], recoit: {}, cookies: '132', part: 0.45 },
];
const PORTIONS = { '120': [6, 0.34, 2], '121': [6, 0, 1], '110': [2, 0.6, 1], '111': [2, 0.5, 1] };   // id : [en n parts, part vendue entière, parts restées]
const PRIX = { '120': 24.0, '121': 26.0, '110': 3.8, '111': 4.2 };

function promos(st) {
  const prod = {};
  (st.categories || []).forEach(c => (c.produits || []).forEach(p => { prod[p.id] = p; }));
  const pu = p => p && p.q ? p.v / p.q : 0;
  const PP = {}, regles = [];
  const ajoute = (id, rid, q, v, r, fois) => { if (q <= 0) { return; } (PP[id] = PP[id] || {})[rid] = [r2(q), r2(v), r2(r), fois]; };
  REGLES.forEach(R => {
    let fois = 0, q = 0, v = 0, r = 0;
    if (R.cookies) {
      const p = prod[R.cookies]; if (!p) { return; }
      fois = Math.floor(p.q * R.part / 4); if (!fois) { return; }
      const rem = fois * pu(p);
      ajoute(R.cookies, R.id, 4 * fois, 4 * fois * pu(p) - rem, rem, fois);
      q = 4 * fois; v = 4 * fois * pu(p) - rem; r = rem;
    } else {
      const dec = R.declenche.map(id => prod[id]).filter(Boolean), rec = Object.keys(R.recoit).map(id => prod[id]).filter(Boolean);
      const nDec = dec.reduce((a, p) => a + p.q, 0), nRec = rec.reduce((a, p) => a + p.q, 0);
      fois = Math.min(Math.floor(nDec * R.part), nRec); if (!fois) { return; }
      // Les pièces qui déclenchent, puis celles qui reçoivent, au prorata de leurs ventes.
      let resteD = fois; dec.forEach((p, i) => { const n = i === dec.length - 1 ? resteD : Math.min(resteD, Math.round(fois * p.q / nDec)); resteD -= n; ajoute(p.id, R.id, n, n * pu(p), 0, n); q += n; v += n * pu(p); });
      let resteR = fois; rec.forEach((p, i) => {
        const n = i === rec.length - 1 ? resteR : Math.min(resteR, Math.round(fois * p.q / nRec)); resteR -= n;
        const rem = Math.min(n * R.recoit[p.id], n * pu(p));
        ajoute(p.id, R.id, n, n * pu(p) - rem, rem, n); q += n; v += n * pu(p) - rem; r += rem;
      });
    }
    regles.push({ id: R.id, nom: R.nom, type: R.type, texte: R.texte, statut: 'active', fois, tickets: fois, q: r1(q), v: r2(v), r: r2(r) });
  });
  // Une remise sur la ligne qu'aucune promotion du panel n'explique (un geste commercial en caisse).
  const ec = prod['113'];
  if (ec && ec.q >= 2) { ajoute('113', 'x', 1, pu(ec) - 0.5, 0.5, 1); regles.push({ id: 'x', nom: 'Remise non reconnue', type: 'remise', texte: 'remise sur la ligne qu’aucune promotion du panel n’explique', statut: null, fois: 1, tickets: 1, q: 1, v: r2(pu(ec) - 0.5), r: 0.5 }); }
  const s = k => regles.reduce((a, x) => a + x[k], 0);
  return { erreur: null, regles, autres: [], produits: PP, tickets: s('tickets'), v: r2(s('v')), r: r2(s('r')), q: r1(s('q')), joursLus: [st.date], joursSans: [] };
}

/** Une ligne produit partagée : la même marge, le CA au prorata. */
const part = (p, k, q, extra) => Object.assign({}, p, { q, v: r2(p.v * k), c: p.c == null ? null : r2(p.c * k), m: p.m == null ? null : r2(p.m * k),
  vHt: p.vHt == null ? null : r2(p.vHt * k), mHt: p.mHt == null ? null : r2(p.mHt * k) }, extra || {});
function portions(st) {
  (st.categories || []).forEach(c => {
    if (!(c.produits || []).some(p => PORTIONS[p.id])) { return; }
    const L = [];
    c.produits.forEach(p => {
      const P = PORTIONS[p.id];
      if (!P) { L.push(p); return; }
      const [n, a, reste] = P, ent = Math.floor(p.q * a), coupees = p.q - ent;
      if (coupees <= 0) { L.push(p); return; }
      const parts = Math.max(1, coupees * n - reste), eq = parts / n;
      // Le CA se partage entre l'entière et les parts ; une part se paie un peu plus que sa fraction de la pièce.
      const kE = ent / (ent + eq * 1.1);
      if (ent > 0) { L.push(part(p, kE, ent)); }
      L.push(part(p, 1 - kE, parts, { id: p.id + ':' + n, nom: p.nom + ' — 1/' + n, portion: { lib: '1/' + n, f: Math.round(10000 / n) / 10000 } }));
    });
    L.sort((x, y) => y.v - x.v).forEach(p => { p.part = r1(100 * p.v / c.v); });
    c.produits = L; c.q = L.reduce((a, p) => a + p.q, 0);
  });
  st.portions = { prix: PRIX };
  return st;
}

function enrichir(st) {
  if (!st || !Array.isArray(st.categories)) { return st; }
  portions(st);
  st.promos = promos(st);
  return st;
}

module.exports = function () { return undefined; };   // ne répond à aucune route : 10_ops.js appelle enrichir()
module.exports.enrichir = enrichir;
