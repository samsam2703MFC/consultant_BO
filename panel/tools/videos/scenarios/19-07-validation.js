/* Mon catalogue › l'onglet « 4 · Validation » (?vue=catalogue), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    catalogue-validation      les points calculés par le Marketing, la jauge, FR / NL, les pages composées et
 *                                       leurs marques, une page en grand
 *   fonction  catalogue-verifier        « ✓ C'est juste », « ✓ Dates vues », « Ouvrir la page N › », « Imprimer ainsi »,
 *                                       « Passer à N pages » : la jauge des points vus avance
 *   fonction  catalogue-retirer-promo   « La retirer » : la promotion ne s'imprime plus, les points se recalculent
 *   fonction  catalogue-valider         « Valider mon catalogue › » : prêt à imprimer, l'export ; les points restés
 *                                       ouverts sont rappelés au PDF
 * Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée « Noël + Saint-Nicolas » (10 pages, deux pages
 * vides à la fin) et on l'ouvre à la Validation. Chaque phrase vient de panel/public/dashboard/catalogue.js
 * (rendreValidation, GESTES, vu, retirerPromo, changerNbPages, valider, rendreExport). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues';
const appel = (p, m, chemin, corps) => p.evaluate(([m, u, b]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [m, API + chemin, corps]);

module.exports = async function () {
  const f = await Film.ouvrir('19-07-validation', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      const c = await appel(p, 'POST', '?shop=4', { title: 'Noël + Saint-Nicolas', source: { kind: 'gamme', ref: [31, 32] }, format: 'A5', pageCount: 10, langs: ['fr', 'nl'] });
      await p.reload(); await p.waitForTimeout(2500);
      await p.click('.cm-tab tr[data-v="' + c.data.id + '"] [data-cm=ouvrir]'); await p.waitForTimeout(1800);
      await p.click('.cm-et5 [data-cm=etape][data-v="4"]'); await p.waitForTimeout(2200);
      // De la place sous le module : un bouton du bas de page se montre au-dessus des sous-titres.
      await p.evaluate(() => { document.querySelector('.cm').style.paddingBottom = '160px'; });
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-validation', titre: 'La validation : ce qui s’imprimera',
    resume: 'Les points calculés par le Marketing, à voir un par un, à côté des pages composées et de leurs marques.',
    accroche: 'Vérifier avant d’imprimer',
    programme: ['Vérifier les points', 'Retirer une promotion qui finit trop tôt', 'Valider mon catalogue'],
  });
  await f.montrer('.cm-et5 [data-cm=etape][data-v="4"]', '4 · Validation');
  await f.dire('« Validation › » mène ici ; depuis la liste, « Vérifier et imprimer › », ou « Le feuilleter » pour celui de la marque.', 5600);
  await f.montrer('.cm-verifs', 'Je vérifie avant d’imprimer');
  await f.dire('Le Marketing <b>compose</b> le catalogue et le <b>vérifie</b> : les points à voir, un par un, chacun avec ses gestes.', 5400);
  await f.montrer('.cm-corps--valid .cm-pan > .h', 'Points vus');
  await f.dire('La jauge compte les <b>points vus</b>.', 2800);
  await f.montrer('.cm-mes-pages', 'Mes 10 pages');
  await f.dire('À côté, les <b>pages composées</b> ; un « ? » ou un « ! » marque celles qui ont un point.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-mes-pages .cm-vg[data-v="2"]', { apres: 1100 });
  await f.montrer('.cm-grand', 'En grand');
  await f.dire('Un clic ouvre une page <b>en grand</b>, comme elle s’imprimera ; « Fermer la page ».', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-grand [data-cm=grand]', { apres: 700 });
  await f.cliquer('.cm-corps--valid .cm-pan > .h [data-cm=lang][data-v="nl"]', { apres: 2000 });
  await f.montrer('.cm-mes-pages', 'NL');
  await f.dire('<b>NL</b> : les pages composées en néerlandais. FR revient au français.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-corps--valid .cm-pan > .h [data-cm=lang][data-v="fr"]', { apres: 1800 });
  await f.montrer('.cm-corps--valid .cm-pied', 'Valider');
  await f.dire('Imprimer reste possible avec des points ouverts : ils sont <b>rappelés au moment du PDF</b>.', 4400);
  await f.cacher(300); await f.taire(200);

  /* Vérifier les points */
  await f.fonction({ id: 'catalogue-verifier', titre: 'Vérifier les points avant d’imprimer',
    resume: 'Pour chaque point : « ✓ C’est juste », « ✓ Dates vues », « Ouvrir la page N › » ou « Imprimer ainsi » ; la jauge des points vus avance.',
    points: ['Lire le point', '« ✓ C’est juste », « ✓ Dates vues »', '« Ouvrir la page › », « Imprimer ainsi »'] });
  await f.montrer('.cm-verif:has([data-v="c-est-juste"])', 'Les prix');
  await f.dire('Le pain d’épices : 6,20 € au comptoir, 6,50 € au webshop. Le catalogue imprime le prix du <b>webshop</b>.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=geste][data-v="c-est-juste"]', { apres: 700 });
  await f.cliquer('[data-cm=geste][data-v="dates-vues"]', { apres: 900 });
  await f.dire('<b>C’est juste</b>, et les dates en pied de page : <b>vues</b>.', 3000);
  await f.taire(150);
  await f.cliquer('[data-cm=geste][data-v="ouvrir-page"]', { apres: 1100 });
  await f.montrer('.cm-grand', 'Page 6');
  await f.dire('« <b>Ouvrir la page 6 ›</b> » : 2 produits sur 6 places, le bas de la page reste blanc.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=geste][data-v="imprimer-ainsi"]', { apres: 900 });
  await f.montrer('.cm-corps--valid .cm-pan > .h', '4 sur 6 vus');
  await f.dire('« <b>Imprimer ainsi</b> » : je la garde comme elle est. La jauge avance.', 3800);
  await f.cacher(300); await f.taire(200);

  /* Retirer une promotion */
  await f.fonction({ id: 'catalogue-retirer-promo', titre: 'Retirer une promotion qui finit avant le catalogue',
    resume: '« La retirer » sur le point : la promotion ne s’imprime plus et les points se recalculent.',
    points: ['Le point « Une promotion finit avant le catalogue »', '« La retirer »'] });
  await f.montrer('.cm-verif:has([data-v="retirer-promo"])', 'Un point ouvert');
  await f.dire('« Spéculoos : 3 sachets + 1 offert » s’arrête le <b>6 décembre</b> ; les biscuits sont en vente jusqu’au 31.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=geste][data-v="retirer-promo"]', { apres: 2200 });
  await f.montrer('.cm-verifs', 'Recalculés');
  await f.dire('<b>Retirée</b> : elle ne s’imprime plus, et les points se recalculent. Le point a disparu.', 4400);
  await f.cacher(300); await f.taire(200);

  /* Valider */
  await f.fonction({ id: 'catalogue-valider', titre: 'Valider mon catalogue',
    resume: '« Valider mon catalogue › » : il est prêt à imprimer et passe à l’export ; les points restés ouverts sont rappelés au PDF.',
    points: ['« Valider mon catalogue › »', 'L’export PDF', 'Les points ouverts, rappelés'] });
  await f.montrer('.cm-corps--valid .cm-pied [data-cm=valider]', 'Valider');
  await f.dire('Un point reste ouvert : les pages 7 et 8, vides. Je peux <b>valider</b> quand même.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-corps--valid .cm-pied [data-cm=valider]', { apres: 2400 });
  await f.montrer('.cm-msg.warn', 'Rappelé');
  await f.dire('Prêt à imprimer : l’<b>export PDF</b>. Le point resté ouvert est rappelé à côté du fichier.', 4400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
