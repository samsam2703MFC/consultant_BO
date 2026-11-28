/* Vendre › Concurrence (vue=concurrence) : le benchmark des prix face aux boulangeries concurrentes, lu par
 * panel/public/dashboard/dashboard.js (rendConcurrence, rendTop10, bmParCp), aux formes de consultant/src/benchmark.php
 * (GET /benchmarks/magasin : relevés, concurrents, familles, parCp ; POST /benchmarks/releve) et de
 * consultant/src/analyse_produits.php (GET /analyse/produits?mois=3 : le top du réseau). « Nous » : les prix TTC du
 * catalogue de démo (lib/demo.js), servis par /exploitation/promos-magasin/produits (mocks/13_offres.js).
 * Neuf concurrents inventés (« Boulangerie du Parc »…), aucun nom d'enseigne réelle ; codes postaux et communes fictifs.
 * Un relevé posé (POST) s'ajoute à l'état : la relecture le montre. Les familles portent leur visibilité web (les
 * contrôles de benchmarkVisibiliteSite, résultats fictifs) et leur carte « À faire » (26/11/2026, lot 3 des vidéos). */
'use strict';
const D = require('./lib/demo');
const { r2 } = D;
const pid = nom => (D.PRODUITS.find(p => p.nom === nom) || {}).pid;

/* ── les concurrents du magasin ── */
const CONC = [
  { id: 301, nom: 'Pâtisserie Les Tilleuls', type: 'Artisan', ville: 'Val-Fleuri', cp: '5000', distance: 0.8 },
  { id: 302, nom: 'Boulangerie du Parc', type: 'Artisan', ville: 'Val-Fleuri', cp: '5000', distance: 1.2 },
  { id: 303, nom: 'Supermarché Le Panier', type: 'GMS', ville: 'Val-Fleuri', cp: '5002', distance: 1.5 },
  { id: 304, nom: 'Fournée Express', type: 'Chaîne', ville: 'Val-Fleuri', cp: '5002', distance: 1.9 },
  { id: 305, nom: 'Station Relais du Nord', type: 'Chaîne', ville: 'Bois-Joli', cp: '5004', distance: 2.2 },
  { id: 306, nom: 'Au Fournil de Léon', type: 'Artisan', ville: 'Bois-Joli', cp: '5004', distance: 2.5 },
  { id: 307, nom: 'La Mie Dorée', type: 'Artisan', ville: 'Les Prés', cp: '5020', distance: 3.1 },
  { id: 308, nom: 'Hyper des Prés', type: 'GMS', ville: 'Mont-Clair', cp: '5100', distance: 3.6 },
  { id: 309, nom: 'Boulangerie Saint-Marc', type: 'Artisan', ville: 'Haut-Vallon', cp: '5030', distance: 4.0 },
];
const conc = id => CONC.find(c => c.id === id);

