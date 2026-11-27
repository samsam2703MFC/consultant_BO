/* Vendre › Clients (vue=clients) : les routes lues et écrites par panel/public/dashboard/clients.js et tfb-magasin.js, aux
 * formes de panel/src/clients_panel.php (ep_clients_*, wr_clients_*), panel/src/bureaux_dev.php (ep_bureaux_dev, bdvLigne,
 * wr_bureaux_dev_*) et panel/src/tfb_magasin.php (la connexion du compte TF Buddy du magasin).
 *   GET  /dashboard/clients/particuliers | entreprises | fiche | analyse | bureaux?chemin=…
 *   POST /dashboard/clients/client {action} | tva | bureaux {methode, chemin, corps} | bureaux/pdf
 *   GET|POST /dashboard/bureaux-dev[/questionnaire|/valider|/refuser|/renvoyer|/tournees]
 *   POST /dashboard/tfb/connexion | confirmer | sms | renouveler | deconnexion ; GET /dashboard/tfb/etat
 * Le compte TF Buddy du magasin : la fausse API ne voit pas l'en-tête X-TFB-Jeton ; « connecté » est son état à elle,
 * posé par /dashboard/tfb/confirmer et retiré par /deconnexion. Connecté : les routes du panel répondent (entreprises
 * avec leur profil, bureaux, analyse du panel) ; sinon elles refusent comme TF Buddy refuse les comptes du cockpit
 * (pclRefusDit) et le module se rabat sur la liste de base et le relevé des tickets pro.
 * Tout est fictif : particuliers inventés (téléphones +32 400…, e-mails @exemple.be), sociétés inventées à « Val-Fleuri »
 * et « Bois-Joli », numéros de TVA BE0000…. Les écritures changent l'état en mémoire : la relecture montre le changement. */
'use strict';
const D = require('./lib/demo');
const { r2 } = D;
const SHOP = 4;
const NOW_S = Math.floor(Date.parse('2026-11-19T15:40:00+01:00') / 1000);
const err = (s, e, plus) => ({ __status: s, json: Object.assign({ error: e }, plus || {}) });

/* ── Le compte TF Buddy du magasin ── */
const TFB = { connecte: false };
const jetons = () => ({ acces: 'demo.jeton-acces-magasin-quatre.signature', renouvellement: 'demo-renouvellement-magasin-quatre', expireAcces: NOW_S + 3600, expireSession: NOW_S + 43200 });
function refusPanel(quoi) {
  const a = quoi.startsWith('les ') ? 'aux ' + quoi.slice(4) : quoi.startsWith('le ') ? 'au ' + quoi.slice(3) : 'à ' + quoi;
  return { ok: false, refus: true, quoi, motif: 'HTTP 404 : ORDER_NOT_FOUND', comptes: 'consultant (404) puis admin (404)', magasin: false, magasinExpire: false,
    texte: 'Les comptes du cockpit n’ont pas accès ' + a + ' dans TF Buddy. Connectez le compte TF Buddy du magasin (téléphone, PIN, code SMS) pour les lire et les modifier ici.' };
}

/* ── Les particuliers (générés, graine fixe) ── */
const PRENOMS = ['Hélène', 'Julien', 'Sophie', 'Marc', 'Claire', 'Pierre', 'Lucie', 'Karim', 'Nathalie', 'Aline', 'Olivier', 'Manon', 'Hugo', 'Emma', 'Louis', 'Chloé', 'Antoine', 'Laura', 'Mehdi', 'Pauline'];
const NOMS = ['Marchal', 'Dewit', 'Lambotte', 'Delvaux', 'Verhoeven', 'Gilson', 'Thiry', 'Benali', 'Fiévez', 'Mertens', 'Piron', 'Simon', 'Collard', 'Wauters', 'Hubert', 'Noël'];
const RUES = ['Rue des Tilleuls', 'Chemin du Moulin', 'Avenue des Prés', 'Rue Haute', 'Place de l’Église', 'Rue des Écoles', 'Allée du Parc', 'Rue du Château'];
let nextId = 8400;
const PART = [];
(function () {
  const r = D.alea('particuliers');
  for (let i = 0; i < 238; i++) {
    const p = PRENOMS[i % PRENOMS.length], n = NOMS[(i * 7 + Math.floor(i / 20)) % NOMS.length];
    const val = r() < 0.6;
    const j = 1 + Math.floor(r() * 680), cree = D.plus('2026-11-18', -j);
    PART.push({ id: 8100 + i, name: p, surname: n, phone: '+32 400 ' + String(10 + Math.floor(r() * 89)) + ' ' + String(10 + Math.floor(r() * 89)) + ' ' + String(10 + (i % 89)),
      email: r() < 0.7 ? (p + '.' + n).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '') + '@exemple.be' : '',
      street: RUES[Math.floor(r() * RUES.length)], street_number: String(1 + Math.floor(r() * 60)), zip: val ? '5000' : '5004', city: val ? 'Val-Fleuri' : 'Bois-Joli',
      comment: '', personal_discount_percent: r() < 0.08 ? 5 : null, can_deferral: false, create_timestamp: cree + ' 10:12:00', id_main_shop: r() < 0.9 ? SHOP : 7, is_b2b: false });
  }
  // Le récit : Benoît Lefèvre, menuisier, devient une entreprise ; Sophie Renard a une fiche en double, créée ici.
  PART.push({ id: 8390, name: 'Benoît', surname: 'Lefèvre', phone: '+32 400 21 43 65', email: 'benoit.lefevre@exemple.be', street: 'Rue du Château', street_number: '18', zip: '5000', city: 'Val-Fleuri',
    comment: 'Commande souvent pour son atelier', personal_discount_percent: null, can_deferral: false, create_timestamp: '2025-03-04 09:40:00', id_main_shop: SHOP, is_b2b: false });
  PART.push({ id: 8391, name: 'Sophie', surname: 'Renard', phone: '+32 400 55 12 08', email: 'sophie.renard@exemple.be', street: 'Avenue des Prés', street_number: '27', zip: '5000', city: 'Val-Fleuri',
    comment: '', personal_discount_percent: 5, can_deferral: false, create_timestamp: '2024-09-12 16:05:00', id_main_shop: SHOP, is_b2b: false });
  PART.push({ id: 8392, name: 'Sophie', surname: 'Renard', phone: '+32 400 55 12 08', email: '', street: '', street_number: '', zip: '', city: '',
    comment: 'Fiche créée au comptoir', personal_discount_percent: null, can_deferral: false, create_timestamp: '2026-11-18 11:20:00', id_main_shop: SHOP, is_b2b: false });
})();
const nomP = c => [c.name, c.surname].filter(Boolean).join(' ') || 'client n° ' + c.id;
function masque(tel) {
  const ch = String(tel || '').replace(/\D+/g, '');
  if (ch.length < 6) { return tel ? '••' : ''; }
  return (String(tel).trim().startsWith('+') ? '+' + ch.slice(0, 2) + ' ' + ch.slice(2, 3) : ch.slice(0, 3)) + '•• •• •• ' + ch.slice(-2);
}
function particuliers(q, filtre) {
  const L = PART.filter(c => !c.is_b2b);
  const mots = String(q || '').toLowerCase().split(/\s+/).filter(Boolean);
  const cle = c => [nomP(c), c.email, c.city, c.phone.replace(/\D+/g, '')].join(' ').toLowerCase();
  let t = L.filter(c => (filtre !== 'remise' || c.personal_discount_percent > 0) && mots.every(m => cle(c).includes(m) || (/^\+?\d{3,}$/.test(m) && cle(c).includes(m.replace(/^\+/, '').replace(/^0+/, '')))));
  t = mots.length ? t.sort((a, b) => nomP(a).localeCompare(nomP(b), 'fr')) : t.sort((a, b) => b.create_timestamp.localeCompare(a.create_timestamp) || b.id - a.id);
  return { shop: SHOP, total: L.length, trouves: t.length, q: q || '', filtre: filtre === 'remise' ? 'remise' : '', limite: 50, avecRemise: L.filter(c => c.personal_discount_percent > 0).length, lu: '15:38',
    clients: t.slice(0, 50).map(c => ({ id: c.id, nom: nomP(c), tel: masque(c.phone), email: !!c.email, ville: c.city, cp: c.zip, remise: c.personal_discount_percent, cree: c.create_timestamp.slice(0, 10) })) };
}

