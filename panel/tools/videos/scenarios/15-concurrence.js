/* Vidéo 15 — « La concurrence » (mode d'emploi de Vendre › Concurrence, ?vue=concurrence). Environ 2 min 45.
 * Données fictives : mocks/15_concurrence.js (neuf concurrents inventés, 41 relevés de prix) et les prix du catalogue de
 * démo. Le récit : le top 10 du réseau face aux concurrents, le sandwich jambon-beurre en détail, la baguette cherchée et
 * un relevé terrain posé, puis les concurrents par code postal. Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (rendConcurrence, rendTop10, bmParCp, bmFormulaire) ou de consultant/src/benchmark.php. */
const { Film } = require('../film');

/** Fait défiler un tableau large de gauche à droite (ou retour), en douceur. */
async function glisser(f, sel, dx) {
  await f.p.evaluate(async ({ sel, dx }) => {
    const el = document.querySelector(sel); if (!el) { return; }
    const de = el.scrollLeft, a = Math.max(0, Math.min(el.scrollWidth - el.clientWidth, de + dx));
    for (let i = 1; i <= 40; i++) { const t = i / 40, e = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; el.scrollLeft = de + (a - de) * e; await new Promise(r => requestAnimationFrame(r)); }
  }, { sel, dx });
  await f.pause(300);
}

