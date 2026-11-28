/* Commandes › la vue « Tournées » (?vue=commandes), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    commandes-tournees          la frise de la journée, une colonne par tournée (ses arrêts, ses bureaux sans
 *                                         commande), le retrait au magasin ; la semaine et le mois par tournée et par
 *                                         bureau ; la liste « Gérer les tournées »
 *   fonction  commandes-creer-tournee     nom, départ, retour, livreur, véhicule, jours, bureaux (POST …/tournees,
 *                                         POST …/tournees/bureaux)
 *   fonction  commandes-modifier-tournee  l'horaire, l'ordre de passage, une consigne (PUT …/tournees)
 *   fonction  commandes-retirer-tournee   la confirmation, puis la tournée retirée (DELETE …/tournees)
 * Données fictives : mocks/25_commandes.js (jeudi 19/11, 15 h 40). La vue Tournées est choisie avant de filmer (gardée
 * par appareil, comme commandes.js la garde). Chaque phrase vient de panel/public/dashboard/commandes.js (vueTournees,
 * vuePeriodeTournees, fenGerer, fenTournee) ou de panel/src/commandes_tournees.php (pcoTourneeLue, pcoAffecter). */
const { Film } = require('../film');
const { heure, ecrire, fonction, recharger } = require('./commandes');