/* ── Les entreprises (clients B2B) : base, profil commercial, 12 mois, factures ── */
const ENT = [
  // id, société, TVA, rue, n°, cp, ville, tel, e-mail, score, type, mode, n12, ca12, dernier, cycle, différé, délai, crédit [actif, limite, utilisé], depuis
  [7201, 'Bureau Horizon SRL', 'BE0000.111.222', 'Parc d’activités', '8', '5004', 'Bois-Joli', '', 'accueil@horizon.exemple.be', 'A', 'standard', 'delivery', 214, 15840, '2026-11-19', 1, true, 30, [true, 3000, 1960], '2024-07-01'],
  [7202, 'Crèche Les Lutins', 'BE0000.333.444', 'Rue des Écoles', '2', '5000', 'Val-Fleuri', '+32 400 70 20 02', 'direction@lutins.exemple.be', 'A', 'standard', 'delivery', 226, 10410, '2026-11-19', 1, true, 30, [true, 1500, 640], '2024-09-02'],
  [7211, 'Fiduciaire du Val', 'BE0000.212.121', 'Rue Haute', '51', '5000', 'Val-Fleuri', '+32 400 70 21 11', 'secretariat@fiduval.exemple.be', 'B', 'standard', 'delivery', 92, 4780, '2026-11-19', 3, true, 30, [true, 1000, 410], '2025-01-13'],
  [7212, 'Atelier Numérique Bois-Joli', 'BE0000.414.141', 'Parc d’activités', '12', '5004', 'Bois-Joli', '', 'hello@atelier-numerique.exemple.be', 'B', 'standard', 'delivery', 168, 7420, '2026-11-19', 1, true, 30, [true, 1500, 1310], '2025-02-03'],
  [7213, 'Mutuelle du Val-Fleuri', 'BE0000.515.151', 'Avenue des Prés', '3', '5002', 'Val-Fleuri', '+32 400 70 21 13', 'accueil@mutuelle-val.exemple.be', 'B', 'standard', 'delivery', 118, 4950, '2026-11-18', 2, true, 30, [true, 1200, 380], '2025-04-22'],
  [7214, 'Résidence Les Glycines', 'BE0000.616.161', 'Chemin du Moulin', '20', '5002', 'Val-Fleuri', '+32 400 70 21 14', 'cuisine@glycines.exemple.be', 'A', 'hotel', 'delivery', 160, 5760, '2026-11-19', 1, true, 30, [true, 1500, 290], '2024-11-04'],
  [7215, 'Garage du Moulin', 'BE0000.717.171', 'Chemin du Moulin', '44', '5000', 'Val-Fleuri', '+32 400 70 21 15', '', 'C', 'standard', 'pickup', 21, 690, '2026-10-30', 14, false, null, null, '2025-06-10'],
  [7216, 'Studio Pixel & Cie', 'BE0000.818.181', 'Parc d’activités', '14', '5004', 'Bois-Joli', '', 'studio@pixel.exemple.be', 'C', 'standard', 'delivery', 38, 1260, '2026-11-12', 6, true, 15, [true, 500, 520], '2025-09-01'],
  [7217, 'Bureau d’études Arcade', 'BE0000.919.191', 'Parc d’activités', '3', '5004', 'Bois-Joli', '+32 400 70 21 17', 'contact@arcade.exemple.be', 'B', 'standard', 'delivery', 109, 4030, '2026-11-19', 2, true, 30, [true, 1000, 220], '2025-03-17'],
  [7218, 'Agence Clé d’Or', 'BE0000.121.212', 'Place de l’Église', '6', '5000', 'Val-Fleuri', '+32 400 70 21 18', 'agence@cledor.exemple.be', 'C', 'standard', 'both', 64, 1580, '2026-11-17', 4, false, null, null, '2025-05-05'],
  [7219, 'Pharmacie des Tilleuls', 'BE0000.232.323', 'Rue des Tilleuls', '1', '5000', 'Val-Fleuri', '+32 400 70 21 19', 'officine@tilleuls.exemple.be', 'D', 'standard', 'pickup', 4, 96, '2026-06-02', null, false, null, null, '2025-10-08'],
  [7220, 'Cabinet médical du Parc', 'BE0000.343.434', 'Allée du Parc', '9', '5004', 'Bois-Joli', '+32 400 70 21 20', 'secretariat@cabinetduparc.exemple.be', 'B', 'standard', 'delivery', 97, 2890, '2026-11-19', 2, true, 30, [true, 800, 160], '2025-02-24'],
  [7231, 'Traiteur Saveurs du Val', '', 'Rue Haute', '7', '5000', 'Val-Fleuri', '+32 400 70 21 31', '', 'C', 'restaurant', 'pickup', 12, 2140, '2026-11-06', 28, false, null, null, '2026-01-15'],
  [7232, 'Association des commerçants de Bois-Joli', 'BE0000.454.545', '', '', '', '', '', '', 'D', 'other', 'pickup', 3, 312, '2026-09-21', null, false, null, null, '2026-04-02'],
].map(([id, soc, tva, rue, num, cp, ville, tel, email, score, type, mode, n12, ca12, dernier, cycle, differe, delai, credit, depuis]) => ({
  id, is_b2b: true, company_name: soc, tax_number: tva, street: rue, street_number: num, zip: cp, city: ville, phone: tel, email, name: '', surname: '', comment: '', peppol_identifier: tva ? '0208:' + tva.replace(/\D/g, '') : '',
  can_deferral: differe, payment_terms: delai, personal_discount_percent: null, create_timestamp: depuis + ' 09:00:00', id_main_shop: SHOP,
  profil: { score, type, mode, creditActif: credit ? credit[0] : false, creditLimite: credit ? credit[1] : null, utilise: credit ? credit[2] : null, depuis }, n12, ca12, dernier, cycle }));
