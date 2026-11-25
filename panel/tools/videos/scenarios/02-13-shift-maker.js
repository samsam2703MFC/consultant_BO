/* Mon équipe › « Shift maker » (test ; ?vue=equipe, option « Matrice A B C D et shift maker »), et sa fonction en
 * chapitre (24/11/2026) :
 *   onglet    equipe-shift-maker   la semaine tirée de la matrice et de la lettre de chacun : la semaine du
 *                                         roulement, les créneaux, les personnes sans lettre, les propositions, le
 *                                         budget avant → après, les écartés
 *   fonction  equipe-shift-maker-creer       « Créer N services » : le comptoir de la semaine, matin et après-midi
 * Avant de filmer : l'option Matrice allumée ; une lettre pour les quatre personnes du comptoir (Camille B, Emma C,
 * Fanny D, Lucie A), les autres sans lettre ; deux services déjà posés le lundi 30 novembre (Camille, comme sa
 * lettre ; Fanny, qui chevauche) ; la semaine du 30 montrée. Données fictives : mocks/20_equipe.js. Chaque phrase
 * vient de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-13-shift-maker', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => {
      await E.options(p, { matrice: true });
      for (const emp of [202, 203, 204, 207, 208, 209]) { await E.envoyer(p, '/dashboard/equipe/roulement', { employe: emp, lettre: null }); }
      await E.envoyer(p, '/dashboard/equipe/service', { employe: 201, date: '2026-11-30', de: '12:30', a: '19:15' });
      await E.envoyer(p, '/dashboard/equipe/service', { employe: 206, date: '2026-11-30', de: '07:00', a: '13:00' });
      await E.recharger(p); await E.planning(p, { pas: 2 });
    } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-shift-maker', titre: 'Le shift maker',
    resume: 'La semaine tirée de la matrice et de la lettre de chacun : les propositions à cocher, les écartés, les personnes sans lettre.',
    accroche: 'La semaine tirée du roulement',
    programme: ['Générer la semaine avec le shift maker'],
  });
  await f.montrer('.eqp-menu', 'Préparer la semaine');
  await f.dire('Avec l’option Matrice : « Préparer la semaine » › <b>Shift maker</b>, encore en test.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-menu summary', { apres: 700 });
  await f.cliquer('[data-eqp-generer]', { apres: 1500 });
  await f.montrer('.eqp-rec .qui', 'La semaine 1');
  await f.dire('La semaine du 30 est la <b>semaine 1</b> du roulement : le matin et l’après-midi, à leurs heures.', 4800);
  await f.montrer('.eqp-rec-sans', 'Sans lettre');
  await f.dire('Sans lettre, pas générés : leur lettre se donne dans leur fiche, ou ils se planifient à la main.', 4800);
  await f.montrer('.eqp-rec-liste .eqp-rec-l', 'Une proposition');
  await f.dire('Chaque service : la personne, sa <b>lettre</b>, matin ou après-midi, ses heures.', 4000);
  await f.montrer('.eqp-rec-b', 'Le budget');
  await f.dire('Le budget de la semaine, avant et après : il se lit avant de créer.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-rec-d summary', { apres: 900 });
  await f.montrer('.eqp-rec-d', 'Les écartés');
  await f.dire('Les <b>écartés</b> : déjà au planning, ou qui chevauchent un service prévu.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-rec .confirm__actions [data-eqp-fermer]', { apres: 800 });

  /* Générer la semaine avec le shift maker */
  await f.fonction({ id: 'equipe-shift-maker-creer', titre: 'Générer la semaine avec le shift maker',
    resume: 'Lire l’aperçu tiré de la matrice, garder les services cochés, puis les créer au panel.',
    points: ['« Préparer la semaine › Shift maker »', 'Lire l’aperçu', 'Cocher', '« Créer N services »'] });
  await f.cliquer('.eqp-menu summary', { apres: 700 });
  await f.cliquer('[data-eqp-generer]', { apres: 1500 });
  await f.montrer('.eqp-rec-r', 'Les services cochés');
  await f.dire('Tout est coché : les services, leurs heures, leur coût. « Tout décocher » repart de zéro.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-rec-ok]', { apres: 2000 });
  await f.montrer('.eqp-flash', 'Créé au panel');
  await f.dire('Les services sont créés au panel.', 2600);
  await f.montrerTout('.eqp-tab tr.eqp-lin', 'Les linéaires');
  await f.dire('Le comptoir est tenu matin et après-midi ; la production se place ensuite, à la main.', 4400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
