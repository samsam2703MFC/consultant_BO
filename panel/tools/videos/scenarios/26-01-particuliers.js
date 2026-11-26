/* Clients › l'onglet « Particuliers » (?vue=clients), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    clients-particuliers           la liste (les derniers créés d'abord, le téléphone en partie), le filtre
 *                                            « Une remise », la recherche, la fiche et ses gestes
 *   fonction  clients-ajouter-particulier    « Ajouter un client » : nom et téléphone obligatoires (action creer)
 *   fonction  clients-modifier-particulier   « Modifier » : les coordonnées, la note (action modifier)
 *   fonction  clients-passer-entreprise      la TVA vérifiée, la société, le paiement différé (action entreprise)
 *   fonction  clients-supprimer-particulier  une fiche en double, créée dans ce magasin (action supprimer)
 *   fonction  clients-preparer-offre         « Préparer une offre » : la page Offres s'ouvre sur ce client
 * Données fictives : mocks/26_clients.js (238 particuliers générés, plus Benoît Lefèvre et les deux fiches de Sophie
 * Renard) et mocks/13_offres.js (la page Offres). Le compte TF Buddy du magasin est connecté avant de filmer. Chaque
 * phrase vient de panel/public/dashboard/clients.js (vueParticuliers, ficheParticulier, fenCourante) ou de
 * panel/src/clients_panel.php (wr_clients_client, wr_clients_tva). */
const { Film } = require('../film');
const { ecrire, fonction, fonctionPreparee, connecterTfb, recharger, attendre } = require('./commandes');

/** La recherche des particuliers : le champ vidé, puis le texte tapé. */
async function chercher(f, texte) {
  await f.cliquer('#pcl-pq', { apres: 150 });
  await f.p.keyboard.press('Control+A');
  await f.taper(texte, 100);
  await f.pause(1100);
}

