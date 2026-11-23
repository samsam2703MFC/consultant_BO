/* La boîte mail du magasin (Boîte mail, ?vue=boite) : boite.js, servi par panel/src/boite_mail.php et boite_ia.php.
 * Données fictives : la boîte magasin.demo@exemple.be, des expéditeurs inventés aux adresses @exemple.be, le jeudi
 * 19/11/2026 à 15 h 40. La boîte est FERMÉE au départ (la forme que lisent toutes les vues, le rail compris) ; elle
 * s'ouvre avec son mot de passe (POST /dashboard/boite/ouvrir, n'importe lequel sauf vide), comme au magasin.
 * Les gestes changent la boîte relue : un e-mail ouvert devient lu, une réponse le marque « répondu », un e-mail
 * archivé ou rangé quitte la réception, le cran du pilote change. Les lectures IA (briefing, radar) sont déjà faites. */
const D = require('./lib/demo');

const MOI = { nom: D.NOM, adresse: 'magasin.demo@exemple.be' };
const ADR = (nom, adresse) => ({ nom, adresse });
const LIRE = { dossier: '', simple: false, confiance: 90, garde: '', jour: '', produits: [], personnes: 0, b2b: false, robot: false, reponse: '' };
const L = o => Object.assign({}, LIRE, o);
const P = (nom, quantite) => ({ nom, quantite });

