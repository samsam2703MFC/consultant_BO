/* La journée › Commandes (vue=commandes) : les routes lues et écrites par panel/public/dashboard/commandes.js, aux formes
 * de panel/src/commandes_tournees.php — la journée (pcoJournee), la semaine et le mois (pcoPeriode), les clients pro du
 * magasin (pcoBureauxDe), les tournées (pcoTourneeLue) et la tournée d'une commande (pcoAffecter), recopiées ici.
 *   GET  /dashboard/commandes/jour?shop=&date=       GET /dashboard/commandes/periode?shop=&du=&au=
 *   GET  /dashboard/commandes/bureaux?shop=&q=       POST|PUT|DELETE /dashboard/commandes/tournees
 *   POST /dashboard/commandes/tournees/bureaux       POST /dashboard/commandes/affecter
 * Tout est fictif : le Magasin démo, ses bureaux (sociétés inventées à « Val-Fleuri » et « Bois-Joli »), des particuliers
 * nommés par un prénom et une initiale, trois tournées. Le jeudi 19/11 et le vendredi 20/11 sont écrits à la main (le
 * récit des vidéos) ; les autres jours sont générés (graine = date). Les écritures changent l'état en mémoire : la
 * relecture montre le changement. */
'use strict';
const D = require('./lib/demo');
const { r2 } = D;

const NOW = D.AUJ + ' 15:40';
const COUPURE = '16:00';

/* ── Les clients pro du magasin (fiche pro avec une société) : ce que GET …/bureaux cherche ── */
const PROS = [
  [7201, 'Bureau Horizon SRL', 'Bois-Joli', '5004'], [7202, 'Crèche Les Lutins', 'Val-Fleuri', '5000'],
  [7211, 'Fiduciaire du Val', 'Val-Fleuri', '5000'], [7212, 'Atelier Numérique Bois-Joli', 'Bois-Joli', '5004'],
  [7213, 'Mutuelle du Val-Fleuri', 'Val-Fleuri', '5002'], [7214, 'Résidence Les Glycines', 'Val-Fleuri', '5002'],
  [7215, 'Garage du Moulin', 'Val-Fleuri', '5000'], [7216, 'Studio Pixel & Cie', 'Bois-Joli', '5004'],
  [7217, 'Bureau d’études Arcade', 'Bois-Joli', '5004'], [7218, 'Agence Clé d’Or', 'Val-Fleuri', '5000'],
  [7219, 'Pharmacie des Tilleuls', 'Val-Fleuri', '5000'], [7220, 'Cabinet médical du Parc', 'Bois-Joli', '5004'],
  [7221, 'Imprimerie du Val', 'Val-Fleuri', '5002'], [7222, 'Salle de sport Énergie', 'Bois-Joli', '5004'],
].map(([id, nom, ville, cp]) => ({ id, nom, ville, cp }));
const pro = id => PROS.find(x => x.id === id);

/* ── Les tournées du magasin (panel_tournee, panel_tournee_bureau) : l'état de départ ── */
let nextT = 4;
const TOURNEES = [
  { id: 1, nom: 'Matin · Val-Fleuri', depart: '07:15', retour: '08:30', livreur: 'Lucas V.', vehicule: 'Camionnette 1', jours: '12345',
    bureaux: [[7202, 'Entrée par la cour, sonner à la direction'], [7214, 'Accueil, rez-de-chaussée'], [7211, '2e étage, badge à l’accueil']] },
  { id: 2, nom: 'Midi · Bois-Joli – Parc d’activités', depart: '11:15', retour: '12:45', livreur: 'Lucas V.', vehicule: 'Camionnette 1', jours: '12345',
    bureaux: [[7201, 'Bâtiment B, accueil'], [7212, 'Lunchroom, 1er étage'], [7217, ''], [7216, 'Sonner chez Pixel'], [7220, 'Entrée du personnel']] },
  { id: 3, nom: 'Fin de journée · Val-Fleuri', depart: '16:30', retour: '17:30', livreur: 'Nora L.', vehicule: 'Vélo cargo', jours: '1234',
    bureaux: [[7213, 'Accueil, déposer avant 17 h'], [7218, '']] },
].map(t => Object.assign(t, { bureaux: t.bureaux.map(([client, consigne], i) => ({ client, nom: pro(client).nom, consigne, ordre: i + 1 })) }));
/** Le choix du magasin pour une commande : [jour => {id commande => id tournée (0 : au magasin)}]. */
const MANUELS = {};

