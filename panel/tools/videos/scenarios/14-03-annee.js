/* Mon équipe › Primes, l'onglet « Année » (?vue=primes), 26/11/2026 :
 *   onglet    primes-annee   le sélecteur « Année » : 2026, les primes d'équipe en anneaux (les mois atteints sur les
 *                            mois clos, depuis le 25/11/2026 ; le montant versé), puis par vendeuse ce qu'elle a gagné,
 *                            prime par prime, et en combien de mois ; le total ; trier, « Celles du magasin »
 * Aucune fonction en chapitre : la page se lit. Données fictives : mocks/14_primes.js (annee()). Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (rendPrimes, prEquipeAn, prAnneeVue, prTableAn). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('14-03-annee', {
    vue: 'primes', serie: 'Primes', parent: 'primes',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/primes.php'],
  });

  await f.onglet({
    id: 'primes-annee', titre: 'L’année',
    resume: '2026 : les primes d’équipe en anneaux — les mois atteints sur les mois clos, le montant versé —, puis par vendeuse ce qu’elle a gagné, prime par prime, et en combien de mois.',
    accroche: 'Ce que l’équipe a gagné',
    programme: ['Les primes d’équipe sur l’année', 'Par vendeuse, prime par prime', 'Le total, trier, filtrer'],
  });
  await f.montrer(f.p.locator('.pr-outils .db-ong').nth(1), 'Mois · Année');
  await f.dire('À côté de « Mois », le sélecteur <b>Année</b>.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prvue="annee"]', { apres: 1600 });
  await f.montrer('.pm-tete', '2026');
  await f.dire('L’année en cours, de janvier à aujourd’hui, et ce que l’équipe a gagné en tout.', 4200);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.pr-eqb-rs', 'Les mois clos');
  await f.dire('Les primes d’équipe : le nombre de <b>mois clos</b> où le magasin y est arrivé. Le mois en cours n’y compte pas encore.', 6000);
  await f.dire('À côté de chaque anneau : qui la paie, ce qu’elle a rapporté, et combien de gagnantes.', 4200);
  await f.montrer('.pr-eqb-pot', 'Versés');
  await f.dire('À droite : ce qui a été <b>versé à l’équipe</b> dans l’année.', 3400);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.pr-tab thead', 'Les primes gagnées');
  await f.dire('Le tableau : une colonne par prime gagnée, avec ce qu’elle a rapporté et ses gagnantes.', 4600);
  await f.montrer('.pr-tab tbody tr:first-child', 'Une vendeuse');
  await f.dire('Une ligne par vendeuse : le montant gagné et en <b>combien de mois</b>, prime par prime ; sous son nom, ses mois primés.', 6000);
  await f.montrerTout('.pr-tab th.pr-d, .pr-tab td.pr-tot', 'Total');
  await f.dire('<b>Total</b> : les primes d’équipe sortent du tableau, mais comptent dans le total de chacune.', 4800);
  await f.cacher(150); await f.taire(150);

  await f.cliquer('[data-prtri="meilleure"]', { apres: 900 });
  await f.montrer('.pr-carte', 'Trier');
  await f.dire('Un clic sur un en-tête <b>trie</b> : ici, sur Meilleure vendeuse.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prtri="total"]', { apres: 800 });
  await f.defiler(-2000);
  await f.cliquer('[data-prf="magasin"]', { apres: 1200 });
  await f.montrer('.pr-carte', 'Celles du magasin');
  await f.dire('<b>Celles du magasin</b> : le défi que le magasin paie, et qui l’a gagné.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prf="toutes"]', { apres: 1000 });
  await f.cliquer('[data-prvue="mois"]', { apres: 1400 });
  await f.dire('« <b>Mois</b> » ramène au mois en cours.', 2600);
  await f.taire(150);
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
