/* Opérationnel › « Une journée passée » (le mode « journée terminée »), la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-journee-passee   un jour de la frise, la journée terminée comparée à toute la journée de la semaine
 *                                d'avant, « objectif atteint », la vitrine « ont manqué », ‹ › d'une semaine à
 *                                l'autre (le même jour), « Aujourd'hui » pour revenir.
 * Changer de jour est un geste de lecture : il se montre ici, sans chapitre à lui. Données fictives : mocks/10_ops.js.
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (friseHtml, opDuel, opVitrineE, navPeriode, data-frsem,
 * data-fdate, data-auj).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const CPT = '.op-duel:not(.b2b)';

module.exports = async function () {
  const f = await ouvrir('01-02-passee');

  await f.onglet({
    id: 'ops-journee-passee', titre: 'Une journée passée',
    resume: 'La même page sur un jour clos : choisi dans la frise, comparé à toute la journée de la semaine d’avant, la vitrine « ont manqué », ‹ › d’une semaine à l’autre et « Aujourd’hui » pour revenir.',
    accroche: 'Relire une journée close',
    programme: ['Choisir un jour dans la frise', 'La journée terminée', 'Ce qui a manqué', 'Revenir à aujourd’hui'],
  });

  await dire(f, '.db-frise', 'La semaine', 'La <b>frise</b> : les sept jours de la semaine, leur chiffre et l’objectif atteint.', 4400);
  await rien(f);
  await f.cliquer('[data-fdate="2026-11-17"]', { apres: 2400 });
  await dire(f, '.db-frise .fr-c.on', 'Mardi 17', 'Un jour cliqué : la page entière passe à ce jour, ici <b>mardi 17</b>.', 4200);
  await rien(f);

  await enHautDe(f, CPT, 16);
  await f.montrerTout(`${CPT} > .dh, ${CPT} > .op-piste, ${CPT} > .op-dax`, 'Journée terminée');
  await f.dire('<b>Journée terminée</b> : elle se compare à toute la journée de mardi dernier.', 4400);
  await dire(f, `${CPT} > .dh .chip`, 'Objectif atteint', 'L’objectif est atteint : la jauge passe à l’<b>or</b>.', 3400);
  await rien(f);

  await enHautDe(f, '.vt', 30);
  await dire(f, '.vt .vt-top', 'Ont manqué', 'La vitrine dit ce qui <b>a manqué</b>, et les ventes perdues.', 4000);
  await f.montrerTout('.vt .vt-h', 'Par durée de vie');
  await f.dire('Par durée de vie : les produits vides alors que des clients les demandaient encore.', 4400);
  await rien(f);

  await f.defiler(-6000);
  await f.montrer('.db-frise [data-frsem="-1"]', '‹');
  await f.dire('‹ recule d’une semaine, au <b>même jour</b>.', 3000);
  await rien(f);
  await f.cliquer('.db-frise [data-frsem="-1"]', { apres: 2400 });
  await dire(f, '.db-frise', 'Mardi 10', 'Mardi 10 novembre ; › avance d’une semaine.', 3400);
  await rien(f);
  await f.montrer('[data-auj]', 'Aujourd’hui');
  await f.dire('« <b>Aujourd’hui</b> », à côté du titre, ramène à la journée en cours.', 3800);
  await rien(f);
  await f.cliquer('[data-auj]', { apres: 2400 });
  await dire(f, '.db-frise .fr-c.on', 'En cours', 'Retour au jeudi 19, en cours.', 2600);
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
