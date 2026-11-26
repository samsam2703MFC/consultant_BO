/* Mon catalogue (vue=catalogue) : les routes /dashboard/catalogues/* du relais panel/src/catalogues_magasin.php, aux
 * formes que lit panel/public/dashboard/catalogue.js (l'API d'intégration du Marketing, relayée telle quelle :
 * {success, data, meta}). Tout est fictif : le webshop du « Magasin démo » (26 produits génériques, 7 catégories,
 * 4 gammes), trois promotions, deux catalogues à moi, deux catalogues de la marque.
 *
 * Le récit de la vidéo 19 : un catalogue de la marque arrive (« Les fêtes de fin d'année »), à regarder ; le magasin
 * crée le sien à partir des gammes Noël et Saint-Nicolas (17 produits, 8 pages, une page par catégorie), retire un
 * produit, met les bûches en pleine page (le catalogue passe à 10 pages), présente ses biscuits en grandes vignettes,
 * change l'ordre, valide, puis imprime et diffuse.
 *
 * Les écritures (créer, enregistrer produits et pages, valider, marquer imprimé, lien, points vus) changent l'état en
 * mémoire : le document (les pages composées) et les points de la validation sont recalculés à chaque lecture.
 * Les images : les dessins au trait du dépôt (tablette, saisons), servis par /film/catalogue/… (fausse API).
 *
 * Complété le 24/11/2026 pour les vidéos par onglet (19-01 … 19-08), comme le dit le contrat (shared/docs/contrat-api.md,
 * « Mon catalogue ») : l'image de la médiathèque d'une promotion (`offers[].mediaId`) et la place libre d'une page
 * (`filler`, `fillerMediaId`) se voient dans les pages composées ; changer `pageCount` sans `pages` retire les pages
 * vides de la fin, ou refuse (422 CATALOGUE_PAGES_NOT_EMPTY) si l'une d'elles porte des produits ; deux pages vides en
 * fin de catalogue font un point de la validation avec le geste « Passer à N pages » (`moins-de-pages`) ; « Nouveau
 * lien » donne un autre lien (l'ancien est révoqué). Les images de la médiathèque sont des tuiles (un dessin sur fond
 * coloré), pour se lire comme des photos. */
'use strict';
const fs = require('fs');
const path = require('path');

const PORT = +(process.env.FILM_PORT || 8099);
const DEPOT = path.resolve(__dirname, '../../../..');
const BASE = '/film/catalogue/';
const ABS = 'http://127.0.0.1:' + PORT + '/api/cockpit' + BASE;   // les dessins : adresses absolues (dessin(), catalogue.js)
const REL = '../api/cockpit' + BASE;                                 // les photos : relatives à /dashboard/
const NOM = 'Magasin démo';

/* ── les dessins (traits Ruby du dépôt) ── */
const ILL = 'marketing/public/fidelite-test/ill/', TAB = 'panel/public/tablette/img/p/';
const DESSINS = {
  buche: ILL + 'cake.png', part: TAB + 'cake-slice.png', cupcake: TAB + 'cupcake.png', tarte: ILL + 'tarte.png', tartelette: ILL + 'tartelette.png',
  tresse: TAB + 'roll.png', roule: TAB + 'rolls.png', croissant: TAB + 'croissant.png', cookies: ILL + 'cookies.png', chocolat: ILL + 'chocolat.png',
  nicolas: ILL + 'saint-nicolas.png', noel: ILL + 'noel.png', hiver: ILL + 'hiver.png', galette: ILL + 'galette.png', pain: TAB + 'bread-3.png',
  baguette: TAB + 'bread-1.png', campagne: TAB + 'bread-5.png', sandwich: TAB + 'sandwiches.png', brioche: TAB + 'bread-2.png',
};
const FONDS = ['#F6EFE5', '#F3E6DA', '#EFE7DC', '#F5EBE0', '#F1E9DF'];
const b64 = {};
function png(nom) { const f = DESSINS[nom]; if (!f) { return null; } return fs.readFileSync(path.join(DEPOT, f)); }
/** La « photo » d'un produit : son dessin sur un fond crème (4:3). */
function photoSvg(pr) {
  const n = pr.dessin;
  if (!b64[n]) { const b = png(n); b64[n] = b ? b.toString('base64') : ''; }
  const fond = FONDS[pr.id % FONDS.length];
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300" width="400" height="300"><rect width="400" height="300" rx="18" fill="' + fond + '"/>'
    + '<image href="data:image/png;base64,' + b64[n] + '" x="70" y="20" width="260" height="260"/></svg>';
}

