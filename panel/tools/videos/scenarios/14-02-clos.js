/* Mon équipe › Primes, l'onglet « Mois clos » (?vue=primes), 26/11/2026 :
 *   onglet    primes-mois-clos   « ‹ » à côté du mois : octobre, clos, est le mois qui se paie ; les primes d'équipe
 *                                atteintes ou pas, la colonne « Gagné », ce qui n'est pas gagné en gris ; « › » revient
 *                                jusqu'au mois en cours (la fonction « Changer de mois » de la liste, fusionnée ici)
 * Aucune fonction en chapitre : la page se lit. Données fictives : mocks/14_primes.js (seuls novembre et octobre 2026 ont
 * des données : on ne va pas plus loin qu'octobre). Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (rendPrimes, prMois, prEquipeInfos, prCase, prBarre) ou de l'en-tête de panel/src/primes.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('14-02-clos', {
    vue: 'primes', serie: 'Primes', parent: 'primes',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/primes.php'],
  });

  await f.onglet({
    id: 'primes-mois-clos', titre: 'Le mois clos',
    resume: '« ‹ » et « › » changent de mois : octobre, clos, est celui qui se paie — les primes d’équipe atteintes ou pas, la colonne « Gagné », ce qui n’est pas gagné en gris.',
    accroche: 'Le mois qui se paie',
    programme: ['Changer de mois', 'Les primes d’équipe, atteintes ou pas', 'La colonne « Gagné »'],
  });
  await f.montrer('.pr-pas', 'Le mois');
  await f.dire('‹ et › changent de mois, de janvier 2026 jusqu’au mois en cours, qui reste en <b>projection</b>.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prm="-1"]', { apres: 1500 });
  await f.montrer('.pr-pas', 'Octobre');
  await f.dire('Octobre est <b>clos</b> : c’est le mois qui se paie.', 3200);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.pr-eqb-rs', 'Atteint ou pas');
  await f.dire('Plus de projection : chaque prime d’équipe est <b>atteinte</b> ou pas. En octobre, les deux le sont.', 5000);
  await f.montrer('.pr-eqb-ch', 'Éligibles');
  await f.dire('Dessous, celles qui en profitent : les éligibles du mois.', 3400);
  await f.cacher(150); await f.taire(150);

  await f.montrerTout('.pr-tab th.pr-d, .pr-tab td.pr-tot', 'Gagné');
  await f.dire('La dernière colonne devient <b>Gagné</b> : ce que chacune a gagné sur le mois.', 4200);
  await f.montrer(f.p.locator('.pr-tab td.pr-perdu', { hasText: 'cible' }).first(), 'Pas gagnée');
  await f.dire('Ce qui n’a pas été gagné passe en <b>gris</b>, sa barre aussi : le mois est fini.', 4400);
  await f.montrer(f.p.locator('.pr-tab td.pr-gagne', { hasText: '1re' }).first(), 'La 1re');
  await f.dire('Dans un classement, la <b>1re</b> du mois a gagné ; les autres gardent leur place, en gris.', 4600);
  await f.cacher(150); await f.taire(150);

  await f.defiler(-2000);
  await f.cliquer('[data-prm="1"]', { apres: 1500 });
  await f.montrer('.pr-pas', 'Le mois en cours');
  await f.dire('› ramène au <b>mois en cours</b> ; il ne va pas plus loin.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.pause(500);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
