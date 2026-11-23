/* Vendre › Offres (vue=offres) : les routes lues et écrites par panel/public/dashboard/offres.js, aux formes de
 * panel/src/offres_client.php (offres normalisées par ocNormaliser), de marketing/src/promos_magasin.php (les produits
 * du magasin et leur coût de recette) et de marketing/src/promos_bons.php (les clients) ; l'onglet Newsletter : la forme
 * de marketing/src/newsletter.php (ep_newsletter) au rôle du magasin.
 * Huit offres fictives du Magasin démo (clients inventés, adresses à « Val-Fleuri », e-mails @exemple.be) ; les produits
 * et leurs prix sont ceux du catalogue de démo (lib/demo.js). Les écritures (créer, modifier, archiver, remettre en
 * cours, supprimer, convertir) changent l'état en mémoire : la relecture de la liste montre le changement. */
'use strict';
const D = require('./lib/demo');
const { r2 } = D;

/* ── les produits du magasin : prix TTC, TVA 6 %, coût de recette du magasin (ou du réseau pour deux d'entre eux) ── */
const RESEAU = new Set(['Jus d’orange pressé', 'Nougat (barre)']);
const PRODUITS = D.PRODUITS.map((p, i) => {
  const ht = p.prix / 1.06, cout = Math.round(ht * (1 - p.taux) * 10000) / 10000;
  return { id: p.pid, nom: p.nom, catId: 10 + Math.floor(i / 3), cat: p.cat, prixTtc: p.prix, prixHt: r2(ht), tva: 6, cout, source: RESEAU.has(p.nom) ? 'reseau' : 'magasin' };
});
const prod = nom => PRODUITS.find(p => p.nom === nom);

/* ── les clients (inventés) ── */
const CLIENTS = [
  { id: 7101, b2b: false, nom: 'Hélène Marchal', contact: '', tva: '', rue: 'Rue des Tilleuls 12', cp: '5000', ville: 'Val-Fleuri', email: 'helene.marchal@exemple.be', tel: '' },
  { id: 7102, b2b: false, nom: 'Famille Lambert', contact: '', tva: '', rue: 'Chemin du Moulin 4', cp: '5002', ville: 'Val-Fleuri', email: 'lambert.famille@exemple.be', tel: '' },
  { id: 7103, b2b: false, nom: 'Julien Dewit', contact: '', tva: '', rue: 'Place de l’Église 3', cp: '5004', ville: 'Bois-Joli', email: 'j.dewit@exemple.be', tel: '' },
  { id: 7104, b2b: false, nom: 'Sophie Renard', contact: '', tva: '', rue: 'Avenue des Prés 27', cp: '5000', ville: 'Val-Fleuri', email: 'sophie.renard@exemple.be', tel: '' },
  { id: 7105, b2b: false, nom: 'Marc Delvaux', contact: '', tva: '', rue: 'Rue Haute 51', cp: '5002', ville: 'Val-Fleuri', email: 'marc.delvaux@exemple.be', tel: '' },
  { id: 7201, b2b: true, nom: 'Bureau Horizon SRL', contact: 'Nathalie Fiévez', tva: 'BE0000.111.222', rue: 'Parc d’activités 8', cp: '5004', ville: 'Bois-Joli', email: 'accueil@horizon.exemple.be', tel: '' },
  { id: 7202, b2b: true, nom: 'Crèche Les Lutins', contact: 'Aline Mertens', tva: 'BE0000.333.444', rue: 'Rue des Écoles 2', cp: '5000', ville: 'Val-Fleuri', email: 'direction@lutins.exemple.be', tel: '' },
  { id: 7203, b2b: true, nom: 'École du Parc — comité des fêtes', contact: 'Karim Benali', tva: '', rue: 'Allée du Parc 1', cp: '5002', ville: 'Val-Fleuri', email: 'comite@ecoleduparc.exemple.be', tel: '' },
];
const cli = id => CLIENTS.find(c => c.id === id) || null;

