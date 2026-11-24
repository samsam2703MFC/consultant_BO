/* Mon catalogue › l'onglet « 3 · Présentation — une catégorie » (?vue=catalogue), et ses fonctions en chapitres
 * (24/11/2026) :
 *   onglet    catalogue-categorie       une catégorie à la fois : ses mises en page, l'aperçu de ses pages (« Voir une
 *                                       page en grand », « Fermer »), pleine page et gabarit, le résumé, le tableau
 *   fonction  catalogue-mise-en-page    1 vedette + 4 · 2 par largeur · 1 par largeur · 2 grandes : les pages se
 *                                       dessinent, le catalogue prend 2 pages de plus s'il le faut
 *   fonction  catalogue-pleine-page     « Un produit seul sur une page » : Oui, puis le produit ; sa promotion dessous
 *   fonction  catalogue-gabarit         gabarit A · B · C, « Catégorie manuscrite » ; « Validation › » enregistre
 * Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée « Noël + Saint-Nicolas » (8 pages) et on l'ouvre
 * à l'étape 3. Chaque phrase vient de panel/public/dashboard/catalogue.js (rendreMep, mepCategorie, mepPleine, mepPoser,
 * mepCote, mepProduits, rendreZoom, enregistrerPages). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues';
const appel = (p, m, chemin, corps) => p.evaluate(([m, u, b]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [m, API + chemin, corps]);

module.exports = async function () {
  const f = await Film.ouvrir('19-04-categorie', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      const c = await appel(p, 'POST', '?shop=4', { title: 'Noël + Saint-Nicolas', source: { kind: 'gamme', ref: [31, 32] }, format: 'A5', pageCount: 8, langs: ['fr'] });
      await p.reload(); await p.waitForTimeout(2500);
      await p.click('.cm-tab tr[data-v="' + c.data.id + '"] [data-cm=ouvrir]'); await p.waitForTimeout(1800);
      await p.click('.cm-hd [data-cm=etape][data-v="3"]'); await p.waitForTimeout(1800);
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-categorie', titre: 'La présentation, une catégorie à la fois',
    resume: 'Une catégorie à la fois : sa mise en page, sa pleine page, son gabarit, l’aperçu de ses pages et le tableau de ses produits.',
    accroche: 'Chaque catégorie, sa page',
    programme: ['Choisir la mise en page d’une catégorie', 'Mettre un produit seul en pleine page', 'Changer le gabarit et le titre manuscrit'],
  });
  await f.montrer('.mep-sous', 'Une catégorie à la fois');
  await f.dire('Une étape par catégorie choisie, dans l’ordre de l’étape 2 ; à la fin, la <b>mise en page finale</b>.', 4600);
  await f.montrer('.mep-lays', 'Comment les présenter ?');
  await f.dire('Quatre mises en page : chacune dit le <b>nombre de pages</b> qu’elle prend pour ces produits.', 4400);
  await f.montrer('.mep-aps', 'L’aperçu');
  await f.dire('L’<b>aperçu</b> de ses pages, avec mes produits et mes prix : il suit chaque choix, sans attendre.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.mep-aps .cm-ap', { apres: 1200 });
  await f.montrer('.cm-zoom__in', 'La page en grand');
  await f.dire('Un clic l’ouvre <b>en grand</b>, avec ses voisines de la même catégorie. « Fermer » la referme.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-zoom__x', { apres: 900 });
  await f.montrer('.mep-opts', 'Pleine page · gabarit');
  await f.dire('Dessous : un produit seul en <b>pleine page</b>, le gabarit, le titre manuscrit.', 4000);
  await f.montrer('.mep-cote', 'Mon catalogue');
  await f.dire('À droite, les pages de produits, catégorie par catégorie, chacune de sa couleur.', 4000);
  await f.montrer('.mep-prods', 'Ses produits');
  await f.dire('Et le <b>tableau</b> de ses produits : un petit interrupteur montre ou cache chacun.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.mep-pied [data-cm=mep][data-v="1"]', { apres: 1200 });
  await f.montrer('.mep-sous', 'La suivante');
  await f.dire('« Viennoiseries de fête › » passe à la catégorie suivante ; une pastille, à n’importe laquelle.', 4400);
  await f.cacher(300); await f.taire(200);
  await f.defiler(-3000);

  /* La mise en page d'une catégorie */
  await f.fonction({ id: 'catalogue-mise-en-page', titre: 'Choisir la mise en page d’une catégorie',
    resume: '1 vedette + 4, 2 par largeur, 1 par largeur ou 2 grandes : les pages de la catégorie se dessinent, et le catalogue prend 2 pages de plus s’il le faut.',
    points: ['La catégorie', 'Une des quatre mises en page', 'Ses pages se dessinent'] });
  await f.cliquer('.mep-ss[data-v="2"]', { apres: 1100 });
  await f.montrer('.mep-lays', 'Biscuits · 7 produits');
  await f.dire('Biscuits et confiseries : <b>7 produits</b>. En liste, une page ; en grandes vignettes, deux.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mepLay][data-v="mosaique"]', { apres: 1000 });
  await f.montrer('.cm-msg.inf', 'Toujours par 2');
  await f.dire('Plus de place : le catalogue passe à <b>10 pages</b>, toujours par 2.', 3600);
  await f.montrer('.mep-aps', 'Deux pages');
  await f.dire('Deux pages de <b>grandes vignettes</b>, côte à côte, aussitôt dessinées.', 3800);
  await f.cacher(300); await f.taire(200);

  /* Pleine page */
  await f.fonction({ id: 'catalogue-pleine-page', titre: 'Mettre un produit seul en pleine page',
    resume: '« Un produit seul sur une page » : Oui, puis le produit ; sa promotion s’imprime dessous.',
    points: ['« Un produit seul sur une page » : Oui', 'Choisir le produit', 'Sa promotion, dessous'] });
  await f.cliquer('.mep-ss[data-v="0"]', { apres: 1100 });
  await f.cliquer('[data-cm=mepPleine][data-v="1"]', { apres: 1000 });
  await f.survoler('select[data-cm=mepProd]', { pause: 400 });
  await f.p.locator('select[data-cm=mepProd]').selectOption('402');
  await f.pause(900);
  await f.montrer('.mep-opt.on', 'Pleine page');
  await f.dire('La <b>bûche framboise-vanille</b> seule sur sa page, avec sa promotion « Duo de bûches » imprimée dessous.', 5200);
  await f.montrer('.mep-aps', 'Ses pages');
  await f.dire('Une page de plus, avant les autres ; les trois autres bûches restent ensemble.', 4000);
  await f.cacher(300); await f.taire(200);

  /* Gabarit et titre manuscrit */
  await f.fonction({ id: 'catalogue-gabarit', titre: 'Changer le gabarit et le titre manuscrit',
    resume: 'Gabarit A · B · C pour toutes les pages de la catégorie, et « Catégorie manuscrite » Oui ou Non ; « Validation › » enregistre.',
    points: ['Gabarit A · B · C', '« Catégorie manuscrite »', '« Validation › » enregistre'] });
  await f.cliquer('.mep-ss[data-v="1"]', { apres: 1100 });
  // Gabarit C : avec le bandeau (B), le titre manuscrit se dessine dans la couleur du bandeau et ne se voit plus
  // (catalogue.css, .cat-h__text.cat-h__script) — signalé, pas filmé.
  await f.cliquer('[data-cm=mepGab][data-v="C"]', { apres: 900 });
  await f.montrer('.mep-aps', 'C · Épuré');
  await f.dire('<b>C · Épuré</b> : fond blanc, titre à gauche. Toutes les pages de la catégorie le prennent ; A et B de même.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mepScript][data-v="1"]', { apres: 900 });
  await f.montrer('.mep-aps', 'Manuscrite');
  await f.dire('« Catégorie manuscrite » : Oui. Le nom de la catégorie, en lettres manuscrites.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-hd [data-cm=etape][data-v="4"]', { apres: 2200 });
  await f.cliquer('.cm-mes-pages .cm-vg[data-v="4"]', { apres: 1000 });
  await f.montrer('.cm-grand', 'Enregistré');
  await f.dire('« <b>Validation ›</b> » enregistre la présentation : le Marketing compose les pages telles que choisies.', 4800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
