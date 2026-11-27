/* Mon équipe (vue=equipe) : les routes /dashboard/equipe/* aux formes de panel/src/equipe.php, lues par
 * panel/public/dashboard/equipe.js. Une équipe fictive de dix personnes, un planning tiré d'une semaine type
 * (du 1er au 29 novembre 2026), le CA de l'Opérationnel pour la semaine du 16 (mardi en orange, vendredi en rouge, jeudi
 * en vert) et du même ordre (1 600 – 2 900 €) pour les autres semaines. Les écritures (service, postes, options, retirer, recopie, créer) modifient
 * l'état en mémoire : la relecture du planning montre le changement. Aucune donnée réelle. */
'use strict';

const AUJ = '2026-11-19', MAINTENANT = '15:40';
const SECTEURS = [{ id: 1, nom: 'Boulangerie' }, { id: 2, nom: 'Pâtisserie' }, { id: 3, nom: 'Snacking' }, { id: 4, nom: 'Traiteur' }];
const PRODUITS = {
  1: ['Baguette tradition', 'Baguette blanche', 'Pain de campagne', 'Pain complet', 'Pain aux céréales', 'Pain de seigle', 'Demi-gris', 'Ciabatta', 'Pistolet', 'Pain d’épeautre', 'Croissant', 'Pain au chocolat', 'Pain aux raisins', 'Couque suisse', 'Chausson aux pommes', 'Brioche tressée', 'Cramique', 'Craquelin'],
  2: ['Éclair au chocolat', 'Éclair café', 'Tarte au riz', 'Tarte aux pommes', 'Tarte au citron meringuée', 'Millefeuille', 'Merveilleux', 'Paris-Brest', 'Royal au chocolat', 'Fraisier', 'Mousse trois chocolats', 'Tartelette framboise', 'Macarons (boîte de 6)', 'Bavarois'],
  3: ['Sandwich jambon-fromage', 'Sandwich poulet curry', 'Wrap végétarien', 'Panini mozzarella', 'Quiche lorraine', 'Salade César', 'Croque-monsieur', 'Pizza margherita (part)', 'Club thon'],
  4: ['Vol-au-vent', 'Lasagne', 'Feuilleté saumon', 'Mini-quiches (plateau)', 'Plateau apéritif'],
  0: ['Café', 'Jus d’orange pressé', 'Eau minérale'],
};
const POSTES = [
  { id: 1, nom: 'Four boulangerie', secteur: 1 }, { id: 2, nom: 'Tourage viennoiserie', secteur: 1 },
  { id: 3, nom: 'Pâtisserie', secteur: 2 }, { id: 4, nom: 'Préparation snacking', secteur: 3 },
  { id: 5, nom: 'Vente comptoir', secteur: null }, { id: 6, nom: 'Caisse', secteur: null },
  { id: 7, nom: 'Responsable de magasin', secteur: null }, { id: 8, nom: 'Livraisons B2B', secteur: null },
];
// L'équipe : dix personnes, chacune deux à quatre jours par semaine (petit magasin : 1 600 à 2 900 € par jour).
const EQUIPE = [
  { id: 201, nom: 'Camille Roussel', fonctions: ['Vendeuse'], postes: [5, 6], taux: 15.8 },
  { id: 202, nom: 'Antoine Morel', fonctions: ['Boulanger'], postes: [1, 3], taux: 17.4 },
  { id: 203, nom: 'Chloé Vasseur', fonctions: ['Pâtissière'], postes: [3, 1], taux: 17.1 },
  { id: 204, nom: 'David Lemaire', fonctions: ['Préparateur snacking'], postes: [4, 5], taux: 15.3 },
  { id: 205, nom: 'Emma Garnier', fonctions: ['Vendeuse'], postes: [5], taux: 14.9 },
  { id: 206, nom: 'Fanny Leroy', fonctions: ['Responsable de magasin'], postes: [5, 6, 7], taux: 17.2 },
  { id: 207, nom: 'Hugo Marchand', fonctions: ['Boulanger-tourier'], postes: [1, 2], taux: 16.8 },
  { id: 208, nom: 'Inès Fontaine', fonctions: ['Vendeuse étudiante'], postes: [5], taux: 12.4 },
  { id: 209, nom: 'Julien Perrin', fonctions: ['Pâtissier'], postes: [3, 4], taux: 16.2 },
  { id: 210, nom: 'Lucie Bernard', fonctions: ['Vendeuse'], postes: [5, 4], taux: 14.6 },
];
EQUIPE.sort((a, b) => a.nom.localeCompare(b.nom, 'fr'));   // comme le serveur (strnatcasecmp sur le nom)
// La semaine type (celle du 16 au 22 novembre) : jour de semaine (1 = lundi … 7 = dimanche) → [employé, début, fin].
// Chaque jour : la boulangerie, la pâtisserie, le snacking et le comptoir de 7 h à 19 h tenus (dimanche 7 h 30 – 13 h 30),
// sauf le trou du mardi au comptoir (13 h 45 – 14 h 30). Les heures sont calées sur le CA de l'Opérationnel.
const SEMAINE = {
  1: [[202, '05:00', '11:00'], [210, '06:45', '12:45'], [208, '12:45', '19:15']],
  2: [[207, '04:30', '09:30'], [209, '06:00', '11:00'], [205, '06:45', '13:45'], [201, '14:30', '19:15']],
  3: [[203, '05:30', '11:00'], [204, '06:45', '13:00'], [201, '12:45', '19:15']],
  4: [[207, '04:45', '09:30'], [209, '06:00', '10:30'], [206, '06:45', '13:00'], [205, '13:00', '19:15']],
  5: [[202, '05:00', '11:00'], [209, '06:00', '11:00'], [206, '06:45', '13:45'], [201, '13:00', '19:15'], [208, '14:45', '19:15']],
  6: [[207, '04:30', '10:30'], [203, '06:00', '11:00'], [210, '06:45', '13:00'], [208, '09:00', '13:00'], [205, '12:15', '19:15']],
  7: [[202, '05:00', '11:45'], [204, '07:00', '13:45'], [201, '07:15', '13:45'], [205, '07:15', '13:45']],
};
// Le CA de la semaine du 16 (l'Opérationnel : comptoir + B2B, TTC) ; jeudi 19 : projection et objectif.
const CA16 = { '2026-11-16': 1660, '2026-11-17': 1854, '2026-11-18': 1745, '2026-11-19': 2118, '2026-11-20': 2200, '2026-11-21': 2800, '2026-11-22': 2500 };
const OBJ_AUJ = 1910, CA_EN_COURS = 1673;
// Les autres semaines : la part du personnel visée par jour de semaine (le CA en découle, entre 1 600 et 2 900 €).
const PCT = { 1: 16.3, 2: 17.4, 3: 16.9, 4: 16.6, 5: 17.6, 6: 15.4, 7: 16.8 };
const CA_MOYEN = [0, 1660, 1850, 1750, 1950, 2200, 2800, 2500];
// La semaine du 30 novembre (vide, celle des vidéos de l'assistant, de la recopie et du shift maker) : un début de
// décembre plus calme (CA prévu ×0,92) — la semaine passée recopiée y a des heures de trop, l'assistant des propositions.
const CALME_APRES = 0.92;
const OUVERTURES = { 1: [420, 1140], 2: [420, 1140], 3: [420, 1140], 4: [420, 1140], 5: [420, 1140], 6: [420, 1140], 7: [450, 810] };
const PREMIER = '2026-11-01', DERNIER = '2026-11-29';   // le planning existe du 1er au 29 novembre

