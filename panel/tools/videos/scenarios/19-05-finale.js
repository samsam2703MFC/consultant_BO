/* Mon catalogue › l'onglet « 3 · Présentation — la mise en page finale » (?vue=catalogue), et ses fonctions en chapitres
 * (24/11/2026) :
 *   onglet    catalogue-finale            tout le catalogue dans l'ordre de l'impression, par blocs de catégorie ; une
 *                                         page en grand ; le nombre de pages, le format, les langues ; « Réglages page par page »
 *   fonction  catalogue-finale-ordre      glisser une catégorie par ⠿ (ses pages la suivent), ou « ‹ › »
 *   fonction  catalogue-finale-pages      de 8 à 16 pages, par 2 : les pages libres de la fin partent, ou des pages vides s'ajoutent
 *   fonction  catalogue-format-langues    A5 / A4, FR ou FR + NL ; « Validation › » enregistre, les pages en néerlandais
 * Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée « Noël + Saint-Nicolas » (10 pages : 4 de
 * produits, 2 libres) et on l'ouvre à la mise en page finale. Chaque phrase vient de panel/public/dashboard/catalogue.js
 * (mepFinale, mepBlocs, mepDeplacer, mepBouger, mepNbPages, mepCote, rendreZoom, enregistrerPages, rendreValidation). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues';
const appel = (p, m, chemin, corps) => p.evaluate(([m, u, b]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [m, API + chemin, corps]);

/** Pendant un glisser-déposer natif, la page ne reçoit plus de mousemove : le curseur filmé suit le dragover. */
async function curseurPendantGlisser(f) {
  await f.p.evaluate(() => {
    if (window.__filmGlisser) { return; }
    window.__filmGlisser = true;
    addEventListener('dragover', e => { const c = document.getElementById('film-curseur'); if (c && e.clientX) { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; } }, true);
  });
}

