/* Mon équipe › Planning › l'onglet « Mois » (?vue=equipe), sans fonction qui écrit (24/11/2026) :
 *   onglet    equipe-mois   les heures de chacun jour par jour, un point de couleur par jour, chaque semaine face au
 *                           budget ; un clic sur une semaine la montre, sur un jour l'ouvre en grand
 * (Ajouter un service depuis une case vide du mois : le chapitre « Ajouter un service » de 02-02-semaine.)
 * Données fictives : mocks/20_equipe.js (le planning existe du 1er au 29 novembre 2026). Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-04-mois', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.planning(p); } });

  await f.onglet({
    id: 'equipe-mois', titre: 'Le planning du mois',
    resume: 'Les heures de chacun jour par jour, et un point de couleur par jour et par semaine face au budget.',
    accroche: 'Le mois d’un coup d’œil',
    programme: ['Les heures de chacun', 'Le budget par jour et par semaine', 'Ouvrir une semaine ou un jour'],
  });
  await f.montrer('[data-eqp-vue=mois]', 'Mois');
  await f.dire('Le bouton « <b>Mois</b> » montre tout le mois.', 2800);
  await f.cacher(150);
  await f.cliquer('[data-eqp-vue=mois]', { apres: 1600 });
  await f.montrer('.eqp-kpis', 'Le mois en chiffres');
  await f.dire('En tête : les heures du mois, la part du personnel, et les jours <b>au-dessus de la cible</b>.', 4400);
  await f.montrer('.eqp-moi tbody tr:nth-child(2)', 'Une ligne par personne');
  await f.dire('Une ligne par personne : les heures de chaque service, <b>beige</b> le matin, <b>rose</b> l’après-midi.', 4800);
  await f.montrer('.eqp-moi tr.tot:not(.eqp-msem)', 'Par jour');
  await f.dire('Sous chaque jour, ses heures et un <b>point de couleur</b> : vert dans la cible, orange, rouge, noir.', 5000);
  await f.montrer('.eqp-moi tr.eqp-msem', 'Par semaine');
  await f.dire('Chaque semaine : ses heures et son pourcentage face au budget.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-semaine="2026-11-16"]', { apres: 1600 });
  await f.defiler(-3000);
  await f.montrer('.eqp-nav', 'Semaine du 16');
  await f.dire('Un clic sur une semaine l’ouvre à la <b>semaine</b>.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-vue=mois]', { apres: 1600 });
  await f.montrer('.eqp-moi [data-eqp-jour="2026-11-20"]', 'Vendredi 20');
  await f.dire('Vendredi a un point <b>rouge</b> : un clic sur le jour l’ouvre en grand.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-moi [data-eqp-jour="2026-11-20"]', { apres: 1600 });
  await f.defiler(-3000);
  await f.montrer('.eqp-kpis', 'Vendredi 20');
  await f.dire('Le jour, ses heures et son budget : on y corrige le planning.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-vue=mois]', { apres: 1500 });
  await f.montrer('.eqp-nav', 'Novembre 2026');
  await f.dire('Les flèches passent d’un mois à l’autre ; « Ce mois » y revient.', 3400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
