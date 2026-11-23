/* La Trésorerie (?vue=tresorerie, module tresorerie.js, serveur panel/src/tresorerie.php) pour le magasin de démo :
 * le plan enregistré (/tresorerie/plan, lu et réécrit), les repères (/tresorerie/references : l'année passée, le budget
 * et le réalisé, semaine par semaine et mois par mois), l'onglet « Budget & étude » (/tresorerie/budget) et l'onglet
 * « Frais » (/exploitation/frais). Données fictives.
 *
 * Le plan de démo part du lundi 2 novembre 2026 : le solde réel du dimanche 8 est saisi, celui du dimanche 15 ne l'est
 * pas encore (la vidéo le tape). Les ventes viennent du même générateur que Semaine et Mois (lib/demo.js) : la semaine
 * close du 9 novembre vaut ici ce qu'elle vaut dans la frise des semaines. Le budget 2026 est validé, celui de 2027
 * encodé, pas encore validé. Les écritures (POST /tresorerie/plan) sont gardées le temps du tournage. */
const D = require('./lib/demo');
const A = require('./08_annee');
const { r2, AUJ, SHOP } = D;

const DEBUT = '2026-11-02';
const finMois = ym => D.iso(new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0));
const lundi = d => D.plus(d, -((D.dow(d) + 6) % 7));
/** Le dernier lundi du plan (tresorerie.php, trFin) : celui de la dernière semaine de l'année où tombe le début + 26 semaines. */
function trFin(debut) { const y = D.plus(debut, 182).slice(0, 4); return lundi(y + '-12-31'); }
/** Le CA d'une fenêtre, jour par jour (la journée en cours à 15 h 40) ; null si aucun jour n'a vendu. */
function ventes(d1, d2) {
  let s = 0, vu = false;
  for (let d = d1; d <= d2 && d <= AUJ; d = D.plus(d, 1)) {
    if (d < '2024-06-14') { continue; }
    s += D.journee(d, d === AUJ ? D.MAINTENANT : null).ca; vu = true;
  }
  return vu ? r2(s) : null;
}
const budgetJour = d => (d >= '2026-01-01' && d <= '2027-12-31') ? D.objectifJour(d) : null;

/* --- les repères ------------------------------------------------------------------------------------------------ */
function references(debut) {
  const fin = trFin(debut), dernier = D.plus(fin, 6);
  const semaines = [];
  for (let m = debut; m <= fin; m = D.plus(m, 7)) {
    const dim = D.plus(m, 6), m1 = D.plus(m, -364), d1 = D.plus(m1, 6);
    let b = 0, nb = 0; for (let j = m; j <= dim; j = D.plus(j, 1)) { const v = budgetJour(j); if (v != null) { b += v; nb++; } }
    const ok = nb >= 4;
    semaines.push({ du: m, n1: d1 < AUJ ? ventes(m1, d1) : null, budget: ok ? r2(b * 7 / nb) : null, budgetSource: ok ? 'budget' : null,
      budgetProfil: ok ? 'n1' : null, budgetJours: nb, realise: m <= AUJ ? ventes(m, dim < AUJ ? dim : AUJ) : null, complet: dim < AUJ });
  }
  const y0 = +debut.slice(0, 4), y1 = +fin.slice(0, 4), mois = [];
  for (let y = y0; y <= y1; y++) {
    for (let mo = 1; mo <= 12; mo++) {
      const ym = y + '-' + String(mo).padStart(2, '0'), du = ym + '-01', au = finMois(ym), ym1 = (y - 1) + ym.slice(4);
      const bud = y <= 2027 ? A.budgetMois(ym) : null;
      mois.push({ ym, realise: du <= AUJ ? ventes(du, au < AUJ ? au : AUJ) : null, complet: au < AUJ,
        avantPlan: du < debut && du <= AUJ ? ventes(du, [au, D.plus(debut, -1), AUJ].sort()[0]) : null,
        n1: finMois(ym1) < AUJ ? ventes(ym1 + '-01', finMois(ym1)) : null, budget: bud, budgetSource: bud ? 'budget' : null });
    }
  }
  // La tendance : les 13 dernières semaines closes face aux mêmes semaines N-1.
  let ca = 0, n1 = 0; const l0 = lundi(AUJ);
  for (let k = 1; k <= 13; k++) { const m = D.plus(l0, -7 * k); ca += ventes(m, D.plus(m, 6)); n1 += ventes(D.plus(m, -364), D.plus(m, -358)); }
  const budgets = [y0, y1].filter((y, i, t) => t.indexOf(y) === i).map(y => ({ annee: y, fiche: true, valideLe: y === 2026 ? '2026-01-20' : null,
    moisBudget: 12, moisTheorique: 0, total: r2(Array.from({ length: 12 }, (_, i) => A.budgetMois(y + '-' + String(i + 1).padStart(2, '0'))).reduce((a, b) => a + b, 0)) }));
  return { shop: SHOP, aujourdhui: AUJ, debut, fin, dernierJour: dernier, semaines, mois,
    tendance: { semaines: 13, ca: r2(ca), n1: r2(n1), pct: Math.round(1000 * (ca / n1 - 1)) / 10 },
    budgets, taux: { matiere: 32, personnel: 17, fraisGeneraux: 13.5 }, charges: CHARGES.map(c => ({ categorie: c.categorie, poste: c.poste, pct: c.pctValide || c.pctReseau, matiere: c.matiere })),
    sources: ['panel'], premiereVente: '2024-06-14' };
}

