/* Mon catalogue › l'onglet « 2 · Gammes — mes produits » (?vue=catalogue), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    catalogue-produits        les produits de mon webshop par catégorie, à mes prix ; chercher ; les promotions
 *                                       lues dans TFBuddy ; la disponibilité ; « Présentation › » enregistre
 *   fonction  catalogue-produit         retirer un produit (chercher, son interrupteur), puis à l'étape 3 le petit
 *                                       interrupteur du tableau le remet : les pages se redessinent
 *   fonction  catalogue-promotion       cocher une promotion : sa vignette dans la page de sa catégorie (Validation)
 *   fonction  catalogue-promo-image     « Image » sur l'offre, chercher, choisir dans la médiathèque ; « Changer », « Retirer »
 *   fonction  catalogue-disponibilite   « Afficher la disponibilité » : la période et le délai en pied de page (Validation)
 * Les choix de l'étape 2 partent à l'étape suivante (« Présentation › ») : chaque fonction y passe, puis montre le
 * résultat dans les pages composées par le Marketing (étape 4, la page en grand).
 * Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée « Noël + Saint-Nicolas » (8 pages), « Duo de
 * bûches » décoché et la disponibilité éteinte, et on l'ouvre à l'étape 2. Chaque phrase vient de
 * panel/public/dashboard/catalogue.js (rendreE2, sectionOffres, rendreMediasOffre, enregistrerProduits, mepProduits). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues';
const appel = (p, m, chemin, corps) => p.evaluate(([m, u, b]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [m, API + chemin, corps]);

/** Passe à l'étape 3 (« Présentation › » enregistre), puis à la Validation, et ouvre la page n en grand. */
async function voirPage(f, n, etiquette) {
  await f.cliquer('.cm-hd [data-cm=etape][data-v="3"]', { apres: 1500 });
  await f.cliquer('.cm-hd [data-cm=etape][data-v="4"]', { apres: 2000 });
  await f.cliquer('.cm-mes-pages .cm-vg[data-v="' + (n - 1) + '"]', { apres: 900 });
  return f.montrer('.cm-grand', etiquette);
}