/* ── Les commandes, déjà normalisées (pcoNormaliser) ── */
const PAY = { facture: { code: 'facture', lib: 'Sur facture · 30 j' }, enligne: { code: 'enligne', lib: 'Payée en ligne' }, caisse: { code: 'caisse', lib: 'Encaissée' },
  aencaisser: { code: 'aencaisser', lib: 'À encaisser' } };
const PRENOMS = ['Hélène M.', 'Julien D.', 'Sophie R.', 'Marc D.', 'Claire V.', 'Pierre G.', 'Lucie T.', 'Karim B.', 'Nathalie F.', 'Aline M.', 'Olivier P.', 'Manon S.', 'Hugo C.', 'Emma W.', 'Louis H.', 'Chloé N.'];
const plusMin = (h, n) => D.hm(D.toMin(h) + n);
/** [id, heure, canal, client (id pro ou prénom), montant, statut, note, livraison, fait] */
function cmd(jour, [id, heure, canal, client, montant, statut, note, livraison, fait]) {
  const p = typeof client === 'number' ? pro(client) : null;
  const passe = (jour + ' ' + heure) <= NOW;
  const paiement = canal === 'bureau' ? PAY.facture : canal === 'webshop' ? PAY.enligne : (statut === 'fait' || passe ? PAY.caisse : PAY.aencaisser);
  return { id, jour, heure, canal, livraison: !!livraison, client: p ? { id: p.id, nom: p.nom, pro: true } : { id: 9000 + (id % 997), nom: client, pro: false },
    note: note || '', montant: r2(montant), statut, fait: fait || null, paiement };
}
const JEUDI = [
  [5101, '07:20', 'bureau', 7202, 48.00, 'fait', 'Goûter du jeudi : 24 brioches', true, '07:28'],
  [5102, '07:45', 'bureau', 7214, 36.40, 'fait', '', true, '07:51'],
  [5103, '08:00', 'webshop', 'Julien D.', 18.60, 'fait', '', false, '08:04'],
  [5104, '08:10', 'bureau', 7211, 54.20, 'fait', 'Petit-déjeuner de réunion, 10 personnes', true, '08:14'],
  [5105, '09:30', 'comptoir', 'Sophie R.', 21.60, 'fait', 'Tarte aux pommes, commandée par téléphone', false, '09:41'],
  [5106, '10:15', 'webshop', 'Marc D.', 12.40, 'fait', '', false, '10:22'],
  [5107, '11:30', 'bureau', 7201, 74.40, 'fait', 'Lunch d’équipe : 12 sandwichs', true, '11:34'],
  [5108, '11:45', 'bureau', 7212, 46.80, 'fait', '', true, '11:50'],
  [5109, '12:00', 'bureau', 7217, 38.90, 'fait', '', true, '12:06'],
  [5110, '12:10', 'bureau', 7220, 29.50, 'fait', '', true, '12:15'],
  [5111, '12:30', 'comptoir', 'Olivier P.', 6.80, 'fait', '2 baguettes tradition, 1 pain de campagne', false, '12:33'],
  [5112, '14:00', 'comptoir', 'Manon S.', 18.40, 'fait', '', false, '14:05'],
  [5113, '15:15', 'webshop', 'Karim B.', 16.20, 'afaire', '', false, null],
  [5114, '16:00', 'comptoir', 'Nathalie F.', 26.00, 'prete', 'Fraisier 6 parts, avec bougies', false, null],
  [5115, '16:40', 'bureau', 7213, 42.00, 'prete', 'Goûter du personnel', true, null],
  [5116, '17:00', 'bureau', 7218, 24.60, 'prep', '', true, null],
  [5117, '17:10', 'webshop', 'Claire V.', 31.80, 'afaire', 'Livraison : Rue des Tilleuls 40, Val-Fleuri', true, null],
  [5118, '17:30', 'comptoir', 'Pierre G.', 14.80, 'afaire', '', false, null],
  [5119, '18:00', 'webshop', 'Lucie T.', 22.90, 'afaire', '', false, null],
].map(x => cmd('2026-11-19', x));
const VENDREDI = [
  [5201, '07:20', 'bureau', 7202, 52.80, 'afaire', 'Goûter du vendredi', true],
  [5202, '09:00', 'webshop', 'Emma W.', 19.80, 'afaire', '', false],
  [5203, '11:30', 'bureau', 7201, 81.20, 'afaire', '', true],
  [5204, '11:45', 'bureau', 7212, 44.10, 'afaire', '', true],
  [5205, '12:00', 'bureau', 7217, 36.30, 'afaire', '', true],
  [5206, '12:10', 'bureau', 7220, 31.40, 'afaire', '', true],
  [5207, '16:00', 'comptoir', 'Hugo C.', 28.50, 'afaire', 'Entremets trois chocolats', false],
].map(x => cmd('2026-11-20', x));