/* ── le webshop du magasin (fictif) ── */
const CATEGORIES = [
  { id: 21, name: 'Bûches de Noël', nameNl: 'Kerststronken', dessin: 'buche' },
  { id: 22, name: 'Viennoiseries de fête', nameNl: 'Feestkoffiekoeken', dessin: 'tresse' },
  { id: 23, name: 'Biscuits et confiseries', nameNl: 'Koekjes en snoep', dessin: 'cookies' },
  { id: 24, name: 'Pains de fête', nameNl: 'Feestbroden', dessin: 'campagne' },
  { id: 25, name: 'Viennoiseries', nameNl: 'Koffiekoeken', dessin: 'croissant' },
  { id: 26, name: 'Pains', nameNl: 'Broden', dessin: 'baguette' },
  { id: 27, name: 'Pâtisseries', nameNl: 'Gebak', dessin: 'tartelette' },
];
const GAMMES = [
  { id: 31, name: 'Noël', from: '2026-12-01', to: '2026-12-31', dessin: 'noel' },
  { id: 32, name: 'Saint-Nicolas', from: '2026-11-20', to: '2026-12-06', dessin: 'nicolas' },
  { id: 33, name: 'Petit-déjeuner', from: null, to: null, dessin: 'croissant' },
  { id: 34, name: 'Galette des rois', from: '2027-01-02', to: '2027-01-31', dessin: 'galette' },
];
const G = 'gluten', O = 'oeufs', L = 'lait', F = 'fruits_coque', SO = 'soja';
// [id, nom, catégorie, gammes, prix (centimes), poids (g), allergènes, dessin, {prix au comptoir, ancien prix}]
const PRODUITS = [
  [401, 'Bûche chocolat-praliné (6 parts)', 21, [31], 2890, 720, [G, O, L, F], 'buche'],
  [402, 'Bûche framboise-vanille (6 parts)', 21, [31], 2790, 700, [G, O, L], 'part'],
  [403, 'Bûche moka (6 parts)', 21, [31], 2690, 700, [G, O, L, SO], 'buche'],
  [404, 'Bûche marrons-cassis (6 parts)', 21, [31], 2990, 720, [G, O, L], 'part'],
  [411, 'Cougnou au sucre', 22, [31], 650, 400, [G, O, L], 'tresse'],
  [412, 'Cougnou aux raisins', 22, [31], 690, 400, [G, O, L], 'tresse'],
  [413, 'Brioche aux fruits confits', 22, [31], 890, 500, [G, O, L], 'brioche', { old: 990 }],
  [414, 'Bonhomme en brioche', 22, [32], 290, 120, [G, O, L], 'roule'],
  [421, 'Spéculoos maison (sachet 250 g)', 23, [32], 590, 250, [G, SO], 'cookies'],
  [422, 'Saint-Nicolas en chocolat', 23, [32], 790, 150, [L, SO], 'nicolas'],
  [423, 'Massepain (figurine)', 23, [32], 450, 80, [F], 'cupcake'],
  [424, 'Pain d’épices', 23, [31, 32], 650, 350, [G, O], 'pain', { comptoir: 620 }],
  [425, 'Sablés étoilés (boîte)', 23, [31], 990, 300, [G, O, L], 'cookies'],
  [426, 'Truffes au chocolat (boîte de 12)', 23, [31], 1290, 180, [L, SO], 'chocolat'],
  [427, 'Orangettes (sachet)', 23, [31], 890, 150, [L, SO], 'chocolat'],
  [431, 'Pain aux noix et figues', 24, [31], 520, 500, [G, F], 'campagne'],
  [432, 'Pain surprise (40 pièces)', 24, [31], 3900, 1400, [G, O, L, 'moutarde', 'poisson'], 'sandwich'],
  [441, 'Croissant au beurre', 25, [33], 140, 60, [G, O, L], 'croissant'],
  [442, 'Pain au chocolat', 25, [33], 160, 70, [G, O, L, SO], 'croissant'],
  [443, 'Pain aux raisins', 25, [33], 190, 90, [G, O, L], 'roule'],
  [444, 'Chausson aux pommes', 25, [33], 210, 110, [G, L], 'croissant'],
  [451, 'Baguette tradition', 26, [33], 150, 250, [G], 'baguette'],
  [452, 'Pain de campagne', 26, [], 380, 800, [G], 'campagne'],
  [453, 'Pain complet', 26, [], 360, 800, [G], 'pain'],
  [461, 'Éclair au chocolat', 27, [], 320, 90, [G, O, L], 'tartelette'],
  [462, 'Tarte au citron meringuée (part)', 27, [], 360, 120, [G, O, L], 'tarte'],
  [463, 'Galette frangipane (6 parts)', 27, [34], 1890, 650, [G, O, L, F], 'galette'],
  [464, 'Galette pomme-frangipane (6 parts)', 27, [34], 1990, 680, [G, O, L, F], 'galette'],
].map(([id, name, cat, gammes, prix, poids, al, dessin, x]) => ({ id, name, categoryId: cat, gammes, priceCents: prix, weightGrams: poids, allergens: al, dessin,
  shopPriceCents: x && x.comptoir ? x.comptoir : prix, oldPriceCents: x && x.old ? x.old : null }));
const prod = id => PRODUITS.find(p => p.id === +id);
const nomCat = id => (CATEGORIES.find(c => c.id === +id) || {}).name || 'Notre sélection';
const fiche = p => ({ id: p.id, name: p.name, nameNl: null, categoryId: p.categoryId, gammes: p.gammes.slice(), priceCents: p.priceCents, shopPriceCents: p.shopPriceCents,
  oldPriceCents: p.oldPriceCents, imageUrl: REL + 'photo/' + p.id + '.svg', weightGrams: p.weightGrams, allergens: p.allergens.slice() });

