/* Mon équipe › l'assistant de la semaine, étape « Les temps morts » (?vue=equipe ; seulement avec l'option « Tâches
 * dans les temps morts »), et sa fonction en chapitre (24/11/2026) :
 *   onglet    equipe-assistant-temps-morts   les creux habituels du comptoir, les tâches sans heure fixe à cocher, la
 *                                            semaine (le comptoir, ses creux, les tâches posées), le bilan
 *   fonction  equipe-taches                  décocher une tâche, « Vérifier › », « Créer » ; au jour, « Tâches des creux »
 * Avant de filmer : les options « Assistant » et « Tâches » allumées, la semaine du 30 novembre (vide) montrée,
 * l'assistant ouvert sur la semaine passée, trois fois « Continuer › ». Données fictives : mocks/20_equipe.js. Chaque
 * phrase vient de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-10-assistant-temps-morts', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.options(p, { assistant: true, taches: true }); await E.recharger(p); await E.planning(p, { pas: 2 }); await E.assistant(p, { suites: 3 }); } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-assistant-temps-morts', titre: 'L’assistant : les temps morts',
    resume: 'Les checklists sans heure fixe posées dans les creux habituels du comptoir, chacune à décocher.',
    accroche: 'Les creux, mis à profit',
    programme: ['Poser les tâches dans les temps morts'],
  });
  await f.montrerTout('.eqp-as-q, .eqp-as-qs', 'Les temps morts du comptoir');
  await f.dire('Avec l’option « Tâches », une étape de plus : les <b>creux habituels</b> du comptoir, 10 minutes et plus sans ticket.', 5600);
  await f.montrer('.eqp-tc-t:not(.fixe)', 'Une tâche');
  await f.dire('Les tâches qui n’ont pas besoin d’une heure fixe, à cocher : leur code, leur durée, leurs jours.', 4600);
  await f.montrer('.eqp-tc-t:not(.fixe) .d', 'Où elle va');
  await f.dire('Chacune dit où elle va : dans un creux, ou à son heure habituelle quand aucun creux n’a la place.', 5000);
  await f.montrer('.eqp-tc-sem .eqp-tc-j', 'Lundi');
  await f.dire('La semaine : le comptoir, ses creux, et les tâches posées, chacune à quelqu’un du comptoir présent.', 5000);
  await f.montrer('.eqp-tc-sem .eqp-tc-cr', 'Un creux');
  await f.dire('Un creux : ses heures, ses minutes, les tâches posées, à qui, et ce qui reste libre.', 4400);
  await f.montrer('.eqp-tc-t.fixe', 'Gardent leur heure');
  await f.dire('L’ouverture, la cuisson, la fermeture <b>gardent leur heure</b>.', 3400);
  await f.montrer('.eqp-tc-cols .eqp-as-bilan', 'Le bilan');
  await f.dire('Ni heure ni personne en plus : <b>le budget ne bouge pas</b>.', 3400);
  await E.sousTitre(f, 'haut');
  await f.montrer('.eqp-as-pied .res', 'Le compte');
  await f.dire('En bas : les tâches posées dans les creux, et celles qui gardent leur heure.', 3800);
  await f.cacher(300); await f.taire(150);
  await E.sousTitre(f, 'bas');

  /* Poser les tâches dans les temps morts */
  await f.fonction({ id: 'equipe-taches', titre: 'Poser les tâches dans les temps morts',
    resume: 'Décocher les tâches à ne pas poser, puis créer la semaine : les tâches restent posées dans les creux, la ligne « Tâches des creux » du jour les montre.',
    points: ['Décocher les tâches à ne pas poser', '« Vérifier › »', '« Créer » au panel', 'Au jour : « Tâches des creux »'] });
  const t = '[data-eqp-as-tache="1.5.02"]';
  await f.cliquer(t, { apres: 900 });
  await f.montrer(t, 'Décochée');
  await f.dire('L’audit visuel du mardi, décoché : il <b>garde son heure</b>.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-suite]', { apres: 1100 });
  await E.sousTitre(f, 'haut');
  await f.montrer('[data-eqp-as-creer]', 'Créer');
  await f.dire('« Vérifier › », puis « <b>Créer</b> » : la semaine et ses tâches, au panel.', 3600);
  await f.cacher(150); await f.taire(150);
  await E.sousTitre(f, 'bas');
  await f.cliquer('[data-eqp-as-creer]', { apres: 2200 });
  await f.defiler(-3000);
  await f.montrer('.eqp-flash', 'Créé');
  await f.dire('Les services sont créés, les tâches <b>posées dans les temps morts</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-jour="2026-12-01"]', { apres: 1700 });
  await f.montrer('.eqp-tcl', 'Tâches des creux');
  await f.dire('Au jour, la ligne « <b>Tâches des creux</b> » : chaque tâche à son heure, et à qui. Les heures fixes en pointillé.', 5400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
