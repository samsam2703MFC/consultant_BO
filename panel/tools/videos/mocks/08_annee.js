/* L'Année (?vue=annee, et son onglet Trimestre) pour le magasin de démo : le plan de développement (/plan), les douze
 * mois face à l'an passé (/ventes/semaines?vue=annee) et les mois du suivi avec leurs ratios (/stores/perf).
 * Données fictives. Ce module passe avant 15_chiffres.js (ordre des noms) : /stores/perf reprend SES mois (CA, budget,
 * clients) et y ajoute ce que le tableau des mois lit (food, labour, overhead, marge nette), tirés du même P&L que la
 * vue Mois (/exploitation/periode) ; les vues Semaine et Mois n'en lisent que le CA et le budget, inchangés. */
const D = require('./lib/demo');
const chiffres = require('./15_chiffres');
const { r2, r1, AUJ, MAINTENANT, SHOP, NOM } = D;

const finMois = ym => D.iso(new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0));
const nbJours = (du, au) => Math.round((D.jourDe(au) - D.jourDe(du)) / 86400000) + 1;
const OUVERTURE = '2024-06-14';

/** Les ventes d'une fenêtre (la journée en cours arrêtée à 15 h 40), comme 15_chiffres. */
function somme(du, au) {
  let ca = 0, tk = 0, n = 0;
  for (let d = du; d <= au && d <= AUJ; d = D.plus(d, 1)) {
    if (d < OUVERTURE) { continue; }
    const J = D.journee(d, d === AUJ ? MAINTENANT : null); ca += J.ca; tk += J.tickets; n++;
  }
  return { ca: r2(ca), tickets: tk, jours: n, source: n ? 'panel' : null, articles: n ? Math.round(tk * 2.3 * D.bruit('ar' + du, 0.04)) : null };
}

/** /ventes/semaines?vue=annee : les douze mois de l'année, chacun face au même mois un an plus tôt (à date et entier). */
function douzeMois(date) {
  const Y = +date.slice(0, 4), L = [];
  const MN = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  for (let mo = 1; mo <= 12; mo++) {
    const ym = Y + '-' + String(mo).padStart(2, '0'), du = ym + '-01', au = finMois(ym), futur = du > AUJ;
    const jusqua = au < AUJ ? au : AUJ;
    const ym1 = (Y - 1) + '-' + String(mo).padStart(2, '0'), du1 = ym1 + '-01', au1Mois = finMois(ym1);
    const au1 = futur ? au1Mois : (ym1 + '-' + jusqua.slice(8, 10) < au1Mois ? ym1 + '-' + jusqua.slice(8, 10) : au1Mois);
    const s = futur ? { ca: 0, tickets: 0, jours: 0, source: null, articles: null } : somme(du, jusqua);
    const s1 = somme(du1, au1), sM = somme(du1, au1Mois);
    L.push({ lab: MN[mo - 1], iso: mo, du, au, enCours: !futur && au >= AUJ, futur, joursServis: futur ? 0 : nbJours(du, jusqua),
      ca: s.ca, tickets: s.tickets, jours: s.jours, source: s.source, articles: s.articles, panier: s.tickets ? r2(s.ca / s.tickets) : null,
      n1: s1.source ? { du: du1, ca: s1.ca, tickets: s1.tickets, jours: s1.jours, source: s1.source, articles: s1.articles } : null,
      n1Mois: sM.source ? { ca: sM.ca, tickets: sM.tickets, jours: sM.jours, source: sM.source, articles: sM.articles } : null });
  }
  return { shop: SHOP, vue: 'annee', semaines: L, n1Motif: null, source: 'panel (margin-heatmap, par jour) · N-1 = même mois un an plus tôt, arrêté au même jour servi' };
}

