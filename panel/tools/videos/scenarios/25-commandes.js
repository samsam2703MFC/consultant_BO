/* Vidéo 25 — « Les commandes du magasin » (mode d'emploi de la page Commandes, ?vue=commandes). Environ 2 min 40.
 * Données fictives : mocks/25_commandes.js. Le récit : jeudi 19/11, 15 h 40 ; les tournées du matin et de midi sont
 * terminées, celle de fin de journée part à 16 h 30 ; une livraison du webshop n'est dans aucune tournée et se place.
 * Chaque phrase vient de panel/public/dashboard/commandes.js ou de panel/src/commandes_tournees.php. */
const { Film } = require('../film');
const { choisir } = require('./commandes');

module.exports = async function () {
  const f = await Film.ouvrir('25-commandes', { vue: 'commandes', serie: 'Mode d’emploi', titreSerie: 'Les commandes du magasin', duree: 'env. 2 min 40' });
  await f.couverture({
    kicker: 'Mode d’emploi · La journée', titre: 'Les commandes<br>du magasin', accroche: 'Chaque commande à sa place',
    programme: ['La journée, en liste', 'Les tournées du jour', 'Placer une commande', 'La semaine et le mois'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'La journée, en liste', texte: 'Les commandes du jour au panel — webshop, comptoir, bureau — avec leur tournée ou le retrait au magasin.' });
  await f.etape('1 · La liste');
  await f.montrer('#db-rail .sidebar__item[data-vue=commandes]', 'Commandes');
  await f.dire('<b>Commandes</b>, dans La journée : le chiffre dit les commandes <b>à préparer</b> aujourd’hui.', 4200);
  await f.montrer('.pco-barre', 'Le jour, la vue');
  await f.dire('En haut : le jour, la période (jour, semaine, mois) et l’interrupteur <b>Liste · Tournées</b>.', 4600);
  await f.montrer('.pco-res-l1', 'Les canaux');
  await f.dire('Le résumé : <b>19 commandes</b>, dont 9 de bureaux ; 5 à préparer, 10 à livrer, 9 à retirer.', 5200);
  await f.cacher(150);
  await f.montrer('.pco-now', 'Maintenant');
  await f.dire('La liste suit l’heure : un filet marque <b>maintenant</b>. Chaque ligne dit sa tournée et son arrêt, ou le retrait au magasin.', 5600);
  await f.montrer('.pco-ligne[data-id="5113"]', 'Pas encore remise');
  await f.dire('Une commande dont l’heure est passée sans remise le signale : <b>pas encore remise</b>.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000);
  await f.cliquer('.pco-outils [data-act="canal"][data-v="bureau"]', { apres: 700 });
  await f.cliquer('.pco-outils [data-act="mode"][data-v="livrer"]', { apres: 700 });
  await f.montrer('.pco-outils', 'Les filtres');
  await f.dire('Les filtres : un canal, à livrer ou à retirer, et la recherche d’un client, d’un bureau ou d’un numéro.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-outils [data-act="canal"][data-v=""]', { apres: 300 });
  await f.cliquer('.pco-outils [data-act="mode"][data-v=""]', { apres: 600 });
  await f.montrer('.pco-volet .pco-tcarte:nth-of-type(1)', 'Tournées du jour');
  await f.dire('À droite, les <b>tournées du jour</b> : leurs arrêts, prêts ou livrés, et les bureaux sans commande.', 5000);
  await f.montrer('.pco-demain', 'Demain');
  await f.dire('Et <b>demain</b> : les bureaux qui n’ont pas encore commandé, avant 16:00.', 4200);
  await f.cacher(150); await f.taire(150);

  await f.chapitre({ n: 2, total: 4, titre: 'Les tournées du jour', texte: 'La journée en frise, puis une colonne par tournée, ses arrêts dans l’ordre.' });
  await f.etape('2 · Les tournées');
  await f.defiler(-2000);
  await f.cliquer('.pco-bascule [data-v="tournees"]', { apres: 1200 });
  await f.montrer('.pco-frise', 'La journée');
  await f.dire('La frise : chaque véhicule avec ses tournées, le <b>retrait au magasin</b>, et l’heure qu’il est.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-fbloc[data-id="3"]', { apres: 1400 });
  await f.montrer('#pco-col-3', 'Fin de journée');
  await f.dire('Un clic sur une tournée mène à sa colonne : départ dans 50 min, ses arrêts dans l’ordre, prêts ou en préparation.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000);
  await f.montrer('.pco-res .pco-corr-b[data-act="cmd"]', 'À placer');
  await f.dire('En tête, « À corriger » : une <b>livraison du webshop</b> n’est dans aucune tournée.', 4400);
  await f.cacher(150); await f.taire(150);

  await f.chapitre({ n: 3, total: 4, titre: 'Placer une commande', texte: 'Une commande s’ouvre d’un clic : sa tournée se choisit, ou le retrait au magasin.' });
  await f.etape('3 · Placer');
  await f.cliquer('.pco-res .pco-corr-b[data-act="cmd"]', { apres: 900 });
  await f.montrer('.pco-fen .confirm__box', 'Commande n° 5117');
  await f.dire('La commande : son client, son canal, son montant, son statut, sa note.', 4000);
  await f.dire('« Changer la livraison » : <b>automatique</b> (la tournée de son bureau), une autre tournée, ou le retrait au magasin.', 5600);
  await f.cacher(150); await f.taire(150);
  await choisir(f, '#pco-aff', '3');
  await f.cliquer('.pco-fen [data-act="aff-ok"]', { apres: 1600 });
  await f.montrer('#pco-col-3', 'Arrêt 3');
  await f.dire('La commande rejoint <b>Fin de journée</b>, à son heure : l’arrêt 3. Elle quitte « À corriger ».', 4600);
  await f.cacher(150); await f.taire(150);

  await f.chapitre({ n: 4, total: 4, titre: 'La semaine et le mois', texte: 'Les mêmes commandes, jour par jour, face à la période d’avant.' });
  await f.etape('4 · Semaine et mois');
  await f.defiler(-2000);
  await f.cliquer('.pco-bascule [data-v="liste"]', { apres: 700 });
  await f.cliquer('.pco-per [data-v="semaine"]', { apres: 1400 });
  await f.montrer('.pco-res-l1', 'La semaine');
  await f.dire('La <b>semaine</b> : chaque canal face à la semaine d’avant, en mots et en flèche.', 4400);
  await f.montrer('.pco-per-table', 'Jour par jour');
  await f.dire('Puis jour par jour : les commandes, les canaux, les livraisons et le retrait au magasin.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-per [data-v="mois"]', { apres: 1400 });
  await f.montrer('.pco-cal', 'Le mois');
  await f.dire('Le <b>mois</b>, en calendrier : la barre de chaque jour, partagée par canal, et le total de chaque semaine.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-cal [data-d="2026-11-18"]', { apres: 1400 });
  await f.montrer('.pco-barre', 'Mercredi 18');
  await f.dire('Un clic sur un jour ouvre ses commandes. Dans la vue Tournées, la semaine et le mois se lisent par tournée et par bureau.', 5800);
  await f.cacher();

  return f.fin({
    titre: 'À vous de jouer', accroche: 'Rien ne part sans tournée',
    retenir: ['Liste ou Tournées : l’interrupteur en haut', 'Une commande à placer : un clic, une tournée', 'Demain : les bureaux qui n’ont pas commandé', 'Semaine et mois : face à la période d’avant'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
