/* Paramètres › Mails et automatisations, la page complète (?vue=mails, ou « Ouvrir la page complète » dans la fenêtre de
 * la roue), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    param-page              les tuiles, la liste des envois, l'équipe, le rappel (quand, par où, l'e-mail, le
 *                                     SMS), son aperçu, les commandes de demain, ce qui est parti
 *   fonction  param-recent            « Pas de rappel pour une commande passée moins de 24 h avant le retrait »
 *   fonction  param-email             l'objet et le texte de l'e-mail, ses variables ; l'aperçu suit ; « Enregistrer »
 *   fonction  param-sms               le texte du SMS, le compteur ; « Enregistrer »
 *   fonction  param-essai             le GSM, « Envoyer un essai » : l'e-mail à la boîte du magasin, le SMS au GSM
 *   fonction  param-equipe-ajouter    le prénom et l'initiale, l'adresse, « Ajouter »
 *   fonction  param-equipe-retirer    « Retirer » au bout de la ligne (sans confirmation)
 * Données fictives : mocks/24_parametres.js (le rappel allumé, demain six commandes, le journal, trois personnes dans
 * l'équipe) ; avant de filmer, la boîte ouverte (70_boite.js) et le réglage des 24 h éteint. Chaque phrase vient de
 * panel/public/dashboard/automatisations.js ou de panel/src/automatisations.php. */
const { Film } = require('../film');
const P = require('./parametres');

