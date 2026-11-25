/* Mon équipe › l'assistant de la semaine, dernière étape « Vérifier et créer » (?vue=equipe), et sa fonction en
 * chapitre (24/11/2026) :
 *   onglet    equipe-assistant-verifier   le budget, les heures, les durées ; jour par jour le comptoir et les secteurs ;
 *                                         les absents et ceux à revoir ; « Créer » ; fermer l'assistant (confirmé)
 *   fonction  equipe-assistant            la semaine vide, de « Lancer l'assistant » à « Créer » ; la personne « à
 *                                         revoir » s'ouvre ensuite dans la fenêtre d'un service
 * Avant de filmer : l'option « Assistant de la semaine » allumée (sans les tâches : « Vérifier et créer » est la
 * 4e étape), la semaine du 30 novembre (vide) montrée, l'assistant ouvert sur la semaine passée, Hugo absent, Fanny à
 * revoir. Données fictives : mocks/20_equipe.js. Chaque phrase vient de panel/public/dashboard/equipe.js ou de
 * panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-11-assistant-verifier', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.options(p, { assistant: true }); await E.recharger(p); await E.planning(p, { pas: 2 }); await E.assistant(p, { suites: 3, etats: { 207: 'a', 206: 'r' } }); } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-assistant-verifier', titre: 'L’assistant : vérifier et créer',
    resume: 'Le dernier contrôle (budget, heures, durées, comptoir et secteurs jour par jour) avant « Créer » au panel.',
    accroche: 'Un dernier regard, puis le panel',
    programme: ['Préparer la semaine avec l’assistant'],
  });
  await f.montrer('.eqp-as-verif', 'Vérifier et créer');
  await f.dire('La dernière étape : la semaine <b>telle qu’elle sera au panel</b>.', 3400);
  await f.dire('Le budget de la semaine, les heures, et les durées hors de 4 à 8 heures.', 3800);
  await f.montrer('.eqp-as-tab', 'Jour par jour');
  await f.dire('Jour par jour : les personnes, les heures, le budget, le <b>comptoir</b> et les secteurs.', 4400);
  await f.montrer('.eqp-as-tab tbody tr:nth-child(2)', 'Mardi');
  await f.dire('Un trou reste marqué : mardi, le comptoir de 13 h 45 à 14 h 30…', 3600);
  await f.dire('… et la boulangerie, vide sans Hugo, absent. On le reprend ensuite dans la fenêtre d’un service.', 5000);
  await f.montrer('.eqp-as-main > .eqp-note', 'Absents · à revoir');
  await f.dire('Les absents ne sont pas créés ; la fenêtre de Fanny, « à revoir », s’ouvrira après.', 4400);
  await E.sousTitre(f, 'haut');
  await f.montrer('[data-eqp-as-creer]', 'Créer');
  await f.dire('Rien n’est écrit au panel <b>avant ce bouton</b>.', 3000);
  await f.cacher(150); await f.taire(150);
  await E.sousTitre(f, 'bas');
  await f.cliquerConfirme('.eqp-as-x', 'Fermer l’assistant ?', 'Ce qui est préparé est perdu ; rien n’a été écrit au panel.', { apres: 1000 });
  await f.dire('Fermé avant la fin : ce qui était préparé est perdu, le panel n’a pas changé.', 4000);
  await f.taire(150);

  /* Préparer la semaine avec l'assistant */
  await f.fonction({ id: 'equipe-assistant', titre: 'Préparer la semaine avec l’assistant',
    resume: 'Une semaine vide : lancer l’assistant, choisir la base, marquer les absents et ceux à revoir, garder les propositions, créer au panel.',
    points: ['« Lancer l’assistant de la semaine »', 'Choisir la base', 'Absents, « à revoir »', 'Les propositions, puis « Créer »'] });
  await f.defiler(-3000);
  await f.cliquer('.eqp-vide7 [data-eqp-assistant]', { apres: 1800 });
  await f.dire('La base : la <b>semaine passée</b>, reportée de sept jours.', 0);
  await f.cliquer('[data-eqp-as-suite]', { apres: 1400 });
  await f.taire(150);
  await f.cliquer('[data-eqp-as-etat="r"][data-emp="203"]', { apres: 800 });
  await f.montrer('.eqp-as-p:has([data-emp="203"])', 'À revoir');
  await f.dire('Chloé, <b>à revoir</b> : on placera ses jours à la main.', 2900);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-suite]', { apres: 1000 });
  await f.montrer('.eqp-as-cols .eqp-as-bilan', 'Les propositions');
  await f.dire('Le chiffre : les propositions cochées rapprochent la semaine du <b>budget</b>.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-suite]', { apres: 1000 });
  await E.sousTitre(f, 'haut');
  await f.montrer('[data-eqp-as-creer]', 'Créer');
  await f.dire('On vérifie, puis « <b>Créer</b> ».', 2400);
  await f.cacher(150); await f.taire(150);
  await E.sousTitre(f, 'bas');
  await f.cliquer('[data-eqp-as-creer]', { apres: 2400 });
  await f.montrer('.eqp-fo-tete', 'Chloé');
  await f.dire('La semaine est au panel. La fenêtre de Chloé s’ouvre : on place ses jours.', 4000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