function manques(c) {
  const m = [];
  if (!c.company_name) { m.push('nom'); }
  if (!c.street || !c.city) { m.push('adresse'); }
  if (!c.tax_number) { m.push('tva'); }
  if (!c.email && !c.phone) { m.push('contact'); }
  return m;
}
const alerte = p => p == null ? null : p >= 100 ? 'danger' : p >= 80 ? 'attention' : p >= 50 ? 'moyen' : 'ok';
function credit(c) {
  const p = c.profil;
  if (!p.creditActif && !(p.creditLimite > 0)) { return null; }
  const pct = p.creditLimite > 0 && p.utilise != null ? Math.round(1000 * p.utilise / p.creditLimite) / 10 : null;
  return { actif: !!p.creditActif, limite: p.creditLimite, utilise: p.utilise, pct, alerte: alerte(pct) };
}
function ligneEnt(c, riche) {
  return { id: c.id, nom: c.company_name || 'client n° ' + c.id, tva: c.tax_number, ville: c.city, email: !!c.email, tel: !!c.phone,
    score: riche ? c.profil.score : null, type: riche ? c.profil.type : null, mode: riche ? c.profil.mode : null,
    n12: c.n12, ca12: c.ca12, panier: c.n12 ? r2(c.ca12 / c.n12) : null, dernier: c.dernier, premier: c.profil.depuis, cycle: c.cycle,
    recurrence: null, depuis: null, differe: c.can_deferral, credit: riche ? credit(c) : null, manques: manques(c) };
}
function entreprises() {
  const L = ENT.slice().sort((a, b) => b.ca12 - a.ca12 || a.company_name.localeCompare(b.company_name, 'fr'));
  if (TFB.connecte) {
    const l = L.map(c => ligneEnt(c, true));
    return { shop: SHOP, source: 'panel', compte: 'magasin', entreprises: l, total: l.length, incompletes: l.filter(c => c.manques.length).length };
  }
  const l = L.map(c => ligneEnt(c, false));
  return { shop: SHOP, source: 'base', panel: refusPanel('le profil commercial des entreprises (score, crédit, récurrence)'), entreprises: l, total: l.length,
    incompletes: l.filter(c => c.manques.length).length, lu: '15:38',
    releve: { jours: 365, lus: 365, texte: 'Fréquence et CA sur 12 mois : les tickets pro de la caisse (fiches avec une TVA valide), 365 jours lus sur 365.' } };
}
const CHAMPS = ['id', 'id_main_shop', 'is_b2b', 'name', 'surname', 'company_name', 'tax_number', 'can_deferral', 'street', 'street_number', 'city', 'zip', 'comment', 'phone', 'email',
  'payment_terms', 'personal_discount_percent', 'create_timestamp', 'peppol_identifier'];
function fiche(id) {
  const e = ENT.find(x => x.id === id), p = PART.find(x => x.id === id);
  const c = e || p;
  if (!c) { return err(404, 'ce client n’est pas un client de ce magasin dans TF Buddy'); }
  const client = {}; CHAMPS.forEach(k => { client[k] = c[k] == null ? null : c[k]; });
  client.nom = e ? (e.company_name || 'client n° ' + e.id) : nomP(p);
  if (!e) { return { shop: SHOP, id, b2b: false, client, manques: [], profil: null }; }
  const r = D.alea('fac' + id), fac = [];
  let d = '2026-11-16';
  for (let i = 0; i < 8; i++) {
    const m = r2(e.ca12 / 26 * (0.8 + r() * 0.4)), payee = i >= 2 || !e.can_deferral;
    fac.push({ id: 66000 + id % 100 * 10 + i, date: d, echeance: D.plus(d, e.payment_terms || 0), montant: m, payee, differee: !!e.can_deferral, peppol: 'delivered' });
    d = D.plus(d, -14);
  }
  // Les impayées font le crédit utilisé (pclCredit : ce qui reste impayé) : la fiche et la liste disent le même chiffre.
  if (e.profil.utilise != null && e.can_deferral) { const a = r2(e.profil.utilise * 0.55); fac[0].montant = a; fac[1].montant = r2(e.profil.utilise - a); }
  const imp = fac.filter(x => !x.payee);
  const out = { shop: SHOP, id, b2b: true, client, manques: manques(e),
    profil: { score: e.profil.score, type: e.profil.type, mode: e.profil.mode, creditActif: e.profil.creditActif, creditLimite: e.profil.creditLimite, statut: 'active', depuis: e.profil.depuis },
    factures: { n: fac.length, impayees: imp.length, impaye: r2(imp.reduce((a, x) => a + x.montant, 0)), liste: fac }, credit: credit(e),
    releve: { ca: e.ca12, n: e.n12, premier: e.profil.depuis, dernier: e.dernier, cycle: e.cycle } };
  if (TFB.connecte && e.dernier) {
    out.commandes = [0, 1, 2, 3, 4, 5].map(i => ({ id: 52000 + id % 100 * 10 + i, date: D.plus(e.dernier, -i * Math.max(1, e.cycle || 7)), montant: r2(e.ca12 / Math.max(1, e.n12) * (0.85 + r() * 0.3)), statut: 'picked_up', texte: '' }));
  } else { out.commandes = null; out.commandesMotif = 'TF Buddy ne donne pas les commandes d’une entreprise aux comptes du cockpit.'; }
  return out;
}