/* --- le budget et l'étude (onglet « Budget & étude ») -------------------------------------------------------------- */
// Les postes du budget (Budget › Paramètres) : le taux validé au cockpit et celui du réseau.
const CHARGES = [
  { categorie: 'Matières premières', poste: 'Achats de marchandises', pctValide: 32, pctReseau: 32, matiere: true },
  { categorie: 'Personnel', poste: 'Salaires et charges sociales', pctValide: 17, pctReseau: 17, matiere: false },
  { categorie: 'Frais généraux', poste: 'Loyer et charges', pctValide: 7, pctReseau: 7.5, matiere: false },
  { categorie: 'Frais généraux', poste: 'Énergie', pctValide: 2.2, pctReseau: 2.2, matiere: false },
  { categorie: 'Frais généraux', poste: 'Redevance de la marque', pctValide: 3, pctReseau: 3, matiere: false },
  { categorie: 'Frais généraux', poste: 'Assurances, comptable, divers', pctValide: 1.3, pctReseau: 1.5, matiere: false },
];
// La saisonnalité de l'étude : la part de l'année de chaque mois (somme 100).
const SAISON = [7.6, 7.0, 7.9, 8.1, 8.5, 8.0, 7.7, 7.3, 8.1, 8.6, 9.0, 12.2];
function budget(debut) {
  const fin = trFin(debut), annees = [];
  for (let y = +debut.slice(0, 4); y <= +fin.slice(0, 4); y++) { annees.push(y); }
  const pot = Math.round(annees.length && A.budgetMois ? Array.from({ length: 12 }, (_, i) => A.budgetMois('2026-' + String(i + 1).padStart(2, '0'))).reduce((a, b) => a + b, 0) / 0.9 / 10000 : 0) * 10000;
  const exercices = annees.map(y => {
    const ex = y - 2024 + 1;
    const mois = Array.from({ length: 12 }, (_, i) => { const ym = y + '-' + String(i + 1).padStart(2, '0'); return { mois: i + 1, budget: A.budgetMois(ym), theorique: r2(pot * Math.min(100, [0, 70, 80, 90][ex] || 100) / 100 * SAISON[i] / 100) }; });
    const tb = r2(mois.reduce((a, x) => a + x.budget, 0)), tt = r2(mois.reduce((a, x) => a + x.theorique, 0));
    return { annee: y, fiche: true, valideLe: y === 2026 ? '2026-01-20' : null, panierEngagement: 13.2, caTheoriqueAn: tt,
      etude: { date: '2023-11-06', source: 'Étude d’implantation (réseau)', potentielMenages: 4800, potentielMaturite: pot, anneeExploitation: ex,
        monteeEnRegime: { a1: 70, a2: 80, a3: 90 }, annexe: null },
      saisonnalite: SAISON, saisonnaliteSource: 'etude', mois, moisBudget: 12, moisTheorique: 12, totalBudget: tb, totalTheorique: tt };
  });
  const reel = [];
  for (let ym = (annees[0] - 1) + '-01'; ym <= AUJ.slice(0, 7); ym = D.iso(new Date(+ym.slice(0, 4), +ym.slice(5, 7), 1)).slice(0, 7)) {
    const P = A.pnlMois(ym); if (!P || !P.realise) { continue; }
    reel.push({ ym, ca: P.realise, matiere: P.coutMatierePct, personnel: P.labourPct, fraisGeneraux: P.overheadPct, marge: P.netPct });
  }
  return { shop: SHOP, annees, exercices, charges: CHARGES, paliers: [{ nom: 'Marge brute', droite: 'cat:Matières premières' }, { nom: 'Disponible pour la rémunération', droite: 'tout' }],
    reel, taux: { food: 32, labour: 17, overhead: 13.5 }, liens: { crm: null } };
}