/* Les autres jours : les habitudes des bureaux (heure, probabilité, montant) et le passage au comptoir et au webshop. */
const HABITUDES = [[7202, '07:20', 0.9, 46], [7214, '07:45', 0.7, 34], [7211, '08:10', 0.45, 50], [7201, '11:30', 0.85, 72], [7212, '11:45', 0.7, 45],
  [7217, '12:00', 0.55, 37], [7216, '12:05', 0.3, 33], [7220, '12:10', 0.5, 30], [7213, '16:40', 0.6, 40], [7218, '17:00', 0.45, 25]];
const memo = {};
function genere(jour) {
  if (memo[jour]) { return memo[jour]; }
  const r = D.alea('cmd' + jour), j = D.dow(jour), we = j === 0 || j === 6;
  const passe = jour < D.AUJ, futur = jour > D.AUJ;
  const L = [];
  let k = 0;
  const id = () => 40000 + (Math.round((D.jourDe(jour) - D.jourDe('2026-01-01')) / 864e5) * 40) + (k++);
  const statut = () => passe ? 'fait' : 'afaire';
  // Plus loin que la semaine prochaine, peu de commandes : celles prises d'avance.
  const loin = futur && jour > D.plus(D.AUJ, 7);
  if (!we && !loin) {
    HABITUDES.forEach(([cli, h, p, m]) => {
      if (r() < p * (futur ? 0.45 : 1)) { L.push(cmd(jour, [id(), h, 'bureau', cli, m * (0.8 + r() * 0.4), statut(), '', true, passe ? plusMin(h, 4 + Math.floor(r() * 6)) : null])); }
    });
  }
  const nC = loin ? (r() < 0.25 ? 1 : 0) : futur ? Math.floor(r() * 3) : (we ? 5 : 3) + Math.floor(r() * 3);
  const nW = loin ? (r() < 0.2 ? 1 : 0) : futur ? Math.floor(r() * 2) : (we ? 4 : 2) + Math.floor(r() * 3);
  for (let i = 0; i < nC; i++) {
    const h = D.hm(7 * 60 + 30 + Math.floor(r() * 21) * 30);
    L.push(cmd(jour, [id(), h, 'comptoir', PRENOMS[Math.floor(r() * PRENOMS.length)], 8 + r() * 38, statut(), '', false, passe ? plusMin(h, 3) : null]));
  }
  for (let i = 0; i < nW; i++) {
    const h = D.hm(8 * 60 + Math.floor(r() * 20) * 30), liv = !loin && r() < 0.12;
    L.push(cmd(jour, [id(), h, 'webshop', PRENOMS[Math.floor(r() * PRENOMS.length)], 10 + r() * 26, statut(), liv ? 'Livraison à domicile' : '', liv, passe ? plusMin(h, 5) : null]));
  }
  return (memo[jour] = L);
}
const commandesDu = jour => jour === '2026-11-19' ? JEUDI : jour === '2026-11-20' ? VENDREDI : genere(jour);

/* ── La logique de panel/src/commandes_tournees.php ── */
const pcoMin = h => +h.slice(0, 2) * 60 + +h.slice(3, 5);
const isoJour = s => { const j = D.dow(s); return String(j === 0 ? 7 : j); };
const roule = (t, d) => String(t.jours || '').includes(isoJour(d));
const avecClients = t => Object.assign({}, t, { clients: t.bureaux.map(b => b.client) });
const somme = L => ({ n: L.length, ca: r2(L.reduce((a, c) => a + c.montant, 0)) });
const toutes = () => TOURNEES.slice().sort((a, b) => a.depart.localeCompare(b.depart) || a.id - b.id).map(t => ({ id: t.id, nom: t.nom, depart: t.depart, retour: t.retour,
  livreur: t.livreur, vehicule: t.vehicule, jours: t.jours, bureaux: t.bureaux.map(b => Object.assign({}, b)) }));