/* ── Les écritures sur un client ── */
function ecrireClient(b) {
  const ch = b.champs || {}, id = +b.id || 0;
  const coord = (ks) => { const o = {}; ks.forEach(k => { if (k in ch) { const v = String(ch[k] == null ? '' : ch[k]).trim(); o[k] = v || null; } }); return o; };
  if (b.action === 'creer') {
    if (b.entreprise) {
      const tva = String(ch.tax_number || '').replace(/[\s.\-]+/g, '').toUpperCase();
      if (!/^[A-Z]{2}[0-9A-Z]{6,14}$/.test(tva)) { return err(422, 'un numéro de TVA (BE0123456789) est attendu'); }
      const x = Object.assign({ id: nextId++, is_b2b: true, name: '', surname: '', comment: '', peppol_identifier: '', can_deferral: false, payment_terms: null, personal_discount_percent: null,
        create_timestamp: '2026-11-19 15:41:00', id_main_shop: SHOP, profil: { score: null, type: null, mode: null, creditActif: false, creditLimite: null, utilise: null, depuis: '2026-11-19' }, n12: 0, ca12: 0, dernier: null, cycle: null },
      { company_name: ch.company_name || '', tax_number: String(ch.tax_number).trim(), street: ch.street || '', street_number: ch.street_number || '', zip: ch.zip || '', city: ch.city || '', phone: ch.phone || '', email: ch.email || '' });
      ENT.push(x);
      return { ok: true, id: x.id, cree: true, rattache: true, compte: 'magasin' };
    }
    if (!String(ch.surname || '').trim() || !String(ch.phone || '').trim()) { return err(422, 'le nom et le téléphone sont obligatoires'); }
    const x = Object.assign({ id: nextId++, is_b2b: false, comment: '', personal_discount_percent: null, can_deferral: false, create_timestamp: '2026-11-19 15:41:00', id_main_shop: SHOP },
      { name: ch.name || '', surname: ch.surname || '', phone: ch.phone || '', email: ch.email || '', street: ch.street || '', street_number: ch.street_number || '', zip: ch.zip || '', city: ch.city || '', comment: ch.comment || '' });
    PART.push(x);
    return { ok: true, id: x.id, cree: true, rattache: true, compte: 'magasin' };
  }
  const e = ENT.find(x => x.id === id), p = PART.find(x => x.id === id), c = e || p;
  if (!c) { return err(404, 'ce client n’est pas un client de ce magasin dans TF Buddy'); }
  if (b.action === 'modifier') {
    const o = coord(['name', 'surname', 'company_name', 'tax_number', 'street', 'street_number', 'city', 'zip', 'comment', 'phone', 'email', 'peppol_identifier']);
    if (o.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(o.email)) { return err(422, 'e-mail invalide'); }
    Object.keys(o).forEach(k => { c[k] = o[k] == null ? '' : o[k]; });
  } else if (b.action === 'profil') {
    if (!e) { return err(422, 'le profil commercial est celui d’une entreprise'); }
    if ('score' in ch) { e.profil.score = ch.score || null; }
    if ('type' in ch) { e.profil.type = ch.type || null; }
    if ('mode' in ch) { e.profil.mode = ch.mode || null; }
    if ('creditActif' in ch) { e.profil.creditActif = !!ch.creditActif; }
    if ('creditLimite' in ch) { e.profil.creditLimite = ch.creditLimite === '' || ch.creditLimite == null ? null : +ch.creditLimite; }
  } else if (b.action === 'entreprise') {
    if (e) { return err(409, 'ce client est déjà une entreprise'); }
    const tva = String(ch.tax_number || '').replace(/[\s.\-]+/g, '').toUpperCase();
    if (!/^[A-Z]{2}[0-9A-Z]{6,14}$/.test(tva) || !String(ch.company_name || '').trim()) { return err(422, 'la société et son numéro de TVA sont attendus'); }
    PART.splice(PART.indexOf(p), 1);
    ENT.push(Object.assign({}, p, { is_b2b: true, name: '', surname: '', company_name: String(ch.company_name).trim(), tax_number: String(ch.tax_number).trim(),
      street: ch.street || p.street, street_number: ch.street_number || p.street_number, zip: ch.zip || p.zip, city: ch.city || p.city,
      can_deferral: !!ch.differe, payment_terms: ch.delai ? +ch.delai : null, personal_discount_percent: ch.remise ? +ch.remise : null,
      profil: { score: null, type: null, mode: null, creditActif: false, creditLimite: null, utilise: null, depuis: '2026-11-19' }, n12: 31, ca12: 412.6, dernier: '2026-11-14', cycle: 21 }));
  } else if (b.action === 'supprimer') {
    if (+c.id_main_shop !== SHOP) { return err(409, 'ce client a été créé par un autre magasin : il ne s’efface pas d’ici'); }
    if (p) { PART.splice(PART.indexOf(p), 1); } else { ENT.splice(ENT.indexOf(e), 1); }
  } else { return err(400, 'action inconnue'); }
  return { ok: true, id, action: b.action, compte: TFB.connecte ? 'magasin' : 'consultant' };
}
const VIES = { BE0000555666: { company_name: 'Menuiserie Lefèvre SRL', street: 'Rue du Château', street_number: '18', zip: '5000', city: 'Val-Fleuri' },
  BE0000777888: { company_name: 'Cabinet d’architectes Ligne Claire', street: 'Allée du Parc', street_number: '15', zip: '5004', city: 'Bois-Joli' },
  BE0000999000: { company_name: 'Coworking Les Ateliers du Val', street: 'Rue Haute', street_number: '27', zip: '5000', city: 'Val-Fleuri' } };
function tva(b) {
  const t = String(b.tva || '').replace(/[\s.\-]+/g, '').toUpperCase();
  if (!/^[A-Z]{2}[0-9A-Z]{6,14}$/.test(t)) { return err(422, 'un numéro de TVA (BE0123456789) est attendu'); }
  const ex = ENT.find(x => x.tax_number.replace(/\W/g, '').toUpperCase() === t);
  if (ex) { return { ok: true, tva: t, trouve: true, source: 'tfbuddy', existe: true, rattache: true, societe: { id: ex.id, company_name: ex.company_name, tax_number: ex.tax_number, street: ex.street, street_number: ex.street_number, city: ex.city, zip: ex.zip } }; }
  const v = VIES[t];
  return { ok: true, tva: t, trouve: !!v, source: 'vies', existe: false, rattache: false, societe: v ? Object.assign({ tax_number: t.slice(0, 2) + t.slice(2, 6) + '.' + t.slice(6, 9) + '.' + t.slice(9) }, v) : {} };
}

/* ── Les bureaux (les offices du panel du magasin) ── */
const ROUTES = [{ id: 31, name: 'Matin · Val-Fleuri', start_time: '07:15:00' }, { id: 32, name: 'Midi · Bois-Joli – Parc d’activités', start_time: '11:15:00' }, { id: 33, name: 'Fin de journée · Val-Fleuri', start_time: '16:30:00' }];
let nextOffice = 960;
const BUR = [
  // id, nom, société, statut, catalogue, offres, départements, contact, jours, route, créneau, sites [[nom, adresse, cp, ville, accès]], personnes, lien
  [951, 'Horizon · Bâtiment B', 7201, 'active', 'restricted', 2, 3, 'Nathalie Fiévez', [1, 2, 3, 4, 5], 32, ['11:30', '12:00'], [['Accueil bâtiment B', 'Parc d’activités 8', '5004', 'Bois-Joli', 5]], 38, true],
  [952, 'Crèche Les Lutins', 7202, 'active', 'full', 1, 2, 'Aline Mertens', [1, 2, 3, 4, 5], 31, ['07:15', '07:30'], [['Cuisine', 'Rue des Écoles 2', '5000', 'Val-Fleuri', 3]], 12, false],
  [953, 'Fiduciaire du Val', 7211, 'active', 'full', 0, 1, 'Olivier Piron', [1, 2, 3, 4, 5], 31, ['08:00', '08:20'], [['Accueil, 2e étage', 'Rue Haute 51', '5000', 'Val-Fleuri', 6]], 9, false],
  [954, 'Atelier Numérique · Lunchroom', 7212, 'active', 'restricted', 1, 2, 'Manon Simon', [1, 2, 3, 4, 5], 32, ['11:40', '12:00'], [['Lunchroom, 1er étage', 'Parc d’activités 12', '5004', 'Bois-Joli', 4]], 22, true],
  [955, 'Mutuelle du Val-Fleuri', 7213, 'active', 'full', 0, 1, 'Pauline Collard', [1, 2, 3, 4], 33, ['16:30', '17:00'], [['Accueil', 'Avenue des Prés 3', '5002', 'Val-Fleuri', 5]], 15, false],
  [956, 'Résidence Les Glycines', 7214, 'active', 'full', 1, 3, 'Louis Hubert', [1, 2, 3, 4, 5], 31, ['07:40', '08:00'], [['Cuisine centrale', 'Chemin du Moulin 20', '5002', 'Val-Fleuri', 8]], 6, false],
  [957, 'Bureau d’études Arcade', 7217, 'active', 'full', 0, 1, 'Antoine Gilson', [1, 2, 3, 4, 5], 32, ['11:55', '12:10'], [['Accueil', 'Parc d’activités 3', '5004', 'Bois-Joli', 3]], 11, false],
  [958, 'Cabinet médical du Parc', 7220, 'active', 'restricted', 1, 1, 'Laura Thiry', [1, 2, 3, 4, 5], 32, ['12:05', '12:20'], [['Entrée du personnel', 'Allée du Parc 9', '5004', 'Bois-Joli', 4]], 8, false],
  [959, 'Studio Pixel & Cie', 7216, 'pending', 'full', 0, 0, 'Hugo Collard', [1, 2, 3, 4, 5], null, null, [], 0, false],
].map(([id, nom, soc, statut, cat, offres, deps, contact, jours, route, creneau, sites, personnes, lien]) => ({ id, nom, soc, statut, cat, offres, deps, contact, jours, route, creneau,
  sites: sites.map(([name, address, postal_code, city, access_minutes], i) => ({ id: id * 10 + i, name, address, postal_code, city, access_minutes })), personnes, lien, cutoff: ['16:00', 1], drop: 5 }));