/* --- le plan enregistré ------------------------------------------------------------------------------------------- */
function planInitial() {
  return {
    v: 2, debut: DEBUT, base: 'budget', croissance: 0, startCash: 11850, coussin: 5000, plafond: 0.33,
    ventes: {}, ponct: { '2026-12-14': 2400, '2027-01-11': 1800 }, reel: { '2026-11-02': 5020 },
    fournisseurs: [
      { id: 'f1', nom: 'Meunerie (farines)', mode: 'pct', pct: 0.08, fixe: 0, montants: { '2026-11-02': 1190, '2026-11-09': 1240 } },
      { id: 'f2', nom: 'Crèmerie (beurre, crème, œufs)', mode: 'pct', pct: 0.11, fixe: 0, montants: { '2026-11-02': 1610 } },
      { id: 'f3', nom: 'Épicerie fine et chocolat', mode: 'pct', pct: 0.07, fixe: 0, montants: { '2026-11-02': 980 } },
      { id: 'f4', nom: 'Fruits et légumes', mode: 'pct', pct: 0.03, fixe: 0, montants: {} },
      { id: 'f5', nom: 'Boissons', mode: 'fixe', pct: 0, fixe: 320, montants: {} },
      { id: 'f6', nom: 'Emballages', mode: 'fixe', pct: 0, fixe: 140, montants: {} },
    ],
    paiements: [
      { id: 'p1', libelle: 'Rémunération du gérant', cat: 'Gérant', montant: 4800, freq: 'Chaque mois', sem: 1, debut: '', fin: '', note: 'Ce que je me verse.' },
      { id: 'p2', libelle: 'Salaires nets', cat: 'Personnel', montant: 7400, freq: 'Chaque mois', sem: 1, debut: '', fin: '', note: 'Secrétariat social' },
      { id: 'p3', libelle: 'ONSS et précompte', cat: 'TVA & ONSS', montant: 2900, freq: 'Chaque mois', sem: 2, debut: '', fin: '', note: 'Montants du comptable' },
      { id: 'p4', libelle: 'TVA du trimestre', cat: 'TVA & ONSS', montant: 5200, freq: 'Chaque trimestre', sem: 3, debut: '', fin: '', note: 'Montants du comptable' },
      { id: 'p13', libelle: 'Versements anticipés d’impôt', cat: 'TVA & ONSS', montant: 9000, freq: 'Chaque trimestre', sem: 2, debut: '', fin: '', note: 'Montants du comptable' },
      { id: 'p5', libelle: 'Loyer', cat: 'Frais fixes', montant: 4300, freq: 'Chaque mois', sem: 1, debut: '', fin: '', note: 'Bail commercial' },
      { id: 'p6', libelle: 'Redevance de la marque', cat: 'Frais fixes', montant: 1850, freq: 'Chaque mois', sem: 2, debut: '', fin: '', note: '3 % du CA du mois précédent' },
      { id: 'p7', libelle: 'Énergie', cat: 'Frais fixes', montant: 1250, freq: 'Chaque mois', sem: 3, debut: '', fin: '', note: 'Acompte mensuel' },
      { id: 'p8', libelle: 'Comptable', cat: 'Frais fixes', montant: 420, freq: 'Chaque mois', sem: 3, debut: '', fin: '', note: '' },
      { id: 'p9', libelle: 'Assurances', cat: 'Frais fixes', montant: 690, freq: 'Chaque trimestre', sem: 2, debut: '', fin: '', note: '' },
      { id: 'p10', libelle: 'Prêt bancaire (installation)', cat: 'Crédits & leasing', montant: 2650, freq: 'Chaque mois', sem: 4, debut: '', fin: '2030-05-31', note: 'Fin en mai 2030' },
      { id: 'p11', libelle: 'Leasing four et vitrine', cat: 'Crédits & leasing', montant: 890, freq: 'Chaque mois', sem: 4, debut: '', fin: '', note: '' },
      { id: 'p14', libelle: 'Rénovation de la boutique', cat: 'Investissements', montant: 26000, freq: 'Chaque mois', sem: 2, debut: '2027-01-01', fin: '2027-01-31', note: 'Devis signé, travaux la semaine du 11 janvier' },
      { id: 'p12', libelle: 'Échéancier fournisseur', cat: 'Remboursement dettes', montant: 600, freq: 'Chaque mois', sem: 2, debut: '', fin: '2027-03-31', note: 'Accord de juin, 10 mensualités' },
    ],
    hors: [
      { id: 'h1', libelle: 'Factures fournisseurs impayées', montant: 1850, note: 'Ce qui reste dû aujourd’hui, hors échéancier déjà dans le plan.' },
      { id: 'h2', libelle: 'ONSS, TVA, précompte : arriérés', montant: null, note: 'À demander au comptable.' },
    ],
  };
}
let PLAN = null, VERSION = 0, MAJ = null;
function lirePlan() {
  if (!PLAN) { PLAN = planInitial(); VERSION = 14; MAJ = '2026-11-15 21:12:00'; }
  return { shop: SHOP, plan: PLAN, version: VERSION, majLe: MAJ, debutPropose: '2026-11-16' };
}