/* ── les offres : [id, nom, description, client, jour, retrait, livraison, conditions, créée, état, lignes [produit, qté, remise %]] ── */
const LIV_HORIZON = { rue: 'Parc d’activités 8', cp: '5004', ville: 'Bois-Joli', frais: 15 };
const DEPART = [
  [418, 'Anniversaire 21/11 — 30 personnes', 'Buffet sucré-salé pour un anniversaire, à retirer samedi matin.', 7101, '2026-11-21', true, null, 'Acompte de 30 % · offre valable jusqu’au 20/11', '2026-11-17 10:42:00', 'cours',
    [['Mini-croissant', 40], ['Éclair au chocolat', 20], ['Tartelette aux fruits', 15], ['Sandwich jambon-beurre', 20, 10]]],
  [417, 'Petit-déjeuner d’équipe', 'Petit-déjeuner pour 30 personnes, livré à 8 h 15.', 7201, '2026-11-26', false, LIV_HORIZON, 'Paiement à 30 jours', '2026-11-16 15:05:00', 'cours',
    [['Croissant au beurre', 30], ['Pain au chocolat', 30], ['Jus d’orange pressé', 12], ['Café', 24]]],
  [416, 'Communion 06/12', 'Desserts pour une communion, 40 personnes.', 7102, '2026-12-06', true, null, 'Acompte de 30 %', '2026-11-14 11:20:00', 'cours',
    [['Entremets trois chocolats', 3], ['Fraisier 6 parts', 2], ['Tarte au citron meringuée', 12], ['Millefeuille', 10]]],
  [415, 'Marché de Noël de l’école', 'Viennoiseries et biscuits pour le stand du comité.', 7203, '2026-12-12', true, null, '', '2026-11-10 09:12:00', 'conv',
    [['Cookie chocolat', 60], ['Madeleines (sachet)', 20], ['Croissant au beurre', 40]]],
  [414, 'Goûter des enfants', 'Le goûter du vendredi.', 7202, '2026-11-13', false, { rue: 'Rue des Écoles 2', cp: '5000', ville: 'Val-Fleuri', frais: 0 }, 'Facture mensuelle', '2026-11-06 14:30:00', 'conv',
    [['Brioche au sucre', 24], ['Pain aux raisins', 24]]],
  [413, 'Baptême 08/11', 'Buffet salé.', 7103, '2026-11-08', true, null, '', '2026-10-29 17:45:00', 'arch',
    [['Wrap végétarien', 20], ['Sandwich poulet crudités', 20]]],
  [412, 'Séminaire de rentrée', 'Pause-café du matin et de l’après-midi.', 7201, '2026-11-05', false, LIV_HORIZON, 'Paiement à 30 jours', '2026-10-27 10:02:00', 'conv',
    [['Mini-croissant', 60], ['Cookie chocolat', 40], ['Café', 40], ['Eau plate 50 cl', 30]]],
  [411, 'Buffet de fin d’année', 'Devis demandé, pas de suite.', 7104, '2026-10-31', true, null, '', '2026-10-20 12:15:00', 'arch',
    [['Bowl quinoa-légumes', 12], ['Salade César', 12], ['Tarte aux pommes (part)', 24]]],
];

/** Une ligne au format de ocNormaliser : quantité, prix TTC, remise, totaux TTC et HT. */
function ligne(nom, q, pct, pu) {
  const p = prod(nom);
  const prix = pu != null ? pu : p.prixTtc, brut = q * prix, rm = pct ? Math.round(brut * pct) / 100 : 0, tot = r2(brut - rm);
  return { idProduit: p.id, nom: p.nom, qte: q, pu: prix, remiseValeur: pct || null, remiseMontant: rm, remiseType: pct ? 1 : null,
    total: tot, totalNet: r2(tot / 1.06), tva: 6, puNet: r2(prix / 1.06) };
}
function offre(o) {
  const lignes = o.lignes, totP = r2(lignes.reduce((a, l) => a + l.total, 0)), frais = o.retrait ? 0 : (o.livraison.frais || 0);
  return { id: o.id, shop: 4, nom: o.nom, description: o.description, clientId: o.client ? o.client.id : null, client: o.client,
    retrait: o.retrait, livraison: o.retrait ? { rue: '', cp: '', ville: '', frais: 0 } : o.livraison, conditions: o.conditions, jour: o.jour,
    cree: o.cree, statut: o.etat === 'conv' ? 1 : 0, actif: o.etat !== 'arch', etat: o.etat,
    lignes, totalProduits: totP, totalNet: lignes.length ? r2(lignes.reduce((a, l) => a + l.totalNet, 0)) : null, total: r2(totP + frais) };
}

