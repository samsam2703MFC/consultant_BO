/* Mon équipe › « Options » (?vue=equipe), et sa fonction en chapitre (24/11/2026) :
 *   onglet    equipe-options   le module principal, et les quatre modules en option, éteints par défaut
 *   fonction  equipe-option    allumer « Recopier la semaine précédente » : « Préparer la semaine » apparaît ; l'éteindre
 * Données fictives : mocks/20_equipe.js (toutes les options éteintes au départ). Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-06-options', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.planning(p); } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-options', titre: 'Les options de Mon équipe',
    resume: 'Les quatre modules en option, éteints par défaut, chacun avec ce qu’il fait et son interrupteur.',
    accroche: 'Le planning d’abord, le reste au choix',
    programme: ['Allumer ou éteindre une option'],
  });
  await f.montrer('[data-eqp-options]', 'Options');
  await f.dire('« <b>Options</b> », en haut à droite de Mon équipe.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-options]', { apres: 1000 });
  await f.montrer('.eqp-opts .qui', 'Le module principal');
  await f.dire('Le module principal : les responsabilités, le planning, la fenêtre d’un service, l’impression.', 4600);
  await f.dire('Le reste s’allume ici, <b>pour ce magasin</b>. Éteint, il ne se montre pas.', 3800);
  const L = f.p.locator('.eqp-opt-l');
  await f.montrer(L.nth(0), 'Assistant de la semaine');
  await f.dire('L’<b>assistant</b> : reprendre la semaine passée, qui adapter, les heures de trop, puis créer la semaine.', 5000);
  await f.montrer(L.nth(1), 'Tâches dans les temps morts');
  await f.dire('Les <b>tâches</b> sans heure fixe, posées dans les creux habituels du comptoir.', 4000);
  await f.montrer(L.nth(2), 'Matrice A B C D et shift maker');
  await f.dire('La <b>matrice</b> : quatre roulements sur deux semaines, la lettre de chacun, et le shift maker.', 4600);
  await f.montrer(L.nth(3), 'Recopier la semaine précédente');
  await f.dire('La <b>recopie</b> : la semaine d’avant, reportée de sept jours.', 3400);
  await f.montrerTout('.eqp-opts .eqp-switch', 'Les interrupteurs');
  await f.dire('Chacun a son interrupteur : tous sont éteints au départ.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-options-fermer]', { apres: 800 });

  /* Allumer ou éteindre une option */
  await f.fonction({ id: 'equipe-option', titre: 'Allumer ou éteindre une option',
    resume: '« Options », l’interrupteur d’un module : le bouton dit combien sont allumées, et le module apparaît. Le même geste l’éteint.',
    points: ['« Options »', 'L’interrupteur du module', 'Le module apparaît', 'Le même geste l’éteint'] });
  await f.cliquer('[data-eqp-options]', { apres: 1000 });
  await f.montrer('.eqp-opt-l:has([data-eqp-opt="recopie"])', 'Recopier la semaine précédente');
  await f.dire('On allume « <b>Recopier la semaine précédente</b> ».', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-opt="recopie"]', { apres: 1400 });
  await f.montrer('[data-eqp-opt="recopie"]', 'Allumée');
  await f.dire('L’interrupteur passe au vert : c’est enregistré pour ce magasin.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-options-fermer]', { apres: 900 });
  await f.montrer('.eqp-opt-btn', 'Options · 1 allumée');
  await f.dire('Le bouton dit « Options · <b>1 allumée</b> ».', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-menu summary', { apres: 800 });
  await f.montrer('.eqp-menu', 'Préparer la semaine');
  await f.dire('Et « <b>Préparer la semaine</b> » apparaît, avec la recopie.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-menu summary', { apres: 600 });
  await f.dire('Le même geste l’<b>éteint</b>.', 0);
  await f.cliquer('[data-eqp-options]', { apres: 900 });
  await f.cliquer('[data-eqp-opt="recopie"]', { apres: 1200 });
  await f.cliquer('[data-eqp-options-fermer]', { apres: 800 });
  await f.montrer('.eqp-pbar', 'Éteinte');
  await f.dire('« Préparer la semaine » a disparu : éteint, le module ne se montre plus.', 3800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