const ent = id => ENT.find(x => x.id === id) || {};
function bureauLigne(b) {
  const e = ent(b.soc);
  return { id: b.id, nom: b.nom, societe: e.company_name || '', idSociete: b.soc, tva: e.tax_number || '', ville: b.sites[0] ? b.sites[0].city : (e.city || ''),
    adresse: b.sites[0] ? b.sites[0].address + ' ' + b.sites[0].postal_code : '', contact: b.contact, statut: b.statut, catalogue: b.cat, sites: b.sites.length, departements: b.deps,
    offres: b.offres, catalogues: 1, siteDefaut: b.sites[0] ? b.sites[0].id : 0, jours: b.jours, prix: true, facturation: b.soc === 7201 || b.soc === 7214 ? 'company' : 'person' };
}
const MEMBRES = ['Julie', 'Thomas', 'Sarah', 'Nicolas', 'Camille', 'Maxime', 'Léa', 'Romain', 'Inès', 'Arthur', 'Zoé', 'Nathan', 'Jade', 'Victor', 'Lina', 'Adam', 'Rose', 'Noah', 'Alice', 'Sacha'];
const DEPS = ['Direction', 'Comptabilité', 'Production', 'Accueil', 'Informatique', 'Commercial'];
/** Les adresses que TF Buddy propose (fictives) : [id, rue, numéro, code postal, ville]. */
const GEO = [['g1', 'Allée du Parc', '15', '5004', 'Bois-Joli'], ['g2', 'Allée du Parc', '1', '5002', 'Val-Fleuri'], ['g3', 'Rue du Château', '18', '5000', 'Val-Fleuri'],
  ['g4', 'Parc d’activités', '20', '5004', 'Bois-Joli'], ['g5', 'Rue Haute', '9', '5000', 'Val-Fleuri'], ['g6', 'Place de l’Église', '6', '5000', 'Val-Fleuri'],
  ['g7', 'Parc d’activités', '14', '5004', 'Bois-Joli'], ['g8', 'Rue Haute', '27', '5000', 'Val-Fleuri'], ['g9', 'Rue Haute', '52', '5000', 'Val-Fleuri']];
