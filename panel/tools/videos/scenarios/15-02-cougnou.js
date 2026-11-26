/* Concurrence, l'onglet « Cougnou » (?vue=concurrence), 26/11/2026 :
 *   onglet    concurrence-cougnou           la famille de saison, comparée au kilo : notre cougnou en €/kg, les
 *                                           médianes par type de concurrent, l'échelle, les relevés ramenés au kilo,
 *                                           la visibilité web et la carte « À faire »
 *   fonction  concurrence-relever-cougnou   « + Relevé terrain » depuis Cougnou : le concurrent, le cougnou vu (son nom
 *                                           porte « cougnou »), son prix, son poids en g, « Poser le relevé »
 * La fonction « Relever un cougnou ou une galette » de la liste se filme en deux chapitres : ici le cougnou (au kilo),
 * dans 15-03 la galette (à notre Ø). Données fictives : mocks/15_concurrence.js (POST /benchmarks/releve s'ajoute aux
 * relevés). Chaque phrase vient de panel/public/dashboard/dashboard.js (rendConcurrence, bmSujet, bmValeur,
 * bmFormulaire, bmBrancher) ou de consultant/src/benchmark.php (ep_benchmark_magasin, wr_benchmark_releve). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('15-02-cougnou', {
    vue: 'concurrence', serie: 'Concurrence', parent: 'concurrence',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/benchmark.php'],
  });

  await f.onglet({
    id: 'concurrence-cougnou', titre: 'Le cougnou',
    resume: 'La famille de saison, comparée au kilo : notre cougnou en €/kg face aux relevés, l’échelle par type de concurrent, la visibilité web et la carte « À faire ».',
    accroche: 'La saison, au kilo près',
    programme: ['Notre cougnou au kilo', 'L’échelle et les relevés', 'Relever le prix d’un cougnou'],
  });
  await f.cliquer('[data-bmf="cougnou"]', { apres: 1300 });
  await f.montrer('.db-tuiles .db-tui:nth-child(1)', 'Notre cougnou');
  await f.dire('Le cougnou se compare <b>au kilo</b> : notre cougnou de 500 g à 8,90 €, soit 17,80 € le kilo.', 5200);
  await f.montrerTout('.db-tuiles .db-tui:nth-child(n+2):nth-child(-n+4)', 'Les médianes');
  await f.dire('Face à la médiane des supermarchés, des artisans, des chaînes et stations ; et notre écart avec chacune.', 5200);
  await f.montrer('.db-tuiles .db-tui:nth-child(5)', 'Moins chers');
  await f.dire('Combien de relevés sont <b>moins chers que nous</b>, parmi ceux dont la taille est connue.', 4400);
  await f.cacher(300); await f.taire(150);

  await f.montrer(f.p.locator('.db-card', { hasText: 'Où nous situons-nous' }).first(), 'L’échelle');
  await f.dire('L’échelle, en <b>€/kg</b> : chaque relevé est un point, rangé par type ; le trait Ruby, c’est nous.', 5000);
  await f.montrer(f.p.locator('.db-card', { hasText: '— les concurrents' }).first(), 'Les relevés');
  await f.dire('Chaque relevé : le concurrent, son code postal, le poids, le prix, ramené au kilo, et l’écart.', 5000);
  await f.dire('Ceux-ci viennent du relevé web du cockpit, pas encore de TFBuddy.', 3600);
  await f.cacher(300); await f.taire(150);
  await f.montrer('.bm-bas .db-card:first-child', 'Visibilité web');
  await f.dire('La <b>visibilité web</b> : ce que la page de la gamme montre sur atelierby.be, contrôle par contrôle.', 5000);
  await f.montrer('.bm-bas .db-card:nth-child(2)', 'À faire');
  await f.dire('<b>À faire</b>, tiré du benchmark : deux cougnous à relever avant le 1er décembre.', 4400);
  await f.cacher(300); await f.taire(150);
  await f.defiler(-3000);

  /* Relever un cougnou */
  await f.fonction({ id: 'concurrence-relever-cougnou', titre: 'Relever le prix d’un cougnou',
    resume: '« + Relevé terrain » depuis Cougnou : le concurrent, le cougnou vu, son prix et son poids en grammes ; le relevé est ramené au kilo et entre dans les médianes.',
    points: ['Cougnou › « + Relevé terrain »', 'Le concurrent', 'Le cougnou vu, son prix, son poids', '« Poser le relevé »'] });
  await f.cliquer('[data-bmf="cougnou"]', { apres: 900 });
  await f.cliquer('.db-sec [data-bmform]', { apres: 1000 });
  await f.montrer('.bm-form', 'Relevé terrain');
  await f.dire('Depuis le cougnou, le formulaire s’ouvre en <b>grammes</b> : on relève le poids.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-bmc=cid]', { pause: 400 });
  await f.p.locator('[data-bmc=cid]').selectOption('307');
  await f.pause(600);
  await f.cliquer('[data-bmc=nom]', { apres: 200 });
  await f.taper('Cougnou sucre', 70);
  await f.cliquer('[data-bmc=prix]', { apres: 200 });
  await f.taper('7,90', 110);
  await f.cliquer('[data-bmc=taille]', { apres: 200 });
  await f.taper('450', 110);
  await f.pause(200);
  await f.montrer('.bm-form .bm-grille', 'Le cougnou vu');
  await f.dire('La Mie Dorée : un cougnou sucre de 450 g à 7,90 €. Le mot <b>cougnou</b> le range dans la famille.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmenv]', { apres: 1600 });
  await f.montrer('.bm-msg', 'Posé');
  await f.dire('<b>Poser le relevé</b> : il entre dans le module Benchmark de TFBuddy, et la page se relit.', 4400);
  await f.cacher(300); await f.taire(150);
  await f.montrer(f.p.locator('.bm-tab tr', { hasText: 'La Mie Dorée' }).first(), 'Le nouveau relevé');
  await f.dire('Ramené au kilo : 7,90 € pour 450 g, soit 17,56 € le kilo. Il compte dans les médianes.', 5000);
  await f.cacher(300); await f.taire(150);
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