/* ── dates ── */
const dU = s => new Date(s + 'T12:00:00Z');
const ymd = d => d.toISOString().slice(0, 10);
const plus = (s, n) => { const d = dU(s); d.setUTCDate(d.getUTCDate() + n); return ymd(d); };
const wd = s => { const g = dU(s).getUTCDay(); return g === 0 ? 7 : g; };
const lundiDe = s => plus(s, 1 - wd(s));
const mn = t => { const m = /^(\d{1,2}):(\d{2})/.exec(String(t || '')); return m ? 60 * +m[1] + +m[2] : null; };
const hh = x => String(Math.floor(x / 60)).padStart(2, '0') + ':' + String(x % 60).padStart(2, '0');
// Un bruit déterministe dans [-1, 1] pour une date.
const bruit = (s, k = 0) => { let h = 2166136261 ^ k; for (const c of s) { h = Math.imul(h ^ c.charCodeAt(0), 16777619); } return ((h >>> 0) % 2001) / 1000 - 1; };

/* ── l'état ── */
const S = { fiches: {}, evals: {}, services: {}, ca: {}, absences: [], prochainAbs: 1, options: { assistant: false, taches: false, matrice: false, recopie: false }, prochainId: 90001,
  postes: Object.fromEntries(EQUIPE.map(e => [e.id, e.postes.slice()])), matrice: null,
  roulements: { 201: 'B', 202: 'A', 203: 'A', 204: 'D', 205: 'C', 206: 'D', 207: 'C', 208: 'B', 209: 'C', 210: 'A' } };
