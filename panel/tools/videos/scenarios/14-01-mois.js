/* Mon équipe › Primes, l'onglet « Mois en cours » (?vue=primes), 26/11/2026 :
 *   onglet    primes-mois   novembre en projection : « Le magasin, ensemble » (un anneau par prime d'équipe, ce que
 *                           chacune touche, les éligibles), le tableau des vendeuses prime par prime, « Acquis » ; trier
 *                           (un clic sur un en-tête, un second inverse) et « Celles du magasin »
 * Aucune fonction en chapitre : la page se lit (les primes se règlent au cockpit) ; trier et filtrer se montrent ici.
 * Données fictives : mocks/14_primes.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rendPrimes,
 * prEquipe, prTableMois, prCase) ou de l'en-tête de panel/src/primes.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('14-01-mois', {
    vue: 'primes', serie: 'Primes', parent: 'primes',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/primes.php'],
  });

  await f.onglet({
    id: 'primes-mois', titre: 'Le mois en cours',
    resume: 'Novembre en projection : les primes d’équipe en anneaux, puis le tableau des vendeuses, prime par prime, et ce que chacune a déjà acquis ; trier et garder les primes du magasin.',
    accroche: 'Où en sont tes vendeuses',
    programme: ['Le magasin, ensemble', 'Le tableau des vendeuses', 'Trier, garder celles du magasin'],
  });
  await f.montrer('.db-eqp-tabs', 'Mon équipe › Primes');
  await f.dire('<b>Primes</b> est un onglet de Mon équipe, après Planning.', 3400);
  await f.montrer('.pm-lecture', 'Lecture seule');
  await f.dire('La page se lit : les primes se posent et se règlent au <b>cockpit</b>.', 3800);
  await f.montrer('.pr-pas', 'Le mois');
  await f.dire('Elle s’ouvre sur le <b>mois en cours</b> : novembre, lu à ce jour.', 3600);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.pr-eqb', 'Le magasin, ensemble');
  await f.dire('En haut, <b>Le magasin, ensemble</b> : les primes que toute l’équipe gagne d’un coup, quand le magasin y arrive.', 5400);
  await f.montrer('.pr-eqb-rs', 'Un anneau par prime');
  await f.dire('Un anneau par prime : l’objectif du magasin, en <b>projection</b>, et la note Google, déjà atteinte. Vert passé 80 %.', 5800);
  await f.montrer('.pr-eqb-pot', 'Si tout est atteint');
  await f.dire('À droite : ce que chacune touche si tout est atteint, et le total pour l’équipe.', 4200);
  await f.montrer('.pr-eqb-ch', 'Qui en profite');
  await f.dire('Dessous, les <b>éligibles</b>, et celle qui est sous le minimum d’heures du mois.', 4400);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.pr-tab thead', 'Les primes');
  await f.dire('Le tableau : une colonne par prime individuelle — sa récompense, qui la paie, combien l’ont gagnée.', 5200);
  await f.montrer('.pr-tab tbody tr:first-child', 'Une vendeuse');
  await f.dire('Une ligne par vendeuse, avec ses heures et ses tickets du mois.', 3400);
  await f.montrer('.pr-tab td.pr-gagne', 'Gagnée');
  await f.dire('<b>✓</b> : gagnée, avec ce qu’elle rapporte.', 2800);
  await f.montrer(f.p.locator('.pr-tab td.pr-tete', { hasText: 'sur la 2e' }).first(), 'En tête');
  await f.dire('<b>En tête</b> : la 1re du magasin pour l’instant, et son avance sur la 2e.', 3800);
  await f.montrer(f.p.locator('.pr-tab td.pr-route', { hasText: 'cible' }).first(), 'Le chemin');
  await f.dire('Sinon, sa valeur face à la cible, et une <b>barre fine</b> : le chemin fait, verte passé 80 %.', 4600);
  await f.montrerTout('.pr-tab th.pr-d, .pr-tab td.pr-tot', 'Acquis');
  await f.dire('La dernière colonne, <b>Acquis</b> : ce que chacune a déjà gagné ce mois-ci.', 3800);
  await f.cacher(150); await f.taire(150);

  await f.cliquer('[data-prtri="croisees"]', { apres: 900 });
  await f.montrer('.pr-tab thead th:nth-child(2)', 'Trier');
  await f.dire('Un clic sur un en-tête <b>trie</b> le tableau sur cette prime…', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prtri="croisees"]', { apres: 900 });
  await f.montrer('.pr-tab thead th:nth-child(2)', 'Dans l’autre sens');
  await f.dire('… un second clic inverse l’ordre.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prtri="acquis"]', { apres: 900 });
  await f.defiler(-2000);

  await f.cliquer('[data-prf="magasin"]', { apres: 1200 });
  await f.montrer('.pr-carte', 'Celles du magasin');
  await f.dire('<b>Celles du magasin</b> : seules restent les primes que le magasin paie lui-même — ici, son défi.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-prf="toutes"]', { apres: 1000 });
  await f.dire('<b>Toutes les primes</b> ramène le tableau entier.', 2800);
  await f.taire(150);
  await f.pause(500);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