module.exports = async function () {
  const f = await Film.ouvrir('19-05-finale', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      const c = await appel(p, 'POST', '?shop=4', { title: 'Noël + Saint-Nicolas', source: { kind: 'gamme', ref: [31, 32] }, format: 'A5', pageCount: 10, langs: ['fr'] });
      await p.reload(); await p.waitForTimeout(2500);
      await p.click('.cm-tab tr[data-v="' + c.data.id + '"] [data-cm=ouvrir]'); await p.waitForTimeout(1800);
      await p.click('.cm-hd [data-cm=etape][data-v="3"]'); await p.waitForTimeout(1800);
      await p.click('.mep-ss.fin'); await p.waitForTimeout(1200);
      // De la place sous le module : un bouton du bas de page se montre au-dessus des sous-titres.
      await p.evaluate(() => { document.querySelector('.cm').style.paddingBottom = '160px'; });
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-finale', titre: 'La mise en page finale',
    resume: 'Tout le catalogue dans l’ordre de l’impression, par blocs de catégorie, avec le nombre de pages, le format et les langues.',
    accroche: 'Le catalogue, tel qu’il s’imprimera',
    programme: ['Changer l’ordre des catégories', 'Changer le nombre de pages', 'Choisir le format et les langues'],
  });
  await f.montrer('.mep-sous .mep-ss.fin', 'La dernière pastille');
  await f.dire('Après les catégories, la dernière pastille : la <b>mise en page finale</b>.', 3600);
  await f.montrer('.mep-fin', 'L’ordre de l’impression');
  await f.dire('Tout le catalogue, dans l’<b>ordre de l’impression</b> : les pages de la marque en tête et en fin, fixes.', 4800);
  await f.montrer('.mep-bloc[data-bloc="0"]', 'Un bloc');
  await f.dire('Un <b>bloc</b> par catégorie, de sa couleur, avec ses pages et leurs numéros.', 3800);
  await f.montrer('.mep-bloc.libre', 'Pages libres');
  await f.dire('Les <b>pages libres</b> : une photo ou l’illustration de la gamme peut les prendre, sinon − 2 pages.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.mep-bloc[data-bloc="1"] .cm-ap', { apres: 1200 });
  await f.montrer('.cm-zoom__in', 'En grand');
  await f.dire('Un clic sur une page l’ouvre <b>en grand</b> ; « Fermer » la referme.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-zoom__x', { apres: 900 });
  await f.montrer('.mep-opts', 'Pages · format · langues');
  await f.dire('Dessous : le <b>nombre de pages</b>, le format, les langues.', 3400);
  await f.montrer('.mep-cote', 'Mon catalogue');
  await f.dire('À droite, les pages de chaque catégorie : p. 3, p. 4…', 3400);
  await f.montrer('.mep-pied [data-cm=mepDetail]', 'Réglages page par page');
  await f.dire('« <b>Réglages page par page</b> » ouvre la liste détaillée ; rien ne part avant « Validation › ».', 4600);
  await f.cacher(300); await f.taire(200);

  /* L'ordre */
  await f.fonction({ id: 'catalogue-finale-ordre', titre: 'Changer l’ordre des catégories',
    resume: 'Glisser une catégorie par sa poignée ⠿ : ses pages la suivent ; « ‹ › » sans souris. Les pages de la marque ne bougent pas.',
    points: ['Prendre la catégorie par ⠿', 'La poser à sa place', 'Ou « ‹ › »'] });
  await curseurPendantGlisser(f);
  // Les deux blocs à l'écran avant de mesurer : un défilement entre les deux mesures fausserait le geste.
  await f.defiler(-3000);
  const de = await f.rect('.mep-bloc:has-text("Pains de fête") .mep-poi', false);
  const vers = await f.rect('.mep-bloc:has-text("Viennoiseries de fête")', false);
  await f.dire('Je prends les <b>pains de fête</b> par leur poignée ⠿, et je les pose avant les viennoiseries.', 800);
  await f.tirer(de.x + de.w / 2, de.y + de.h / 2, vers.x + vers.w * 0.2, vers.y + vers.h / 2, 1700);
  await f.pause(2600);
  await f.montrer('.mep-cote', 'p. 4');
  await f.dire('Leurs pages suivent : les pains de fête passent en <b>page 4</b>.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.mep-bloc:has-text("Pains de fête") [data-cm=mepBloc][data-d="1"]', { apres: 1000 });
  await f.montrer('.mep-fin', '‹ ›');
  await f.dire('Sans souris, « <b>›</b> » le passe après le bloc suivant, « ‹ » avant le précédent.', 4000);
  await f.cacher(300); await f.taire(200);

  /* Le nombre de pages */
  await f.fonction({ id: 'catalogue-finale-pages', titre: 'Changer le nombre de pages',
    resume: 'De 8 à 16 pages, toujours par 2 : les pages libres de la fin partent, ou des pages vides s’ajoutent.',
    points: ['« Nombre de pages »', 'Les pages libres partent', 'Ou des pages vides s’ajoutent'] });
  await f.montrer('.mep-opts .mep-opt', 'Nombre de pages');
  await f.dire('<b>10 pages</b>, dont 2 libres. Un nombre qui laisserait des produits sans place n’est pas proposé.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mepNb][data-v="8"]', { apres: 1000 });
  await f.montrer('.mep-fin', '8 pages');
  await f.dire('À <b>8 pages</b>, les deux pages libres de la fin partent.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mepNb][data-v="12"]', { apres: 1000 });
  await f.montrer('.mep-fin', '12 pages');
  await f.dire('À <b>12 pages</b>, quatre pages vides s’ajoutent à la fin. « Validation › » enregistre le nombre de pages.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mepNb][data-v="10"]', { apres: 900 });
  await f.cacher(300); await f.taire(200);

  /* Format et langues */
  await f.fonction({ id: 'catalogue-format-langues', titre: 'Choisir le format et les langues',
    resume: 'A5 ou A4 ; FR ou FR + NL. « Validation › » les enregistre avec les pages ; le catalogue se compose aussi en néerlandais.',
    points: ['Format A5 ou A4', 'Langues FR ou FR + NL', '« Validation › » enregistre'] });
  await f.cliquer('[data-cm=format][data-v="A4"]', { apres: 700 });
  await f.cliquer('[data-cm=mepLangs][data-v="fr,nl"]', { apres: 800 });
  await f.montrer('.mep-opts', 'A4 · FR + NL');
  await f.dire('Le format <b>A4</b>, et les deux langues : <b>FR + NL</b>.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-hd [data-cm=etape][data-v="4"]', { apres: 2400 });
  await f.cliquer('.cm-pan .h [data-cm=lang][data-v="nl"]', { apres: 2000 });
  await f.montrer('.cm-mes-pages', 'En néerlandais');
  await f.dire('« Validation › » enregistre : le catalogue est en A4, et ses pages se lisent aussi en <b>néerlandais</b>.', 5000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