/** Le P&L d'un mois du magasin de démo : la ligne du magasin dans /exploitation/periode (vue Mois), la même qu'à l'écran. */
const P_MOIS = {};
function pnlMois(ym) {
  if (!P_MOIS[ym]) {
    const date = ym === AUJ.slice(0, 7) ? AUJ : finMois(ym);
    const per = chiffres('GET', '/exploitation/periode', new URLSearchParams({ vue: 'mois', date }), null);
    P_MOIS[ym] = ((per && per.magasins) || []).find(x => String(x.shopId) === SHOP) || null;
  }
  return P_MOIS[ym];
}

/** Le budget d'un mois (encodé au cockpit pour 2026 et 2027) : la somme des objectifs de ses jours. */
function budgetMois(ym) {
  let b = 0; for (let d = ym + '-01'; d <= finMois(ym); d = D.plus(d, 1)) { b += D.objectifJour(d); }
  return r2(b);
}

/** /stores/perf : les mois de 15_chiffres.js, avec leurs ratios et leur marge nette ; les mois à venir de l'année n'ont
 * que leur budget (« un mois budgété sans réel doit exister aussi », ep_perf). */
function perf(q) {
  const L0 = chiffres('GET', '/stores/perf', q, null);
  if (!Array.isArray(L0)) { return L0; }
  const L = L0.slice();
  (q.get('annees') || '2025,2026').split(',').map(Number).forEach(a => {
    for (let mo = 1; mo <= 12; mo++) {
      const ym = a + '-' + String(mo).padStart(2, '0');
      if (ym <= AUJ.slice(0, 7) || a < 2026 || a > 2027) { continue; }
      L.push({ storeId: +SHOP, annee: a, mois: mo, ca: null, tickets: null, caBudget: budgetMois(ym), caTheorique: null, caComptoir: null, ticketsComptoir: null,
        margeNette: null, margePct: null, panierMoyen: null, foodCostPct: null, labourCostPct: null, overheadPct: null });
    }
  });
  return L.map(c => {
    if (String(c.storeId) !== SHOP || !c.ca) { return c; }
    const P = pnlMois(c.annee + '-' + String(c.mois).padStart(2, '0'));
    if (!P) { return c; }
    return Object.assign({}, c, { caTheorique: null, panierMoyen: c.tickets ? r2(c.ca / c.tickets) : null,
      panierBase: 'comptoir', panierRetenu: c.ticketsComptoir ? r2(c.caComptoir / c.ticketsComptoir) : null,
      foodCostPct: P.coutMatierePct, labourCostPct: P.labourPct, overheadPct: P.overheadPct,
      margeNette: P.net, margePct: Math.round(P.netPct * 10) / 1000, sansPlanning: 0, coutsManque: false });
  });
}

/* --- Le plan de développement (/plan) ------------------------------------------------------------------------------ */

