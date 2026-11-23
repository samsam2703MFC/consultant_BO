/* Mon marketing (vue=marketing) : les routes que lit panel/public/dashboard/marketing.js, aux formes de
 * panel/src/dashboard_marketing.php (campagnes en cours et leur note, calendrier de l'année, ventes d'une saison),
 * de /exploitation/objectifs-produits (marketing/src/objectifs_produits.php), de /production/saisons et
 * /production/catalogue (shared/docs/contrat-api.md). Tout est fictif : sept campagnes au nom inventé pour le
 * « Magasin démo » en 2026 (deux en cours le jeudi 19/11/2026), neuf saisons de produits, une responsable inventée. */
'use strict';
const D = require('./lib/demo');

const AN = 2026;
const TYPES = { Saison: '#B0792A', Produit: '#3f7a52', Fidélité: '#2F5D8A', Notoriété: '#7A4E8C' };
// [id, nom, type, début, fin, réseau]
const CAMPAGNES = [
  [301, 'Galettes en fête', 'Saison', '2026-01-05', '2026-01-31', true],
  [302, 'Printemps gourmand', 'Produit', '2026-03-16', '2026-04-12', true],
  [303, 'Carte fidélité : double tampon', 'Fidélité', '2026-05-04', '2026-05-24', false],
  [304, 'L’été des tartes', 'Produit', '2026-06-22', '2026-07-31', true],
  [305, 'Rentrée petit-déjeuner', 'Notoriété', '2026-08-31', '2026-09-27', true],
  [306, 'Douceurs d’automne', 'Produit', '2026-10-26', '2026-11-29', false],
  [307, 'Saint-Nicolas gourmand', 'Saison', '2026-11-16', '2026-12-06', true],
  [308, 'Bûches de Noël 2026', 'Saison', '2026-12-01', '2026-12-24', true],
].map(([id, nom, type, debut, fin, reseau]) => ({ id, nom, type, typeCouleur: TYPES[type], debut, fin, reseau,
  phase: fin < D.AUJ ? 'terminee' : debut > D.AUJ ? 'a_venir' : 'en_cours' }));

/* Les produits des saisons (références du panel) : nom, catégorie, prix TTC, identifiant panel. */
const REFS = [
  ['4100101', 'Galette frangipane (6 parts)', 'Pâtisseries', 18.90], ['4100102', 'Galette pomme-frangipane (6 parts)', 'Pâtisseries', 19.90],
  ['4100111', 'Crêpes (sachet de 6)', 'Biscuiterie', 4.90], ['4100121', 'Cœur framboise (2 parts)', 'Pâtisseries', 8.90],
  ['4100131', 'Œuf en chocolat', 'Confiserie', 6.50], ['4100132', 'Nid de Pâques (brioche)', 'Viennoiseries', 3.90], ['4100133', 'Agneau en biscuit', 'Pâtisseries', 7.90],
  ['4100141', 'Tarte rhubarbe (6 parts)', 'Pâtisseries', 16.90], ['4100142', 'Fraisier (6 parts)', 'Pâtisseries', 26.00],
  ['4100151', 'Cœur fraisier (2 parts)', 'Pâtisseries', 9.50],
  ['4100161', 'Tarte aux abricots (part)', 'Pâtisseries', 3.60], ['4100162', 'Tarte aux fraises (part)', 'Pâtisseries', 3.90], ['4100163', 'Limonade maison', 'Boissons', 3.20],
  ['4100171', 'Tarte aux pommes caramel (6 parts)', 'Pâtisseries', 17.90], ['4100172', 'Chausson poire-chocolat', 'Viennoiseries', 2.40], ['4100173', 'Pain aux noix', 'Pains', 4.20],
  ['4100181', 'Spéculoos maison (sachet 250 g)', 'Biscuiterie', 5.90], ['4100182', 'Saint-Nicolas en chocolat', 'Confiserie', 7.90], ['4100183', 'Massepain (figurine)', 'Confiserie', 4.50],
  ['4100184', 'Pain d’épices', 'Biscuiterie', 6.50], ['4100185', 'Bonhomme en brioche', 'Viennoiseries', 2.90],
  ['4100191', 'Bûche chocolat-praliné (6 parts)', 'Pâtisseries', 28.90], ['4100192', 'Bûche framboise-vanille (6 parts)', 'Pâtisseries', 27.90], ['4100193', 'Cougnou au sucre', 'Viennoiseries', 6.50],
  ['4100194', 'Truffes au chocolat (boîte de 12)', 'Confiserie', 12.90],
].map(([ref, nom, categorie, prix], i) => ({ ref, nom, categorie, prix, pwaId: 7000 + i }));
const pwa = ref => (REFS.find(r => r.ref === ref) || {}).pwaId;
// [id, nom, début MM-JJ, fin MM-JJ, références]
const SAISONS = [
  [11, 'Épiphanie', '01-02', '01-31', ['4100101', '4100102']],
  [12, 'Saint-Valentin', '02-01', '02-14', ['4100121']],
  [13, 'Pâques', '03-16', '04-12', ['4100131', '4100132', '4100133']],
  [14, 'Printanière', '03-20', '06-20', ['4100141', '4100142']],
  [15, 'Fête des mères', '05-01', '05-31', ['4100151']],
  [16, 'Estivale', '06-21', '09-21', ['4100161', '4100162', '4100163']],
  [17, 'Automnale', '09-22', '11-30', ['4100171', '4100172', '4100173']],
  [18, 'Saint-Nicolas', '11-20', '12-06', ['4100181', '4100182', '4100183', '4100184', '4100185']],
  [19, 'Noël & Nouvel An', '12-01', '01-06', ['4100191', '4100192', '4100193', '4100194', '4100184']],
].map(([id, nom, debut, fin, produits]) => ({ id, emoji: '', nom, nomPanel: 'Gamme ' + nom, debut, fin, recurrente: true, produits, lu: true, alerte: null, obligatoires: 0 }));