function bureauLire(ch, q) {
  if (ch === '') { return { bureaux: BUR.map(bureauLigne) }; }
  if (ch === '/options') {
    return { routes: ROUTES, companies: ENT.filter(e => e.tax_number).map(e => ({ id: e.id, name: e.company_name, tax_number: e.tax_number })).sort((a, b) => a.name.localeCompare(b.name, 'fr')),
      delivery_sites: BUR.flatMap(b => b.sites.map(s => ({ id: s.id, name: s.name, address: s.address, postal_code: s.postal_code, city: s.city, country: 'BE', route_id: b.route }))).slice(0, 6),
      department_presets: ['Direction', 'Comptabilité', 'Accueil', 'Informatique', 'Commercial', 'Production'],
      vouchers: [{ id: 501, code: 'BIENVENUE-BUREAU', name: 'Un café offert' }, { id: 502, code: 'PTITDEJ10', name: 'Petit-déjeuner −10 %' }] };
  }
  if (ch === '/geo/autocomplete') {
    const sans = t => String(t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[’']/g, ' ');
    const mots = sans(q.get('query') || '').split(/\s+/).filter(Boolean);
    return { available: true, suggestions: GEO.filter(g => mots.every(m => sans(g[1] + ' ' + g[2] + ' ' + g[3] + ' ' + g[4]).includes(m)))
      .map(g => ({ id: g[0], label: g[1] + ' ' + g[2] + ', ' + g[3] + ' ' + g[4] })).slice(0, 4) };
  }
  if (ch === '/geo/place') {
    const P = GEO.find(g => g[0] === q.get('id'));
    return P ? { place: { verified: true, street: P[1], street_number: P[2], postal_code: P[3], city: P[4], country: 'BE', place_id: 'demo-' + P[0], lat: 50.4, lng: 4.4 } } : { place: null };
  }
  if (ch === '/geo/postcodes') { const cp = q.get('postal_code'); return { localities: cp === '5004' ? [{ city: 'Bois-Joli' }] : cp === '5000' || cp === '5002' ? [{ city: 'Val-Fleuri' }] : [] }; }
  const m = /^\/(\d+)(\/.*)?$/.exec(ch);
  const b = m && BUR.find(x => x.id === +m[1]);
  if (!b) { return null; }
  const s = m[2] || '';
  if (s === '') { return { offers: b.offres ? [{ id: 71, name: 'Plateau petit-déjeuner', discount_percent: 10, valid_to: '2026-12-31' }, { id: 72, name: 'Lunch du vendredi', discount_percent: 5, valid_to: '2027-03-31' }].slice(0, b.offres) : [] }; }
  if (s === '/delivery-settings') {
    if (!b.sites.length) { return 'SITE'; }
    const r = ROUTES.find(x => x.id === b.route);
    return { route: r ? { id: r.id, name: r.name } : null, route_id: b.route, delivery_window_start: b.creneau ? b.creneau[0] + ':00' : null, delivery_window_end: b.creneau ? b.creneau[1] + ':00' : null,
      cutoff_time: b.cutoff[0] + ':00', cutoff_days_before: b.cutoff[1], drop_minutes: b.drop };
  }
  if (s === '/sites') { return { sites: b.sites }; }
  if (s === '/assortment') { return { mode: b.cat, products: b.cat === 'restricted' ? D.PRODUITS.slice(0, 24).map(p => p.pid) : [] }; }
  if (s === '/members') {
    const r = D.alea('m' + b.id);
    return { members: Array.from({ length: b.personnes }, (_, i) => ({ id: 3000 + i, name: MEMBRES[(i * 3 + b.id) % MEMBRES.length], surname: NOMS[(i * 5 + b.id) % NOMS.length].slice(0, 1) + '.', department_name: b.deps ? DEPS[Math.floor(r() * Math.min(DEPS.length, b.deps + 1))] : '' })) };
  }
  if (s === '/invite') { return { invite: b.lien ? { url: 'https://commande.exemple.be/bureau/' + b.id + '/rejoindre', expires_at: '2026-12-31 23:59:00', uses: Math.round(b.personnes * 0.6) } : null }; }
  return {};
}
function bureauEcrire(m, ch, c) {
  c = c || {};
  if (m === 'POST' && ch === '/onboard') {
    const o = c.office || {};
    const b = { id: nextOffice++, nom: o.name || 'Bureau', soc: +o.id_company_client, statut: o.status || 'active', cat: 'full', offres: 0, deps: (c.departments || []).length, contact: (c.contact || {}).name || '',
      jours: o.delivery_days || [1, 2, 3, 4, 5], route: (c.sites || [])[0] ? (c.sites[0].route_id || null) : null, creneau: o.delivery_window_start ? [o.delivery_window_start, o.delivery_window_end] : null,
      sites: (c.sites || []).map((s, i) => ({ id: nextOffice * 10 + i, name: s.name, address: s.address, postal_code: s.postal_code, city: s.city, access_minutes: s.access_minutes })),
      personnes: (c.staff || []).length, lien: false, cutoff: ['16:00', 1], drop: 5 };
    BUR.push(b);
    return { office: { id: b.id } };
  }
  const mm = /^\/(\d+)(\/.*)?$/.exec(ch);
  const b = mm && BUR.find(x => x.id === +mm[1]);
  if (!b) { return null; }
  const s = mm[2] || '';
  if (m === 'POST' && s === '/sites') { const n = { id: b.id * 10 + b.sites.length, name: c.name, address: c.address, postal_code: c.postal_code, city: c.city, access_minutes: c.access_minutes }; b.sites.push(n); if (c.route_id) { b.route = c.route_id; } if (!b.creneau) { b.creneau = null; } return n; }
  if (m === 'PUT' && s === '/delivery-settings') {
    b.route = c.route_id || null; b.creneau = c.delivery_window_start ? [String(c.delivery_window_start).slice(0, 5), String(c.delivery_window_end || '').slice(0, 5)] : null;
    b.cutoff = [String(c.cutoff_time || '16:00').slice(0, 5), +c.cutoff_days_before || 0]; b.drop = +c.drop_minutes || 5; return { ok: true };
  }
  if (m === 'POST' && s === '/members/invite') { return { invited: true }; }
  if (m === 'POST' && s === '/invite') { b.lien = true; return { ok: true }; }
  if (m === 'DELETE' && s === '/invite') { b.lien = false; return { ok: true }; }
  return { ok: true };
}

/* ── Les bureaux en développement (bureaux_dev.php) ── */
let nextDev = 14;
const PROMOS = [{ id: 31, nom: 'Bienvenue au bureau : un café offert', description: 'Un café offert à la première commande', enCaisse: true, campagne: 81 },
  { id: 32, nom: 'Petit-déjeuner d’équipe −10 %', description: '', enCaisse: false, campagne: 82 }];
const QUEST = { actif: true, jeton: '0f3c9a7e5b2d4c6a8e1f0b3d5c7a9e2f', promo: 31, titre: 'Du frais au bureau', message: 'Dites-nous où vous travaillez : nous livrons votre bureau, et votre première commande est récompensée.' };
const DEV = [
  { id: 13, source: 'questionnaire', statut: 'a_valider', le: '2026-11-19 09:12', bureau: 'Ligne Claire · Atelier', societe: 'Cabinet d’architectes Ligne Claire', tva: 'BE0000.777.888',
    personne: 'Chloé Noël', fonction: 'Office manager', email: 'chloe.noel@exemple.be', tel: '+32 400 63 21 09', adresse: 'Allée du Parc 15', cp: '5004', ville: 'Bois-Joli',
    personnes: 14, jours: [1, 2, 3, 4, 5], remarque: 'Une réunion d’équipe chaque mardi matin.', clientId: 8501, societeId: 8502, bon: { code: 'BIENVENUE-BUREAU', texte: 'Un café offert à la première commande' }, erreurs: [], officeId: null, traite: null },
  { id: 12, source: 'dashboard', statut: 'a_valider', le: '2026-11-18 16:47', bureau: 'Salle de sport Énergie', societe: 'Salle de sport Énergie', tva: '',
    personne: 'Mehdi Benali', fonction: 'Gérant', email: 'mehdi.benali@exemple.be', tel: '+32 400 63 44 71', adresse: 'Parc d’activités 20', cp: '5004', ville: 'Bois-Joli',
    personnes: 6, jours: [1, 3, 5], remarque: '', clientId: 8503, societeId: null, bon: null, erreurs: [], officeId: null, traite: null },
  { id: 11, source: 'questionnaire', statut: 'cree', le: '2026-11-10 08:30', bureau: 'Bureau d’études Arcade', societe: 'Bureau d’études Arcade', tva: 'BE0000.919.191',
    personne: 'Antoine Gilson', fonction: 'Assistant de direction', email: 'antoine.gilson@exemple.be', tel: '+32 400 63 10 17', adresse: 'Parc d’activités 3', cp: '5004', ville: 'Bois-Joli',
    personnes: 11, jours: [1, 2, 3, 4, 5], remarque: '', clientId: 8410, societeId: 7217, bon: { code: 'BIENVENUE-BUREAU', texte: 'Un café offert à la première commande' }, erreurs: [], officeId: 957, traite: '2026-11-10 14:02' },
  { id: 10, source: 'questionnaire', statut: 'refuse', le: '2026-11-04 19:55', bureau: 'Test', societe: 'Test', tva: '',
    personne: 'Arthur Wauters', fonction: '', email: 'arthur.wauters@exemple.be', tel: '+32 400 63 00 01', adresse: 'Rue Haute 1', cp: '5000', ville: 'Val-Fleuri',
    personnes: 0, jours: [], remarque: '', clientId: 8411, societeId: null, bon: null, erreurs: [], officeId: null, traite: '2026-11-05 08:10' },
];
// Les demandes hors zone (27/11/2026) : la copie de « Ma zone n'est pas dans la liste », rangée à ce magasin (fictives).
DEV.push(
  { id: 15, source: 'hors_zone', statut: 'hors_zone', le: '2026-11-19 11:05', bureau: '', societe: 'Imprimerie du Val', tva: '', personne: '', fonction: '', email: 'contact@exemple.be', tel: '',
    adresse: '', cp: '5003', ville: 'Val-Fleuri', personnes: 22, jours: [], remarque: '', clientId: null, societeId: null, bon: null, erreurs: [], officeId: null, traite: '2026-11-19 11:05',
    d: { origine: 'landing', distance: 7.4, _tfbuddy: 'ok' } },
  { id: 16, source: 'hors_zone', statut: 'hors_zone', le: '2026-11-19 14:31', bureau: '', societe: 'Cabinet Bois-Joli', tva: '', personne: '', fonction: '', email: 'accueil@exemple.be', tel: '',
    adresse: '', cp: '5004', ville: 'Bois-Joli', personnes: 0, jours: [], remarque: '', clientId: null, societeId: null, bon: null, erreurs: ['TF Buddy n’a pas pris la demande (HTTP 429 DELIVERY_INTEREST_RATE_LIMIT)'], officeId: null, traite: '2026-11-19 14:31',
    d: { origine: 'webshop', distance: 12.9, _tfbuddy: 'refus' } });
// Les tournées que le webshop publie pour ce magasin (GET /dashboard/bureaux-dev/tournees, ep_bureaux_dev_tournees).
const TOURNEES = [{ id: 1, nom: 'Val-Fleuri centre', zone: 'Val-Fleuri, Bois-Joli', principale: 'Magasin démo', detail: true, jours: [1, 2, 3, 4, 5], de: '11:30', a: '12:30', limite: '10:30', preavis: 0, creneaux: ['11:30–12:30'] }];
const devEtat = () => ({ shop: String(SHOP), demandes: DEV.slice().sort((a, b) => (b.statut === 'a_valider') - (a.statut === 'a_valider') || b.id - a.id).map(x => JSON.parse(JSON.stringify(x))),
  questionnaire: Object.assign({}, QUEST), promos: PROMOS });
function devEcrire(p, b) {
  if (p === '/dashboard/bureaux-dev') {
    const d = b.donnees || {}, e = [];
    if (!String(d.prenom || '').trim() || !String(d.nom || '').trim()) { e.push('votre prénom et votre nom'); }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(d.email || '').trim())) { e.push('un e-mail valide'); }
    if (!String(d.tel || '').trim()) { e.push('un numéro de téléphone'); }
    if (!String(d.societe || '').trim()) { e.push('le nom de la société'); }
    if (!String(d.adresse || '').trim() || !String(d.ville || '').trim()) { e.push('l’adresse du bureau (rue et ville)'); }
    if (e.length) { return err(422, 'Il manque ' + e.join(', ') + '.'); }
    const promo = b.bon && QUEST.promo ? PROMOS.find(x => x.id === QUEST.promo) : null;
    const x = { id: nextDev++, source: 'dashboard', statut: 'a_valider', le: '2026-11-19 15:41', bureau: String(d.societe).trim(), societe: String(d.societe).trim(), tva: String(d.tva || '').trim(),
      personne: (d.prenom + ' ' + d.nom).trim(), fonction: d.fonction || '', email: String(d.email).toLowerCase(), tel: d.tel, adresse: d.adresse, cp: d.cp || '', ville: d.ville,
      personnes: +d.personnes || 0, jours: [], remarque: d.remarque || '', clientId: nextId++, societeId: d.tva ? nextId++ : null,
      bon: promo ? { code: 'BUREAU-' + (4200 + nextDev), texte: promo.description || promo.nom } : null, erreurs: [], officeId: null, traite: null };
    DEV.push(x);
    return { ok: true, demande: JSON.parse(JSON.stringify(x)) };
  }
  if (p === '/dashboard/bureaux-dev/questionnaire') {
    if (b.promo != null && !PROMOS.some(x => x.id === +b.promo)) { return err(422, 'cette promotion n’a pas de bon dans ce magasin'); }
    Object.assign(QUEST, { actif: !!b.actif, promo: b.promo ? +b.promo : null, titre: String(b.titre || '').trim() || 'Du frais au bureau', message: String(b.message || '').trim() });
    if (b.regenerer) { QUEST.jeton = '7a1e5c3b9d2f4a6c8e0b1d3f5a7c9e4b'; }
    return Object.assign({ ok: true }, devEtat());
  }
  const x = DEV.find(y => y.id === +b.id);
  if (!x) { return err(404, 'demande introuvable'); }
  if (p === '/dashboard/bureaux-dev/renvoyer') { if (x.d) { x.d._tfbuddy = 'ok'; x.erreurs = []; } return { ok: true, tfbuddy: 'ok' }; }
  if (p === '/dashboard/bureaux-dev/refuser') { x.statut = 'refuse'; x.traite = '2026-11-19 15:42'; return { ok: true }; }
  if (p === '/dashboard/bureaux-dev/valider') {
    if (x.statut !== 'a_valider') { return err(409, 'cette demande est déjà traitée'); }
    if (!TFB.connecte) { return err(403, 'Le bureau se crée avec le compte TF Buddy du magasin : connectez-le (Clients, en haut à droite), puis validez.', { refus: true }); }
    const tvaV = String(b.tva || x.tva || '').trim();
    if (!x.societeId && !tvaV) { return err(422, 'TF Buddy demande une société B2B pour créer un bureau : donnez son numéro de TVA.'); }
    let soc = x.societeId;
    if (!soc) { soc = nextId++; ENT.push(Object.assign({}, ENT[0], { id: soc, company_name: x.societe, tax_number: tvaV, street: x.adresse, street_number: '', zip: x.cp, city: x.ville, n12: 0, ca12: 0, dernier: null, cycle: null,
      profil: { score: null, type: null, mode: null, creditActif: false, creditLimite: null, utilise: null, depuis: '2026-11-19' } })); }
    else if (!ENT.some(e => e.id === soc)) { ENT.push(Object.assign({}, ENT[0], { id: soc, company_name: x.societe, tax_number: tvaV, street: x.adresse, street_number: '', zip: x.cp, city: x.ville, n12: 0, ca12: 0, dernier: null, cycle: null,
      profil: { score: null, type: null, mode: null, creditActif: false, creditLimite: null, utilise: null, depuis: '2026-11-19' } })); }
    const statut = b.statut === 'pending' ? 'pending' : 'active';
    const o = { id: nextOffice++, nom: String(b.nom || x.bureau).trim(), soc, statut, cat: 'full', offres: 0, deps: 0, contact: x.personne, jours: x.jours.length ? x.jours : [1, 2, 3, 4, 5], route: +b.route_id || null,
      creneau: null, sites: [{ id: nextOffice * 10, name: String(b.nom || x.bureau).trim(), address: x.adresse, postal_code: x.cp, city: x.ville, access_minutes: null }], personnes: 1, lien: false, cutoff: ['16:00', 1], drop: 5 };
    BUR.push(o);
    Object.assign(x, { statut: 'cree', officeId: o.id, traite: '2026-11-19 15:42', societeId: soc, tva: tvaV });
    return { ok: true, officeId: o.id, statut, notes: [] };
  }
  return undefined;
}