/* La réception : les quatre non-lus d'aujourd'hui, puis les e-mails des dix derniers jours. */
const RECEPTION = [
  { uid: 1049, de: ADR('Sophie Lambert', 'sophie.lambert@exemple.be'), objet: 'Commande pour une communion le 28/11', date: '2026-11-19T15:12:00+01:00', lu: false,
    texte: 'Bonjour,\n\nNous fêtons la communion de notre fille le samedi 28 novembre. Serait-il possible de commander 30 croissants au beurre, 30 pains au chocolat et un fraisier 6 parts ? Nous serons 25 personnes.\n\nRetrait au magasin vers 10 h, si possible.\nPourriez-vous me faire une offre ?\n\nMerci d’avance,\nSophie Lambert',
    lecture: L({ resume: 'Communion le 28/11, 25 personnes : 30 croissants, 30 pains au chocolat et un fraisier, retrait vers 10 h.', categorie: 'commande', ton: 'chaleureux', urgence: 'normale', action: 'offre', confiance: 84, garde: 'commande',
      reponse: 'Bonjour Sophie,\n\nMerci pour votre message, et félicitations pour la communion de votre fille !\nNous pouvons préparer pour le samedi 28 novembre 30 croissants au beurre, 30 pains au chocolat et un fraisier 6 parts, à retirer au magasin vers 10 h.\nLe total s’élève à [à compléter : le prix de la commande] ; l’offre détaillée suit pour confirmation.\n\nBelle journée,\nL’équipe de Magasin démo',
      jour: '2026-11-28', produits: [P('croissant au beurre', 30), P('pain au chocolat', 30), P('fraisier', 1)], personnes: 25 }) },
  { uid: 1048, de: ADR('Marc Dubois', 'marc.dubois@exemple.be'), objet: 'Éclairs de mardi', date: '2026-11-19T14:05:00+01:00', lu: false,
    texte: 'Bonjour,\n\nJ’ai acheté quatre éclairs au chocolat mardi en fin de journée. Ils étaient secs et la crème avait un goût de frigo. C’est la deuxième fois ce mois-ci.\n\nJe suis client depuis l’ouverture et je suis déçu.\n\nMarc Dubois',
    lecture: L({ resume: 'Quatre éclairs au chocolat secs achetés mardi ; la deuxième fois ce mois-ci, client déçu.', categorie: 'reclamation', ton: 'mecontent', urgence: 'haute', action: 'repondre', confiance: 76, garde: 'reclamation',
      reponse: 'Bonjour Monsieur Dubois,\n\nMerci de nous l’avoir dit, et toutes nos excuses : des éclairs secs, ce n’est pas ce que nous voulons vous servir.\nNous avons transmis votre remarque à l’équipe de la pâtisserie.\nPour nous faire pardonner, [à compléter : le geste proposé].\n\nAu plaisir de vous revoir,\nL’équipe de Magasin démo' }) },
  { uid: 1047, de: ADR('Moulins Démo', 'factures@moulins-demo.exemple.be'), objet: 'Facture F-26-1187 — livraison du 17/11', date: '2026-11-19T11:30:00+01:00', lu: false, pj: true,
    pieces: [{ id: '2', nom: 'F-26-1187.pdf', type: 'application/pdf', taille: 84213 }],
    texte: 'Bonjour,\n\nVeuillez trouver ci-joint la facture F-26-1187 relative à la livraison du 17/11.\nÉchéance : 30 jours fin de mois.\n\nService facturation\nMoulins Démo',
    lecture: L({ resume: 'La facture F-26-1187 de la livraison du 17/11, échéance à 30 jours fin de mois.', categorie: 'facture', ton: 'neutre', urgence: 'basse', action: 'ranger', dossier: 'Fournisseurs', confiance: 95, b2b: true }) },
  { uid: 1046, de: ADR('Léa Martin', 'lea.martin@exemple.be'), objet: 'Candidature — vendeuse le week-end', date: '2026-11-19T09:48:00+01:00', lu: false, pj: true,
    pieces: [{ id: '2', nom: 'CV-Lea-Martin.pdf', type: 'application/pdf', taille: 126480 }],
    texte: 'Bonjour,\n\nÉtudiante en deuxième année, je cherche un emploi de vendeuse le samedi et le dimanche. J’ai déjà travaillé deux étés dans une boulangerie.\nVous trouverez mon CV en pièce jointe.\n\nBien à vous,\nLéa Martin',
    lecture: L({ resume: 'Candidature spontanée : vendeuse le week-end, étudiante, deux étés d’expérience en boulangerie. CV joint.', categorie: 'candidature', ton: 'chaleureux', urgence: 'normale', action: 'repondre', simple: true, confiance: 91,
      reponse: 'Bonjour Léa,\n\nMerci pour votre candidature et votre CV, que nous avons bien reçus.\nNous les regardons avec attention et revenons vers vous dans les prochains jours.\n\nBelle journée,\nL’équipe de Magasin démo' }) },
  { uid: 1045, de: ADR('Crèche Les Petits Pas', 'commandes@creche-demo.exemple.be'), objet: 'Commande de la semaine prochaine', date: '2026-11-18T16:20:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nPour lundi 23/11, comme d’habitude : 40 pains au chocolat pour le goûter, livrés avant 8 h.\n\nMerci !\nL’équipe de la crèche',
    lecture: L({ resume: 'Lundi 23/11 : 40 pains au chocolat pour le goûter, livrés avant 8 h.', categorie: 'commande', ton: 'chaleureux', urgence: 'normale', action: 'offre', garde: 'b2b', jour: '2026-11-23', produits: [P('pain au chocolat', 40)], b2b: true }) },
  { uid: 1044, de: ADR('Thomas Renard', 'thomas.renard@exemple.be'), objet: 'Re: Notre offre n° 412', date: '2026-11-18T10:02:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nC’est parfait pour nous : je confirme l’offre n° 412 pour samedi 21/11, l’entremets trois chocolats pour 12 personnes.\nJe passerai le chercher vers 11 h.\n\nMerci,\nThomas Renard',
    lecture: L({ resume: 'Confirme l’offre n° 412 : un entremets trois chocolats pour 12 personnes, samedi 21/11 vers 11 h.', categorie: 'commande', ton: 'chaleureux', urgence: 'normale', action: 'archiver', jour: '2026-11-21', produits: [P('entremets trois chocolats', 1)], personnes: 12 }) },
  { uid: 1043, de: ADR('Julie Mercier', 'julie.mercier@exemple.be'), objet: 'Baptême le samedi 28 novembre', date: '2026-11-17T19:15:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nPour le baptême de notre fils, le samedi 28 novembre, nous aimerions 15 éclairs au chocolat et 15 millefeuilles. Nous serons 15.\n\nBonne soirée,\nJulie Mercier',
    lecture: L({ resume: 'Baptême le 28/11, 15 personnes : 15 éclairs au chocolat et 15 millefeuilles.', categorie: 'commande', ton: 'chaleureux', urgence: 'normale', action: 'offre', garde: 'commande', jour: '2026-11-28', produits: [P('éclair au chocolat', 15), P('millefeuille', 15)], personnes: 15 }) },
  { uid: 1042, de: ADR('Bureau Démo SRL', 'achats@bureau-demo.exemple.be'), objet: 'Plateaux lunch pour le 2 décembre', date: '2026-11-17T09:20:00+01:00', lu: true,
    texte: 'Bonjour,\n\nNous organisons une réunion le mercredi 2 décembre. Pourriez-vous nous livrer 18 sandwichs poulet crudités et 18 cookies au chocolat pour 12 h 30 ?\nMerci de nous envoyer une offre avec la TVA.\n\nService achats\nBureau Démo SRL',
    lecture: L({ resume: 'Réunion du 2/12 : 18 sandwichs poulet crudités et 18 cookies, livrés à 12 h 30 ; offre avec TVA demandée.', categorie: 'offre', ton: 'neutre', urgence: 'normale', action: 'offre', garde: 'b2b', jour: '2026-12-02', produits: [P('sandwich poulet crudités', 18), P('cookie chocolat', 18)], personnes: 18, b2b: true }) },
  { uid: 1041, de: ADR('Fournil Pro Magazine', 'newsletter@fournil-pro.exemple.be'), objet: 'Les tendances de la bûche', date: '2026-11-16T07:00:00+01:00', lu: true,
    texte: 'La lettre de la semaine : les tendances de la bûche, trois recettes et un dossier sur la vitrine de décembre.',
    lecture: L({ resume: 'La lettre d’information de la semaine : les tendances de la bûche.', categorie: 'newsletter', ton: 'neutre', urgence: 'basse', action: 'archiver', robot: true }) },
  { uid: 1040, de: ADR('Claire Petit', 'claire.petit@exemple.be'), objet: 'Merci pour le gâteau !', date: '2026-11-15T20:41:00+01:00', lu: true, repondu: true,
    texte: 'Bonsoir,\n\nUn grand merci pour le fraisier de cet après-midi : tout le monde a adoré !\n\nClaire',
    lecture: L({ resume: 'Remercie pour le fraisier de l’après-midi : tout le monde a adoré.', categorie: 'autre', ton: 'chaleureux', urgence: 'basse', action: 'repondre', simple: true, reponse: 'Bonsoir Claire,\n\nMerci beaucoup pour votre message, il fait plaisir à toute l’équipe !\n\nÀ bientôt,\nL’équipe de Magasin démo' }) },
  { uid: 1039, de: ADR('Asbl Quartier Démo', 'contact@quartier-demo.exemple.be'), objet: 'Goûter de Saint-Nicolas le 6 décembre', date: '2026-11-14T11:05:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nPour le goûter de Saint-Nicolas du quartier, le dimanche 6 décembre, nous attendons 60 enfants : 60 cookies au chocolat et 60 chaussons aux pommes.\n\nMerci !\nPour l’asbl',
    lecture: L({ resume: 'Goûter de Saint-Nicolas le 6/12, 60 enfants : 60 cookies et 60 chaussons aux pommes.', categorie: 'offre', ton: 'chaleureux', urgence: 'normale', action: 'offre', garde: 'b2b', jour: '2026-12-06', produits: [P('cookie chocolat', 60), P('chausson aux pommes', 60)], personnes: 60, b2b: true }) },
  { uid: 1038, de: ADR('École communale Démo', 'direction@ecole-demo.exemple.be'), objet: 'Collation du 4 décembre', date: '2026-11-13T13:30:00+01:00', lu: true,
    texte: 'Madame, Monsieur,\n\nPour la journée sportive du vendredi 4 décembre, nous souhaiterions 45 pains au chocolat et 45 brioches au sucre, livrés à 10 h.\n\nCordialement,\nLa direction',
    lecture: L({ resume: 'Journée sportive du 4/12 : 45 pains au chocolat et 45 brioches au sucre, livrés à 10 h.', categorie: 'commande', ton: 'neutre', urgence: 'normale', action: 'offre', garde: 'b2b', jour: '2026-12-04', produits: [P('pain au chocolat', 45), P('brioche au sucre', 45)], personnes: 45, b2b: true }) },
  { uid: 1037, de: ADR('Moulins Démo', 'commandes@moulins-demo.exemple.be'), objet: 'Confirmation de livraison — mardi 17/11', date: '2026-11-12T08:12:00+01:00', lu: true,
    texte: 'Bonjour,\n\nNous confirmons la livraison de mardi 17/11 entre 6 h et 7 h : farine T55, farine de seigle, beurre de tourage.\n\nService commandes\nMoulins Démo',
    lecture: L({ resume: 'Confirme la livraison du mardi 17/11 entre 6 h et 7 h.', categorie: 'fournisseur', ton: 'neutre', urgence: 'basse', action: 'ranger', dossier: 'Fournisseurs', b2b: true }) },
  { uid: 1036, de: ADR('Nicolas Hubert', 'nicolas.hubert@exemple.be'), objet: 'Allergènes du pain aux noix', date: '2026-11-10T17:52:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nMon fils est allergique aux arachides. Votre pain aux noix en contient-il des traces ?\n\nMerci,\nNicolas Hubert',
    lecture: L({ resume: 'Demande si le pain aux noix contient des traces d’arachides (fils allergique).', categorie: 'question', ton: 'neutre', urgence: 'normale', action: 'repondre', garde: 'allergenes', confiance: 70 }) },
];
const AUTRES = {   // les autres dossiers : quelques e-mails chacun, pour la liste
  'INBOX.Fournisseurs': [
    { uid: 311, de: ADR('Moulins Démo', 'factures@moulins-demo.exemple.be'), objet: 'Facture F-26-1102 — livraison du 10/11', date: '2026-11-11T10:40:00+01:00', lu: true, pj: true },
    { uid: 309, de: ADR('Laiterie Démo', 'facturation@laiterie-demo.exemple.be'), objet: 'Votre relevé d’octobre', date: '2026-11-03T09:15:00+01:00', lu: true, pj: true },
  ],
  Drafts: [], Sent: [], Archive: [], Junk: [], Trash: [],
  'INBOX.Commandes': [], 'INBOX.Candidatures': [],
};

