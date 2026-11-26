/* Trésorerie › l'onglet « Mode d'emploi » (?vue=tresorerie), en lecture seule (24/11/2026) :
 *   onglet    treso-mode-emploi   la question du plan, la routine du dimanche en quatre étapes, ce qui est repris tout
 *                                 seul, le sens de chaque couleur, « À savoir ».
 * Pas de chapitre de fonction : l'onglet ne fait que se lire. Chaque phrase vient de panel/public/dashboard/tresorerie.js
 * (rendGuide). */
const { Film } = require('../film');
const { fiabiliser, T, onglet, placeSousLaPage } = require('./tresorerie');

const G = T + '.tr-guide ';

module.exports = async function () {
  const f = await Film.ouvrir('09-06-mode-emploi', {
    vue: 'tresorerie', serie: 'Trésorerie', parent: 'tresorerie', chargement: 3500,
    sources: ['panel/public/dashboard/tresorerie.js'],
  });
  fiabiliser(f);

  await f.onglet({
    id: 'treso-mode-emploi', titre: 'Le mode d’emploi',
    resume: 'La routine du dimanche en quatre étapes, ce qui est repris tout seul, le sens de chaque couleur et les points « À savoir ».',
    accroche: 'Le plan, pas à pas',
    programme: ['La routine du dimanche soir', 'Ce qui est repris tout seul', 'Les couleurs', 'À savoir'],
  });
  await f.montrer(T + '.tr-ong button:last-child', 'Mode d’emploi');
  await f.dire('Le dernier onglet de la Trésorerie : le <b>mode d’emploi</b> du plan.', 3600);
  await f.cacher(150); await f.taire(150);
  await onglet(f, 'guide');
  await placeSousLaPage(f);   // « À savoir », en bas de page, remonte au-dessus du sous-titre
  await f.montrerTout(G + '> h3, ' + G + '> p', 'Une seule question');
  await f.dire('Le plan répond à une seule question : peut-on <b>payer tout le monde</b> dans les semaines qui viennent ?', 5400);
  await f.montrerTout(G + '.tr-etape', 'La routine du dimanche soir');
  await f.dire('La routine du dimanche soir, en quatre étapes : le <b>solde réel</b>, l’<b>écart</b>, les quatre semaines à venir, la <b>décision</b>.', 6400);
  await f.dire('Plus de 500 € d’écart : on en trouve la cause avant lundi. Orange ou rouge : on décide quoi décaler, et on prévient avant l’échéance.', 7000);
  await f.montrer(G + '.tr-def:not(.tr-def-c)', 'Repris tout seul');
  await f.dire('Repris tout seul : les <b>ventes réelles</b> des semaines closes, le <b>budget validé</b>, l’<b>année passée</b>.', 5600);
  await f.montrer(G + '.tr-def-c', 'Les couleurs');
  await f.dire('Le sens de chaque couleur : un chiffre réel que vous seul connaissez, une prévision, une estimation ≈, le réel de la caisse…', 6400);
  await f.dire('… un calcul, un montant saisi pour une seule semaine, puis sous le coussin, et à découvert.', 5000);
  await f.montrer(G + '.tr-savoir', 'À savoir');
  await f.dire('À savoir : tout est en € TTC ; TVA et ONSS s’encodent avec le comptable ; le consultant voit le même plan.', 6400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