/** Les quatre trimestres d'une année, agrégés depuis les mois du suivi (consultant/src/plan.php, planTrimestres). */
function trimestres(cells, Y, notes, revues) {
  const moisC = +AUJ.slice(5, 7), anC = +AUJ.slice(0, 4), jM = +finMois(AUJ.slice(0, 7)).slice(8, 10), jJ = +AUJ.slice(8, 10);
  const dernierComplet = Y < anC ? 12 : (Y > anC ? 0 : moisC - 1);
  const T = [];
  for (let q = 1; q <= 4; q++) {
    let obj = 0, objN = 0, ca = 0, caN = 0, tk = 0, food = 0, foodCa = 0, lab = 0, labCa = 0, net = 0, netCa = 0, att = 0, caC = 0, tkC = 0;
    for (let m = (q - 1) * 3 + 1; m <= q * 3; m++) {
      const c = cells.find(x => +x.annee === Y && +x.mois === m) || null;
      const o = c && c.caBudget > 0 ? +c.caBudget : null;
      if (o != null) { obj += o; objN++; if (Y < anC || (Y === anC && m < moisC)) { att += o; } else if (Y === anC && m === moisC) { att += o * jJ / jM; } }
      if (c && c.ca > 0) {
        ca += c.ca; caN++; tk += c.tickets || 0;
        if (c.foodCostPct != null) { food += c.foodCostPct * c.ca; foodCa += c.ca; }
        if (c.labourCostPct != null) { lab += c.labourCostPct * c.ca; labCa += c.ca; }
        if (c.margeNette != null) { net += c.margeNette; netCa += c.ca; }
        caC += c.caComptoir || 0; tkC += c.ticketsComptoir || 0;
      }
    }
    const clos = dernierComplet >= q * 3, enCours = !clos && Y === anC && moisC >= (q - 1) * 3 + 1, futur = !clos && !enCours;
    const panier = tk > 0 ? ca / tk : null, ecart = objN && caN ? ca - att : null;
    T.push({ t: q, label: 'T' + q + ' ' + Y, mois: [(q - 1) * 3 + 1, (q - 1) * 3 + 2, q * 3], clos, enCours, futur,
      objectif: objN ? r2(obj) : null, attendu: objN ? r2(att) : null, realise: caN ? r2(ca) : null, moisRealises: caN,
      ecart: ecart != null ? r2(ecart) : null, ecartPct: ecart != null && att > 0 ? r1(100 * ecart / att) : null,
      clients: ecart != null && panier ? Math.round(-ecart / panier) : null, panier: panier != null ? r2(panier) : null,
      food: foodCa > 0 ? r1(food / foodCa) : null, labour: labCa > 0 ? r1(lab / labCa) : null, netPct: netCa > 0 ? r1(100 * net / netCa) : null,
      notes: notes[q] || {}, revue: revues[q] || { etape: 1, validePar: null, valideLe: null },
      panierBase: caN ? 'comptoir' : null, panierRetenu: tkC ? r2(caC / tkC) : null });
  }
  return T;
}

// Les trois voix, les actions et le rituel du magasin de démo (fictifs) : 2026, sa troisième année d'exploitation.
const NOTES = {
  2026: {
    3: { franchise: { texte: 'Un été plus calme que prévu en juillet, rattrapé en septembre avec la rentrée des bureaux.', par: 'Camille R.', le: '2026-10-06 18:20:00' },
      consultant: { texte: 'Objectif du trimestre tenu. Le personnel reste au-dessus de la cible les samedis : planning à revoir au flux.', par: 'Hugo P.', le: '2026-10-08 10:05:00' },
      marque: { texte: 'Bonne application de la gamme de rentrée.', par: 'Réseau', le: '2026-10-09 09:30:00' } },
    4: { franchise: { texte: 'Novembre au-dessus de l’attendu. Commandes de bûches ouvertes dès le 1er décembre.', par: 'Camille R.', le: '2026-11-16 20:45:00' },
      consultant: { texte: 'À suivre : la vitrine de l’après-midi en semaine, et le taux de personnel en décembre.', par: 'Hugo P.', le: '2026-11-17 14:10:00' } },
  },
};
const REVUES = { 2026: { 1: { etape: 5, validePar: 'Hugo P.', valideLe: '2026-04-09 11:00:00' }, 2: { etape: 5, validePar: 'Hugo P.', valideLe: '2026-07-07 10:30:00' },
  3: { etape: 5, validePar: 'Hugo P.', valideLe: '2026-10-08 10:05:00' }, 4: { etape: 2, validePar: null, valideLe: null } } };
