/* Mon catalogue › l'onglet « 3 · Réglages page par page » (?vue=catalogue), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    catalogue-reglages          chaque page réglée à la main : le nombre de pages, le format, les langues, les
 *                                         pages de la marque, une page et son aperçu en grand ; « ‹ L'assistant »
 *   fonction  catalogue-reglages-pages    « − 2 pages » refusé (une page à retirer porte des produits), « Répartir
 *                                         automatiquement sur 8 pages », puis « ＋ 2 pages » : enregistré tout de suite
 *   fonction  catalogue-regler-page       une page : sa mise en page (Présentation), sa gamme, son texte, son gabarit
 *   fonction  catalogue-place-libre       « Place libre » : Photo (médiathèque, chercher) ou Illustration ; à la Validation
 *   fonction  catalogue-reglages-ordre    une page glissée par ⠿, puis « ↑ »
 *   fonction  catalogue-repartir          « ↺ Répartir automatiquement » : une page par catégorie
 * Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée « Noël + Saint-Nicolas » (10 pages, toutes
 * prises : une pleine page de bûche, les biscuits en grandes vignettes, les pains de fête en dernier) et on ouvre ses
 * réglages page par page. Chaque phrase vient de
 * panel/public/dashboard/catalogue.js (rendreE3, segPages, changerNbPages, repartir, changerType, rendreMedias,
 * glisserDebut, deposer, enregistrerPages). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues';
const appel = (p, m, chemin, corps) => p.evaluate(([m, u, b]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [m, API + chemin, corps]);

async function curseurPendantGlisser(f) {
  await f.p.evaluate(() => {
    if (window.__filmGlisser) { return; }
    window.__filmGlisser = true;
    addEventListener('dragover', e => { const c = document.getElementById('film-curseur'); if (c && e.clientX) { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; } }, true);
  });
}

module.exports = async function () {
  const f = await Film.ouvrir('19-06-reglages', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      const c = await appel(p, 'POST', '?shop=4', { title: 'Noël + Saint-Nicolas', source: { kind: 'gamme', ref: [31, 32] }, format: 'A5', pageCount: 10, langs: ['fr'] });
      await appel(p, 'PUT', '/' + c.data.id + '?shop=4', { pages: [{ layout: 'pleine', category: 21 }, { layout: 'grille', category: 21 }, { layout: 'grille', category: 22 },
        { layout: 'mosaique', category: 23 }, { layout: 'mosaique', category: 23 }, { layout: 'grille', category: 24 }] });
      await p.reload(); await p.waitForTimeout(2500);
      await p.click('.cm-tab tr[data-v="' + c.data.id + '"] [data-cm=ouvrir]'); await p.waitForTimeout(1800);
      await p.click('.cm-hd [data-cm=etape][data-v="3"]'); await p.waitForTimeout(1800);
      await p.click('.mep-ss.fin'); await p.waitForTimeout(1000);
      await p.click('.mep-pied [data-cm=mepDetail]'); await p.waitForTimeout(1200);
      // De la place sous le module : un bouton du bas de page se montre au-dessus des sous-titres.
      await p.evaluate(() => { document.querySelector('.cm').style.paddingBottom = '160px'; document.scrollingElement.scrollTop = 0; window.__film.prets(); });
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-reglages', titre: 'Les réglages page par page',
    resume: 'Chaque page réglée à la main : catégorie, gamme, mise en page, gabarit, place libre, ordre, et la répartition automatique.',
    accroche: 'Chaque page, à la main',
    programme: ['Changer le nombre de pages', 'Régler une page à la main', 'Remplir la place libre', 'Changer l’ordre des pages', 'Répartir automatiquement'],
  });
  await f.montrer('.cm-pan > .h', 'Réglages page par page');
  await f.dire('Ouverts depuis la mise en page finale, « Réglages page par page ». « <b>‹ L’assistant</b> » y revient.', 5000);
  await f.montrer('.cm-reglages', 'Pages · format · langues');
  await f.dire('En tête : le <b>nombre de pages</b>, le format A5 ou A4, le français et le néerlandais.', 4400);
  await f.montrer('.cm-pg.lock', 'La marque');
  await f.dire('Les quatre pages de la <b>marque</b> sont fixes : la couverture, la page 2, l’avant-dernière et la dernière.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.cm-pg[data-pg="0"]', 'Une page');
  await f.dire('Chaque page : son aperçu, sa <b>catégorie</b>, sa gamme, sa mise en page, son <b>gabarit</b>, sa place dans l’ordre.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-pg[data-pg="0"] .cm-ap', { apres: 1200 });
  await f.montrer('.cm-zoom__in', 'En grand');
  await f.dire('Un clic sur l’aperçu l’ouvre <b>en grand</b> ; « Fermer » le referme.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-zoom__x', { apres: 900 });
  await f.defiler(2400);
  await f.montrer('.cm-pied', 'Validation ›');
  await f.dire('Les pages s’enregistrent à « <b>Validation ›</b> » ; le nombre de pages et la répartition, tout de suite.', 4800);
  await f.cacher(300); await f.taire(200);
  await f.defiler(-6000);

  /* Le nombre de pages */
  await f.fonction({ id: 'catalogue-reglages-pages', titre: 'Changer le nombre de pages, page par page',
    resume: '« − 2 pages » ou « ＋ 2 pages », enregistré tout de suite. Une page à retirer porte des produits : le Marketing refuse et propose de répartir.',
    points: ['« − 2 pages » ou « ＋ 2 pages »', 'Refusé si une page à retirer porte des produits', '« Répartir automatiquement sur N pages »'] });
  await f.montrer('.cm-reglages .cm-nbp', '10 pages');
  await f.dire('<b>10 pages</b>, toutes prises : les biscuits et les pains de fête sont sur les deux dernières.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-reglages .cm-nbp [data-cm=nbpages]:first-child', { apres: 1800 });
  await f.montrer('.cm-msg.err', 'Refusé');
  await f.dire('Les deux dernières pages partiraient, avec leurs produits : le Marketing <b>refuse</b>, rien n’est retiré.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=repartirN]', { apres: 1800 });
  await f.montrer('.cm-reglages .cm-nbp', '8 pages');
  await f.dire('« <b>Répartir automatiquement sur 8 pages</b> » : une page par catégorie, enregistré tout de suite.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-reglages .cm-nbp [data-cm=nbpages]:last-child', { apres: 1800 });
  await f.montrer('.cm-reglages .cm-nbp', '10 pages');
  await f.dire('« <b>＋ 2 pages</b> » ajoute deux pages vides à la fin.', 3200);
  await f.cacher(300); await f.taire(200);

  /* Régler une page à la main */
  await f.fonction({ id: 'catalogue-regler-page', titre: 'Régler une page à la main',
    resume: 'Pour une page : sa mise en page (7 choix, dont la présentation d’une gamme et son texte FR / NL), sa catégorie, sa gamme, son gabarit, le produit de la pleine page.',
    points: ['La mise en page de la page', 'Sa gamme, sa catégorie, son texte', 'Son gabarit'] });
  await f.cliquer('[data-cm=layout][data-i="4"][data-v="presentation"]', { apres: 1000 });
  await f.survoler('select[data-cm=pgamme][data-i="4"]', { pause: 400 });
  await f.p.locator('select[data-cm=pgamme][data-i="4"]').selectOption('32');
  await f.pause(900);
  await f.montrer('.cm-pg[data-pg="4"]', 'Page 7');
  await f.dire('Page 7 : « <b>Présentation</b> » de la gamme Saint-Nicolas, son illustration et son nom.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=ptexte][data-i="4"]', { apres: 200 });
  await f.taper('Le 6 décembre, le grand saint passe chez nous.', 55);
  await f.p.keyboard.press('Tab'); await f.pause(500);
  await f.cliquer('[data-cm=gabarit][data-i="4"][data-v="B"]', { apres: 900 });
  await f.montrer('.cm-pg[data-pg="4"]', 'Texte · gabarit B');
  await f.dire('Son <b>texte</b>, et le gabarit B, pleine couleur. Une page de produits, elle, prend sa catégorie et sa gamme.', 5400);
  await f.cacher(300); await f.taire(200);

  /* La place libre */
  await f.fonction({ id: 'catalogue-place-libre', titre: 'Remplir la place libre d’une page',
    resume: '« Place libre » : Rien, Photo (choisie dans la médiathèque, avec recherche) ou Illustration, quand il reste une rangée libre.',
    points: ['« Place libre »', 'Photo : chercher, choisir', 'Ou Illustration'] });
  await f.montrer('.cm-pg[data-pg="3"]', 'Page 6 · 2 produits');
  await f.dire('Les pains de fête : <b>2 produits sur 6 places</b>. Le bas de la page reste blanc.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=place][data-i="3"][data-v="photo"]', { apres: 1200 });
  await f.cliquer('[data-cm=mediasQ]', { apres: 200 });
  await f.taper('flocon', 110);
  await f.cliquer('[data-cm=photoChercher]', { apres: 1000 });
  await f.cliquer('[data-cm=photo][data-i="3"][data-v="803"]', { apres: 1100 });
  await f.montrer('.cm-pg[data-pg="3"]', 'La photo');
  await f.dire('Une <b>photo</b> de la médiathèque prend la place libre. « Illustration » y mettrait l’illustration de la gamme.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-hd [data-cm=etape][data-v="4"]', { apres: 2400 });
  await f.cliquer('.cm-mes-pages .cm-vg[data-v="5"]', { apres: 1000 });
  await f.montrer('.cm-grand', 'Page 6');
  await f.dire('Enregistrée à « Validation › » : la photo est dans la page composée.', 3800);
  await f.cacher(300); await f.taire(200);

  /* L'ordre des pages */
  await f.fonction({ id: 'catalogue-reglages-ordre', titre: 'Changer l’ordre des pages',
    resume: 'Page par page : la poignée ⠿ glisse une page au-dessus ou au-dessous d’une autre ; « ↑ » et « ↓ » la montent ou la descendent.',
    points: ['Prendre la page par ⠿', 'La poser au-dessus ou au-dessous', 'Ou « ↑ » « ↓ »'] });
  await f.cliquer('.cm-et5 [data-cm=etape][data-v="3"]', { apres: 1600 });
  await curseurPendantGlisser(f);
  await f.defilerVers(f.p.locator('.cm-pg[data-pg="3"]'), 20);
  const de = await f.rect('[data-pgdrag="4"]', false);
  const vers = await f.rect('.cm-pg[data-pg="3"]', false);
  await f.dire('La présentation de Saint-Nicolas, prise par sa poignée <b>⠿</b>, posée au-dessus des pains de fête.', 800);
  await f.tirer(de.x + de.w / 2, de.y + de.h / 2, vers.x + vers.w * 0.5, vers.y + vers.h * 0.25, 1600);
  await f.pause(3000);
  await f.taire(150);
  for (let k = 0; k < 3; k++) { await f.cliquer('.cm-pg[data-pg="' + (3 - k) + '"] [data-cm=monter]', { apres: 700 }); }
  await f.montrer('.cm-pg[data-pg="0"]', 'Page 3');
  await f.dire('Puis « <b>↑</b> » : elle ouvre le catalogue, en page 3. « Validation › » enregistre l’ordre.', 4400);
  await f.cacher(300); await f.taire(200);

  /* Répartir automatiquement */
  await f.fonction({ id: 'catalogue-repartir', titre: 'Répartir automatiquement',
    resume: '« ↺ Répartir automatiquement » : le Marketing refait la répartition, une page par catégorie, enregistrée tout de suite.',
    points: ['« ↺ Répartir automatiquement »', 'Une page par catégorie'] });
  await f.montrer('.cm-pan > .h [data-cm=repartir]', 'Répartir');
  await f.dire('Pour repartir de zéro : « <b>↺ Répartir automatiquement</b> ».', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-pan > .h [data-cm=repartir]', { apres: 1800 });
  await f.montrer('.cm-cote', 'Une page par catégorie');
  await f.dire('Le Marketing refait <b>une page par catégorie</b>, enregistrée tout de suite : mes réglages page par page sont remplacés.', 5400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
