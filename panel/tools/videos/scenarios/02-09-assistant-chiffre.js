/* Mon équipe › l'assistant de la semaine, étape 3 « Le chiffre » (?vue=equipe), sans fonction qui écrit ici
 * (« Revenir au budget avec les propositions » n'écrit qu'à « Créer » : un geste de lecture, montré dans l'onglet) :
 *   onglet    equipe-assistant-chiffre   le CA planifié, le budget à la cible, les heures de trop, les propositions
 *                                        chiffrées à cocher, le total qui se recalcule, le jour par jour
 * Avant de filmer : l'option « Assistant de la semaine » allumée, la semaine du 30 novembre (vide ; mocks/20_equipe.js :
 * un début de décembre plus calme) montrée, l'assistant ouvert sur la semaine passée, deux fois « Continuer › ». Chaque
 * phrase vient de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-09-assistant-chiffre', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.options(p, { assistant: true }); await E.recharger(p); await E.planning(p, { pas: 2 }); await E.assistant(p, { suites: 2 }); } });

  await f.onglet({
    id: 'equipe-assistant-chiffre', titre: 'L’assistant : le chiffre',
    resume: 'Le CA planifié, le budget à la cible, les heures de trop et les propositions chiffrées à cocher : le total se recalcule.',
    accroche: 'Revenir au budget',
    programme: ['Le CA planifié et le budget', 'Les heures de trop', 'Les propositions, à cocher'],
  });
  await f.montrer('.eqp-as-msg', 'Le chiffre');
  await f.dire('Le <b>CA planifié</b> de la semaine, et ce que le personnel peut coûter à 17 %.', 4000);
  await f.dire('La semaine proposée en compte plus : ce sont les <b>heures de trop</b>.', 3400);
  await f.montrerTout('.eqp-as-prop', 'Les propositions');
  await f.dire('Des <b>propositions</b> chiffrées, à cocher : ici, deux services à retirer.', 3800);
  await f.dire('Chacune garde sa section tenue : quelqu’un d’autre est là sur ces heures.', 3600);
  await f.montrer('.eqp-as-cols .eqp-as-bilan', 'Le total');
  await f.dire('Le total des propositions cochées, et ce qu’il reste de trop.', 3200);
  await f.montrer('.eqp-as-jt', 'Jour par jour');
  await f.dire('Jour par jour : les heures, et le <b>trait vert</b> du budget du jour.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-prop="1"]', { apres: 900 });
  await f.montrer('.eqp-as-cols .eqp-as-bilan', 'Décochée');
  await f.dire('Une proposition décochée : le total et le pourcentage <b>se recalculent</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-prop="1"]', { apres: 900 });
  await E.sousTitre(f, 'haut');
  await f.montrer('.eqp-as-pied .res', 'Avant → après');
  await f.dire('En bas : les heures et le pourcentage, avant et après.', 3200);
  await f.dire('Les propositions ne touchent que ce que l’assistant crée, jamais ce qui est déjà au planning.', 4600);
  await f.cacher(300);
  await E.sousTitre(f, 'bas');

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