module.exports = async function () {
  const f = await Film.ouvrir('26-01-particuliers', {
    vue: 'clients', serie: 'Clients', parent: 'clients',
    sources: ['panel/public/dashboard/clients.js', 'panel/src/clients_panel.php', 'panel/public/dashboard/offres.js'],
    avant: async p => { await connecterTfb(p); await p.evaluate(() => localStorage.setItem('pcl-onglet:4', 'b2c')); await recharger(p); },
  });

  /* L'onglet */
  await f.onglet({
    id: 'clients-particuliers', titre: 'Les particuliers',
    resume: 'Les particuliers du magasin dans TF Buddy : la recherche par nom, téléphone ou e-mail, le filtre des remises, la fiche et ses gestes.',
    accroche: 'Retrouver un client en trois lettres',
    programme: ['Ajouter un client', 'Modifier un client', 'Passer un client en entreprise', 'Supprimer un client', 'Préparer une offre'],
  });
  await f.montrer('.pcl-tabs', 'Particuliers');
  await f.dire('« <b>Particuliers</b> » : les clients du magasin dans TF Buddy, lus avec le compte du magasin.', 4400);
  await f.montrer('.pcl-carte', 'La liste');
  await f.dire('Sans recherche, les <b>derniers créés</b> d’abord. Le téléphone n’y paraît qu’en partie ; la fiche le montre en entier.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-act="p-filtre"][data-v="remise"]', { apres: 1100 });
  await f.montrer('.pcl-filtres', 'Une remise');
  await f.dire('« <b>Une remise</b> » : les seuls clients qui ont une remise personnelle.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-act="p-filtre"][data-v=""]', { apres: 900 });
  await chercher(f, 'renard');
  await f.montrer('.pcl-carte', 'Chercher');
  await f.dire('La recherche : chaque mot doit s’y trouver — le nom, l’e-mail, la ville, ou les chiffres du téléphone.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-ligne[data-id="8391"] td:first-child', { apres: 1100 });
  await f.montrer('.pcl-fiche', 'La fiche');
  await f.dire('Un clic ouvre la fiche à côté : la remise, le paiement différé, la ville, puis les coordonnées.', 4800);
  await f.montrer('.pcl-fiche .pcl-acts', 'Les gestes');
  await f.dire('Ses gestes : préparer une offre, modifier, passer en entreprise, et supprimer un client né dans ce magasin.', 5200);
  await f.cacher(300); await f.taire(200);

  /* Ajouter un client */
  await fonction(f, { id: 'clients-ajouter-particulier', titre: 'Ajouter un client',
    resume: 'Un nouveau particulier dans TF Buddy : son nom et son téléphone, obligatoires, puis ce que l’on sait.',
    points: ['« Ajouter un client »', 'Le nom et le téléphone', '« Ajouter »'] });
  await f.cliquer('.pcl-tete [data-act="p-ajouter"]', { apres: 900 });
  await ecrire(f, '#pcl-c-m-name', 'Élise');
  await ecrire(f, '#pcl-c-m-surname', 'Moreau');
  await ecrire(f, '#pcl-c-m-phone', '+32 400 31 41 59', { delai: 45 });
  await ecrire(f, '#pcl-c-m-email', 'elise.moreau@exemple.be', { delai: 40 });
  await ecrire(f, '#pcl-c-m-city', 'Val-Fleuri');
  await f.montrer('.pcl-fen .confirm__box', 'Ajouter un client');
  await f.dire('Le <b>nom</b> et le <b>téléphone</b> sont obligatoires ; le reste se complète plus tard.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-fen [data-act="p-ok"]', { apres: 1800 });
  await f.montrer('.pcl-fiche', 'Élise Moreau');
  await f.dire('Le client est créé dans TF Buddy : la liste le retrouve et sa fiche s’ouvre.', 4200);
  await f.cacher(300); await f.taire(200);

  /* Modifier un client */
  await fonction(f, { id: 'clients-modifier-particulier', titre: 'Modifier un client',
    resume: 'Les coordonnées et la note d’un client, changées dans TF Buddy depuis sa fiche.',
    points: ['Ouvrir la fiche', '« Modifier »', '« Enregistrer »'] });
  await chercher(f, 'renard');
  await f.cliquer('.pcl-ligne[data-id="8391"] td:first-child', { apres: 1100 });
  await f.cliquer('.pcl-fiche [data-act="p-modifier"]', { apres: 900 });
  await f.dire('La fenêtre reprend la fiche : prénom, nom, téléphone, e-mail, adresse, note.', 4000);
  await ecrire(f, '#pcl-c-m-comment', 'Commande souvent le samedi pour 10 h');
  await f.cliquer('.pcl-fen [data-act="p-ok"]', { apres: 1700 });
  await f.montrer('.pcl-fiche', 'Enregistré');
  await f.dire('<b>Enregistrer</b> : la fiche est à jour dans TF Buddy, la note y paraît.', 3800);
  await f.cacher(300); await f.taire(200);

  /* Passer en entreprise */
  await fonction(f, { id: 'clients-passer-entreprise', titre: 'Passer un client en entreprise',
    resume: 'Un client devient une entreprise : la TVA se vérifie dans TF Buddy puis VIES et remplit la société ; la fiche passe dans Entreprises B2B.',
    points: ['« Passer en entreprise »', 'La TVA : « Vérifier »', 'Les conditions de paiement', 'Confirmer'] });
  await chercher(f, 'lefèvre');
  await f.cliquer('.pcl-ligne[data-id="8390"] td:first-child', { apres: 1100 });
  await f.montrer('.pcl-fiche', 'Benoît Lefèvre');
  await f.dire('Benoît Lefèvre commande pour son atelier : il devient une <b>entreprise</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-fiche [data-act="p-entreprise"]', { apres: 900 });
  await ecrire(f, '#pcl-c-m-tax_number', 'BE0000.555.666', { delai: 60 });
  await f.cliquer('.pcl-fen [data-act="tva"]', { apres: 1300 });
  await f.montrer('.pcl-fen .confirm__box', 'TVA trouvée');
  await f.dire('<b>Vérifier</b> : TF Buddy, puis VIES, trouve la société et remplit son nom.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#pcl-c-m-differe', { apres: 400 });
  await ecrire(f, '#pcl-c-m-delai', '30');
  await f.dire('Le <b>paiement différé</b> et son délai, la remise : ses conditions d’entreprise.', 4000);
  await f.taire(150);
  await f.cliquer('.pcl-fen [data-act="p-entreprise-ok"]', { apres: 2200 });
  await f.montrer('.pcl-fiche', 'Menuiserie Lefèvre SRL');
  await f.dire('La fiche passe dans <b>Entreprises B2B</b> : le nom de la personne s’efface au profit de la société.', 4800);
  await f.cacher(300); await f.taire(200);

  /* Supprimer un client */
  await fonctionPreparee(f, { id: 'clients-supprimer-particulier', titre: 'Supprimer un client',
    resume: 'Une fiche créée dans ce magasin, en double par exemple, supprimée de TF Buddy ; ses tickets et ses commandes restent.',
    points: ['Ouvrir la fiche', '« Supprimer »', 'Confirmer'] }, async () => {
    await f.p.locator('.pcl-tabs [data-act="onglet"][data-v="b2c"]').click();
    await attendre(900);
    await f.p.evaluate(() => window.scrollTo(0, 0));
  });
  await chercher(f, 'renard');
  await f.montrer('.pcl-carte', 'En double');
  await f.dire('Deux fiches pour Sophie Renard : la seconde, créée hier au comptoir, est en <b>double</b>.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-ligne[data-id="8392"] td:first-child', { apres: 1100 });
  await f.cliquer('.pcl-fiche [data-act="p-supprimer"]', { apres: 900 });
  await f.montrer('.pcl-fen .confirm__box', 'Supprimer le client');
  await f.dire('Le client est supprimé de TF Buddy ; <b>ses tickets et ses commandes restent</b>.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-fen [data-act="p-supprimer-ok"]', { apres: 1700 });
  await f.montrer('.pcl-carte', 'Une seule fiche');
  await f.dire('Il ne reste qu’une fiche. Un client créé par un autre magasin ne s’efface pas d’ici : « Supprimer » n’y paraît pas.', 5600);
  await f.cacher(300); await f.taire(200);

  /* Préparer une offre */
  await fonction(f, { id: 'clients-preparer-offre', titre: 'Préparer une offre pour un client',
    resume: '« Préparer une offre » ouvre la page Offres sur ce client : on y ajoute les produits, puis on crée l’offre dans TF Buddy.',
    points: ['Ouvrir la fiche', '« Préparer une offre »', 'La page Offres, le client choisi'] });
  await f.cliquer('.pcl-ligne[data-id="8391"] td:first-child', { apres: 1100 });
  await f.cliquer('.pcl-fiche [data-act="p-offre"]', { apres: 2200 });
  await f.dire('La page <b>Offres</b> s’ouvre sur une nouvelle offre, le client déjà choisi.', 4000);
  await f.dire('On y ajoute les produits et le jour, puis « <b>Créer l’offre dans TF Buddy</b> ». Le bouton « Offre » de chaque ligne fait de même.', 6000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
