/* La boîte mail du magasin (Boîte mail, ?vue=boite) : boite.js, servi par panel/src/boite_mail.php et boite_ia.php.
 * Données fictives : la boîte magasin.demo@exemple.be, des expéditeurs inventés aux adresses @exemple.be, le jeudi
 * 19/11/2026 à 15 h 40. La boîte est reliée et FERMÉE au départ (la forme que lisent toutes les vues, le rail compris) ;
 * elle s'ouvre avec son mot de passe (POST /dashboard/boite/ouvrir, n'importe lequel sauf vide), comme au magasin.
 * « Délier » (POST …/delier, boîte ouverte) la rend non reliée : la porte demande alors l'adresse et le mot de passe
 * (POST …/relier, qui l'ouvre aussi sur l'appareil, comme bmRelier).
 * Les gestes changent la boîte relue : un e-mail ouvert devient lu, une réponse le marque « répondu » et rejoint les
 * Envoyés, un e-mail archivé, rangé, mis à la corbeille ou remis en Réception change de dossier, « effacer » l'ôte pour
 * de bon, « Nouveau dossier » crée le dossier ; les modèles du magasin se créent, se modifient, se suppriment ; les
 * messages automatiques s'allument et se règlent ; le cran du pilote change, « C'est traité » et « Annuler l'envoi »
 * s'appliquent.
 * Les lectures IA : celles du briefing (les non-lus) sont faites ; quatre e-mails plus anciens de la réception ne sont
 * pas encore lus pour le Radar (« Lire et mettre sur le radar » : POST …/lire).
 * L'horloge (GET …/cron, celle des messages automatiques et du pilote) : un passage fait arriver un e-mail simple
 * (Martine Wauters, 15 h 34) ; le pilote allumé le lit, et en « Simples » il prépare un envoi « part dans 10 min ».
 * Les produits du Radar et les offres de « Joindre une offre » viennent de 12_promotions.js et 13_offres.js (lus avant
 * ce module) : les e-mails citent les offres de 13_offres.js (n° 418, Hélène Marchal) et ses clients (Bureau Horizon SRL). */
const D = require('./lib/demo');

const MOI = { nom: D.NOM, adresse: 'magasin.demo@exemple.be' };
const ADR = (nom, adresse) => ({ nom, adresse });
const LIRE = { dossier: '', simple: false, confiance: 90, garde: '', jour: '', produits: [], personnes: 0, b2b: false, robot: false, reponse: '' };
const L = o => Object.assign({}, LIRE, o);
const P = (nom, quantite) => ({ nom, quantite });
const MAINTENANT = '2026-11-19T15:40:00+01:00';

