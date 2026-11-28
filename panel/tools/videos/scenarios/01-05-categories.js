/* Opérationnel › « Ventes par catégorie : Liste · Treemap · Promotions · Portions », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-ventes-categories   la liste groupe › catégorie › produit (CA hors TVA, part, marge, taux, coefficient,
 *                                   la couleur de la marge), déplier jusqu'au produit, la fiche d'un produit ; le
 *                                   treemap ; les promotions lues dans les tickets ; les portions ; la vue gardée.
 * Déplier, ouvrir la fiche d'un produit, changer de vue : des gestes de lecture, sans chapitre à eux (la fiche produit
 * a sa vidéo avec les fenêtres communes). Données fictives : mocks/10_ops.js, les promotions et les portions de
 * mocks/10_ops_ventes.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (jourPieces, accordeon, treemap,
 * promoOnglet, portionOnglet, ficheOuvrir).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const CAT = '.db-card:has(.db-cvue)';

module.exports = async function () {
  const f = await ouvrir('01-05-categories', { sources: ['panel/src/stats_ventes.php', 'marketing/src/promos_ventes.php'] });

  await f.onglet({
    id: 'ops-ventes-categories', titre: 'Ventes par catégorie',
    resume: 'La vente du jour en quatre vues : la liste groupe › catégorie › produit avec sa marge, le treemap, les promotions lues dans les tickets et les portions.',
    accroche: 'Ce qui se vend, et ce que ça rapporte',
    programme: ['Liste : jusqu’au produit', 'Treemap', 'Promotions', 'Portions'],
  });

  await enHautDe(f, CAT, 44);
  await dire(f, CAT + ' .db-cvue', 'Quatre vues', '<b>Ventes par catégorie</b>, hors TVA : la même vente, en quatre vues.', 4000);
  await dire(f, CAT + ' .db-acc .db-ent', 'Liste', 'La <b>liste</b> : chaque groupe, son CA, sa part, sa marge brute, son taux et son coefficient.', 5200);
  await f.dire('La couleur suit le taux de marge : rouge sous 40 %, or de 40 à 60 %, vert à 60 % et plus.', 4400);
  await rien(f);

  await f.cliquer('[data-cacc="g:Pâtisserie"]', { apres: 700, dx: 0.04 });
  await f.cliquer('[data-cacc="c:Pâtisseries individuelles"]', { apres: 700, dx: 0.06 });
  await f.montrerTout(CAT + ' .db-al.p', 'Les produits');
  await f.dire('Un groupe, puis une catégorie : ses <b>produits</b>, chacun avec sa marge.', 4200);
  await rien(f);
  await f.cliquer(CAT + ' .db-al.p[data-fprod="113"]', { apres: 1800, dx: 0.12 });
  await f.dire('Un produit ouvre sa <b>fiche</b> : ses ventes sur 12 semaines, son prix face au réseau, sa recette.', 5000);
  await f.taire(150);
  await f.p.keyboard.press('Escape'); await f.pause(600);
  await f.cliquer('[data-cacc="g:Pâtisserie"]', { apres: 600, dx: 0.04 });

  await enHautDe(f, CAT, 16);
  await f.cliquer(CAT + ' [data-cvue="treemap"]', { apres: 1000 });
  await dire(f, CAT + ' .db-tm', 'Treemap', '<b>Treemap</b> : une surface par catégorie, à la taille de son CA, à la couleur de sa marge.', 5000);
  await rien(f);

  await f.cliquer(CAT + ' [data-cvue="promo"]', { apres: 1000 });
  await dire(f, CAT + ' .pr-bd', 'Promotions', '<b>Promotions</b>, lues dans les tickets : la part du CA en promotion, les remises, les tickets.', 5200);
  await dire(f, CAT + ' .pr-chips', 'Une couleur chacune', 'Chaque promotion a sa couleur, et le nombre de fois qu’elle s’est déclenchée.', 4400);
  await f.montrer(CAT + ' .db-acc.pro .db-al.u3', 'Une ligne par promotion');
  await f.dire('Puis groupe, catégorie, produit ; sous chaque produit, une ligne par promotion.', 4400);
  await rien(f);

  await enHautDe(f, CAT, 16);
  await f.cliquer(CAT + ' [data-cvue="portion"]', { apres: 1000 });
  await dire(f, CAT + ' .pr-bd', 'Portions', '<b>Portions</b> : les catégories vendues aussi en parts.', 3400);
  await dire(f, CAT + ' .db-acc.pot', 'Par produit', 'Par produit : combien entières, en ½, en ⅙ ; ce que ça fait en pièces entières, et ce qui reste en parts.', 6000);
  await rien(f);
  await f.montrer(CAT + ' .db-cvue', 'Gardée');
  await f.dire('La vue choisie reste pour la prochaine visite.', 2800);
  await rien(f);
  await f.cliquer(CAT + ' [data-cvue="liste"]', { apres: 1400 });
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
