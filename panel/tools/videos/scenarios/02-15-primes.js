/* Mon équipe › l'onglet « Primes » (?vue=equipe, puis ?vue=primes), sans fonction qui écrit (24/11/2026, « mettre les
 * primes dans l'icône Mon équipe, après le planning ») :
 *   onglet    equipe-primes   « Où en sont tes vendeuses » : les primes d'équipe (Le magasin, ensemble), le tableau
 *                             vendeuses × primes, l'acquis ; trier, changer de mois, « Celles du magasin », l'année
 * La page se LIT : rien ne s'y pose ni ne s'y modifie (« Lecture seule : les primes se posent et se règlent au
 * cockpit ») — aucun chapitre de fonction. Les cartes du téléphone (rendPrimesMobile) ne se filment pas ici.
 * Données fictives : mocks/14_primes.js (six personnes de vente, novembre 2026 en cours, octobre clos, l'année).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (rendPrimes et voisins) ou de l'en-tête de
 * panel/src/primes.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('02-15-primes', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/primes.php'] });

  await f.onglet({
    id: 'equipe-primes', titre: 'Les primes',
    resume: 'Où en sont les vendeuses : les primes d’équipe, le tableau prime par prime et l’acquis ; trier, changer de mois, celles du magasin, l’année. La page se lit : les primes se règlent au cockpit.',
    accroche: 'Où en sont tes vendeuses',
    programme: ['Le magasin, ensemble', 'Le tableau des vendeuses', 'Le mois, l’année, les filtres'],
  });
  await f.montrer('[data-eqp-onglet=primes]', 'Primes');
  await f.dire('« <b>Primes</b> », le troisième onglet de Mon équipe, après Planning.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-onglet=primes]', { apres: 1800 });
  await f.montrer('.pm-tete', 'Où en sont tes vendeuses');
  await f.dire('Ce que chacune voit dans « Mes primes », prime par prime, pour tout le magasin.', 4200);
  await f.montrer('.pm-lecture', 'Lecture seule');
  await f.dire('Ici, tout se lit : les primes se posent et se règlent au <b>cockpit</b>.', 3800);

  /* Le magasin, ensemble */
  await f.montrer('.pr-eqb-rs', 'Le magasin, ensemble');
  await f.dire('En haut, les <b>primes d’équipe</b> : un anneau par prime, vert passé 80 % ou atteint.', 4600);
  await f.montrer('.pr-eqb-pot', 'Si tout est atteint');
  await f.dire('Ce que chacune touche si tout est atteint, et le total pour l’équipe.', 3600);
  await f.montrer('.pr-eqb-ch', 'Qui en profite');
  await f.dire('Les éligibles, et celles <b>sous le minimum d’heures</b> du mois, qui n’en profitent pas.', 4400);

  /* Le tableau */
  await f.montrer('.pr-tab thead', 'Une colonne par prime');
  await f.dire('Le tableau : une colonne par prime individuelle, sa récompense, qui la paie, combien l’ont gagnée.', 5000);
  await f.montrer('.pr-tab tbody tr:first-child', 'Une ligne par vendeuse');
  await f.dire('<b>✓ gagnée</b> en vert, <b>en tête</b>, ou sa valeur face à l’objectif et la barre du chemin fait.', 5000);
  await f.montrerTout('.pr-tab th.pr-d, .pr-tab td.pr-tot', 'Acquis');
  await f.dire('À droite : ce que chacune a déjà <b>acquis</b> ce mois-ci.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prtri="concours:tartes"]', { apres: 900 });
  await f.montrer('.pr-carte', 'Trier');
  await f.dire('Un clic sur un en-tête <b>trie</b> le tableau sur cette prime ; un second clic inverse.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prtri="acquis"]', { apres: 900 });
  await f.defiler(-2000);

  /* Le mois, les filtres, l'année */
  await f.montrer('.pr-pas', 'Le mois');
  await f.dire('‹ et › changent de mois. Le mois en cours se lit en <b>projection</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prm="-1"]', { apres: 1400 });
  await f.montrer('.pr-pas', 'Octobre');
  await f.dire('Octobre est <b>clos</b> : c’est lui qui se paie ; la dernière colonne dit ce qui a été gagné.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prf="magasin"]', { apres: 1200 });
  await f.montrer('.pr-carte', 'Celles du magasin');
  await f.dire('« <b>Celles du magasin</b> » : seulement les primes que le magasin paie lui-même.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prf="toutes"]', { apres: 900 });
  await f.cliquer('[data-prvue="annee"]', { apres: 1500 });
  await f.montrer('.pr-eqb', 'L’année');
  await f.dire('« <b>Année</b> » : les mois où le magasin a décroché les primes d’équipe…', 3800);
  await f.montrer('.pr-carte', 'Prime par prime');
  await f.dire('… puis ce que chacune a gagné sur l’année, prime par prime, et en combien de mois.', 4400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
