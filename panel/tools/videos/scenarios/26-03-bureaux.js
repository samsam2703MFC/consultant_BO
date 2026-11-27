/* Clients › l'onglet « Bureaux » (?vue=clients), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    clients-bureaux               les bureaux en développement (à valider, traités), la liste des bureaux et
 *                                           son filtre, le tiroir d'un bureau (livraison, sites, catalogue, offres,
 *                                           personnes, invitation, documents ; précédent, suivant)
 *   fonction  clients-questionnaire         le questionnaire du magasin : le lien et son QR, le message, le bon
 *   fonction  clients-valider-demande       une demande validée : le bureau créé dans TF Buddy
 *   fonction  clients-refuser-demande       une demande refusée
 *   fonction  clients-ajouter-bureau        « Ajouter un bureau » : l'assistant en sept étapes (POST …/offices/onboard)
 *   fonction  clients-modifier-livraison    la tournée, le créneau, l'heure limite d'un bureau (PUT …/delivery-settings)
 *   fonction  clients-ajouter-site          un site de livraison, l'adresse proposée par TF Buddy (POST …/sites)
 *   fonction  clients-inviter               une invitation par e-mail (POST …/members/invite)
 * Non filmé : « Ouvrir » le questionnaire (un nouvel onglet du navigateur), le logo de l'assistant (le sélecteur de
 * fichiers du système). Données fictives : mocks/26_clients.js. Le compte TF Buddy du magasin est connecté avant de
 * filmer, et l'onglet Bureaux choisi. La connexion elle-même n'est pas filmée (décision de Sam, 26/11/2026).
 * Chaque phrase vient de panel/public/dashboard/clients.js (vueBureaux, blocDev, tiroirDemande, tiroirBureau,
 * vueAssistant, fenCourante), tfb-magasin.js, panel/src/clients_panel.php, panel/src/bureaux_dev.php ou tfb_magasin.php. */
const { Film } = require('../film');
const { ecrire, choisir, heure, fonction, fonctionPreparee, connecterTfb, recharger, poster, attendre } = require('./commandes');

const TIR = '.pcl-tir';
async function fermerTiroirs(f) { await f.p.keyboard.press('Escape'); await attendre(300); await f.p.keyboard.press('Escape'); await attendre(300); await f.p.evaluate(() => window.scrollTo(0, 0)); }

