/* Promotions › le Coach Vente, étape 1 « Le constat » (?vue=promos), la vidéo de l'onglet (24/11/2026) :
 *   onglet    promos-constat     la lecture du panel, la marge de base, le nuage des produits, les deux listes, puis
 *                                « En discuter avec le Coach Vente » (l'Assistant IA s'ouvre sur lui)
 * Aucune fonction n'écrit à cette étape : pas de chapitre de fonction (relire le panel, ouvrir l'Assistant IA : des
 * gestes de lecture, montrés dans l'onglet).
 * Données fictives : mocks/12_promotions.js (la relecture du panel, &frais=1, rend l'heure du tournage), 71_assistant.js.
 * Chaque phrase vient de panel/public/dashboard/bundles.js, de rendPromos (dashboard.js) ou de panel/src/bundles.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-01-constat', {
    vue: 'promos', serie: 'Promotions', parent: 'promotions',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/bundles.js', 'panel/public/dashboard/bundles.css', 'panel/src/bundles.php'],
  });

  await f.onglet({
    id: 'promos-constat', titre: 'Le constat',
    resume: 'L’étape 1 du Coach Vente : votre marge de base, chaque produit en marge et en ventes, ce qui fait le volume et ce qui rapporte.',
    accroche: 'Votre marge de base, d’abord',
    programme: ['Relire le panel', 'Votre marge de base', 'Chaque produit : marge et ventes', 'Les deux listes', 'En discuter avec le Coach Vente'],
  });

  /* La tête de la page : la lecture du panel */
  await f.montrer('.pm-tete > div:first-child', 'Relu au panel');
  await f.dire('En tête : l’heure de la dernière lecture du panel. La page le relit à chaque ouverture.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pmrelire]', { apres: 1200 });
  await f.montrer('.pm-tete > div:first-child', 'Relu à l’instant');
  await f.dire('« <b>↻ relire le panel</b> » le refait : une promotion changée au panel se voit tout de suite.', 4600);
  await f.cacher(300); await f.taire(200);

  /* Le Coach Vente, étape 1 */
  await f.montrer('.bd-h', 'Le Coach Vente');
  await f.dire('Sous « Où les promotions sont utilisées », le <b>Coach Vente</b> : il crée avec vous un bundle qui monte votre marge.', 5200);
  await f.montrer('.bd-pas', 'Cinq étapes');
  await f.dire('Cinq étapes. La page s’ouvre sur la première : <b>le constat</b>.', 3600);
  await f.montrer('.bd-k4', 'Le constat');
  await f.dire('Votre <b>marge de base</b> : prix hors TVA moins coût de recette, sur les ventes des 30 derniers jours.', 5000);
  await f.dire('Puis la part des pièces vendues sous cette marge, celle à 78 % de marge et plus, et la période lue.', 4800);
  await f.montrer('.bd-c1 > div:first-child', 'Chaque produit');
  await f.dire('Chaque point est un produit : sa marge en hauteur, ses pièces vendues en largeur. Le pointillé : votre marge de base.', 5600);
  await f.dire('En rouge, les produits qui attirent, à coupler ; en bleu, ceux à grande valeur ajoutée.', 4400);
  await f.montrer('.bd-listes', 'Les deux listes');
  await f.dire('« <b>Ils font le volume, pas la marge</b> » : beaucoup vendus, sous votre marge de base.', 4400);
  await f.dire('« <b>Grande valeur ajoutée, peu vendus</b> » : 78 % de marge et plus. Le Coach Vente les couple.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.bd-act [data-bdphase="2"]', 'Étape 2');
  await f.dire('« <b>Voir les couples qui montent la marge</b> » passe à l’étape 2.', 3600);
  await f.cacher(150); await f.taire(150);

  /* En discuter avec le Coach Vente */
  await f.montrer('.bd-act [data-bd="coach"]', 'Une question ?');
  await f.dire('Au pied de chaque étape : « <b>En discuter avec le Coach Vente</b> ».', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bd-act [data-bd="coach"]', { apres: 2000 });
  await f.montrer('.ia-ton.on', 'Coach Vente');
  await f.dire('L’<b>Assistant IA</b> s’ouvre sur le Coach Vente : prix face à la concurrence, bundles, panier.', 4800);
  await f.montrerTout('.ia-q [data-iaq*="bundle"]', 'Les bundles');
  await f.dire('À gauche, ses questions toutes prêtes, dont celles sur les bundles ; ou la vôtre, en bas.', 4600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