module.exports = async function () {
  const f = await Film.ouvrir('19-03-produits', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      const c = await appel(p, 'POST', '?shop=4', { title: 'Noël + Saint-Nicolas', source: { kind: 'gamme', ref: [31, 32] }, format: 'A5', pageCount: 8, langs: ['fr'] });
      await appel(p, 'PUT', '/' + c.data.id + '?shop=4', { offers: [{ type: 'bundle', id: 71, on: false }], showAvailability: false });
      await p.reload(); await p.waitForTimeout(2500);
      await p.click('.cm-tab tr[data-v="' + c.data.id + '"] [data-cm=ouvrir]'); await p.waitForTimeout(1800);
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-produits', titre: 'Mes produits, à mes prix',
    resume: 'Les produits de mon webshop par catégorie et à mes prix, les promotions lues dans TFBuddy et la disponibilité.',
    accroche: 'Ce que ma boutique vend, rien d’autre',
    programme: ['Retirer ou remettre un produit', 'Cocher ou décocher une promotion', 'Mettre une image sur une promotion', 'Afficher la disponibilité'],
  });
  await f.montrer('.cm-pan .h', 'Produits disponibles');
  await f.dire('Seuls les produits de <b>mon webshop</b>, à <b>mes prix</b> : 17 choisis par les gammes Noël et Saint-Nicolas.', 5000);
  await f.montrer('.cm-acc.ouvert', 'Une catégorie');
  await f.dire('Rangés par <b>catégorie</b> : un interrupteur par produit, et « toute la catégorie ».', 4000);
  await f.cacher(150); await f.taire(150);
  await f.defiler(560);
  await f.cliquer('.cm-acc-t[data-v="25"]', { apres: 900 });
  await f.montrer('.cm-acc.ouvert:has([data-v="25"])', 'Viennoiseries · 0 / 4');
  await f.dire('Un clic sur une catégorie la déplie : ses autres produits, à cocher si je veux.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-acc-t[data-v="25"]', { apres: 700 });
  await f.cliquer('[data-cm=rech]', { apres: 200 });
  await f.taper('cougnou', 110);
  await f.pause(600);
  await f.montrer('.cm-accs', 'Chercher un produit');
  await f.dire('« Chercher un produit » réduit la liste à chaque lettre.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('[data-cm=rech]').fill(''); await f.p.locator('[data-cm=rech]').dispatchEvent('input');
  await f.pause(500);
  await f.montrer('.cm-offres', 'Promotions et bundles');
  await f.dire('Dessous, les <b>promotions</b> lues dans TFBuddy, et la <b>disponibilité</b> en pied de page.', 4400);
  await f.montrer('.cm-cote', 'Mon catalogue');
  await f.dire('À droite, le résumé : 8 pages, et ce que chacune porte.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-4000);
  await f.montrer('.cm-hd [data-cm=etape][data-v="3"]', 'Présentation ›');
  await f.dire('Rien ne part avant « <b>Présentation ›</b> » : l’étape suivante enregistre mes choix.', 4000);
  await f.cacher(300); await f.taire(200);

  /* Retirer ou remettre un produit */
  await f.fonction({ id: 'catalogue-produit', titre: 'Retirer ou remettre un produit',
    resume: 'L’interrupteur du produit à l’étape 2, ou le petit interrupteur du tableau à l’étape 3 : les pages se redessinent aussitôt.',
    points: ['Chercher le produit', 'Son interrupteur', 'À l’étape 3 : le tableau de la catégorie'] });
  await f.cliquer('[data-cm=rech]', { apres: 200 });
  await f.taper('orang', 110);
  await f.pause(500);
  await f.cliquer('[data-cm=prod][data-v="427"]', { apres: 700 });
  await f.montrerTout('.cm-pan .h .cm-tag.vif, .cm-pl:has([data-v="427"])', 'Retiré');
  await f.dire('Les orangettes sont <b>retirées</b> : 16 produits choisis. Le même interrupteur les remet.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('[data-cm=rech]').fill(''); await f.p.locator('[data-cm=rech]').dispatchEvent('input');
  await f.pause(400);
  await f.montrer('[data-cm=tout][data-cat="23"]', 'Toute la catégorie');
  await f.dire('« toute la catégorie » coche ou retire une catégorie entière ; « Tout cocher », « Tout décocher », toute la liste.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-hd [data-cm=etape][data-v="3"]', { apres: 1600 });
  await f.cliquer('.mep-ss[data-v="2"]', { apres: 1000 });
  await f.montrer('.mep-prods', 'Le tableau');
  await f.dire('À l’étape 3, chaque catégorie a le <b>tableau</b> de ses produits : les orangettes n’y sont plus montrées.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mepProdOn][data-v="427"]', { apres: 1000 });
  await f.montrerTout('.mep-aps, .mep-prods', 'Remises');
  await f.dire('Le petit interrupteur les remet : la page se <b>redessine aussitôt</b>. « Validation › » l’enregistre.', 4800);
  await f.cacher(300); await f.taire(200);

  /* Cocher ou décocher une promotion */
  await f.fonction({ id: 'catalogue-promotion', titre: 'Cocher ou décocher une promotion',
    resume: 'Dans « Promotions et bundles », lus dans TFBuddy : un interrupteur par offre. Cochée, sa vignette prend une case de la page de sa catégorie.',
    points: ['« Promotions et bundles »', 'L’interrupteur de l’offre', 'Sa vignette dans la page'] });
  await f.cliquer('.cm-et5 [data-cm=etape][data-v="2"]', { apres: 1500 });
  await f.montrer('.cm-offres', 'Promotions et bundles');
  await f.dire('Les promotions de TFBuddy pour ma boutique : badge, produits, période, et la <b>page</b> où elles vont.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=offre][data-v="bundle-71"]', { apres: 700 });
  await f.montrer('.cm-offre:has([data-v="bundle-71"])', 'Cochée');
  await f.dire('« Duo de bûches » <b>cochée</b> : sa vignette prend une case de la page des bûches.', 4000);
  await f.cacher(150); await f.taire(150);
  await voirPage(f, 3, 'Page 3 · le Duo');
  await f.dire('Enregistrée au passage à l’étape 3 : dans la page composée, le <b>Duo de bûches</b> et son prix barré.', 5000);
  await f.cacher(300); await f.taire(200);

  /* Une image de la médiathèque sur une promotion */
  await f.fonction({ id: 'catalogue-promo-image', titre: 'Mettre une image de la médiathèque sur une promotion',
    resume: '« Image » sur l’offre, chercher et choisir une image de la médiathèque : elle remplace les photos des produits dans la vignette ; « Changer » ou « Retirer ».',
    points: ['« Image » sur l’offre', 'Chercher, choisir une image', '« Changer » ou « Retirer »'] });
  await f.cliquer('.cm-et5 [data-cm=etape][data-v="2"]', { apres: 1500 });
  await f.cliquer('[data-cm=offreImg][data-v="bundle-71"]', { apres: 1200 });
  await f.montrer('.cm-medias', 'La médiathèque');
  await f.dire('La <b>médiathèque</b> du réseau et de ma boutique.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=mediasQ]', { apres: 200 });
  await f.taper('sapin', 110);
  await f.cliquer('[data-cm=photoChercher]', { apres: 1000 });
  await f.cliquer('[data-cm=offrePhoto][data-v="801"]', { apres: 900 });
  await f.montrer('.cm-offre:has([data-v="bundle-71"])', 'Son image');
  await f.dire('L’image <b>remplace les photos</b> des produits dans la vignette. « Changer » en prend une autre, « Retirer » revient aux photos.', 5600);
  await f.cacher(150); await f.taire(150);
  await voirPage(f, 3, 'Page 3 · l’image');
  await f.dire('Dans la page composée, la vignette du Duo porte l’<b>image choisie</b>.', 3800);
  await f.cacher(300); await f.taire(200);

  /* La disponibilité */
  await f.fonction({ id: 'catalogue-disponibilite', titre: 'Afficher ou masquer la disponibilité',
    resume: 'L’interrupteur « Afficher la disponibilité » : la période et le délai de commande, lus dans TFBuddy, en pied de chaque page.',
    points: ['« Afficher la disponibilité »', 'Son interrupteur', 'En pied de chaque page'] });
  await f.cliquer('.cm-et5 [data-cm=etape][data-v="2"]', { apres: 1500 });
  await f.montrer('.cm-offre.dispo', 'Disponibilité');
  await f.dire('Éteinte, les pages ne disent pas quand commander.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=dispo]', { apres: 700 });
  await f.montrer('.cm-offre.dispo', 'Affichée');
  await f.dire('<b>Allumée</b> : la période et le délai de commande, lus dans TFBuddy, en pied de chaque page.', 4400);
  await f.cacher(150); await f.taire(150);
  await voirPage(f, 3, 'En pied de page');
  await f.dire('En pied de la page des bûches : « <b>Du 1er au 24 décembre · à commander 48 h à l’avance</b> ».', 5000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
