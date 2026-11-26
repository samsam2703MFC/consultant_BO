/* Produits › la fenêtre d'une catégorie (?vue=produits), 26/11/2026 :
 *   onglet    produits-categorie   une tuile de « Vos catégories » ouvre la fenêtre : sa flèche, ses 13 semaines en
 *                                  colonnes, ses produits (prix, pièces par semaine, évolution : nouveau, arrêté, trop peu
 *                                  vendu), « ▾ les N autres produits », un produit déplié, « Fiche complète ↗ » — des
 *                                  gestes de lecture, montrés ici
 * La fiche produit et ses trois onglets (dont « Adapter mon prix de vente » et « Demander au réseau de relever le
 * maximum ») sont déjà filmés sous la barre du haut (01-24, 01-25, 01-26) : on montre comment l'ouvrir, et on y renvoie.
 * Aucune fonction en chapitre. Données fictives : mocks/11_produits.js (Pâtisserie : 14 références, SUPPL), la fiche :
 * mocks/10_ops.js (lib/fiche.js). Chaque phrase vient de panel/public/dashboard/dashboard.js (rendProduits, pdRendre,
 * pdDeroule, pdEvo, pdBarres) et du registre des vidéos (barre-fiche-*, sous « Découvrir le dashboard »). */
const { Film } = require('../film');

/** Un cadre autour d'un élément, défilé à vue, rogné à l'écran (le tableau des produits dépasse l'écran). */
async function cadre(f, sel, etiq) {
  const r = await f.rect(sel);
  const y = Math.max(8, r.y), y2 = Math.min(810 - 8, r.y + r.h);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y, w: r.w, h: y2 - y }, etiq || '']);
}

module.exports = async function () {
  const f = await Film.ouvrir('11-01-categorie', {
    vue: 'produits', serie: 'Produits', parent: 'produits',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/analyse_produits.php'],
    // Pour le film seulement : de la marge sous le tableau de la fenêtre, pour que ses dernières lignes remontent au-dessus
    // du sous-titre (sans elle, la fenêtre ne défile pas assez et le sous-titre les cache).
    avant: async p => { await p.addStyleTag({ content: '#db-pd .fi-bd{padding-bottom:190px}' }); },
  });
  const M = '#db-pd .fi-modale';

  await f.onglet({
    id: 'produits-categorie', titre: 'La fenêtre d’une catégorie',
    resume: 'Une tuile de « Vos catégories » ouvre sa fenêtre : sa flèche, ses 13 semaines, ses produits (prix, pièces par semaine, évolution), un produit déplié, et sa fiche complète.',
    accroche: 'Une catégorie, produit par produit',
    programme: ['Ouvrir une catégorie', 'Ses 13 semaines', 'Ses produits : nouveaux, arrêtés', 'Déplier un produit', 'Ouvrir sa fiche complète'],
  });
  await f.montrer('.pd-tuiles', 'Vos catégories');
  await f.dire('Dans « <b>Vos catégories</b> », un clic sur une tuile ouvre la fenêtre de la catégorie.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pdg="Pâtisserie"]', { apres: 1500 });
  await f.montrer('#db-pd .pd-mrep', 'Pâtisserie');
  await f.dire('Sa flèche : son chiffre par semaine sur les <b>6 dernières semaines</b>, face aux 6 d’avant.', 4600);
  await f.montrer('#db-pd .fi-bd > .pd-gr', 'Ses 13 semaines');
  await f.dire('Ses 13 semaines en colonnes : les 6 d’avant en clair, les 6 dernières en foncé, la semaine en cours hachurée.', 5800);
  await f.cacher(150); await f.taire(150);
  await cadre(f, '#db-pd .pd-prods', 'Ses produits');
  await f.dire('Ses produits, au chiffre : le prix, les pièces par semaine, l’<b>évolution</b>, et leurs semaines en petit.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.montrer('#db-pd [data-pdp="953"]', 'Arrêté');
  await f.dire('« <b>arrêté</b> », avec sa dernière semaine de vente : S41.', 3400);
  await f.montrer('#db-pd .pd-plus', 'Les autres produits');
  await f.dire('Les 12 premiers s’affichent ; « <b>▾ les 2 autres produits</b> » montre la suite.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-pd .pd-plus', { apres: 900 });
  await f.montrerTout('#db-pd [data-pdp="954"], #db-pd [data-pdp="955"]', 'Nouveau, trop peu vendu');
  await f.dire('« <b>nouveau</b> », avec la semaine de son départ ; « trop peu vendu » : pas assez de pièces pour juger.', 5400);
  await f.cacher(150); await f.taire(150);

  /* Déplier un produit, puis sa fiche complète. */
  await f.p.evaluate(async m => { const e = document.querySelector(m); const de = e.scrollTop; for (let i = 1; i <= 30; i++) { e.scrollTop = de * (1 - i / 30); await new Promise(r => requestAnimationFrame(r)); } }, M);
  await f.pause(300);
  await f.cliquer('#db-pd [data-pdp="113"]', { apres: 1200 });
  await f.montrer('#db-pd .pd-ddb', 'Éclair au chocolat');
  await f.dire('Un clic sur un produit le déplie : son <b>prix de vente</b>, ses pièces par semaine avant et maintenant, sa <b>marge</b>.', 6000);
  await f.montrer('#db-pd .pd-fiche', 'Fiche complète');
  await f.dire('« <b>Fiche complète ↗</b> » : la fenêtre se ferme, la fiche du produit s’ouvre.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-pd .pd-fiche', { apres: 1500 });
  await f.montrer('#db-fiche .fi-ong', 'Trois onglets');
  await f.dire('Trois onglets : les ventes, le prix face au réseau, la recette et la marge — où l’on <b>adapte son prix de vente</b>.', 6000);
  await f.dire('Chaque onglet a sa vidéo, « La fiche produit : … », sous « <b>Découvrir le dashboard</b> ».', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche .fi-x', { apres: 900 });

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
