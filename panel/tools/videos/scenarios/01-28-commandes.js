/* La barre du haut › les fenêtres communes › « Commandes du jour » (?vue=ops), 26/11/2026 :
 *   onglet    barre-commandes   le ruban des canaux d'Opérationnel ouvre la fenêtre : Toutes · Comptoir · Webshop, le CA
 *                               en commandes rangé par état (en caisse, payée avant, en ligne, à venir) face à la caisse
 *                               du jour, puis chaque commande dépliée sur ses articles et son ticket (vérifier les
 *                               commandes du jour : un geste de lecture, montré ici)
 * Aucune fonction en chapitre : la fenêtre ne fait que lire. Données fictives : mocks/10_ops.js (lib/jour.js,
 * commandesDetail). Chaque phrase vient de panel/public/dashboard/dashboard.js (opRuban, cmdmOuvrir, cmdmRendre, CMD_ETAT). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-28-commandes', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'],
  });

  await f.onglet({
    id: 'barre-commandes', titre: 'Commandes du jour',
    resume: 'Le ruban des canaux d’Opérationnel ouvre « Commandes du jour » : Toutes, Comptoir ou Webshop, le CA en commandes rangé par état face à la caisse, puis chaque commande avec ses articles et son ticket.',
    accroche: 'Chaque commande, à sa place',
    programme: ['Le ruban des canaux', 'Le CA en commandes', 'Comptoir · Webshop', 'Une commande, dépliée'],
  });
  await f.montrer('#dash .op-rb', 'Par canal');
  await f.dire('Sur Opérationnel, sous la jauge du comptoir : la part de chaque <b>canal</b>, comptoir, commandes, webshop.', 5600);
  await f.dire('Un appui sur un canal, ou sur « toucher un canal », ouvre ses commandes.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .op-rb .bas[data-cmdm="tous"]', { apres: 1400 });
  await B.cadre(f, '#db-cmdm .bm-ong', 'Toutes · Comptoir · Webshop');
  await f.dire('« <b>Commandes du jour</b> » s’ouvre sur toutes ; les boutons gardent le comptoir ou le webshop.', 5000);
  await B.cadre(f, '#db-cmdm .bm-rap', 'Le CA en commandes');
  await f.dire('Le <b>CA en commandes</b>, rangé par état : encaissées en caisse, payées un autre jour, payées en ligne, à venir.', 6200);
  await B.cadre(f, '#db-cmdm .bm-rap .eq', 'Face à la caisse');
  await f.dire('Puis ce qui est dans le chiffre du jour, face à la <b>caisse du jour</b>.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-cmdm [data-cmdt] >> nth=0', { apres: 900 });
  await f.montrer('#db-cmdm .bm-li.ouv', 'Une commande');
  await f.dire('Chaque commande : l’heure, ses articles, son état ; un appui la déplie sur ses lignes et son ticket.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-cmdm [data-cmdf="web"]', { apres: 1100 });
  await B.cadre(f, '#db-cmdm .fi-modale', 'Webshop');
  await f.dire('« <b>Webshop</b> » : le click & collect et les livraisons, payés en ligne ou à la caisse.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-cmdm [data-cmdf="compt"]', { apres: 1100 });
  await B.cadre(f, '#db-cmdm .fi-modale', 'Comptoir');
  await f.dire('« <b>Comptoir</b> » : les commandes prises au magasin.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-cmdm .fi-x', { apres: 800 });
  await f.dire('La croix referme la fenêtre.', 2400);
  await f.taire(150);
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