module.exports = async function () {
  const f = await Film.ouvrir('26-03-bureaux', {
    vue: 'clients', serie: 'Clients', parent: 'clients',
    sources: ['panel/public/dashboard/clients.js', 'panel/public/dashboard/tfb-magasin.js', 'panel/src/clients_panel.php', 'panel/src/bureaux_dev.php', 'panel/src/tfb_magasin.php'],
    avant: async p => { await connecterTfb(p); await p.evaluate(() => localStorage.setItem('pcl-onglet:4', 'bur')); await recharger(p); },
  });

  /* L'onglet */
  await f.onglet({
    id: 'clients-bureaux', titre: 'Les bureaux',
    resume: 'Les bureaux en développement à valider, puis les bureaux livrés : statut, catalogue, offres, sites ; la fiche d’un bureau dans un tiroir.',
    accroche: 'Du questionnaire à la tournée',
    programme: ['Régler le questionnaire', 'Saisir un bureau en développement', 'Valider une demande', 'Refuser une demande',
      'Ajouter un bureau', 'Modifier la livraison', 'Ajouter un site', 'Inviter des personnes'],
  });
  await f.montrer('.pcl-dev', 'En développement');
  await f.dire('En tête, les <b>bureaux en développement</b> : 2 demandes à valider, du questionnaire du magasin ou saisies ici.', 5400);
  await f.montrer('.pcl-dev .pcl-ligne[data-id="13"]', 'Une demande');
  await f.dire('Chaque demande : le bureau, la personne, le jour, et le <b>bon de bienvenue</b> qu’elle a reçu.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-dev [data-act="dev-traitees"]', { apres: 900 });
  await f.dire('« Voir les demandes traitées » : les bureaux créés, et les demandes refusées.', 3800);
  await f.taire(150);
  await f.cliquer('.pcl-dev [data-act="dev-traitees"]', { apres: 600 });
  await f.cliquer('[data-act="b-statut"][data-v="pending"]', { apres: 900 });
  await f.montrer('.pcl-carte:not(.pcl-dev)', 'En attente');
  await f.dire('La liste des bureaux, filtrée par statut : ce bureau <b>en attente</b> n’a pas encore de site, aucune tournée ne peut le livrer.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-act="b-statut"][data-v=""]', { apres: 900 });
  await f.cliquer('.pcl-ligne[data-act="b-sel"][data-id="951"]', { apres: 1500 });
  await f.montrer(TIR + ' .pcl-tir-corps', 'Le tiroir');
  await f.dire('Un clic ouvre le bureau dans un tiroir : la <b>livraison</b> et sa tournée, le créneau, l’heure limite, les sites.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(TIR + ' .pcl-tir-nav [data-act="b-aller"][data-v="personnes"]', { apres: 1200 });
  await f.dire('Les onglets du haut y mènent : le catalogue et les offres, les <b>personnes</b> du bureau, l’invitation.', 4800);
  await f.taire(150);
  await f.cliquer(TIR + ' [data-act="b-pdf"][data-v="poster"]', { apres: 1400 });
  await f.montrer(TIR + ' .pcl-tir-corps .pcl-ok', 'Document téléchargé');
  await f.dire('Les <b>documents</b> que TF Buddy fabrique pour le bureau — affiche A2, cartes de bienvenue, brochure — se téléchargent ici.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(TIR + ' .pcl-tir-nav [data-act="b-pas"][data-v="1"]', { apres: 1200 });
  await f.dire('Les flèches passent au bureau suivant ; Échap ferme le tiroir.', 3400);
  await f.taire(150);
  await f.cliquer(TIR + ' [data-act="b-fermer"]', { apres: 700 });

  /* Le questionnaire */
  await fonction(f, { id: 'clients-questionnaire', titre: 'Régler le questionnaire du magasin',
    resume: 'Le lien et le QR du questionnaire du magasin, son titre, son message d’accueil et le bon de bienvenue que reçoit chaque réponse.',
    points: ['« Questionnaire du magasin »', 'Le lien, le QR', 'Le message, le bon', '« Enregistrer »'] });
  await f.cliquer('.pcl-dev [data-act="dev-questionnaire"]', { apres: 1400 });
  await f.montrer('.pcl-fen .pcl-q-lien', 'Le lien et son QR');
  await f.dire('Le <b>lien</b> à partager — e-mail, affiche, réseaux — et son <b>QR</b> à imprimer.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-fen [data-act="dev-copier"]', { apres: 900 });
  await ecrire(f, '#pcl-c-m-message', 'Dites-nous où vous travaillez : nous livrons votre bureau chaque matin, et votre premier café est offert.', { delai: 22 });
  await f.montrer('#pcl-c-m-promo', 'Le bon de bienvenue');
  await f.dire('Le <b>bon de bienvenue</b> : une promotion du magasin avec un bon. Code commun, ou un code personnel par réponse.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-fen [data-act="dev-questionnaire-ok"]', { apres: 1400 });
  await f.dire('Enregistré. Chaque réponse crée la personne, son bon et la société dans TF Buddy ; le bureau attend ici.', 5200);
  await f.taire(200);

  /* Valider une demande */
  await fonctionPreparee(f, { id: 'clients-valider-demande', titre: 'Valider une demande',
    resume: 'Valider crée le bureau dans TF Buddy avec le compte du magasin : son nom, sa société, sa tournée, actif ou en attente.',
    points: ['Ouvrir la demande', 'Le nom, la tournée, le statut', '« Créer le bureau dans TF Buddy »'] }, async () => { await fermerTiroirs(f); });
  await f.cliquer('.pcl-dev .pcl-ligne[data-id="13"]', { apres: 1400 });
  await f.montrer(TIR + ' .pcl-tir-2', 'Déjà dans TF Buddy');
  await f.dire('Arrivée par le questionnaire : la personne et la société ont déjà leur fiche dans TF Buddy, le bon est donné.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.defilerVers(f.p.locator(TIR + ' .pcl-dev-valider'));
  await choisir(f, '#pcl-dv-route', '32');
  await f.montrer(TIR + ' .pcl-dev-valider', 'Valider');
  await f.dire('Le nom du bureau, la <b>tournée</b> qui le livre, et son statut : <b>actif</b>, ou en attente.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(TIR + ' [data-act="dev-valider"]', { apres: 2200 });
  await f.montrer(TIR + ' .pcl-tir-tete', 'Le bureau créé');
  await f.dire('Le bureau est créé dans TF Buddy, la personne y est reliée : sa fiche s’ouvre.', 4200);
  await f.cacher(300); await f.taire(200);

  /* Refuser une demande */
  await fonctionPreparee(f, { id: 'clients-refuser-demande', titre: 'Refuser une demande',
    resume: 'Une demande sans suite : la personne et la société restent dans TF Buddy ; le bureau ne sera pas créé.',
    points: ['Ouvrir la demande', '« Refuser »', 'Confirmer'] }, async () => { await fermerTiroirs(f); });
  await f.cliquer('.pcl-dev .pcl-ligne[data-id="12"]', { apres: 1400 });
  await f.cliquerConfirme(TIR + ' [data-act="dev-refuser"]', 'Refuser ce bureau ?', 'La personne et la société restent dans TF Buddy ; le bureau ne sera pas créé.', { apres: 1500 });
  await f.montrer('.pcl-dev', 'Refusée');
  await f.dire('La demande quitte les demandes à valider ; elle reste parmi les demandes traitées.', 4400);
  await f.cacher(300); await f.taire(200);

  /* Ajouter un bureau */
  await fonctionPreparee(f, { id: 'clients-ajouter-bureau', titre: 'Ajouter un bureau',
    resume: 'L’assistant du panel en sept étapes : société, contact, sites, départements, webshop, personnes, récapitulatif ; tout part d’un coup vers TF Buddy.',
    points: ['« Ajouter un bureau »', 'La société, le contact', 'Un site et sa tournée', 'Relire, « Créer le bureau »'] }, async () => { await fermerTiroirs(f); });
  await f.cliquer('.pcl-tete [data-act="w-ouvrir"]', { apres: 1300 });
  await choisir(f, '#pcl-c-f-id_company_client', '7218');
  await ecrire(f, '#pcl-c-f-name', 'Agence Clé d’Or', { delai: 40 });
  await ecrire(f, '#pcl-c-f-tax_number', 'BE0000.121.212', { delai: 35 });
  await f.dire('Sept étapes ; à droite, <b>ce que l’on crée</b> : société, bureau, site, tournée.', 3800);
  await f.taire(150);
  await f.cliquer(TIR + ' [data-act="w-suivant"]', { apres: 700 });
  await ecrire(f, '#pcl-c-f-contact_name', 'Claire Verhoeven', { delai: 40 });
  await ecrire(f, '#pcl-c-f-contact_email', 'claire.verhoeven@exemple.be', { delai: 22 });
  await f.cliquer(TIR + ' [data-act="w-suivant"]', { apres: 700 });
  await ecrire(f, '#pcl-c-s0-address', 'Place de l', { delai: 80, apres: 1300 });
  await f.cliquer('#pcl-geo-s0 [data-act="geo-place"][data-id="g6"]', { apres: 1000 });
  await choisir(f, '#pcl-c-s0-route_id', '33');
  await f.dire('Au moins un <b>site</b> avec une adresse, et la tournée qui le livre.', 3400);
  await f.dire('Départements, webshop et personnes sont facultatifs. On relit, puis « <b>Créer le bureau</b> ».', 600);
  for (let i = 0; i < 4; i++) { await f.cliquer(TIR + ' [data-act="w-suivant"]', { apres: 350 }); }
  await f.pause(2000);
  await f.taire(150);
  await f.cliquer(TIR + ' [data-act="w-creer"]', { apres: 2200 });
  await f.montrer(TIR + ' .pcl-tir-tete', 'Agence Clé d’Or');
  await f.dire('Le bureau est dans TF Buddy : sa fiche s’ouvre, livré par Fin de journée.', 3600);
  await f.cacher(300); await f.taire(200);

  /* Modifier la livraison */
  await fonctionPreparee(f, { id: 'clients-modifier-livraison', titre: 'Modifier la livraison d’un bureau',
    resume: 'La tournée qui livre le bureau, le créneau, l’heure limite des commandes et le temps de remise sur place : ce que lisent la page Commandes et l’appli du livreur.',
    points: ['Ouvrir le bureau', '« Modifier la livraison »', 'Tournée, créneau, heure limite', '« Enregistrer »'] }, async () => { await fermerTiroirs(f); });
  await f.cliquer('.pcl-ligne[data-act="b-sel"][data-id="952"]', { apres: 1500 });
  await f.cliquer(TIR + ' .pcl-tir-pied [data-act="b-livraison"]', { apres: 1000 });
  await f.montrer('.pcl-fen .confirm__box', 'Livraison du bureau');
  await f.dire('La <b>tournée</b>, le créneau, « commandes jusqu’à » et le jour, la remise sur place.', 4400);
  await f.cacher(150); await f.taire(150);
  await heure(f, '#pcl-c-m-delivery_window_start', '07:00');
  await heure(f, '#pcl-c-m-delivery_window_end', '07:20');
  await heure(f, '#pcl-c-m-cutoff_time', '15:00');
  await f.cliquer('.pcl-fen [data-act="b-livraison-ok"]', { apres: 1600 });
  await f.montrer(TIR + ' #pcl-sec-livraison', 'À jour');
  await f.dire('Enregistré dans TF Buddy : créneau 07:00 – 07:20, commandes jusqu’à <b>15:00 la veille</b>.', 4600);
  await f.cacher(300); await f.taire(200);

  /* Ajouter un site */
  await fonctionPreparee(f, { id: 'clients-ajouter-site', titre: 'Ajouter un site de livraison',
    resume: 'Un bureau sans site ne peut être livré par aucune tournée : son adresse, proposée par TF Buddy, et la tournée qui le livre.',
    points: ['Ouvrir le bureau', '« Ajouter un site »', 'L’adresse, la tournée', '« Ajouter le site »'] }, async () => { await fermerTiroirs(f); });
  await f.cliquer('.pcl-ligne[data-act="b-sel"][data-id="959"]', { apres: 1500 });
  await f.montrer(TIR + ' .pcl-manque', 'Pas de site');
  await f.dire('<b>Pas de site de livraison</b> : aucune tournée ne peut livrer ce bureau tant qu’il n’a pas d’adresse.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(TIR + ' .pcl-manque [data-act="b-site"]', { apres: 1000 });
  await ecrire(f, '#pcl-c-m-name', 'Accueil');
  await ecrire(f, '#pcl-c-m-address', 'Parc d’activités 14', { delai: 60, apres: 1300 });
  await f.cliquer('#pcl-geo-m [data-act="geo-place"][data-id="g7"]', { apres: 1000 });
  await choisir(f, '#pcl-c-m-route_id', '32');
  await f.dire('Le premier site devient le site par défaut ; la tournée choisie le livre.', 3800);
  await f.taire(150);
  await f.cliquer('.pcl-fen [data-act="b-site-ok"]', { apres: 1800 });
  await f.montrer(TIR + ' .pcl-tir-corps', 'Site ajouté');
  await f.dire('Le site est ajouté : la livraison du bureau se règle désormais.', 3800);
  await f.cacher(300); await f.taire(200);

  /* Inviter des personnes */
  await fonctionPreparee(f, { id: 'clients-inviter', titre: 'Inviter des personnes',
    resume: 'Une invitation par e-mail à une personne du bureau ; le lien d’invitation se crée ou se retire au même endroit.',
    points: ['Ouvrir le bureau', '« Inviter des personnes »', 'L’e-mail, « Envoyer l’invitation »'] }, async () => { await fermerTiroirs(f); });
  await f.cliquer('.pcl-ligne[data-act="b-sel"][data-id="951"]', { apres: 1500 });
  await f.cliquer(TIR + ' .pcl-tir-pied [data-act="b-aller"][data-v="inviter"]', { apres: 1200 });
  await f.taper('lea.dewit@horizon.exemple.be', 35);
  await f.cliquer(TIR + ' [data-act="b-inviter"]', { apres: 1500 });
  await f.montrer(TIR + ' .pcl-tir-corps .pcl-ok', 'Invitation envoyée');
  await f.dire('« Envoyer l’invitation » : TF Buddy l’envoie par e-mail à cette adresse.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.montrer(TIR + ' #pcl-sec-inviter', 'Le lien d’invitation');
  await f.dire('Dessous, le <b>lien d’invitation</b> actif et ses inscrits ; « retirer le lien », ou « créer un lien » s’il n’y en a pas.', 5600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