/* Les promotions de TFBuddy qui valent pour la boutique. */
const OFFRES = [
  { id: 'bundle-71', type: 'bundle', erpId: 71, badge: 'Duo', name: 'Duo de bûches', items: [[401, 1], [402, 1]], moreProducts: 0, period: 'du 1er au 24 décembre',
    categoryId: 21, productIds: [401, 402], categoryIds: [], priceCents: 5190, oldPriceCents: 5680, deal: 'Deux bûches de 6 parts' },
  { id: 'bundle-72', type: 'bundle', erpId: 72, badge: '3 + 1', name: 'Spéculoos : 3 sachets + 1 offert', items: [[421, 4]], moreProducts: 0, period: 'jusqu’au 6 décembre',
    categoryId: 23, productIds: [421], categoryIds: [], priceCents: 1770, oldPriceCents: 2360, deal: null },
  { id: 'scheduledDiscount-73', type: 'scheduledDiscount', erpId: 73, badge: '−10 %', name: 'Brioche aux fruits confits −10 %', items: [[413, 1]], moreProducts: 0,
    period: 'du 20 au 30 novembre', categoryId: 22, productIds: [413], categoryIds: [], priceCents: 890, oldPriceCents: 990, deal: null },
];
const offreListe = o => ({ id: o.id, type: o.type, erpId: o.erpId, badge: o.badge, name: o.name, products: o.items.map(([id, q]) => ({ id, name: prod(id).name, quantity: q })),
  moreProducts: o.moreProducts, period: o.period, categoryId: o.categoryId, productIds: o.productIds.slice(), categoryIds: o.categoryIds.slice(), priceCents: o.priceCents, oldPriceCents: o.oldPriceCents });

/* ── les catalogues ── */
const LAYOUTS = { vedette: 5, grille: 6, liste: 12, mosaique: 4, pleine: 1, presentation: 0, presentation_liste: 8 };
const estPres = l => l === 'presentation' || l === 'presentation_liste';
const page = (layout, category, x) => Object.assign({ layout, category, template: 'A', gamme: null, text: '', textNl: '', filler: null, fillerMediaId: null, header: 'titre', script: false }, x || {});
const produitsDesGammes = refs => PRODUITS.filter(p => p.gammes.some(g => refs.includes(g))).map(p => ({ id: p.id, on: true }));
/** La répartition automatique : une page par catégorie (grille 2 × 3 ; liste au-delà de 6 produits), dans l'ordre du
 * webshop, au nombre de pages demandé (+ 2 pages tant qu'il le faut, 16 au plus), le reste en pages libres. */
function repartir(c) {
  const on = new Set(c.products.filter(x => x.on !== false).map(x => +x.id));
  const cats = []; PRODUITS.forEach(p => { if (on.has(p.id) && !cats.includes(p.categoryId)) { cats.push(p.categoryId); } });
  const pages = [];
  cats.forEach(k => { const n = PRODUITS.filter(p => on.has(p.id) && p.categoryId === k).length; const l = n <= 6 ? 'grille' : 'liste'; for (let i = 0; i < Math.ceil(n / LAYOUTS[l]); i++) { pages.push(page(l, k)); } });
  let nb = Math.max(8, c.pageCount || 8);
  while (pages.length > nb - 4 && nb < 16) { nb += 2; }
  while (pages.length < nb - 4) { pages.push(page('grille', null)); }
  c.pages = pages.slice(0, nb - 4); c.pageCount = nb;
}
let prochain = 5110;
const MOI = [
  { id: 5102, owner: 'boutique', title: 'Galettes des rois', source: { kind: 'gamme', ref: 34, refs: [34], label: 'Galette des rois' }, format: 'A5', langs: ['fr'], pageCount: 8,
    status: 'brouillon', updatedAt: '2026-11-12T10:20:00+01:00', products: [{ id: 463, on: true }, { id: 464, on: true }], offers: [], showAvailability: true,
    pages: [page('mosaique', 27), page('grille', null), page('grille', null), page('grille', null)] },
  { id: 5101, owner: 'boutique', title: 'Le petit-déjeuner', source: { kind: 'gamme', ref: 33, refs: [33], label: 'Petit-déjeuner' }, format: 'A5', langs: ['fr'], pageCount: 8,
    status: 'imprime', printQty: 250, print: { quantity: 250, printedAt: '2026-10-05T09:00:00+02:00' }, updatedAt: '2026-10-05T09:00:00+02:00',
    products: [441, 442, 443, 444, 451].map(id => ({ id, on: true })), offers: [], showAvailability: false,
    pages: [page('presentation', null, { gamme: 33, text: 'Tout ce qu’il faut pour bien commencer la journée.' }), page('vedette', 25), page('liste', 26), page('grille', null)] },
];
const MARQUE = [
  { id: 9001, owner: 'marque', title: 'Les fêtes de fin d’année', source: { kind: 'gamme', ref: 31, refs: [31, 32], label: 'Gammes Noël + Saint-Nicolas' }, format: 'A5', langs: ['fr', 'nl'],
    pageCount: 12, status: 'pret', reception: { status: 'nouveau', receivedAt: '2026-11-16T08:30:00+01:00' }, updatedAt: '2026-11-16T08:30:00+01:00',
    products: produitsDesGammes([31, 32]), offers: [], showAvailability: true, subtitle: 'Bûches, cougnous et biscuits de saison',
    pages: [page('presentation', null, { gamme: 31, template: 'B', text: 'Nos bûches, nos cougnous et nos biscuits de fête, faits dans notre atelier.' }), page('pleine', 21, { product: 401, template: 'B' }),
      page('grille', 21, { template: 'B' }), page('mosaique', 22), page('vedette', 23), page('liste', 23), page('grille', 24), page('presentation', null, { gamme: 32, text: 'Le 6 décembre, le grand saint passe par notre boutique.' })] },
  { id: 9002, owner: 'marque', title: 'La rentrée gourmande', source: { kind: 'gamme', ref: 33, refs: [33], label: 'Petit-déjeuner' }, format: 'A5', langs: ['fr'], pageCount: 8,
    status: 'pret', reception: { status: 'pris', receivedAt: '2026-08-24T08:30:00+02:00' }, print: { quantity: 150, printedAt: '2026-08-28T09:00:00+02:00' }, updatedAt: '2026-08-28T09:00:00+02:00',
    products: [441, 442, 443, 444, 451].map(id => ({ id, on: true })), offers: [], showAvailability: false,
    pages: [page('presentation', null, { gamme: 33 }), page('vedette', 25), page('liste', 26), page('grille', null)] },
];
const vus = {};        // les points de vérification vus : « id|code » → vrai
const liens = {};      // le lien permanent d'un catalogue : id → {url, token}
const copie = x => JSON.parse(JSON.stringify(x));
const trouver = (id, marque) => (marque ? MARQUE : MOI).find(c => c.id === +id);

