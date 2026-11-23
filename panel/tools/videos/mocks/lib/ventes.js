/* Les ventes de chaque produit du magasin de démo, jour par jour : Vendre › Analyse (00_analyse.js), Produits
 * (11_produits.js) et Promotions (12_promotions.js) lisent les mêmes chiffres. Tout est fictif et déterministe (graine =
 * produit + date). Le chiffre d'un jour est celui des autres vues (demo.caJourPlein, la journée en cours arrêtée à
 * 15 h 40) ; il se répartit entre les produits du catalogue de démo selon leur poids, leurs jours forts et leur tendance.
 */
const D = require('./demo');
const { r2, r1 } = D;
const OUVERTURE = '2024-06-14';
const REMISE = 0.985;                   // le prix encaissé : le prix affiché, remises comprises
const TVA = 6;

// La tendance de chaque produit : sa part de plus (ou de moins) en 13 semaines, à vendre au même rythme de magasin.
const TENDANCE = {
  'Croissant aux amandes': 0.24, 'Tartelette aux fruits': 0.2, 'Cappuccino': 0.16, 'Sandwich poulet crudités': 0.1,
  'Pain aux noix': 0.12, 'Cookie chocolat': 0.1, 'Suisse pépites': 0.12, 'Jus d’orange pressé': 0.08,
  'Pain aux raisins': -0.2, 'Millefeuille': -0.6, 'Fraisier 6 parts': -0.36, 'Baguette céréales': -0.12, 'Salade César': -0.34, 'Wrap végétarien': -0.24,
  'Sandwich jambon-beurre': -0.18, 'Madeleines (sachet)': -0.2, 'Sablés au beurre (sachet)': -0.14, 'Thé': -0.12, 'Pain complet': -0.1,
};
// La tendance de chaque groupe, qui s'ajoute à celle de ses produits.
const TENDANCE_GROUPE = { Pâtisserie: 0.3, Boissons: 0.26, Viennoiserie: -0.04, Pains: 0, Snacking: -0.3, Biscuiterie: -0.34 };
// Un produit nouveau : vendu depuis ce lundi-là.
const NOUVEAUX = { 'Bowl quinoa-légumes': '2026-09-28' };
// Le produit dont la recette n'a pas de coût au panel (la marge ne se calcule pas).
const SANS_COUT = ['Nougat (barre)'];
// Le moment de la journée où chaque groupe se vend (matin < 11 h, midi 11 h – 14 h, après-midi).
const MOMENTS = {
  Viennoiserie: { matin: 0.72, midi: 0.16, apres: 0.12 }, Pains: { matin: 0.48, midi: 0.3, apres: 0.22 },
  Pâtisserie: { matin: 0.14, midi: 0.34, apres: 0.52 }, Snacking: { matin: 0.08, midi: 0.74, apres: 0.18 },
  Boissons: { matin: 0.56, midi: 0.26, apres: 0.18 }, Biscuiterie: { matin: 0.18, midi: 0.3, apres: 0.52 },
};
// Les jours forts : le facteur de chaque jour (dimanche = 0) par groupe, ou par catégorie quand elle diffère.
const JOURS = {
  Viennoiserie: [1.45, 0.92, 0.9, 0.92, 0.95, 1.0, 1.2], Pains: [1.1, 0.95, 0.95, 0.95, 0.95, 1.0, 1.3],
  Pâtisserie: [1.7, 0.8, 0.8, 0.85, 0.85, 1.0, 1.5], Entremets: [2.6, 0.5, 0.5, 0.6, 0.6, 1.2, 2.2],
  Snacking: [0.4, 1.15, 1.15, 1.15, 1.2, 1.15, 0.6], Boissons: [1.15, 0.95, 0.95, 0.95, 1.0, 1.0, 1.1], Biscuiterie: [1.1, 0.95, 0.95, 1.0, 1.0, 1.0, 1.1],
};
// La part jetée en fin de journée, selon la durée de vie (S : la journée, M : deux ou trois jours, L : longue).
const JETE = { S: 0.075, M: 0.035, L: 0.008 };

