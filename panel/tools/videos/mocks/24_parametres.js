/* Paramètres › Mails et automatisations (vue=mails, et la fenêtre de la roue du rail) : les routes
 * /dashboard/boite/automatisations*, /dashboard/boite/equipe aux formes de panel/src/automatisations.php (amLire),
 * lues par panel/public/dashboard/automatisations.js et par parLire (dashboard.js). La boîte est ouverte sur cet
 * appareil (00_commun.js). Le rappel la veille du retrait est allumé (les deux canaux, 17:00) ; demain, vendredi
 * 20 novembre, six commandes à retirer ; le journal des rappels des jours d'avant ; trois personnes dans l'équipe.
 * Les écritures (réglage, équipe) changent l'état en mémoire, l'essai répond comme le serveur. Tout est fictif. */
'use strict';

const BOITE = 'magasin.demo@exemple.be', MAGASIN = 'Magasin démo', DEMAIN = '2026-11-20', DEMAIN_LIB = 'vendredi 20 novembre';
const RAPPEL = {
  actif: true, canal: 'les2', heure: '17:00', recent: true,
  objet: 'Votre commande de demain, {heure} · {magasin}',
  corps: 'Bonjour {prenom},\n\nVotre commande vous attend demain, {jour}, à partir de {heure}.\n{paiement}\n\nUn changement ? Répondez simplement à cet e-mail.\n\nÀ demain,\n{magasin}',
  sms: '{magasin} : votre commande vous attend demain, {jour}, à {heure}. {paiement}',
};
const VARIABLES = {
  '{prenom}': 'le prénom du client', '{nom}': 'son nom', '{magasin}': 'le nom du magasin', '{jour}': 'le jour du retrait (mardi 17 novembre)',
  '{heure}': 'l’heure du retrait', '{total}': 'le montant de la commande', '{paiement}': '« Elle est déjà réglée. » ou « Reste à payer au retrait : … »',
  '{commande}': 'le n° de la commande',
};
// Les commandes du panel à retirer demain : [n°, heure, client (prénom et initiale), total, réglée, webshop, e-mail, téléphone, passée il y a moins de 24 h].
const COMMANDES = [
  [2187, '08:30', 'Martine L.', 42.6, false, false, true, true, false],
  [2191, '09:15', 'Crèche Les Petits Pas', 86.4, true, false, true, false, false],
  [2194, '10:00', 'Patrick V.', 24.8, true, true, true, true, false],
  [2196, '11:30', 'Sophie D.', 68.4, false, false, false, true, false],
  [2199, '12:00', 'Marc T.', 31.2, false, false, false, false, false],
  [2203, '16:30', 'Élise R.', 54, true, true, true, true, true],
];
/** Le journal des rappels partis (panel_boite_journal), du plus récent au plus ancien : les 30 derniers. */
function journal() {
  const jours = ['2026-11-18', '2026-11-17', '2026-11-16', '2026-11-14', '2026-11-13', '2026-11-12', '2026-11-11', '2026-11-10'];
  const L = []; let n = 2186;
  jours.forEach((j, i) => {
    const k = 2 + (i * 7) % 3;
    for (let c = 0; c < k; c++) {
      const cmd = n--, s = String(2 + c).padStart(2, '0');
      L.push({ le: j + ' 17:00:' + s, canal: 'email', commande: cmd, objet: 'Votre commande de demain', erreur: null });
      if ((cmd + i) % 3) { L.push({ le: j + ' 17:00:' + String(20 + c).padStart(2, '0'), canal: 'sms', commande: cmd, objet: null, erreur: j === '2026-11-13' && c === 0 ? 'numéro refusé par le fournisseur' : null }); }
    }
  });
  return L.sort((a, b) => b.le.localeCompare(a.le)).slice(0, 30);
}
let E = null;
const etat = () => E || (E = { reglage: Object.assign({}, RAPPEL), equipe: [{ id: 3, nom: 'Inès B.', adresse: 'ines.b@exemple.be' }, { id: 1, nom: 'Julie M.', adresse: 'julie.m@exemple.be' }, { id: 2, nom: 'Thomas D.', adresse: 'thomas.d@exemple.be' }], prochain: 4 });