/* ── les relevés : [concurrent, produit vu, notre produit (ou null : non rattaché), prix, promo, vu le] ── */
const RELEVES0 = [
  // Croissant au beurre : 1,40 € chez nous
  [301, 'Croissant au beurre', 'Croissant au beurre', 1.45], [302, 'Croissant au beurre', 'Croissant au beurre', 1.35], [306, 'Croissant pur beurre', 'Croissant au beurre', 1.30],
  [307, 'Croissant au beurre', 'Croissant au beurre', 1.50], [304, 'Croissant au beurre', 'Croissant au beurre', 1.20], [303, 'Croissant au beurre (sachet de 4)', 'Croissant au beurre', 0.89],
  [308, 'Croissant au beurre', 'Croissant au beurre', 0.95],
  // Pain au chocolat : 1,60 €
  [301, 'Pain au chocolat', 'Pain au chocolat', 1.60], [302, 'Pain au chocolat', 'Pain au chocolat', 1.55], [306, 'Pain au chocolat', 'Pain au chocolat', 1.50],
  [307, 'Pain au chocolat', 'Pain au chocolat', 1.70], [304, 'Pain au chocolat', 'Pain au chocolat', 1.40], [303, 'Pain au chocolat', 'Pain au chocolat', 0.99],
  // Baguette tradition : 1,50 €
  [301, 'Baguette tradition', 'Baguette tradition', 1.70], [302, 'Baguette tradition', 'Baguette tradition', 1.60], [306, 'Baguette à l’ancienne', 'Baguette tradition', 1.65],
  [307, 'Baguette tradition', 'Baguette tradition', 1.80], [305, 'Baguette', 'Baguette tradition', 1.45], [308, 'Baguette', 'Baguette tradition', 0.95],
  // Mini-croissant : 0,80 €
  [302, 'Mini-croissant', 'Mini-croissant', 0.75], [306, 'Mini-croissant', 'Mini-croissant', 0.70], [307, 'Mini-croissant', 'Mini-croissant', 0.80],
  // Sandwich jambon-beurre : 4,90 €
  [302, 'Sandwich jambon-beurre', 'Sandwich jambon-beurre', 4.50], [306, 'Sandwich jambon-beurre', 'Sandwich jambon-beurre', 4.20], [307, 'Sandwich jambon-beurre', 'Sandwich jambon-beurre', 4.80],
  [304, 'Sandwich jambon-beurre', 'Sandwich jambon-beurre', 4.10, 3.90], [303, 'Sandwich jambon-beurre', 'Sandwich jambon-beurre', 2.99],
  [302, 'Sandwich thon-crudités', null, 4.60], [304, 'Sandwich poulet curry', null, 4.30],
  // Cookie chocolat : 2,20 €
  [301, 'Cookie chocolat', 'Cookie chocolat', 2.30], [302, 'Cookie chocolat', 'Cookie chocolat', 2.00], [307, 'Cookie chocolat', 'Cookie chocolat', 2.20], [304, 'Cookie chocolat', 'Cookie chocolat', 1.90],
  // Pain aux raisins : 1,90 €
  [301, 'Pain aux raisins', 'Pain aux raisins', 2.00], [302, 'Pain aux raisins', 'Pain aux raisins', 1.80], [306, 'Pain aux raisins', 'Pain aux raisins', 1.90],
  // Éclair au chocolat : 3,20 €
  [301, 'Éclair au chocolat', 'Éclair au chocolat', 3.50], [302, 'Éclair au chocolat', 'Éclair au chocolat', 3.10], [306, 'Éclair au chocolat', 'Éclair au chocolat', 2.90], [307, 'Éclair au chocolat', 'Éclair au chocolat', 3.30],
  // Chausson aux pommes : 2,10 €
  [302, 'Chausson aux pommes', 'Chausson aux pommes', 2.30], [306, 'Chausson aux pommes', 'Chausson aux pommes', 2.40], [304, 'Chausson aux pommes', 'Chausson aux pommes', 1.90],
];
const VU = ['2026-10-14', '2026-10-28', '2026-11-04', '2026-11-12'];
const S = { releves: [], prochain: 9001 };
RELEVES0.forEach(([cid, produit, nous, prix, promo], i) => {
  const c = conc(cid), p = nous ? pid(nous) : null;
  S.releves.push({ id: 8000 + i, observation: 500 + cid, concurrentId: cid, concurrent: c.nom, type: c.type, cp: c.cp, distance: c.distance, produit,
    monProduit: p || null, monProduitNom: nous, prix, promo: promo || null, unite: 'piece', taille: null, vuLe: VU[(cid + i) % VU.length], notes: '', source: 'api' });
});

/* ── le top 10 du réseau (4 magasins, août → octobre) ── */
const TOP = [['Croissant au beurre', 33900], ['Pain au chocolat', 28700], ['Baguette tradition', 25100], ['Mini-croissant', 10900], ['Sandwich jambon-beurre', 9350],
  ['Cookie chocolat', 8600], ['Pain aux raisins', 7900], ['Éclair au chocolat', 7700], ['Chausson aux pommes', 7150], ['Pain de campagne', 6480]];
function top() {
  const produits = TOP.map(([nom, total]) => { const p = D.PRODUITS.find(x => x.nom === nom); return { pid: p.pid, nom: p.nom, cat: p.cat, total, ca: r2(total * p.prix * 0.985), parShop: {} }; });
  // quelques produits plus loin dans le classement
  ['Baguette céréales', 'Café', 'Sandwich poulet crudités', 'Tartelette aux fruits'].forEach((nom, i) => { const p = D.PRODUITS.find(x => x.nom === nom); produits.push({ pid: p.pid, nom: p.nom, cat: p.cat, total: 6100 - i * 450, ca: r2((6100 - i * 450) * p.prix), parShop: {} }); });
  return { mois: 3, bornes: [['2026-08-01', '2026-08-31'], ['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']], produits };
}