/* ── les pages composées (le document du Marketing) ── */
const dessinAbs = n => ABS + 'dessin/' + n + '.png';
const pieceProduit = p => {
  const o = OFFRES.find(x => x.type === 'scheduledDiscount' && x.productIds.includes(p.id));
  return Object.assign({ name: p.name, priceCents: p.priceCents, oldPriceCents: p.oldPriceCents, imageUrl: REL + 'photo/' + p.id + '.svg', weightGrams: p.weightGrams, allergens: p.allergens.slice() },
    o ? { promo: { label: o.badge, period: o.period } } : {});
};
/** Une promotion dans la page ; l'image choisie dans la médiathèque (`mediaId`) remplace les photos de ses produits. */
const pieceOffre = (o, c) => {
  const x = ((c && c.offers) || []).find(y => y.type + '-' + y.id === o.id), m = x && x.mediaId != null ? MEDIAS.find(y => y.id === +x.mediaId) : null;
  return { kind: 'offer', type: o.type, badge: o.badge, name: o.name, products: o.items.map(([id, q]) => ({ name: prod(id).name, quantity: q, imageUrl: REL + 'photo/' + id + '.svg' })),
    moreProducts: o.moreProducts, deal: o.deal, priceCents: o.priceCents, oldPriceCents: o.oldPriceCents, period: o.period, imageUrl: m ? m.url : null };
};
/** Une rangée entière libre : la place libre peut prendre une photo ou l'illustration (la règle du Marketing). */
const remplissable = (l, n) => l === 'grille' ? n <= 4 : l === 'vedette' ? n <= 3 : l === 'liste' ? n <= 9 : l === 'mosaique' ? n <= 2 : l === 'pleine' ? n === 0 : false;
/** La place libre d'une page : la photo de la médiathèque, ou l'illustration de sa catégorie (sinon de la gamme). */
function placeLibre(p, cat, n) {
  if (!p.filler || !remplissable(p.layout, n)) { return null; }
  if (p.filler === 'photo') { const m = MEDIAS.find(y => y.id === +p.fillerMediaId); return m ? { kind: 'photo', imageUrl: m.url, drawing: null, drawingUrl: null } : null; }
  const g = GAMMES.find(x => x.id === +p.gamme);
  return { kind: 'illustration', imageUrl: null, drawing: cat ? cat.dessin : g ? g.dessin : 'noel', drawingUrl: dessinAbs(cat ? cat.dessin : g ? g.dessin : 'noel') };
}
const offreOn = (c, o) => { const x = (c.offers || []).find(y => y.type + '-' + y.id === o.id); if (x) { return !!x.on; } const on = new Set(c.products.filter(p => p.on !== false).map(p => +p.id)); return o.productIds.some(id => on.has(id)); };
const DISPO = { 21: 'Du 1er au 24 décembre · à commander 48 h à l’avance', 22: 'Du 1er au 31 décembre', 23: 'Du 20 novembre au 31 décembre', 24: 'Du 1er au 31 décembre · à commander 48 h à l’avance' };
const DISPO_NL = { 21: 'Van 1 tot 24 december · 48 u op voorhand bestellen', 22: 'Van 1 tot 31 december', 23: 'Van 20 november tot 31 december', 24: 'Van 1 tot 31 december · 48 u op voorhand bestellen' };
/** Ce que chaque page porte (la règle de `fill`, comme `remplir` dans catalogue.js) : les ids de produits par page. */
function remplir(c) {
  const on = new Set(c.products.filter(p => p.on !== false).map(p => +p.id));
  const prods = PRODUITS.filter(p => on.has(p.id));
  const pris = new Set(), reserve = new Map();
  c.pages.forEach((p, i) => { if (p.layout === 'pleine' && p.product != null && on.has(+p.product) && !reserve.has(+p.product)) { reserve.set(+p.product, i); } });
  return c.pages.map((p, i) => {
    if (estPres(p.layout)) { return p.layout === 'presentation_liste' ? prods.filter(x => x.gammes.includes(+p.gamme)).slice(0, 8).map(x => x.id) : []; }
    if (p.layout === 'pleine') {
      const mien = [...reserve].find(e => e[1] === i);
      if (mien) { pris.add(mien[0]); return [mien[0]]; }
    }
    const mes = [];
    for (const x of prods) {
      if (mes.length >= LAYOUTS[p.layout]) { break; }
      if (reserve.has(x.id) || pris.has(x.id) || (p.category != null && x.categoryId !== +p.category)) { continue; }
      pris.add(x.id); mes.push(x.id);
    }
    return mes;
  });
}
function documentDe(c, lang) {
  const nl = lang === 'nl', ids = remplir(c);
  const offres = OFFRES.filter(o => o.type !== 'scheduledDiscount' && offreOn(c, o));
  const placees = new Set();
  const interieur = c.pages.map((p, i) => {
    if (estPres(p.layout)) {
      const g = GAMMES.find(x => x.id === +p.gamme) || GAMMES[0];
      return { kind: 'presentation', layout: p.layout, template: p.template || 'A', title: g.name, text: (nl ? p.textNl : p.text) || '', drawingUrl: dessinAbs(g.dessin), imageUrl: null,
        products: ids[i].map(id => pieceProduit(prod(id))), locked: false };
    }
    const cat = CATEGORIES.find(x => x.id === +p.category);
    const pr = ids[i].map(id => pieceProduit(prod(id)));
    // Une promotion cochée : sous le produit de sa pleine page, sinon dans une case libre de la page de sa catégorie.
    offres.forEach(o => {
      if (placees.has(o.id)) { return; }
      const pleine = p.layout === 'pleine' && ids[i].some(id => o.productIds.includes(id));
      const derniere = p.category != null && +p.category === o.categoryId && pr.length < LAYOUTS[p.layout] && !c.pages.some((q, j) => j > i && !estPres(q.layout) && +q.category === o.categoryId);
      if (pleine || (derniere && p.layout !== 'pleine')) { pr.push(pieceOffre(o, c)); placees.add(o.id); }
    });
    return { kind: 'products', layout: p.layout, template: p.template || 'A', header: p.header || 'titre', script: !!p.script, title: cat ? (nl ? cat.nameNl : cat.name) : (nl ? 'Onze selectie' : 'Notre sélection'),
      gammeTitle: null, products: pr, room: Math.max(0, LAYOUTS[p.layout] - pr.length), fillable: remplissable(p.layout, pr.length), filler: placeLibre(p, cat, pr.length),
      drawingUrl: cat ? dessinAbs(cat.dessin) : null, familyDrawingUrl: cat ? dessinAbs(cat.dessin) : null, gammeImageUrl: null,
      availabilityFooter: c.showAvailability !== false && cat && DISPO[cat.id] ? (nl ? DISPO_NL : DISPO)[cat.id] : null, locked: false };
  });
  const al = []; c.pages.forEach((p, i) => ids[i].forEach(id => prod(id).allergens.forEach(a => { if (!al.includes(a)) { al.push(a); } })));
  const gamme = GAMMES.find(g => g.id === +((c.source && c.source.refs && c.source.refs[0]) || (c.source && c.source.ref)));
  return {
    catalogue: { id: c.id, title: c.title, format: c.format, pageCount: c.pageCount },
    charter: { version: 4, logoUrl: null, colors: { primary: '#8D1D2C', secondary: '#F6EFE5', accent: '#B0792A', ink: '#241C1A' } },
    version: { lang: nl ? 'nl' : 'fr' },
    pages: [
      { kind: 'cover', template: 'A', title: c.title, subtitle: c.subtitle || (gamme && gamme.id === 33 ? 'Viennoiseries et pains du matin' : 'Bûches, biscuits et douceurs de saison'), eyebrow: NOM,
        drawingUrl: dessinAbs(gamme ? gamme.dessin : 'noel'), imageUrl: null, locked: true },
      { kind: 'brand', slot: 'page2', template: 'A', title: nl ? 'Elke ochtend hier gemaakt' : 'Fait ici, chaque matin',
        text: nl ? 'Onze bakkers en patissiers maken elke dag, in ons atelier, de lekkernijen uit deze catalogus.' : 'Nos boulangers et nos pâtissiers préparent chaque jour, dans notre atelier, les douceurs de ce catalogue.\n\nCommandez-les en boutique ou en ligne : elles vous attendent le jour choisi.', locked: true },
      ...interieur,
      { kind: 'brand', slot: 'penultimate', template: 'A', title: nl ? 'Onze diensten' : 'Nos services', text: nl ? 'Bestellingen voor uw feesten\nLevering aan bedrijven' : 'Commandes pour vos fêtes\nLivraison aux entreprises\nPièces sur mesure', allergens: al, locked: true },
      { kind: 'back', template: 'A', title: nl ? 'Bestel online' : 'Commandez en ligne', text: nl ? 'Afhalen in de winkel op de gekozen dag.' : 'Retrait en boutique le jour choisi.',
        qrUrl: 'https://commande.exemple.be/magasin-demo', host: 'commande.exemple.be',
        shop: { name: NOM, address: 'Place de l’Exemple 1, 1000 Bruxelles', hours: nl ? 'van dinsdag tot zondag, 7 – 19 u' : 'du mardi au dimanche, de 7 h à 19 h', phone: '02 000 00 00', email: 'magasin.demo@exemple.be' }, locked: true },
    ],
  };
}