const P = D.PRODUITS;
const prixHt = p => r2(p.prix / (1 + TVA / 100));
const cout = p => SANS_COUT.includes(p.nom) ? null : Math.round(p.prix / (1 + TVA / 100) * (1 - p.taux) * 1000) / 1000;
const groupeDe = p => p.groupe;
const facteurJour = (p, dow) => (JOURS[p.cat] || JOURS[p.groupe] || [1, 1, 1, 1, 1, 1, 1])[dow];

const memo = new Map();
/** Les ventes d'un jour : pid → {q, v, j} (pièces, chiffre TTC encaissé, pièces jetées) ; {} avant l'ouverture ou après aujourd'hui. */
function jour(s) {
  if (memo.has(s)) { return memo.get(s); }
  const out = {};
  if (s >= OUVERTURE && s <= D.AUJ) {
    let ca = s === D.AUJ ? D.journee(s, D.MAINTENANT).ca : D.caJourPlein(s);
    if (s.slice(0, 7) === '2024-06') { ca *= 0.55; }
    const t = Math.max(-1, (D.jourDe(s) - D.jourDe(D.AUJ)) / 864e5 / 91);   // au-delà de 13 semaines, la tendance ne remonte plus
    const dow = D.dow(s);
    const W = P.map(p => {
      const n = NOUVEAUX[p.nom];
      let k = 1;
      if (n) { const d = (D.jourDe(s) - D.jourDe(n)) / 864e5; k = d < 0 ? 0 : Math.min(1, 0.35 + d / 21); }
      return p.q * p.prix * facteurJour(p, dow) * Math.exp(((TENDANCE[p.nom] || 0) + (TENDANCE_GROUPE[p.groupe] || 0)) * t) * D.bruit('pv' + p.pid + s, 0.2) * k;
    });
    const somme = W.reduce((a, w) => a + w, 0) || 1;
    P.forEach((p, i) => {
      const v0 = ca * W[i] / somme, pu = p.prix * REMISE, x = v0 / pu, r = D.alea('pq' + p.pid + s)();
      const q = Math.floor(x) + (r < x - Math.floor(x) ? 1 : 0);
      if (q <= 0) { return; }
      // Les invendus : la part de la durée de vie, plus en début de semaine (lundi, mardi), quand le magasin vend moins.
      const kj = (dow === 1 || dow === 2 ? 1.6 : (dow === 0 || dow === 6 ? 0.7 : 1)) * D.bruit('pj' + p.pid + s, 0.5);
      const j = s === D.AUJ ? 0 : Math.round(q * JETE[p.vie] * kj);
      out[p.pid] = { q, v: r2(q * pu), j };
    });
  }
  memo.set(s, out);
  return out;
}
/** Les jours de du à au, bornes comprises. */
function jours(du, au) { const L = []; for (let d = du; d <= au; d = D.plus(d, 1)) { L.push(d); } return L; }
/** Les ventes d'une période : pid → {q, v, j}. */
function periode(du, au) {
  const out = {};
  jours(du, au).forEach(d => { const J = jour(d); Object.keys(J).forEach(id => { const o = out[id] || (out[id] = { q: 0, v: 0, j: 0 }); o.q += J[id].q; o.v += J[id].v; o.j += J[id].j; }); });
  Object.values(out).forEach(o => { o.v = r2(o.v); });
  return out;
}
const produit = pid => P.find(p => String(p.pid) === String(pid)) || null;

/** Le numéro de semaine ISO d'une date. */
function semaineIso(s) {
  const x = D.jourDe(s); x.setDate(x.getDate() + 3 - (x.getDay() + 6) % 7);
  const j1 = new Date(x.getFullYear(), 0, 4, 12);
  return 1 + Math.round(((x - j1) / 864e5 - 3 + (j1.getDay() + 6) % 7) / 7);
}

module.exports = { OUVERTURE, REMISE, TVA, TENDANCE, NOUVEAUX, SANS_COUT, MOMENTS, P, prixHt, cout, groupeDe, jour, jours, periode, produit, semaineIso, r2, r1 };
