/* Mon catalogue › l'onglet « 2 · Gammes — le point de départ » (?vue=catalogue), et sa fonction en chapitre (24/11/2026) :
 *   onglet    catalogue-depart   « ＋ Nouveau catalogue » : le titre, le nombre de pages, une ou plusieurs gammes ou
 *                                tous mes produits (le cumul des produits, le titre proposé)
 *   fonction  catalogue-creer    cocher les gammes, le titre, « ＋ 2 pages », « Créer et choisir mes produits › »
 * Choisir ses gammes n'écrit rien (le choix part à la création) : montré dans l'onglet, refait dans « Créer ».
 * Données fictives : mocks/19_catalogue.js. Chaque phrase vient de panel/public/dashboard/catalogue.js (rendreE1,
 * segPages, choisirSource, creer, resume). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('19-02-depart', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-depart', titre: 'Le point de départ d’un nouveau catalogue',
    resume: 'Un nouveau catalogue : son titre, son nombre de pages, une ou plusieurs gammes, ou tous mes produits.',
    accroche: 'Une gamme, deux, ou tout le webshop',
    programme: ['Créer le catalogue'],
  });
  await f.montrer('.cm-hd [data-cm=nouveau]', 'Nouveau catalogue');
  await f.dire('Depuis la liste, « <b>＋ Nouveau catalogue</b> » ouvre l’étape 2 sur son point de départ.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-hd [data-cm=nouveau]', { apres: 1400 });
  await f.montrer('[data-cm=titre]', 'Titre du catalogue');
  await f.dire('Son <b>titre</b> : « Mes bûches 2026 », par exemple.', 3000);
  await f.montrer('.cm-nbp', 'Nombre de pages');
  await f.dire('<b>8 pages</b> par défaut, dont 4 de la marque ; « ＋ 2 pages » en ajoute deux, jusqu’à 16. Modifiable ensuite.', 5000);
  await f.montrer('.cm-src', 'Je pars de…');
  await f.dire('Une ou plusieurs <b>gammes</b>, huit au plus : combien de leurs produits ma boutique vend, et leurs dates.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-src [data-v="31"]', { apres: 500 });
  await f.cliquer('.cm-src [data-v="32"]', { apres: 700 });
  await f.montrerTout('[data-cm=titre], .cm-pan > .cm-cpt', 'Deux gammes');
  await f.dire('Deux gammes cochées : <b>17 produits cumulés</b>, un produit des deux comptant une fois. Le titre proposé suit.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-src [data-v="tous"]', { apres: 800 });
  await f.montrer('.cm-src', 'Tous mes produits');
  await f.dire('Ou « <b>Tous mes produits</b> » : tout le webshop, et les gammes se décochent.', 4000);
  await f.montrer('.cm-cote', 'Mon catalogue');
  await f.dire('Le catalogue n’existe pas encore : il se crée à la fin de cette étape.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-src [data-v="tous"]', { apres: 600 });
  await f.cacher(300); await f.taire(200);

  /* Créer */
  await f.fonction({ id: 'catalogue-creer', titre: 'Créer le catalogue',
    resume: 'Une ou plusieurs gammes (ou tous mes produits), le titre, le nombre de pages, puis « Créer et choisir mes produits › ».',
    points: ['Cocher une ou plusieurs gammes', 'Le titre, le nombre de pages', '« Créer et choisir mes produits › »'] });
  await f.cliquer('.cm-src [data-v="31"]', { apres: 500 });
  await f.cliquer('.cm-src [data-v="32"]', { apres: 700 });
  await f.dire('Noël et Saint-Nicolas : <b>17 produits</b>.', 2600);
  await f.taire(150);
  await f.cliquer('[data-cm=titre]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('Nos fêtes 2026', 90);
  await f.pause(400);
  await f.cliquer('.cm-nbp [data-cm=nbpages]:last-child', { apres: 700 });
  await f.montrerTout('[data-cm=titre], .cm-nbp', 'Titre · 10 pages');
  await f.dire('Mon titre, et <b>10 pages</b> : deux de plus, toujours par 2.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=creer]', { apres: 1800 });
  await f.montrer('.cm-cote', 'Créé');
  await f.dire('Le catalogue est créé : <b>17 produits choisis</b>, une page par catégorie. Je les règle maintenant, à mes prix.', 5000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
