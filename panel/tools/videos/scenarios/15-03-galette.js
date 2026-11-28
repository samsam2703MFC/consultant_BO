/* Concurrence, l'onglet « Galette des rois » (?vue=concurrence), 26/11/2026 :
 *   onglet    concurrence-galette           les galettes des concurrents ramenées à notre Ø 22 cm, à surface égale
 *                                           (prix × (Ø 22 / Ø)²), face à notre prix : les tuiles, l'échelle, les
 *                                           relevés, la visibilité web et « À faire »
 *   fonction  concurrence-relever-galette   « + Relevé terrain » depuis Galette des rois : unité cm (Ø), le concurrent,
 *                                           la galette vue (son nom porte « galette »), son prix et son Ø
 * Données fictives : mocks/15_concurrence.js. Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (rendConcurrence, bmSujet, bmValeur, bmFormulaire, bmBrancher) ou de consultant/src/benchmark.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('15-03-galette', {
    vue: 'concurrence', serie: 'Concurrence', parent: 'concurrence',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/benchmark.php'],
  });

  await f.onglet({
    id: 'concurrence-galette', titre: 'La galette des rois',
    resume: 'Les galettes des concurrents ramenées à notre Ø 22 cm, à surface égale, face à notre prix : les médianes, l’échelle, chaque relevé et son écart.',
    accroche: 'À taille égale, qui est le moins cher',
    programme: ['À notre diamètre', 'L’échelle et les relevés', 'Relever le prix d’une galette'],
  });
  await f.cliquer('[data-bmf="galette"]', { apres: 1300 });
  await f.montrer('#dash .ac-cle', 'Notre galette');
  await f.dire('Notre galette : <b>Ø 22 cm</b>, 19,90 €. Celles des concurrents sont ramenées à notre diamètre.', 5200);
  await f.montrer(f.p.locator('.db-card', { hasText: 'Où nous situons-nous' }).first(), 'À surface égale');
  await f.dire('À surface égale : le prix × (Ø 22 ÷ son Ø)². Une galette de 24 cm à 21 € vaut 17,65 € à Ø 22.', 6200);
  await f.dire('L’échelle : chaque relevé est un point, rangé par type de concurrent ; le trait Ruby, c’est nous.', 4800);
  await f.cacher(300); await f.taire(150);
  await f.montrer('#dash .ac-cle .q small', 'Les médianes');
  await f.dire('Les médianes des supermarchés, des artisans, des chaînes, et combien sont <b>moins chères</b> que nous.', 5200);
  await f.montrer(f.p.locator('.db-card', { hasText: '— les concurrents' }).first(), 'Les relevés');
  await f.dire('Chaque relevé : son Ø, son prix, ramené à notre Ø, et l’écart avec nous.', 4200);
  await f.cacher(300); await f.taire(150);
  await f.montrer('.bm-bas .db-card:first-child', 'Visibilité web');
  await f.dire('La <b>visibilité web</b> de la galette sur atelierby.be : chaque contrôle, fait ou manque.', 4600);
  await f.montrer('.bm-bas .db-card:nth-child(2)', 'À faire');
  await f.dire('<b>À faire</b>, tiré du benchmark : relever les galettes dès le début de la saison.', 4400);
  await f.cacher(300); await f.taire(150);
  await f.defiler(-3000);

  /* Relever une galette */
  await f.fonction({ id: 'concurrence-relever-galette', titre: 'Relever le prix d’une galette',
    resume: '« + Relevé terrain » depuis Galette des rois : le concurrent, la galette vue, son prix et son diamètre en cm ; le relevé est ramené à notre Ø 22 cm.',
    points: ['Galette des rois › « + Relevé terrain »', 'Le concurrent', 'La galette vue, son prix, son Ø', '« Poser le relevé »'] });
  await f.cliquer('[data-bmf="galette"]', { apres: 900 });
  await f.cliquer('.db-sec [data-bmform]', { apres: 1000 });
  await f.montrer('.bm-form', 'Relevé terrain');
  await f.dire('Depuis la galette, le formulaire s’ouvre en <b>cm (Ø)</b> : on relève le diamètre.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-bmc=cid]', { pause: 400 });
  await f.p.locator('[data-bmc=cid]').selectOption('306');
  await f.pause(700);
  await f.cliquer('[data-bmc=nom]', { apres: 200 });
  await f.taper('Galette frangipane', 60);
  await f.cliquer('[data-bmc=prix]', { apres: 200 });
  await f.taper('18,50', 110);
  await f.cliquer('[data-bmc=taille]', { apres: 200 });
  await f.taper('20', 110);
  await f.pause(300);
  await f.montrer('.bm-form .bm-grille', 'La galette vue');
  await f.dire('Au Fournil de Léon : une galette frangipane de <b>20 cm</b>, à 18,50 €. Son nom porte « galette ».', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmenv]', { apres: 1600 });
  await f.montrer('.bm-msg', 'Posé');
  await f.dire('<b>Poser le relevé</b> : il est dans TFBuddy, et la page se relit.', 3600);
  await f.cacher(300); await f.taire(150);
  await f.montrer(f.p.locator('.bm-tab tr', { hasText: 'Au Fournil de Léon' }).first(), 'Ramenée à Ø 22');
  await f.dire('Ramenée à Ø 22 cm, elle vaudrait 22,39 € : ici, nous sommes moins chers.', 4600);
  await f.cacher(300); await f.taire(150);
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