/* Les deux campagnes en cours, décrites comme la note de campagne du réseau, réduite au magasin. */
const ETOILE = 'M12 2.5l2.9 6.2 6.6.6-5 4.5 1.5 6.6L12 17l-6 3.4 1.5-6.6-5-4.5 6.6-.6z';
const PANIER = 'M3 6h18l-2 11H5L3 6zm4 0l2-3m8 3l-2-3M9 10v4m6-4v4';
function jours(du, au) { return Math.round((Date.parse(au) - Date.parse(du)) / 864e5) + 1; }
function enCours() {
  const c7 = CAMPAGNES.find(c => c.id === 307), c6 = CAMPAGNES.find(c => c.id === 306);
  return [
    { id: 307, nom: c7.nom, type: c7.type, typeCouleur: c7.typeCouleur, statut: 'Validée', du: c7.debut, au: c7.fin, jours: jours(c7.debut, c7.fin), jour: jours(c7.debut, D.AUJ),
      image: null, portee: 'Réseau', nMagasins: 24, levier: 'Panier moyen', iconePath: ETOILE,
      kpi: { nom: 'Panier moyen', unite: '€', decimales: 2, mesure: true, objectifPct: 4 },
      magasin: { source: 'n1', repli: null, reference: 12.42, cible: 12.92, clientsPrevus: 2960, clientsA1: 2885, caAttendu: 38240, caA1: 35830, budget: 37500, budgetSource: 'budget' },
      mot: { texte: 'Le 6 décembre approche : les spéculoos et les Saint-Nicolas en chocolat bien en vue dès l’entrée, et proposés en caisse à chaque client.\nUn sachet offert pour trois achetés, jusqu’au 6 décembre.', nom: 'Élise Marchal', fonction: 'Responsable marketing du réseau' },
      budget: 300,
      etapes: [{ libelle: 'Livraison des affiches et du présentoir', quand: '09/11/2026', fait: true }, { libelle: 'Vitrine de la Saint-Nicolas en place', quand: '14/11/2026', fait: true },
        { libelle: 'Post du réseau sur Facebook et Instagram', quand: '20/11/2026', fait: false }, { libelle: 'Newsletter aux clients fidèles', quand: '27/11/2026', fait: false },
        { libelle: 'Le week-end de la Saint-Nicolas', quand: '05/12/2026', fait: false }],
      canaux: [{ nom: 'Affiche A2 en vitrine', famille: 'Magasin', agence: '', valide: true }, { nom: 'Présentoir de caisse', famille: 'Magasin', agence: '', valide: true },
        { nom: 'Facebook et Instagram', famille: 'Réseaux sociaux', agence: '', valide: true }, { nom: 'Newsletter', famille: 'E-mail', agence: '', valide: true },
        { nom: 'Publicité locale', famille: 'Publicité en ligne', agence: '', valide: false }],
      investis: [{ nom: 'Impression', montant: 180, unite: '€', kpi: null }, { nom: 'Publicité locale', montant: 120, unite: '€', kpi: 'portée' }],
      formats: [{ nom: 'Affiche A2' }, { nom: 'Post carré' }, { nom: 'Story 9:16' }],
      annexes: [{ id: 41, nom: 'Guide de mise en place', type: 'PDF', taille: '1,2 Mo' }, { id: 42, nom: 'Affiche A2 à imprimer', type: 'PDF', taille: '3,4 Mo' }],
      note: null },
    { id: 306, nom: c6.nom, type: c6.type, typeCouleur: c6.typeCouleur, statut: 'Validée', du: c6.debut, au: c6.fin, jours: jours(c6.debut, c6.fin), jour: jours(c6.debut, D.AUJ),
      image: null, portee: 'Magasins désignés', nMagasins: 9, levier: 'Fréquentation', iconePath: PANIER,
      kpi: { nom: 'Clients par jour', unite: 'clients', decimales: 0, mesure: true, objectifPct: 3 },
      magasin: { source: 'n1', repli: null, reference: 148, cible: 152, clientsPrevus: 5320, clientsA1: 5180, caAttendu: null, caA1: 62400, budget: 66000, budgetSource: 'budget' },
      mot: null, budget: 150,
      etapes: [{ libelle: 'Nouvelle tarte aux pommes caramel en vitrine', quand: '26/10/2026', fait: true }, { libelle: 'Dégustation le samedi matin', quand: '07/11/2026', fait: true }],
      canaux: [{ nom: 'Affiche A3 au comptoir', famille: 'Magasin', agence: '', valide: true }, { nom: 'Facebook et Instagram', famille: 'Réseaux sociaux', agence: '', valide: true }],
      investis: [{ nom: 'Dégustation', montant: 150, unite: '€', kpi: 'clients' }], formats: [{ nom: 'Affiche A3' }, { nom: 'Post carré' }], annexes: [], note: null },
  ];
}
/* Les objectifs produits des campagnes en cours (la jauge). */
const OBJECTIFS = [
  { id: 307, nom: 'Saint-Nicolas gourmand', debut: '2026-11-16', fin: '2026-12-06',
    produits: [{ id: 7016, nom: 'Spéculoos maison (sachet 250 g)', vendu: 58 }, { id: 7017, nom: 'Saint-Nicolas en chocolat', vendu: 31 }, { id: 7018, nom: 'Massepain (figurine)', vendu: 17 }],
    objectif: 420, vendu: 106, ceJour: 22, pct: 25.2, attendu: 19, etat: 'avance', reste: 314, ilFaut: 18.5, rythme: 26.5 },
  { id: 306, nom: 'Douceurs d’automne', debut: '2026-10-26', fin: '2026-11-29',
    produits: [{ id: 7013, nom: 'Tarte aux pommes caramel (6 parts)', vendu: 74 }, { id: 7014, nom: 'Chausson poire-chocolat', vendu: 352 }],
    objectif: 620, vendu: 426, ceJour: 14, pct: 68.7, attendu: 71.4, etat: 'clous', reste: 194, ilFaut: 19.4, rythme: 17 },
];
/* Les ventes d'une saison l'an dernier : quantité, CA, marge (règle de daMarge). */
function ventes(du, au, pids) {
  const r = D.alea('ventes' + du + pids.join(','));
  const P = {}, T = { q: 0, ca: 0, caHt: 0, marge: 0, sansMarge: 0 };
  pids.forEach(pid => {
    const ref = REFS.find(x => x.pwaId === +pid); if (!ref) { return; }
    const q = Math.round((ref.prix > 15 ? 12 : ref.prix > 6 ? 40 : 90) * (0.7 + r() * 0.6)), ca = D.r2(q * ref.prix), caHt = D.r2(ca / 1.06), pct = 58 + r() * 14, marge = D.r2(caHt * pct / 100);
    P[String(pid)] = { q, ca, caHt, marge, margePct: D.r1(pct), motif: null, methode: 'pieces' };
    T.q += q; T.ca += ca; T.caHt += caHt; T.marge += marge;
  });
  return { shop: D.SHOP, du, au, auLu: au, produits: P,
    total: { q: T.q, ca: D.r2(T.ca), caHt: D.r2(T.caHt), marge: D.r2(T.marge), margePct: T.caHt ? D.r1(100 * T.marge / T.caHt) : null, sansMarge: 0 },
    source: 'ventes du panel (product-category-groups) ; marge : CA HT encaissé − quantité × coût de la recette du jour (products/available)' };
}

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/dashboard/marketing/campagnes') { return { shop: D.SHOP, aujourdhui: D.AUJ, campagnes: enCours(), genere: '2026-11-19T15:40:00+01:00' }; }
  if (p === '/dashboard/marketing/annee') {
    const an = +(q.get('annee') || AN);
    return { shop: D.SHOP, annee: an, campagnes: CAMPAGNES.filter(c => c.debut <= an + '-12-31' && c.fin >= an + '-01-01').map(c => ({ id: c.id, nom: c.nom, type: c.type, typeCouleur: c.typeCouleur, debut: c.debut, fin: c.fin, phase: c.phase, reseau: c.reseau })) };
  }
  if (p === '/dashboard/marketing/ventes-periode') { return ventes(q.get('du'), q.get('au'), String(q.get('pids') || '').split(',').filter(Boolean)); }
  if (p === '/dashboard/marketing/campagnes/annexe') { return { __body: '%PDF-1.4\n% document fictif\n', type: 'application/pdf' }; }
  if (p === '/exploitation/objectifs-produits') { return { shop: D.SHOP, date: q.get('date') || D.AUJ, aujourdhui: D.AUJ, campagnes: OBJECTIFS }; }
  if (p === '/production/saisons') { return { aujourdhui: D.AUJ, saisons: SAISONS, permanentes: [{ id: 1, emoji: '', nom: 'Standard', produits: 180 }], alertes: [] }; }
  if (p === '/production/catalogue') { return REFS.map(r => ({ ref: r.ref, nom: r.nom, categorie: r.categorie, prix: r.prix, pwaId: r.pwaId, saisons: SAISONS.filter(s => s.produits.includes(r.ref)).map(s => s.nom) })); }
  return undefined;
};
