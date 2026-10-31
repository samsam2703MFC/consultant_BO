/* Maquettes « Promotions » du cockpit — les données, toutes illustrées.
 * 1. Ce que l'API du cockpit répondrait au dashboard (onglet Promotions de Gosselies) : le générateur fait tourner le VRAI
 *    formulaire du dashboard sur ces réponses pour en reprendre les étapes telles quelles.
 * 2. Le réseau : toutes les promotions de tous les magasins, pour le tableau d'analyse. */
const AUJ = '2026-10-26';
const STORES = [[4,'Gosselies'],[2,'Halle'],[3,'Nivelles'],[5,'Liège'],[6,'Namur'],[7,'Mons'],[8,'Waterloo'],[9,'Wavre'],[10,'Uccle']]
  .map(([id, n]) => ({ id, nom: "L'Atelier by - " + n, status: 'Ouvert' }));
const P = [];
let pid = 100;
const add = (cat, catId, nom, ttc, cout) => P.push({ id: ++pid, nom, prixHt: +(ttc / 1.06).toFixed(4), tva: 6, prixTtc: ttc, catId, cat, cout, source: 'magasin' });
add('Cookies', 12, 'Cookie chocolat', 2.6, 0.48); add('Cookies', 12, 'Cookie noix de pécan', 2.9, 0.61); add('Cookies', 12, 'Cookie caramel beurre salé', 2.9, 0.55); add('Cookies', 12, 'Cookie double chocolat', 2.9, 0.58);
add('Viennoiseries', 3, 'Croissant', 1.5, 0.31); add('Viennoiseries', 3, 'Pain au chocolat', 1.7, 0.36); add('Viennoiseries', 3, 'Chausson aux pommes', 2.1, 0.44); add('Viennoiseries', 3, 'Pain aux raisins', 2.0, 0.41);
add('Tartes', 7, 'Tarte au riz', 16.5, 3.9); add('Tartes', 7, 'Tarte aux pommes', 17.5, 4.2); add('Tartes', 7, 'Tarte au sucre', 14.5, 2.8); add('Tartes', 7, 'Tarte au citron meringuée', 19.5, 4.9);
add('Boissons chaudes', 20, 'Café', 2.4, 0.22); add('Boissons chaudes', 20, 'Cappuccino', 3.4, 0.38); add('Boissons chaudes', 20, 'Thé', 2.4, 0.15);
add('Sandwichs', 15, 'Sandwich jambon-fromage', 4.9, 1.35); add('Sandwichs', 15, 'Sandwich poulet curry', 5.4, 1.6); add('Sandwichs', 15, 'Sandwich thon mayonnaise', 5.2, 1.5);
add('Desserts', 9, 'Éclair au chocolat', 3.2, 0.7); add('Desserts', 9, 'Mille-feuille', 3.6, 0.85); add('Desserts', 9, 'Merveilleux', 3.4, 0.8);
const c = (id, nom) => ({ id, nom });
const DEMANDES = [
  { id: 'p21', panelId: 21, nom: 'Cookies 2 + 1 offert', statut: 'en_caisse', shop: 4, shops: [4], x: { q: 2, cats: [c(12, 'Cookies')] }, y: { q: 1, type: 'FREE', cats: [c(12, 'Cookies')] }, du: '2026-10-14 00:00', au: '2026-11-13 23:59', creneaux: [{ jours: [1,2,3,4,5], de: '14:00', a: '17:00' }], repetable: true, maxParTicket: 3, canal: 'tous', seuil: 0, bon: null },
  { id: 'p24', panelId: 24, nom: 'Café + viennoiserie à 1 €', statut: 'en_caisse', shop: 4, shops: [4], x: { q: 1, cats: [c(20, 'Boissons chaudes')] }, y: { q: 1, type: 'FIXED_PRICE', valeur: 1, cats: [c(3, 'Viennoiseries')] }, du: '2026-10-01 00:00', au: '2037-12-31 23:59', creneaux: [{ jours: [1,2,3,4,5,6,7], de: '07:00', a: '10:00' }], repetable: false, canal: 'comptoir', seuil: 0, bon: null },
  { id: 'p27', panelId: 27, nom: 'Tarte −20 % le dimanche soir', statut: 'brouillon', shop: 4, shops: [4], x: { q: 1, cats: [c(7, 'Tartes')] }, y: { q: 1, type: 'PERCENTAGE_DISCOUNT', valeur: 20, cats: [c(7, 'Tartes')] }, du: '2026-11-02 00:00', au: '2026-11-30 23:59', creneaux: [{ jours: [7], de: '16:00', a: '18:00' }], repetable: false, canal: 'tous', seuil: 0, bon: null },
  { id: 'p29', panelId: 29, nom: 'Bon BIENVENUE · sandwich + dessert', statut: 'en_caisse', shop: 4, shops: [4], x: { q: 1, cats: [c(15, 'Sandwichs')] }, y: { q: 1, type: 'FIXED_AMOUNT_DISCOUNT', valeur: 1.5, cats: [c(9, 'Desserts')] }, du: '2026-10-20 00:00', au: '2026-12-31 23:59', creneaux: [], repetable: false, canal: 'webshop', seuil: 10, bon: { id: 5, distribution: 'SHARED', code: 'BIENVENUE', limite: 200 } },
];
const PANEL = [
  { id: 12, nom: 'Formule petit-déjeuner', type: 'bundle', du: '2026-09-01 00:00', au: '2037-12-31 23:59', etat: 'active', reseau: true, magasins: 9, bundle: { items: ['Café', 'Croissant', 'Jus d’orange'], prix: 4.9 } },
  { id: 15, nom: 'Halloween : 3 cookies achetés, le 4e offert', type: 'xy', du: '2026-10-25 00:00', au: '2026-11-01 23:59', etat: 'active', reseau: true, magasins: 9, x: { q: 3, c: { 12: 'Cookies' } }, y: { q: 1, c: { 12: 'Cookies' } }, recompense: { type: 'FREE' }, canaux: null, seuil: 0, creneaux: [] },
  { id: 18, nom: 'Saint-Nicolas : merveilleux −30 %', type: 'xy', du: '2026-12-01 00:00', au: '2026-12-06 23:59', etat: 'a_venir', reseau: false, magasins: 6, x: { q: 2, p: { 121: 'Merveilleux' } }, y: { q: 1, p: { 121: 'Merveilleux' } }, recompense: { type: 'PERCENTAGE_DISCOUNT', valeur: 30 }, canaux: null, seuil: 0, creneaux: [] },
];
const promos = { lu: '10:42', luJour: AUJ, luTs: Date.now(), regles: { coefMin: 2, conditions: { seuil: true, canal: true, comptoir: true, webshop: true } }, panel: PANEL, demandes: DEMANDES };
const jours = []; for (let i = 29; i >= 0; i--) { const t = new Date(AUJ + 'T12:00:00'); t.setDate(t.getDate() - i); jours.push(t.toISOString().slice(0, 10)); }
const rnd = (s => () => (s = (s * 9301 + 49297) % 233280) / 233280)(7);
const canaux = { promos: [
  { id: 21, nom: 'Cookies 2 + 1 offert', compt: { n: 214, r: 512.4 }, cc: { n: 22, r: 51.7 }, liv: { n: 9, r: 21.2 } },
  { id: 24, nom: 'Café + viennoiserie à 1 €', compt: { n: 388, r: 349.2 }, cc: { n: 0, r: 0 }, liv: { n: 0, r: 0 } },
  { id: 12, nom: 'Formule petit-déjeuner', compt: { n: 96, r: 105.6 }, cc: { n: 14, r: 15.4 }, liv: { n: 6, r: 6.6 } },
  { id: 29, nom: 'Bon BIENVENUE · sandwich + dessert', compt: { n: 0, r: 0 }, cc: { n: 17, r: 25.5 }, liv: { n: 11, r: 16.5 } } ],
  total: { compt: { n: 698, r: 967.2 }, cc: { n: 53, r: 92.6 }, liv: { n: 26, r: 44.3 } },
  parJour: jours.map(j => ({ j, lu: true, compt: { n: Math.round(16 + 14 * rnd()), r: 20 + 20 * rnd() }, web: { n: Math.round(1 + 4 * rnd()), r: 2 + 6 * rnd() } })),
  enLigne: { cc: 0, liv: 0 }, joursSans: [], commandes: true };