/* ── les points de la validation (calculés par le Marketing) ── */
function verifications(c, marque) {
  const ids = remplir(c), on = c.products.filter(p => p.on !== false).length;
  const items = [];
  const vu = code => !!vus[c.id + '|' + code];
  items.push({ code: 'pages-marque', level: 'ok', title: 'Les quatre pages de la marque', text: 'Couverture, page 2, avant-dernière et dernière : la charte publiée, avec l’adresse, les horaires et le QR de ma boutique.', actions: [], seen: true });
  items.push({ code: 'prix', level: 'question', title: 'Les prix de mon webshop', text: on + ' produits à mes prix. Le pain d’épices est à 6,20 € au comptoir et à 6,50 € au webshop : le catalogue imprime 6,50 €.', actions: marque ? ['regle-par-la-marque'] : ['c-est-juste'], seen: vu('prix') });
  if (c.showAvailability !== false) { items.push({ code: 'dates', level: 'question', title: 'Les dates en pied de page', text: 'Lues dans TFBuddy : « Du 1er au 24 décembre · à commander 48 h à l’avance » pour les bûches, « Du 20 novembre au 31 décembre » pour les biscuits.', actions: ['dates-vues'], seen: vu('dates') }); }
  const iSpec = c.pages.findIndex((p, i) => ids[i].includes(421));
  const spec = OFFRES[1];
  if (!marque && offreOn(c, spec)) {
    const der = c.pages.reduce((a, p, i) => (+p.category === 23 ? i : a), iSpec);
    items.push({ code: 'promo-fin', level: 'attention', title: 'Une promotion finit avant le catalogue', text: '« ' + spec.name + ' » s’arrête le 6 décembre ; les biscuits du catalogue sont en vente jusqu’au 31 décembre.', actions: ['retirer-promo'], page: der + 3, offer: { type: spec.type, id: spec.erpId }, seen: vu('promo-fin') });
  }
  // Une page de produits pas pleine : la place libre reste blanche.
  let creuse = -1; c.pages.forEach((p, i) => { if (creuse < 0 && !estPres(p.layout) && p.layout !== 'pleine' && p.category != null && ids[i].length && ids[i].length <= LAYOUTS[p.layout] / 2 && !p.filler) { creuse = i; } });
  if (creuse >= 0) {
    items.push({ code: 'page-' + (creuse + 3), level: 'question', title: 'Page ' + (creuse + 3) + ' : ' + ids[creuse].length + ' produits sur ' + LAYOUTS[c.pages[creuse].layout] + ' places', text: 'Le bas de la page reste blanc. Une photo de la médiathèque ou l’illustration de la gamme peut prendre la place libre.', actions: marque ? ['ouvrir-page', 'regle-par-la-marque'] : ['ouvrir-page', 'modifier', 'imprimer-ainsi'], page: creuse + 3, seen: vu('page-' + (creuse + 3)) });
  }
  // Les pages vides de la fin (ni produit, ni présentation, ni place libre) : deux au moins, « Passer à N pages » les retire.
  let vides = 0;
  for (let i = c.pages.length - 1; i >= 0 && !estPres(c.pages[i].layout) && !ids[i].length && !c.pages[i].filler; i--) { vides++; }
  if (vides >= 2 && c.pageCount > 8) {
    const de = c.pages.length - vides + 3;
    items.push({ code: 'pages-vides', level: 'question', title: (vides === 2 ? 'Pages ' + de + ' et ' + (de + 1) : 'Pages ' + de + ' à ' + (de + vides - 1)) + ' : vides',
      text: 'Aucun produit sur ' + (vides === 2 ? 'ces deux pages' : 'ces ' + vides + ' pages') + ' : elles s’imprimeraient blanches. À ' + (c.pageCount - 2) + ' pages, les deux dernières partent.',
      actions: marque ? ['ouvrir-page', 'regle-par-la-marque'] : ['moins-de-pages', 'ouvrir-page', 'imprimer-ainsi'], page: de, seen: vu('pages-vides') });
  }
  items.forEach(it => { if (it.level !== 'ok') { it.seen = vu(it.code); } });
  return { items, total: items.length, seen: items.filter(it => it.seen).length,
    pages: items.filter(it => it.page && it.level !== 'ok' && !it.seen).map(it => ({ no: it.page, mark: it.level === 'question' ? 'question' : 'attention' })) };
}