module.exports = async function () {
  const f = await Film.ouvrir('15-concurrence', { vue: 'concurrence', serie: 'Mode d’emploi', titreSerie: 'La concurrence', duree: 'env. 2 min 45' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Nos prix face<br>à la concurrence', accroche: 'Le bon prix, au bon endroit',
    programme: ['Le top 10 du réseau', 'Un produit en détail', 'Ajouter un relevé terrain', 'Les concurrents par code postal'],
  });

  /* 1 — le top 10 */
  await f.chapitre({ n: 1, total: 4, titre: 'Le top 10 du réseau', texte: 'Nos 10 produits les plus vendus, face aux concurrents du magasin.' });
  await f.etape('1 · Le top 10');
  await f.montrer('.bm-choix', 'Ce qu’on compare');
  await f.dire('On compare le <b>top 10 du réseau</b>, deux familles de saison — cougnou et galette des rois — ou n’importe quel produit.', 5600);
  await f.cacher(150);
  await f.montrer('#dash .ac-cle', 'En un coup d’œil');
  await f.dire('En tête : combien de produits sont <b>au prix du marché</b>, au-dessus, en dessous, et ceux qui n’ont pas encore de relevé.', 5600);
  await f.montrer('#dash .ac-cle .v', 'Au prix du marché');
  await f.dire('Au prix du marché : à <b>± 5 %</b> de la médiane des boulangeries.', 3600);
  await f.cacher(450);
  await f.montrer('.bm-tab', 'Le tableau');
  await f.dire('Pour chaque produit : notre prix, la médiane des boulangeries, celle des supermarchés, et la fourchette.', 5000);
  await f.montrer('.bm-tab tbody tr:nth-child(5) td:nth-child(7)', 'La position');
  await f.dire('La position : chaque point gris est un concurrent, le trait Ruby notre prix.', 3800);
  await f.cacher(150);
  await glisser(f, '.bm-defil', 600);
  await f.montrerTout('.bm-tab tbody td:nth-last-child(-n+2)', 'Écart et verdict');
  await f.dire('Plus à droite : l’<b>écart</b> et le <b>verdict</b>. Il se lit face aux boulangeries — artisans, chaînes, stations — hors supermarchés.', 6000);
  await f.cacher(150);
  await glisser(f, '.bm-defil', -600);

  /* 2 — un produit */
  await f.chapitre({ n: 2, total: 4, titre: 'Un produit en détail', texte: 'Un clic sur une ligne ouvre le produit : chaque concurrent, chaque prix.' });
  await f.etape('2 · Un produit');
  await f.cliquer('[data-bmtop="122"]', { apres: 1200 });
  await f.montrer('#dash .ac-cle', 'Sandwich jambon-beurre');
  await f.dire('Notre prix face à la médiane des supermarchés, des artisans et des chaînes ; et combien de relevés sont moins chers que nous.', 6000);
  await f.cacher(150);
  await f.montrer(f.p.locator('.db-card', { hasText: 'Où nous situons-nous' }).first(), 'L’échelle');
  await f.dire('L’échelle : chaque relevé est un point, rangé par type de concurrent ; le trait Ruby, c’est nous.', 4800);
  await f.cacher(150);
  await f.montrer(f.p.locator('.db-card', { hasText: 'les concurrents du magasin' }).first(), 'Les relevés');
  await f.dire('Chaque relevé : le concurrent, sa distance, la date, le prix — <b>promo comprise</b> — et l’écart avec nous.', 5200);
  await f.cacher(150);
  await f.montrer(f.p.locator('.db-card', { hasText: 'Produits proches' }).first(), 'Produits proches');
  await f.dire('Les <b>produits proches</b> sont là pour information : ils n’entrent pas dans les médianes.', 4400);
  await f.cacher(150);
  await f.defiler(-3000);
  await f.cliquer('[data-bmq]', { apres: 300 });
  await f.taper('baguette', 110);
  await f.montrer('.bm-cherche', 'Aller chercher un produit');
  await f.dire('<b>Aller chercher un produit</b> : n’importe quel produit du catalogue du magasin.', 4000);
  await f.cacher(150);
  await f.taire(150);
  await f.cliquer('[data-bmp="108"]', { apres: 1200 });

  /* 3 — un relevé terrain */
  await f.chapitre({ n: 3, total: 4, titre: 'Ajouter un relevé terrain', texte: 'Un prix vu chez un concurrent : on le pose dans TFBuddy, daté d’aujourd’hui.' });
  await f.etape('3 · Un relevé terrain');
  await f.montrer(f.p.locator('.db-card', { hasText: 'les concurrents du magasin' }).first(), 'Baguette tradition');
  await f.dire('La baguette tradition : pas encore de prix relevé chez la Boulangerie Saint-Marc.', 4000);
  await f.cacher(150);
  await f.defiler(-3000);
  await f.cliquer('.db-sec [data-bmform]', { apres: 900 });
  await f.montrer('.bm-form', 'Relevé terrain');
  await f.dire('Le concurrent — ou un nouveau, avec son code postal —, notre produit, le produit vu, son prix, la promo, la taille.', 5600);
  await f.taire(150); await f.cacher(150);
  await f.survoler('[data-bmc=cid]', { pause: 400 });
  await f.p.locator('[data-bmc=cid]').selectOption('309');
  await f.pause(600);
  await f.cliquer('[data-bmc=prix]', { apres: 200 });
  await f.taper('1,75', 140);
  await f.pause(400);
  await f.cliquer('[data-bmenv]', { apres: 1500 });
  await f.montrer('.bm-msg', 'Posé');
  await f.dire('<b>Poser le relevé</b> : il entre dans le module Benchmark de TFBuddy, et la page se relit.', 4400);
  await f.cacher(450);
  await f.montrer(f.p.locator('.bm-tab tr', { hasText: 'Boulangerie Saint-Marc' }).first(), 'Le nouveau relevé');
  await f.dire('Le nouveau relevé est dans la liste, et dans les médianes.', 3400);
  await f.cacher(150);

  /* 4 — par code postal */
  await f.chapitre({ n: 4, total: 4, titre: 'Les concurrents par code postal', texte: 'La zone du magasin, et où se trouvent ses concurrents.' });
  await f.etape('4 · Par code postal');
  await f.cliquer('[data-bmcp]', { apres: 900 });
  await f.montrer(f.p.locator('.db-card', { hasText: 'par code postal' }).first(), 'Par code postal');
  await f.dire('Les concurrents du magasin, rangés par <b>code postal</b> : dans la zone, zone d’un autre magasin, hors zone, nationaux.', 6000);
  await f.dire('La zone du magasin se règle dans le cockpit, Benchmark.', 3400);
  await f.cacher();

  return f.fin({
    titre: 'Le bon prix,<br>au bon endroit', accroche: 'Nos prix face à la concurrence',
    retenir: ['± 5 % de la médiane : au prix du marché', 'Le verdict se lit face aux boulangeries', 'Un clic sur un produit : chaque concurrent', 'Un prix vu en magasin ? Relevé terrain'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
