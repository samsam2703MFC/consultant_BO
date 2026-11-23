/* Vidéo 17 — « Réseaux sociaux » (mode d'emploi court de Marketing › Réseaux sociaux, vue=social). Environ 1 min.
 * panel/public/dashboard/reseaux.js : l'entrée du dashboard vers l'espace boutique du Marketing — la barre d'onglets et
 * le cadre où s'ouvrent ses pages. Ces pages sont servies par l'application Marketing (dépôt séparé) : elles ne
 * s'inventent pas et ne sont pas filmées ; le cadre reste vide (mocks/17_reseaux.js). */
const { Film } = require('../film');

/** Le cadre de l'espace (il prend la hauteur de la fenêtre et défile lui-même) : entouré sans faire défiler la page. */
async function montrerCadre(f, etiquette) {
  const r = await f.p.locator('#db-reseaux .rx-cadre').boundingBox();
  if (!r) { return; }
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.width, h: Math.min(r.height, 810 - r.y - 150) }, etiquette]);
}

module.exports = async function () {
  const f = await Film.ouvrir('17-reseaux-sociaux', { vue: '', serie: 'Mode d’emploi', titreSerie: 'Réseaux sociaux', duree: 'env. 1 min' });
  await f.couverture({
    kicker: 'Mode d’emploi · Marketing', titre: 'Réseaux sociaux', accroche: 'L’espace boutique, dans le dashboard',
    programme: ['L’entrée dans le rail', 'Les onglets de l’espace boutique', 'Une page, sans quitter le dashboard'], ms: 4800,
  });

  await f.chapitre({ n: 1, total: 2, titre: 'L’entrée', texte: 'Marketing › Réseaux sociaux : l’espace boutique du Marketing, ouvert pour mon magasin.', ms: 3400 });
  await f.etape('1 · L’entrée');
  await f.montrer('#db-rail .sidebar__item[data-vue=social]', 'Réseaux sociaux');
  await f.dire('Dans le rail, section Marketing : <b>Réseaux sociaux</b>.', 3200);
  await f.cacher(150);
  await f.cliquer('#db-rail .sidebar__item[data-vue=social]', { apres: 1800 });
  await f.montrer('.rx-barre', 'Les onglets');
  await f.dire('Une seule barre d’onglets : le <b>calendrier</b>, les vues et abonnés, le suivi, les commentaires, les messages, les <b>avis Google</b>…', 6000);
  await f.dire('… <b>créer un post</b>, les publicités et opérations, la grille Instagram.', 3800);
  await f.cacher(150);

  await f.chapitre({ n: 2, total: 2, titre: 'L’espace boutique', texte: 'Ses pages s’ouvrent ici, fondues dans la page du dashboard.', ms: 3200 });
  await f.etape('2 · L’espace boutique');
  await montrerCadre(f, 'L’espace boutique');
  await f.dire('Dessous, les pages de l’<b>espace boutique du Marketing</b>, ouvertes pour mon magasin, sans quitter le dashboard.', 5400);
  await f.dire('Elles sont servies par l’application Marketing : cette vidéo n’en montre que l’entrée.', 4400);
  await f.cacher(150);
  await f.cliquer('.rx-barre [data-rxk="google"]', { apres: 1600 });
  await f.montrer('.rx-barre [data-rxk="google"]', 'Avis Google');
  await f.dire('Un onglet ouvre sa page de l’espace dans le <b>même cadre</b>.', 3600);
  await f.cacher(150);
  return f.fin({
    titre: 'À vous<br>de publier', accroche: 'Vos réseaux, depuis le dashboard', ms: 6000,
    retenir: ['Marketing › Réseaux sociaux, dans le rail', 'Les onglets de l’espace boutique', 'Tout se fait sans quitter le dashboard'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
