/* Commandes › la vue « Liste » (?vue=commandes), et sa fonction en chapitre (26/11/2026) :
 *   onglet    commandes-liste     le bandeau des canaux, la liste et le filet « Maintenant », une commande ouverte, les
 *                                 filtres et la recherche, les tournées du jour, demain ; le jour d'avant, la semaine
 *                                 (jour par jour) et le mois (le calendrier), un jour rouvert
 *   fonction  commandes-placer    une livraison sans tournée placée dans une tournée (POST …/affecter)
 * Données fictives : mocks/25_commandes.js (jeudi 19/11, 15 h 40). Chaque phrase vient de
 * panel/public/dashboard/commandes.js (vueListe, voletTournees, carteDemain, fenCommande, vueSemaineListe, vueMoisListe)
 * ou de panel/src/commandes_tournees.php (pcoJournee, pcoAffecter). */
const { Film } = require('../film');
const { choisir, fonction } = require('./commandes');

module.exports = async function () {
  const f = await Film.ouvrir('25-01-liste', {
    vue: 'commandes', serie: 'Commandes', parent: 'commandes',
    sources: ['panel/public/dashboard/commandes.js', 'panel/src/commandes_tournees.php'],
  });

  /* L'onglet */
  await f.onglet({
    id: 'commandes-liste', titre: 'La liste des commandes',
    resume: 'Les commandes du jour par canal, la liste qui suit l’heure, une commande ouverte, les filtres, les tournées du jour et demain ; la semaine et le mois.',
    accroche: 'La journée, d’un coup d’œil', programme: ['Placer une commande dans une tournée'],
  });
  await f.montrer('.pco-bascule', 'Liste · Tournées');
  await f.dire('L’interrupteur sur <b>Liste</b> : toutes les commandes de la journée, quel que soit leur canal.', 4200);
  await f.montrer('.pco-res-l1', 'Les canaux');
  await f.dire('Le résumé : les commandes du jour et leur montant, puis le <b>webshop</b>, le <b>comptoir</b> et les <b>bureaux</b>, avec leur part.', 5200);
  await f.montrer('.pco-res-prep', 'À préparer');
  await f.dire('À droite : <b>5 à préparer</b>, 10 à livrer, 9 à retirer.', 3600);
  await f.montrer('.pco-res-l2', 'À corriger');
  await f.dire('Dessous, « <b>À corriger</b> » : ce qui demande un geste, un bouton par commande, par tournée, et demain.', 5000);
  await f.cacher(150);
  await f.montrer('.pco-now', 'Maintenant · 15:40');
  await f.dire('La liste, à l’heure : le filet <b>Maintenant</b> sépare ce qui est passé de ce qui vient.', 4400);
  await f.montrer('.pco-ligne[data-id="5115"]', 'Une ligne');
  await f.dire('Chaque ligne : l’heure, le canal, le client ou le bureau, la tournée et l’arrêt, le montant, le paiement et le statut.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-ligne[data-id="5115"] .pco-nom', { apres: 900 });
  await f.montrer('.pco-fen .confirm__box', 'La commande');
  await f.dire('Un clic l’ouvre : la tournée vient de son bureau, <b>automatiquement</b>.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-fen [data-act="fermer"].btn--ghost', { apres: 600 });
  await f.defiler(-2000);
  await f.cliquer('.pco-outils [data-act="canal"][data-v="webshop"]', { apres: 900 });
  await f.montrer('.pco-tab', 'Webshop');
  await f.dire('Les filtres : un canal (ici le <b>webshop</b>), puis à livrer ou à retirer.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-outils [data-act="canal"][data-v=""]', { apres: 400 });
  await f.cliquer('#pco-q', { apres: 200 });
  await f.taper('Horizon', 110);
  await f.pause(600);
  await f.montrer('.pco-tab', 'Chercher');
  await f.dire('La recherche : un client, un bureau, un numéro de commande.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('#pco-q').fill(''); await f.p.locator('#pco-q').dispatchEvent('input');
  await f.pause(500);
  await f.montrer('.pco-volet .pco-tcarte:nth-of-type(1)', 'Tournées du jour');
  await f.dire('À droite, les <b>tournées du jour</b> : chaque arrêt en rond, vert une fois livré ; « Voir la tournée » ouvre la vue Tournées.', 5600);
  await f.montrer('.pco-demain', 'Demain');
  await f.dire('<b>Demain</b> : on commande jusqu’à 16:00 la veille. 5 bureaux sur 8 ont commandé ; voici ceux qui manquent.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000);
  await f.cliquer('.pco-per [data-v="semaine"]', { apres: 1400 });
  await f.montrer('.pco-per-table', 'La semaine');
  await f.dire('La <b>semaine</b>, jour par jour : commandes, canaux, livraisons, retraits ; un jour « à placer » le signale.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-per [data-v="mois"]', { apres: 1400 });
  await f.montrer('.pco-cal', 'Le mois');
  await f.dire('Le <b>mois</b>, en calendrier. Un clic sur un jour ouvre ses commandes ; les flèches changent de période.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-cal [data-d="2026-11-19"]', { apres: 1400 });
  await f.cacher(200);

  /* Placer une commande */
  await fonction(f, { id: 'commandes-placer', titre: 'Placer une commande dans une tournée',
    resume: 'Une livraison qui n’est dans aucune tournée : on l’ouvre, on choisit sa tournée, ou le retrait au magasin.',
    points: ['« Placer », ou un clic sur la commande', 'Changer la livraison', '« Enregistrer »'] });
  await f.montrer('.pco-ligne[data-id="5117"]', 'Livraison sans tournée');
  await f.dire('Une livraison du <b>webshop</b>, à 17:10 : elle n’est dans aucune tournée.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pco-ligne[data-id="5117"] [data-act="cmd"].pco-acc', { apres: 900 });
  await f.montrer('#pco-aff', 'Changer la livraison');
  await f.dire('<b>Automatique</b> : la tournée de son bureau. Sinon, une tournée du jour, ou le <b>retrait au magasin</b>.', 5000);
  await f.cacher(150); await f.taire(150);
  await choisir(f, '#pco-aff', '3');
  await f.cliquer('.pco-fen [data-act="aff-ok"]', { apres: 1600 });
  await f.montrer('.pco-ligne[data-id="5117"]', 'Arrêt 3');
  await f.dire('Enregistré : <b>Fin de journée</b>, arrêt 3. La commande, elle, ne change pas au panel.', 4600);
  await f.cacher(150);
  await f.montrer('.pco-volet .pco-tcarte:nth-of-type(3)', 'Fin de journée');
  await f.dire('La tournée compte un arrêt de plus. Revenir à « Automatique » rend la commande à la tournée de son bureau.', 5200);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
