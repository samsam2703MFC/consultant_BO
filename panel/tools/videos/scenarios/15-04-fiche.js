/* Concurrence, l'onglet « Fiche produit » (?vue=concurrence), 26/11/2026 :
 *   onglet    concurrence-fiche                un produit, ouvert d'une ligne du top 10 ou par « Aller chercher un
 *                                              produit… » : notre prix face aux médianes, l'échelle, chaque relevé et
 *                                              son écart, les produits proches
 *   fonction  concurrence-releve               « + Relevé terrain » sur la fiche : le concurrent, notre produit et le
 *                                              produit vu, le prix, « Poser le relevé » (la baguette tradition)
 *   fonction  concurrence-nouveau-concurrent   « — nouveau concurrent — » : son nom, son code postal, son type, puis le
 *                                              prix (le cookie chocolat) ; le concurrent est créé dans TFBuddy
 * Données fictives : mocks/15_concurrence.js (le POST crée le concurrent et le relevé ; la relecture les montre). La
 * liste « par code postal » ne se rouvre pas après l'ajout : la fausse API ne classe pas un concurrent créé.
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (rendConcurrence, bmChercher, bmFormulaire, bmBrancher) ou
 * de consultant/src/benchmark.php (wr_benchmark_releve). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('15-04-fiche', {
    vue: 'concurrence', serie: 'Concurrence', parent: 'concurrence',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/benchmark.php'],
  });

  await f.onglet({
    id: 'concurrence-fiche', titre: 'La fiche d’un produit',
    resume: 'Un produit, ouvert depuis le top 10 ou cherché dans le catalogue : notre prix face aux médianes des supermarchés, artisans et chaînes, l’échelle, chaque relevé avec son écart, puis les produits proches.',
    accroche: 'Un produit, chaque concurrent',
    programme: ['Ouvrir ou chercher un produit', 'Poser un relevé terrain', 'Ajouter un nouveau concurrent'],
  });
  await f.survoler('[data-bmtop="122"]', { pause: 300 });
  await f.dire('Un produit s’ouvre d’un clic sur sa ligne du <b>top 10</b>.', 3000);
  await f.taire(150);
  await f.cliquer('[data-bmtop="122"]', { apres: 1300 });
  await f.montrer('.db-tuiles', 'Sandwich jambon-beurre');
  await f.dire('Notre prix face à la médiane des supermarchés, des artisans et des chaînes ; et combien de relevés sont moins chers que nous.', 6400);
  await f.montrer(f.p.locator('.db-card', { hasText: 'Où nous situons-nous' }).first(), 'L’échelle');
  await f.dire('L’échelle : chaque relevé est un point, rangé par type de concurrent ; le trait Ruby, c’est nous.', 4800);
  await f.montrer(f.p.locator('.db-card', { hasText: '— les concurrents' }).first(), 'Les relevés');
  await f.dire('Chaque relevé : le concurrent, son code postal, sa distance, la date, le prix — <b>promo comprise</b> — et l’écart.', 5800);
  await f.montrer(f.p.locator('.db-card', { hasText: 'Produits proches' }).first(), 'Produits proches');
  await f.dire('Les <b>produits proches</b> : pour information, hors médianes.', 3400);
  await f.cacher(300); await f.taire(150);
  await f.defiler(-3000);
  await f.cliquer('[data-bmq]', { apres: 300 });
  await f.taper('baguette', 110);
  await f.pause(400);
  await f.montrerTout('.bm-cherche input, #bm-res button', 'Aller chercher un produit');
  await f.dire('« <b>Aller chercher un produit…</b> » : n’importe quel produit du catalogue du magasin.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmp="108"]', { apres: 1300 });
  await f.montrer('.bm-choix', 'Baguette tradition');
  await f.dire('Sa fiche s’ouvre ; son nom prend place dans les boutons du haut.', 3600);
  await f.cacher(300); await f.taire(150);

  /* Poser un relevé terrain */
  await f.fonction({ id: 'concurrence-releve', titre: 'Poser un relevé terrain',
    resume: 'Un prix vu chez un concurrent : sur la fiche du produit, « + Relevé terrain », le concurrent, le produit vu et son prix, puis « Poser le relevé » : il entre dans la liste et dans les médianes.',
    points: ['La fiche du produit', '« + Relevé terrain »', 'Le concurrent, le prix', '« Poser le relevé »'] });
  await f.montrer(f.p.locator('.db-card', { hasText: '— les concurrents' }).first(), 'Baguette tradition');
  await f.dire('La baguette tradition : pas encore de prix relevé chez la Boulangerie Saint-Marc.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);
  await f.cliquer('.db-sec [data-bmform]', { apres: 1000 });
  await f.montrer('.bm-form', 'Relevé terrain');
  await f.dire('Notre produit et le produit vu sont déjà remplis ; reste le concurrent et le prix — la promo et la taille si besoin.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-bmc=cid]', { pause: 400 });
  await f.p.locator('[data-bmc=cid]').selectOption('309');
  await f.pause(700);
  await f.cliquer('[data-bmc=prix]', { apres: 200 });
  await f.taper('1,75', 140);
  await f.pause(400);
  await f.cliquer('[data-bmenv]', { apres: 1600 });
  await f.montrer('.bm-msg', 'Posé');
  await f.dire('<b>Poser le relevé</b> : il entre dans le module Benchmark de TFBuddy, daté d’aujourd’hui.', 4400);
  await f.cacher(300); await f.taire(150);
  await f.montrer(f.p.locator('.bm-tab tr', { hasText: 'Boulangerie Saint-Marc' }).first(), 'Le nouveau relevé');
  await f.dire('Il est dans la liste, avec son écart, et dans les médianes.', 3400);
  await f.cacher(300); await f.taire(150);
  await f.defiler(-3000);

  /* Ajouter un nouveau concurrent */
  await f.fonction({ id: 'concurrence-nouveau-concurrent', titre: 'Ajouter un nouveau concurrent',
    resume: 'Dans le relevé terrain, laisser « — nouveau concurrent — » : son nom, son code postal et son type, puis le prix ; « Poser le relevé » crée le concurrent dans TFBuddy.',
    points: ['« + Relevé terrain »', '« — nouveau concurrent — »', 'Son nom, son code postal, son type', 'Le prix, « Poser le relevé »'] });
  await f.cliquer('[data-bmq]', { apres: 300 });
  await f.taper('cookie', 110);
  await f.pause(400);
  await f.cliquer('[data-bmp="132"]', { apres: 1300 });
  await f.dire('Un cookie vu chez un concurrent qui n’est pas encore dans la liste.', 3200);
  await f.taire(150);
  await f.cliquer('.db-sec [data-bmform]', { apres: 1000 });
  await f.montrer('[data-bmc=cid]', 'Nouveau concurrent');
  await f.dire('On laisse « <b>— nouveau concurrent —</b> », puis son nom, son <b>code postal</b> et son type.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmc=cnom]', { apres: 200 });
  await f.taper('Boulangerie des Remparts', 50);
  await f.cliquer('[data-bmc=ccp]', { apres: 200 });
  await f.taper('5001', 110);
  await f.survoler('[data-bmc=ctype]', { pause: 300 });
  await f.montrerTout('.bm-grille label:nth-child(-n+4)', 'Artisan, supermarché ou chaîne', 1800);
  await f.cacher(150);
  await f.cliquer('[data-bmc=prix]', { apres: 200 });
  await f.taper('2,40', 110);
  await f.pause(400);
  await f.cliquer('[data-bmenv]', { apres: 1600 });
  await f.montrer('.bm-msg', 'Posé');
  await f.dire('<b>Poser le relevé</b> : le concurrent est créé dans TFBuddy, avec son relevé.', 4000);
  await f.cacher(300); await f.taire(150);
  await f.montrer(f.p.locator('.bm-tab tr', { hasText: 'Boulangerie des Remparts' }).first(), 'Le nouveau concurrent');
  await f.dire('Il entre dans la liste, avec son code postal ; la prochaine fois, il est dans le choix des concurrents.', 5200);
  await f.cacher(300); await f.taire(150);
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