/* La réception : les quatre non-lus d'aujourd'hui, puis les e-mails des trois dernières semaines (les quatre plus
 * anciens, `radar: true`, ne sont pas encore lus par l'IA). */
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
  { uid: 1044, de: ADR('Hélène Marchal', 'helene.marchal@exemple.be'), objet: 'Re: Notre offre n° 418', date: '2026-11-18T10:02:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nC’est parfait pour nous : je confirme l’offre n° 418 pour l’anniversaire du samedi 21/11, 30 personnes.\nJe passerai tout chercher vers 10 h.\n\nMerci,\nHélène Marchal',
    lecture: L({ resume: 'Confirme l’offre n° 418 : l’anniversaire de 30 personnes, samedi 21/11, retrait vers 10 h.', categorie: 'commande', ton: 'chaleureux', urgence: 'normale', action: 'archiver', jour: '2026-11-21',
      produits: [P('mini-croissant', 40), P('éclair au chocolat', 20), P('tartelette aux fruits', 15), P('sandwich jambon-beurre', 20)], personnes: 30 }) },
  { uid: 1043, de: ADR('Julie Mercier', 'julie.mercier@exemple.be'), objet: 'Baptême le samedi 28 novembre', date: '2026-11-17T19:15:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nPour le baptême de notre fils, le samedi 28 novembre, nous aimerions 15 éclairs au chocolat et 15 millefeuilles. Nous serons 15.\n\nBonne soirée,\nJulie Mercier',
    lecture: L({ resume: 'Baptême le 28/11, 15 personnes : 15 éclairs au chocolat et 15 millefeuilles.', categorie: 'commande', ton: 'chaleureux', urgence: 'normale', action: 'offre', garde: 'commande', jour: '2026-11-28', produits: [P('éclair au chocolat', 15), P('millefeuille', 15)], personnes: 15 }) },
  { uid: 1042, de: ADR('Nathalie Fiévez', 'accueil@horizon.exemple.be'), objet: 'Plateaux lunch pour le 2 décembre', date: '2026-11-17T09:20:00+01:00', lu: true,
    texte: 'Bonjour,\n\nNous organisons une réunion le mercredi 2 décembre. Pourriez-vous nous livrer 18 sandwichs poulet crudités et 18 cookies au chocolat pour 12 h 30 ?\nMerci de nous envoyer une offre avec la TVA.\n\nNathalie Fiévez\nService achats — Bureau Horizon SRL',
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
  { uid: 1038, de: ADR('École communale Démo', 'direction@ecole-demo.exemple.be'), cc: [ADR('Économat — École communale Démo', 'economat@ecole-demo.exemple.be')], objet: 'Collation du 4 décembre', date: '2026-11-13T13:30:00+01:00', lu: true,
    texte: 'Madame, Monsieur,\n\nPour la journée sportive du vendredi 4 décembre, nous souhaiterions 45 pains au chocolat et 45 brioches au sucre, livrés à 10 h.\nL’économat, en copie, reçoit la facture.\n\nCordialement,\nLa direction',
    lecture: L({ resume: 'Journée sportive du 4/12 : 45 pains au chocolat et 45 brioches au sucre, livrés à 10 h.', categorie: 'commande', ton: 'neutre', urgence: 'normale', action: 'offre', garde: 'b2b', jour: '2026-12-04', produits: [P('pain au chocolat', 45), P('brioche au sucre', 45)], personnes: 45, b2b: true }) },
  { uid: 1037, de: ADR('Moulins Démo', 'commandes@moulins-demo.exemple.be'), objet: 'Confirmation de livraison — mardi 17/11', date: '2026-11-12T08:12:00+01:00', lu: true,
    texte: 'Bonjour,\n\nNous confirmons la livraison de mardi 17/11 entre 6 h et 7 h : farine T55, farine de seigle, beurre de tourage.\n\nService commandes\nMoulins Démo',
    lecture: L({ resume: 'Confirme la livraison du mardi 17/11 entre 6 h et 7 h.', categorie: 'fournisseur', ton: 'neutre', urgence: 'basse', action: 'ranger', dossier: 'Fournisseurs', b2b: true }) },
  { uid: 1036, de: ADR('Nicolas Hubert', 'nicolas.hubert@exemple.be'), objet: 'Allergènes du pain aux noix', date: '2026-11-10T17:52:00+01:00', lu: true, repondu: true,
    texte: 'Bonjour,\n\nMon fils est allergique aux arachides. Votre pain aux noix en contient-il des traces ?\n\nMerci,\nNicolas Hubert',
    lecture: L({ resume: 'Demande si le pain aux noix contient des traces d’arachides (fils allergique).', categorie: 'question', ton: 'neutre', urgence: 'normale', action: 'repondre', garde: 'allergenes', confiance: 70 }) },
  { uid: 1035, de: ADR('Aurélie Simon', 'aurelie.simon@exemple.be'), objet: 'Mariage le samedi 5 décembre', date: '2026-11-09T18:30:00+01:00', lu: true, radar: true,
    texte: 'Bonjour,\n\nPour notre mariage, le samedi 5 décembre, nous aimerions 2 entremets trois chocolats, 60 mini-croissants et 40 éclairs au chocolat. Nous serons 60.\nPouvez-vous nous dire si c’est possible ?\n\nAurélie Simon',
    lecture: L({ resume: 'Mariage le 5/12, 60 personnes : 2 entremets trois chocolats, 60 mini-croissants et 40 éclairs.', categorie: 'offre', ton: 'chaleureux', urgence: 'normale', action: 'offre', garde: 'commande', jour: '2026-12-05', produits: [P('entremets trois chocolats', 2), P('mini-croissant', 60), P('éclair au chocolat', 40)], personnes: 60 }) },
  { uid: 1034, de: ADR('Philippe Gérard', 'philippe.gerard@exemple.be'), objet: 'Pain de campagne brûlé', date: '2026-11-06T12:10:00+01:00', lu: true, repondu: true, radar: true,
    texte: 'Bonjour,\n\nLe pain de campagne acheté ce matin était brûlé dessous. Ce n’est pas la première fois.\n\nPhilippe Gérard',
    lecture: L({ resume: 'Le pain de campagne du matin était brûlé dessous ; pas la première fois.', categorie: 'reclamation', ton: 'mecontent', urgence: 'normale', action: 'repondre', garde: 'reclamation', confiance: 80 }) },
  { uid: 1033, de: ADR('Fournil Pro Magazine', 'newsletter@fournil-pro.exemple.be'), objet: 'Spécial galettes des rois', date: '2026-11-02T07:00:00+01:00', lu: true, radar: true,
    texte: 'La lettre de la semaine : la galette des rois, de la frangipane à la vitrine.',
    lecture: L({ resume: 'La lettre d’information de la semaine : la galette des rois.', categorie: 'newsletter', ton: 'neutre', urgence: 'basse', action: 'archiver', robot: true }) },
  { uid: 1032, de: ADR('Collège Démo', 'secretariat@college-demo.exemple.be'), objet: 'Réunion de parents du 27 novembre', date: '2026-10-30T10:40:00+01:00', lu: true, radar: true,
    texte: 'Bonjour,\n\nPour la réunion de parents du vendredi 27 novembre, nous aimerions 40 cookies au chocolat et 40 chaussons aux pommes, à retirer à 17 h.\n\nLe secrétariat',
    lecture: L({ resume: 'Réunion de parents du 27/11 : 40 cookies et 40 chaussons aux pommes, retrait à 17 h.', categorie: 'commande', ton: 'neutre', urgence: 'normale', action: 'offre', garde: 'b2b', jour: '2026-11-27', produits: [P('cookie chocolat', 40), P('chausson aux pommes', 40)], personnes: 40, b2b: true }) },
].map(m => Object.assign({ dossier: 'INBOX' }, m));