/* ── l'état ── */
const S = {
  offres: DEPART.map(([id, nom, description, c, jour, retrait, livraison, conditions, cree, etat, L]) => ({
    id, nom, description, client: cli(c), jour, retrait, livraison: livraison || { rue: '', cp: '', ville: '', frais: 0 }, conditions, cree, etat,
    lignes: L.map(([n, q, pct]) => ligne(n, q, pct)) })),
  prochain: 419,
};
function liste() {
  const l = S.offres.map(offre).sort((a, b) => b.id - a.id);
  const n = { cours: 0, conv: 0, arch: 0 };
  l.forEach(o => { n[o.etat]++; });
  return { shop: '4', offres: l, n: Object.assign(n, { tout: l.length }), lu: '15:40' };
}
/** Le corps d'une offre envoyée par l'écran (creer, modifier) → l'offre gardée. */
function depuisCorps(o, b) {
  o.nom = String(b.nom || ''); o.description = String(b.description || ''); o.conditions = String(b.conditions || '');
  o.jour = String(b.jour || ''); o.retrait = b.retrait !== false;
  o.livraison = { rue: (b.livraison || {}).rue || '', cp: (b.livraison || {}).cp || '', ville: (b.livraison || {}).ville || '', frais: +((b.livraison || {}).frais || 0) };
  if (b.clientId) { o.client = cli(+b.clientId); }
  if (Array.isArray(b.lignes)) {
    o.lignes = b.lignes.map(l => {
      const p = PRODUITS.find(x => x.id === +l.idProduit) || prod(l.nom);
      const x = ligne(p.nom, +l.qte || 1, null, l.pu != null ? +l.pu : null);
      if (+l.remiseMontant > 0) { x.remiseMontant = +l.remiseMontant; x.remiseValeur = l.remiseValeur; x.remiseType = l.remiseType; x.total = r2(x.qte * x.pu - x.remiseMontant); x.totalNet = r2(x.total / 1.06); }
      return x;
    });
  }
}
function ecrire(b) {
  const a = b && b.action;
  if (a === 'creer') {
    const o = { id: S.prochain++, client: null, cree: '2026-11-19 15:40:00', etat: 'cours', lignes: [] };
    depuisCorps(o, b.offre || {}); S.offres.push(o);
    return { ok: true, id: o.id };
  }
  const o = S.offres.find(x => x.id === +b.id);
  if (!o) { return { __status: 404, json: { error: 'offre introuvable pour ce magasin' } }; }
  if (a === 'modifier') { depuisCorps(o, b.offre || {}); }
  else if (a === 'archiver') { o.etat = 'arch'; }
  else if (a === 'reactiver') { o.etat = 'cours'; }
  else if (a === 'convertir') { o.etat = 'conv'; }
  else if (a === 'supprimer') { S.offres = S.offres.filter(x => x !== o); }
  else { return { __status: 400, json: { error: 'action inconnue' } }; }
  return { ok: true, id: o.id };
}

