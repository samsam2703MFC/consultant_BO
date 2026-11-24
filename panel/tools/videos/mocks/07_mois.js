/* Le Mois (?vue=mois) : les six leviers du mois (/leviers/magasins, la puce « Les six leviers ») pour le magasin de
 * démo et huit voisins anonymes. Données fictives. Le reste de la page (le mois, les jours, le résultat, les douze
 * derniers mois) est servi par 15_chiffres.js ; ce module passe avant lui (ordre des noms) pour cette seule route.
 * Les ratios du magasin sont ceux de son P&L du mois (08_annee.js, pnlMois) : les mêmes qu'à l'écran. */
const D = require('./lib/demo');
const A = require('./08_annee');
const { r1, r2, AUJ, SHOP, NOM } = D;

const finMois = ym => D.iso(new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0));
const ymDe = (y, m) => y + '-' + String(m).padStart(2, '0');
const moinsMois = (ym, n) => { const d = new Date(+ym.slice(0, 4), +ym.slice(5, 7) - 1 - n, 1); return ymDe(d.getFullYear(), d.getMonth() + 1); };
/** Les jours d'un mois qui comptent pour le trafic : le mois en cours à date. */
const joursDe = ym => ym === AUJ.slice(0, 7) ? +AUJ.slice(8, 10) : +finMois(ym).slice(8, 10);

// Les leviers du référentiel (couleurs du Manuel opératoire, shared/bin/seed.php).
const LEVIERS = [
  ['trafic', 'Trafic', 'Vente', '#6366f1', 'clients par jour', 'clients'], ['recurrence', 'Récurrence', 'Vente', '#ec4899', 'taux de retour des clients identifiés', 'taux de retour des clients identifiés'],
  ['xp', 'Expérience Client', 'Vente', '#f59e0b', 'note Google', 'note Google'], ['food', 'Food Cost', 'Coût', '#10b981', '% du CA', '% du CA'],
  ['labour', 'Labour Cost', 'Coût', '#8b5cf6', '% du CA', '% du CA'], ['overhead', 'Overhead Cost', 'Coût', '#8b5cf6', '% du CA', '% du CA'],
].map(([cle, nom, type, couleur, mesureMois, mesureCumul]) => ({ cle, nom, type, couleur, mesureMois, mesureCumul, vide: false }));
// Les seuils de la Table KPI du réseau de démo (fictifs, alignés sur /meta : food 32, labour 17, overhead 13,5).
const SEUILS = {
  trafic: { code: 'trafic-clients', alerte: -10, critique: -20, sens: 'bas' }, xp: { code: 'note-google', alerte: 4.5, critique: 4.2, sens: 'bas' },
  food: { code: 'food-cost-pct', alerte: 32, critique: 35, sens: 'haut' }, labour: { code: 'labour-pct', alerte: 17, critique: 20, sens: 'haut' },
  overhead: { code: 'overhead-pct', alerte: 13.5, critique: 16, sens: 'haut' }, recurrence: { code: 'clients-revenus', alerte: 35, critique: 25, sens: 'bas' },
};
function niveau(s, v) {
  if (!s || v == null || s.alerte == null) { return null; }
  const al = s.alerte, cr = s.critique != null ? s.critique : al;
  if (s.sens === 'haut') { return v >= cr ? 'r' : (v >= al ? 'o' : 'v'); }
  return v <= cr ? 'r' : (v <= al ? 'o' : 'v');
}
// Les voisins : le même écart d'échelle qu'en Semaine et Mois (15_chiffres.js), des ratios un peu différents.
const VOISINS = [[1, 1.32], [2, 0.86], [3, 1.12], [5, 0.88], [6, 1.48], [7, 0.78], [8, 0.95], [9, 0.82]];

/** Les valeurs d'un mois pour un magasin : clients par jour, food, labour, overhead, récurrence. */
function valeurs(id, k, ym) {
  if (ym < '2024-07') { return null; }
  const P = A.pnlMois(ym); if (!P) { return null; }
  const S = A.somme(ym + '-01', finMois(ym));
  const moi = String(id) === SHOP, a = D.alea('lv' + id + ym);
  const dec = moi ? [0, 0, 0, 0] : [a() * 4 - 2, a() * 5 - 2.5, a() * 3 - 1.5, a() * 14 - 7];
  return { tickets: S.tickets * (moi ? 1 : k * D.bruit('t' + id + ym, 0.06)), jours: joursDe(ym),
    trafic: r1(S.tickets * (moi ? 1 : k * D.bruit('t' + id + ym, 0.06)) / joursDe(ym)),
    food: r1(P.coutMatierePct + dec[0]), labour: r1(P.labourPct + dec[1]), overhead: r1(P.overheadPct + dec[2]),
    recurrence: r1(38 + D.alea('rc' + id + ym)() * 6 + (ym >= '2026-07' ? 2 : 0) + dec[3]), ca: S.ca * (moi ? 1 : k) };
}