function repondre(url) {
  const u = new URL(url), p = u.pathname.replace(/^.*\/api\/cockpit/, '');
  if (p === '/stores') { return STORES; }
  if (p === '/exploitation/promos-magasin') { return promos; }
  if (p === '/exploitation/promos-magasin/produits') { return { produits: P }; }
  if (p === '/exploitation/promos-magasin/canaux') { return canaux; }
  if (p === '/exploitation/promos-magasin/clients') { return { clients: [] }; }
  if (p === '/promos-magasin') { return { demandes: DEMANDES, noms: {}, regles: promos.regles }; }
  return null;
}
module.exports = { repondre, STORES, P, DEMANDES, PANEL, AUJ };

/* --- Le réseau : toutes les promotions de tous les magasins (illustré) -------------------------------------------------
 * Une ligne par promotion. `mag` : le magasin (promotion du magasin) ; `mags` : les magasins d'une promotion de la marque.
 * Les chiffres sont ceux des 30 derniers jours : fois (comptoir, webshop), ventes des lignes en promotion, remise accordée. */
const MAGS = ['Gosselies', 'Halle', 'Nivelles', 'Liège', 'Namur', 'Mons', 'Waterloo', 'Wavre', 'Uccle'];
const R = [
  { id: 12, src: 'marque', mags: MAGS, nom: 'Formule petit-déjeuner', sorte: 'bundle', achete: 'Café + Croissant + Jus d’orange', recoit: 'le lot à 4,90 €', cpt: true, webOk: true, du: '01/09', au: null, statut: 'en_caisse',
    fois: 1046, web: 9, ventes: 5125, remise: 1287, coef: null, par: [116, 104, 138, 151, 97, 122, 109, 118, 91] },
  { id: 15, src: 'marque', mags: MAGS, nom: 'Halloween : 3 cookies, le 4e offert', sorte: 'xy', achete: '3 × Cookies', recoit: 'le 4<sup>e</sup> offert', cpt: true, webOk: true, du: '25/10', au: '01/11', statut: 'en_caisse',
    fois: 212, web: 14, ventes: 2460, remise: 590, coef: 3.36, par: [31, 22, 25, 30, 18, 27, 21, 20, 18] },
  { id: 18, src: 'marque', mags: ['Gosselies', 'Halle', 'Nivelles', 'Liège', 'Mons', 'Waterloo'], nom: 'Saint-Nicolas : merveilleux −30 %', sorte: 'xy', achete: '2 × Merveilleux', recoit: 'le 3<sup>e</sup> à −30 %', cpt: true, webOk: true, du: '01/12', au: '06/12', statut: 'a_venir',
    fois: null, coef: 3.12 },
  { id: 21, src: 'mag', mag: 'Gosselies', nom: 'Cookies 2 + 1 offert', achete: '2 × Cookies', recoit: 'le 3<sup>e</sup> offert', cpt: true, webOk: true, du: '14/10', au: '13/11', statut: 'en_caisse', fois: 245, web: 13, ventes: 2080, remise: 585, coef: 2.99, cren: 'lun.–ven. 14–17 h' },
  { id: 24, src: 'mag', mag: 'Gosselies', nom: 'Café + viennoiserie à 1 €', achete: '1 × Boissons chaudes', recoit: 'Viennoiseries à 1,00 €', cpt: true, webOk: false, du: '01/10', au: null, statut: 'en_caisse', fois: 388, web: 0, ventes: 1320, remise: 349, coef: 4.86, cren: 'tous les jours 7–10 h' },
  { id: 29, src: 'mag', mag: 'Gosselies', nom: 'Bon BIENVENUE · sandwich + dessert', achete: '1 × Sandwichs', recoit: 'Desserts −1,50 €', cpt: false, webOk: true, seuil: 10, bon: 'BIENVENUE', du: '20/10', au: '31/12', statut: 'en_caisse', fois: 28, web: 100, ventes: 252, remise: 42, coef: 2.87 },
  { id: 27, src: 'mag', mag: 'Gosselies', nom: 'Tarte −20 % le dimanche soir', achete: '1 × Tartes', recoit: 'le 2<sup>e</sup> à −20 %', cpt: true, webOk: true, du: '02/11', au: '30/11', statut: 'brouillon', fois: null, coef: 2.31 },
  { id: 31, src: 'mag', mag: 'Halle', nom: 'Cookies 2 + 1 offert', achete: '2 × Cookies', recoit: 'le 3<sup>e</sup> offert', cpt: true, webOk: true, du: '16/10', au: '15/11', statut: 'en_caisse', fois: 180, web: 9, ventes: 1530, remise: 431, coef: 2.99 },
  { id: 33, src: 'mag', mag: 'Halle', nom: '½ tarte au riz + café offert', achete: '1 × ½ Tarte au riz', recoit: 'Café offert', cpt: true, webOk: false, du: '05/10', au: '04/11', statut: 'en_caisse', fois: 64, web: 0, ventes: 610, remise: 154, coef: 1.84 },
  { id: 35, src: 'mag', mag: 'Nivelles', nom: 'Viennoiseries 5 + 1 offerte', achete: '5 × Viennoiseries', recoit: 'le 6<sup>e</sup> offert', cpt: true, webOk: true, du: '01/10', au: null, statut: 'en_caisse', fois: 140, web: 6, ventes: 1290, remise: 230, coef: 3.41 },
  { id: 36, src: 'mag', mag: 'Nivelles', nom: 'Sandwich + boisson −1 €', achete: '1 × Sandwichs', recoit: 'Boissons −1,00 €', cpt: true, webOk: true, du: '12/10', au: '12/11', statut: 'en_caisse', fois: 96, web: 21, ventes: 715, remise: 96, coef: 2.12 },
  { id: 38, src: 'mag', mag: 'Liège', nom: 'Happy hour : éclair −50 %', achete: '1 × Éclair au chocolat', recoit: 'le 2<sup>e</sup> à −50 %', cpt: true, webOk: false, du: '19/10', au: '18/11', statut: 'en_caisse', fois: 52, web: 0, ventes: 125, remise: 83, coef: 1.71, cren: 'tous les jours 16–18 h' },
  { id: 39, src: 'mag', mag: 'Liège', nom: 'Merveilleux 3 + 1', achete: '3 × Merveilleux', recoit: 'le 4<sup>e</sup> offert', cpt: true, webOk: true, du: '02/11', au: '30/11', statut: 'a_venir', fois: null, coef: 2.78 },
  { id: 41, src: 'mag', mag: 'Mons', nom: 'Cappuccino + cookie à 1,50 €', achete: '1 × Cappuccino', recoit: 'Cookies à 1,50 €', cpt: true, webOk: false, du: '07/10', au: null, statut: 'en_caisse', fois: 120, web: 0, ventes: 588, remise: 162, coef: 3.05 },
  { id: 42, src: 'mag', mag: 'Mons', nom: 'Tarte : la 2e à −30 %', achete: '1 × Tartes', recoit: 'le 2<sup>e</sup> à −30 %', cpt: true, webOk: true, du: '—', au: null, statut: 'brouillon', fois: null, coef: 2.4 },
  { id: 44, src: 'mag', mag: 'Waterloo', nom: 'Bon FIDÈLE · pain au chocolat offert', achete: '1 × Viennoiseries', recoit: 'Pain au chocolat offert', cpt: true, webOk: true, seuil: 15, bon: '50 codes', du: '15/10', au: '15/12', statut: 'en_caisse', fois: 14, web: 36, ventes: 301, remise: 22, coef: 2.45 },
  { id: 46, src: 'mag', mag: 'Wavre', nom: 'Croissant 4 + 2 offerts', achete: '4 × Croissant', recoit: 'les 2 suivants offerts', cpt: true, webOk: true, du: '21/09', au: '20/10', statut: 'terminee', fois: 205, web: 4, ventes: 615, remise: 205, coef: 2.64 },
  { id: 48, src: 'mag', mag: 'Uccle', nom: 'Quiche : la 2e à −50 %', achete: '1 × Quiches', recoit: 'le 2<sup>e</sup> à −50 %', cpt: true, webOk: true, du: '—', au: null, statut: 'brouillon', fois: null, coef: 2.05 },
];
// Le CA des magasins sur 30 jours (pour le poids des promotions dans le CA) — illustré.
const CA30 = { Gosselies: 98400, Halle: 71200, Nivelles: 83600, Liège: 92800, Namur: 64100, Mons: 77300, Waterloo: 69500, Wavre: 58900, Uccle: 61700 };
module.exports.MAGS = MAGS; module.exports.R = R; module.exports.CA30 = CA30;
