/* Mon équipe › la fiche d'une personne › l'onglet « Évaluations » (?vue=equipe, 24/11/2026), et sa fonction :
 *   onglet    equipe-evaluations   une note de 1 à 5 par service du planning, avec un mot : la moyenne, la répartition,
 *                                  les services des 14 derniers jours à noter, l'historique
 *   fonction  equipe-noter         Camille, mardi 17 novembre (14 h 30 – 19 h 15) : 4 étoiles et un mot
 * Données fictives : mocks/20_equipe.js (les notes de Fanny Leroy, la responsable, depuis le 2 novembre ; les services
 * du 17 et du 18 restent à noter). Chaque phrase vient de panel/public/dashboard/equipe.js (fpEvaluations, fpNoter) ou
 * de l'en-tête de panel/src/equipe_fiche.php. */
const { Film } = require('../film');

const SOURCES = ['panel/public/dashboard/equipe.js', 'panel/public/dashboard/equipe.css', 'panel/src/equipe_fiche.php'];

/** Filmé : la personne dans Responsabilités, « Fiche complète », l'onglet Évaluations. */
async function ouvrir(f, emp) {
  await f.cliquer(`[data-eqp-sel="${emp}"]`, { apres: 700 });
  await f.cliquer('.eqp-fiche [data-eqp-fiche]', { apres: 1400 });
  await f.cliquer('[data-eqp-fp-onglet=evaluations]', { apres: 1400 });
}

module.exports = async function () {
  const f = await Film.ouvrir('02-17-evaluations', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: SOURCES });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-evaluations', titre: 'Les évaluations d’une personne',
    resume: 'Dans sa fiche : une note de 1 à 5 par service du planning, avec un mot ; sa moyenne, la répartition des notes, les services à noter et l’historique.',
    accroche: 'Une note par service',
    programme: ['Noter un service'],
  });
  await ouvrir(f, 201);
  await f.montrer('[data-eqp-fp-onglet=evaluations]', 'Évaluations');
  await f.dire('Dans sa fiche, « <b>Évaluations</b> » : l’onglet dit combien de services sont à noter.', 4200);
  await f.montrer('.eqp-fp-ev > div:first-child > .eqp-fp-moy', 'Sa moyenne');
  await f.dire('Une note par service du planning, de 1 à 5, avec un mot. En grand : sa <b>moyenne</b>.', 4600);
  await f.montrer('.eqp-fp-ev > div:first-child > div.eqp-note', 'Notés · à noter');
  await f.dire('Combien de ses services sont notés, et combien restent à noter sur les 14 derniers jours.', 4400);
  await f.montrer('.eqp-fp-histo', 'La répartition');
  await f.dire('La <b>répartition</b> : combien de services à 5 étoiles, à 4, à 3…', 3800);
  await f.montrer('.eqp-fp-ev > div:nth-child(2) > .eqp-fp-liste:first-of-type', 'À noter');
  await f.dire('« <b>À noter</b> » : ses services passés des 14 derniers jours, pas encore notés.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.eqp-fp-ev > div:nth-child(2) > .eqp-fp-liste:last-of-type', 'Historique');
  await f.dire('L’<b>historique</b> : le jour, les heures, la note, le mot, et qui a noté.', 4200);
  await f.montrer(f.p.locator('.eqp-fp-liste .eqp-fp-li', { hasText: 'Mer 4 novembre' }), 'Sans mot');
  await f.dire('Le mot est facultatif : sans mot, un tiret.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000, '.eqp-fp-voile');
  await f.cliquer('.eqp-fp-tete [data-eqp-fp-fermer]', { apres: 900 });

  /* Noter un service */
  await f.fonction({ id: 'equipe-noter', titre: 'Noter un service',
    resume: 'Dans « Évaluations », un service à noter : de une à cinq étoiles, un mot facultatif, puis « Enregistrer la note » ; il passe dans l’historique.',
    points: ['Sa fiche, « Évaluations »', '« Noter ce service »', 'Les étoiles, un mot', '« Enregistrer la note »'] });
  await ouvrir(f, 201);
  const ligne = '[data-eqp-fp-anoter][data-date="2026-11-17"]';
  await f.montrer(f.p.locator('.eqp-fp-li', { has: f.p.locator(ligne) }), 'Mardi 17 novembre');
  await f.dire('Mardi 17, de 14 h 30 à 19 h 15 : Camille a tenu le comptoir jusqu’à la fermeture.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(ligne, { apres: 900 });
  await f.cliquer('[data-eqp-fp-etoile="4"]', { apres: 700 });
  await f.cliquer('[data-eqp-fp-mot]', { apres: 250 });
  await f.taper('Comptoir bien tenu ; réassort des tartes un peu tard.', 55);
  await f.montrer('.eqp-fp-li.ouvert', 'La note');
  await f.dire('Les <b>étoiles</b>, puis un mot, facultatif : ce qui était bien, ce qui est à reprendre.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-noter]', { apres: 1500 });
  await f.montrer('.eqp-fp-corps .eqp-flash', 'Note enregistrée', 1400);
  await f.montrer('.eqp-fp-ev > div:nth-child(2) > .eqp-fp-liste:last-of-type .eqp-fp-li:first-child', 'Dans l’historique');
  await f.dire('Le service passe dans l’<b>historique</b> ; la moyenne et la répartition suivent.', 4000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