/* ── la visibilité web d'une famille (benchmarkVisibiliteSite : les contrôles de la page de la gamme ; résultats fictifs) ── */
function web(mot, taille, ok) {
  const titre = ok.includes('titre') ? mot.charAt(0).toUpperCase() + mot.slice(1) + 's de saison — la gamme' : 'Noël et Nouvel An — la gamme';
  const L = [['page', 'Page dédiée en ligne', ''], ['titre', '« ' + mot + ' » dans le titre de la page', titre], ['description', '« ' + mot + ' » dans la meta description', '—'],
    ['texte', 'Produit lisible dans le HTML servi', '6 mention(s)'], ['prix', 'Prix affiché sur la page', ''], ['taille', taille, '1 mention(s)'], ['schema', 'Balisage schema.org Product / Offer', ''],
    ['accueil', '« ' + mot + ' » visible sur l’accueil (HTML servi)', ''], ['sitemap', 'Page dans le sitemap', ''], ['robots', 'robots.txt présent', 'HTTP 200']];
  const controles = L.map(([cle, libelle, detail]) => ({ cle, libelle, ok: ok.includes(cle), detail }));
  return { score: Math.round(100 * ok.length / L.length), ok: ok.length, total: L.length, controles, lu_le: '2026-11-19 15:25' };
}

/* ── les deux familles saisonnières (cougnou au kilo, galette ramenée à notre Ø) ── */
const FAMILLES = {
  cougnou: { libelle: 'Cougnou', motif: 'cougnou|cougnolle', mesure: 'poids', tailleRef: 500, tailleConfirmee: true, saison: { du: '11-15', au: '12-31' }, releve: '2026-11-12',
    nous: [{ id: 990, nom: 'Cougnou sucre 500 g', prix: 8.90, taille: 500, reference: true }], constats: ['Relever les cougnous de La Mie Dorée et de la Boulangerie Saint-Marc avant le 1er décembre.'],
    web: web('cougnou', 'Poids affiché (g) pour comparer au kilo', ['page', 'titre', 'texte', 'prix', 'sitemap', 'robots']) },
  galette: { libelle: 'Galette des rois', motif: 'galette', mesure: 'diametre', tailleRef: 22, tailleConfirmee: true, saison: { du: '12-26', au: '01-31' }, releve: '2026-01-08',
    nous: [{ id: 991, nom: 'Galette frangipane Ø 22 cm', prix: 19.90, taille: 22, reference: true }], constats: ['Relever les galettes des concurrents de la zone dès le 26 décembre, début de la saison.'],
    web: web('galette', 'Diamètre ou nombre de parts affiché', ['page', 'texte', 'prix', 'taille', 'sitemap', 'robots']) },
};
const REFERENTIEL = [
  ['Boulangerie du Parc', 'Artisan', 1.2, 'Cougnou sucre', 9.40, 'g', 500, 'cougnou', '5000'], ['Pâtisserie Les Tilleuls', 'Artisan', 0.8, 'Cougnou au chocolat', 7.20, 'g', 400, 'cougnou', '5000'],
  ['Supermarché Le Panier', 'GMS', 1.5, 'Cougnou', 4.99, 'g', 450, 'cougnou', '5002'], ['Au Fournil de Léon', 'Artisan', 2.5, 'Cougnou sucre', 10.50, 'g', 600, 'cougnou', '5004'],
  ['Boulangerie du Parc', 'Artisan', 1.2, 'Galette frangipane', 21.00, 'cm', 24, 'galette', '5000'], ['La Mie Dorée', 'Artisan', 3.1, 'Galette des rois', 16.50, 'cm', 20, 'galette', '5020'],
  ['Hyper des Prés', 'GMS', 3.6, 'Galette frangipane', 8.99, 'cm', 22, 'galette', '5100'],
].map(([concurrent, type, distance, produit, prix, unite, taille, famille, cp]) => ({ concurrent, type, distance, produit, cp, monProduit: null, prix, promo: null, unite, taille,
  vuLe: FAMILLES[famille].releve, notes: '', lien: null, famille, source: 'referentiel' }));

