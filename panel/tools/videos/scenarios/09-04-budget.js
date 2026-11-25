/* Trésorerie › l'onglet « Budget & étude » (?vue=tresorerie), en lecture seule (24/11/2026) :
 *   onglet    treso-budget   le lien « Base : budget validé » du plan ; l'étude de marché, le budget mois par mois, le
 *                            réalisé, l'atteinte, l'année passée, l'atterrissage, les charges en euros, le réel du P&L,
 *                            le total de l'année ; changer d'exercice (2026 validé, 2027 encodé) ; la carte de l'étude.
 * Pas de chapitre de fonction : rien ne s'y modifie (le choix de l'exercice est un geste de lecture). Données fictives :
 * mocks/09_tresorerie.js (budget). Chaque phrase vient de panel/public/dashboard/tresorerie.js (rendBudget, carteEtude). */
const { Film } = require('../film');
const { fiabiliser, T, lignes, ligne, defilerCote } = require('./tresorerie');

const L = (de, a) => T + `.tr-tab-b tbody tr:nth-child(n+${de}):nth-child(-n+${a})`;

module.exports = async function () {
  const f = await Film.ouvrir('09-04-budget', {
    vue: 'tresorerie', serie: 'Trésorerie', parent: 'tresorerie', chargement: 3500,
    sources: ['panel/public/dashboard/tresorerie.js', 'panel/src/tresorerie.php'],
  });
  fiabiliser(f);

  await f.onglet({
    id: 'treso-budget', titre: 'Budget & étude',
    resume: 'En lecture seule : l’étude de marché, le budget mois par mois, le réalisé, l’atteinte, l’année passée, l’atterrissage, les charges en euros, le réel du P&L et la carte de l’étude.',
    accroche: 'Le budget et le réalisé, côte à côte',
    programme: ['L’étude de marché', 'Le budget et le réalisé', 'Les charges en euros', 'Le réel du P&L', 'Changer d’exercice'],
  });
  await lignes(f, await ligne(f, 'Encaissements (ventes)'), 'Base : budget validé');
  await f.dire('Les ventes prévues du plan partent du <b>budget validé</b> : le lien sous la ligne mène à l’onglet.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '.tr-sous-l[data-tra="onglet:budget"]', { apres: 1500 });
  await f.defiler(-4000);
  await f.montrer(T + '.tr-carte-t .tr-cbar', 'Budget & étude');
  await f.dire('En <b>lecture seule</b> : le budget et l’étude s’encodent au cockpit, avec le consultant ; l’onglet les reprend tout seul.', 6200);
  await lignes(f, L(1, 3), 'L’étude de marché');
  await f.dire('L’<b>étude de marché</b> : la saisonnalité, la part de l’année de chaque mois, et le CA théorique.', 5200);
  await lignes(f, L(4, 9), 'Le budget');
  await f.dire('Le <b>budget</b>, validé le 20 janvier : le CA au budget, le réalisé en caisse, l’atteinte, l’année passée, l’évolution.', 6200);
  await f.dire('Vert : au-dessus du budget ou de l’année passée ; rouge : en dessous. L’étoile : le mois en cours, à ce jour.', 5600);
  await lignes(f, L(10, 11), 'L’atterrissage');
  await f.dire('L’<b>atterrissage</b> : le réalisé des mois passés, puis le plan pour les mois qui viennent, face au budget.', 5600);
  await lignes(f, L(12, 20), 'Les charges');
  await f.dire('Les <b>charges</b> du budget en euros : chaque taux fois le CA au budget, jusqu’à la marge brute et au disponible pour la rémunération.', 6800);
  await lignes(f, L(21, 25), 'Le réel du P&L');
  await f.dire('Le <b>réel du P&L</b> des mois clos, face aux taux du budget : matière, personnel, frais généraux, marge nette.', 6000);
  await f.cacher(150); await f.taire(150);
  await defilerCote(f, 400);
  await lignes(f, L(4, 11), 'Total');
  await f.dire('Le total de l’année, au bout de chaque ligne.', 3000);
  await f.cacher(150); await f.taire(150);
  await defilerCote(f, 0);
  await f.cliquer(T + '[data-tra="anb:2027"]', { apres: 1300 });
  await f.montrer(T + '.tr-carte-t .tr-cbar', 'Exercice 2027');
  await f.dire('Changer d’exercice : <b>2027</b>, budget encodé, pas encore validé.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.montrer(T + '.tr-carte:not(.tr-carte-t)', 'L’étude de marché');
  await f.dire('Enfin, la carte de l’étude : le potentiel à maturité, la montée en régime année par année, la saisonnalité.', 6000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