function leviers(q) {
  let y = +(q.get('annee') || AUJ.slice(0, 4)), m = +(q.get('mois') || AUJ.slice(5, 7));
  if (ymDe(y, m) > AUJ.slice(0, 7)) { y = +AUJ.slice(0, 4); m = +AUJ.slice(5, 7); }
  const ym = ymDe(y, m), ym1 = ymDe(y - 1, m), enCours = ym === AUJ.slice(0, 7);
  const serieMois = Array.from({ length: 12 }, (_, i) => moinsMois(ym, 11 - i));
  const mags = [[SHOP, NOM, 1]].concat(VOISINS.map(([i, k]) => [String(100 + i), 'Magasin ' + i, k]));
  const V = mags.map(([id, nom, k]) => ({ id, nom, k, x: valeurs(id, k, ym), x1: valeurs(id, k, ym1) }));
  const resTraf = V.reduce((s, g) => s + g.x.trafic, 0) / V.length;
  const pond = cle => { const t = V.reduce((s, g) => s + g.x.ca, 0); return r1(V.reduce((s, g) => s + g.x[cle] * g.x.ca, 0) / t); };
  const pond1 = cle => { const t = V.reduce((s, g) => s + g.x1.ca, 0); return r1(V.reduce((s, g) => s + g.x1[cle] * g.x1.ca, 0) / t); };
  const magasins = V.map(g => {
    const x = g.x, x1 = g.x1, moi = g.id === SHOP;
    const t1 = x1 ? r1(x1.tickets / x1.jours) : null;
    const e = r1(100 * (x.trafic / resTraf - 1));
    const mois = { ouverture: false, trafic: { v: x.trafic, n1: t1, delta: t1 ? r1(100 * (x.trafic / t1 - 1)) : null, ecartReseau: e, niveau: niveau(SEUILS.trafic, e) } };
    ['food', 'labour', 'overhead'].forEach(c => { mois[c] = { v: x[c], n1: x1 ? x1[c] : null, delta: x1 ? r1(x[c] - x1[c]) : null, niveau: niveau(SEUILS[c], x[c]) }; });
    const note = moi ? 4.6 : r1(4.3 + D.alea('g' + g.id)() * 0.5);
    mois.xp = { v: note, avis: moi ? 318 : Math.round(150 + D.alea('av' + g.id)() * 300), n1: null, delta: null, niveau: niveau(SEUILS.xp, note) };
    const cli = Math.round(x.tickets * 0.42), rev = Math.round(cli * x.recurrence / 100);
    mois.recurrence = { v: x.recurrence, n1: x1 ? x1.recurrence : null, delta: x1 ? r1(x.recurrence - x1.recurrence) : null, niveau: niveau(SEUILS.recurrence, x.recurrence),
      clients: cli, revenus: rev, partTickets: 41.5, pwa: { v: r1(x.recurrence + 9), n1: null, delta: null, niveau: null, clients: Math.round(cli * 0.31), revenus: Math.round(cli * 0.31 * (x.recurrence + 9) / 100), partTickets: 12.8 },
      inconnus: Math.round(x.tickets * 0.58), joursLus: x.jours, jours: x.jours, complet: true, repartition: null };
    const serie = { trafic: [], food: [], labour: [], overhead: [], recurrence: [] };
    serieMois.forEach(s => { const v = valeurs(g.id, g.k, s); ['trafic', 'food', 'labour', 'overhead', 'recurrence'].forEach(c => serie[c].push(v ? v[c] : null)); });
    return { id: g.id, nom: g.nom, ville: '', franchise: '', ouverture: null, mois, cumul: null, horsNorme: [], serie };
  });
  const reseauMois = { trafic: { v: r1(resTraf), comp: r1(resTraf), n1: r1(V.reduce((s, g) => s + g.x1.tickets / g.x1.jours, 0) / V.length), delta: null, magasins: V.length } };
  reseauMois.trafic.delta = r1(100 * (reseauMois.trafic.comp / reseauMois.trafic.n1 - 1));
  ['food', 'labour', 'overhead', 'recurrence'].forEach(c => { const v = pond(c), v1 = pond1(c); reseauMois[c] = { v, comp: v, n1: v1, delta: r1(v - v1), niveau: niveau(SEUILS[c], v) }; });
  reseauMois.xp = { v: 4.52, avis: 2140, niveau: niveau(SEUILS.xp, 4.52) };
  return { leviers: LEVIERS, annee: y, mois: m, moisEnCours: enCours, jours: joursDe(ym), cumulAu: enCours ? (m > 1 ? m - 1 : null) : m,
    seuils: SEUILS, serieMois, magasins, reseau: { mois: reseauMois, cumul: null }, googleN1Des: '2027-10',
    recurrence: { debut: '2025-09-01', kpi: 'clients-revenus', kpiPwa: null } };
}

module.exports = function (m, p, q) {
  if (m === 'GET' && p === '/leviers/magasins') { return leviers(q); }
  return undefined;
};
