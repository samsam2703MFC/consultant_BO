/* Mon coffre-fort › « Déposer », étape 2 : l'assistant (après « Déposer » dans la fenêtre du dépôt), et sa fonction en
 * chapitre (26/11/2026) :
 *   onglet    coffre-assistant      l'assistant lit le document : ce que c'est, son résumé, la phrase qui fonde les
 *                                   dates, les grandes dates proposées à cocher ; à gauche, la pièce reconnue, le titre et
 *                                   l'échéance remplis
 *   fonction  coffre-ranger-dates   décocher, corriger une date, puis « Ranger le document et N grandes dates »
 * Avant de filmer, la fenêtre est ouverte (« Déposer un document », sans pièce choisie) et le bail choisi, daté. Données
 * fictives : mocks/23_coffre.js (la lecture du bail, ses dates calculées comme cofBailDates). Chaque phrase vient de
 * panel/public/dashboard/coffre.js (lancerAssistant, fenetre, rangerDepot) ou de consultant/src/coffre_dates.php. */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-08-assistant', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES,
    avant: async p => {
      await C.marge(p); await C.lenteur(p, 3200);
      await p.click('.cf2-barre [data-cf-depot]'); await p.waitForTimeout(600);
      await p.locator('[data-cf-fichier]').setInputFiles(C.pdf('bail-commercial-boutique.pdf', 1235)); await p.waitForTimeout(500);
      await p.locator('[data-cf-dp="date"]').fill('2024-05-15'); await p.locator('[data-cf-dp="date"]').dispatchEvent('change');
      await p.waitForTimeout(400);
    } });
  const prop = t => f.p.locator('.cf2-mod label.cf2-pp', { hasText: t }).first();

  /* L'onglet */
  await f.onglet({
    id: 'coffre-assistant', titre: 'Déposer, étape 2 : l’assistant',
    resume: 'Après « Déposer », l’assistant lit le document : ce que c’est, la phrase qui fonde les dates, les grandes dates proposées à cocher ; il reconnaît la pièce, remplit le titre et l’échéance.',
    accroche: 'Il lit, vous décidez', programme: ['Ranger les grandes dates proposées'],
  });
  await C.cadreFixe(f, '.cf2-mod', 'Déposer un document');
  await f.dire('Le bail de la boutique, choisi sans dire quelle pièce c’est : « <b>Déposer</b> ».', 4000);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod [data-cf-envoi]', 500);
  await C.cadreFixe(f, '.cf2-mod .d', 'L’assistant lit le document');
  await f.dire('Le document est rangé ; l’<b>assistant</b> le lit. Quelques secondes, parfois une minute.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.p.waitForSelector('.cf2-mod .cf2-res', { timeout: 10000 });
  await f.pause(500);
  await C.cadreFixe(f, '.cf2-mod .cf2-ash', 'Ce que c’est');
  await f.dire('Il dit ce que c’est — un <b>bail commercial</b> —…', 3000);
  await C.cadreFixe(f, '.cf2-mod .cf2-res', 'Le résumé');
  await f.dire('… le résume en deux phrases…', 2600);
  await C.cadreFixe(f, '.cf2-mod .cf2-ex', 'La phrase');
  await f.dire('… et cite la <b>phrase</b> d’où viennent les dates.', 3200);
  await f.montrerTout('.cf2-mod .d label.cf2-pp:nth-of-type(-n+3)', 'Les grandes dates proposées');
  await f.dire('Les <b>grandes dates</b> proposées, cochées : calculées depuis le bail, ou lues dans le document, à telle page.', 5600);
  await f.montrer('.cf2-mod .d .cf2-pas', 'Déjà passées');
  await f.dire('Celles déjà passées restent <b>décochées</b>, repliées.', 3200);
  await C.cadreFixe(f, '.cf2-mod .cf2-sug', 'La pièce reconnue');
  await f.dire('À gauche, il a reconnu la pièce : <b>Bail commercial</b>, choisie pour vous.', 4000);
  await f.montrerTout('.cf2-mod [data-cf-dp="titre"], .cf2-mod .g .cf2-2', 'Le titre, l’échéance');
  await f.dire('Il a rempli un titre lisible et l’<b>échéance</b> : vous pouvez les changer.', 4000);
  await C.cadreFixe(f, '.cf2-mod .f', 'Ranger');
  await f.dire('Les dates du bail suivent la règle générale : à vérifier dans votre bail. Puis « <b>Ranger</b> ».', 5000);
  await f.cacher(300); await f.taire(200);

  /* Ranger les grandes dates proposées */
  await f.fonction({ id: 'coffre-ranger-dates', titre: 'Ranger les grandes dates proposées',
    resume: 'Décocher ce qu’on ne garde pas, corriger une date au besoin, puis « Ranger le document et N grandes dates » : elles rejoignent les grandes dates du coffre.',
    points: ['Lire ce que l’assistant propose', 'Décocher ce qu’on ne garde pas', 'Corriger une date', '« Ranger le document et N grandes dates »'] });
  await C.cadreFixe(f, '.cf2-mod .d', 'Les grandes dates proposées');
  await f.dire('L’assistant a lu le bail : sept grandes dates cochées.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(prop('Indexation du loyer').locator('input[type=checkbox]'), { apres: 700 });
  await f.montrer(prop('Indexation du loyer'), 'Décochée');
  await f.dire('L’indexation du loyer ? On ne la garde pas : on la <b>décoche</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await C.taperDate(f, '.cf2-mod label.cf2-pp:has-text("congé — 1er triennat") [data-cf-as-le]', '2026-11-27');
  await f.montrer(prop('congé — 1er triennat'), 'Corrigée');
  await f.dire('Une date se <b>corrige</b> : le dernier jour du congé, avancé au 27 novembre pour poster le recommandé à temps.', 5600);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod [data-cf-range]', 1500);
  await f.montrer('.cf2-flash', 'Rangé');
  await f.dire('<b>Rangé</b>, avec ses grandes dates.', 3000);
  await f.cacher(150); await C.apresFlash(f);
  await f.montrer('.cf2-rs-pr', 'Prochaine grande date');
  await f.dire('La prochaine grande date : le <b>dernier jour pour donner congé</b>, le 27 novembre, dans 8 jours.', 5000);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
