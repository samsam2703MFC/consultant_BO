/* Mon coffre-fort › « Rapports de visite » (?vue=coffre, à gauche) (26/11/2026) :
 *   onglet    coffre-visites   les rapports envoyés après chaque visite du consultant, lus ou « à lire », leur aperçu
 *                              qui renvoie à « Mon suivi »
 * « Lire le rapport ↗ » ouvre « Mon suivi » (un autre portail) dans un nouvel onglet du navigateur : il ne se filme pas,
 * on montre le bouton. Ouvrir un rapport ne le marque pas lu (rien n'est écrit). Données fictives : mocks/23_coffre.js.
 * Chaque phrase vient de panel/public/dashboard/coffre.js (lignes, aFaire, tiroir). */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-04-visites', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES, avant: C.marge });

  await f.onglet({
    id: 'coffre-visites', titre: 'Les rapports de visite',
    resume: 'Le rapport envoyé après chaque visite de votre consultant arrive ici tout seul : lu, ou « à lire » ; son aperçu renvoie à « Mon suivi ».',
    accroche: 'Chaque visite, son rapport',
  });
  await f.montrer('.cf2-rs-b[data-cf-faire="nouveaux"]', 'À lire');
  await f.dire('Un nouveau rapport de visite ? Il compte dans les <b>nouveaux documents</b> à lire, et sur le rail.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-d[data-cf-dos="visites"]', { apres: 1000 });
  await f.montrer('.cf2-cont .cf2-carte', 'Rapports de visite');
  await f.dire('<b>Rapports de visite</b> : ils arrivent tout seuls, après chaque visite de votre consultant.', 4600);
  await f.montrerTout('.cf2-cont .cf2-l .et', 'Lu, ou à lire');
  await f.dire('Chacun dit s’il est <b>lu</b>, ou « <b>à lire</b> ».', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-l[data-cf-sel="v301"]', { apres: 1300 });
  await C.cadreFixe(f, '.cf2-tir .cf2-kv', 'La visite');
  await f.dire('L’aperçu : le jour de la visite, quand le rapport a été envoyé, et s’il est lu.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.survoler('.cf2-tir .cf2-acts a', { pause: 400 });
  await C.cadreFixe(f, '.cf2-tir .cf2-acts', 'Lire le rapport');
  await f.dire('« <b>Lire le rapport</b> » l’ouvre dans <b>Mon suivi</b>, dans un nouvel onglet.', 4000);
  await C.cadreFixe(f, '.cf2-tir .c > .cf2-n', 'Mon suivi');
  await f.dire('Le rapport, ses photos et ses points à corriger sont dans « Mon suivi ».', 4000);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-tir [data-cf-ferme].x', 900);
  await f.cliquer('.cf2-l[data-cf-sel="v299"]', { apres: 1300 });
  await C.cadreFixe(f, '.cf2-tir .h', 'Audit qualité');
  await f.dire('Une visite régulière, un <b>audit qualité</b> : le type de visite est dans le titre.', 4000);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-tir [data-cf-ferme].x', 900);
  await f.montrer('.cf2-cont .cf2-carte', 'Rapports de visite');
  await f.pause(1600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