const DOSSIERS = [
  ['Réception', 'INBOX', 'inbox'], ['Brouillons', 'Drafts', 'drafts'], ['Envoyés', 'Sent', 'sent'], ['Archives', 'Archive', 'archive'], ['Indésirables', 'Junk', 'junk'], ['Corbeille', 'Trash', 'trash'],
  ['Candidatures', 'INBOX.Candidatures', null], ['Commandes', 'INBOX.Commandes', null], ['Fournisseurs', 'INBOX.Fournisseurs', null],
];
const TOTAUX = { Drafts: 4, Sent: 214, Archive: 486, Junk: 3, Trash: 12 };   // ce que les autres dossiers comptent

/* L'état d'une séance de tournage (le module est relu à chaque film). */
const S = { ouverte: false, lus: new Set(), repondus: new Set(), suivis: new Set(), partis: new Map(), niveau: 1, vus: new Set(), annules: new Set(), modeles: [] };
const dans = (m, dossier) => (S.partis.get(m.uid) || 'INBOX') === dossier;
const lu = m => m.lu || S.lus.has(m.uid);
const repondu = m => !!m.repondu || S.repondus.has(m.uid);
const reception = () => RECEPTION.filter(m => dans(m, 'INBOX'));

function etat() {
  const base = { shop: D.SHOP, reliee: true, ouverte: S.ouverte, adresse: MOI.adresse, nom: D.NOM, serveur: 'ssl0.ovh.net', dossiers: [], nonLus: null };
  if (!S.ouverte) { return base; }
  const nonLus = reception().filter(m => !lu(m)).length;
  base.dossiers = DOSSIERS.map(([nom, brut, role]) => ({ nom, brut, role,
    nonLus: role === 'inbox' ? nonLus : role ? 0 : null,
    total: role === 'inbox' ? reception().length : role ? (TOTAUX[brut] || 0) + RECEPTION.filter(m => dans(m, brut)).length : null }));
  base.nonLus = nonLus;
  return base;
}
const offresCitees = t => [...new Set([...String(t).matchAll(/\b(?:offre|devis)\s*(?:n\s*[°o.]?\s*|#\s*)?(\d{2,7})\b/giu)].map(x => +x[1]))];
function ligne(m) {
  return { uid: m.uid, de: m.de, a: [MOI], objet: m.objet, date: m.date, lu: lu(m), suivi: S.suivis.has(m.uid), repondu: repondu(m), pj: !!m.pj, taille: 4200 + (m.uid % 7) * 900, offres: offresCitees(m.objet) };
}
function liste(q) {
  const dossier = q.get('dossier') || 'INBOX', filtre = q.get('filtre') || '', cherche = String(q.get('q') || '').toLowerCase();
  let L = dossier === 'INBOX' ? reception() : (AUTRES[dossier] || []).concat(RECEPTION.filter(m => dans(m, dossier)));
  if (filtre === 'nonlus') { L = L.filter(m => !lu(m)); }
  if (filtre === 'suivis') { L = L.filter(m => S.suivis.has(m.uid)); }
  if (filtre === 'pj') { L = L.filter(m => m.pj); }
  if (cherche) { L = L.filter(m => (m.objet + ' ' + m.de.nom + ' ' + (m.texte || '')).toLowerCase().includes(cherche)); }
  L = L.slice().sort((a, b) => b.date.localeCompare(a.date));
  return { dossier, page: 1, parPage: 50, total: L.length, messages: L.map(ligne) };
}
function message(q) {
  const uid = +q.get('uid'), m = RECEPTION.find(x => x.uid === uid) || Object.values(AUTRES).flat().find(x => x.uid === uid);
  if (!m) { return { __status: 404, json: { error: 'Cet e-mail n’est plus dans le dossier.' } }; }
  if (!q.get('garder')) { S.lus.add(uid); }
  const texte = m.texte || ('Bonjour,\n\n' + m.objet + '.\n\nBien à vous,\n' + m.de.nom);
  return { uid, dossier: q.get('dossier') || 'INBOX', objet: m.objet, de: m.de, repondreA: m.de, a: [MOI], cc: [], date: m.date, messageId: '<' + uid + '@exemple.be>',
    texte, html: null, lourd: false, pieces: m.pieces || [], suivi: S.suivis.has(uid), repondu: repondu(m), offres: offresCitees(m.objet + '\n' + texte), moi: MOI.adresse };
}
function geste(c) {
  const uids = c.tout ? liste(new URLSearchParams({ dossier: c.dossier || 'INBOX' })).messages.map(x => x.uid) : (c.uids || []).map(Number);
  const vers = { archiver: 'Archive', corbeille: 'Trash', effacer: '__efface', deplacer: c.nouveau ? 'INBOX.' + c.nouveau : c.vers }[c.action];
  uids.forEach(u => {
    if (vers) { S.partis.set(u, vers); }
    if (c.action === 'lu') { S.lus.add(u); }
    if (c.action === 'nonlu') { S.lus.delete(u); RECEPTION.forEach(m => { if (m.uid === u) { m.lu = false; } }); }
    if (c.action === 'suivi') { S.suivis.add(u); }
    if (c.action === 'nonsuivi') { S.suivis.delete(u); }
  });
  if (c.nouveau && !DOSSIERS.some(d => d[0] === c.nouveau)) { DOSSIERS.push([c.nouveau, 'INBOX.' + c.nouveau, null]); return { ok: true, n: uids.length, cree: c.nouveau }; }
  return { ok: true, n: uids.length };
}
function envoyer(c) {
  const r = c.reponse && +c.reponse.uid;
  if (r) { S.repondus.add(r); S.lus.add(r); }
  return { ok: true, messageId: '<' + Date.now() + '.demo@exemple.be>', avertissement: null };
}

/* Les lectures IA : le briefing (les non-lus) et le radar (les trois dernières semaines). */
function lectures(q) {
  const quoi = q.get('quoi') || 'nonlus';
  const L = (quoi === 'recents' ? reception() : reception().filter(m => !lu(m))).slice().sort((a, b) => b.date.localeCompare(a.date));
  return { quoi, elements: L.map(m => ({ uid: m.uid, de: m.de, repondreA: m.de, objet: m.objet, date: m.date, lu: lu(m), repondu: repondu(m), suivi: S.suivis.has(m.uid), lecture: m.lecture })),
    aLire: [], ia: true, lot: 8 };
}

/* Le pilote : allumé lundi en « Brouillons » ; il a rédigé, le magasin envoie. */
const GARDES = ['parle de prix', 'demande une remise ou un geste', 'réclamation', 'question d’allergènes ou de santé', 'commande ou demande d’offre à confirmer', 'client professionnel', 'personnel ou RH', 'l’IA n’est pas sûre'];
const PILOTE = [
  { id: 58, uid: 1048, de: 'Marc Dubois', adr: 'marc.dubois@exemple.be', objet: 'Éclairs de mardi', categorie: 'reclamation', confiance: 76, etat: 'brouillon', raison: 'réclamation · brouillon déposé', le: '2026-11-19T14:10:00+01:00' },
  { id: 57, uid: 1047, de: 'Moulins Démo', adr: 'factures@moulins-demo.exemple.be', objet: 'Facture F-26-1187 — livraison du 17/11', categorie: 'facture', confiance: 95, etat: 'attente', raison: 'rien à répondre seul', le: '2026-11-19T11:40:00+01:00', reponse: '' },
  { id: 56, uid: 1046, de: 'Léa Martin', adr: 'lea.martin@exemple.be', objet: 'Candidature — vendeuse le week-end', categorie: 'candidature', confiance: 91, etat: 'brouillon', raison: 'cran « Brouillons » · brouillon déposé', le: '2026-11-19T09:50:00+01:00' },
  { id: 55, uid: 1049, de: 'Sophie Lambert', adr: 'sophie.lambert@exemple.be', objet: 'Commande pour une communion le 28/11', categorie: 'commande', confiance: 84, etat: 'brouillon', raison: 'commande ou demande d’offre à confirmer · brouillon déposé', le: '2026-11-19T15:20:00+01:00' },
  { id: 54, uid: 1045, de: 'Crèche Les Petits Pas', adr: 'commandes@creche-demo.exemple.be', objet: 'Commande de la semaine prochaine', categorie: 'commande', confiance: 88, etat: 'vu', raison: 'client professionnel · brouillon déposé', le: '2026-11-18T16:30:00+01:00' },
  { id: 53, uid: 1044, de: 'Thomas Renard', adr: 'thomas.renard@exemple.be', objet: 'Re: Notre offre n° 412', categorie: 'commande', confiance: 86, etat: 'vu', raison: 'commande ou demande d’offre à confirmer · brouillon déposé', le: '2026-11-18T10:10:00+01:00' },
  { id: 52, uid: 1043, de: 'Julie Mercier', adr: 'julie.mercier@exemple.be', objet: 'Baptême le samedi 28 novembre', categorie: 'commande', confiance: 83, etat: 'vu', raison: 'commande ou demande d’offre à confirmer · brouillon déposé', le: '2026-11-17T19:20:00+01:00' },
];
function pilote() {
  const rep = p => { const m = RECEPTION.find(x => x.uid === p.uid); return p.reponse != null ? p.reponse : (m && m.lecture.reponse) || ''; };
  const vue = p => ({ id: p.id, uid: p.uid, de_adresse: p.adr, de_nom: p.de, objet: p.objet, categorie: p.categorie, confiance: p.confiance, reponse: rep(p),
    etat: S.vus.has(p.id) ? 'vu' : p.etat, raison: p.raison, envoi_le: null, cree_le: p.le });
  const L = PILOTE.map(vue).sort((a, b) => b.cree_le.localeCompare(a.cree_le));
  const attente = L.filter(p => p.etat === 'attente' || p.etat === 'brouillon');
  return { niveau: S.niveau, niveaux: { 0: 'Éteint', 1: 'Brouillons', 2: 'Simples' }, seuil: 85, delai: 10, horloge: '2026-11-19T15:30:00+01:00', ia: true,
    fil: L.filter(p => p.etat !== 'attente' && p.etat !== 'brouillon'), attente,
    stats: { envoyes: 0, brouillons: PILOTE.filter(p => /brouillon déposé/.test(p.raison)).length, annules: 0, planifies: 0, attente: attente.length }, gardes: GARDES };
}

/* Les modèles du réseau (BM_MODELES_BASE) et les messages automatiques (BM_REGLES), tels que le serveur les rend. */
const MODELES_RESEAU = [
  { id: 'b-accuse', nom: 'Accusé de réception', objet: 'Re: {objet}', corps: 'Bonjour {prenom},\n\nMerci pour votre message. Nous l’avons bien reçu et nous vous répondons au plus vite.\n\nBelle journée,\n{magasin}' },
  { id: 'b-precisions', nom: 'Demande de précisions', objet: 'Re: {objet}', corps: 'Bonjour {prenom},\n\nMerci pour votre demande. Pour vous faire une offre précise, pourriez-vous nous indiquer :\n- le nombre de personnes,\n- la date et l’heure souhaitées,\n- retrait en magasin ou livraison (et l’adresse) ?\n\nÀ très vite,\n{magasin}' },
  { id: 'b-offre', nom: 'Envoi de l’offre', objet: 'Notre offre n° {offre}', corps: 'Bonjour {prenom},\n\nMerci pour votre demande. Vous trouverez en pièce jointe notre offre n° {offre} ({total}) pour le {jour}.\n\nPour la confirmer, il suffit de répondre à cet e-mail.\n\nÀ très bientôt,\n{magasin}' },
  { id: 'b-relance', nom: 'Relance d’une offre', objet: 'Notre offre n° {offre}', corps: 'Bonjour {prenom},\n\nNous revenons vers vous au sujet de notre offre n° {offre} pour le {jour}. Avez-vous pu la consulter ? Nous pouvons l’adapter si besoin.\n\nBien à vous,\n{magasin}' },
  { id: 'b-confirmation', nom: 'Confirmation de commande', objet: 'Votre commande du {jour}', corps: 'Bonjour {prenom},\n\nC’est confirmé : votre commande est prévue pour le {jour}. Nous vous attendons au magasin.\n\nMerci de votre confiance,\n{magasin}' },
  { id: 'b-rappel', nom: 'Rappel la veille', objet: 'Votre commande de demain', corps: 'Bonjour {prenom},\n\nPetit rappel : votre commande est prévue demain, le {jour}. Tout est prêt de notre côté.\n\nÀ demain,\n{magasin}' },
];
const VARIABLES = { '{prenom}': 'le prénom du client', '{nom}': 'son nom', '{objet}': 'l’objet de son e-mail', '{magasin}': 'le nom du magasin', '{offre}': 'le n° de l’offre', '{total}': 'son total TTC', '{jour}': 'le jour de l’événement' };
const modeles = () => ({ reseau: MODELES_RESEAU, magasin: S.modeles, variables: VARIABLES });
function automatiques() {
  const m = id => MODELES_RESEAU.find(x => x.id === id);
  return { regles: [
    { regle: 'accuse', nom: 'Accusé de réception', texte: 'Répond à chaque nouvel e-mail reçu — une fois par expéditeur sur la période choisie ; jamais aux envois automatiques, aux listes, aux « ne pas répondre », ni à la boîte elle-même.', actif: false, jours: 3, objet: m('b-accuse').objet, corps: m('b-accuse').corps },
    { regle: 'relance', nom: 'Relance d’une offre sans réponse', texte: 'Relance une fois le client d’une offre en cours, restée sans suite depuis le nombre de jours choisi (et dont l’événement n’est pas passé).', actif: true, jours: 3, objet: m('b-relance').objet, corps: m('b-relance').corps },
    { regle: 'rappel', nom: 'Rappel la veille de la commande', texte: 'Écrit au client d’une offre convertie en commande la veille du jour prévu.', actif: true, jours: 1, objet: m('b-rappel').objet, corps: m('b-rappel').corps },
  ], journal: [
    { le: '2026-11-18 17:30:00', nom: 'Rappel la veille de la commande', destinataire: 'claire.petit@exemple.be', objet: 'Votre commande de demain', erreur: null },
    { le: '2026-11-16 09:10:00', nom: 'Relance d’une offre sans réponse', destinataire: 'thomas.renard@exemple.be', objet: 'Notre offre n° 412', erreur: null },
  ], horloge: '2026-11-19T15:30:00+01:00' };
}

/* Les offres du magasin (Vendre › Offres) : « Joindre une offre » les liste. */
function offres() {
  const o = (id, nom, client, jour, etat, lignes) => {
    const L = lignes.map(([n, q]) => { const p = D.PRODUITS.find(x => x.nom === n); return { idProduit: p.pid, nom: n, qte: q, pu: p.prix, remiseValeur: null, remiseMontant: 0, remiseType: null, total: D.r2(q * p.prix), totalNet: D.r2(q * p.prix / 1.06), tva: 6, puNet: D.r2(p.prix / 1.06) }; });
    const t = D.r2(L.reduce((s, x) => s + x.total, 0));
    return { id, shop: 4, nom, description: '', clientId: client.id, client: Object.assign({ b2b: false, contact: '', tva: '', rue: '', cp: '', ville: '', tel: '' }, client), retrait: true,
      livraison: { rue: '', cp: '', ville: '', frais: 0 }, conditions: '', jour, cree: '2026-11-17 17:40:00', statut: etat === 'conv' ? 1 : 0, actif: true, etat,
      lignes: L, totalProduits: t, totalNet: D.r2(t / 1.06), total: t };
  };
  const L = [
    o(415, 'Baptême du 28/11', { id: 2209, nom: 'Julie Mercier', email: 'julie.mercier@exemple.be' }, '2026-11-28', 'cours', [['Éclair au chocolat', 15], ['Millefeuille', 15]]),
    o(412, 'Anniversaire du 21/11', { id: 2187, nom: 'Thomas Renard', email: 'thomas.renard@exemple.be' }, '2026-11-21', 'conv', [['Entremets trois chocolats', 1]]),
  ];
  return { shop: D.SHOP, offres: L, n: { cours: 1, conv: 1, arch: 0, tout: 2 }, lu: '15:40' };
}
/* Les produits du magasin, leur prix et leur coût (le radar estime les demandes aux prix du catalogue). */
function produits() {
  const L = D.PRODUITS.map((p, i) => ({ id: p.pid, nom: p.nom, catId: 10 + D.CATALOGUE.findIndex(c => c[1] === p.cat), cat: p.cat, prixTtc: p.prix, prixHt: D.r2(p.prix / 1.06), tva: 6,
    cout: D.r2(p.prix / 1.06 * (1 - p.taux)), source: i % 5 === 4 ? 'reseau' : 'magasin' }));
  return { shop: D.SHOP, produits: L, nMagasin: L.filter(p => p.source === 'magasin').length, nReseau: L.filter(p => p.source === 'reseau').length, nSansCout: 0, seuils: { ko: 3, att: 3.5, food: 32 } };
}

module.exports = function (m, p, q, c) {
  c = c && typeof c === 'object' ? c : {};
  // Les produits (le radar estime les demandes) et les offres (« Joindre une offre ») : servis ici une fois la boîte
  // ouverte seulement, pour laisser les pages Offres et Promotions à leurs propres données.
  if (S.ouverte && p === '/exploitation/promos-magasin/produits' && m === 'GET') { return produits(); }
  if (S.ouverte && p === '/exploitation/offres-client' && m === 'GET') { return offres(); }
  if (p !== '/dashboard/boite' && !p.startsWith('/dashboard/boite/')) { return undefined; }
  const sous = p.slice('/dashboard/boite'.length);
  if (sous === '' && m === 'GET') { return etat(); }
  if (sous === '/ouvrir') { if (!c.motDePasse) { return { __status: 401, json: { error: 'Mot de passe incorrect.' } }; } S.ouverte = true; return { ok: true }; }
  if (sous === '/fermer') { S.ouverte = false; return { ok: true }; }
  if (sous === '/automatisations' || sous.startsWith('/automatisations/') || sous === '/equipe') { return undefined; }   // Paramètres › Mails et automatisations : hors de cette page
  if (!S.ouverte) { return { __status: 401, json: { error: 'Ouvrez la boîte avec son mot de passe.', etat: 'fermee' } }; }
  if (sous === '/messages') { return liste(q); }
  if (sous === '/message') { return message(q); }
  if (sous === '/geste') { return geste(c); }
  if (sous === '/envoyer') { return envoyer(c); }
  if (sous === '/lectures') { return lectures(q); }
  if (sous === '/lire') { return { lectures: {} }; }
  if (sous === '/ranger') {
    return { modele: 'claude-haiku', propositions: (c.uids || []).map(u => { const x = RECEPTION.find(y => y.uid === +u) || { de: null, objet: '', lecture: {} };
      const f = x.lecture.dossier || (x.lecture.categorie === 'candidature' ? 'Candidatures' : x.lecture.categorie === 'commande' || x.lecture.categorie === 'offre' ? 'Commandes' : '');
      return { uid: +u, de: x.de, objet: x.objet, cible: f ? 'd:INBOX.' + f : 'laisser', raison: f ? 'un e-mail ' + (x.lecture.categorie || '') + ', comme ceux du dossier' : 'attend encore une réponse' }; }) };
  }
  if (sous === '/pilote') {
    if (m === 'POST') {
      if (c.action === 'vu') { S.vus.add(+c.id); } else if (c.action === 'annuler') { S.annules.add(+c.id); } else if (c.niveau != null) { S.niveau = +c.niveau; }
      return Object.assign({ ok: true }, pilote());
    }
    return pilote();
  }
  if (sous === '/modeles') {
    if (m === 'POST' && c.action === 'creer') { S.modeles.push({ id: 900 + S.modeles.length, nom: c.nom, objet: c.objet, corps: c.corps, maj: '2026-11-19 15:40:00' }); }
    return modeles();
  }
  if (sous === '/automatiques') { return automatiques(); }
  return undefined;
};