const COMPTOIR_IDS = new Set([201, 204, 205, 206, 208, 210]);
const semaineImpaire = s => Math.round((dU(lundiDe(s)) - dU('2026-11-16')) / 864e5 / 7) % 2 !== 0;
function genere(date) {
  if (date < PREMIER || date > DERNIER) { return []; }
  const L = [];
  SEMAINE[wd(date)].forEach(([emp, d0, a0], k) => {
    // Une semaine sur deux, Camille et Emma échangent leurs services (le mois ne se répète pas à l'identique).
    const e = semaineImpaire(date) && (emp === 201 || emp === 205) ? (emp === 201 ? 205 : 201) : emp;
    let de = mn(d0), a = mn(a0);
    // Hors de la semaine du 16 (celle des vidéos), la production bouge d'une demi-heure (le comptoir reste tenu).
    if (lundiDe(date) !== '2026-11-16' && !COMPTOIR_IDS.has(emp)) {
      const n = bruit(date, emp);
      if (n > 0.6 && a - de >= 4.5 * 60) { a -= 30; } else if (n < -0.6) { a += 30; }
    }
    L.push({ id: 50000 + Math.round((dU(date) - dU('2026-01-01')) / 864e5) * 20 + k, employe: e, de: hh(de), a: hh(a) });
  });
  return L.sort((x, y) => x.de.localeCompare(y.de));
}
function servicesDu(date) { if (!S.services[date]) { S.services[date] = genere(date); } return S.services[date]; }
const tauxDe = id => (EQUIPE.find(e => e.id === id) || {}).taux || 0;
function coutDu(date) { return genere(date).reduce((a, s) => a + (mn(s.a) - mn(s.de)) / 60 * tauxDe(s.employe), 0); }
/** Le CA d'un jour, figé à la première lecture (les écritures ne le changent pas). */
function caDu(date) {
  if (S.ca[date]) { return S.ca[date]; }
  const w = wd(date), cout = coutDu(date), r10 = x => Math.round(x / 10) * 10;
  let base = CA16[date];
  if (base == null) {
    base = cout > 0 ? cout / (PCT[w] + bruit(date, 3) * 0.9) * 100 : CA_MOYEN[w] * (1 + bruit(date, 5) * 0.05) * (date > DERNIER ? CALME_APRES : 1);
    base = r10(Math.max(date > DERNIER ? 1500 : 1600, Math.min(2900, base)));
  }
  let o;
  if (date < AUJ) { o = { objectif: r10(base * (1 + bruit(date, 7) * 0.05)), ca: base, caSource: 'reel', caEnCours: null }; }
  else if (date === AUJ) { o = { objectif: OBJ_AUJ, ca: base, caSource: 'projection', caEnCours: CA_EN_COURS }; }
  else { o = { objectif: base, ca: null, caSource: null, caEnCours: null }; }
  S.ca[date] = o; return o;
}

/* ── l'équipe aux deux formes ── */
const posteDe = id => POSTES.find(p => p.id === id);
function personnePlanning(e) {
  const postes = S.postes[e.id].map(id => { const p = posteDe(id); return { id: p.id, nom: p.nom, secteur: p.secteur }; });
  return { id: e.id, nom: e.nom, fonctions: e.fonctions, postes, actif: true, taux: e.taux, franchise: false, contrat: contratDe(e.id),
    secteurs: [...new Set(postes.map(p => p.secteur).filter(Boolean))], lettre: S.options.matrice ? (S.roulements[e.id] || null) : null };
}
function carte() {
  let pid = 1000;
  const secteurs = SECTEURS.map(s => ({ id: s.id, nom: s.nom, produits: PRODUITS[s.id].map(n => ({ id: ++pid, nom: n })).sort((a, b) => a.nom.localeCompare(b.nom, 'fr')), employes: [] }));
  const sansSecteur = PRODUITS[0].map(n => ({ id: ++pid, nom: n }));
  const occupes = new Set(), postesSans = new Map();
  const employes = EQUIPE.map(e => {
    const x = personnePlanning(e);
    const r = { id: x.id, nom: x.nom, fonctions: x.fonctions, postes: x.postes, taux: x.taux, franchise: false, secteurs: [], autres: [], produits: 0, lettre: x.lettre };
    x.postes.forEach(p => { occupes.add(p.id); if (p.secteur == null) { r.autres.push(p.nom); postesSans.set(p.id, p); return; } if (!r.secteurs.includes(p.secteur)) { r.secteurs.push(p.secteur); } const s = secteurs.find(y => y.id === p.secteur); if (!s.employes.includes(x.id)) { s.employes.push(x.id); } });
    r.produits = r.secteurs.reduce((a, id) => a + secteurs.find(y => y.id === id).produits.length, 0);
    return r;
  });
  const vides = secteurs.filter(s => !s.employes.length).map(s => s.id);
  const nProd = secteurs.reduce((a, s) => a + s.produits.length, 0) + sansSecteur.length;
  const couverts = secteurs.filter(s => s.employes.length).reduce((a, s) => a + s.produits.length, 0);
  return { shop: 4, employes, postes: POSTES.map(p => ({ id: p.id, nom: p.nom, secteur: p.secteur })), secteurs, produitsSansSecteur: sansSecteur,
    postesSansSecteur: [...postesSans.values()], secteursVides: vides,
    resume: { employes: employes.length, postesOccupes: occupes.size, postes: POSTES.length, produits: nProd, couverts, nonCouverts: nProd - couverts,
      secteursVides: vides.length, sansPoste: employes.filter(e => !e.postes.length).length } };
}

/* ── les temps morts d'un jour ── */
const CREUX = { 1: [[870, 930]], 2: [[855, 915], [1050, 1080]], 3: [[870, 915]], 4: [[855, 915], [1035, 1065]], 5: [[885, 930]], 6: [[900, 930]], 7: [] };
const TICKETS = { 7: 22, 8: 41, 9: 38, 10: 30, 11: 34, 12: 47, 13: 31, 14: 15, 15: 14, 16: 22, 17: 28, 18: 19 };
function tempsMorts(date) {
  const w = wd(date), dates = []; let d = date;
  while (dates.length < 4) { d = plus(d, -7); if (d < AUJ) { dates.push(d); } }
  const f = { 1: 0.85, 2: 0.82, 3: 0.95, 4: 0.9, 5: 1.05, 6: 1.35, 7: 1.2 }[w];
  const th = {}; Object.entries(TICKETS).forEach(([h, n]) => { if (w === 7 && (+h < 7 || +h > 13)) { return; } th['h' + h] = Math.round(n * f * (1 + bruit(date, +h) * 0.08) * 10) / 10; });
  const o = { seuil: 10, habitude: { dates, jours: 4, creux: CREUX[w], ticketsHeure: th } };
  if (date === AUJ) { o.jour = { etat: 'encours', tickets: 214, mort: 53, trous: [[852, 893], [930, 942]] }; }
  else if (date < AUJ) { o.jour = { etat: 'lu', tickets: 318 + Math.round(bruit(date) * 30), mort: 71, trous: CREUX[w].map(([a, b]) => [a + 5, b - 10]).concat([[1060, 1078]]) }; }
  return o;
}