/* ── L'analyse B2B ── */
const SAISON = [0.86, 0.9, 0.97, 0.99, 1.02, 0.95, 0.78, 0.74, 0.98, 1.03, 1.06, 0.9];
function analyse(annee, mois) {
  const base = an => an === 2026 ? 6200 : an === 2025 ? 4700 : 2600;
  const v = (an, m) => (an > 2026 || (an === 2026 && m > 11)) ? null : r2(base(an) * SAISON[m - 1] * (an === 2026 && m === 11 ? 0.66 : 1) * (0.95 + D.alea('a' + an + m)() * 0.1));
  const tk = x => x == null ? null : Math.round(x / 21.4), cl = x => x == null ? null : Math.round(x / 260), part = (x, an, m) => x == null ? null : Math.round(1000 * x / (base(an) * 7.9 * SAISON[m - 1])) / 10;
  const graphique = Array.from({ length: 12 }, (_, i) => { const m = i + 1, a = v(annee, m), b = v(annee - 1, m); return { mois: m, ventes: [a, b], tickets: [tk(a), tk(b)], clients: [cl(a), cl(b)], part: [part(a, annee, m), part(b, annee - 1, m)] }; });
  const cur = v(annee, mois);
  const prev = mois === 1 ? v(annee - 1, 12) : v(annee, mois - 1), an = v(annee - 1, mois);
  const enCours = annee === 2026 && mois === 11;
  const prevC = prev == null ? null : enCours ? prev * 0.63 : prev, anC = an == null ? null : enCours ? an * 0.63 : an;
  const ec = (a, b) => a == null || !b ? null : Math.round(1000 * (a - b) / b) / 10;
  const kpi = { ventes: { v: cur, mom: ec(cur, prevC), yoy: ec(cur, anC) }, tickets: { v: tk(cur), mom: ec(tk(cur), tk(prevC)), yoy: ec(tk(cur), tk(anC)) },
    panier: { v: cur ? r2(cur / tk(cur)) : null, mom: 1.8, yoy: 4.2 }, clients: { v: cl(cur) + 9, mom: ec(cl(cur) + 9, cl(prevC) + 9), yoy: ec(cl(cur) + 9, cl(anC) + 6) },
    part: { v: part(cur, annee, mois) * (enCours ? 1.5 : 1), mom: 0.6, yoy: 2.1 }, factures: { v: 14, mom: 7.7, yoy: 27.3, ca: cur ? r2(cur * 0.62) : null } };
  kpi.part.v = kpi.part.v == null ? null : Math.round(kpi.part.v * 10) / 10;
  if (TFB.connecte) { return { shop: SHOP, source: 'panel', annee, mois, kpi, graphique }; }
  return { shop: SHOP, source: 'releve', annee, mois, panel: refusPanel('le tableau de bord B2B'), kpi, graphique, ca: cur ? r2(cur * 100 / kpi.part.v) : null,
    memesJours: enCours ? 19 : null, couverture: { lus: enCours ? 19 : 30, jours: enCours ? 19 : 30 },
    texte: 'Lu dans les tickets de la caisse : un ticket pro est celui d’une fiche avec une TVA valide (la jauge B2B de l’Opérationnel), montant après la remise personnelle des paiements différés.' };
}