function affecter(cmds, tournees, manuels) {
  const ids = tournees.map(t => t.id), out = {};
  for (const c of cmds) {
    if (Object.prototype.hasOwnProperty.call(manuels, c.id)) {
      const m = +manuels[c.id];
      if (m === 0) { out[c.id] = { t: null, mode: 'manuel' }; continue; }
      if (ids.includes(m)) { out[c.id] = { t: m, mode: 'manuel' }; continue; }
    }
    const cli = c.client.id;
    const cands = cli > 0 ? tournees.filter(t => t.clients.includes(cli)) : [];
    if (!cands.length) { out[c.id] = { t: null, mode: null }; continue; }
    const h = pcoMin(c.heure);
    const dans = cands.filter(t => h >= pcoMin(t.depart) - 60 && h <= pcoMin(t.retour) + 30);
    if (!dans.length) { out[c.id] = { t: null, mode: 'hors' }; continue; }
    dans.sort((a, b) => Math.abs(pcoMin(a.depart) - h) - Math.abs(pcoMin(b.depart) - h));
    out[c.id] = { t: dans[0].id, mode: 'auto' };
  }
  return out;
}
function etatTournee(t, cmds, date, auj, maintenant) {
  if (!cmds.length) { return 'vide'; }
  if (cmds.filter(c => c.statut === 'fait').length === cmds.length) { return 'terminee'; }
  if (date < auj) { return 'terminee'; }
  if (date === auj && maintenant != null && maintenant >= t.depart) { return 'route'; }
  return cmds.some(c => c.statut === 'prete' || c.statut === 'prep') ? 'prep' : 'afaire';
}
function journee(date) {
  const auj = NOW.slice(0, 10), maintenant = date === auj ? NOW.slice(11, 16) : null, demain = D.plus(date, 1);
  const jour = commandesDu(date).map(c => Object.assign({}, c)).sort((a, b) => a.heure.localeCompare(b.heure) || a.id - b.id);
  const lendemain = commandesDu(demain);
  const roulent = TOURNEES.filter(t => roule(t, date)).map(avecClients).sort((a, b) => a.depart.localeCompare(b.depart) || a.id - b.id);
  const aff = affecter(jour, roulent, MANUELS[date] || {});
  const rang = {};
  jour.forEach(c => {
    const a = aff[c.id] || { t: null, mode: null };
    c.tournee = a.t; c.mode = a.mode;
    c.arret = a.t !== null ? (rang[a.t] = (rang[a.t] || 0) + 1) : null;
    c.sansTournee = a.t === null && a.mode !== 'manuel' && (c.livraison || a.mode === 'hors');
  });
  const tOut = roulent.map(t => {
    const L = jour.filter(c => c.tournee === t.id), servis = L.map(c => c.client.id);
    const bureaux = t.bureaux.map(b => { const c = L.find(x => x.client.id === b.client); return { client: b.client, nom: b.nom, consigne: b.consigne || '', commande: c ? c.id : null }; });
    return { id: t.id, nom: t.nom, depart: t.depart, retour: t.retour, livreur: t.livreur, vehicule: t.vehicule, jours: t.jours, etat: etatTournee(t, L, date, auj, maintenant),
      commandes: L.map(c => c.id), bureaux, sansCommande: bureaux.filter(b => b.commande === null && !servis.includes(b.client)).length, total: somme(L) };
  });
  const livrer = jour.filter(c => c.tournee !== null || c.sansTournee), retirer = jour.filter(c => c.tournee === null && !c.sansTournee);
  const par = k => jour.filter(c => c.canal === k);
  const totaux = { total: somme(jour), webshop: somme(par('webshop')), comptoir: somme(par('comptoir')), bureau: somme(par('bureau')), livrer: somme(livrer), retirer: somme(retirer),
    aPreparer: date < auj ? 0 : jour.filter(c => c.statut === 'afaire' || c.statut === 'prep').length, sansTournee: jour.filter(c => c.sansTournee).length };
  let dem = null;
  if (date === auj) {
    const cl = new Set(lendemain.map(c => c.client.id));
    const tD = TOURNEES.filter(t => roule(t, demain)).map(t => ({ id: t.id, nom: t.nom, depart: t.depart, bureaux: t.bureaux.map(b => ({ client: b.client, nom: b.nom, commande: cl.has(b.client) })) }))
      .filter(t => t.bureaux.length).sort((a, b) => a.depart.localeCompare(b.depart));
    dem = { date: demain, coupure: COUPURE, ouvert: maintenant === null || maintenant < COUPURE, commandes: somme(lendemain), tournees: tD };
  }
  return { shop: 4, panel: true, motif: null, coupure: COUPURE, date, maintenant, commandes: jour, tournees: tOut, totaux, demain: dem, toutes: toutes() };
}
function periode(du, au) {
  const vide = () => ({ n: 0, ca: 0 });
  const ajoute = (x, m) => { x.n++; x.ca = r2(x.ca + m); };
  const n = Math.round((D.jourDe(au) - D.jourDe(du)) / 864e5) + 1, avDu = D.plus(du, -n);
  const avant = { du: avDu, total: vide(), webshop: vide(), comptoir: vide(), bureau: vide() };
  for (let i = 0; i < n; i++) { commandesDu(D.plus(avDu, i)).forEach(c => { ajoute(avant.total, c.montant); ajoute(avant[c.canal], c.montant); }); }
  const T = {}, B = {};
  TOURNEES.forEach(t => {
    T[t.id] = { id: t.id, nom: t.nom, depart: t.depart, retour: t.retour, livreur: t.livreur, vehicule: t.vehicule, jours: t.jours, bureaux: t.bureaux.length, roule: 0, parJour: {}, total: vide() };
    t.bureaux.forEach(b => { B[b.client] = B[b.client] || { client: b.client, nom: b.nom, tournees: [], parJour: {}, total: vide(), dernier: null }; B[b.client].tournees.push(t.id); });
  });
  const jours = [], tot = { total: vide(), webshop: vide(), comptoir: vide(), bureau: vide(), livrer: vide(), retirer: vide() };
  let sansT = 0;
  for (let i = 0; i < n; i++) {
    const d = D.plus(du, i);
    const L = commandesDu(d).slice().sort((a, b) => a.heure.localeCompare(b.heure) || a.id - b.id);
    const roulent = TOURNEES.filter(t => roule(t, d)).map(avecClients);
    roulent.forEach(t => { T[t.id].roule++; T[t.id].parJour[d] = { n: 0, ca: 0, servis: 0 }; });
    const aff = affecter(L, roulent, MANUELS[d] || {});
    const j = { date: d, total: vide(), webshop: vide(), comptoir: vide(), bureau: vide(), livrer: vide(), retirer: vide(), sansTournee: 0 };
    const servis = {};
    L.forEach(c => {
      const a = aff[c.id] || { t: null, mode: null };
      const sans = a.t === null && a.mode !== 'manuel' && (c.livraison || a.mode === 'hors');
      ['total', c.canal, a.t !== null || sans ? 'livrer' : 'retirer'].forEach(k => { ajoute(j[k], c.montant); ajoute(tot[k], c.montant); });
      if (sans) { j.sansTournee++; sansT++; }
      if (a.t !== null) {
        ajoute(T[a.t].total, c.montant);
        const pj = T[a.t].parJour[d]; pj.n++; pj.ca = r2(pj.ca + c.montant);
        (servis[a.t] = servis[a.t] || new Set()).add(c.client.id);
      }
      const k = c.client.id;
      if (k > 0 && (B[k] || c.canal === 'bureau')) {
        B[k] = B[k] || { client: k, nom: c.client.nom, tournees: [], parJour: {}, total: vide(), dernier: null };
        B[k].parJour[d] = B[k].parJour[d] || vide();
        ajoute(B[k].parJour[d], c.montant); ajoute(B[k].total, c.montant); B[k].dernier = d;
      }
    });
    Object.keys(servis).forEach(t => { T[t].parJour[d].servis = servis[t].size; });
    jours.push(j);
  }
  const bureaux = Object.values(B).sort((a, b) => b.total.ca - a.total.ca || a.nom.localeCompare(b.nom));
  const tournees = Object.values(T).sort((a, b) => a.depart.localeCompare(b.depart) || a.id - b.id);
  return { shop: 4, panel: true, motif: null, du, au, jours, totaux: Object.assign(tot, { sansTournee: sansT }), avant, tournees, bureaux, aujourdhui: NOW.slice(0, 10), toutes: toutes() };
}