module.exports = async function () {
  const f = await Film.ouvrir('25-02-tournees', {
    vue: 'commandes', serie: 'Commandes', parent: 'commandes',
    sources: ['panel/public/dashboard/commandes.js', 'panel/src/commandes_tournees.php'],
    avant: async p => { await p.evaluate(() => localStorage.setItem('pco-vue:4', 'tournees')); await recharger(p); },
  });

  /* L'onglet */
  await f.onglet({
    id: 'commandes-tournees', titre: 'Les tournées',
    resume: 'La journée en frise, une colonne par tournée avec ses arrêts et ses bureaux sans commande, le retrait au magasin ; la semaine et le mois par tournée et par bureau.',
    accroche: 'Qui livre quoi, et quand', programme: ['Créer une tournée', 'Modifier une tournée', 'Retirer une tournée'],
  });
  await f.montrer('.pco-bascule', 'Tournées');
  await f.dire('L’interrupteur sur <b>Tournées</b>. Une tournée regroupe les bureaux livrés ensemble : un départ, un livreur, des bureaux dans l’ordre.', 6000);
  await f.montrer('.pco-frise', 'La journée');
  await f.dire('La frise : une ligne par véhicule, le <b>retrait au magasin</b> point par point, et l’heure qu’il est.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-fbloc[data-id="2"]', { apres: 1400 });
  await f.montrer('#pco-col-2', 'Midi · Bois-Joli');
  await f.dire('Un clic sur une tournée mène à sa colonne : l’horaire, le livreur, le véhicule, et la barre de ses arrêts.', 5200);
  await f.montrer('#pco-col-2 .pco-arret.is-vide', 'Pas de commande');
  await f.dire('Ses arrêts dans l’ordre, avec la consigne du bureau ; et le bureau qui n’a <b>pas commandé</b> ce jour.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000);
  await f.montrer('.pco-colonnes > .pco-col:last-child', 'Retrait au magasin');
  await f.dire('La dernière colonne : le <b>retrait au magasin</b>, par canal. Une commande s’ouvre d’un clic, comme dans la liste.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000);
  await f.cliquer('.pco-per [data-v="semaine"]', { apres: 1500 });
  await f.montrer('.pco-tab:has(.pco-per-table)', 'La semaine');
  await f.dire('À la <b>semaine</b> : chaque tournée jour par jour, ses arrêts, son montant et combien de ses bureaux ont été servis.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.pco-tab:last-of-type', 'Les bureaux à livrer');
  await f.dire('Puis les <b>bureaux à livrer</b> : leurs commandes jour par jour, et les jours où leur tournée roule sans commande.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);
  await f.cliquer('.pco-per [data-v="mois"]', { apres: 1500 });
  await f.dire('Au <b>mois</b>, la même lecture, semaine par semaine.', 3200);
  await f.taire(150);
  await f.cliquer('.pco-per [data-v="jour"]', { apres: 1300 });
  await f.cliquer('.pco-barre [data-act="gerer"]', { apres: 1000 });
  await f.montrer('.pco-fen .confirm__box', 'Gérer les tournées');
  await f.dire('« <b>Gérer les tournées</b> » : toutes les tournées du magasin, leur horaire, leurs jours, leurs bureaux. Le panel n’en a pas : elles se tiennent ici.', 6400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-fen [data-act="fermer"].btn--ghost', { apres: 700 });

  /* Créer une tournée */
  await fonction(f, { id: 'commandes-creer-tournee', titre: 'Créer une tournée',
    resume: 'Un nom, le départ et le retour, le livreur, le véhicule, les jours, puis les bureaux dans l’ordre de passage.',
    points: ['« Gérer les tournées » › « Nouvelle tournée »', 'L’horaire, le livreur, les jours', 'Ajouter les bureaux', '« Enregistrer »'] });
  await f.cliquer('.pco-barre [data-act="gerer"]', { apres: 900 });
  await f.cliquer('.pco-fen [data-act="t-nouvelle"]', { apres: 900 });
  await ecrire(f, '.pco-fen [data-f="nom"]', 'Après-midi · Val-Fleuri');
  await heure(f, '.pco-fen [data-f="depart"]', '14:30');
  await heure(f, '.pco-fen [data-f="retour"]', '15:15');
  await ecrire(f, '.pco-fen [data-f="livreur"]', 'Nora L.');
  await ecrire(f, '.pco-fen [data-f="vehicule"]', 'Vélo cargo');
  await f.cliquer('.pco-fen #pco-bq', { apres: 200 });
  await f.taper('Imprimerie', 90);
  await f.pause(900);
  await f.dire('Les jours : du lundi au vendredi, d’office. Puis <b>ajouter un bureau</b> : un client pro du magasin, par sa société ou sa ville.', 5000);
  await f.cliquer('.pco-fen [data-act="b-ajouter"][data-cli="7221"]', { apres: 600 });
  await f.p.keyboard.press('Control+A'); await f.taper('Pharmacie', 90); await f.pause(900);
  await f.cliquer('.pco-fen [data-act="b-ajouter"][data-cli="7219"]', { apres: 600 });
  await ecrire(f, '.pco-fen [data-bc="1"]', 'Porte de service, à gauche');
  await f.montrer('.pco-fen .pco-blist', 'Ordre de passage');
  await f.dire('Les bureaux, dans l’<b>ordre de passage</b>, chacun avec sa consigne : étage, accueil, badge…', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-fen [data-act="t-ok"]', { apres: 1800 });
  await f.montrer('.pco-frise', 'La nouvelle tournée');
  await f.dire('Enregistrée : elle roule dès aujourd’hui. Les commandes de ses bureaux s’y rangeront d’elles-mêmes.', 5000);
  await f.cacher(300); await f.taire(200);

  /* Modifier une tournée */
  await fonction(f, { id: 'commandes-modifier-tournee', titre: 'Modifier une tournée',
    resume: 'Changer l’horaire, les jours, l’ordre de passage ou la consigne d’un bureau, puis enregistrer.',
    points: ['« Modifier », au pied de la colonne', 'L’horaire, les jours', 'Les flèches : l’ordre de passage', '« Enregistrer »'] });
  await f.cliquer('#pco-col-2 [data-act="t-modifier"]', { apres: 900 });
  await heure(f, '.pco-fen [data-f="retour"]', '13:00');
  await f.dire('Le retour passe à <b>13:00</b> : la tournée prend les commandes demandées jusqu’à 13:30.', 4600);
  await f.cliquer('.pco-fen [data-act="b-haut"][data-i="4"]', { apres: 700 });
  await f.montrer('.pco-fen .pco-blist', 'Ordre de passage');
  await f.dire('Les <b>flèches</b> changent l’ordre de passage : le cabinet médical passe avant le studio.', 4400);
  await f.cacher(150); await f.taire(150);
  await ecrire(f, '.pco-fen [data-bc="2"]', 'Accueil, 2e étage');
  await f.dire('Une <b>consigne</b> pour le livreur ; × retire un bureau de la tournée.', 3600);
  await f.taire(150);
  await f.cliquer('.pco-fen [data-act="t-ok"]', { apres: 1800 });
  await f.montrer('#pco-col-2 .pco-colmeta', '11:15 → 13:00');
  await f.dire('Enregistré : la colonne montre le nouvel horaire.', 3400);
  await f.cacher(300); await f.taire(200);

  /* Retirer une tournée */
  await fonction(f, { id: 'commandes-retirer-tournee', titre: 'Retirer une tournée',
    resume: 'La tournée et ses bureaux disparaissent ; leurs commandes ne sont plus rangées dans une tournée.',
    points: ['« Modifier »', '« Retirer la tournée »', 'Lire, puis confirmer'] });
  await f.cliquer('.pco-barre [data-act="gerer"]', { apres: 900 });
  await f.cliquer(f.p.locator('.pco-fen tr:has-text("Après-midi · Val-Fleuri") [data-act="t-modifier"]'), { apres: 900 });
  await f.cliquer('.pco-fen [data-act="t-suppr"]', { apres: 900 });
  await f.montrer('.pco-fen .confirm__box', 'Retirer la tournée ?');
  await f.dire('La tournée et ses 2 bureaux disparaissent ; les commandes, elles, ne changent pas au panel.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-fen [data-act="t-suppr-ok"]', { apres: 1700 });
  await f.montrer('.pco-frise', 'Retirée');
  await f.dire('Retirée : elle quitte la frise et la liste des tournées du magasin.', 3800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