/* ── le planning ── */
function planning(du, n) {
  const jours = []; for (let i = 0; i < n; i++) { jours.push(plus(du, i)); }
  const out = jours.map(j => { const c = caDu(j); return { date: j, objectif: c.objectif, ca: c.ca, caSource: c.caSource, caEnCours: c.caEnCours, lu: true, services: servicesDu(j).map(s => Object.assign({}, s)),
    absences: S.absences.filter(a => a.date === j).map(a => Object.assign({}, a)) }; });
  const r = { shop: 4, du: jours[0], au: jours[n - 1], aujourdhui: AUJ, maintenant: MAINTENANT, jours: out,
    employes: EQUIPE.map(personnePlanning), secteurs: SECTEURS.filter(s => s.id !== 4 || true).map(s => ({ id: s.id, nom: s.nom })),
    objectif: { source: 'budget', motif: null }, budget: { cible: 17, rouge: 20, noir: 22, source: 'réglage' }, etp: { heuresMois: 168, caCible: 13000, caCritique: 10000 },
    durees: { min: 4, max: 8, source: 'départ' }, options: Object.assign({}, S.options),
    taches: S.options.taches ? { liste: TACHES, fixes: FIXES, places: placesEntre(jours[0], jours[n - 1]) } : null, nonLus: [],
    ouvertures: Object.fromEntries(Object.entries(OUVERTURES).map(([k, v]) => [k, v])) };
  if (n === 1) { r.tempsMorts = tempsMorts(jours[0]); }
  return r;
}