/* ── Les écritures ── */
const err = (s, e) => ({ __status: s, json: { error: e } });
function lue(b) {
  const nom = String(b.nom || '').trim(), dep = String(b.depart || '').trim(), ret = String(b.retour || '').trim();
  const jours = [...new Set(String(b.jours || '').replace(/[^1-7]/g, '').split(''))].sort().join('');
  if (!nom || nom.length > 80) { return [null, 'le nom de la tournée : 1 à 80 caractères']; }
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(dep) || !/^([01]\d|2[0-3]):[0-5]\d$/.test(ret)) { return [null, 'départ et retour : HH:MM']; }
  if (ret <= dep) { return [null, 'le retour vient après le départ']; }
  if (!jours) { return [null, 'au moins un jour']; }
  return [{ nom, depart: dep, retour: ret, jours, livreur: String(b.livreur || '').trim(), vehicule: String(b.vehicule || '').trim() }, null];
}
function ecrire(m, p, b) {
  b = b || {};
  if (p === '/dashboard/commandes/tournees') {
    if (m === 'DELETE') {
      const i = TOURNEES.findIndex(t => t.id === +b.id);
      if (i < 0) { return err(404, 'tournée inconnue pour ce magasin'); }
      TOURNEES.splice(i, 1);
      Object.values(MANUELS).forEach(M => Object.keys(M).forEach(k => { if (+M[k] === +b.id) { delete M[k]; } }));
      return { ok: true };
    }
    const [t, e] = lue(b);
    if (!t) { return err(422, e); }
    if (m === 'POST') { const id = nextT++; TOURNEES.push(Object.assign({ id, bureaux: [] }, t)); return { ok: true, id }; }
    if (m === 'PUT') { const x = TOURNEES.find(y => y.id === +b.id); if (!x) { return err(404, 'tournée inconnue pour ce magasin'); } Object.assign(x, t); return { ok: true, id: x.id }; }
  }
  if (p === '/dashboard/commandes/tournees/bureaux' && m === 'POST') {
    const x = TOURNEES.find(y => y.id === +b.tournee);
    if (!x) { return err(404, 'tournée inconnue pour ce magasin'); }
    if (!Array.isArray(b.bureaux)) { return err(422, 'bureaux : une liste'); }
    const vus = new Set();
    x.bureaux = b.bureaux.filter(y => +y.client > 0 && String(y.nom || '').trim() && !vus.has(+y.client) && vus.add(+y.client))
      .map((y, i) => ({ client: +y.client, nom: String(y.nom).trim(), consigne: String(y.consigne || '').trim().slice(0, 200), ordre: i + 1 }));
    return { ok: true, n: x.bureaux.length };
  }
  if (p === '/dashboard/commandes/affecter' && m === 'POST') {
    if (!(+b.commande > 0) || !/^\d{4}-\d{2}-\d{2}$/.test(String(b.date || ''))) { return err(422, 'commande et date (AAAA-MM-JJ)'); }
    const M = MANUELS[b.date] = MANUELS[b.date] || {};
    if (b.tournee === null || b.tournee === undefined) { delete M[+b.commande]; return { ok: true, tournee: null }; }
    const t = +b.tournee;
    if (t !== 0 && !TOURNEES.some(x => x.id === t)) { return err(404, 'tournée inconnue pour ce magasin'); }
    M[+b.commande] = t;
    return { ok: true, tournee: t };
  }
  return undefined;
}

module.exports = function (m, p, q, c) {
  if (!p.startsWith('/dashboard/commandes')) { return undefined; }
  if (m !== 'GET') { return ecrire(m, p, c); }
  if (p === '/dashboard/commandes/jour') { const d = q.get('date'); return journee(/^\d{4}-\d{2}-\d{2}$/.test(d || '') ? d : D.AUJ); }
  if (p === '/dashboard/commandes/periode') {
    const du = q.get('du') || '', au = q.get('au') || '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(du) || !/^\d{4}-\d{2}-\d{2}$/.test(au) || au < du) { return err(422, 'du et au : AAAA-MM-JJ, au après du'); }
    return periode(du, au);
  }
  if (p === '/dashboard/commandes/bureaux') {
    const t = String(q.get('q') || '').trim().toLowerCase();
    const L = PROS.filter(b => !t || (b.nom + ' ' + b.ville + ' ' + b.cp).toLowerCase().includes(t)).sort((a, b) => a.nom.localeCompare(b.nom, 'fr'));
    return { shop: 4, n: L.length, bureaux: L.slice(0, 30) };
  }
  return undefined;
};
