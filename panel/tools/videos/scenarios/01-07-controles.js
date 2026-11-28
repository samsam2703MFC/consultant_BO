/* Opérationnel › « Contrôles en photo », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-controles-photo   le carrousel des checklists du jour, les puces (Tout, Écarts, À contrôler, Conformes,
 *                                 Non rendues ; Sans photo quand il y en a), le résumé (qui a rendu, qui a noté, la
 *                                 moyenne), le rappel des checklists qui y amène, ‹ ›.
 * Filtrer et faire défiler : des gestes de lecture ; noter reste dans Contrôle des tâches. La photo en grand est la
 * vidéo 01-11. Données fictives : mocks/04_taches.js, 10_ops.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (rendCQ, cqEtat, cqResume, cqFrise, CQ_FILTRES).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const CQ = '.db-cq';

module.exports = async function () {
  const f = await ouvrir('01-07-controles');

  await f.onglet({
    id: 'ops-controles-photo', titre: 'Les contrôles en photo',
    resume: 'Le carrousel des checklists du jour et leurs photos, filtré selon l’état de la photo : écarts, à contrôler, conformes, non rendues.',
    accroche: 'Les photos du jour, d’un coup d’œil',
    programme: ['Les checklists et leurs photos', 'Les filtres', 'Le rappel des checklists'],
  });

  await enHautDe(f, CQ, 16);
  await dire(f, CQ + ' .cqk-c', 'Une checklist', 'Les <b>contrôles en photo</b> du jour : une carte par checklist, à son heure, ses photos dessous.', 5200);
  await dire(f, CQ + ' .cqk-c .cqf-b', 'Rendues / attendues', 'Le bandeau : le code, le nom, l’heure, et les photos rendues sur attendues.', 4400);
  await dire(f, CQ + ' .ct .db-mini', 'Le résumé', 'Au-dessus : qui a rendu les photos et quand, qui les a notées, la moyenne.', 4400);
  await dire(f, CQ + ' .db-cqf', 'Les filtres', 'Les puces filtrent les photos selon leur état ; une puce par état présent ce jour-là.', 4800);
  await rien(f);

  await f.cliquer(CQ + ' [data-cqf="nc"]', { apres: 900 });
  await dire(f, CQ + ' .db-cqc', 'Écarts', '<b>Écarts</b> : notées sous le seuil, avec ce qui a été relevé.', 3800);
  await rien(f);
  await f.cliquer(CQ + ' [data-cqf="ctl"]', { apres: 900 });
  await dire(f, CQ + ' .db-cqc', 'À contrôler', '<b>À contrôler</b> : la photo est déposée, pas encore notée.', 3600);
  await rien(f);
  await f.cliquer(CQ + ' [data-cqf="ko"]', { apres: 900 });
  await dire(f, CQ + ' .db-cqc', 'Non rendues', '<b>Non rendues</b> : la photo attendue n’est pas encore là.', 3600);
  await rien(f);
  await f.cliquer(CQ + ' [data-cqf="tout"]', { apres: 900 });

  await dire(f, CQ + ' .cqk-nav', 'Le rappel', 'Le <b>rappel</b> des checklists : l’heure, le code, rendues sur attendues.', 4200);
  await rien(f);
  await f.cliquer(CQ + ' [data-cqkgo="4"]', { apres: 1400 });
  await dire(f, CQ + ' .cqk-c[data-cqk="4"]', '16:00 · 1.2.01', 'Un clic y amène : le comptoir de l’après-midi, pas encore rendu.', 4200);
  await rien(f);
  await f.cliquer(CQ + ' .cqk-fl.g', { apres: 1300 });
  await f.dire('‹ et › font défiler les checklists.', 2600);
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