/* ── la newsletter du magasin (rôle « 4 ») ── */
const NL = {
  test: true, role: '4', canSend: true, domaine: 'exemple.be', smtp: true, dispatch: false, reglages: null, lots: [], reel: true,
  moi: { id: '4', nom: D.NOM, kind: 'franchise', senderName: 'L’Atelier By · ' + D.NOM, email: 'magasin.demo@exemple.be', status: 'verified', canSend: true },
  sources: [
    { id: 'indiv', table: 'mar_nl_contact', name: { fr: 'Clients individuels', nl: 'Particuliere klanten', en: 'Individual customers' }, count: 1264, optin: 1037, sms: 612, reel: true },
    { id: 'b2b', table: 'mar_nl_contact', name: { fr: 'Clients B2B', nl: 'B2B-klanten', en: 'B2B clients' }, count: 48, optin: 43, sms: 12, reel: true },
  ],
  segments: [
    { id: 'tous-4', src: 'indiv', shop: '4', name: { fr: 'Tous les clients du magasin', nl: 'Alle klanten van de winkel', en: 'All shop customers' }, rule: { fr: 'clients opt-in du magasin' }, count: 1037, split: [846, 175, 16], createdBy: 'brand', reel: true },
    { id: 'dormant', src: 'indiv', shop: '', name: { fr: 'Pas d’achat depuis 45 jours', nl: 'Geen aankoop sinds 45 dagen', en: 'No purchase in 45 days' }, rule: { fr: 'dernier achat il y a plus de 45 jours' }, count: 214, split: [176, 34, 4], createdBy: 'brand', reel: true },
    { id: 'b2b-all', src: 'b2b', shop: '', name: { fr: 'Tous les comptes B2B actifs', nl: 'Alle actieve B2B-accounts', en: 'All active B2B accounts' }, rule: { fr: 'comptes B2B actifs' }, count: 43, split: [35, 8, 0], createdBy: 'brand', reel: true },
  ],
  campagnes: [
    { id: 31, name: 'Cougnou 2026 — ' + D.NOM, src: 'indiv', segment: 'tous-4', langs: ['fr', 'nl'], templateId: 'produit', channel: 'both', subjects: { fr: 'Le cougnou est de retour' },
      sendMode: 'manual', trigger: null, sendAt: '2026-12-01 08:30:00', maxVouchers: 30, sender: '4', status: 'sched', testSent: true, stats: {}, exemple: false, createdBy: '4' },
    { id: 27, name: 'Réveil 45 jours', src: 'indiv', segment: 'dormant', langs: ['fr', 'nl'], templateId: 'produit', channel: 'email', subjects: { fr: 'On ne vous a pas vu depuis un moment' },
      sendMode: 'auto', trigger: 'dormant45', sendAt: '2026-09-19 08:30:00', maxVouchers: 40, sender: '4', status: 'live', testSent: true, stats: { sent: 96, open: 51, click: 14, vouchers: 9 }, exemple: false, createdBy: 'brand' },
    { id: 22, name: 'La tarte du mois — octobre', src: 'indiv', segment: 'tous-4', langs: ['fr', 'nl'], templateId: 'gagne', channel: 'email', subjects: { fr: 'Votre part de tarte vous attend' },
      sendMode: 'manual', trigger: null, sendAt: '2026-10-08 08:30:00', maxVouchers: 30, sender: '4', status: 'sent', testSent: true, stats: { sent: 1003, open: 452, click: 118, vouchers: 23 }, exemple: false, createdBy: '4' },
    { id: 18, name: 'Petit-déjeuner au bureau', src: 'b2b', segment: 'b2b-all', langs: ['fr'], templateId: 'produit', channel: 'email', subjects: { fr: 'Le petit-déjeuner livré au bureau' },
      sendMode: 'manual', trigger: null, sendAt: '2026-09-15 08:30:00', maxVouchers: null, sender: '4', status: 'sent', testSent: true, stats: { sent: 41, open: 27, click: 9, vouchers: 0 }, exemple: false, createdBy: '4' },
  ],
};
NL.magasins = [NL.moi];
NL.chiffres = { optin: 1037 + 43, envoisMois: 0, vouchers: [32, 100] };

module.exports = function (m, p, q, c) {
  if (p === '/exploitation/offres-client') {
    if (m === 'GET') { return liste(); }
    if (m === 'POST') { return ecrire(c || {}); }
  }
  if (m !== 'GET') { return undefined; }
  if (p === '/exploitation/offres-client/employes') {
    return { shop: '4', employes: [{ id: 205, nom: 'Emma G.' }, { id: 206, nom: 'Fanny L.' }, { id: 201, nom: 'Camille R.' }, { id: 210, nom: 'Lucie B.' }].sort((a, b) => a.nom.localeCompare(b.nom, 'fr')) };
  }
  if (p === '/exploitation/offres-client/pdf') { return { __status: 404, json: { error: 'le PDF ne se rend pas dans la démo' } }; }
  if (p === '/exploitation/promos-magasin/produits') {
    return { shop: '4', produits: PRODUITS, nMagasin: PRODUITS.filter(x => x.source === 'magasin').length, nReseau: PRODUITS.filter(x => x.source === 'reseau').length, nSansCout: 0,
      seuils: { ko: 1.67, att: 2.5, food: 40 } };
  }
  if (p === '/exploitation/promos-magasin/clients') {
    const type = q.get('type') === 'b2b' ? 'b2b' : 'particulier', t = String(q.get('q') || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const L = CLIENTS.filter(x => x.b2b === (type === 'b2b')).filter(x => !t || [x.nom, x.ville, x.email, x.contact, x.tva].join(' ').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').includes(t))
      .map(x => ({ id: x.id, nom: x.nom, b2b: x.b2b, ville: x.ville, tva: x.tva, contact: x.contact ? x.contact.split(' ')[0] + ' ' + x.contact.split(' ')[1][0] + '.' : '' }))
      .sort((a, b) => a.nom.localeCompare(b.nom, 'fr'));
    return { shop: '4', type, total: CLIENTS.length, trouves: L.length, clients: L, lu: '15:38', ms: 120, nB2b: 3, nParticuliers: 5 };
  }
  if (p === '/dashboard/marketing/newsletter') { return NL; }
  return undefined;
};