module.exports = async function () {
  const f = await Film.ouvrir('24-03-page', { vue: 'mails', serie: 'Paramètres', parent: 'mails-automatisations', sources: P.SOURCES,
    // Le réglage des 24 h est éteint au départ : la fonction « Écarter les commandes passées au dernier moment » l'allume.
    avant: async p => { await P.marge(p); await P.depart({ plus: p => P.poster(p, '/dashboard/boite/automatisations', { auto: 'rappel_retrait', recent: false }) })(p); } });
  const quand = '.au-g2 .au-col:first-child > .db-card:nth-child(1)';
  const demain = '.au-g2 .au-col:nth-child(2) > .db-card:nth-child(2)';
  const enregistrer = async () => {
    await f.cliquer('[data-au="enregistrer"]', { apres: 1200 });
    await f.montrer('.au-flash', 'Enregistré');
  };

  /* L'onglet */
  await f.onglet({
    id: 'param-page', titre: 'Mails et automatisations',
    resume: 'La page complète : ce que le magasin envoie tout seul, l’équipe qui reçoit les fiches des promotions, le rappel la veille du retrait en détail, son aperçu, demain et ce qui est parti.',
    accroche: 'Ce que le magasin envoie tout seul',
    programme: ['Écarter les commandes de dernière minute', 'Écrire l’e-mail du rappel', 'Écrire le SMS', 'Envoyer un essai', 'Ajouter une personne à l’équipe', 'La retirer'],
  });
  await P.cadreFixe(f, '#db-rail [data-par]', 'Paramètres');
  await f.dire('La page complète s’ouvre de la fenêtre des Paramètres : « Ouvrir la page complète », ou « L’équipe ».', 5000);
  await f.montrer('.au-tuiles', 'En un coup d’œil');
  await f.dire('En tête : le rappel <b>allumé</b>, les commandes de demain, le canal, et l’horloge qui passe <b>toutes les 10 minutes</b>.', 5600);
  await f.montrer('.au-liste', 'Les automatisations');
  await f.dire('Les envois du magasin : le <b>rappel la veille du retrait</b>, pour les clients ; la <b>fiche d’une promotion</b>, pour l’équipe.', 5800);
  await f.montrerTout('.au-liste tr.gris', 'À venir');
  await f.dire('En gris, à venir : le dossier comptable du mois, « Commande prête ».', 4000);
  await f.montrer('.au-liste [data-au="auto-boite"]', 'Messages automatiques');
  await f.dire('L’accusé de réception et la relance d’une offre se règlent dans la <b>Boîte mail</b>.', 4400);
  await f.montrer('.au-eq', 'L’équipe');
  await f.dire('<b>L’équipe</b> : qui reçoit la fiche PDF d’un bundle lancé. Ni marge ni coefficient.', 4600);
  await f.montrer(quand, 'Le rappel');
  await f.dire('Puis le <b>rappel</b> en détail : quand, par où, le texte de l’e-mail et du SMS.', 4400);
  await f.montrer('.au-g2 .au-col:nth-child(2) > .db-card:nth-child(1)', 'Aperçu');
  await f.dire('À droite, l’<b>aperçu</b>, rempli avec la première commande de demain.', 3800);
  await f.montrer(demain, 'Demain');
  await f.dire('<b>Demain</b>, vendredi 20 novembre : chaque commande, son canal, son état.', 4200);
  await f.montrer('.au-g2 .au-col:nth-child(2) > .db-card:nth-child(3)', 'Ce qui est parti');
  await f.dire('<b>Ce qui est parti</b> : les 30 derniers rappels. Chacun ne part qu’une fois.', 4200);
  await f.montrer('.au-actions', 'Enregistrer');
  await f.dire('Ici, un changement attend « <b>Enregistrer</b> » ; l’interrupteur de la liste, lui, agit tout de suite.', 5000);
  await f.cacher(300); await f.taire(200);

  /* Écarter les commandes de dernière minute (le réglage est éteint au départ) */
  await f.fonction({ id: 'param-recent', titre: 'Écarter les commandes passées au dernier moment',
    resume: '« Pas de rappel pour une commande passée moins de 24 h avant le retrait », puis « Enregistrer » : la confirmation de la commande suffit, elle passe « trop récente ».',
    points: ['« Quand » : l’interrupteur des 24 h', '« Enregistrer »'] });
  await f.montrer(demain, 'Demain');
  await f.montrer(demain + ' tr:has-text("Élise R.")', 'Commande récente');
  await f.dire('La commande d’Élise vient d’être passée : elle recevrait un rappel en plus de sa confirmation.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('label.bx-bascule:has([data-au-f="recent"])', { apres: 800 });
  await f.montrer(quand, 'Moins de 24 h avant');
  await f.dire('« <b>Pas de rappel</b> pour une commande passée moins de 24 h avant le retrait » : la confirmation suffit.', 5400);
  await f.cacher(150); await f.taire(150);
  await enregistrer();
  await f.dire('<b>Enregistré</b>.', 2000);
  await f.montrer(demain, 'Demain');
  await f.montrer(demain + ' tr:has-text("Élise R.")', 'Trop récente');
  await f.dire('Sa commande passe « <b>trop récente</b> » : pas de rappel.', 3600);
  await f.cacher(300); await f.taire(200);

  /* L'e-mail */
  await f.fonction({ id: 'param-email', titre: 'Écrire l’e-mail du rappel',
    resume: 'Changer l’objet et le texte, avec les variables {prenom}, {jour}, {heure}, {paiement}… ; l’aperçu suit ; puis « Enregistrer ».',
    points: ['« L’e-mail » : l’objet, le texte', 'Les variables', '« Enregistrer »'] });
  await f.montrer('[data-au-f="objet"]', 'Objet');
  await f.dire('L’<b>objet</b> : on y met le numéro de la commande.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-au-f="objet"]', { apres: 150 });
  await f.p.keyboard.press('Control+A');
  await f.taper('Votre commande n° {commande}, demain à {heure}', 45);
  await f.pause(500);
  await f.montrer('.au-vars', 'Les variables');
  await f.dire('Les <b>variables</b> se remplissent pour chaque client : le prénom, le jour, l’heure, ce qui reste à payer…', 5000);
  await f.montrer('.au-mail', 'Aperçu');
  await f.dire('L’<b>aperçu</b> suit : « Votre commande n° 2187, demain à 08:30 ».', 4000);
  await f.cacher(150); await f.taire(150);
  await enregistrer();
  await f.dire('<b>Enregistré</b> : les prochains rappels partent avec cet objet.', 3400);
  await f.cacher(300); await f.taire(200);

  /* Le SMS */
  await f.fonction({ id: 'param-sms', titre: 'Écrire le SMS du rappel',
    resume: 'Changer le texte du SMS ; le compteur donne les signes et le nombre de SMS ; puis « Enregistrer ».',
    points: ['« Le SMS » : le texte', 'Le compteur', '« Enregistrer »'] });
  await f.montrer('[data-au-f="sms"]', 'Le SMS');
  await f.dire('Le texte du <b>SMS</b>, avec les mêmes variables.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-au-f="sms"]', { apres: 150 });
  await f.p.keyboard.press('Control+End');
  await f.taper(' À demain !', 90);
  await f.pause(600);
  await f.montrer('.au-g2 .au-col:first-child > .db-card:has([data-au-f="sms"]) .ct', 'Le compteur');
  await f.dire('Le <b>compteur</b> : les signes, et le nombre de SMS. Au-delà de 160 signes, il en part plusieurs.', 5000);
  await f.cacher(150); await f.taire(150);
  await enregistrer();
  await f.dire('<b>Enregistré</b>.', 2200);
  await f.cacher(300); await f.taire(200);

  /* L'essai */
  await f.fonction({ id: 'param-essai', titre: 'Envoyer un essai',
    resume: 'Votre GSM, puis « Envoyer un essai » : l’e-mail part à la boîte du magasin, le SMS au GSM donné. Rien n’est gardé.',
    points: ['Votre GSM', '« Envoyer un essai »'] });
  await f.montrer('.au-actions', 'Envoyer un essai');
  await f.dire('Avant de laisser partir : un <b>essai</b>, avec la première commande de demain.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-au-tel]', { apres: 200 });
  await f.taper('0470 12 34 56', 60);
  await f.cliquer('[data-au="essai"]', { apres: 1300 });
  await f.montrer('.au-flash', 'L’essai');
  await f.dire('L’<b>e-mail</b> part à la boîte du magasin, le <b>SMS</b> au GSM donné.', 4200);
  await f.cacher(300); await f.taire(200);

  /* L'équipe : ajouter */
  await f.fonction({ id: 'param-equipe-ajouter', titre: 'Ajouter une personne à l’équipe',
    resume: 'Le prénom et l’initiale, l’adresse e-mail, puis « Ajouter » : la personne reçoit la fiche de la prochaine promotion lancée.',
    points: ['« L’équipe » : le prénom et l’initiale', 'L’adresse e-mail', '« Ajouter »'] });
  await f.montrer('.au-eq', 'L’équipe');
  await f.dire('Sarah rejoint l’équipe du magasin.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-au-eq="nom"]', { apres: 200 });
  await f.taper('Sarah K.', 70);
  await f.cliquer('[data-au-eq="adresse"]', { apres: 200 });
  await f.taper('sarah.k@exemple.be', 50);
  await f.cliquer('[data-au="eq-ajouter"]', { apres: 1300 });
  await f.montrer('.au-flash', 'Ajoutée');
  await f.dire('<b>Ajouté à l’équipe</b>.', 2200);
  await f.montrer('.au-eq', 'L’équipe');
  await f.dire('Sarah reçoit la fiche du prochain bundle lancé.', 3400);
  await f.cacher(300); await f.taire(200);

  /* L'équipe : retirer */
  await f.fonction({ id: 'param-equipe-retirer', titre: 'Retirer une personne de l’équipe',
    resume: '« Retirer » au bout de sa ligne : elle ne reçoit plus les fiches. Il n’y a pas de confirmation.',
    points: ['« Retirer » au bout de sa ligne'] });
  await f.montrer('.au-eq tr:has-text("Thomas D.")', 'Thomas D.');
  await f.dire('Thomas quitte le magasin : « <b>Retirer</b> », au bout de sa ligne.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.au-eq tr:has-text("Thomas D.") [data-au-eqx]', { apres: 1300 });
  await f.montrer('.au-flash', 'Retiré');
  await f.dire('<b>Retiré de l’équipe</b>, tout de suite : il n’y a pas de confirmation.', 3800);
  await f.montrer('.au-eq', 'L’équipe');
  await f.pause(2000);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