const ACTIONS = {
  2026: [
    { id: 1, libelle: 'Planning au flux le samedi matin', effetAn: 6500, trimestre: 2, statut: 'fait', responsable: 'Camille R.' },
    { id: 2, libelle: 'Offre petit-déjeuner pour les bureaux du quartier (B2B)', effetAn: 9000, trimestre: 3, statut: 'fait', responsable: 'Camille R.' },
    { id: 3, libelle: 'Inscription des clients à l’application en caisse', effetAn: 4000, trimestre: 3, statut: 'encours', responsable: 'Julie M.' },
    { id: 4, libelle: 'Vitrine de Noël et commandes de bûches dès le 1er décembre', effetAn: 7500, trimestre: 4, statut: 'encours', responsable: 'Camille R.' },
    { id: 5, libelle: 'Vitrine de l’après-midi en semaine : moins de pièces après 15 h', effetAn: 3000, trimestre: 4, statut: 'alancer', responsable: 'Inès B.' },
  ],
};

function plan(q) {
  const Y = +(q.get('exercice') || AUJ.slice(0, 4));
  const cells = perf(new URLSearchParams({ granularite: 'mois', annees: (Y - 1) + ',' + Y, pro: SHOP })).filter(c => String(c.storeId) === SHOP);
  const T = trimestres(cells, Y, NOTES[Y] || {}, REVUES[Y] || {});
  const budget = T.reduce((a, t) => a + (t.objectif || 0), 0);
  // L'étude : ouvert en juin 2024, 2026 est la troisième année d'exploitation (montée en régime 70, 80, 90 %, puis 100 %).
  const anEx0 = 2024, coef = k => k <= 1 ? 70 : (k === 2 ? 80 : (k === 3 ? 90 : 100));
  const pot = Math.round(budget / 0.9 / 10000) * 10000;
  const annees = [0, 1, 2, 3, 4].map(k => { const an = Y + k, x = Math.min(4, Math.max(1, an - anEx0 + 1)); return { an, anneeExploitation: x, coef: coef(x), ca: r2(pot * coef(x) / 100), maturite: x >= 4 }; });
  const objectif = Math.round(budget / 5000) * 5000;
  const engagements = Y === 2026 ? { 2026: { objectif, rampe: annees[0].ca, motif: 'Le budget validé, mois par mois : la rampe de l’étude, et les actions du plan.', statut: 'valide', engagePar: 'Camille R.', engageLe: '2026-01-14 19:02:00', validePar: 'Hugo P.', valideLe: '2026-01-20 10:15:00' } } : {};
  const objAn = engagements[Y] ? engagements[Y].objectif : annees[0].ca;
  let realise = 0, attendu = 0, objMois = 0;
  T.forEach(t => { realise += t.realise || 0; attendu += t.attendu || 0; objMois += t.objectif || 0; });
  const part = objMois > 0 ? attendu / objMois : null, attEng = part != null ? objAn * part : null;
  const proj = attEng ? realise / attEng * objAn : null;
  let courant = 1; T.forEach(t => { if (t.enCours) { courant = t.t; } });
  return { shop: SHOP, magasin: NOM, exercice: Y, aujourdhui: AUJ,
    rampe: { potentiel: pot, anneeExploitation: annees[0].anneeExploitation, annees }, engagements,
    annee: { objectif: objAn, objectifSource: engagements[Y] ? 'engagement' : 'rampe', rampe: annees[0].ca, realise: r2(realise),
      attendu: attEng != null ? r2(attEng) : null, partEcoulee: part != null ? r1(part * 100) : null,
      ecart: attEng != null ? r2(realise - attEng) : null, projection: proj != null ? r2(proj) : null, budgetMensuelTotal: r2(objMois) },
    actions: ACTIONS[Y] || [], trimestres: T, historique: [], trimestreCourant: courant,
    voix: { franchise: 'Franchisé', consultant: 'Consultant', marque: 'Marque' } };
}

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/plan') { return plan(q); }
  if (p === '/ventes/semaines' && q.get('vue') === 'annee') { return douzeMois(q.get('date') || AUJ); }
  if (p === '/stores/perf') { return perf(q); }
  return undefined;
};
module.exports.pnlMois = pnlMois;
module.exports.somme = somme;
module.exports.budgetMois = budgetMois;
module.exports.perf = perf;
