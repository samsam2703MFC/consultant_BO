/* Vidéo 14 — « Les primes de l'équipe » (mode d'emploi de Vendre › Primes, ?vue=primes). Environ 2 min.
 * Données fictives : mocks/14_primes.js (six personnes de vente, novembre 2026 en cours, octobre clos, l'année).
 * La page se LIT : rien ne s'y pose ni ne s'y modifie (les primes se règlent au cockpit). Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (rendPrimes et voisins) ou de l'en-tête de panel/src/primes.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('14-primes', { vue: 'primes', serie: 'Mode d’emploi', titreSerie: 'Les primes de l’équipe', duree: 'env. 2 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Les primes<br>de l’équipe', accroche: 'Où en sont tes vendeuses',
    programme: ['Le magasin, ensemble', 'Le tableau des vendeuses', 'Le mois, l’année, les filtres'],
  });

  /* 1 — les primes d'équipe */
  await f.chapitre({ n: 1, total: 3, titre: 'Le magasin, ensemble', texte: 'Les primes que toute l’équipe gagne d’un coup, quand le magasin y arrive.' });
  await f.etape('1 · Le magasin, ensemble');
  await f.montrer('.pm-tete', 'Primes');
  await f.dire('La page <b>Primes</b> : ce que chacune voit dans Mes primes, prime par prime, pour tout le magasin.', 4600);
  await f.montrer('.pm-lecture', 'Lecture seule');
  await f.dire('Elle se lit : rien ne s’y pose ni ne s’y modifie. Les primes se règlent au <b>cockpit</b>.', 4200);
  await f.montrer('.pr-eqb', 'Le magasin, ensemble');
  await f.dire('En haut, les <b>primes d’équipe</b> : l’objectif du magasin et la note Google, la même valeur pour toute l’équipe.', 5400);
  await f.montrer('.pr-eqb-rs', 'Un anneau par prime');
  await f.dire('Un anneau par prime : le chemin fait, vert passé 80 % ou atteint. L’objectif du mois en cours se lit en <b>projection</b>.', 5600);
  await f.montrer('.pr-eqb-pot', 'Si tout est atteint');
  await f.dire('À droite : ce que chacune touche si tout est atteint, et le total pour l’équipe.', 4000);
  await f.montrer('.pr-eqb-ch', 'Qui en profite');
  await f.dire('Dessous, les <b>éligibles</b>, et celles sous le minimum d’heures du mois, qui n’en profitent pas.', 4600);
  await f.cacher();

  /* 2 — le tableau */
  await f.chapitre({ n: 2, total: 3, titre: 'Le tableau des vendeuses', texte: 'Une ligne par vendeuse, une colonne par prime individuelle.' });
  await f.etape('2 · Le tableau');
  await f.montrer('.pr-tab thead', 'Les primes');
  await f.dire('En tête de colonne : la prime, sa récompense, qui la paie — la marque ou le magasin — et combien l’ont déjà gagnée.', 5600);
  await f.cacher(150);
  await f.montrer('.pr-tab tbody tr:first-child', 'Une vendeuse');
  await f.dire('Une ligne par vendeuse, avec ses heures et ses tickets du mois.', 3400);
  await f.cacher(150);
  await f.montrer('.pr-tab td.pr-gagne', 'Gagnée');
  await f.dire('<b>✓ gagnée</b>, en vert, avec ce qu’elle rapporte.', 3200);
  await f.montrer(f.p.locator('.pr-tab td.pr-tete', { hasText: 'sur la 2e' }).first(), 'En tête');
  await f.dire('<b>En tête</b> : la 1re du magasin pour l’instant, et son avance sur la 2e.', 3800);
  await f.montrer(f.p.locator('.pr-tab td.pr-route', { hasText: 'cible' }).first(), 'Le chemin');
  await f.dire('Sinon, sa valeur face à l’objectif, et une <b>barre fine</b> : le chemin fait, verte passé 80 %.', 4600);
  await f.montrerTout('.pr-tab th.pr-d, .pr-tab td.pr-tot', 'Acquis');
  await f.dire('La dernière colonne : ce que chacune a déjà <b>acquis</b> ce mois-ci.', 3400);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-prtri="concours:tartes"]', { apres: 900 });
  await f.montrer('.pr-carte', 'Trier');
  await f.dire('Un clic sur un en-tête <b>trie</b> le tableau sur cette prime ; un second clic inverse.', 4200);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-prtri="acquis"]', { apres: 900 });
  await f.defiler(-2000);

  /* 3 — le mois, l'année, les filtres */
  await f.chapitre({ n: 3, total: 3, titre: 'Le mois, l’année, les filtres', texte: 'Le mois en cours en projection ; seul le mois clos se paie.' });
  await f.etape('');   // le badge de l'étape couvrirait le choix du mois
  await f.montrer('.pr-pas', 'Le mois');
  await f.dire('‹ et › changent de mois. Le mois en cours se montre en <b>projection</b>.', 3800);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-prm="-1"]', { apres: 1400 });
  await f.montrer('.pr-pas', 'Octobre');
  await f.dire('Octobre est <b>clos</b> : c’est lui qui se paie, et la dernière colonne dit ce qui a été gagné.', 4600);
  await f.taire(150); await f.cacher(150);
  await f.etape('3 · Le mois, l’année');
  await f.cliquer('[data-prf="magasin"]', { apres: 1200 });
  await f.montrer('.pr-carte', 'Celles du magasin');
  await f.dire('<b>Celles du magasin</b> : seulement les primes que le magasin paie lui-même.', 3800);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-prf="toutes"]', { apres: 900 });
  await f.cliquer('[data-prvue="annee"]', { apres: 1500 });
  await f.montrer('.pr-eqb', 'L’année');
  await f.dire('<b>Année</b> : les primes d’équipe, et le nombre de mois où le magasin y est arrivé.', 4400);
  await f.cacher(150);
  await f.montrer('.pr-carte', 'Prime par prime');
  await f.dire('Puis chacune : ce qu’elle a gagné sur l’année, prime par prime, et en combien de mois.', 4600);
  await f.cacher();

  return f.fin({
    titre: 'Les primes,<br>en clair', accroche: 'Où en sont tes vendeuses',
    retenir: ['La page se lit, les primes se règlent au cockpit', 'Les primes d’équipe : le magasin, ensemble', 'Une case : gagnée, en tête, ou le chemin fait', 'Le mois en cours en projection, le clos se paie'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
