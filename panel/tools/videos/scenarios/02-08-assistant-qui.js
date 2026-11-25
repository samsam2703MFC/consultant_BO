/* Mon équipe › l'assistant de la semaine, étape 2 « Qui adapter ? » (?vue=equipe), sans fonction qui écrit ici
 * (l'assistant n'écrit qu'à « Créer » : 02-11-assistant-verifier) :
 *   onglet    equipe-assistant-qui   chaque personne de la base : comme proposé, absent·e, à revoir
 * Avant de filmer : l'option « Assistant de la semaine » allumée, la semaine du 30 novembre (vide) montrée, l'assistant
 * ouvert sur la semaine passée, « Continuer › ». Données fictives : mocks/20_equipe.js. Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-08-assistant-qui', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.options(p, { assistant: true }); await E.recharger(p); await E.planning(p, { pas: 2 }); await E.assistant(p, { suites: 1 }); } });

  await f.onglet({
    id: 'equipe-assistant-qui', titre: 'L’assistant : qui adapter ?',
    resume: 'Chaque personne de la base marquée « Comme proposé », « Absent·e » ou « À revoir ».',
    accroche: 'Les absents, ceux à revoir',
    programme: ['Comme proposé', 'Absent·e : rien n’est créé', 'À revoir : sa fenêtre s’ouvre après'],
  });
  await f.montrer('.eqp-as-rail', 'Étape 2');
  await f.dire('Deuxième étape : « <b>Qui adapter ?</b> »', 2800);
  await f.montrer('.eqp-as-pers', 'Personne par personne');
  await f.dire('La semaine proposée, personne par personne : ses heures, jour par jour.', 3800);
  await f.montrer('.eqp-as-pers .eqp-as-p:nth-child(3) .eqp-as-sem', 'Camille');
  await f.dire('Chaque jour : ses heures, <b>beige</b> le matin, <b>rose</b> l’après-midi.', 3800);
  await f.montrer('.eqp-as-pers .eqp-as-p:nth-child(3) .eqp-as-seg', 'Trois choix');
  await f.dire('Trois choix : « Comme proposé », « Absent·e », « À revoir ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-etat="a"][data-emp="207"]', { apres: 900 });
  await f.montrer('.eqp-as-p:has([data-emp="207"])', 'Absent');
  await f.dire('Hugo est en congé : <b>absent</b>, rien n’est créé pour lui.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-etat="r"][data-emp="206"]', { apres: 900 });
  await f.montrer('.eqp-as-p:has([data-emp="206"])', 'À revoir');
  await f.dire('Fanny, « <b>à revoir</b> » : pas créée non plus, sa fenêtre s’ouvre après la création, pour placer ses jours à la main.', 5800);
  await E.sousTitre(f, 'haut');
  await f.montrer('.eqp-as-pied .res', 'Le compte');
  await f.dire('En bas, le compte : les absents, ceux à revoir, les services et les heures à créer.', 4200);
  await f.montrer('[data-eqp-as-suite]', 'Continuer');
  await f.dire('« Continuer › » passe au chiffre.', 2600);
  await f.cacher(300);
  await E.sousTitre(f, 'bas');

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
