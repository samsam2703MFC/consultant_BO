/* Opérationnel › « La vitrine : Short · Medium · Long life », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-vitrine   ce qui va manquer d'ici la fermeture et les ventes à sauver, la prochaine cuisson, les trois
 *                         durées de vie, une durée dépliée (la journée du produit en cases, à cuire dans la prochaine
 *                         cuisson), les petits carrés qui filtrent, « voir les N autres », le long life face à trois
 *                         jours de vente ; en bas de page, la barre Stock et « la liste ».
 * Déplier, filtrer, consulter l'inventaire : des gestes de lecture, sans chapitre à eux. « M'avertir » (les alertes de
 * stock en notification) demande l'autorisation du navigateur : pas filmé. Données fictives : mocks/10_ops.js
 * (/production/flux/suivi, /ventes/stock). Chaque phrase vient de panel/public/dashboard/dashboard.js (opVitrineE,
 * vitLire, vitBande, rendStock, stockTiroir).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

module.exports = async function () {
  const f = await ouvrir('01-06-vitrine');

  await f.onglet({
    id: 'ops-vitrine', titre: 'La vitrine',
    resume: 'Ce qui va manquer d’ici la fermeture, par durée de vie : heure par heure pour le frais, le stock face à trois jours de vente pour le long life ; les filtres ; et, en bas de page, l’inventaire.',
    accroche: 'Ce qui va manquer, avant que ça manque',
    programme: ['Short, medium et long life', 'Les filtres', 'Le long life', 'La barre Stock'],
  });

  await enHautDe(f, '.vt', 50);
  await dire(f, '.vt .ct', 'La vitrine', 'La <b>vitrine</b> : ce qui va manquer d’ici la fermeture. À droite, la prochaine cuisson.', 4800);
  await f.montrerTout('.vt .vt-h .rs', 'À sauver');
  await f.dire('Cinq produits vont manquer : 64 € de <b>ventes à sauver</b>. En haut de la page, « À corriger » le dit aussi.', 5000);
  await f.montrerTout('.vt .vt-h', 'Trois durées de vie');
  await f.dire('Short life, vendu le jour même ; medium life, deux à trois jours ; long life, une semaine et plus.', 5400);
  await rien(f);

  await f.cliquer('[data-vitb="S"]', { apres: 900, dx: 0.1 });
  await f.montrer('.vt-b.on .vt-l', 'Un produit');
  await f.dire('Chaque produit et sa journée en cases : vert en vitrine, or basse, rouge vide alors que des clients le demandent.', 6200);
  await f.montrerTout('.vt-b.on .vt-l.ko .mq, .vt-b.on .vt-l.ko .g', 'À sauver, à cuire');
  await f.dire('À droite : les ventes à sauver, et combien ajouter dans la cuisson de 16:30.', 4400);
  await dire(f, '.vt-b.on .cpt', 'Les filtres', 'Les petits carrés filtrent : <b>rouge</b>, vide maintenant ; <b>or</b>, va manquer ; <b>vert</b>, en ordre.', 5600);
  await rien(f);
  await f.cliquer('[data-vitq="S:ok"]', { apres: 900 });
  await f.dire('Le vert est éteint au départ : un clic ajoute les produits en ordre.', 3800);
  await f.taire(150);
  await f.montrer('[data-vitall="S"]', 'Voir les autres');
  await f.dire('Au-delà de douze produits : « <b>voir les autres</b> ».', 3000);
  await rien(f);
  await f.cliquer('[data-vitq="S:ok"]', { apres: 700 });
  await f.cliquer('[data-vitb="S"]', { apres: 700, dx: 0.1 });

  await f.cliquer('[data-vitb="L"]', { apres: 900, dx: 0.1 });
  await dire(f, '.vt-b.on', 'Long life', '<b>Long life</b> : le stock face à trois jours de vente, et ce qu’il faut refaire pour les tenir.', 5400);
  await rien(f);

  // La barre Stock est tout en bas de la page : on ouvre sa liste d'abord, pour la remonter au-dessus du sous-titre.
  await f.montrer('.op-stock .db-stbar', 'Stock', 1400);
  await f.cliquer('.op-stock .db-stbar .dr', { apres: 900 });
  await enHautDe(f, '.op-stock', 90);
  await dire(f, '.op-stock .db-stbar', 'Stock', 'En bas de page, la barre <b>Stock</b> : l’inventaire du magasin ; ce qui est sous le minimum est dans « À corriger ».', 5400);
  await dire(f, '.op-stock .db-stdl', 'La liste', '« <b>la liste</b> » : les références en alerte d’abord, puis tout l’inventaire, par catégorie.', 5200);
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