/* L'e-mail qu'apporte le passage de l'horloge (GET …/cron) : simple, le pilote peut y répondre seul. */
const ARRIVE = { uid: 1050, dossier: 'INBOX', de: ADR('Martine Wauters', 'martine.wauters@exemple.be'), objet: 'Merci pour la tarte aux pommes', date: '2026-11-19T15:34:00+01:00', lu: false,
  texte: 'Bonjour,\n\nLa tarte aux pommes de dimanche était délicieuse : toute la famille s’est régalée. Merci et à bientôt !\n\nMartine Wauters',
  lecture: L({ resume: 'Remercie pour la tarte aux pommes de dimanche : toute la famille s’est régalée.', categorie: 'autre', ton: 'chaleureux', urgence: 'basse', action: 'repondre', simple: true, confiance: 94,
    reponse: 'Bonjour Martine,\n\nMerci beaucoup pour votre message : il fait plaisir à toute l’équipe !\n\nÀ bientôt,\nL’équipe de Magasin démo' }) };

/* Les autres dossiers. */
const pdf = (nom, taille) => [{ id: '2', nom, type: 'application/pdf', taille }];
const E = (dossier, uid, de, objet, date, o) => Object.assign({ dossier, uid, de, objet, date, lu: true }, o || {});
const AUTRES = [
  E('INBOX.Fournisseurs', 311, ADR('Moulins Démo', 'factures@moulins-demo.exemple.be'), 'Facture F-26-1102 — livraison du 10/11', '2026-11-11T10:40:00+01:00', { pj: true, pieces: pdf('F-26-1102.pdf', 83104) }),
  E('INBOX.Fournisseurs', 309, ADR('Laiterie Démo', 'facturation@laiterie-demo.exemple.be'), 'Votre relevé d’octobre', '2026-11-03T09:15:00+01:00', { pj: true, pieces: pdf('Releve-octobre.pdf', 61220) }),
  E('INBOX.Candidatures', 321, ADR('Emma Leclercq', 'emma.leclercq@exemple.be'), 'Candidature — apprentie pâtissière', '2026-11-04T18:22:00+01:00', { pj: true, pieces: pdf('CV-Emma-Leclercq.pdf', 98400), repondu: true }),
  E('INBOX.Candidatures', 322, ADR('Yanis Haddad', 'yanis.haddad@exemple.be'), 'Job étudiant pour décembre', '2026-10-27T20:05:00+01:00', { repondu: true }),
  E('INBOX.Commandes', 331, ADR('Crèche Les Petits Pas', 'commandes@creche-demo.exemple.be'), 'Commande de la semaine du 16/11', '2026-11-11T16:02:00+01:00', { repondu: true }),
  E('INBOX.Commandes', 332, ADR('École communale Démo', 'direction@ecole-demo.exemple.be'), 'Collation du 6 novembre', '2026-10-28T13:12:00+01:00', { repondu: true }),
  // Les brouillons : les trois réponses que le pilote a rédigées (cran « Brouillons »), et une commande à finir.
  E('Drafts', 404, MOI, 'Re: Commande pour une communion le 28/11', '2026-11-19T15:20:00+01:00', { a: [ADR('Sophie Lambert', 'sophie.lambert@exemple.be')], texte: RECEPTION[0].lecture.reponse }),
  E('Drafts', 403, MOI, 'Re: Éclairs de mardi', '2026-11-19T14:10:00+01:00', { a: [ADR('Marc Dubois', 'marc.dubois@exemple.be')], texte: RECEPTION[1].lecture.reponse }),
  E('Drafts', 402, MOI, 'Re: Candidature — vendeuse le week-end', '2026-11-19T09:50:00+01:00', { a: [ADR('Léa Martin', 'lea.martin@exemple.be')], texte: RECEPTION[3].lecture.reponse }),
  E('Drafts', 401, MOI, 'Commande de farine — décembre', '2026-11-16T18:30:00+01:00', { a: [ADR('Moulins Démo', 'commandes@moulins-demo.exemple.be')], texte: 'Bonjour,\n\nPour la livraison du 1er décembre :\n' }),
  // Les envoyés : les réponses du magasin et deux messages automatiques.
  E('Sent', 508, MOI, 'Notre offre n° 417', '2026-11-19T15:10:00+01:00', { a: [ADR('Nathalie Fiévez', 'accueil@horizon.exemple.be')] }),
  E('Sent', 507, MOI, 'Votre commande de demain', '2026-11-18T17:30:00+01:00', { a: [ADR('Claire Petit', 'claire.petit@exemple.be')] }),
  E('Sent', 506, MOI, 'Re: Commande de la semaine prochaine', '2026-11-18T16:42:00+01:00', { a: [ADR('Crèche Les Petits Pas', 'commandes@creche-demo.exemple.be')] }),
  E('Sent', 505, MOI, 'Re: Notre offre n° 418', '2026-11-18T10:31:00+01:00', { a: [ADR('Hélène Marchal', 'helene.marchal@exemple.be')] }),
  E('Sent', 504, MOI, 'Re: Baptême le samedi 28 novembre', '2026-11-18T08:55:00+01:00', { a: [ADR('Julie Mercier', 'julie.mercier@exemple.be')] }),
  E('Sent', 503, MOI, 'Re: Merci pour le gâteau !', '2026-11-16T09:05:00+01:00', { a: [ADR('Claire Petit', 'claire.petit@exemple.be')] }),
  E('Sent', 502, MOI, 'Re: Goûter de Saint-Nicolas le 6 décembre', '2026-11-14T15:20:00+01:00', { a: [ADR('Asbl Quartier Démo', 'contact@quartier-demo.exemple.be')] }),
  E('Sent', 501, MOI, 'Re: Allergènes du pain aux noix', '2026-11-11T08:40:00+01:00', { a: [ADR('Nicolas Hubert', 'nicolas.hubert@exemple.be')] }),
  // Les indésirables et la corbeille.
  E('Junk', 703, ADR('Colis Express', 'suivi@colis-express-demo.exemple.be'), 'Votre colis est en attente de livraison', '2026-11-18T06:14:00+01:00'),
  E('Junk', 702, ADR('Avis Rapides', 'contact@avis-rapides-demo.exemple.be'), '500 avis Google en 48 h', '2026-11-16T22:40:00+01:00'),
  E('Junk', 701, ADR('Loterie Démo', 'gagnant@loterie-demo.exemple.be'), 'Félicitations, vous avez gagné !', '2026-11-12T03:05:00+01:00'),
  E('Trash', 803, ADR('Laiterie Démo', 'livraisons@laiterie-demo.exemple.be'), 'Nouvel horaire de livraison dès le 23/11', '2026-11-17T08:05:00+01:00',
    { texte: 'Bonjour,\n\nÀ partir du lundi 23/11, nos livraisons passent entre 5 h 30 et 6 h 15.\n\nLe service livraisons\nLaiterie Démo' }),
  E('Trash', 802, ADR('Emballages Démo', 'info@emballages-demo.exemple.be'), 'Nos boîtes à bûches 2026', '2026-11-15T10:20:00+01:00'),
  E('Trash', 801, ADR('Fournil Pro Magazine', 'newsletter@fournil-pro.exemple.be'), 'Les tendances du pain', '2026-11-09T07:00:00+01:00'),
];
/* Les archives : soixante-deux e-mails de l'automne (plus d'une page de 50). */
(function archives() {
  const NOMS = ['Anne Dupont', 'Luc Lemaire', 'Chantal Hermans', 'Pierre Simon', 'Isabelle Lambotte', 'Olivier Charlier', 'Véronique Maes', 'Benoît Collin',
    'Didier Jacobs', 'Nadia Benali', 'Kevin Peeters', 'Sandrine Leroy', 'Patrick Renson', 'Céline Dumont', 'Sylvie Lejeune', 'Thierry Bastin'];
  const adr = n => n.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, '.') + '@exemple.be';
  const SUJETS = [
    [null, 'Merci pour le gâteau d’anniversaire', { repondu: true }], [null, 'Commande de pains pour samedi', { repondu: true }],
    [ADR('Moulins Démo', 'factures@moulins-demo.exemple.be'), 'Facture', { pj: true }], [null, 'Horaires du dimanche ?', { repondu: true }],
    [ADR('Fournil Pro Magazine', 'newsletter@fournil-pro.exemple.be'), 'La lettre de la semaine', {}], [null, 'Gâteau pour un départ à la retraite', { repondu: true }],
    [ADR('Laiterie Démo', 'commandes@laiterie-demo.exemple.be'), 'Confirmation de livraison', {}], [null, 'Question sur les allergènes', { repondu: true }],
    [ADR('Emballages Démo', 'info@emballages-demo.exemple.be'), 'Les nouveautés d’automne', {}], [null, 'Merci pour l’accueil', {}],
  ];
  const d0 = Date.parse('2026-11-12T09:00:00+01:00');
  for (let i = 0; i < 62; i++) {
    const [de, objet, o] = SUJETS[i % SUJETS.length], nom = NOMS[(i * 7) % NOMS.length];
    const t = new Date(d0 - i * 12.5 * 3600e3 - (i % 5) * 47 * 60e3 + 3600e3);   // l'heure de Bruxelles (UTC+1), quel que soit le fuseau de la machine
    const date = t.toISOString().slice(0, 16) + ':00+01:00';
    const ob = objet === 'Facture' ? 'Facture F-26-' + (1090 - i) : objet === 'Confirmation de livraison' ? objet + ' du ' + date.slice(8, 10) + '/' + date.slice(5, 7) : objet;
    AUTRES.push(E('Archive', 600 + i, de || ADR(nom, adr(nom)), ob, date, Object.assign({}, o, o.pj ? { pieces: pdf(ob.replace('Facture ', '') + '.pdf', 80000 + i * 97) } : {})));
  }
})();