/* ── les gestes ── */
function creer(corps) {
  const refs = corps.source && corps.source.kind === 'gamme' ? [].concat(corps.source.ref).map(Number) : [];
  const c = { id: prochain++, owner: 'boutique', title: String(corps.title || 'Mon catalogue'), format: corps.format || 'A5', langs: corps.langs || ['fr'], pageCount: +corps.pageCount || 8,
    source: refs.length ? { kind: 'gamme', ref: refs[0], refs, label: refs.map(r => GAMMES.find(g => g.id === r).name).join(' + ') } : { kind: 'choix' },
    status: 'brouillon', updatedAt: '2026-11-19T15:40:00+01:00', products: refs.length ? produitsDesGammes(refs) : (corps.products || []).map(x => ({ id: +x.id, on: x.on !== false })),
    offers: [], showAvailability: true };
  repartir(c);
  MOI.unshift(c);
  return c;
}
function modifier(c, corps) {
  ['title', 'format', 'langs', 'status', 'showAvailability'].forEach(k => { if (corps[k] !== undefined) { c[k] = corps[k]; } });
  if (corps.offers) { c.offers = corps.offers.map(o => ({ type: o.type, id: +o.id, on: !!o.on, mediaId: o.mediaId == null ? null : +o.mediaId })); }
  if (corps.products) {
    c.products = corps.products.map(x => ({ id: +x.id, on: x.on !== false }));
    if (!corps.pages) {
      // Sans `pages` : la mise en page est gardée ; une catégorie ajoutée prend une page libre, sinon 2 pages de plus.
      const on = new Set(c.products.filter(x => x.on).map(x => x.id)), cats = [];
      PRODUITS.forEach(p => { if (on.has(p.id) && !cats.includes(p.categoryId)) { cats.push(p.categoryId); } });
      cats.forEach(k => {
        if (c.pages.some(p => +p.category === k)) { return; }
        const ids = remplir(c), libre = c.pages.findIndex((p, i) => !estPres(p.layout) && !ids[i].length);
        if (libre >= 0) { c.pages[libre] = page('grille', k); } else if (c.pageCount < 16) { c.pageCount += 2; c.pages.push(page('grille', k), page('grille', null)); }
      });
    }
  }
  if (corps.pageCount !== undefined) {
    c.pageCount = +corps.pageCount;
    // Sans `pages` : les pages vides de la fin partent (pagesARetirer a déjà dit qu'elles le sont).
    if (!corps.pages && c.pages.length > c.pageCount - 4) { c.pages = c.pages.slice(0, c.pageCount - 4); }
  }
  if (corps.pages) {
    if (!corps.pages.length) { repartir(c); } else { c.pages = corps.pages.map(p => page(p.layout, p.category == null ? null : +p.category, Object.assign({}, p))); }
  }
  while (c.pages.length < c.pageCount - 4) { c.pages.push(page('grille', null)); }
  c.updatedAt = '2026-11-19T15:40:00+01:00';
  return c;
}
/** Le refus d'un PUT avant d'écrire quoi que ce soit (la règle du Marketing) : `pageCount` pair de 8 à 16 ; réduit sans
 * `pages`, les pages qui partent (celles de la fin) doivent être vides — sinon 422 CATALOGUE_PAGES_NOT_EMPTY. */