/** amCanaux : ce que le réglage demande, ce que la fiche du client permet. */
function canaux(canal, mail, tel) {
  let m = canal !== 'sms' && mail, s = canal !== 'email' && tel;
  if (canal === 'email' && !mail && tel) { s = true; }
  if (canal === 'sms' && !tel && mail) { m = true; }
  return { email: !!m, sms: !!s };
}
/** amLire : le réglage, les commandes de demain avec leur état, le journal, l'équipe. */
function lire() {
  const S = etat(), g = S.reglage;
  const commandes = COMMANDES.map(([id, heure, client, total, paye, webshop, mail, tel, recente]) => {
    const k = canaux(g.canal, mail, tel);
    const e = g.recent && recente ? 'recente' : (!k.email && !k.sms ? 'injoignable' : 'prevu');
    return { id, heure, client, total, paye, webshop, email: mail, tel, canaux: k, etat: e, envois: [] };
  });
  return { shop: '4', boite: BOITE, magasin: MAGASIN, horloge: '2026-11-19 15:30:12', equipe: S.equipe.slice().sort((a, b) => a.nom.localeCompare(b.nom, 'fr') || a.id - b.id).map(x => Object.assign({}, x)),
    sms: 'smsapi',
    rappel: { reglage: Object.assign({}, g), variables: VARIABLES, smsMax: 306, heures: ['12:00', '21:00'], offresAllume: false,
      demain: { jour: DEMAIN, lib: DEMAIN_LIB, lu: true, commandes }, journal: journal() } };
}
const erreur = (t) => ({ __status: 422, json: { error: t } });
/** amValider puis amEcrire. */
function ecrire(b) {
  const S = etat(), g = Object.assign({}, S.reglage);
  if (b.auto !== 'rappel_retrait') { return erreur('automatisation inconnue'); }
  if ('actif' in b) { g.actif = !!b.actif; }
  if ('canal' in b) { if (!['email', 'sms', 'les2'].includes(b.canal)) { return erreur('Le canal : e-mail, SMS ou les deux.'); } g.canal = b.canal; }
  if ('heure' in b) { const h = String(b.heure); if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(h) || h < '12:00' || h > '21:00') { return erreur('L’heure d’envoi : entre 12:00 et 21:00.'); } g.heure = h; }
  if ('recent' in b) { g.recent = !!b.recent; }
  for (const [k, max] of [['objet', 200], ['corps', 5000], ['sms', 306]]) {
    if (!(k in b)) { continue; }
    const v = String(b[k]).replace(/\r/g, '').trim();
    if (v.length > max) { return erreur({ objet: 'L’objet', corps: 'Le texte de l’e-mail', sms: 'Le SMS' }[k] + ' dépasse ' + max + ' signes.'); }
    g[k] = v;
  }
  if (g.canal !== 'sms' && (!g.objet || !g.corps)) { return erreur('L’e-mail a besoin d’un objet et d’un texte.'); }
  if (g.canal !== 'email' && !g.sms) { return erreur('Le SMS est vide.'); }
  S.reglage = g;
  return Object.assign({ ok: true, note: null }, lire());
}
/** amEssai : l'e-mail à la boîte du magasin, le SMS au numéro donné. */
function essai(b) {
  const g = Object.assign({}, etat().reglage, b), out = { ok: true, email: null, sms: null };
  if (g.canal !== 'sms') { out.email = 'envoyé à ' + BOITE; }
  const tel = String(b.tel || '').replace(/[^\d+]/g, '');
  if (g.canal !== 'email' && tel) {
    const n = tel.replace(/^\+?32/, '').replace(/^0/, '');
    out.sms = n.length === 9 ? 'envoyé au +32 ' + n.slice(0, 3) + ' ' + n.slice(3, 5) + ' ' + n.slice(5, 7) + ' ' + n.slice(7) : 'échec : numéro invalide';
    if (n.length !== 9) { out.ok = false; }
  }
  return out;
}
/** amEquipeEcrire. */
function equipe(b) {
  const S = etat();
  if (b.action === 'retirer') { S.equipe = S.equipe.filter(x => x.id !== +b.id); return Object.assign({ ok: true }, lire()); }
  if (b.action !== 'ajouter') { return erreur('action inconnue'); }
  const nom = String(b.nom || '').trim().slice(0, 80), a = String(b.adresse || '').trim().toLowerCase();
  if (!nom) { return erreur('Le prénom (et l’initiale du nom) de la personne.'); }
  if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/.test(a)) { return erreur('Une adresse e-mail valide.'); }
  const x = S.equipe.find(y => y.adresse === a);
  if (x) { x.nom = nom; } else { S.equipe.push({ id: S.prochain++, nom, adresse: a }); }
  return Object.assign({ ok: true }, lire());
}

module.exports = function (m, p, q, c) {
  if (p === '/dashboard/boite/automatisations') { return m === 'POST' ? ecrire(c || {}) : lire(); }
  if (p === '/dashboard/boite/automatisations/essai' && m === 'POST') { return essai(c || {}); }
  if (p === '/dashboard/boite/equipe' && m === 'POST') { return equipe(c || {}); }
  return undefined;
};