/* ── les concurrents par code postal (Benchmark › Classement) ── */
function parCp() {
  const cl = { 301: 'zone', 302: 'zone', 303: 'zone', 304: 'national', 305: 'zone', 306: 'zone', 307: 'zone', 308: 'hors', 309: 'ailleurs' };
  const ordre = ['zone', 'ailleurs', 'hors', 'inconnu', 'national'];
  const L = CONC.map(c => ({ nom: c.nom, type: c.type, source: 'tfbuddy', cp: cl[c.id] === 'national' ? null : c.cp, commune: c.ville, ville: c.ville, classement: cl[c.id],
    aussi: c.id === 309 ? ['Magasin 3'] : (c.id === 307 ? ['Magasin 2'] : []), national: cl[c.id] === 'national' }))
    .sort((a, b) => ordre.indexOf(a.classement) - ordre.indexOf(b.classement) || String(a.cp || '9999').localeCompare(String(b.cp || '9999')) || a.nom.localeCompare(b.nom, 'fr'));
  return { id: 4, nom: D.NOM, franchise: 'Démo SRL', cp: '5000', zone: { n: 6, codes: ['5000–5004', '5020', '5021', '5022'] }, concurrents: L };
}

function magasin() {
  return { shop: 4, lu: '2026-11-19 15:25', releves: S.releves, referentiel: REFERENTIEL,
    concurrents: CONC.map(c => ({ id: c.id, nom: c.nom, type: c.type, ville: c.ville, cp: c.cp, distance: c.distance, actif: true })),
    parCp: parCp(), familles: FAMILLES, kpi: { comparisons_count: S.releves.filter(r => r.monProduit).length }, produits: [], erreur: null };
}
/** POST /benchmarks/releve : un prix vu en magasin, ajouté aux relevés (le concurrent créé s'il est nouveau). */
function releve(b) {
  const prix = parseFloat(String(b.prix || '').replace(',', '.'));
  if (!b.nom || !(prix > 0)) { return { __status: 400, json: { ok: false, error: 'magasin, produit et prix sont requis' } }; }
  let c = b.concurrentId ? conc(+b.concurrentId) : CONC.find(x => x.nom.toLowerCase() === String((b.concurrent || {}).nom || '').toLowerCase());
  if (!c) { c = { id: 400 + CONC.length, nom: String(b.concurrent.nom), type: b.concurrent.type || 'Artisan', ville: '', cp: b.concurrent.cp || '', distance: null }; CONC.push(c); }
  // Notre produit : un produit du catalogue, ou celui d'une famille (le cougnou, la galette de référence).
  const p = D.PRODUITS.find(x => x.pid === +b.produitId)
    || Object.values(FAMILLES).flatMap(f => f.nous).filter(n => n.id === +b.produitId).map(n => ({ pid: n.id, nom: n.nom }))[0];
  const promo = b.promo ? parseFloat(String(b.promo).replace(',', '.')) : null;
  S.releves.push({ id: S.prochain++, observation: 700 + c.id, concurrentId: c.id, concurrent: c.nom, type: c.type, cp: c.cp, distance: c.distance, produit: String(b.nom),
    monProduit: p ? p.pid : null, monProduitNom: p ? p.nom : null, prix, promo: promo > 0 ? promo : null, unite: b.unite || 'piece', taille: b.taille ? +String(b.taille).replace(',', '.') : null,
    vuLe: '2026-11-19', notes: 'terrain', source: 'api' });
  return { ok: true, concurrentId: c.id, observation: 700 + c.id, item: S.prochain - 1 };
}

module.exports = function (m, p, q, c) {
  if (p === '/benchmarks/releve' && m === 'POST') { return releve(c || {}); }
  if (m !== 'GET') { return undefined; }
  if (p === '/benchmarks/magasin') { return magasin(); }
  if (p === '/analyse/produits' && !q.get('pid')) { return top(); }
  return undefined;
};