function refusPut(c, corps) {
  if (corps.pageCount === undefined) { return null; }
  const n = +corps.pageCount;
  const refus = (code, message, details) => ({ __status: 422, json: { success: false, error: { code, message, details: details || [] } } });
  if (!(n >= 8 && n <= 16 && n % 2 === 0)) { return refus('VALIDATION_FAILED', 'Le nombre de pages est invalide.', [{ field: 'pageCount', message: 'Un nombre pair de 8 à 16.' }]); }
  if (corps.pages || n >= c.pageCount) { return null; }
  const ids = remplir(c), pleines = [];
  for (let i = n - 4; i < c.pages.length; i++) { if (estPres(c.pages[i].layout) || ids[i].length || c.pages[i].filler) { pleines.push(i + 3); } }
  if (!pleines.length) { return null; }
  return refus('CATALOGUE_PAGES_NOT_EMPTY', 'À ' + n + ' pages, ' + (pleines.length > 1 ? 'les pages ' + pleines.join(' et ') + ' portent' : 'la page ' + pleines[0] + ' porte') + ' des produits : rien n’a été retiré.');
}
const ok = (data, meta) => ({ success: true, data, meta: meta || {} });
const nonTrouve = () => ({ __status: 404, json: { success: false, error: { code: 'RESOURCE_NOT_FOUND', message: 'Catalogue introuvable.', details: [] } } });
/** Le lien permanent ; « Nouveau lien » en fait un autre (l'ancien jeton est révoqué). */
const JETONS = ['k7q2', 'm4x9', 'p2w6', 't8r3', 'b5n1', 'h3c7'];
const tours = {};
function creerLien(c) {
  const n = tours[c.id] = (tours[c.id] || 0) + 1, t = JETONS[(n - 1) % JETONS.length] + (n > JETONS.length ? n : '');
  return { url: 'https://catalogue.exemple.be/c/' + c.id + t, token: t + c.id, createdAt: '2026-11-19T15:40:00+01:00' };
}
const lien = c => liens[c.id] || (liens[c.id] = creerLien(c));
// Les catalogues déjà diffusés ont leur lien.
[5101, 9002].forEach(id => lien({ id }));

// La médiathèque du réseau et de ma boutique : des tuiles (un dessin du dépôt sur un fond de saison), /film/catalogue/media/<id>.svg.
const MEDIAS = [['noel', 'Sapin de Noël', '#8D1D2C'], ['nicolas', 'Saint-Nicolas', '#B0792A'], ['hiver', 'Flocon', '#2F5D8A'], ['buche', 'Gâteau sous cloche', '#7A4E8C'],
  ['cookies', 'Biscuits de fête', '#A0522D'], ['tresse', 'Brioche tressée', '#B0792A'], ['chocolat', 'Chocolat chaud', '#5C3A2E'], ['galette', 'Couronne', '#3f7a52']]
  .map(([d, name, fond], i) => ({ id: 801 + i, name, dessin: d, fond, url: ABS + 'media/' + (801 + i) + '.svg', thumbnailUrl: ABS + 'media/' + (801 + i) + '.svg' }));
/** Une tuile de la médiathèque : le fond de couleur, un halo crème, le dessin en blanc (filtre SVG). */
function tuileSvg(m) {
  if (!b64[m.dessin]) { const b = png(m.dessin); b64[m.dessin] = b ? b.toString('base64') : ''; }
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300" width="400" height="300"><defs><filter id="b"><feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 1 0"/></filter>'
    + '<radialGradient id="g" cx="50%" cy="45%" r="60%"><stop offset="0" stop-color="#fff" stop-opacity=".22"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient></defs>'
    + '<rect width="400" height="300" fill="' + m.fond + '"/><rect width="400" height="300" fill="url(#g)"/>'
    + '<image href="data:image/png;base64,' + b64[m.dessin] + '" x="90" y="20" width="220" height="260" filter="url(#b)"/></svg>';
}