/* ── un PDF d'une page (le planning imprimé) ── */
function pdf(du, jours, pres) {
  const lignes = [`Planning - Magasin demo - semaine du ${du}`, `${jours} jours - ${pres === 'departements' ? 'une page par section' : 'tout le magasin, groupe par section'}`, ''];
  for (let i = 0; i < Math.min(7, jours); i++) {
    const d = plus(du, i), L = servicesDu(d);
    lignes.push(d + ' : ' + L.map(s => (EQUIPE.find(e => e.id === s.employe) || {}).nom.split(' ')[0].normalize('NFD').replace(/[̀-ͯ]/g, '') + ' ' + s.de + '-' + s.a).join(', ').slice(0, 150));
  }
  const txt = lignes.map((l, i) => `BT /F1 ${i ? 9 : 14} Tf 40 ${560 - i * 18} Td (${l.replace(/[()\\]/g, '')}) Tj ET`).join('\n');
  const obj = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${txt.length} >>\nstream\n${txt}\nendstream`, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
  let s = '%PDF-1.4\n'; const off = [];
  obj.forEach((o, i) => { off.push(s.length); s += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = s.length;
  s += `xref\n0 ${obj.length + 1}\n0000000000 65535 f \n` + off.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('') + `trailer\n<< /Size ${obj.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  return s;
}

/* ── les écritures ── */
const err = (code, msg, plus2) => ({ __status: code, json: Object.assign({ error: msg }, plus2 || {}) });
function chevauche(date, emp, de, a, sauf) { return servicesDu(date).find(s => s.employe === emp && s.id !== sauf && de < mn(s.a) && a > mn(s.de)) || null; }
function creerService(emp, date, de, a) {
  const L = servicesDu(date), id = S.prochainId++;
  L.push({ id, employe: emp, de, a }); L.sort((x, y) => x.de.localeCompare(y.de)); return id;
}
function recopie(du) {
  const prop = [], ecartes = []; let n = 0;
  for (let i = 0; i < 7; i++) {
    const t = plus(du, i), src = plus(t, -7);
    servicesDu(src).forEach(s => {
      n++;
      const nom = (EQUIPE.find(e => e.id === s.employe) || {}).nom || 'Employé ' + s.employe;
      const x = { source: s.id, employe: s.employe, date: t, de: s.de, a: s.a, nom };
      const deja = servicesDu(t).find(y => y.employe === s.employe && mn(y.de) < mn(s.a) && mn(y.a) > mn(s.de));
      if (deja) { ecartes.push(Object.assign(x, { motif: deja.de === s.de && deja.a === s.a ? 'déjà au planning' : 'chevauche ' + deja.de + '–' + deja.a })); } else { prop.push(x); }
    });
  }
  const deja = Array.from({ length: 7 }, (_, i) => servicesDu(plus(du, i)).length).reduce((a, b) => a + b, 0);
  return { shop: 4, du, source: { du: plus(du, -7), au: plus(du, -1), services: n }, dejaPrevus: deja, propositions: prop, ecartes };
}
function matriceDepart() {
  const c = x => x.split('');
  return { creneaux: { M: { de: '06:45', a: '13:45' }, P: { de: '12:30', a: '19:15' } }, reference: '2026-11-16',
    lettres: { A: [c('RRMMMMM'), c('PPPPPRR')], B: [c('PPPPPRR'), c('RRMMMMM')], C: [c('RRPPPPP'), c('MMMMMRR')], D: [c('MMMMMRR'), c('RRPPPPP')] } };
}
/** Le shift maker : la semaine tirée de la matrice et des lettres (eqpShiftMaker). */
function generer(du) {
  const M2 = S.matrice || matriceDepart(), cycle = ((Math.round((dU(du) - dU(M2.reference)) / 864e5 / 7) % 2) + 2) % 2;
  const prop = [], ecartes = [];
  Object.keys(S.roulements).map(Number).sort((a, b) => a - b).forEach(emp => {
    const l = S.roulements[emp], e = EQUIPE.find(x => x.id === emp); if (!M2.lettres[l] || !e) { return; }
    M2.lettres[l][cycle].forEach((code, i) => {
      if (code === 'R') { return; }
      const cr = M2.creneaux[code], date = plus(du, i);
      const x = { cle: 'e' + emp + '-' + date + '-' + cr.de, employe: emp, lettre: l, creneau: code, date, de: cr.de, a: cr.a, nom: e.nom };
      const d = servicesDu(date).find(y => y.employe === emp && mn(y.de) < mn(cr.a) && mn(y.a) > mn(cr.de));
      if (d) { ecartes.push(Object.assign(x, { motif: d.de === cr.de && d.a === cr.a ? 'déjà au planning' : 'chevauche ' + d.de + '–' + d.a })); } else { prop.push(x); }
    });
  });
  prop.sort((x, y) => (x.date + x.de).localeCompare(y.date + y.de) || x.employe - y.employe);
  const deja = Array.from({ length: 7 }, (_, i) => servicesDu(plus(du, i)).length).reduce((a, b) => a + b, 0);
  return { shop: 4, du, cycle: cycle + 1, matrice: M2, dejaPrevus: deja, propositions: prop, ecartes,
    sansLettre: EQUIPE.filter(e => !S.roulements[e.id]).map(e => ({ id: e.id, nom: e.nom })) };
}
/* Les tâches dans les temps morts (option) : la liste par défaut (EQP_TACHES_DEFAUT), les fixes, celles posées. */
const TACHES = [['1.2.01', 'Facing et FIFO en service', '12:00', 15, [1, 2, 3, 4, 5, 6, 7]], ['1.2.02', 'Moments calmes', null, 10, [1, 2, 3, 4, 5, 6, 7]],
  ['4.2.01', 'Hygiène quotidienne', '14:00', 20, [1, 2, 3, 4, 5, 6, 7]], ['1.5.01', 'Planogramme et merchandising (lundi)', '10:00', 40, [1]],
  ['1.5.02', 'Audit visuel de la boutique (mardi)', '10:00', 20, [2]], ['4.5.01', 'Nettoyage approfondi (mercredi)', '15:00', 45, [3]],
  ['1.3.02', 'Photos des comptoirs (après-midi)', '14:30', 10, [6, 7]]].map(([code, nom, heure, duree, jours]) => ({ code, nom, zone: +code[0], heure, duree, jours }));
const FIXES = [['1.1.01', 'Ouverture de la boutique', '06:30'], ['1.1.02', 'Photos des comptoirs', '07:00'], ['1.3.01', 'Après-midi : recuisson et réassort', '14:00'], ['1.4.01', 'Fermeture de la boutique', '18:00']].map(([code, nom, heure]) => ({ code, nom, heure }));
S.places = [];
const placesEntre = (du, au) => S.places.filter(x => x.date >= du && x.date <= au).sort((a, b) => (a.date + String(a.de).padStart(4, '0')).localeCompare(b.date + String(b.de).padStart(4, '0')));
const OPTIONS_LISTE = [
  { cle: 'assistant', nom: 'Assistant de la semaine', dit: 'Reprendre la semaine passée ou la matrice, qui adapter, les heures de trop et des propositions, puis créer la semaine au panel.' },
  { cle: 'taches', nom: 'Tâches dans les temps morts', dit: 'Les checklists sans heure fixe posées dans les creux habituels du comptoir, à quelqu’un du comptoir présent : une étape de l’assistant et une ligne au jour.' },
  { cle: 'matrice', nom: 'Matrice A B C D et shift maker', dit: 'Quatre roulements sur deux semaines, la lettre de chacun dans sa fiche, les créneaux matin et après-midi, et le shift maker (test).' },
  { cle: 'recopie', nom: 'Recopier la semaine précédente', dit: 'Dans « Préparer la semaine » : la semaine d’avant reportée de sept jours, aperçu puis création.' },
];

/* Les absences (27/11/2026, panel/src/equipe_absences.php) : payée, le service reste au planning ; non payée, il en
 * sort ; le remplaçant est un service, ou un extérieur noté sur l'absence. Le même plan que le serveur, en plus court. */
const MOTIFS_PAYES = { maladie: true, cp: true, circ: true, nonpaye: false };
const SANS_CONTRAT = ['civil_law_contract', 'b2b', 'temporary_work', 'internship', 'volunteer'];
function contratDe(id) { return (S.fiches[id] || {}).contrat || (id === 208 ? 'internship' : 'employment_contract'); }
function absenceRoute(m, c) {
  if (m === 'DELETE') {
    const L = S.absences.filter(a => (c.ids || []).includes(a.id)); if (!L.length) { return err(404, 'aucune de ces absences n’est de ce magasin'); }
    L.forEach(a => {
      if (!a.payee) { creerService(a.employe, a.date, a.de, a.a); }
      if (c.remplacants && a.remplacantService) { const D = servicesDu(a.date), k = D.findIndex(s => s.id === a.remplacantService); if (k >= 0) { D.splice(k, 1); } }
      S.absences.splice(S.absences.indexOf(a), 1);
    });
    return { ok: true, annulees: L.length, echecs: [] };
  }
  if (m !== 'POST') { return undefined; }
  if (!(c.motif in MOTIFS_PAYES)) { return err(422, 'motif inconnu'); }
  const payee = MOTIFS_PAYES[c.motif] && !SANS_CONTRAT.includes(contratDe(c.employe)), remps = {};
  (c.remplacements || []).forEach(r => { remps[r.date] = r; });
  let n = 0, nr = 0;
  for (let d = c.du; d <= c.au; d = plus(d, 1)) {
    const D = servicesDu(d);
    D.filter(s => s.employe === c.employe).forEach(s => {
      let a = S.absences.find(x => x.service === s.id);
      if (!a) { a = { id: S.prochainAbs++, employe: c.employe, date: d, service: s.id, de: s.de, a: s.a, remplacant: null, remplacantService: null, externe: null }; S.absences.push(a); }
      Object.assign(a, { motif: c.motif, payee, certificat: !!c.certificat, du: c.du, au: c.au }); n++;
      if (!payee) { D.splice(D.indexOf(s), 1); }
      const r = remps[d];
      if (r && r.externe) { a.externe = { nom: r.externe.nom, taux: +r.externe.taux }; nr++; }
      else if (r && r.employe) { a.remplacant = r.employe; a.remplacantService = creerService(r.employe, d, s.de, s.a); nr++; }
    });
  }
  if (!n) { return err(422, 'aucun service de cette personne au planning sur ces jours : rien à marquer'); }
  return { ok: true, absences: n, payee, remplacements: nr, echecs: [], arret: null };
}

/* La fiche d'une personne (24/11/2026) : des données fictives, stables d'un tournage à l'autre. */
const CONTRATS = { employment_contract: 'Contrat de travail', civil_law_contract: 'Contrat civil', b2b: 'B2B', temporary_work: 'Intérim', internship: 'Stage', volunteer: 'Bénévole', other: 'Autre' };
const ROLES = { employee: 'Employé·e', franchisee: 'Franchisé·e (hors primes)', franchise_partner: 'Associé·e' };
const COMPETENCES = { 201: ['Encaissement', 'Conseil client', 'Mise en vitrine', 'Hygiène (HACCP)'], 206: ['Encaissement', 'Ouverture du magasin', 'Former une nouvelle', 'Commandes'], 202: ['Four', 'Pâte feuilletée', 'Hygiène (HACCP)'] };
function personneFiche(id) {
  const e = EQUIPE.find(x => x.id === id); if (!e) { return null; }
  const o = S.fiches[id] || {}, [pr, ...rest] = e.nom.split(' ');
  const prenom = o.prenom != null ? o.prenom : pr, nomF = o.nomFamille != null ? o.nomFamille : rest.join(' ');
  return { id, shop: 4, nom: (prenom + ' ' + nomF).trim(), fonctions: e.fonctions, postes: (S.postes[id] || e.postes).map(pid => ({ id: pid, nom: (posteDe(pid) || {}).nom || '' })),
    actif: true, taux: o.taux != null ? o.taux : e.taux, franchise: false, prenom, nomFamille: nomF, affiche: o.affiche != null ? o.affiche : pr + ' ' + (rest[0] || '').slice(0, 1) + '.',
    langue: o.langue || 'fr', contrat: o.contrat || contratDe(id), role: o.role || 'employee', telephone: '+32 ·· ·· ' + String(id).slice(-2),
    pin: true, appPrete: id !== 209, roles: [], competences: COMPETENCES[id] || ['Hygiène (HACCP)'], positions: [{ nom: e.fonctions[0], niveau: id === 206 ? 'Niveau 4' : 'Niveau 2' }] };
}
// Le mot suit la note (5 : un compliment, 4 : un détail à reprendre, 3 : ce qui n'allait pas) ; parfois aucun mot.
const MOTS = {
  5: ['Très bonne vente des tartes, comptoir rangé.', 'Bon accueil, vitrine tenue jusqu’à la fermeture.', '', 'Rush de midi bien tenu, caisse juste.'],
  4: ['Un peu lente au rush de midi.', 'Bonne journée ; la vitrine se vidait vers 17 h.', '', 'Accueil souriant, étiquettes à revoir.'],
  3: ['Ouverture en retard de 10 min.', 'Comptoir pas réassorti après le rush.', 'Caisse fausse de 4 € à la fermeture.'],
};
function evaluations(id) {
  if (!S.evals[id]) {
    S.evals[id] = [];
    for (let d = '2026-11-02'; d < plus(AUJ, -3); d = plus(d, 1)) {
      servicesDu(d).filter(s => s.employe === id).forEach((s, k) => { const b = bruit(d + id, k); if (b > -0.55) { S.evals[id].push({ id: 5000 + S.evals[id].length, service: s.id, date: d, de: s.de, a: s.a, note: b > 0.35 ? 5 : b > -0.2 ? 4 : 3, mot: (L => L[Math.abs(Math.round(b * 10)) % L.length])(MOTS[b > 0.35 ? 5 : b > -0.2 ? 4 : 3]), par: id === 206 ? null : 'Fanny Leroy' }); } });
    }
  }
  const L = S.evals[id].slice().sort((x, y) => (y.date + y.de).localeCompare(x.date + x.de)), notes = new Set(L.map(x => x.service)), aNoter = [];
  for (let i = 0; i < 14; i++) { const d = plus(AUJ, -i); servicesDu(d).filter(s => s.employe === id && !notes.has(s.id) && (d < AUJ || s.a <= MAINTENANT)).forEach(s => aNoter.push({ service: s.id, date: d, de: s.de, a: s.a })); }
  const n = L.filter(x => x.note != null);
  return { evaluations: L, lues: true, aNoter: aNoter.sort((x, y) => (y.date + y.de).localeCompare(x.date + x.de)), joursLus: 14, moyenne: n.length ? Math.round(100 * n.reduce((a, x) => a + x.note, 0) / n.length) / 100 : null, echelle: 5 };
}
function ventes(id) {
  const lundi = lundiDe(AUJ), semaines = [], k = (EQUIPE.findIndex(e => e.id === id) + 1) || 1, vend = COMPTOIR_IDS.has(id);
  let ca = 0, t = 0, l = 0;
  for (let i = 12; i >= 1; i--) {
    const du = plus(lundi, -7 * i), base = vend ? 1650 + 45 * (12 - i) : 260, w = Math.round(base * (1 + 0.06 * bruit(du, k))), tk = Math.round(w / ((vend ? 13.7 : 11.5) + 0.09 * (12 - i) + 0.3 * bruit(du, k + 7))), pcs = Math.round(100 * ((vend ? 2.06 : 1.7) + 0.022 * (12 - i) + 0.04 * bruit(du, k + 3))) / 100;
    semaines.push({ du, ca: w, tickets: tk, panier: Math.round(100 * w / tk) / 100, pieces: pcs, jours: 7, joursLignes: 7 }); ca += w; t += tk; l += pcs * tk;
  }
  return { personne: (EQUIPE.find(e => e.id === id) || {}).nom, semaines, total: { ca, tickets: t, panier: Math.round(100 * ca / t) / 100, pieces: Math.round(100 * l / t) / 100, part: Math.round(1000 * ca / (12 * 14700)) / 10 },
    equipe: { panier: 14.05, pieces: 2.14 }, rang: vend ? { position: id === 201 ? 2 : 3, sur: 6 } : null, couverture: { jours: 84, ventes: 84, lignes: 84 }, cibles: { panier: 13, pieces: 3.6 } };
}
function fiche(m, p, id, c) {
  const P = personneFiche(id);
  if (!P) { return err(404, 'cette personne n’est pas de l’équipe du magasin', { motif: 'employe_inconnu' }); }
  const choix = { contrats: CONTRATS, roles: ROLES, langues: { fr: 'Français', nl: 'Néerlandais', en: 'Anglais', pl: 'Polonais' } };
  if (p === '/dashboard/equipe/personne' && m === 'GET') { return { personne: P, detailLu: true, choix }; }
  if (p === '/dashboard/equipe/personne' && m === 'PATCH') { S.fiches[id] = Object.assign(S.fiches[id] || {}, c.champs || {}); const e = EQUIPE.find(x => x.id === id); if (c.champs && c.champs.taux != null) { e.taux = c.champs.taux; } return { ok: true, personne: personneFiche(id), detailLu: true, choix }; }
  if (p === '/dashboard/equipe/personne/evaluations' && m === 'GET') { return evaluations(id); }
  if (p === '/dashboard/equipe/personne/evaluation' && m === 'POST') { evaluations(id); const s = servicesDu(c.date).find(x => x.id === c.service); S.evals[id].push({ id: 6000 + S.evals[id].length, service: c.service, date: c.date, de: s ? s.de : '', a: s ? s.a : '', note: c.note, mot: c.mot || '', par: null }); return Object.assign({ ok: true }, evaluations(id)); }
  if (p === '/dashboard/equipe/personne/ventes' && m === 'GET') { return ventes(id); }
  return undefined;
}

module.exports = function (m, p, q, c) {
  if (!p.startsWith('/dashboard/equipe')) { return undefined; }
  c = (c && typeof c === 'object') ? c : {};
  if (p === '/dashboard/equipe/absence') { return absenceRoute(m, c); }
  if (p === '/dashboard/equipe/carte' && m === 'GET') { return carte(); }
  // La fiche d'une personne (24/11/2026, panel/src/equipe_fiche.php) : identité, évaluations, ventes des 12 semaines.
  if (p.startsWith('/dashboard/equipe/personne')) { const id = +(m === 'GET' ? q.get('id') : c.id); const r = fiche(m, p, id, c); if (r !== undefined) { return r; } }
  if (p === '/dashboard/equipe/options') {
    if (m === 'POST') { Object.entries(c.options || {}).forEach(([k, v]) => { if (k in S.options) { S.options[k] = !!v; } }); return { ok: true, options: Object.assign({}, S.options) }; }
    return { shop: 4, options: Object.assign({}, S.options), liste: OPTIONS_LISTE.map(o => Object.assign({}, o, { on: S.options[o.cle] })) };
  }
  if (p === '/dashboard/equipe/planning' && m === 'GET') {
    const du = q.get('du') || AUJ, n = Math.max(1, Math.min(31, +(q.get('jours') || 7)));
    return planning(du, n);
  }
  if (p === '/dashboard/equipe/planning/pdf') { return { __body: pdf(q.get('du') || lundiDe(AUJ), +(q.get('jours') || 7), q.get('presentation') || 'magasin'), type: 'application/pdf' }; }
  if (p === '/dashboard/equipe/service') {
    const date = c.date;
    if (m === 'POST') {
      const de = mn(c.de), a = mn(c.a), x = chevauche(date, c.employe, de, a, 0);
      if (x) { return err(422, 'chevauche son service de ' + x.de + ' à ' + x.a, { motif: 'chevauchement' }); }
      return { ok: true, id: creerService(c.employe, date, hh(de), hh(a)), date };
    }
    const L = servicesDu(date), s = L.find(x => x.id === c.id);
    if (!s) { return err(404, 'ce service n’est pas au planning du magasin ce jour-là', { motif: 'service_inconnu' }); }
    if (m === 'PATCH') {
      const de = mn(c.de), a = mn(c.a), x = chevauche(date, s.employe, de, a, s.id);
      if (x) { return err(422, 'chevauche son service de ' + x.de + ' à ' + x.a, { motif: 'chevauchement' }); }
      s.de = hh(de); s.a = hh(a); L.sort((x2, y) => x2.de.localeCompare(y.de)); return { ok: true, id: s.id, date };
    }
    if (m === 'DELETE') { L.splice(L.indexOf(s), 1); return { ok: true, id: null, date }; }
  }
  if (p === '/dashboard/equipe/postes' && m === 'POST') { S.postes[c.employe] = (c.postes || []).slice(); return carte(); }
  if (p === '/dashboard/equipe/semaine/retirer' && m === 'POST') {
    let n = 0; for (let i = 0; i < 7; i++) { const L = servicesDu(plus(c.du, i)); for (let k = L.length - 1; k >= 0; k--) { if (L[k].employe === c.employe) { L.splice(k, 1); n++; } } }
    return { ok: true, du: c.du, employe: c.employe, retires: n, echecs: [] };
  }
  if (p === '/dashboard/equipe/semaine/recopie') {
    const du = m === 'GET' ? q.get('du') : c.du, R = recopie(du);
    if (m === 'GET') { return R; }
    const voulus = new Set(c.sources || []), a = R.propositions.filter(x => voulus.has(x.source));
    a.forEach(x => creerService(x.employe, x.date, x.de, x.a));
    return { ok: true, du, crees: a.length, demandes: a.length, echecs: [], arret: null };
  }
  if (p === '/dashboard/equipe/semaine/creer' && m === 'POST') {
    const L = c.services || []; L.forEach(x => creerService(x.employe, x.date, x.de, x.a));
    return { ok: true, du: c.du, crees: L.length, demandes: L.length, echecs: [], arret: null };
  }
  if (p === '/dashboard/equipe/matrice') {
    if (m === 'POST') { S.matrice = c.depart ? matriceDepart() : c.matrice; }
    return { shop: 4, matrice: S.matrice || matriceDepart(), depart: !S.matrice, roulements: Object.assign({}, S.roulements), lettres: ['A', 'B', 'C', 'D'] };
  }
  if (p === '/dashboard/equipe/semaine/generer') {
    const du = m === 'GET' ? q.get('du') : c.du, G = generer(du);
    if (m === 'GET') { return G; }
    const voulus = new Set(c.cles || []), a = G.propositions.filter(x => voulus.has(x.cle));
    a.forEach(x => creerService(x.employe, x.date, x.de, x.a));
    return { ok: true, du, crees: a.length, demandes: a.length, echecs: [], arret: null };
  }
  if (p === '/dashboard/equipe/semaine/taches') {
    if (m === 'POST') { const L = c.places || [], au = plus(c.du, 6); S.places = S.places.filter(x => x.date < c.du || x.date > au).concat(L.map(x => ({ date: x.date, code: x.code, de: x.de, a: x.a, employe: x.employe == null ? null : x.employe }))); return { ok: true, du: c.du, places: L.length }; }
    const du = q.get('du'), creux = {}; for (let i = 0; i < 7; i++) { const d = plus(du, i); creux[d] = CREUX[wd(d)]; }
    return { shop: 4, du, creux, joursLus: 7, taches: TACHES, fixes: FIXES, places: placesEntre(du, plus(du, 6)) };
  }
  if (p === '/dashboard/equipe/roulement' && m === 'POST') {
    if (c.lettre) { S.roulements[c.employe] = c.lettre; } else { delete S.roulements[c.employe]; }
    return { ok: true, employe: c.employe, lettre: c.lettre || null, roulements: Object.assign({}, S.roulements) };
  }
  return undefined;
};