module.exports = function (m, p, q, c) {
  c = c || {};
  /* Le compte TF Buddy du magasin */
  if (p.startsWith('/dashboard/tfb/')) {
    if (m === 'GET' && p === '/dashboard/tfb/etat') { return TFB.connecte ? { shop: SHOP, recu: true, par: 'entete', entete: true, cookie: true, expire: NOW_S + 3600, maintenant: NOW_S, tfbuddy: { code: 200, erreur: null } } : { shop: SHOP, recu: false, par: null, entete: false, cookie: false, expire: null, maintenant: NOW_S }; }
    if (p === '/dashboard/tfb/connexion') {
      const tel = String(c.phone || '').replace(/[^\d+]/g, ''), pin = String(c.pin || '').replace(/\D/g, '');
      if (tel.length < 8 || pin.length < 4) { return err(422, 'le téléphone (avec l’indicatif, +32…) et le PIN sont attendus'); }
      return { etape: 'sms', challenge: 'demo-challenge-magasin-quatre', texte: 'Un code a été envoyé par SMS au ' + tel.slice(0, 4) + '•••' + tel.slice(-2) + '.' };
    }
    if (p === '/dashboard/tfb/confirmer') {
      if (String(c.code || '').replace(/\D/g, '').length < 4) { return err(422, 'le code reçu par SMS est attendu'); }
      TFB.connecte = true; return { ok: true, jetons: jetons() };
    }
    if (p === '/dashboard/tfb/sms') { return { ok: true }; }
    if (p === '/dashboard/tfb/renouveler') { return TFB.connecte ? { ok: true, jetons: jetons() } : err(401, 'session terminée', { expire: true }); }
    if (p === '/dashboard/tfb/deconnexion') { TFB.connecte = false; return { ok: true }; }
    return undefined;
  }
  /* Les bureaux en développement */
  if (p === '/dashboard/bureaux-dev' && m === 'GET') { return devEtat(); }
  if (p === '/dashboard/bureaux-dev/tournees' && m === 'GET') { return { ok: true, tournees: JSON.parse(JSON.stringify(TOURNEES)) }; }
  if (p.startsWith('/dashboard/bureaux-dev') && m === 'POST') { return devEcrire(p, c); }
  if (!p.startsWith('/dashboard/clients')) { return undefined; }
  if (m === 'GET') {
    if (p === '/dashboard/clients/particuliers') { return particuliers(q.get('q') || '', q.get('filtre') || ''); }
    if (p === '/dashboard/clients/entreprises') { return entreprises(); }
    if (p === '/dashboard/clients/fiche') { return fiche(+q.get('id')); }
    if (p === '/dashboard/clients/analyse') { return analyse(+q.get('annee'), +q.get('mois')); }
    if (p === '/dashboard/clients/bureaux') {
      const ch = q.get('chemin') || '';
      if (!TFB.connecte) { return refusPanel('les bureaux du magasin'); }
      const d = bureauLire(ch, q);
      if (d === 'SITE') { return { ok: false, refus: false, code: 422, motif: 'HTTP 422 : OFFICE_DELIVERY_SITE_REQUIRED' }; }
      if (d === null) { return { ok: false, refus: false, code: 404, motif: 'HTTP 404 : OFFICE_NOT_FOUND' }; }
      return { ok: true, compte: 'magasin', data: d };
    }
    return undefined;
  }
  if (p === '/dashboard/clients/client') { return ecrireClient(c); }
  if (p === '/dashboard/clients/tva') { return tva(c); }
  if (p === '/dashboard/clients/bureaux') {
    if (!TFB.connecte) { return Object.assign({ __status: 403 }, { json: Object.assign(refusPanel('l’écriture des bureaux'), { error: 'TF Buddy refuse l’écriture des bureaux aux comptes du cockpit.' }) }); }
    const d = bureauEcrire(String(c.methode || 'POST').toUpperCase(), String(c.chemin || ''), c.corps);
    return d === null ? err(422, 'TF Buddy refuse cette modification du bureau (HTTP 404 : OFFICE_NOT_FOUND).') : { ok: true, data: d, compte: 'magasin' };
  }
  if (p === '/dashboard/clients/bureaux/pdf') {
    // Un PDF d'une page, tel que TF Buddy le rend (le navigateur le télécharge).
    return { __body: '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n', type: 'application/pdf' };
  }
  return undefined;
};