module.exports = function (m, p, q, corps) {
  if (p.startsWith(BASE)) {
    // Les images : /film/catalogue/dessin/<nom>.png (les traits du dépôt), /film/catalogue/photo/<id>.svg.
    const d = /^\/film\/catalogue\/dessin\/([a-z]+)\.png$/.exec(p);
    if (d) { const b = png(d[1]); return b ? { __body: b, type: 'image/png' } : undefined; }
    const f = /^\/film\/catalogue\/photo\/(\d+)\.svg$/.exec(p);
    if (f && prod(f[1])) { return { __body: photoSvg(prod(f[1])), type: 'image/svg+xml' }; }
    const t = /^\/film\/catalogue\/media\/(\d+)\.svg$/.exec(p), m = t && MEDIAS.find(x => x.id === +t[1]);
    if (m) { return { __body: tuileSvg(m), type: 'image/svg+xml' }; }
    // La page d'impression du Marketing (lien signé) : l'onglet qu'ouvre « Télécharger le PDF » (pas filmé).
    if (p.startsWith(BASE + 'imprimer/')) { return { __body: '<!doctype html><meta charset="utf-8"><title>Catalogue</title><p style="font:15px system-ui;padding:24px">Catalogue prêt à imprimer.</p>', type: 'text/html' }; }
    return undefined;
  }
  if (p !== '/dashboard/catalogues' && !p.startsWith('/dashboard/catalogues/')) { return undefined; }
  const s = p.slice('/dashboard/catalogues'.length);
  const c0 = corps && typeof corps === 'object' ? corps : {};
  if (s === '/produits' && m === 'GET') {
    return ok({ products: PRODUITS.map(fiche), categories: CATEGORIES.map(c => ({ id: c.id, name: c.name, nameNl: c.nameNl })), gammes: GAMMES.map(g => ({ id: g.id, name: g.name, from: g.from, to: g.to })),
      offers: OFFRES.map(offreListe), offersError: null, pageCounts: [8, 10, 12, 14, 16] });
  }
  if (s === '/medias' && m === 'GET') { const t = (q.get('q') || '').toLowerCase(); return ok(MEDIAS.filter(x => !t || x.name.toLowerCase().includes(t)).map(x => ({ id: x.id, name: x.name, url: x.url, thumbnailUrl: x.thumbnailUrl }))); }
  if (s === '' && m === 'GET') { return ok(MOI.map(copie), { total: MOI.length }); }
  if (s === '' && m === 'POST') { return ok(copie(creer(c0))); }
  if (s === '/marque' && m === 'GET') { return ok(MARQUE.map(copie), { total: MARQUE.length }); }
  const r = /^(\/marque)?\/(\d+)(\/[a-z]+)?(\/[a-z0-9-]+)?$/.exec(s);
  if (!r) { return { __status: 404, json: { success: false, error: { code: 'RESOURCE_NOT_FOUND', message: 'Route inconnue.', details: [] } } }; }
  const marque = !!r[1], c = trouver(r[2], marque), suite = r[3] || '';
  if (!c) { return nonTrouve(); }
  if (suite === '' && m === 'GET') { return ok(copie(c)); }
  if (suite === '' && m === 'PUT' && !marque) { return refusPut(c, c0) || ok(copie(modifier(c, c0))); }
  if (suite === '' && m === 'DELETE' && !marque) { MOI.splice(MOI.indexOf(c), 1); return ok(null); }
  if (suite === '/document' && m === 'GET') { return ok(documentDe(c, q.get('lang') || 'fr')); }
  if (suite === '/verifications' && m === 'GET') { return ok(verifications(c, marque)); }
  if (suite === '/verifications' && m === 'PUT') { const code = (r[4] || '').slice(1); vus[c.id + '|' + code] = c0.seen === true; return ok({ code, seen: c0.seen === true }); }
  if (suite === '/lien' && m === 'GET') { return ok(liens[c.id] ? [liens[c.id]] : []); }
  if (suite === '/lien' && m === 'POST') { liens[c.id] = creerLien(c); return ok(liens[c.id]); }
  if (suite === '/impression' && m === 'POST') { return ok({ url: 'http://127.0.0.1:' + PORT + '/api/cockpit' + BASE + 'imprimer/' + c.id + '?lang=' + (c0.lang || 'fr') + '&sig=film', expiresAt: '2026-11-21T15:40:00+01:00' }); }
  if (suite === '/imprime' && m === 'POST') {
    const n = +c0.quantity || 0;
    c.print = { quantity: n, printedAt: '2026-11-19T15:40:00+01:00' };
    if (!marque) { c.status = 'imprime'; c.printQty = n; }
    return ok(copie(c));
  }
  if (marque && suite === '/reception' && m === 'PUT') { c.reception.status = c0.status === 'pris' ? 'pris' : c.reception.status; return ok(copie(c)); }
  if (marque && suite === '/adaptation' && m === 'POST') {
    c.reception.status = 'adapte';
    const n = Object.assign(copie(c), { id: prochain++, owner: 'boutique', status: 'brouillon', reception: undefined, print: undefined });
    delete n.reception; delete n.print;
    MOI.unshift(n);
    return ok(copie(n), { removedProducts: [] });
  }
  return { __status: 405, json: { success: false, error: { code: 'METHOD_NOT_ALLOWED', message: 'Méthode non permise.', details: [] } } };
};