const DOSSIERS = [
  ['Réception', 'INBOX', 'inbox'], ['Brouillons', 'Drafts', 'drafts'], ['Envoyés', 'Sent', 'sent'], ['Archives', 'Archive', 'archive'], ['Indésirables', 'Junk', 'junk'], ['Corbeille', 'Trash', 'trash'],
  ['Candidatures', 'INBOX.Candidatures', null], ['Commandes', 'INBOX.Commandes', null], ['Fournisseurs', 'INBOX.Fournisseurs', null],
];

/* L'état d'une séance de tournage (le module est relu à chaque film). */
const S = { reliee: true, adresse: MOI.adresse, ouverte: false, lus: new Set(), nonlus: new Set(), repondus: new Set(), suivis: new Set(), partis: new Map(),
  arrives: [], envoyes: [], niveau: 1, radar: new Set(), lectures: new Map(), modeles: [], horloge: '2026-11-19T15:30:00+01:00', passage: false };
const tous = () => RECEPTION.concat(S.arrives, AUTRES, S.envoyes);
const ou = m => S.partis.get(m.uid) || m.dossier;
const lu = m => !S.nonlus.has(m.uid) && (m.lu || S.lus.has(m.uid));
const repondu = m => !!m.repondu || S.repondus.has(m.uid);
const dans = dossier => tous().filter(m => ou(m) === dossier);
const reception = () => dans('INBOX');