/* --- les frais du mois (onglet « Frais ») ------------------------------------------------------------------------- */
function frais(q) {
  const mois = q.get('mois') || AUJ.slice(0, 7);
  const L = [['Loyer', 'Loyer', 3910, false, 0], ['Énergie', 'Énergie', 1250, true, 21], ['Redevance de la marque', 'Redevance', 1850, true, 21],
    ['Assurances', 'Assurances', 230, false, 0], ['Comptable', 'Honoraires', 420, true, 21], ['Télécom et caisse', 'Abonnements', 96, true, 21],
    ['Entretien et nettoyage', 'Entretien', 310, null, null], ['Frais bancaires', 'Banque', 58, false, 0]];
  const lignes = L.map(([poste, type, montant, tva, taux], i) => {
    const ai = tva == null, ht = ai ? montant : (tva ? r2(montant / (1 + taux / 100)) : montant);
    return { cle: 'k' + i, poste, type, montant, tva, taux, ht, tvaMontant: r2(montant - ht), aIndiquer: ai };
  });
  const saisi = r2(lignes.reduce((a, l) => a + l.montant, 0)), ht = r2(lignes.reduce((a, l) => a + l.ht, 0));
  const ai = lignes.filter(l => l.aIndiquer);
  return { shop: SHOP, mois, lu: true, luLe: AUJ + ' 15:32:00', lignes, taux: [0, 6, 12, 21], tauxOui: 21, saisi, ht, tva: r2(saisi - ht), part: ht / saisi,
    postes: lignes.length, aIndiquer: ai.length, aIndiquerEur: r2(ai.reduce((a, l) => a + l.montant, 0)),
    jour: mois === AUJ.slice(0, 7) ? { saisi: r2(saisi / 30), ht: r2(ht / 30) } : null,
    types: L.map(([, type], i) => ({ id: i + 1, nom: type })), ajouts: [] };
}

module.exports = function (m, p, q, corps) {
  if (p === '/tresorerie/plan' && m === 'GET') { return lirePlan(); }
  if (p === '/tresorerie/plan' && m === 'POST') {
    lirePlan();
    const c = corps || {};
    if (+c.version !== VERSION) { return { __status: 409, json: { error: 'le plan a été modifié sur un autre écran', plan: PLAN, version: VERSION, majLe: MAJ } }; }
    if (c.donnees) { PLAN = c.donnees; } VERSION++; MAJ = AUJ + ' 15:40:00';
    return { ok: true, version: VERSION, majLe: MAJ };
  }
  if (m !== 'GET') { return undefined; }
  if (p === '/tresorerie/references') { return references(q.get('debut') && q.get('debut') !== 'undefined' ? lundi(q.get('debut')) : DEBUT); }
  if (p === '/tresorerie/budget') { return budget(q.get('debut') && q.get('debut') !== 'undefined' ? lundi(q.get('debut')) : DEBUT); }
  if (p === '/exploitation/frais') { return frais(q); }
  return undefined;
};
module.exports._references = references;
module.exports._planInitial = planInitial;
