/* Boîte mail › Radar (la bascule IA › Radar), et sa fonction en chapitre (24/11/2026) :
 *   onglet    boite-radar        les trois alertes, la frise des trois semaines à venir estimée aux prix du catalogue,
 *                                les demandes repérées, le thermomètre d'humeur ; « Répondre à … » ouvre l'e-mail
 *   fonction  boite-radar-lire   « Lire et mettre sur le radar » : l'IA lit, une seule fois, ce qui ne l'est pas encore
 * « Répondre à … » et « Ouvrir » ne font qu'ouvrir l'e-mail en Classique : ils se voient dans l'onglet. Données fictives :
 * mocks/70_boite.js (quatre e-mails des trois dernières semaines pas encore lus par l'IA ; POST …/lire les lit), les
 * prix de 12_promotions.js. Chaque phrase vient de panel/public/dashboard/boite.js (vueRadar, lireLots) ou de
 * panel/src/LISEZMOI.md. */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-11-radar', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_ia.php'], avant: B.ouverte() });

  /* L'onglet */
  await f.onglet({
    id: 'boite-radar', titre: 'Le Radar',
    resume: 'Les demandes lues dans les e-mails des trois dernières semaines : trois alertes, la frise des trois semaines à venir, les demandes repérées et l’humeur.',
    accroche: 'La demande qui arrive, avant qu’elle arrive', programme: ['Mettre les e-mails sur le radar'],
  });
  await f.cliquer('[data-bxmode="radar"]', { apres: 1500 });
  await f.montrer('.bx-ra-alertes', 'Trois alertes');
  await f.dire('Le <b>Radar</b> lit les e-mails des trois dernières semaines. D’abord, trois alertes.', 4400);
  await f.montrer('.bx-ra-al:nth-child(1)', 'Sans réponse');
  await f.dire('Les demandes <b>sans réponse</b> pour les trois semaines à venir, et ce qu’elles pèsent.', 4400);
  await f.montrer('.bx-ra-al:nth-child(2)', 'Le jour le plus chargé');
  await f.dire('Le <b>jour le plus chargé</b>, à prévoir à la production.', 3200);
  await f.montrer('.bx-ra-al:nth-child(3)', 'Mécontents');
  await f.dire('Les <b>clients mécontents</b> qui attendent une réponse.', 3000);
  await f.montrer('.bx-ra-frise', 'Les trois semaines à venir');
  await f.dire('La frise : chaque jour, ses demandes, répondues ou non, estimées <b>aux prix du catalogue</b>.', 4800);
  await f.defilerVers(f.p.locator('.bx-ra-tab'));
  await B.cadre(f, '.bx-ra-tab', 'Les demandes repérées');
  await f.dire('Le tableau : pour quand, qui, combien de personnes, le montant estimé, l’état.', 4200);
  await f.montrer('.bx-ra-humeur', 'L’humeur');
  await f.dire('Le <b>thermomètre d’humeur</b> : chaleureux, neutres, mécontents.', 3400);
  await f.cacher(150); await f.taire(150);
  await B.enHaut(f);
  await f.cliquer('.bx-ra-al:nth-child(1) [data-bxbro]', { apres: 1400 });
  await B.cadre(f, '.bx-lire', 'En Classique');
  await f.dire('« <b>Répondre à …</b> » ouvre l’e-mail en Classique. Le Radar, lui, n’agit jamais seul.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxmode="radar"]', { apres: 1200 });

  /* Lire et mettre sur le radar */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-radar-lire', titre: 'Mettre les e-mails sur le radar',
    resume: '« Lire et mettre sur le radar » : l’IA lit, une seule fois, les e-mails des trois dernières semaines qu’elle n’a pas encore lus.',
    points: ['Le bandeau : N e-mails pas encore lus', '« Lire et mettre sur le radar »', 'Les demandes s’ajoutent'] });
  await f.cliquer('[data-bxmode="radar"]', { apres: 900 });
  await f.montrer('.bx-ra-scan', 'Pas encore lus');
  await f.dire('Quatre e-mails des trois dernières semaines ne sont <b>pas encore lus</b> par l’IA.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bx="raLire"]', { apres: 1600 });
  await f.montrer('.bx-ra-al:nth-child(1)', 'Cinq sans réponse');
  await f.dire('Lus, une fois pour toutes : deux demandes de plus attendent une réponse.', 4000);
  await f.montrer('.bx-ra-frise', 'Sur la frise');
  await f.dire('Le 27/11 et le 5/12 apparaissent sur la frise, et dans le tableau.', 3600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