function etat() {
  if (!S.reliee) { return { shop: D.SHOP, reliee: false, ouverte: false, adresse: null, nom: null, serveur: 'ssl0.ovh.net', dossiers: [], nonLus: null }; }
  const base = { shop: D.SHOP, reliee: true, ouverte: S.ouverte, adresse: S.adresse, nom: D.NOM, serveur: 'ssl0.ovh.net', dossiers: [], nonLus: null };
  if (!S.ouverte) { return base; }
  const nonLus = reception().filter(m => !lu(m)).length;
  base.dossiers = DOSSIERS.map(([nom, brut, role]) => ({ nom, brut, role, nonLus: role === 'inbox' ? nonLus : role ? dans(brut).filter(m => !lu(m)).length : null, total: role ? dans(brut).length : null }));
  base.nonLus = nonLus;
  return base;
}
const offresCitees = t => [...new Set([...String(t).matchAll(/\b(?:offre|devis)\s*(?:n\s*[°o.]?\s*|#\s*)?(\d{2,7})\b/giu)].map(x => +x[1]))];
function ligne(m) {
  return { uid: m.uid, de: m.de, a: m.a || [MOI], objet: m.objet, date: m.date, lu: lu(m), suivi: S.suivis.has(m.uid), repondu: repondu(m), pj: !!m.pj, taille: 4200 + (m.uid % 7) * 900, offres: offresCitees(m.objet) };
}
/** Les e-mails d'un dossier, recherche et filtre compris, du plus récent au plus ancien. */
function filtrer(dossier, filtre, cherche) {
  let X = dans(dossier);
  if (filtre === 'nonlus') { X = X.filter(m => !lu(m)); }
  if (filtre === 'suivis') { X = X.filter(m => S.suivis.has(m.uid)); }
  if (filtre === 'pj') { X = X.filter(m => m.pj); }
  cherche = String(cherche || '').toLowerCase();
  if (cherche) { X = X.filter(m => (m.objet + ' ' + (m.de || {}).nom + ' ' + (m.a || []).map(x => x.nom).join(' ') + ' ' + (m.texte || '')).toLowerCase().includes(cherche)); }
  return X.slice().sort((a, b) => b.date.localeCompare(a.date) || b.uid - a.uid);
}
function liste(q) {
  const dossier = q.get('dossier') || 'INBOX', page = Math.max(1, +q.get('page') || 1);
  const X = filtrer(dossier, q.get('filtre') || '', q.get('q'));
  return { dossier, page, parPage: 50, total: X.length, messages: X.slice((page - 1) * 50, page * 50).map(ligne) };
}
function message(q) {
  const uid = +q.get('uid'), m = tous().find(x => x.uid === uid);
  if (!m || ou(m) === '__efface') { return { __status: 404, json: { error: 'Cet e-mail n’est plus dans le dossier.' } }; }
  if (!q.get('garder')) { S.lus.add(uid); S.nonlus.delete(uid); }
  const texte = m.texte || ('Bonjour,\n\n' + m.objet + '.\n\nBien à vous,\n' + m.de.nom);
  return { uid, dossier: q.get('dossier') || ou(m), objet: m.objet, de: m.de, repondreA: m.de, a: m.a || [MOI], cc: m.cc || [], date: m.date, messageId: '<' + uid + '@exemple.be>',
    texte, html: null, lourd: false, pieces: m.pieces || [], suivi: S.suivis.has(uid), repondu: repondu(m), offres: offresCitees(m.objet + '\n' + texte), moi: MOI.adresse };
}
function geste(c) {
  const t = c.tout && typeof c.tout === 'object' ? c.tout : null;
  const uids = c.tout ? filtrer(c.dossier || 'INBOX', t ? t.filtre : '', t ? t.q : '').map(x => x.uid) : (c.uids || []).map(Number);
  const vers = { archiver: 'Archive', corbeille: 'Trash', effacer: '__efface', deplacer: c.nouveau ? 'INBOX.' + c.nouveau : c.vers }[c.action];
  uids.forEach(u => {
    if (vers) { S.partis.set(u, vers); }
    if (c.action === 'lu') { S.lus.add(u); S.nonlus.delete(u); }
    if (c.action === 'nonlu') { S.nonlus.add(u); }
    if (c.action === 'suivi') { S.suivis.add(u); }
    if (c.action === 'nonsuivi') { S.suivis.delete(u); }
  });
  if (c.nouveau && !DOSSIERS.some(d => d[0] === c.nouveau)) {
    DOSSIERS.push([c.nouveau, 'INBOX.' + c.nouveau, null]); DOSSIERS.sort((a, b) => (a[2] ? 0 : 1) - (b[2] ? 0 : 1) || (a[2] ? 0 : a[0].localeCompare(b[0], 'fr')));
    return { ok: true, n: uids.length, cree: c.nouveau };
  }
  return { ok: true, n: uids.length };
}
/** « Nom <adresse>, adresse » → [{nom, adresse}]. */
const adresses = t => String(t || '').split(',').map(x => x.trim()).filter(Boolean).map(x => { const m = /^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/.exec(x); return m ? ADR(m[1].trim(), m[2].trim()) : ADR('', x); });
function envoyer(c) {
  const a = adresses(c.a);
  if (!a.length || a.some(x => !/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(x.adresse))) { return { __status: 422, json: { error: 'Une adresse ne convient pas : ' + String(c.a || '') } }; }
  const r = c.reponse && +c.reponse.uid;
  if (r) { S.repondus.add(r); S.lus.add(r); }
  const pj = !!(c.offre || c.transfert || (c.pieces || []).length || (c.coffre || []).length);
  S.envoyes.push({ uid: 900 + S.envoyes.length, dossier: 'Sent', de: MOI, a: a.map(x => x.nom ? x : ADR(x.adresse, x.adresse)), cc: adresses(c.cc), objet: String(c.objet || ''), date: MAINTENANT, lu: true, pj, texte: String(c.texte || '') });
  return { ok: true, messageId: '<' + Date.now() + '.demo@exemple.be>', avertissement: null };
}

/* Les lectures IA : le briefing (les non-lus) et le radar (les trois dernières semaines, depuis le 29/10). */
const lectureDe = m => S.lectures.get(m.uid) || (m.lecture && (!m.radar || S.radar.has(m.uid)) ? m.lecture : null);
function lectures(q) {
  const quoi = q.get('quoi') || 'nonlus';
  const X = (quoi === 'recents' ? reception().filter(m => m.date >= '2026-10-29') : reception().filter(m => !lu(m))).slice().sort((a, b) => b.date.localeCompare(a.date));
  return { quoi, elements: X.map(m => ({ uid: m.uid, de: m.de, repondreA: m.de, objet: m.objet, date: m.date, lu: lu(m), repondu: repondu(m), suivi: S.suivis.has(m.uid), lecture: lectureDe(m) })),
    aLire: X.filter(m => !lectureDe(m)).map(m => m.uid), ia: true, lot: 8 };
}
/** POST /lire : la lecture de chaque e-mail désigné (8 au plus), gardée. */
function lire(c) {
  const out = {};
  (c.uids || []).slice(0, 8).map(Number).forEach(u => {
    const m = tous().find(x => x.uid === u); if (!m) { return; }
    S.radar.add(u);
    const l = lectureDe(m) || L({ resume: m.objet, categorie: 'autre', ton: 'neutre', urgence: 'basse', action: 'archiver' });
    S.lectures.set(u, l); out[u] = l;
  });
  return { lectures: out };
}

/* Le pilote : allumé lundi en « Brouillons » ; il a rédigé, le magasin envoie. */
const GARDES = ['parle de prix', 'demande une remise ou un geste', 'réclamation', 'question d’allergènes ou de santé', 'commande ou demande d’offre à confirmer', 'client professionnel', 'personnel ou RH', 'l’IA n’est pas sûre'];
const PILOTE = [
  { id: 58, uid: 1048, de: 'Marc Dubois', adr: 'marc.dubois@exemple.be', objet: 'Éclairs de mardi', categorie: 'reclamation', confiance: 76, etat: 'brouillon', raison: 'réclamation · brouillon déposé', le: '2026-11-19T14:10:00+01:00' },
  { id: 57, uid: 1047, de: 'Moulins Démo', adr: 'factures@moulins-demo.exemple.be', objet: 'Facture F-26-1187 — livraison du 17/11', categorie: 'facture', confiance: 95, etat: 'attente', raison: 'rien à répondre seul', le: '2026-11-19T11:40:00+01:00', reponse: '' },
  { id: 56, uid: 1046, de: 'Léa Martin', adr: 'lea.martin@exemple.be', objet: 'Candidature — vendeuse le week-end', categorie: 'candidature', confiance: 91, etat: 'brouillon', raison: 'cran « Brouillons » · brouillon déposé', le: '2026-11-19T09:50:00+01:00' },
  { id: 55, uid: 1049, de: 'Sophie Lambert', adr: 'sophie.lambert@exemple.be', objet: 'Commande pour une communion le 28/11', categorie: 'commande', confiance: 84, etat: 'brouillon', raison: 'commande ou demande d’offre à confirmer · brouillon déposé', le: '2026-11-19T15:20:00+01:00' },
  { id: 54, uid: 1045, de: 'Crèche Les Petits Pas', adr: 'commandes@creche-demo.exemple.be', objet: 'Commande de la semaine prochaine', categorie: 'commande', confiance: 88, etat: 'vu', raison: 'client professionnel · brouillon déposé', le: '2026-11-18T16:30:00+01:00' },
  { id: 53, uid: 1044, de: 'Hélène Marchal', adr: 'helene.marchal@exemple.be', objet: 'Re: Notre offre n° 418', categorie: 'commande', confiance: 86, etat: 'vu', raison: 'commande ou demande d’offre à confirmer · brouillon déposé', le: '2026-11-18T10:10:00+01:00' },
  { id: 52, uid: 1043, de: 'Julie Mercier', adr: 'julie.mercier@exemple.be', objet: 'Baptême le samedi 28 novembre', categorie: 'commande', confiance: 83, etat: 'vu', raison: 'commande ou demande d’offre à confirmer · brouillon déposé', le: '2026-11-17T19:20:00+01:00' },
].map(p => Object.assign({ envoi: null }, p));
function pilote() {
  const rep = p => { const m = tous().find(x => x.uid === p.uid); return p.reponse != null ? p.reponse : (m && m.lecture && m.lecture.reponse) || ''; };
  const vue = p => ({ id: p.id, uid: p.uid, de_adresse: p.adr, de_nom: p.de, objet: p.objet, categorie: p.categorie, confiance: p.confiance, reponse: rep(p),
    etat: p.etat, raison: p.raison, envoi_le: p.envoi, cree_le: p.le });
  const X = PILOTE.map(vue).sort((a, b) => b.cree_le.localeCompare(a.cree_le));
  const attente = X.filter(p => p.etat === 'attente' || p.etat === 'brouillon');
  const n = e => PILOTE.filter(p => p.etat === e).length;
  return { niveau: S.niveau, niveaux: { 0: 'Éteint', 1: 'Brouillons', 2: 'Simples' }, seuil: 85, delai: 10, horloge: S.horloge, ia: true,
    fil: X.filter(p => p.etat !== 'attente' && p.etat !== 'brouillon'), attente,
    stats: { envoyes: n('envoye'), brouillons: PILOTE.filter(p => /brouillon déposé/.test(p.raison || '')).length, annules: n('annule'), planifies: n('planifie'), attente: attente.length }, gardes: GARDES };
}
function piloteEcrire(c) {
  if (c.action === 'annuler' || c.action === 'vu') {
    const p = PILOTE.find(x => x.id === +c.id);
    if (c.action === 'annuler') {
      if (!p || p.etat !== 'planifie') { return { __status: 409, json: { error: 'Trop tard : cette réponse est déjà partie, ou n’était plus prévue.' } }; }
      p.etat = 'annule'; p.raison = 'annulé par le magasin';
    } else if (p && (p.etat === 'attente' || p.etat === 'brouillon')) { p.etat = 'vu'; }
    return Object.assign({ ok: true }, pilote());
  }
  if (c.niveau == null || ![0, 1, 2].includes(+c.niveau)) { return { __status: 422, json: { error: 'Le cran du pilote est attendu.' } }; }
  S.niveau = +c.niveau;
  if (S.niveau === 0) { PILOTE.forEach(p => { if (p.etat === 'planifie') { p.etat = 'annule'; p.raison = 'pilote éteint'; } }); }
  return Object.assign({ ok: true }, pilote());
}
/** Un passage de l'horloge (toutes les 10 minutes au magasin) : l'e-mail de Martine Wauters arrive ; le pilote allumé le
 * lit — en « Simples », une réponse simple part 10 minutes plus tard, annulable ; en « Brouillons », un brouillon. */
function horloge() {
  S.horloge = MAINTENANT;
  if (S.passage) { return { ok: true }; }
  S.passage = true; S.arrives.push(ARRIVE);
  if (S.niveau === 2) { PILOTE.push({ id: 59, uid: ARRIVE.uid, de: ARRIVE.de.nom, adr: ARRIVE.de.adresse, objet: ARRIVE.objet, categorie: 'autre', confiance: 94, etat: 'planifie', raison: null, le: MAINTENANT, envoi: '2026-11-19T15:50:00+01:00' }); }
  if (S.niveau === 1) { PILOTE.push({ id: 59, uid: ARRIVE.uid, de: ARRIVE.de.nom, adr: ARRIVE.de.adresse, objet: ARRIVE.objet, categorie: 'autre', confiance: 94, etat: 'brouillon', raison: 'cran « Brouillons » · brouillon déposé', le: MAINTENANT, envoi: null }); }
  return { ok: true };
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
const modeles = () => ({ reseau: MODELES_RESEAU, magasin: S.modeles.slice().sort((a, b) => a.nom.localeCompare(b.nom, 'fr')).map(x => Object.assign({}, x)), variables: VARIABLES });
/** POST /modeles : creer, modifier, supprimer (comme bmModelesEcrire). */
function modelesEcrire(c) {
  const id = +c.id || 0;
  if (c.action === 'supprimer') { S.modeles = S.modeles.filter(x => x.id !== id); return Object.assign({ ok: true }, modeles()); }
  const nom = String(c.nom || '').trim().slice(0, 120), objet = String(c.objet || '').trim().slice(0, 200), corps = String(c.corps || '').replace(/\s+$/, '');
  if (!nom || !corps) { return { __status: 422, json: { error: 'Un nom et un texte, au moins.' } }; }
  if (c.action === 'creer') { S.modeles.push({ id: 900 + S.modeles.length + 1, nom, objet, corps, maj: '2026-11-19 15:40:00' }); }
  else if (c.action === 'modifier') { const x = S.modeles.find(m => m.id === id); if (x) { Object.assign(x, { nom, objet, corps, maj: '2026-11-19 15:40:00' }); } }
  else { return { __status: 422, json: { error: 'action inconnue' } }; }
  return Object.assign({ ok: true }, modeles());
}
const REGLES = [
  { regle: 'accuse', nom: 'Accusé de réception', texte: 'Répond à chaque nouvel e-mail reçu — une fois par expéditeur sur la période choisie ; jamais aux envois automatiques, aux listes, aux « ne pas répondre », ni à la boîte elle-même.', modele: 'b-accuse', actif: false, jours: 3 },
  { regle: 'relance', nom: 'Relance d’une offre sans réponse', texte: 'Relance une fois le client d’une offre en cours, restée sans suite depuis le nombre de jours choisi (et dont l’événement n’est pas passé).', modele: 'b-relance', actif: true, jours: 3 },
  { regle: 'rappel', nom: 'Rappel la veille de la commande', texte: 'Écrit au client d’une offre convertie en commande la veille du jour prévu.', modele: 'b-rappel', actif: true, jours: 1 },
].map(r => { const m = MODELES_RESEAU.find(x => x.id === r.modele); return Object.assign(r, { objet: m.objet, corps: m.corps }); });
function automatiques() {
  return { regles: REGLES.map(r => ({ regle: r.regle, nom: r.nom, texte: r.texte, actif: r.actif, jours: r.jours, objet: r.objet, corps: r.corps })), journal: [
    { le: '2026-11-19 15:10:00', nom: 'Relance d’une offre sans réponse', destinataire: 'accueil@horizon.exemple.be', objet: 'Notre offre n° 417', erreur: null },
    { le: '2026-11-18 17:30:00', nom: 'Rappel la veille de la commande', destinataire: 'claire.petit@exemple.be', objet: 'Votre commande de demain', erreur: null },
  ], horloge: S.horloge };
}
/** POST /automatiques : allumer, éteindre, régler le délai, l'objet, le texte (comme bmAutoEcrire). */
function automatiquesEcrire(c) {
  const r = REGLES.find(x => x.regle === c.regle);
  if (!r) { return { __status: 422, json: { error: 'règle inconnue' } }; }
  const corps = c.corps != null ? String(c.corps).replace(/\s+$/, '') : r.corps;
  if (!corps) { return { __status: 422, json: { error: 'Le texte du message est vide.' } }; }
  if (c.objet != null) { r.objet = String(c.objet).trim().slice(0, 200); }
  r.corps = corps;
  if (c.jours != null) { r.jours = Math.max(1, Math.min(30, +c.jours || 1)); }
  if (c.actif != null) { r.actif = !!c.actif; }
  return Object.assign({ ok: true }, automatiques());
}

/** POST /ranger : un dossier proposé par e-mail (comme le ferait le modèle) ; ce qui attend une réponse reste en place. */
function ranger(c) {
  const existe = nom => DOSSIERS.some(d => d[0] === nom);
  return { modele: 'claude-haiku', propositions: (c.uids || []).map(u => {
    const x = tous().find(y => y.uid === +u) || { de: null, objet: '' }, l = x.lecture || {};   // le modèle lit l'e-mail lui-même
    let cible = 'laisser', raison = 'attend encore une réponse';
    if (l.robot || l.categorie === 'newsletter') { cible = 'archive'; raison = 'une lettre d’information, déjà lue'; }
    else if (l.dossier) { cible = 'd:INBOX.' + l.dossier; raison = 'un e-mail ' + (l.categorie === 'facture' ? 'de facture' : 'de fournisseur') + ', comme ceux du dossier'; }
    else if (l.categorie === 'candidature') { cible = 'd:INBOX.Candidatures'; raison = 'une candidature, comme celles du dossier'; }
    else if (repondu(x) && l.b2b) { cible = existe('Clients pro') ? 'd:INBOX.Clients pro' : 'n:Clients pro'; raison = 'un client professionnel, déjà répondu'; }
    else if (repondu(x) && (l.categorie === 'commande' || l.categorie === 'offre')) { cible = 'd:INBOX.Commandes'; raison = 'une commande déjà répondue, comme celles du dossier'; }
    else if (repondu(x)) { cible = 'archive'; raison = 'déjà répondu, rien à suivre'; }
    return { uid: +u, de: x.de, objet: x.objet, cible, raison };
  }) };
}

module.exports = function (m, p, q, c) {
  c = c && typeof c === 'object' ? c : {};
  if (p !== '/dashboard/boite' && !p.startsWith('/dashboard/boite/')) { return undefined; }
  const sous = p.slice('/dashboard/boite'.length);
  if (sous === '/automatisations' || sous.startsWith('/automatisations/') || sous === '/equipe') { return undefined; }   // Paramètres › Mails et automatisations : 24_parametres.js
  if (sous === '' && m === 'GET') { return etat(); }
  if (sous === '/cron') { return horloge(); }
  if (sous === '/relier' && m === 'POST') {
    if (S.reliee && !S.ouverte) { return { __status: 403, json: { error: 'Cette boîte est déjà reliée : ouvrez-la d’abord pour changer ses accès.' } }; }
    const adr = String(c.adresse || '').trim().toLowerCase();
    if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/.test(adr) || !c.motDePasse) { return { __status: 422, json: { error: 'L’adresse e-mail et le mot de passe de la boîte sont attendus.' } }; }
    Object.assign(S, { reliee: true, ouverte: true, adresse: adr });
    return { ok: true, reliee: true, adresse: adr, nom: D.NOM };
  }
  if (!S.reliee) { return { __status: 409, json: { error: 'La boîte du magasin n’est pas encore reliée.', etat: 'non_reliee' } }; }
  if (sous === '/ouvrir') { if (!c.motDePasse) { return { __status: 401, json: { error: 'Mot de passe incorrect.' } }; } S.ouverte = true; return { ok: true }; }
  if (sous === '/fermer') { S.ouverte = false; return { ok: true }; }
  if (!S.ouverte) { return { __status: 401, json: { error: 'Ouvrez la boîte avec son mot de passe.', etat: 'fermee' } }; }
  if (sous === '/delier' && m === 'POST') { Object.assign(S, { reliee: false, ouverte: false }); REGLES.forEach(r => { r.actif = false; }); return { ok: true }; }
  if (sous === '/messages') { return liste(q); }
  if (sous === '/message') { return message(q); }
  if (sous === '/geste') { return geste(c); }
  if (sous === '/envoyer') { return envoyer(c); }
  if (sous === '/lectures') { return lectures(q); }
  if (sous === '/lire') { return lire(c); }
  if (sous === '/ranger') { return ranger(c); }
  if (sous === '/pilote') { return m === 'POST' ? piloteEcrire(c) : pilote(); }
  if (sous === '/modeles') { return m === 'POST' ? modelesEcrire(c) : modeles(); }
  if (sous === '/automatiques') { return m === 'POST' ? automatiquesEcrire(c) : automatiques(); }
  return undefined;
};
